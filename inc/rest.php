<?php
if (!defined('ABSPATH')) exit;

/**
 * REST API (public + admin)
 *
 * Namespace: kkchat/v1
 */
add_action('rest_api_init', function () {
  $ns = 'kkchat/v1';

  /* =========================================================
   *  Session unlock helper — prevents PHP session file lock
   * ========================================================= */
  if (!function_exists('kkchat_close_session_if_open')) {
    function kkchat_close_session_if_open(): void {
      if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
    }
  }

  if (!function_exists('kkchat_public_presence_snapshot')) {
    /**
     * Shared helper for public presence payloads (non-admin views).
     *
     * @param int   $now           Current unix timestamp for freshness/TTL checks.
     * @param int   $window        Seconds of recency to include (<=0 disables the cutoff).
     * @param array $admin_names   Lower-cased admin WP usernames for flagging.
     * @param array $opts          Optional flags: include_flagged.
     */
    function kkchat_public_presence_snapshot(int $now, int $window, array $admin_names, array $opts = []): array {
      global $wpdb; $t = kkchat_tables();

      $includeFlagged = !empty($opts['include_flagged']);

      $limit = (int) apply_filters('kkchat_public_presence_limit', 400);
      if ($limit <= 0) { $limit = 400; }
      $limit = max(1, $limit);

      $cols = ['id','name','gender','wp_username'];
      if ($includeFlagged) { $cols[] = 'watch_flag'; }

      $select = implode(',', $cols);

      $cacheTtl = max(0, (int) apply_filters('kkchat_public_presence_cache_ttl', 2));
      $cacheKey = null;
      if ($cacheTtl > 0 && function_exists('wp_cache_get')) {
        $bucket   = max(1, $cacheTtl);
        $cacheKey = sprintf(
          'presence:%s:%d:%d:%d',
          $includeFlagged ? 'f1' : 'f0',
          $window,
          $limit,
          (int) floor($now / $bucket)
        );
        $cached = wp_cache_get($cacheKey, 'kkchat');
        if (is_array($cached)) {
          return $cached;
        }
      }

      if ($window > 0) {
        $sql = $wpdb->prepare(
          "SELECT {$select}
             FROM {$t['users']}
            WHERE %d - last_seen <= %d
            ORDER BY name_lc ASC
            LIMIT %d",
          $now,
          $window,
          $limit
        );
      } else {
        $sql = $wpdb->prepare(
          "SELECT {$select}
             FROM {$t['users']}
            ORDER BY name_lc ASC
            LIMIT %d",
          $limit
        );
      }

      $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
      $out  = [];

      foreach ($rows as $r) {
        $entry = [
          'id'       => (int) ($r['id'] ?? 0),
          'name'     => (string) ($r['name'] ?? ''),
          'gender'   => (string) ($r['gender'] ?? ''),
          'is_admin' => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
        ];

        if ($includeFlagged) {
          $entry['flagged'] = !empty($r['watch_flag']) ? 1 : 0;
        }

        if ($entry['id'] > 0) {
          $out[] = $entry;
        }
      }

      if ($cacheTtl > 0 && $cacheKey && function_exists('wp_cache_set')) {
        wp_cache_set($cacheKey, $out, 'kkchat', $cacheTtl);
      }

      return $out;
    }
  }

  if (!function_exists('kkchat_sync_build_context')) {
    function kkchat_sync_build_context(WP_REST_Request $req): array {
      kkchat_require_login(false);
      kkchat_assert_not_blocked_or_fail();
      nocache_headers();

      global $wpdb; $t = kkchat_tables();

      kkchat_touch_active_user(false, false);

      $since_pub = isset($_SESSION['kkchat_seen_at_public']) ? (int) $_SESSION['kkchat_seen_at_public'] : 0;

      $me             = kkchat_current_user_id();
      $guest          = kkchat_is_guest() ? 1 : 0;
      $is_admin_viewer = kkchat_is_admin();

      kkchat_close_session_if_open();

      $since = max(-1, (int) $req->get_param('since'));
      $room  = kkchat_sanitize_room_slug((string) $req->get_param('room'));
      if ($room === '') { $room = 'general'; }
      $peer  = $req->get_param('to') !== null ? (int) $req->get_param('to') : null;
      $onlyPub = $req->get_param('public') !== null;

      $limit = (int) $req->get_param('limit');
      if ($limit <= 0) { $limit = 200; }
      $limit = max(1, min($limit, 200));

      return [
        'tables'          => $t,
        'me'              => $me,
        'guest'           => $guest,
        'is_admin_viewer' => $is_admin_viewer,
        'since_pub'       => $since_pub,
        'since'           => $since,
        'room'            => $room,
        'peer'            => $peer,
        'only_public'     => $onlyPub,
        'limit'           => $limit,
      ];
    }
  }

  if (!function_exists('kkchat_sync_build_payload')) {
    function kkchat_sync_build_payload(array $ctx): array {
      global $wpdb; $t = kkchat_tables();

      $now = time();

      $me      = (int) ($ctx['me'] ?? 0);
      $guest   = !empty($ctx['guest']) ? 1 : 0;
      $since   = (int) ($ctx['since'] ?? -1);
      $room    = (string) ($ctx['room'] ?? 'general');
      $peer    = $ctx['peer'] !== null ? (int) $ctx['peer'] : null;
      $onlyPub = !empty($ctx['only_public']);
      $limit   = (int) ($ctx['limit'] ?? 200);
      $limit   = max(1, min($limit, 200));
      $since_pub = (int) ($ctx['since_pub'] ?? 0);
      $is_admin_viewer = !empty($ctx['is_admin_viewer']);

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
      $admin_names = kkchat_admin_usernames();
      $presence    = [];
      if ($is_admin_viewer) {
        $presence_rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT u.id,
                    u.name,
                    u.gender,
                    u.watch_flag,
                    u.wp_username,
                    u.last_seen,
                    lm.last_content,
                    lm.last_room,
                    lm.last_recipient_id,
                    lm.last_recipient_name,
                    lm.last_kind,
                    lm.last_created_at
               FROM {$t['users']} u
          LEFT JOIN (
                SELECT m.sender_id,
                       SUBSTRING(m.content, 1, 200) AS last_content,
                       m.room AS last_room,
                       m.recipient_id AS last_recipient_id,
                       m.recipient_name AS last_recipient_name,
                       m.kind AS last_kind,
                       m.created_at AS last_created_at
                  FROM {$t['messages']} m
            INNER JOIN (
                      SELECT sender_id, MAX(id) AS last_id
                        FROM {$t['messages']}
                       WHERE hidden_at IS NULL
                    GROUP BY sender_id
                    ) latest
                    ON latest.sender_id = m.sender_id AND latest.last_id = m.id
                 WHERE m.hidden_at IS NULL
              ) lm ON lm.sender_id = u.id
              WHERE %d - u.last_seen <= %d
              ORDER BY u.name_lc ASC
              LIMIT %d",
            $now,
            max(30, (int) apply_filters('kkchat_presence_active_sec', 60)),
            max(50, (int) apply_filters('kkchat_presence_limit', 1200))
          ),
          ARRAY_A
        ) ?: [];

        foreach ($presence_rows as $r) {
          $lastMsg = null;
          if (
            isset($r['last_content']) ||
            isset($r['last_room']) ||
            isset($r['last_recipient_id']) ||
            isset($r['last_kind'])
          ) {
            $lastMsg = [
              'text'            => (string) ($r['last_content'] ?? ''),
              'room'            => ($r['last_room'] ?? '') !== '' ? (string) $r['last_room'] : null,
              'to'              => isset($r['last_recipient_id']) ? (int) $r['last_recipient_id'] : null,
              'recipient_name'  => ($r['last_recipient_name'] ?? '') !== '' ? (string) $r['last_recipient_name'] : null,
              'kind'            => (string) ($r['last_kind'] ?? 'chat'),
              'time'            => isset($r['last_created_at']) ? (int) $r['last_created_at'] : null,
            ];
          }

          $presence[] = [
            'id'            => (int) ($r['id'] ?? 0),
            'name'          => (string) ($r['name'] ?? ''),
            'gender'        => (string) ($r['gender'] ?? ''),
            'flagged'       => !empty($r['watch_flag']) ? 1 : 0,
            'is_admin'      => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
            'last_seen'     => (int) ($r['last_seen'] ?? 0),
            'last_message'  => $lastMsg,
          ];
        }
      } else {
        $publicPresenceWindow = max(0, (int) apply_filters('kkchat_public_presence_window', 120));
        $presence = kkchat_public_presence_snapshot($now, $publicPresenceWindow, $admin_names, [
          'include_flagged' => false,
        ]);
      }

      $blocked = kkchat_blocked_ids($me);

      $params = [$me, $me];
      $blkClause = '';
      if ($blocked) {
        $blkClause = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
        foreach ($blocked as $bid) { $params[] = (int) $bid; }
      }
      $sqlPriv =
        "SELECT COUNT(*) FROM {$t['messages']} m
         LEFT JOIN {$t['reads']} r ON r.message_id = m.id AND r.user_id = %d
         WHERE m.recipient_id = %d
           AND r.user_id IS NULL
           AND m.hidden_at IS NULL
         $blkClause";
      $totPriv = (int) $wpdb->get_var($wpdb->prepare($sqlPriv, ...$params));

      $paramsPub = [$me, $me, $since_pub, $guest];
      $blkClausePub = '';
      if ($blocked) {
        $blkClausePub = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
        foreach ($blocked as $bid) { $paramsPub[] = (int) $bid; }
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
      $totPub = (int) $wpdb->get_var($wpdb->prepare($sqlPub, ...$paramsPub));

      $paramsPer = [$me, $me];
      $blkPer = '';
      if ($blocked) {
        $blkPer = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
        foreach ($blocked as $bid) { $paramsPer[] = (int) $bid; }
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
      foreach ($rowsPer as $r) { $per[(int) $r['sender_id']] = (int) $r['c']; }

      $paramsRoom = [$me, $me, $since_pub, $guest];
      $blkRoom = '';
      if ($blocked) {
        $blkRoom = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
        foreach ($blocked as $bid) { $paramsRoom[] = (int) $bid; }
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
      foreach ($rowsRoom as $r) { $perRoom[$r['slug']] = (int) $r['c']; }

      $unread = [
        'totPriv' => $totPriv,
        'totPub'  => $totPub,
        'per'     => (object) $per,
        'rooms'   => (object) $perRoom,
      ];

      $msgs = [];
      $msgColumns = 'id, room, sender_id, sender_name, recipient_id, recipient_name, content, created_at, kind, hidden_at';
      if ($onlyPub) {
        if ($since < 0) {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
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
              "SELECT $msgColumns FROM {$t['messages']}
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
                "SELECT $msgColumns FROM {$t['messages']}
                 WHERE hidden_at IS NULL
                   AND ((sender_id = %d AND recipient_id = %d) OR
                        (sender_id = %d AND recipient_id = %d))
                 ORDER BY id DESC
                 LIMIT %d",
                $me, $peer, $peer, $me, $limit
              ),
              ARRAY_A
            ) ?: [];
            $rows = array_reverse($rows);
          } else {
            $rows = $wpdb->get_results(
              $wpdb->prepare(
                "SELECT $msgColumns FROM {$t['messages']}
                 WHERE id > %d
                   AND hidden_at IS NULL
                   AND ((sender_id = %d AND recipient_id = %d) OR
                        (sender_id = %d AND recipient_id = %d))
                 ORDER BY id ASC
                 LIMIT %d",
                $since, $me, $peer, $peer, $me, $limit
              ),
              ARRAY_A
            ) ?: [];
          }
        } else {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE id > %d
                 AND (recipient_id = %d OR sender_id = %d)
                 AND hidden_at IS NULL
               ORDER BY id ASC
               LIMIT %d",
              $since, $me, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
        }
      }

      if (!empty($rows)) {
        if ($blocked) {
          $rows = array_values(array_filter($rows, function ($r) use ($blocked, $me, $onlyPub) {
            $sid = (int) $r['sender_id'];
            if (!$onlyPub && $sid === $me) { return true; }
            return !in_array($sid, $blocked, true);
          }));
        }

        if (!empty($rows)) {
          $ids = array_map(fn($r) => (int) $r['id'], $rows);
          $placeholders = implode(',', array_fill(0, count($ids), '%d'));
          $read_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT message_id, user_id FROM {$t['reads']} WHERE message_id IN ($placeholders)", ...$ids),
            ARRAY_A
          ) ?: [];
          $read_map = [];
          foreach ($read_rows as $rr) {
            $mid = (int) $rr['message_id'];
            $uid = (int) $rr['user_id'];
            $read_map[$mid][] = $uid;
          }
          foreach ($rows as $r) {
            $mid = (int) $r['id'];
            $msgs[] = [
              'id'             => $mid,
              'time'           => (int) $r['created_at'],
              'kind'           => $r['kind'] ?: 'chat',
              'room'           => $r['room'] ?: null,
              'sender_id'      => (int) $r['sender_id'],
              'sender_name'    => (string) $r['sender_name'],
              'recipient_id'   => isset($r['recipient_id']) ? (int) $r['recipient_id'] : null,
              'recipient_name' => $r['recipient_name'] ?: null,
              'content'        => $r['content'],
              'read_by'        => isset($read_map[$mid]) ? array_values(array_map('intval', $read_map[$mid])) : [],
            ];
          }
        }
      }

      $urow = $wpdb->get_row($wpdb->prepare(
        "SELECT name, wp_username FROM {$t['users']} WHERE id = %d LIMIT 1", $me
      ), ARRAY_A);
      $dispName = trim((string) ($urow['name'] ?? '')) ?: ('User' . $me);
      $wpUser   = trim((string) ($urow['wp_username'] ?? ''));
      $parts    = array_filter([preg_quote($dispName, '/'), $wpUser !== '' ? preg_quote($wpUser, '/') : null]);
      $nameAlt  = implode('|', $parts);
      $mentionRe = $nameAlt !== '' ? "/(^|[^\\w])@(?:{$nameAlt})(?=$|\\W)/i" : null;

      $mention_bumps = [];
      if ($mentionRe && !empty($perRoom)) {
        $mentionSlugs = [];
        foreach ($perRoom as $slug => $count) {
          if ((int) $count > 0) {
            $mentionSlugs[] = (string) $slug;
          }
        }

        if ($mentionSlugs) {
          $paramsMB = [$me, $since_pub, $guest];
          if ($blocked) {
            foreach ($blocked as $bid) { $paramsMB[] = (int) $bid; }
            $blkList = implode(',', array_fill(0, count($blocked), '%d'));
            $blkSql  = " AND m.sender_id NOT IN ($blkList)";
          } else {
            $blkSql = '';
          }
          $inClause = implode(',', array_fill(0, count($mentionSlugs), '%s'));
          foreach ($mentionSlugs as $slug) { $paramsMB[] = $slug; }

          $sqlMB =
            "SELECT m.room, m.content
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
                $blkSql
                AND m.room IN ($inClause)
           ORDER BY m.id DESC
              LIMIT 300";
          $rowsMB = $wpdb->get_results($wpdb->prepare($sqlMB, ...$paramsMB), ARRAY_A) ?: [];

          $grouped = [];
          foreach ($rowsMB as $rowMB) {
            $slug = (string) ($rowMB['slug'] ?? ($rowMB['room'] ?? ''));
            if ($slug === '') { continue; }
            if (!isset($grouped[$slug])) { $grouped[$slug] = []; }
            if (count($grouped[$slug]) >= 30) { continue; }
            $grouped[$slug][] = (string) ($rowMB['content'] ?? '');
          }

          foreach ($mentionSlugs as $slug) {
            $hit = false;
            foreach ($grouped[$slug] ?? [] as $content) {
              if ($content !== '' && preg_match($mentionRe, $content)) { $hit = true; break; }
            }
            $mention_bumps[$slug] = $hit ? true : false;
          }
        }
      }

      return [
        'now'           => $now,
        'unread'        => $unread,
        'presence'      => $presence,
        'messages'      => $msgs,
        'mention_bumps' => (object) $mention_bumps,
      ];
    }
  }

  if (!function_exists('kkchat_sync_max_cursor')) {
    function kkchat_sync_max_cursor(array $payload, int $current): int {
      $max = $current;
      foreach (($payload['messages'] ?? []) as $msg) {
        $mid = isset($msg['id']) ? (int) $msg['id'] : null;
        if ($mid !== null) {
          $max = max($max, $mid);
        }
      }
      return $max;
    }
  }

  if (!function_exists('kkchat_sync_retry_after_hint')) {
    function kkchat_sync_retry_after_hint(array $payload, array $ctx, bool $hasChanges): int {
      $fast = (int) apply_filters('kkchat_sync_retry_after_fast', 3, $ctx, $payload, $hasChanges);
      if ($fast <= 0) { $fast = 3; }

      $idle = (int) apply_filters('kkchat_sync_retry_after_idle', 12, $ctx, $payload, $hasChanges);
      if ($idle <= 0) { $idle = 12; }

      $hint = $hasChanges ? $fast : $idle;
      return max(1, $hint);
    }
  }

  if (!function_exists('kkchat_sync_format_events')) {
    function kkchat_sync_format_events(array $payload, bool $initial): array {
      $event = [
        'type'          => $initial ? 'snapshot' : 'delta',
        'messages'      => array_values($payload['messages'] ?? []),
        'unread'        => $payload['unread'] ?? new stdClass(),
        'presence'      => $payload['presence'] ?? [],
        'mention_bumps' => $payload['mention_bumps'] ?? new stdClass(),
      ];

      return [$event];
    }
  }

  if (!function_exists('kkchat_sync_view_key')) {
    function kkchat_sync_view_key(array $ctx): string {
      $peer = $ctx['peer'] ?? null;
      if ($peer !== null) {
        return 'dm-' . (int) $peer;
      }

      $room = (string) ($ctx['room'] ?? 'general');
      if ($room === '') { $room = 'general'; }
      return 'room-' . $room;
    }
  }

  if (!function_exists('kkchat_sync_build_etag')) {
    function kkchat_sync_build_etag(array $ctx, int $cursor): string {
      $key = kkchat_sync_view_key($ctx);
      return sprintf('W/"%s-%d"', $key, max(-1, $cursor));
    }
  }

  if (!function_exists('kkchat_sync_rate_guard')) {
    function kkchat_sync_rate_guard(int $userId): int {
      if ($userId <= 0) { return 0; }

      $bucket = 'kkchat';
      $now    = microtime(true);

      if (function_exists('wp_cache_get') && function_exists('wp_cache_set')) {
        $banKey = 'sync:penalty:' . $userId;
        $ban    = wp_cache_get($banKey, $bucket);
        if (is_array($ban) && !empty($ban['until']) && $ban['until'] > $now) {
          return (int) ceil($ban['until'] - $now);
        }

        $lastKey = 'sync:last:' . $userId;
        $last    = wp_cache_get($lastKey, $bucket);
        wp_cache_set($lastKey, $now, $bucket, 30);

        if (is_numeric($last) && ($now - (float) $last) < 0.9) {
          $penalty = max(2, (int) apply_filters('kkchat_sync_rate_penalty', 6));
          $until   = $now + $penalty;
          wp_cache_set($banKey, ['until' => $until], $bucket, $penalty);
          return $penalty;
        }
      }

      return 0;
    }
  }

  /* =========================================================
   *                     AUTH
   * ========================================================= */

  register_rest_route($ns, '/login', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_check_csrf_or_fail($req);

      $gender  = trim((string)$req->get_param('login_gender'));
      $allowed = ['Man','Woman','Couple','Trans (MTF)','Trans (FTM)','Non-binary/other'];
      if (!in_array($gender, $allowed, true)) {
        kkchat_json(['ok'=>false,'err'=>'Välj en giltig kategori']);
      }

      $via_wp = ((string)$req->get_param('via_wp') === '1');

      $nick = '';
      $wp_username = '';

      if ($via_wp) {
        // Only allow via_wp for *authenticated* WP users.
        // Trust wp_get_current_user() only — never POST/session.
        if (!is_user_logged_in()) {
          kkchat_json(['ok'=>false,'err'=>'Du är inte inloggad i WordPress'], 403);
        }
        $wp_user = wp_get_current_user();
        if (!$wp_user || empty($wp_user->ID) || empty($wp_user->user_login)) {
          kkchat_json(['ok'=>false,'err'=>'Kunde inte läsa WP-användarnamn'], 400);
        }
        $wp_username = (string)$wp_user->user_login;
        $nick = kkchat_sanitize_name_nosuffix($wp_username);
      } else {
        $nick = kkchat_sanitize_guest_nick((string)$req->get_param('login_nick'));
        if ($nick === '') kkchat_json(['ok'=>false,'err'=>'Välj ett giltigt smeknamn']);
      }

      // Check moderation blocks BEFORE inserting presence
      $ip = kkchat_client_ip();
      $block = kkchat_moderation_block_for(0, $nick, $wp_username, $ip);
      if ($block) {
        if ($block['type']==='ipban') kkchat_json(['ok'=>false,'err'=>'ip_banned','cause'=>$block['row']['cause']??''], 403);
        if ($block['type']==='kick')  kkchat_json(['ok'=>false,'err'=>'kicked','cause'=>$block['row']['cause']??'', 'until'=>$block['row']['expires_at']??null], 403);
      }

      global $wpdb; $t = kkchat_tables();
      $now = time();

      // Clean old presences
      $wpdb->query($wpdb->prepare("DELETE FROM {$t['users']} WHERE %d - last_seen > %d", $now, kkchat_user_ttl()));

      // Insert presence (wp_username is NULL for guests)
      $name_lc = mb_strtolower($nick, 'UTF-8');
      $ins = $wpdb->insert($t['users'], [
        'name'        => $nick,
        'name_lc'     => $name_lc,
        'gender'      => $gender,
        'last_seen'   => $now,
        'ip'          => $ip,
        'wp_username' => $via_wp ? $wp_username : null,
      ], ['%s','%s','%s','%d','%s','%s']);

      if (!$ins) {
        if ($via_wp) {
          // For WP users, replace abandoned presence with same name_lc
          $wpdb->delete($t['users'], ['name_lc' => $name_lc], ['%s']);
          $ins2 = $wpdb->insert($t['users'], [
            'name'        => $nick,
            'name_lc'     => $name_lc,
            'gender'      => $gender,
            'last_seen'   => $now,
            'ip'          => $ip,
            'wp_username' => $wp_username,
          ], ['%s','%s','%s','%d','%s','%s']);
          if (!$ins2) kkchat_json(['ok'=>false,'err'=>'Namnet är upptaget']);
        } else {
          kkchat_json(['ok'=>false,'err'=>'Namnet är upptaget']);
        }
      }

      // Establish chat session
      $_SESSION['kkchat_user_id']        = (int)$wpdb->insert_id;
      $_SESSION['kkchat_user_name']      = $nick;
      $_SESSION['kkchat_gender']         = $gender;
      $_SESSION['kkchat_is_guest']       = $via_wp ? 0 : 1;
      $_SESSION['kkchat_seen_at_public'] = time();
      kkchat_touch_active_user();

      if ($via_wp) {
        $_SESSION['kkchat_wp_username'] = $wp_username;
      } else {
        unset($_SESSION['kkchat_wp_username']);
      }

      // Admin determined strictly by configured list vs real WP username
      $_SESSION['kkchat_is_admin'] = ($via_wp && kkchat_is_admin_username($wp_username)) ? 1 : 0;

      // Prevent session fixation after auth
      if (function_exists('session_regenerate_id')) @session_regenerate_id(true);

      kkchat_json(['ok'=>true, 'is_admin'=>!empty($_SESSION['kkchat_is_admin'])]);
    },
    'permission_callback' => '__return_true',
  ]);


  register_rest_route($ns, '/logout', [
    'methods'  => ['GET','POST'],
    'callback' => function () {
      kkchat_logout_session();
      kkchat_close_session_if_open(); // unlock after session mutation
      kkchat_json(['ok' => true]);
    },
    'permission_callback' => '__return_true',
  ]);

  /* =========================================================
   *                     Image upload
   * ========================================================= */

  register_rest_route($ns, '/upload', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_assert_not_blocked_or_fail(); kkchat_check_csrf_or_fail($req);
      nocache_headers();

      // Simple rate limit to deter abuse (default: 3s gap)
      $gap  = (int) apply_filters('kkchat_upload_min_gap', 3);
      $last = (int) ($_SESSION['kk_last_upload_at'] ?? 0);
      if ($gap > 0 && time() - $last < $gap) {
        kkchat_json(['ok'=>false,'err'=>'too_fast'], 429);
      }

      if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        kkchat_json(['ok'=>false,'err'=>'no_file'], 400);
      }
      $file = $_FILES['file'];

      // Size limit (5 MB default, filterable)
      $max_bytes = (int)apply_filters('kkchat_upload_max_bytes', 5 * 1024 * 1024);
      if (!empty($file['size']) && $file['size'] > $max_bytes) {
        kkchat_json(['ok'=>false,'err'=>'too_large','max'=>$max_bytes], 413);
      }

      // Allow-list mimes (filterable)
      $mimes = (array)apply_filters('kkchat_allowed_image_mimes', [
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'gif'      => 'image/gif',
        'webp'     => 'image/webp',
      ]);

      // Force uploads into /uploads/kkchat/YYYY/MM
      require_once ABSPATH . 'wp-admin/includes/file.php';
      $subroot = (string)apply_filters('kkchat_upload_subdir', '/kkchat'); // keep leading slash

      $upload_dir_filter = function($dirs) use ($subroot) {
        $sub            = trailingslashit($subroot) . ltrim((string)$dirs['subdir'], '/'); // /kkchat/2025/09
        $dirs['path']   = trailingslashit((string)$dirs['basedir']) . ltrim($sub, '/');
        $dirs['url']    = trailingslashit((string)$dirs['baseurl']) . ltrim($sub, '/');
        $dirs['subdir'] = $sub;
        return $dirs;
      };

      add_filter('upload_dir', $upload_dir_filter);
      try {
        $overrides = [
          'test_form' => false,
          'mimes'     => $mimes,
          'unique_filename_callback' => null,
        ];
        $moved = wp_handle_upload($file, $overrides);
      } finally {
        remove_filter('upload_dir', $upload_dir_filter);
      }

      if (!is_array($moved) || empty($moved['url']) || empty($moved['file'])) {
        $err = (is_array($moved) && !empty($moved['error'])) ? $moved['error'] : 'upload_failed';
        kkchat_json(['ok'=>false,'err'=>$err], 400);
      }

      // Double-check it's an image
      $type = (string)($moved['type'] ?? '');
      if (strpos($type, 'image/') !== 0) {
        if (file_exists($moved['file'])) @unlink($moved['file']);
        kkchat_json(['ok'=>false,'err'=>'bad_type'], 400);
      }

      // Ensure file is truly under /uploads/kkchat/
      $up       = wp_upload_dir();
      $basedir  = rtrim((string)$up['basedir'], DIRECTORY_SEPARATOR);
      $baseurl  = rtrim((string)$up['baseurl'], '/');
      $allow_fs = wp_normalize_path($basedir . rtrim($subroot, '/') . '/');
      $real     = wp_normalize_path((string)realpath($moved['file']));
      if ($real === '' || strpos($real, $allow_fs) !== 0) {
        if (file_exists($moved['file'])) @unlink($moved['file']);
        kkchat_json(['ok'=>false,'err'=>'bad_image_scope'], 400);
      }

      // Verify it parses as image (guards against spoofed content)
      if (!@getimagesize($moved['file'])) {
        @unlink($moved['file']);
        kkchat_json(['ok'=>false,'err'=>'bad_image'], 400);
      }

      // Optional: strip EXIF/metadata (off by default to preserve quality)
      $strip = (bool)apply_filters('kkchat_upload_strip_exif', false);
      if ($strip) {
        $editor = wp_get_image_editor($moved['file']);
        if (!is_wp_error($editor)) {
          // Re-save in place; this typically strips metadata
          $saved = $editor->save($moved['file']);
          if (!is_wp_error($saved)) {
            clearstatcache(true, $moved['file']);
          }
        }
      }

      $_SESSION['kk_last_upload_at'] = time();
        kkchat_close_session_if_open(); 
      kkchat_json(['ok'=>true,'url'=>$moved['url']]);
    },
    'permission_callback' => '__return_true',
  ]);



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
register_rest_route($ns, '/sync', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) {
    $ctx = kkchat_sync_build_context($req);

    $penalty = kkchat_sync_rate_guard((int) ($ctx['me'] ?? 0));
    if ($penalty > 0) {
      $resp = new WP_REST_Response(['err' => 'rate_limited'], 429);
      $resp->header('Retry-After', (string) $penalty);
      return $resp;
    }

    $payload = kkchat_sync_build_payload($ctx);
    $since   = (int) ($ctx['since'] ?? -1);
    $cursor  = kkchat_sync_max_cursor($payload, $since);
    $hasChanges = ($since < 0) ? true : ($cursor > $since);

    $retryAfter = kkchat_sync_retry_after_hint($payload, $ctx, $hasChanges);
    $etag       = kkchat_sync_build_etag($ctx, $cursor);

    $headers = [
      'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
      'Pragma'        => 'no-cache',
    ];

    if ($retryAfter > 0) {
      $headers['Retry-After'] = (string) $retryAfter;
    }
    if ($etag !== '') {
      $headers['ETag'] = $etag;
    }

    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';

    if (!$hasChanges && $etag !== '' && $ifNoneMatch !== '' && $ifNoneMatch === $etag) {
      $resp = new WP_REST_Response(null, 304);
      foreach ($headers as $hk => $hv) { $resp->header($hk, $hv); }
      return $resp;
    }

    if (!$hasChanges) {
      $resp = new WP_REST_Response(null, 204);
      foreach ($headers as $hk => $hv) { $resp->header($hk, $hv); }
      return $resp;
    }

    $events = kkchat_sync_format_events($payload, $since < 0);

    $data = [
      'now'        => (int) ($payload['now'] ?? time()),
      'next'       => $cursor,
      'retryAfter' => $retryAfter,
      'events'     => $events,
    ];

    unset($payload['now']);
    foreach ($payload as $k => $v) {
      if (!array_key_exists($k, $data)) {
        $data[$k] = $v;
      }
    }

    $resp = new WP_REST_Response($data, 200);
    foreach ($headers as $hk => $hv) { $resp->header($hk, $hv); }
    return $resp;
  },
  'permission_callback' => '__return_true',
]);

register_rest_route($ns, '/ping', [
  // just add POST; keep everything else unchanged
  'methods'  => ['GET','POST'],
  'callback' => function () {
    // Auth & access checks (may read/write session)
    kkchat_require_login(false);
    kkchat_assert_not_blocked_or_fail();

    // Pings should never be cached
    nocache_headers();

    // Ensure/refresh my presence row (also ensures session knows my id)
    $uid = kkchat_touch_active_user(false, false);

    // Release the PHP session lock ASAP — ping is frequent
    kkchat_close_session_if_open();

    global $wpdb;
    $t   = kkchat_tables();
    $now = time();

    // Clear expired watch flags
    $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$t['users']}
            SET watch_flag = 0, watch_flag_at = NULL
          WHERE watch_flag = 1
            AND watch_flag_at IS NOT NULL
            AND %d - watch_flag_at > %d",
        $now,
        kkchat_watch_reset_after()
      )
    );

    // (Optional) light, probabilistic purge of very stale presences
    if (mt_rand(1, 20) === 1) {
      $wpdb->query(
        $wpdb->prepare(
          "DELETE FROM {$t['users']}
            WHERE %d - last_seen > %d",
          $now,
          kkchat_user_ttl()
        )
      );
    }

    // --- Admin-only: open report count + rising-edge anchor (cheap) ---
    $reports_open   = 0; // NEW
    $reports_max_id = 0; // NEW
    if (function_exists('kkchat_is_admin') && kkchat_is_admin()) {
      // COUNT(*) on status index; MAX(id) on PK — both fast
      $reports_open   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['reports']} WHERE status='open'");
      $reports_max_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$t['reports']}");
    }

    return kkchat_json([
      'ok'               => true,
      'uid'              => (int)$uid,
      'now'              => $now,
      'reports_open'     => $reports_open,     // NEW
      'reports_max_id'   => $reports_max_id,   // NEW
    ]);
  },
  'permission_callback' => '__return_true',
]);

register_rest_route($ns, '/rooms', [
  'methods'  => 'GET',
  'callback' => function () {
    kkchat_require_login();
    kkchat_assert_not_blocked_or_fail();

    // Rooms are mostly static — allow caching if you want.
    // (Leave nocache_headers() disabled to let client/proxy cache briefly.)
    // nocache_headers();

    // Refresh presence so this request counts as activity
    kkchat_touch_active_user();

    // Release the PHP session lock ASAP
    kkchat_close_session_if_open();

    global $wpdb;
    $t = kkchat_tables();

    // Simple read-only query
    $rows = $wpdb->get_results(
      "SELECT slug, title, member_only, sort
         FROM {$t['rooms']}
        ORDER BY sort ASC, title ASC",
      ARRAY_A
    );

    $guest = kkchat_is_guest();

    $out = array_map(function ($r) use ($guest) {
      $mo = ((int)$r['member_only'] === 1);
      return [
        'slug'        => (string)$r['slug'],
        'title'       => (string)$r['title'],
        'member_only' => $mo,
        'allowed'     => $guest ? !$mo : true,
      ];
    }, $rows ?? []);

    return kkchat_json($out);
  },
  'permission_callback' => '__return_true',
]);


register_rest_route($ns, '/fetch', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) {
    kkchat_require_login(); kkchat_assert_not_blocked_or_fail();
    nocache_headers();

    // Keep presence warm on every fetch
    kkchat_touch_active_user();

    // Unlock session before heavy DB work
    kkchat_close_session_if_open();

    global $wpdb; $t = kkchat_tables();
    $me    = kkchat_current_user_id();
    $since = max(-1, (int)$req->get_param('since'));
    $onlyPublic = $req->get_param('public') !== null;
    $roomParam  = kkchat_sanitize_room_slug((string)$req->get_param('room'));
    if ($roomParam === '') $roomParam = 'general';
    $peer = $req->get_param('to') !== null ? (int)$req->get_param('to') : null;

    // Soft cap to keep payloads small on first load
    $limit = (int)$req->get_param('limit');
    if ($limit <= 0) $limit = 200;                   // sane default
    $limit = max(1, min($limit, 200));               // 1..500

    $blocked = kkchat_blocked_ids($me);
    $msgColumns = 'id, room, sender_id, sender_name, recipient_id, recipient_name, content, created_at, kind, hidden_at';

    if ($onlyPublic) {
      if ($since < 0) {
        // First load: last N messages in the room (ASC order for display)
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT $msgColumns FROM {$t['messages']}
             WHERE recipient_id IS NULL
               AND room = %s
               AND hidden_at IS NULL
             ORDER BY id DESC
             LIMIT %d",
            $roomParam, $limit
          ),
          ARRAY_A
        ) ?: [];
        $rows = array_reverse($rows);
      } else {
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT $msgColumns FROM {$t['messages']}
             WHERE id > %d
               AND recipient_id IS NULL
               AND room = %s
               AND hidden_at IS NULL
             ORDER BY id ASC
             LIMIT %d",
            $since, $roomParam, $limit
          ),
          ARRAY_A
        ) ?: [];
      }
      if ($blocked) {
        $rows = array_values(array_filter($rows, function($r) use ($blocked){
          $sid = (int)$r['sender_id'];
          return !in_array($sid, $blocked, true);
        }));
      }
    } else {
      // DMs
      if ($peer) {
        if ($since < 0) {
          // Last N in thread with specific peer
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE hidden_at IS NULL
                 AND ((sender_id = %d AND recipient_id = %d) OR
                      (sender_id = %d AND recipient_id = %d))
               ORDER BY id DESC
               LIMIT %d",
              $me, $peer, $peer, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
          $rows = array_reverse($rows);
        } else {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE id > %d
                 AND hidden_at IS NULL
                 AND ((sender_id = %d AND recipient_id = %d)
                   OR  (sender_id = %d AND recipient_id = %d))
               ORDER BY id ASC
               LIMIT %d",
              $since, $me, $peer, $peer, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
        }
      } else {
        // Legacy: all DMs to/from me (kept for backward compatibility)
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT $msgColumns FROM {$t['messages']}
             WHERE id > %d
               AND (recipient_id = %d OR sender_id = %d)
               AND hidden_at IS NULL
             ORDER BY id ASC
             LIMIT %d",
            $since, $me, $me, $limit
          ),
          ARRAY_A
        ) ?: [];
      }
      if ($blocked) {
        $rows = array_values(array_filter($rows, function($r) use ($blocked, $me){
          $sid = (int)$r['sender_id'];
          if ($sid === $me) return true;        // always show own messages
          return !in_array($sid, $blocked, true);
        }));
      }
    }

    $out = [];
    if ($rows) {
      foreach ($rows as $r) {
        $mid = (int)$r['id'];
        $out[] = [
          'id'           => $mid,
          'time'         => (int)$r['created_at'],
          'kind'         => $r['kind'] ?: 'chat',
          'room'         => $r['room'] ?: null,
          'sender_id'    => (int)$r['sender_id'],
          'sender_name'  => $r['sender_name'],
          'recipient_id' => isset($r['recipient_id']) ? (int)$r['recipient_id'] : null,
          'recipient_name'=> $r['recipient_name'] ?: null,
          'content'      => $r['content']
        ];
      }
    }
    kkchat_json($out);
  },
  'permission_callback' => '__return_true',
]);

  register_rest_route($ns, '/message', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_assert_not_blocked_or_fail(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();
      $me_id = kkchat_current_user_id();
      $me_nm = kkchat_current_user_name();

      // Kind: 'chat' or 'image'
      $kind = strtolower((string)$req->get_param('kind'));
      if (!in_array($kind, ['chat','image'], true)) {
        $kind = 'chat';
      }

      $txt = '';
      $image_url = '';

      if ($kind === 'image') {
        $image_url = esc_url_raw((string)$req->get_param('image_url'));
        if ($image_url === '') kkchat_json(['ok'=>false,'err'=>'bad_image'], 400);

        // Validate URL is within WP uploads AND under /kkchat/ subdir
        $up = wp_upload_dir();
        $baseurl = rtrim((string)$up['baseurl'], '/');
        $basedir = rtrim((string)$up['basedir'], DIRECTORY_SEPARATOR);

        $subroot = (string)apply_filters('kkchat_upload_subdir', '/kkchat');
        $must_prefix = $baseurl . rtrim($subroot, '/') . '/';
        if (strpos($image_url, $must_prefix) !== 0) {
          kkchat_json(['ok'=>false,'err'=>'bad_image_scope'], 400);
        }
        if (strpos($image_url, $baseurl . '/') !== 0) {
          kkchat_json(['ok'=>false,'err'=>'bad_image_scope'], 400);
        }

        // Harden path resolution (guard against symlinks/traversal)
        $rel   = ltrim(substr($image_url, strlen($baseurl)), '/');
        $fpath = $basedir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $real_base = wp_normalize_path((string)realpath($basedir));
        $real_path = wp_normalize_path((string)realpath($fpath));
        if (!$real_path || !$real_base || strpos($real_path, trailingslashit($real_base)) !== 0) {
          kkchat_json(['ok'=>false,'err'=>'image_missing'], 400);
        }
        if (!file_exists($fpath)) kkchat_json(['ok'=>false,'err'=>'image_missing'], 400);

      } else {
        $txt = trim((string)$req->get_param('content'));
        if ($txt==='' || mb_strlen($txt) > 2000) kkchat_json(['ok'=>false], 400);
      }

      // === Auto moderation by Word Rules (non-admin only) ===
      if ($kind === 'chat' && !kkchat_is_admin()) {
        $rules = kkchat_rules_active();
        $hit_forbid = null; $hit_watch = null;
        foreach ($rules as $r){
          if (!kkchat_rule_matches($r, $txt)) continue;
          if ($r['kind']==='watch' && !$hit_watch) $hit_watch = $r;
          if ($r['kind']==='forbid' && !$hit_forbid) $hit_forbid = $r;
        }
        if ($hit_watch){
          $wpdb->update($t['users'], ['watch_flag'=>1,'watch_flag_at'=>time()], ['id'=>$me_id], ['%d','%d'], ['%d']);
        }
        if ($hit_forbid){
          $now = time();
          $admin = (string)($_SESSION['kkchat_wp_username'] ?? '');
          $cause = 'Forbidden word: "'.$hit_forbid['word'].'"';
          $dur   = $hit_forbid['duration_sec']; // NULL => infinite
          $ip    = kkchat_client_ip();

          if ($hit_forbid['action'] === 'kick'){
            $exp = isset($dur) ? ($now + max(60,(int)$dur)) : null;
            $wpdb->insert($t['blocks'], [
              'type'=>'kick',
              'target_user_id'=>$me_id,
              'target_name'=>$me_nm,
              'target_wp_username'=>$_SESSION['kkchat_wp_username'] ?? null,
              'target_ip'=>null,
              'cause'=>$cause,
              'created_by'=>$admin ?: null,
              'created_at'=>$now,
              'expires_at'=>$exp,
              'active'=>1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);
            if ($exp === null){ $id=(int)$wpdb->insert_id; $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at=NULL WHERE id=%d",$id)); }
          } elseif ($hit_forbid['action'] === 'ipban'){
            $exp = isset($dur) ? ($now + max(60,(int)$dur)) : null;
            $wpdb->insert($t['blocks'], [
              'type'=>'ipban',
              'target_user_id'=>$me_id,
              'target_name'=>$me_nm,
              'target_wp_username'=>$_SESSION['kkchat_wp_username'] ?? null,
              'target_ip'=>kkchat_ip_ban_key($ip),
              'cause'=>$cause,
              'created_by'=>$admin ?: null,
              'created_at'=>$now,
              'expires_at'=>$exp,
              'active'=>1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);
            if ($exp === null){ $id=(int)$wpdb->insert_id; $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at=NULL WHERE id=%d",$id)); }
          }

          // Remove presence and block the send
          $wpdb->delete($t['users'], ['id'=>$me_id], ['%d']);
          kkchat_json(['ok'=>false,'err'=>'auto_moderated','cause'=>$cause], 403);
        }
      }
      // === end automod ===

      // Minimal anti-flood: min interval between messages
      $minGap = max(0, kkchat_min_interval_seconds());
      if ($minGap > 0) {
        $now = time();
        $recent = (int)$wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$t['messages']} WHERE sender_id = %d AND created_at > %d",
          $me_id, $now - $minGap
        ));
        if ($recent > 0) {
          kkchat_json(
            ['ok' => false, 'err' => 'too_fast', 'cause' => 'Du skriver för snabbt. Försök igen strax.'],
            429
          );
        }
      }

      // DM or room
      $recipient = $req->get_param('recipient_id');
      $recipient = ($recipient !== null && $recipient!=='') ? (int)$recipient : null;
      if ($recipient === $me_id) $recipient = null;

      $room = null; $recipient_name = null; $recipient_ip = null;

      if ($recipient !== null) {
        // Respect my blocklist for DMs
        $mineBlocked = kkchat_blocked_ids($me_id);
        if (in_array($recipient, $mineBlocked, true)) {
          kkchat_json(['ok'=>false,'err'=>'blocked_peer'], 403);
        }

        $urow = $wpdb->get_row($wpdb->prepare("SELECT name, ip FROM {$t['users']} WHERE id=%d", $recipient), ARRAY_A);
        if (!$urow) kkchat_json(['ok'=>false], 400);
        $recipient_name = $urow['name'] ?? null;
        $recipient_ip   = $urow['ip']   ?? null;
      } else {
        $room = kkchat_sanitize_room_slug((string)$req->get_param('room'));
        if ($room === '') $room = 'general';
        if (!kkchat_can_access_room($room)) kkchat_json(['ok'=>false,'err'=>'no_room_access'], 403);
      }

      $now = time();
      $sender_ip = kkchat_client_ip();

      // Prepare content + dedupe basis
      if ($kind === 'image') {
        $content = esc_url_raw($image_url);
        $raw     = 'image:' . $content;
      } else {
        $content = $txt;
        $raw     = trim($txt);
      }

      // Strict, small dedupe window on exact content per-context
      $ctx = ($recipient !== null) ? ('dm:' . $recipient) : ('room:' . $room);
      $content_hash = sha1($ctx . '|' . $raw);
      $window = max(1, kkchat_dedupe_window());
      $dupe_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t['messages']}
         WHERE sender_id=%d AND content_hash=%s AND created_at > %d
         ORDER BY id DESC LIMIT 1",
        $me_id, $content_hash, $now - max(1,$window)
      ));
      if ($dupe_id > 0) {
        // Auto-kick repeated duplicate attempts (non-admins)
        if (!kkchat_is_admin()) {
          if (!isset($_SESSION['kk_dupe'])) $_SESSION['kk_dupe'] = [];
          $key = $content_hash;

          $win   = kkchat_dupe_window_seconds();
          $fast  = kkchat_dupe_fast_seconds();
          $max   = kkchat_dupe_max_repeats();
          $mins  = kkchat_dupe_autokick_minutes();

          $rec = $_SESSION['kk_dupe'][$key] ?? ['n'=>0, 'first'=>$now];
          if ($now - $rec['first'] > $win) $rec = ['n'=>0, 'first'=>$now];
          $rec['n']++;
          $_SESSION['kk_dupe'][$key] = $rec;

          if ($mins > 0 && $rec['n'] >= $max && ($now - $rec['first']) <= $fast) {
            $exp = $now + max(60, $mins * 60);
            $admin = (string)($_SESSION['kkchat_wp_username'] ?? '');
            $cause = 'Repeat spam (auto)';

            $wpdb->insert($t['blocks'], [
              'type' => 'kick',
              'target_user_id' => $me_id,
              'target_name' => $me_nm,
              'target_wp_username' => $_SESSION['kkchat_wp_username'] ?? null,
              'target_ip' => null,
              'cause' => $cause,
              'created_by' => $admin ?: null,
              'created_at' => $now,
              'expires_at' => $exp,
              'active' => 1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

            unset($_SESSION['kk_dupe'][$key]);
            $wpdb->delete($t['users'], ['id'=>$me_id], ['%d']);
            kkchat_json(['ok'=>false,'err'=>'auto_moderated','cause'=>$cause], 403);
          }
        }

        kkchat_json(['ok'=>true,'id'=>$dupe_id,'deduped'=>1]);
      }

      $data = [
        'created_at'   => $now,
        'sender_id'    => $me_id,
        'sender_name'  => $me_nm,
        'sender_ip'    => $sender_ip,
        'content_hash' => $content_hash,
        'content'      => $content,
        'kind'         => $kind
      ];
      $format = ['%d','%d','%s','%s','%s','%s','%s'];

      if ($recipient !== null) {
        $data['recipient_id']   = $recipient;
        $data['recipient_name'] = $recipient_name;
        $data['recipient_ip']   = $recipient_ip;
        $format[] = '%d'; $format[] = '%s'; $format[] = '%s';
      } else {
        $data['room'] = $room;
        $format[] = '%s';
      }

      $ok = $wpdb->insert($t['messages'], $data, $format);
      if ($ok === false) kkchat_json(['ok'=>false,'err'=>'db_insert_failed'], 500);

      $mid = (int)$wpdb->insert_id;

      kkchat_json(['ok'=>true,'id'=>$mid]);

    },
    'permission_callback' => '__return_true',
  ]);

register_rest_route($ns, '/reads/mark', [
  'methods'  => 'POST',
  'callback' => function (WP_REST_Request $req) {
    kkchat_require_login();
    kkchat_assert_not_blocked_or_fail();
    kkchat_check_csrf_or_fail($req);   // ✅ require CSRF
    nocache_headers();

    // keep presence warm — but DON'T close the session yet (we'll write to $_SESSION below)
    kkchat_touch_active_user();

    global $wpdb;
    $t  = kkchat_tables();
    $me = (int) kkchat_current_user_id();
    if ($me <= 0) {
      return new WP_REST_Response(['ok' => false, 'error' => 'not_logged_in'], 401);
    }

    // Payload
    $dms          = $req->get_param('dms');                 // array of DM message IDs
    $public_since = (int) ($req->get_param('public_since') ?? 0); // server "now" watermark

    // ---- Mark DM reads (handles both 2-col and 3-col schema) ----------------
    $reads_table = $t['reads'];
    $has_created_at = true;
    try {
      if (function_exists('kkchat_column_exists')) {
        $has_created_at = kkchat_column_exists($reads_table, 'created_at');
      } else {
        $has_created_at = (bool) $wpdb->get_var(
          $wpdb->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = %s
               AND column_name  = 'created_at'
             LIMIT 1",
            $reads_table
          )
        );
      }
    } catch (\Throwable $e) { /* default true */ }

    if (is_array($dms) && !empty($dms)) {
      $ids = array_values(
        array_unique(
          array_filter(array_map('intval', $dms), fn($x) => $x > 0)
        )
      );

      if (!empty($ids)) {
        $now = time();
        foreach (array_chunk($ids, 400) as $chunk) {
          $rows = [];
          $vals = [];

          if ($has_created_at) {
            foreach ($chunk as $mid) {
              $rows[] = '(%d,%d,%d)';   // (message_id, user_id, created_at)
              $vals[] = $mid; $vals[] = $me; $vals[] = $now;
            }
            $sql = "INSERT INTO {$reads_table} (message_id,user_id,created_at) VALUES "
                 . implode(',', $rows)
                 . " ON DUPLICATE KEY UPDATE created_at = GREATEST(created_at, VALUES(created_at))";
          } else {
            foreach ($chunk as $mid) {
              $rows[] = '(%d,%d)';      // (message_id, user_id)
              $vals[] = $mid; $vals[] = $me;
            }
            $sql = "REPLACE INTO {$reads_table} (message_id,user_id) VALUES "
                 . implode(',', $rows);
          }

          // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
          $wpdb->query($wpdb->prepare($sql, ...$vals));
        }
      }
    }

    // ---- Advance public watermark (SESSION WRITE happens here) --------------
    if ($public_since > 0) {
      $prev = (int) ($_SESSION['kkchat_seen_at_public'] ?? 0);
      if ($public_since > $prev) {
        $_SESSION['kkchat_seen_at_public'] = $public_since;
      }
    }

    // ✅ Now it's safe to release the session lock
    kkchat_close_session_if_open();

    return kkchat_json(['ok' => true]);
  },
  'permission_callback' => '__return_true',
]);


  /* =========================================================
   *                     User Reports
   * ========================================================= */

  register_rest_route($ns, '/report', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login();
      kkchat_check_csrf_or_fail($req);

      global $wpdb; $t = kkchat_tables();
      $me_id = kkchat_current_user_id();
      $me_nm = kkchat_current_user_name();
      if ($me_id <= 0 || $me_nm === '') kkchat_json(['ok'=>false,'err'=>'not_logged_in'], 403);

      $reported_id = max(0, (int)$req->get_param('reported_id'));
      $reason = trim((string)$req->get_param('reason'));
      if ($reported_id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_user'], 400);
      if ($reported_id === $me_id) kkchat_json(['ok'=>false,'err'=>'self_report'], 400);
      if ($reason === '' || mb_strlen($reason) > 1000) kkchat_json(['ok'=>false,'err'=>'bad_reason'], 400);

      $u = $wpdb->get_row($wpdb->prepare("SELECT id,name,ip FROM {$t['users']} WHERE id=%d", $reported_id), ARRAY_A);
      if (!$u) kkchat_json(['ok'=>false,'err'=>'user_gone'], 400);

      $now = time();
      $wpdb->insert($t['reports'], [
        'created_at'    => $now,
        'reporter_id'   => $me_id,
        'reporter_name' => $me_nm,
        'reporter_ip'   => kkchat_client_ip(),
        'reported_id'   => (int)$u['id'],
        'reported_name' => (string)$u['name'],
        'reported_ip'   => (string)($u['ip'] ?? ''),
        'reason'        => $reason,
        'status'        => 'open',
      ], ['%d','%d','%s','%s','%d','%s','%s','%s','%s']);

      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);
      kkchat_json(['ok'=>true]);
    },
    'permission_callback' => '__return_true',
  ]);
  // =========================================================
  // Admin: list open/resolved reports (default: open)
  // =========================================================
  register_rest_route($ns, '/reports', [
    'methods'  => 'GET',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login();
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $status = strtolower((string)($req->get_param('status') ?? 'open'));
      if (!in_array($status, ['open','resolved'], true)) $status = 'open';

      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, created_at, reporter_id, reporter_name,
                  reported_id, reported_name, reason
             FROM {$t['reports']}
            WHERE status = %s
            ORDER BY id DESC
            LIMIT 200",
          $status
        ),
        ARRAY_A
      );

      kkchat_json(['ok'=>true,'rows'=>array_map(static function($r){
        return [
          'id'            => (int)$r['id'],
          'created_at'    => (int)$r['created_at'],
          'reporter_id'   => (int)$r['reporter_id'],
          'reporter_name' => (string)$r['reporter_name'],
          'reported_id'   => (int)$r['reported_id'],
          'reported_name' => (string)$r['reported_name'],
          'reason'        => (string)$r['reason'],
        ];
      }, $rows)]);
    },
    'permission_callback' => '__return_true',
  ]);

  // =========================================================
  // Admin: resolve (open -> resolved). No "resolved_by" stored.
  // =========================================================
  register_rest_route($ns, '/reports/resolve', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_check_csrf_or_fail($req);
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $id  = max(0, (int)$req->get_param('id'));
      if ($id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);

      $now = time();
      $updated = $wpdb->update(
        $t['reports'],
        ['status'=>'resolved','resolved_at'=>$now],               // no "resolved_by"
        ['id'=>$id,'status'=>'open'],
        ['%s','%d'],
        ['%d','%s']
      );
      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);

      kkchat_json(['ok'=>true,'updated'=>(int)$updated]);
    },
    'permission_callback' => '__return_true',
  ]);

  // =========================================================
  // Admin: delete report permanently
  // =========================================================
  register_rest_route($ns, '/reports/delete', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_check_csrf_or_fail($req);
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $id = max(0, (int)$req->get_param('id'));
      if ($id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);

      $deleted = $wpdb->delete($t['reports'], ['id'=>$id], ['%d']);
      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);

      kkchat_json(['ok'=>true,'deleted'=>(int)$deleted]);
    },
    'permission_callback' => '__return_true',
  ]);

  /* =========================================================
   *                     Per-user block (uses kkchat.php helpers)
   * ========================================================= */

  // List my blocked IDs
  register_rest_route($ns, '/block/list', [
    'methods'  => 'GET',
    'callback' => function () {
      kkchat_require_login();
      nocache_headers();
      kkchat_close_session_if_open();
      $ids = kkchat_blocked_ids(kkchat_current_user_id());
      kkchat_json(['ok'=>true, 'ids'=>$ids]);
    },
    'permission_callback' => '__return_true',
  ]);

  // Toggle block/unblock target (server-enforced: admins can't be blocked)
  register_rest_route($ns, '/block/toggle', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_check_csrf_or_fail($req);
      $target = max(0, (int)$req->get_param('target_id'));
      if ($target <= 0) kkchat_json(['ok'=>false,'err'=>'bad_target'], 400);

      // Hard block: if target is an admin (by ID), forbid immediately
      if (kkchat_is_admin_id($target)) {
        kkchat_json(['ok'=>false,'err'=>'cant_block_admin'], 403);
      }

      // Toggle using server-only logic (kkchat_block_add checks admin again)
      $res = kkchat_block_toggle($target);
      if (!empty($res['ok']) && array_key_exists('now_blocked', $res)) {
        kkchat_json(['ok'=>true, 'now_blocked'=>!empty($res['now_blocked'])]);
      }

      // Map helper errors to HTTP
      $err = (string)($res['err'] ?? 'error');
      if ($err === 'cant_block_admin') kkchat_json(['ok'=>false,'err'=>$err], 403);
      if ($err === 'not_logged_in' || $err === 'self_block') kkchat_json(['ok'=>false,'err'=>$err], 400);
      kkchat_json(['ok'=>false,'err'=>$err], 400);
    },
    'permission_callback' => '__return_true',
  ]);

  /* =========================================================
   *                     Moderation (admins only)
   * ========================================================= */

  $require_admin = function() {
    kkchat_require_login();
    if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'not_admin'], 403);
  };

    // Hide a message (admins only)
register_rest_route($ns, '/moderate/hide-message', [
  'methods'  => 'POST',
  'callback' => function (WP_REST_Request $req) use ($require_admin) {
    $require_admin(); kkchat_check_csrf_or_fail($req);
    global $wpdb; $t = kkchat_tables();

    $mid = (int) $req->get_param('message_id');
    if ($mid <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);

    // Fetch the message so we know if it's public (room) or a DM (sender/recipient)
    $msg = $wpdb->get_row($wpdb->prepare(
      "SELECT id, room, sender_id, recipient_id FROM {$t['messages']} WHERE id = %d",
      $mid
    ), ARRAY_A);
    if (!$msg) kkchat_json(['ok'=>false,'err'=>'no_message'], 404);

    $cause = sanitize_text_field((string)$req->get_param('cause'));

    // Mark as hidden
    $wpdb->query($wpdb->prepare(
      "UPDATE {$t['messages']}
          SET hidden_at = %d,
              hidden_by = %d,
              hidden_cause = %s
        WHERE id = %d",
      time(), get_current_user_id() ?: 0, ($cause !== '' ? $cause : null), $mid
    ));

    // Emit an invisible moderation event to wake long-poll clients
    // Clients must ignore kind 'mod_hide' and use its content payload to remove the message.
    if (empty($msg['recipient_id'])) {
      // Public message (room)
      $wpdb->insert($t['messages'], [
        'created_at'     => time(),
        'kind'           => 'mod_hide',
        'room'           => $msg['room'],
        'sender_id'      => 0,          // system
        'sender_name'    => '',
        'recipient_id'   => null,
        'recipient_name' => null,
        'content'        => wp_json_encode(['id' => (int)$mid, 'action' => 'hide']),
      ]);
    } else {
      // Direct message: insert into the same sender/recipient channel so both sides wake up
      $wpdb->insert($t['messages'], [
        'created_at'     => time(),
        'kind'           => 'mod_hide',
        'room'           => null,
        'sender_id'      => (int)$msg['sender_id'],
        'sender_name'    => '',
        'recipient_id'   => (int)$msg['recipient_id'],
        'recipient_name' => null,
        'content'        => wp_json_encode(['id' => (int)$mid, 'action' => 'hide']),
      ]);
    }

    kkchat_json(['ok'=>true]);
  },
]);
    
    // Unhide a message (admins only)
    register_rest_route($ns, '/moderate/unhide-message', [
      'methods'  => 'POST',
      'callback' => function (WP_REST_Request $req) use ($require_admin) {
        $require_admin(); kkchat_check_csrf_or_fail($req);
        global $wpdb; $t = kkchat_tables();
    
        $mid = (int) $req->get_param('message_id');
        if ($mid <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);
    
        $exists = (int)$wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$t['messages']} WHERE id = %d", $mid
        ));
        if ($exists === 0) kkchat_json(['ok'=>false,'err'=>'no_message'], 404);
    
        // Use raw SQL so NULLs are truly NULL (wpdb->update can coerce)
        $wpdb->query($wpdb->prepare(
          "UPDATE {$t['messages']}
              SET hidden_at = NULL,
                  hidden_by = NULL,
                  hidden_cause = NULL
            WHERE id = %d",
          $mid
        ));
    
        kkchat_json(['ok'=>true]);
      },
    ]);

  register_rest_route($ns, '/moderate/kick', [
    'methods'=>'POST',
    'callback'=>function(WP_REST_Request $req) use ($require_admin) {
      $require_admin(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();
      $uid = (int)$req->get_param('user_id');
      $minutes = max(1, (int)$req->get_param('minutes'));
      $cause = trim((string)$req->get_param('cause'));
      $admin = (string)($_SESSION['kkchat_wp_username'] ?? '');

      $u = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['users']} WHERE id=%d", $uid), ARRAY_A);
      if (!$u) kkchat_json(['ok'=>false,'err'=>'no_user'], 400);

      $now = time();
      $exp = $now + $minutes*60;

      $wpdb->insert($t['blocks'], [
        'type'=>'kick',
        'target_user_id'=>$uid,
        'target_name'=>$u['name'] ?? null,
        'target_wp_username'=>$u['wp_username'] ?? null,
        'target_ip'=>null,
        'cause'=>$cause ?: null,
        'created_by'=>$admin ?: null,
        'created_at'=>$now,
        'expires_at'=>$exp,
        'active'=>1
      ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

      // Drop presence immediately
      $wpdb->delete($t['users'], ['id'=>$uid], ['%d']);

      kkchat_json(['ok'=>true]);
    },
    'permission_callback'=>'__return_true',
  ]);

  register_rest_route($ns, '/moderate/ipban', [
    'methods'=>'POST',
    'callback'=>function(WP_REST_Request $req) use ($require_admin) {
      $require_admin(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();

      $uid     = (int)$req->get_param('user_id');
      $minutes = (int)$req->get_param('minutes'); // 0 => forever
      $cause   = trim((string)$req->get_param('cause'));
      $admin   = (string)($_SESSION['kkchat_wp_username'] ?? '');

      $u = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['users']} WHERE id=%d", $uid), ARRAY_A);
      if (!$u || empty($u['ip'])) kkchat_json(['ok'=>false,'err'=>'no_ip'], 400);

      $now = time();
      $exp = ($minutes > 0) ? ($now + $minutes*60) : null;

      $wpdb->insert($t['blocks'], [
        'type'               => 'ipban',
        'target_user_id'     => $uid,
        'target_name'        => $u['name'] ?? null,
        'target_wp_username' => $u['wp_username'] ?? null,
        'target_ip'          => kkchat_ip_ban_key($u['ip']),
        'cause'              => $cause ?: null,
        'created_by'         => $admin ?: null,
        'created_at'         => $now,
        'expires_at'         => $exp,  // may be null
        'active'             => 1
      ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

      $id = (int)$wpdb->insert_id;
      if ($exp === null) {
        $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at = NULL WHERE id=%d", $id));
      }

      $wpdb->delete($t['users'], ['id'=>$uid], ['%d']);

      kkchat_json(['ok'=>true]);
    },
    'permission_callback'=>'__return_true',
  ]);

    // Admin: fetch a user's messages (for the 🧾 overlay)
register_rest_route($ns, '/admin/user-messages', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) use ($require_admin) {
    // Auth (may touch/read session)
    $require_admin();

    // This is a read-only, potentially heavy GET — don’t cache and free the session lock
    nocache_headers();
    kkchat_close_session_if_open();

    global $wpdb; $t = kkchat_tables();

    $uid    = max(0, (int)$req->get_param('user_id'));
    $name   = trim((string)$req->get_param('name'));
    $limit  = max(20, min(500, (int)($req->get_param('limit') ?: 200)));
    $before = max(0, (int)$req->get_param('before_id'));

    if ($uid === 0 && $name === '') kkchat_json(['ok'=>false,'err'=>'need_user'], 400);

    $where  = [];
    $params = [];

    if ($uid > 0) {
      $where[]  = "(sender_id = %d OR recipient_id = %d)";
      $params[] = $uid; $params[] = $uid;
    }
    if ($name !== '') {
      $where[]  = "(sender_name = %s OR recipient_name = %s)";
      $params[] = $name; $params[] = $name;
    }
    if ($before > 0) {
      $where[]  = "id < %d";
      $params[] = $before;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', array_map(fn($w)=>"($w)", $where))) : '';

    $sql = "SELECT id,created_at,kind,room,
                   sender_id,sender_name,sender_ip,
                   recipient_id,recipient_name,recipient_ip,
                   content
              FROM {$t['messages']}
              $whereSql
          ORDER BY id DESC
             LIMIT %d";

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$limit])), ARRAY_A) ?: [];

    $out = array_map(function($m){
      return [
        'id'             => (int)$m['id'],
        'time'           => (int)$m['created_at'],
        'kind'           => $m['kind'] ?: 'chat',
        'room'           => $m['room'] ?: null,
        'sender_id'      => (int)$m['sender_id'],
        'sender_name'    => (string)$m['sender_name'],
        'sender_ip'      => $m['sender_ip'] ?: null,
        'recipient_id'   => isset($m['recipient_id']) ? (int)$m['recipient_id'] : null,
        'recipient_name' => $m['recipient_name'] ?: null,
        'recipient_ip'   => $m['recipient_ip'] ?: null,
        'content'        => $m['content'],
      ];
    }, $rows);

    $next_before = (count($rows) === $limit) ? (int)end($rows)['id'] : null;

    kkchat_json(['ok'=>true,'rows'=>$out,'next_before'=>$next_before]);
  },
  'permission_callback' => '__return_true',
]);

});