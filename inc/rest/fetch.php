<?php
if (!defined('ABSPATH')) exit;

register_rest_route($ns, '/fetch', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) {
    kkchat_require_login(); kkchat_assert_not_blocked_or_fail();
    nocache_headers();

    // Keep presence warm on every fetch
    kkchat_touch_active_user();

    // Unlock session before heavy DB work
    kkchat_close_session_if_open();

    global $wpdb; $t = kkchat_tables();
    $me    = kkchat_current_user_id();
    $since = max(-1, (int)$req->get_param('since'));
    $onlyPublic = $req->get_param('public') !== null;
    $roomParam  = kkchat_sanitize_room_slug((string)$req->get_param('room'));
    if ($roomParam === '') $roomParam = 'general';
    $peer = $req->get_param('to') !== null ? (int)$req->get_param('to') : null;
    $allowLegacyDm = (string) $req->get_param('legacy_dm') === '1';

    // Soft cap to keep payloads small on first load
    $limit = (int)$req->get_param('limit');
    if ($limit <= 0) $limit = 200;                   // sane default
    $limit = max(1, min($limit, 200));               // 1..500

    $blocked = kkchat_blocked_ids($me);
    $msgColumns = 'id, room, sender_id, sender_name, recipient_id, recipient_name, content, is_explicit, created_at, kind, hidden_at, reply_to_id, reply_to_sender_id, reply_to_sender_name, reply_to_excerpt';

    if ($onlyPublic) {
      if ($since < 0) {
        // First load: last N messages in the room (ASC order for display)
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT $msgColumns FROM {$t['messages']}
             WHERE recipient_id IS NULL
               AND room = %s
               AND hidden_at IS NULL
             ORDER BY id DESC
             LIMIT %d",
            $roomParam, $limit
          ),
          ARRAY_A
        ) ?: [];
        $rows = array_reverse($rows);
      } else {
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT $msgColumns FROM {$t['messages']}
             WHERE id > %d
               AND recipient_id IS NULL
               AND room = %s
               AND hidden_at IS NULL
             ORDER BY id ASC
             LIMIT %d",
            $since, $roomParam, $limit
          ),
          ARRAY_A
        ) ?: [];
      }
      if ($blocked) {
        $rows = array_values(array_filter($rows, function($r) use ($blocked){
          $sid = (int)$r['sender_id'];
          return !in_array($sid, $blocked, true);
        }));
      }
    } else {
      // DMs
      if ($peer) {
        if ($since < 0) {
          // Last N in thread with specific peer
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE hidden_at IS NULL
                 AND ((sender_id = %d AND recipient_id = %d) OR
                      (sender_id = %d AND recipient_id = %d))
               ORDER BY id DESC
               LIMIT %d",
              $me, $peer, $peer, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
          $rows = array_reverse($rows);
        } else {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE id > %d
                 AND hidden_at IS NULL
                 AND ((sender_id = %d AND recipient_id = %d)
                   OR  (sender_id = %d AND recipient_id = %d))
               ORDER BY id ASC
               LIMIT %d",
              $since, $me, $peer, $peer, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
        }
      } else {
        if ($allowLegacyDm) {
          // Legacy: all DMs to/from me (opt-in only)
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE id > %d
                 AND (recipient_id = %d OR sender_id = %d)
                 AND hidden_at IS NULL
               ORDER BY id ASC
               LIMIT %d",
              $since, $me, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
        } else {
          $rows = [];
        }
      }
      if ($blocked) {
        $rows = array_values(array_filter($rows, function($r) use ($blocked, $me){
          $sid = (int)$r['sender_id'];
          if ($sid === $me) return true;        // always show own messages
          return !in_array($sid, $blocked, true);
        }));
      }
    }

    $out = [];
    if ($rows) {
      foreach ($rows as $r) {
        $mid = (int)$r['id'];
        $out[] = [
          'id'           => $mid,
          'time'         => (int)$r['created_at'],
          'kind'         => $r['kind'] ?: 'chat',
          'room'         => $r['room'] ?: null,
          'sender_id'    => (int)$r['sender_id'],
          'sender_name'  => $r['sender_name'],
          'recipient_id' => isset($r['recipient_id']) ? (int)$r['recipient_id'] : null,
          'recipient_name'=> $r['recipient_name'] ?: null,
          'content'      => $r['content'],
          'is_explicit'  => !empty($r['is_explicit']),
          'reply_to_id'  => isset($r['reply_to_id']) ? (int)$r['reply_to_id'] : null,
          'reply_to_sender_id'   => isset($r['reply_to_sender_id']) ? (int)$r['reply_to_sender_id'] : null,
          'reply_to_sender_name' => $r['reply_to_sender_name'] ?: null,
          'reply_to_excerpt'     => $r['reply_to_excerpt'] ?: null,
        ];
      }
    }
    kkchat_json($out);
  },
  'permission_callback' => '__return_true',
]);
