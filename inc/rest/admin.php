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
                   content, is_explicit,
                   reply_to_id, reply_to_sender_id, reply_to_sender_name, reply_to_excerpt
              FROM {$t['messages']}
              $whereSql
          ORDER BY id DESC
             LIMIT %d";

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$limit])), ARRAY_A) ?: [];

    $watch_rules = array_values(array_filter(kkchat_rules_active(), function ($rule) {
      return isset($rule['kind']) && $rule['kind'] === 'watch';
    }));

    $reporter_ids = [];
    $message_sources = [];
    if ($uid > 0) {
      $report_rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT reporter_id, reporter_name, context_label, message_id
             FROM {$t['reports']}
            WHERE reported_id = %d",
          $uid
        ),
        ARRAY_A
      ) ?: [];

      foreach ($report_rows as $report) {
        $reporter_id = (int) ($report['reporter_id'] ?? 0);
        if ($reporter_id > 0) {
          $reporter_ids[$reporter_id] = (string) ($report['reporter_name'] ?? '');
        }

        $message_id = (int) ($report['message_id'] ?? 0);
        $context_label = trim((string) ($report['context_label'] ?? ''));
        if ($message_id > 0 && $context_label !== '') {
          if (!isset($message_sources[$message_id])) {
            $message_sources[$message_id] = [];
          }
          $message_sources[$message_id][] = $context_label;
        }
      }

      foreach ($message_sources as $message_id => $labels) {
        $unique_labels = array_unique(array_filter($labels));
        $message_sources[$message_id] = $unique_labels ? implode(', ', $unique_labels) : '';
      }
    }

    $out = array_map(function($m) use ($watch_rules, $uid, $reporter_ids, $message_sources){
      $watch_words = [];
      $kind = $m['kind'] ?: 'chat';
      if ($watch_rules && $kind === 'chat') {
        $text = (string) ($m['content'] ?? '');
        if ($text !== '') {
          foreach ($watch_rules as $rule) {
            if (!kkchat_rule_matches($rule, $text)) {
              continue;
            }
            $word = trim((string) ($rule['word'] ?? ''));
            if ($word === '') {
              continue;
            }
            $watch_words[] = $word;
            if (count($watch_words) >= 3) {
              break;
            }
          }
        }
      }

      $report_source = '';
      $reported_by_peer = false;
      $message_id = (int) ($m['id'] ?? 0);
      if ($message_id > 0 && isset($message_sources[$message_id])) {
        $report_source = (string) $message_sources[$message_id];
      }

      if ($uid > 0 && !empty($m['recipient_id'])) {
        $sender_id = (int) ($m['sender_id'] ?? 0);
        $recipient_id = (int) ($m['recipient_id'] ?? 0);
        $other_id = ($sender_id === $uid) ? $recipient_id : $sender_id;
        if ($other_id > 0 && isset($reporter_ids[$other_id])) {
          $reported_by_peer = true;
        }
      }

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
        'is_explicit'    => !empty($m['is_explicit']),
        'reply_to_id'          => isset($m['reply_to_id']) ? (int)$m['reply_to_id'] : null,
        'reply_to_sender_id'   => isset($m['reply_to_sender_id']) ? (int)$m['reply_to_sender_id'] : null,
        'reply_to_sender_name' => $m['reply_to_sender_name'] ?: null,
        'reply_to_excerpt'     => $m['reply_to_excerpt'] ?: null,
        'watch_hit'      => !empty($watch_words),
        'watch_words'    => $watch_words,
        'report_source'  => $report_source,
        'reported_by_peer' => $reported_by_peer,
      ];
    }, $rows);

    $next_before = (count($rows) === $limit) ? (int)end($rows)['id'] : null;

    kkchat_json(['ok'=>true,'rows'=>$out,'next_before'=>$next_before]);
  },
  'permission_callback' => '__return_true',
]);

// Admin: fetch a full DM thread between two users
register_rest_route($ns, '/admin/dm-thread', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) use ($require_admin) {
    $require_admin();

    nocache_headers();
    kkchat_close_session_if_open();

    global $wpdb; $t = kkchat_tables();

    $user_id = max(0, (int)$req->get_param('user_id'));
    $peer_id = max(0, (int)$req->get_param('peer_id'));
    $limit   = max(20, min(500, (int)($req->get_param('limit') ?: 200)));

    if ($user_id <= 0 || $peer_id <= 0) kkchat_json(['ok'=>false,'err'=>'need_users'], 400);

    $sql = "SELECT id,created_at,kind,room,
                   sender_id,sender_name,
                   recipient_id,recipient_name,
                   content, is_explicit,
                   reply_to_id, reply_to_sender_id, reply_to_sender_name, reply_to_excerpt
              FROM {$t['messages']}
             WHERE ((sender_id = %d AND recipient_id = %d) OR
                    (sender_id = %d AND recipient_id = %d))
          ORDER BY id DESC
             LIMIT %d";

    $rows = $wpdb->get_results(
      $wpdb->prepare($sql, $user_id, $peer_id, $peer_id, $user_id, $limit),
      ARRAY_A
    ) ?: [];

    $rows = array_reverse($rows);

    $out = array_map(function($m){
      return [
        'id'             => (int)$m['id'],
        'time'           => (int)$m['created_at'],
        'kind'           => $m['kind'] ?: 'chat',
        'room'           => $m['room'] ?: null,
        'sender_id'      => (int)$m['sender_id'],
        'sender_name'    => (string)$m['sender_name'],
        'recipient_id'   => isset($m['recipient_id']) ? (int)$m['recipient_id'] : null,
        'recipient_name' => $m['recipient_name'] ?: null,
        'content'        => $m['content'],
        'is_explicit'    => !empty($m['is_explicit']),
        'reply_to_id'          => isset($m['reply_to_id']) ? (int)$m['reply_to_id'] : null,
        'reply_to_sender_id'   => isset($m['reply_to_sender_id']) ? (int)$m['reply_to_sender_id'] : null,
        'reply_to_sender_name' => $m['reply_to_sender_name'] ?: null,
        'reply_to_excerpt'     => $m['reply_to_excerpt'] ?: null,
      ];
    }, $rows);

    kkchat_json(['ok'=>true,'rows'=>$out]);
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
