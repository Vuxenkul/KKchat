<?php
if (!defined('ABSPATH')) exit;

  /* =========================================================
   *                     User Reports
   * ========================================================= */

  register_rest_route($ns, '/report', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login();
      kkchat_check_csrf_or_fail($req);

      global $wpdb; $t = kkchat_tables();
      $me_id = kkchat_current_user_id();
      $me_nm = kkchat_current_user_name();
      if ($me_id <= 0 || $me_nm === '') kkchat_json(['ok'=>false,'err'=>'not_logged_in'], 403);

      $reported_id = max(0, (int)$req->get_param('reported_id'));
      $reason = trim((string)$req->get_param('reason'));
      if ($reported_id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_user'], 400);
      if ($reported_id === $me_id) kkchat_json(['ok'=>false,'err'=>'self_report'], 400);
      if ($reason === '' || mb_strlen($reason) > 1000) kkchat_json(['ok'=>false,'err'=>'bad_reason'], 400);

      $u = $wpdb->get_row($wpdb->prepare("SELECT id,name,ip FROM {$t['users']} WHERE id=%d", $reported_id), ARRAY_A);
      if (!$u) kkchat_json(['ok'=>false,'err'=>'user_gone'], 400);

      $now = time();
      $wpdb->insert($t['reports'], [
        'created_at'    => $now,
        'reporter_id'   => $me_id,
        'reporter_name' => $me_nm,
        'reporter_ip'   => kkchat_client_ip(),
        'reported_id'   => (int)$u['id'],
        'reported_name' => (string)$u['name'],
        'reported_ip'   => (string)($u['ip'] ?? ''),
        'reason'        => $reason,
        'status'        => 'open',
      ], ['%d','%d','%s','%s','%d','%s','%s','%s','%s']);

      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);
      kkchat_json(['ok'=>true]);
    },
    'permission_callback' => '__return_true',
  ]);
  // =========================================================
  // Admin: list open/resolved reports (default: open)
  // =========================================================
  register_rest_route($ns, '/reports', [
    'methods'  => 'GET',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login();
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $status = strtolower((string)($req->get_param('status') ?? 'open'));
      if (!in_array($status, ['open','resolved'], true)) $status = 'open';

      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, created_at, reporter_id, reporter_name,
                  reported_id, reported_name, reason
             FROM {$t['reports']}
            WHERE status = %s
            ORDER BY id DESC
            LIMIT 200",
          $status
        ),
        ARRAY_A
      );

      kkchat_json(['ok'=>true,'rows'=>array_map(static function($r){
        return [
          'id'            => (int)$r['id'],
          'created_at'    => (int)$r['created_at'],
          'reporter_id'   => (int)$r['reporter_id'],
          'reporter_name' => (string)$r['reporter_name'],
          'reported_id'   => (int)$r['reported_id'],
          'reported_name' => (string)$r['reported_name'],
          'reason'        => (string)$r['reason'],
        ];
      }, $rows)]);
    },
    'permission_callback' => '__return_true',
  ]);

  // =========================================================
  // Admin: resolve (open -> resolved). No "resolved_by" stored.
  // =========================================================
  register_rest_route($ns, '/reports/resolve', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_check_csrf_or_fail($req);
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $id  = max(0, (int)$req->get_param('id'));
      if ($id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);

      $now = time();
      $updated = $wpdb->update(
        $t['reports'],
        ['status'=>'resolved','resolved_at'=>$now],               // no "resolved_by"
        ['id'=>$id,'status'=>'open'],
        ['%s','%d'],
        ['%d','%s']
      );
      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);

      kkchat_json(['ok'=>true,'updated'=>(int)$updated]);
    },
    'permission_callback' => '__return_true',
  ]);

  // =========================================================
  // Admin: delete report permanently
  // =========================================================
  register_rest_route($ns, '/reports/delete', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_check_csrf_or_fail($req);
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $id = max(0, (int)$req->get_param('id'));
      if ($id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);

      $deleted = $wpdb->delete($t['reports'], ['id'=>$id], ['%d']);
      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);

      kkchat_json(['ok'=>true,'deleted'=>(int)$deleted]);
    },
    'permission_callback' => '__return_true',
  ]);

