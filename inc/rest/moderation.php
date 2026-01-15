<?php
if (!defined('ABSPATH')) exit;

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

  register_rest_route($ns, '/moderate/unblock', [
    'methods'=>'POST',
    'callback'=>function(WP_REST_Request $req) use ($require_admin) {
      $require_admin(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();
      $id = (int)$req->get_param('block_id');
      $wpdb->update($t['blocks'], ['active'=>0], ['id'=>$id], ['%d'], ['%d']);
      kkchat_json(['ok'=>true]);
    },
    'permission_callback'=>'__return_true',
  ]);

