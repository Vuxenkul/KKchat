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
        "UPDATE {$t['users']}\n            SET watch_flag = 0, watch_flag_at = NULL\n          WHERE watch_flag = 1\n            AND watch_flag_at IS NOT NULL\n            AND %d - watch_flag_at > %d",
        $now,
        kkchat_watch_reset_after()
      )
    );

    if ($is_admin_viewer) {
      $rows = $wpdb->get_results(
        "SELECT u.id,\n                u.name,\n                u.gender,\n                u.watch_flag,\n                u.wp_username,\n                u.last_seen,\n                lm.last_content,\n                lm.last_room,\n                lm.last_recipient_id,\n                lm.last_recipient_name,\n                lm.last_kind,\n                lm.last_created_at\n           FROM {$t['users']} u\n      LEFT JOIN (\n            SELECT m.sender_id,\n                   SUBSTRING(m.content, 1, 200) AS last_content,\n                   m.room AS last_room,\n                   m.recipient_id AS last_recipient_id,\n                   m.recipient_name AS last_recipient_name,\n                   m.kind AS last_kind,\n                   m.created_at AS last_created_at\n              FROM {$t['messages']} m\n        INNER JOIN (\n                  SELECT sender_id, MAX(id) AS last_id\n                    FROM {$t['messages']}\n                   WHERE hidden_at IS NULL\n                GROUP BY sender_id\n                ) latest\n                ON latest.sender_id = m.sender_id AND latest.last_id = m.id\n             WHERE m.hidden_at IS NULL\n          ) lm ON lm.sender_id = u.id\n       ORDER BY u.name ASC",
        ARRAY_A
      );

      $out = [];
      foreach ($rows ?? [] as $r) {
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

        $out[] = [
          'id'           => (int) $r['id'],
          'name'         => (string) $r['name'],
          'gender'       => (string) ($r['gender'] ?? ''),
          'flagged'      => !empty($r['watch_flag']) ? 1 : 0,
          'is_admin'     => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
          'last_seen'    => (int) ($r['last_seen'] ?? 0),
          'last_message' => $lastMsg,
        ];
      }
      return kkchat_json($out);
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
