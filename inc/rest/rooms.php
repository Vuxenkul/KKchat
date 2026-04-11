<?php
if (!defined('ABSPATH')) exit;

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


