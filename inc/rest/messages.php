<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    $ns = kkchat_rest_namespace();

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
    $dmKey = ($peer !== null) ? kkchat_dm_key($me, $peer) : null;

    // Soft cap to keep payloads small on first load
    $limit = (int)$req->get_param('limit');
    if ($limit <= 0) $limit = 250;                   // sane default
    $limit = max(1, min($limit, 500));               // 1..500

    $blocked = kkchat_blocked_ids($me);

    if ($onlyPublic) {
      if ($since < 0) {
        // First load: last N messages in the room (ASC order for display)
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT * FROM {$t['messages']}
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
            "SELECT * FROM {$t['messages']}
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
      if ($peer && $dmKey !== null) {
        if ($since < 0) {
          // Last N in thread with specific peer
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT * FROM {$t['messages']}"
              . " WHERE dm_key = %s"
              . "   AND hidden_at IS NULL"
              . " ORDER BY id DESC"
              . " LIMIT %d",
              $dmKey, $limit
            ),
            ARRAY_A
          ) ?: [];
          $rows = array_reverse($rows);
        } else {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT * FROM {$t['messages']}"
              . " WHERE id > %d"
              . "   AND dm_key = %s"
              . "   AND hidden_at IS NULL"
              . " ORDER BY id ASC"
              . " LIMIT %d",
              $since, $dmKey, $limit
            ),
            ARRAY_A
          ) ?: [];
        }
      } else {
        // Legacy: all DMs to/from me (kept for backward compatibility)
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

      // Kind: 'chat' (default) or 'image'
      $kind = (string)$req->get_param('kind');
      $kind = ($kind === 'image') ? 'image' : 'chat';

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
        $content = kkchat_html_esc($txt);
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
        $wpdb->query($wpdb->prepare("UPDATE {$t['users']} SET typing_text=NULL, typing_room=NULL, typing_to=NULL, typing_at=NULL WHERE id=%d", $me_id));

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
        $dm_key = kkchat_dm_key($me_id, $recipient);
        $data['recipient_id']   = $recipient;
        $data['recipient_name'] = $recipient_name;
        $data['recipient_ip']   = $recipient_ip;
        $data['dm_key']         = $dm_key;
        $format[] = '%d'; $format[] = '%s'; $format[] = '%s'; $format[] = '%s';
      } else {
        $data['room'] = $room;
        $format[] = '%s';
      }

      $ok = $wpdb->insert($t['messages'], $data, $format);
      if ($ok === false) kkchat_json(['ok'=>false,'err'=>'db_insert_failed'], 500);

      $mid = (int)$wpdb->insert_id;

    // Write a short-lived preview so admins see the last sent message (room or DM).
    // It expires via the existing 8–10s cleanup in /users, /sync and /ping.
    if ($kind === 'chat') {
      $preview = mb_substr(trim((string)$txt), 0, 200);
      // Prefer a fresh timestamp; $now is already set above, but reusing is fine.
      $ts = time();
    
      if ($recipient !== null) {
        // DM context
        $wpdb->query($wpdb->prepare(
          "UPDATE {$t['users']}
              SET typing_text=%s, typing_room=NULL, typing_to=%d, typing_at=%d
            WHERE id=%d",
          $preview, (int)$recipient, $ts, (int)$me_id
        ));
      } else {
        // Room context
        $wpdb->query($wpdb->prepare(
          "UPDATE {$t['users']}
              SET typing_text=%s, typing_room=%s, typing_to=NULL, typing_at=%d
            WHERE id=%d",
          $preview, $room, $ts, (int)$me_id
        ));
      }
    } else {
      // Non-text messages: keep typing fields blank
      $wpdb->query($wpdb->prepare(
        "UPDATE {$t['users']} SET typing_text=NULL, typing_room=NULL, typing_to=NULL, typing_at=NULL WHERE id=%d",
        (int)$me_id
      ));
    }
    
    kkchat_json(['ok'=>true,'id'=>$mid]);

    },
    'permission_callback' => '__return_true',
  ]);


});
