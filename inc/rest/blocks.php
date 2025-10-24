<?php
if (!defined('ABSPATH')) exit;

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

