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
      $reason_keys_raw = $req->get_param('reason_keys');
      $reason_other = trim((string)$req->get_param('reason_other'));
      if ($reported_id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_user'], 400);
      if ($reported_id === $me_id) kkchat_json(['ok'=>false,'err'=>'self_report'], 400);

      $reason_keys = [];
      if (is_string($reason_keys_raw) && $reason_keys_raw !== '') {
        $decoded = json_decode($reason_keys_raw, true);
        if (is_array($decoded)) {
          $reason_keys = $decoded;
        } else {
          $reason_keys = array_map('trim', explode(',', $reason_keys_raw));
        }
      } elseif (is_array($reason_keys_raw)) {
        $reason_keys = $reason_keys_raw;
      }

      $reason_map = kkchat_report_reason_map();
      $valid_keys = [];
      $labels = [];
      foreach ($reason_keys as $key_raw) {
        $key = sanitize_title((string) $key_raw);
        if ($key && isset($reason_map[$key])) {
          $valid_keys[] = $key;
          $labels[] = (string) ($reason_map[$key]['label'] ?? $key);
        }
      }
      $reason_other = trim($reason_other);
      if ($reason_other !== '') {
        $labels[] = 'Annat: ' . $reason_other;
      }

      if (!$labels) kkchat_json(['ok'=>false,'err'=>'bad_reason'], 400);

      $reason_summary = trim(implode(', ', $labels));
      if ($reason_summary === '' || mb_strlen($reason_summary) > 1000) kkchat_json(['ok'=>false,'err'=>'bad_reason'], 400);

      $reason_key_store = $valid_keys ? '|' . implode('|', $valid_keys) . '|' : null;

      $u = $wpdb->get_row($wpdb->prepare("SELECT id,name,ip,wp_username FROM {$t['users']} WHERE id=%d", $reported_id), ARRAY_A);
      if (!$u) kkchat_json(['ok'=>false,'err'=>'user_gone'], 400);

      $source_type = strtolower(trim((string) $req->get_param('source_type')));
      if (!in_array($source_type, ['lobby', 'dm'], true)) {
        $source_type = 'lobby';
      }
      $source_room = null;
      if ($source_type === 'lobby') {
        $source_room = kkchat_sanitize_room_slug((string) $req->get_param('source_room'));
        if ($source_room === '') {
          $source_room = 'lobby';
        }
      }
      $source_dm_id = $source_type === 'dm' ? max(0, (int) $req->get_param('source_dm_id')) : 0;
      if ($source_type === 'dm' && $source_dm_id <= 0) {
        $source_dm_id = $reported_id;
      }
      $message_id = max(0, (int) $req->get_param('message_id'));
      $message_excerpt = trim((string) $req->get_param('message_excerpt'));
      if ($message_excerpt !== '') {
        $message_excerpt = mb_substr($message_excerpt, 0, 2000);
      } else {
        $message_excerpt = null;
      }

      $now = time();
      $reporter_ip_raw = kkchat_client_ip();
      $reporter_ip_key = kkchat_ip_ban_key($reporter_ip_raw);
      $reported_ip_raw = (string)($u['ip'] ?? '');
      $reported_ip_key = kkchat_ip_ban_key($reported_ip_raw);
      $wpdb->insert($t['reports'], [
        'created_at'    => $now,
        'reporter_id'   => $me_id,
        'reporter_name' => $me_nm,
        'reporter_ip'   => $reporter_ip_raw ?: null,
        'reporter_ip_key' => $reporter_ip_key ?: null,
        'reported_id'   => (int)$u['id'],
        'reported_name' => (string)$u['name'],
        'reported_ip'   => $reported_ip_raw,
        'reported_ip_key' => $reported_ip_key ?: null,
        'reason_key'    => $reason_key_store,
        'reason'        => $reason_summary,
        'reason_detail' => $reason_other !== '' ? $reason_other : null,
        'source_type'   => $source_type,
        'source_room'   => $source_room,
        'source_dm_id'  => $source_dm_id > 0 ? $source_dm_id : null,
        'source_message_id' => $message_id > 0 ? $message_id : null,
        'source_message_excerpt' => $message_excerpt,
        'status'        => 'open',
      ], ['%d','%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s']);

      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);
      $threshold    = kkchat_report_autoban_threshold();
      $window_days  = kkchat_report_autoban_window_days();
      $window_secs  = ($window_days > 0) ? (int) ($window_days * (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400)) : 0;

      $ban_trigger = null;
      if ($reported_ip_key && $window_secs > 0) {
        $since = max(0, $now - $window_secs);
        $total_threshold = 0;
        foreach ($valid_keys as $key) {
          $candidate = (int) ($reason_map[$key]['total_threshold'] ?? 0);
          if ($candidate > 0 && ($total_threshold === 0 || $candidate < $total_threshold)) {
            $total_threshold = $candidate;
          }
        }
        if ($total_threshold === 0) {
          $total_threshold = $threshold;
        }

        if ($total_threshold > 0) {
          $distinct_reporters = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT reporter_ip_key) FROM {$t['reports']} WHERE reported_ip_key = %s AND reporter_ip_key IS NOT NULL AND created_at >= %d",
            $reported_ip_key,
            $since
          ));
          if ($distinct_reporters >= $total_threshold) {
            $ban_trigger = [
              'type' => 'total',
              'count' => $distinct_reporters,
              'threshold' => $total_threshold,
              'label' => null,
            ];
          }
        }

        if (!$ban_trigger && $valid_keys) {
          foreach ($valid_keys as $key) {
            $repeat_threshold = (int) ($reason_map[$key]['repeat_threshold'] ?? 0);
            if ($repeat_threshold <= 0) {
              continue;
            }
            $like = '%' . $wpdb->esc_like('|' . $key . '|') . '%';
            $reason_reporters = (int) $wpdb->get_var($wpdb->prepare(
              "SELECT COUNT(DISTINCT reporter_ip_key)
                 FROM {$t['reports']}
                WHERE reported_ip_key = %s
                  AND reporter_ip_key IS NOT NULL
                  AND created_at >= %d
                  AND reason_key LIKE %s",
              $reported_ip_key,
              $since,
              $like
            ));
            if ($reason_reporters >= $repeat_threshold) {
              $ban_trigger = [
                'type' => 'repeat',
                'count' => $reason_reporters,
                'threshold' => $repeat_threshold,
                'label' => (string) ($reason_map[$key]['label'] ?? $key),
              ];
              break;
            }
          }
        }

        if ($ban_trigger) {
          $existing_ban = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$t['blocks']} WHERE type='ipban' AND active=1 AND target_ip=%s LIMIT 1",
            $reported_ip_key
          ));

          if (!$existing_ban) {
            if ($ban_trigger['type'] === 'repeat') {
              $cause = sprintf(
                'Auto-ban via rapporter: %d unika IP-rapporter för "%s" inom %d dagar (gräns %d).',
                $ban_trigger['count'],
                $ban_trigger['label'],
                $window_days,
                $ban_trigger['threshold']
              );
            } else {
              $cause = sprintf(
                'Auto-ban via rapporter: %d unika IP-rapporter inom %d dagar (gräns %d).',
                $ban_trigger['count'],
                $window_days,
                $ban_trigger['threshold']
              );
            }

            $wpdb->insert($t['blocks'], [
              'type'               => 'ipban',
              'target_user_id'     => (int) $u['id'],
              'target_name'        => $u['name'] ?? null,
              'target_wp_username' => $u['wp_username'] ?? null,
              'target_ip'          => $reported_ip_key,
              'cause'              => $cause,
              'created_by'         => 'auto:reports',
              'created_at'         => $now,
              'expires_at'         => null,
              'active'             => 1,
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

            if (!$wpdb->last_error) {
              $ban_id = (int) $wpdb->insert_id;
              if ($ban_id > 0) {
                $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at = NULL WHERE id=%d", $ban_id));
              }

              $wpdb->delete($t['users'], ['id' => (int) $u['id']], ['%d']);

              $wpdb->query($wpdb->prepare(
                "UPDATE {$t['reports']} SET status='resolved', resolved_at=%d, resolved_by=%s WHERE status='open' AND reported_ip_key = %s",
                $now,
                'auto:reports',
                $reported_ip_key
              ));
            }
          }
        }
      }
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
