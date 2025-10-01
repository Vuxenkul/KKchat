<?php
if (!defined('ABSPATH')) {
    exit;
}

/* ---------- MENTION HELPERS ---------- */

function kk_buildMentionRegex(string $displayName, string $username): string {
    $dn = preg_quote($displayName, '/');
    $un = preg_quote($username, '/');
    // Match "@Display Name" or "@username" with boundaries, case-insensitive
    return "/(^|[^\\w])@(?:{$dn}|{$un})(?=$|\\W)/i";
}
function kk_textMentionsUser(string $content, string $mentionRegex): bool {
    return (bool)preg_match($mentionRegex, $content);
}

/**
 * Returns ['slugOrDmKey' => true|false] for sources whose unread increased.
 * Excludes self-sent messages. Scans up to LIMIT rows for speed.
 */
function kk_computeMentionBumps(PDO $db, array $authUser, array $perRoom, array $perDm = []): array {
    $userId    = (int)$authUser['id'];
    $dispName  = (string)($authUser['display_name'] ?? $authUser['name'] ?? '');
    $username  = (string)($authUser['username'] ?? $authUser['handle'] ?? $authUser['login'] ?? $dispName);
    $re        = kk_buildMentionRegex($dispName, $username);
    $out       = [];

    // ---- ROOMS ----
    foreach ($perRoom as $slug => $delta) {
        if ((int)$delta <= 0) { continue; }

        // last read id for this user/room (adjust table/columns if different)
        $stmt = $db->prepare("
            SELECT lr.last_msg_id
            FROM last_reads lr
            WHERE lr.user_id = :uid AND lr.room_slug = :slug
            LIMIT 1
        ");
        $stmt->execute([':uid'=>$userId, ':slug'=>$slug]);
        $lastId = (int)($stmt->fetchColumn() ?: 0);

        // Check up to 30 new messages for a mention (exclude self)
        $stmt = $db->prepare("
            SELECT m.content
            FROM messages m
            WHERE m.room_slug = :slug
              AND m.id > :lastId
              AND m.sender_id <> :uid
            ORDER BY m.id DESC
            LIMIT 30
        ");
        $stmt->execute([':slug'=>$slug, ':lastId'=>$lastId, ':uid'=>$userId]);

        $hint = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (kk_textMentionsUser((string)($row['content'] ?? ''), $re)) { $hint = true; break; }
        }
        $out[$slug] = $hint;
    }

    // ---- DMs ---- (skip if your app doesn’t have per-DM counters)
    foreach ($perDm as $dmKey => $delta) {
        if ((int)$delta <= 0) { continue; }

        $stmt = $db->prepare("
            SELECT ldr.last_msg_id
            FROM last_dm_reads ldr
            WHERE ldr.user_id = :uid AND ldr.dm_key = :dmk
            LIMIT 1
        ");
        $stmt->execute([':uid'=>$userId, ':dmk'=>$dmKey]);
        $lastId = (int)($stmt->fetchColumn() ?: 0);

        $stmt = $db->prepare("
            SELECT m.content
            FROM messages m
            WHERE m.dm_key = :dmk
              AND m.id > :lastId
              AND m.sender_id <> :uid
            ORDER BY m.id DESC
            LIMIT 30
        ");
        $stmt->execute([':dmk'=>$dmKey, ':lastId'=>$lastId, ':uid'=>$userId]);

        $hint = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (kk_textMentionsUser((string)($row['content'] ?? ''), $re)) { $hint = true; break; }
        }
        $out[$dmKey] = $hint;
    }

    return $out;
}


  /* =========================================================
   *  NEW: Single batched sync endpoint
   *  Returns unread counts, presence, and new messages
   * ========================================================= */

add_action('rest_api_init', function () {
    $ns = kkchat_rest_namespace();

register_rest_route($ns, '/sync', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) {
    kkchat_require_login(); 
    kkchat_assert_not_blocked_or_fail();
    nocache_headers();

    global $wpdb; $t = kkchat_tables();

    // ✅ Count this as activity so presence doesn't expire
    kkchat_touch_active_user();

    // Read any session-derived values you need, then unlock the session
    $since_pub = isset($_SESSION['kkchat_seen_at_public']) ? (int)$_SESSION['kkchat_seen_at_public'] : 0;

    $me      = kkchat_current_user_id();
    $guest   = kkchat_is_guest() ? 1 : 0;
    kkchat_close_session_if_open();
    $since   = max(-1, (int)$req->get_param('since'));
    $room    = kkchat_sanitize_room_slug((string)$req->get_param('room'));
    if ($room === '') $room = 'general';
    $peer    = $req->get_param('to') !== null ? (int)$req->get_param('to') : null;
    $dmKey   = ($peer !== null) ? kkchat_dm_key($me, $peer) : null;
    $onlyPub = $req->get_param('public') !== null;
    $limit   = (int)$req->get_param('limit');
    if ($limit <= 0) $limit = 250;
    $limit   = max(1, min($limit, 500));

    // Optional long-polling flags
    $do_lp    = ((string)$req->get_param('lp') === '1') && ($since >= 0); // only long-poll with a cursor
    $tmo      = max(5, min(25, (int)($req->get_param('timeout') ?? 25)));
    $deadline = $do_lp ? (time() + $tmo) : 0;

    /* ----------------- lightweight long-poll wait ----------------- */
    if ($do_lp) {
      $checkNew = function() use ($wpdb, $t, $since, $onlyPub, $room, $me, $peer, $dmKey) {
        if ($onlyPub) {
          return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(id),0)
               FROM {$t['messages']}
              WHERE recipient_id IS NULL
                AND room = %s
                AND hidden_at IS NULL
                AND id > %d",
            $room, $since
          ));
        }
        if ($peer && $dmKey !== null) {
          return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(id),0)
               FROM {$t['messages']}
              WHERE id > %d
                AND hidden_at IS NULL
                AND dm_key = %s",
            $since, $dmKey
          ));
        }
        // Fallback: any new DM involving me
        return (int)$wpdb->get_var($wpdb->prepare(
          "SELECT COALESCE(MAX(id),0)
             FROM {$t['messages']}
            WHERE id > %d
              AND hidden_at IS NULL
              AND (recipient_id = %d OR sender_id = %d)",
          $since, $me, $me
        ));
      };


      if ($checkNew() === 0) {
        while (time() < $deadline) {
          usleep(800000); // 0.8s
          if ($checkNew() > 0) break;
        }
      }
    }

    // Recompute $now after any wait to keep TTL math accurate
    $now = time();

    /* ------------ presence (active users) ------------ */
    // Cleanup (presence purge can be heavy; keep it but feel free to make it probabilistic if needed)
    $wpdb->query($wpdb->prepare(
      "DELETE FROM {$t['users']}
        WHERE %d - last_seen > %d",
      $now, kkchat_user_ttl()
    ));
    $wpdb->query($wpdb->prepare(
      "UPDATE {$t['users']}
          SET watch_flag = 0, watch_flag_at = NULL
        WHERE watch_flag = 1
          AND watch_flag_at IS NOT NULL
          AND %d - watch_flag_at > %d",
      $now, kkchat_watch_reset_after()
    ));
    $wpdb->query($wpdb->prepare(
      "UPDATE {$t['users']}
          SET typing_text=NULL, typing_room=NULL, typing_to=NULL, typing_at=NULL
        WHERE typing_at IS NOT NULL AND %d - typing_at > 10",
      $now
    ));

    $admin_names = kkchat_admin_usernames();
    $presence_rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT id,name,gender,typing_text,typing_room,typing_to,typing_at,watch_flag,wp_username,last_seen
           FROM {$t['users']}
          WHERE %d - last_seen <= %d
          ORDER BY name_lc ASC
          LIMIT %d",
        $now,
        max(30, (int)apply_filters('kkchat_presence_active_sec', 120)),
        max(50, (int)apply_filters('kkchat_presence_limit', 200))
      ),
      ARRAY_A
    ) ?: [];

    // 10s window for typing to be considered "active"
    $presence = array_map(function($r) use ($now, $admin_names) {
      $typing = null;
      if (!empty($r['typing_text']) && (int)$r['typing_at'] > $now - 10) {
        $typing = [
          'text' => $r['typing_text'],
          'room' => $r['typing_room'] ?: null,
          'to'   => isset($r['typing_to']) ? (int)$r['typing_to'] : null,
          'at'   => (int)$r['typing_at'],
        ];
      }
      return [
        'id'       => (int)$r['id'],
        'name'     => (string)$r['name'],
        'gender'   => (string)$r['gender'],
        'typing'   => $typing,
        'flagged'  => !empty($r['watch_flag']) ? 1 : 0,
        'is_admin' => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
      ];
    }, $presence_rows);

    /* ------------ unread counts ------------ */
    $blocked = kkchat_blocked_ids($me);

    // Private total
    $params = [$me, $me];
    $blkClause = '';
    if ($blocked) {
      $blkClause = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
      foreach ($blocked as $bid) $params[] = (int)$bid;
    }
    $sqlPriv =
      "SELECT COUNT(*) FROM {$t['messages']} m
       LEFT JOIN {$t['reads']} r ON r.message_id = m.id AND r.user_id = %d
       WHERE m.recipient_id = %d
         AND r.user_id IS NULL
         AND m.hidden_at IS NULL
       $blkClause";
    $totPriv = (int)$wpdb->get_var($wpdb->prepare($sqlPriv, ...$params));

    // Public total (since last seen in public)
    $paramsPub = [$me, $me, $since_pub, $guest];
    $blkClausePub = '';
    if ($blocked) {
      $blkClausePub = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
      foreach ($blocked as $bid) $paramsPub[] = (int)$bid;
    }
    $sqlPub =
      "SELECT COUNT(*) FROM {$t['messages']} m
       LEFT JOIN {$t['reads']} r ON r.message_id = m.id AND r.user_id = %d
       LEFT JOIN {$t['rooms']} rr ON rr.slug = m.room
       WHERE m.recipient_id IS NULL
         AND m.sender_id <> %d
         AND r.user_id IS NULL
         AND rr.slug IS NOT NULL
         AND m.created_at > %d
         AND (%d = 0 OR rr.member_only = 0)
         AND m.hidden_at IS NULL
         $blkClausePub";
    $totPub = (int)$wpdb->get_var($wpdb->prepare($sqlPub, ...$paramsPub));

    // Per-sender DMs
    $paramsPer = [$me, $me];
    $blkPer = '';
    if ($blocked) {
      $blkPer = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
      foreach ($blocked as $bid) $paramsPer[] = (int)$bid;
    }
    $sqlPer =
      "SELECT m.sender_id, COUNT(*) AS c
         FROM {$t['messages']} m
    LEFT JOIN {$t['reads']} r ON r.message_id = m.id AND r.user_id = %d
        WHERE m.recipient_id = %d
          AND r.user_id IS NULL
          AND m.hidden_at IS NULL
          $blkPer
     GROUP BY m.sender_id";
    $per = [];
    $rowsPer = $wpdb->get_results($wpdb->prepare($sqlPer, ...$paramsPer), ARRAY_A) ?: [];
    foreach ($rowsPer as $r) $per[(int)$r['sender_id']] = (int)$r['c'];

    // Per-room public
    $paramsRoom = [$me, $me, $since_pub, $guest];
    $blkRoom = '';
    if ($blocked) {
      $blkRoom = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
      foreach ($blocked as $bid) $paramsRoom[] = (int)$bid;
    }
    $sqlRoom =
      "SELECT m.room AS slug, COUNT(*) AS c
         FROM {$t['messages']} m
    LEFT JOIN {$t['reads']} r ON r.message_id = m.id AND r.user_id = %d
    LEFT JOIN {$t['rooms']} rr ON rr.slug = m.room
        WHERE m.recipient_id IS NULL
          AND m.sender_id <> %d
          AND r.user_id IS NULL
          AND rr.slug IS NOT NULL
          AND m.created_at > %d
          AND (%d = 0 OR rr.member_only = 0)
          AND m.hidden_at IS NULL
          $blkRoom
     GROUP BY m.room";
    $perRoom = [];
    $rowsRoom = $wpdb->get_results($wpdb->prepare($sqlRoom, ...$paramsRoom), ARRAY_A) ?: [];
    foreach ($rowsRoom as $r) $perRoom[$r['slug']] = (int)$r['c'];

    $unread = [
      'totPriv' => $totPriv,
      'totPub'  => $totPub,
      'per'     => (object)$per,
      'rooms'   => (object)$perRoom,
    ];

    /* ------------ messages (like /fetch) ------------ */
    $msgs = [];
    if ($onlyPub) {
      if ($since < 0) {
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT * FROM {$t['messages']}
             WHERE recipient_id IS NULL
               AND room = %s
               AND hidden_at IS NULL
             ORDER BY id DESC
             LIMIT %d",
            $room, $limit
          ),
          ARRAY_A
        ) ?: [];
        $rows = array_reverse($rows);
      } else {
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT * FROM {$t['messages']}
             WHERE id > %d
               AND recipient_id IS NULL
               AND room = %s
               AND hidden_at IS NULL
             ORDER BY id ASC
             LIMIT %d",
            $since, $room, $limit
          ),
          ARRAY_A
        ) ?: [];
      }
    } else {
      if ($peer) {
        if ($since < 0) {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "(SELECT * FROM {$t['messages']}
                 WHERE sender_id = %d
                   AND recipient_id = %d
                   AND hidden_at IS NULL
                 ORDER BY id DESC LIMIT %d)
               UNION ALL
               (SELECT * FROM {$t['messages']}
                 WHERE sender_id = %d
                   AND recipient_id = %d
                   AND hidden_at IS NULL
                 ORDER BY id DESC LIMIT %d)
               ORDER BY id ASC
               LIMIT %d",
              $me, $peer, $limit,
              $peer, $me, $limit,
              $limit
            ),
            ARRAY_A
          ) ?: [];
        } else {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "(SELECT * FROM {$t['messages']}
                 WHERE id > %d
                   AND sender_id = %d
                   AND recipient_id = %d
                   AND hidden_at IS NULL
                 ORDER BY id ASC
                 LIMIT %d)
               UNION ALL
               (SELECT * FROM {$t['messages']}
                 WHERE id > %d
                   AND sender_id = %d
                   AND recipient_id = %d
                   AND hidden_at IS NULL
                 ORDER BY id ASC
                 LIMIT %d)
               ORDER BY id ASC
               LIMIT %d",
              $since, $me, $peer, $limit,
              $since, $peer, $me, $limit,
              $limit
            ),
            ARRAY_A
          ) ?: [];
        }
      } else {
        // Legacy: all DMs to/from me (kept for compatibility)
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "(SELECT * FROM {$t['messages']}
               WHERE id > %d
                 AND recipient_id = %d
                 AND hidden_at IS NULL
               ORDER BY id ASC
               LIMIT %d)
             UNION ALL
             (SELECT * FROM {$t['messages']}
               WHERE id > %d
                 AND sender_id = %d
                 AND hidden_at IS NULL
               ORDER BY id ASC
               LIMIT %d)
             ORDER BY id ASC
             LIMIT %d",
            $since, $me, $limit,
            $since, $me, $limit,
            $limit
          ),
          ARRAY_A
        ) ?: [];
      }
    }

    // Apply block filter + attach read_by
    if (!empty($rows)) {
      if ($blocked) {
        $rows = array_values(array_filter($rows, function($r) use ($blocked, $me, $onlyPub){
          $sid = (int)$r['sender_id'];
          if (!$onlyPub && $sid === $me) return true; // show own DMs
          return !in_array($sid, $blocked, true);
        }));
      }
      if (!empty($rows)) {
        $ids = array_map(fn($r)=>(int)$r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $read_rows = $wpdb->get_results(
          $wpdb->prepare("SELECT message_id, user_id FROM {$t['reads']} WHERE message_id IN ($placeholders)", ...$ids),
          ARRAY_A
        ) ?: [];
        $read_map = [];
        foreach ($read_rows as $rr) {
          $mid = (int)$rr['message_id']; $uid = (int)$rr['user_id'];
          $read_map[$mid][] = $uid;
        }
        foreach ($rows as $r) {
          $mid = (int)$r['id'];
          $msgs[] = [
            'id'             => $mid,
            'time'           => (int)$r['created_at'],
            'kind'           => $r['kind'] ?: 'chat',
            'room'           => $r['room'] ?: null,
            'sender_id'      => (int)$r['sender_id'],
            'sender_name'    => (string)$r['sender_name'],
            'recipient_id'   => isset($r['recipient_id']) ? (int)$r['recipient_id'] : null,
            'recipient_name' => $r['recipient_name'] ?: null,
            'content'        => $r['content'],
            'read_by'        => isset($read_map[$mid]) ? array_values(array_map('intval',$read_map[$mid])) : [],
          ];
        }
      }
    }

    /* ------------ mention hints for public rooms ------------ */
    // Build mention regex for current user (match @Display Name or @wp_username, case-insensitive)
    $urow = $wpdb->get_row($wpdb->prepare(
      "SELECT name, wp_username FROM {$t['users']} WHERE id = %d LIMIT 1", $me
    ), ARRAY_A);
    $dispName = trim((string)($urow['name'] ?? '')) ?: ('User'.$me);
    $wpUser   = trim((string)($urow['wp_username'] ?? ''));
    $parts    = array_filter([preg_quote($dispName, '/'), $wpUser !== '' ? preg_quote($wpUser, '/') : null]);
    $nameAlt  = implode('|', $parts);
    // (^|[^\w]) ensures non-word or start before '@'; (?=$|\W) ensures a boundary after the name
    $mentionRe = $nameAlt !== '' ? "/(^|[^\\w])@(?:{$nameAlt})(?=$|\\W)/i" : null;

    $mention_bumps = [];
    if ($mentionRe && !empty($perRoom)) {
      foreach ($perRoom as $slug => $count) {
        if ((int)$count <= 0) continue;

        // Query up to 30 unread public messages in this room since $since_pub with same filters as unread counts
        $paramsMB = [$me, $me, $slug, $since_pub, $guest];
        $blkMB = '';
        if ($blocked) {
          $blkMB = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
          foreach ($blocked as $bid) $paramsMB[] = (int)$bid;
        }

        $sqlMB =
          "SELECT m.content
             FROM {$t['messages']} m
        LEFT JOIN {$t['reads']} r ON r.message_id = m.id AND r.user_id = %d
        LEFT JOIN {$t['rooms']} rr ON rr.slug = m.room
            WHERE m.recipient_id IS NULL
              AND m.sender_id <> %d
              AND r.user_id IS NULL
              AND rr.slug = %s
              AND rr.slug IS NOT NULL
              AND m.created_at > %d
              AND (%d = 0 OR rr.member_only = 0)
              AND m.hidden_at IS NULL
              $blkMB
         ORDER BY m.id DESC
            LIMIT 30";

        $rowsMB = $wpdb->get_results($wpdb->prepare($sqlMB, ...$paramsMB), ARRAY_A) ?: [];
        $hit = false;
        foreach ($rowsMB as $rowMB) {
          $content = (string)($rowMB['content'] ?? '');
          if ($content !== '' && preg_match($mentionRe, $content)) { $hit = true; break; }
        }
        $mention_bumps[$slug] = $hit ? true : false;
      }
    }

    kkchat_json([
      'now'           => $now,
      'unread'        => $unread,
      'presence'      => $presence,
      'messages'      => $msgs,
      'mention_bumps' => (object)$mention_bumps, // e.g. { "general": true, "photography": false }
    ]);
  },
  'permission_callback' => '__return_true',
]);

  /* =========================================================
   *                     Core endpoints (guarded)
   * ========================================================= */


});
