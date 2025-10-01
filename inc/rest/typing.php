<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    $ns = kkchat_rest_namespace();

register_rest_route($ns, '/users', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) {
    // Must be logged in and not blocked. These may read/write session.
    kkchat_require_login();
    kkchat_assert_not_blocked_or_fail();

    // Presence is “writey”; do it before releasing the session lock.
    kkchat_touch_active_user();

    // Cache control: presence should not be cached.
    nocache_headers();

    global $wpdb;
    $t   = kkchat_tables();
    $now = time();

    // Read any session-driven bits BEFORE closing session.
    $is_admin_viewer = kkchat_is_admin();
    $admin_names     = kkchat_admin_usernames();

    // Query param (read-only); allow "1", 1, true-ish.
    $typingOnly = ((string) $req->get_param('typing_only') === '1');

    // Release the PHP session lock early so this GET can long-run
    // without blocking other requests (especially long-pollers).
    kkchat_close_session_if_open();

    // Housekeeping: purge stale presences
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$t['users']} WHERE %d - last_seen > %d",
        $now,
        kkchat_user_ttl()
      )
    );

    // Auto-clear watchlist highlights after N seconds
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

    // ADMIN: typing-only slice (last 8s)
    if ($is_admin_viewer && $typingOnly) {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id,name,gender,typing_text,typing_room,typing_to,typing_at,watch_flag,wp_username
             FROM {$t['users']}
            WHERE typing_at IS NOT NULL
              AND %d - typing_at <= 8
         ORDER BY name ASC",
          $now
        ),
        ARRAY_A
      );

      $out = [];
      foreach ($rows ?? [] as $r) {
        $out[] = [
          'id'       => (int) $r['id'],
          'name'     => (string) $r['name'],
          'gender'   => (string) ($r['gender'] ?? ''),
          'typing'   => [
            'text' => (string) ($r['typing_text'] ?? ''),
            'room' => ($r['typing_room'] ?? '') !== '' ? (string) $r['typing_room'] : null,
            'to'   => isset($r['typing_to']) ? (int) $r['typing_to'] : null,
            'at'   => (int) ($r['typing_at'] ?? 0),
          ],
          'flagged'  => !empty($r['watch_flag']) ? 1 : 0,
          'is_admin' => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
        ];
      }
      return kkchat_json($out);
    }

    // ADMIN: full presence with recent typing
    if ($is_admin_viewer) {
      $rows = $wpdb->get_results(
        "SELECT id,name,gender,typing_text,typing_room,typing_to,typing_at,watch_flag,wp_username
           FROM {$t['users']}
       ORDER BY name ASC",
        ARRAY_A
      );

      $out = [];
      foreach ($rows ?? [] as $r) {
        $typing = null;
        if (!empty($r['typing_text']) && (int) $r['typing_at'] > $now - 8) {
          $typing = [
            'text' => (string) $r['typing_text'],
            'room' => ($r['typing_room'] ?? '') !== '' ? (string) $r['typing_room'] : null,
            'to'   => isset($r['typing_to']) ? (int) $r['typing_to'] : null,
            'at'   => (int) $r['typing_at'],
          ];
        }

        $out[] = [
          'id'       => (int) $r['id'],
          'name'     => (string) $r['name'],
          'gender'   => (string) ($r['gender'] ?? ''),
          'typing'   => $typing,
          'flagged'  => !empty($r['watch_flag']) ? 1 : 0,
          'is_admin' => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
        ];
      }
      return kkchat_json($out);
    }

    // NON-ADMIN: no typing detail, minimal fields
    $rows = $wpdb->get_results(
      "SELECT id,name,gender,wp_username
         FROM {$t['users']}
     ORDER BY name ASC",
      ARRAY_A
    );

    $out = array_map(function ($r) use ($admin_names) {
      return [
        'id'       => (int) $r['id'],
        'name'     => (string) $r['name'],
        'gender'   => (string) ($r['gender'] ?? ''),
        'is_admin' => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
      ];
    }, $rows ?? []);

    return kkchat_json($out);
  },
  // We gate inside with kkchat_require_login(); this stays open for REST discovery.
  'permission_callback' => '__return_true',
]);


register_rest_route($ns, '/ping', [
  // just add POST; keep everything else unchanged
  'methods'  => ['GET','POST'],
  'callback' => function () {
    // Auth & access checks (may read/write session)
    kkchat_require_login();
    kkchat_assert_not_blocked_or_fail();

    // Pings should never be cached
    nocache_headers();

    // Ensure/refresh my presence row (also ensures session knows my id)
    $uid = kkchat_touch_active_user();

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

    // Clear stale typing previews (>10s)
    $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$t['users']}
            SET typing_text = NULL,
                typing_room = NULL,
                typing_to   = NULL,
                typing_at   = NULL
          WHERE typing_at IS NOT NULL
            AND %d - typing_at > 10",
        $now
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


register_rest_route($ns, '/typing', [
    'methods'  => 'POST',
    'callback' => function(WP_REST_Request $req){
      kkchat_require_login(); kkchat_assert_not_blocked_or_fail(); kkchat_check_csrf_or_fail($req);
      nocache_headers();

      global $wpdb; $t = kkchat_tables();
      $me = kkchat_current_user_id();
      $ctx = (string)$req->get_param('context'); // 'public' | 'dm'
      $text = trim((string)$req->get_param('text'));
      $text = mb_substr($text, 0, 200);
      $now  = time();

      $typing_room = null; $typing_to = null;

      if ($ctx === 'public') {
        $room = kkchat_sanitize_room_slug((string)$req->get_param('room'));
        if ($room==='') $room='general';
        $typing_room = $room;
      } elseif ($ctx === 'dm') {
        $to = (int)$req->get_param('to');
        if ($to>0) $typing_to = $to;
      } else {
        kkchat_json(['ok'=>false,'err'=>'bad_ctx'], 400);
      }

      // live watchlist flag while typing (non-admins only)
      if (!kkchat_is_admin() && $text !== '') {
        foreach (kkchat_rules_active() as $r) {
          if (($r['kind'] ?? 'forbid') === 'watch' && kkchat_rule_matches($r, $text)) {
            $wpdb->update($t['users'], ['watch_flag'=>1,'watch_flag_at'=>$now], ['id'=>$me], ['%d','%d'], ['%d']);
            break;
          }
        }
      }

      if ($text === '') {
        $wpdb->query($wpdb->prepare(
          "UPDATE {$t['users']} SET typing_text=NULL, typing_room=NULL, typing_to=NULL, typing_at=NULL WHERE id=%d", $me
        ));
        return kkchat_json(['ok'=>true]);
      }

      $sql = "UPDATE {$t['users']} SET typing_text=%s, ";
      $args = [$text];

      if ($typing_room === null) {
        $sql .= "typing_room=NULL, ";
      } else {
        $sql .= "typing_room=%s, ";
        $args[] = $typing_room;
      }

      if ($typing_to === null) {
        $sql .= "typing_to=NULL, ";
      } else {
        $sql .= "typing_to=%d, ";
        $args[] = $typing_to;
      }

      $sql .= "typing_at=%d WHERE id=%d";
      $args[] = $now;
      $args[] = $me;

      $wpdb->query($wpdb->prepare($sql, ...$args));

      kkchat_json(['ok'=>true]);
    },
    'permission_callback' => '__return_true',
  ]);

  /* =========================================================
   *                     User Reports
   * ========================================================= */

  
});
