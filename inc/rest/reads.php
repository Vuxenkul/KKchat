<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    $ns = kkchat_rest_namespace();

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
   *                     Typing
   * ========================================================= */

  
});
