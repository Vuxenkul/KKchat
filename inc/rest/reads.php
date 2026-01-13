<?php
if (!defined('ABSPATH')) exit;

register_rest_route($ns, '/reads/mark', [
  'methods'  => 'POST',
  'callback' => function (WP_REST_Request $req) {
    kkchat_require_login();
    kkchat_assert_not_blocked_or_fail();
    kkchat_check_csrf_or_fail($req);   // ✅ require CSRF
    nocache_headers();

    // keep presence warm — but DON'T close the session yet (we'll write to $_SESSION below)
    kkchat_touch_active_user();

    global $wpdb;
    $t  = kkchat_tables();
    $me = (int) kkchat_current_user_id();
    if ($me <= 0) {
      return new WP_REST_Response(['ok' => false, 'error' => 'not_logged_in'], 401);
    }

    // Payload
    $dms           = $req->get_param('dms');                 // array of DM message IDs (legacy)
    $public_since  = (int) ($req->get_param('public_since') ?? 0); // server "now" watermark
    $dm_peer       = (int) ($req->get_param('dm_peer') ?? 0);
    $dm_last_id    = (int) ($req->get_param('dm_last_id') ?? 0);
    $room_slug_raw = (string) ($req->get_param('room_slug') ?? '');
    $room_slug     = kkchat_sanitize_room_slug($room_slug_raw);
    $room_last_id  = (int) ($req->get_param('room_last_id') ?? 0);

    $now         = time();
    $dmUpdates   = [];
    $roomUpdates = [];
    $roomSeenAt  = [];

    if ($dm_peer > 0) {
      $dmTargetId = $dm_last_id;
      if ($dmTargetId <= 0) {
        $dmTargetId = (int) $wpdb->get_var(
          $wpdb->prepare(
            "SELECT MAX(id) FROM {$t['messages']}
             WHERE hidden_at IS NULL
               AND ((sender_id = %d AND recipient_id = %d) OR
                    (sender_id = %d AND recipient_id = %d))",
            $me,
            $dm_peer,
            $dm_peer,
            $me
          )
        );
      }

      if ($dmTargetId > 0) {
        $row = $wpdb->get_row(
          $wpdb->prepare(
            "SELECT sender_id, recipient_id FROM {$t['messages']} WHERE id = %d LIMIT 1",
            $dmTargetId
          ),
          ARRAY_A
        );

        if ($row) {
          $sender    = (int) ($row['sender_id'] ?? 0);
          $recipient = (int) ($row['recipient_id'] ?? 0);
          $isPeer    = ($sender === $me && $recipient === $dm_peer) || ($sender === $dm_peer && $recipient === $me);
          if ($isPeer) {
            $dmUpdates[$dm_peer] = max($dmUpdates[$dm_peer] ?? 0, $dmTargetId);
          }
        }
      }
    }

    if (is_array($dms) && !empty($dms)) {
      $ids = array_values(
        array_unique(
          array_filter(array_map('intval', $dms), fn($x) => $x > 0)
        )
      );

      if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT id, sender_id, recipient_id FROM {$t['messages']} WHERE id IN ($placeholders)",
            ...$ids
          ),
          ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
          $mid       = (int) ($row['id'] ?? 0);
          $sender    = (int) ($row['sender_id'] ?? 0);
          $recipient = (int) ($row['recipient_id'] ?? 0);
          if ($mid <= 0 || $recipient === 0 || $recipient === null) { continue; }

          if ($sender === $me && $recipient > 0) {
            $dmUpdates[$recipient] = max($dmUpdates[$recipient] ?? 0, $mid);
          } elseif ($recipient === $me && $sender > 0) {
            $dmUpdates[$sender] = max($dmUpdates[$sender] ?? 0, $mid);
          }
        }
      }
    }

    if (!empty($dmUpdates) && !empty($t['last_dm_reads'])) {
      $existing = [];
      $peerIds  = array_keys($dmUpdates);
      $peerIds  = array_values(array_filter(array_map('intval', $peerIds), fn($id) => $id > 0));

      if (!empty($peerIds)) {
        $placeholders = implode(',', array_fill(0, count($peerIds), '%d'));
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT peer_id, last_msg_id FROM {$t['last_dm_reads']} WHERE user_id = %d AND peer_id IN ($placeholders)",
            $me,
            ...$peerIds
          ),
          ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
          $existing[(int) ($row['peer_id'] ?? 0)] = (int) ($row['last_msg_id'] ?? 0);
        }
      }

      foreach ($dmUpdates as $peer => $mid) {
        $peer = (int) $peer;
        $mid  = (int) $mid;
        if ($peer <= 0 || $mid <= 0) { continue; }
        if ($mid <= ($existing[$peer] ?? 0)) { continue; }

        $wpdb->query(
          $wpdb->prepare(
            "INSERT INTO {$t['last_dm_reads']} (user_id, peer_id, last_msg_id, updated_at)
             VALUES (%d,%d,%d,%d)
             ON DUPLICATE KEY UPDATE last_msg_id = GREATEST(last_msg_id, VALUES(last_msg_id)),
                                     updated_at = VALUES(updated_at)",
            $me,
            $peer,
            $mid,
            $now
          )
        );

        $existing[$peer] = $mid;
      }
    }

    if ($room_slug !== '' && $room_last_id > 0) {
      $row = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT room, created_at FROM {$t['messages']} WHERE id = %d LIMIT 1",
          $room_last_id
        ),
        ARRAY_A
      );

      if ($row) {
        $room = (string) ($row['room'] ?? '');
        if ($room === $room_slug) {
          $roomUpdates[$room_slug] = max($roomUpdates[$room_slug] ?? 0, $room_last_id);
          $roomSeenAt[$room_slug]  = max($roomSeenAt[$room_slug] ?? 0, (int) ($row['created_at'] ?? 0));
        }
      }
    }

    if (!empty($roomUpdates) && !empty($t['last_reads'])) {
      $normalizedUpdates = [];
      $normalizedSeenAt  = [];

      foreach ($roomUpdates as $slug => $mid) {
        $slug = kkchat_sanitize_room_slug((string) $slug);
        $mid  = (int) $mid;
        if ($slug === '' || $mid <= 0) { continue; }

        $normalizedUpdates[$slug] = max($normalizedUpdates[$slug] ?? 0, $mid);
        if (isset($roomSeenAt[$slug])) {
          $normalizedSeenAt[$slug] = max($normalizedSeenAt[$slug] ?? 0, (int) $roomSeenAt[$slug]);
        }
      }

      if (!empty($normalizedUpdates)) {
        $existing = [];
        $validSlugs = array_keys($normalizedUpdates);
        $validSlugs = array_values(array_filter(array_map('strval', $validSlugs), fn($slug) => $slug !== ''));

        if (!empty($validSlugs)) {
          $placeholders = implode(',', array_fill(0, count($validSlugs), '%s'));
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT room_slug, last_msg_id FROM {$t['last_reads']} WHERE user_id = %d AND room_slug IN ($placeholders)",
              $me,
              ...$validSlugs
            ),
            ARRAY_A
          ) ?: [];

          foreach ($rows as $row) {
            $slug = (string) ($row['room_slug'] ?? '');
            if ($slug === '') { continue; }
            $existing[$slug] = (int) ($row['last_msg_id'] ?? 0);
          }
        }

        foreach ($normalizedUpdates as $slug => $mid) {
          if ($mid <= ($existing[$slug] ?? 0)) { continue; }

          $wpdb->query(
            $wpdb->prepare(
              "INSERT INTO {$t['last_reads']} (user_id, room_slug, last_msg_id, updated_at)
               VALUES (%d,%s,%d,%d)
               ON DUPLICATE KEY UPDATE last_msg_id = GREATEST(last_msg_id, VALUES(last_msg_id)),
                                       updated_at = VALUES(updated_at)",
              $me,
              $slug,
              $mid,
              $now
            )
          );

          $existing[$slug] = $mid;

          $seenAt = (int) ($normalizedSeenAt[$slug] ?? 0);
          if ($seenAt > 0) {
            $prev = (int) ($_SESSION['kkchat_seen_at_public'] ?? 0);
            if ($seenAt > $prev) {
              $_SESSION['kkchat_seen_at_public'] = $seenAt;
            }
          }
        }
      }
    }

    if ($public_since > 0) {
      $prev = (int) ($_SESSION['kkchat_seen_at_public'] ?? 0);
      if ($public_since > $prev) {
        $_SESSION['kkchat_seen_at_public'] = $public_since;
      }
    }

    // ✅ Now it's safe to release the session lock
    kkchat_close_session_if_open();

    return kkchat_json(['ok' => true]);
  },
  'permission_callback' => '__return_true',
]);

