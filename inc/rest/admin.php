<?php
if (!defined('ABSPATH')) exit;

  // Admin: fetch a user's messages (for the receipt overlay)
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
                   content,
                   reply_to_id, reply_to_sender_id, reply_to_sender_name, reply_to_excerpt
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
        'reply_to_id'          => isset($m['reply_to_id']) ? (int)$m['reply_to_id'] : null,
        'reply_to_sender_id'   => isset($m['reply_to_sender_id']) ? (int)$m['reply_to_sender_id'] : null,
        'reply_to_sender_name' => $m['reply_to_sender_name'] ?: null,
        'reply_to_excerpt'     => $m['reply_to_excerpt'] ?: null,
      ];
    }, $rows);

    $next_before = (count($rows) === $limit) ? (int)end($rows)['id'] : null;

    kkchat_json(['ok'=>true,'rows'=>$out,'next_before'=>$next_before]);
  },
  'permission_callback' => '__return_true',
]);

register_rest_route($ns, '/admin/visibility', [
  'methods'  => 'POST',
  'callback' => function (WP_REST_Request $req) use ($require_admin) {
    $require_admin();
    kkchat_check_csrf_or_fail($req);

    $me = kkchat_touch_active_user();
    if ($me <= 0) {
      kkchat_json(['ok' => false, 'err' => 'no_user'], 400);
    }

    global $wpdb; $t = kkchat_tables();

    $current = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT is_hidden FROM {$t['users']} WHERE id = %d",
      $me
    ));

    $raw = $req->get_param('hidden');
    if (is_array($raw)) {
      $raw = reset($raw);
    }
    $raw = is_string($raw) ? trim($raw) : $raw;

    $target = null;
    if ($raw === null || $raw === '' || (is_string($raw) && strtolower($raw) === 'toggle')) {
      $target = $current ? 0 : 1;
    } else {
      $normalized = is_string($raw) ? strtolower($raw) : $raw;
      if ($normalized === 1 || $normalized === true || $normalized === '1' || $normalized === 'true' || $normalized === 'yes' || $normalized === 'on') {
        $target = 1;
      } elseif ($normalized === 0 || $normalized === false || $normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
        $target = 0;
      } else {
        kkchat_json(['ok' => false, 'err' => 'bad_param'], 400);
      }
    }

    $target = $target ? 1 : 0;

    if ($target !== $current) {
      $updated = $wpdb->update(
        $t['users'],
        ['is_hidden' => $target],
        ['id' => $me],
        ['%d'],
        ['%d']
      );

      if ($updated === false) {
        kkchat_json(['ok' => false, 'err' => 'db_error'], 500);
      }

      kkchat_admin_presence_cache_flush();
      if (function_exists('kkchat_public_presence_cache_flush')) {
        kkchat_public_presence_cache_flush();
      }
    }

    $_SESSION['kkchat_auto_hidden'] = $target ? 1 : 0;

    kkchat_json(['ok' => true, 'hidden' => $target]);
  },
  'permission_callback' => '__return_true',
]);
