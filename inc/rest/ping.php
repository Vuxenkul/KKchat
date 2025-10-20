<?php
if (!defined('ABSPATH')) exit;

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
    $uid = kkchat_touch_active_user(true, false);

    // Release the PHP session lock ASAP — ping is frequent
    kkchat_close_session_if_open();

    kkchat_wpdb_reconnect_if_needed();
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

