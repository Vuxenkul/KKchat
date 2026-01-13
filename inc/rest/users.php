<?php
if (!defined('ABSPATH')) exit;

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

    // Public presence lists should stay lean — limit non-admin views to
    // recently active users (default: last 2 minutes).
    $publicPresenceWindow = max(0, (int) apply_filters('kkchat_public_presence_window', 120));

    // Release the PHP session lock early so this GET can long-run
    // without blocking other requests (especially long-pollers).
    kkchat_close_session_if_open();

    if ($is_admin_viewer) {
      $rows = kkchat_admin_presence_snapshot($now, $admin_names, [
        'active_window' => 0,
        'limit'         => 0,
        'order_column'  => 'name',
      ]);
      return kkchat_json($rows);
    }

    // NON-ADMIN: minimal fields
    $rows = kkchat_public_presence_snapshot($now, $publicPresenceWindow, $admin_names, [
      'include_flagged' => false,
    ]);

    $out = array_map(function ($r) {
      return [
        'id'       => (int) ($r['id'] ?? 0),
        'name'     => (string) ($r['name'] ?? ''),
        'gender'   => (string) ($r['gender'] ?? ''),
        'is_admin' => !empty($r['is_admin']) ? 1 : 0,
      ];
    }, $rows ?? []);

    return kkchat_json($out);
  },
  // We gate inside with kkchat_require_login(); this stays open for REST discovery.
  'permission_callback' => '__return_true',
]);
