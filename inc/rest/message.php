<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('kkchat_reply_excerpt_from_message')) {
  function kkchat_reply_excerpt_from_message(string $content, string $kind): string {
    $kind = strtolower(trim($kind));
    if ($kind === 'image') {
      return '[Bild]';
    }

    $text = wp_strip_all_tags($content);
    $text = preg_replace('/\s+/u', ' ', (string) $text);
    $text = trim((string) $text);
    if ($text === '') {
      return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($text) > 160) {
        $text = rtrim(mb_substr($text, 0, 160)) . '…';
      }
    } else {
      if (strlen($text) > 160) {
        $text = rtrim(substr($text, 0, 160)) . '…';
      }
    }

    return $text;
  }
}

  register_rest_route($ns, '/message', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_assert_not_blocked_or_fail(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();
      kkchat_wpdb_reconnect_if_needed();
      $me_id = kkchat_current_user_id();
      $me_nm = kkchat_current_user_name();

      // Kind: 'chat' (default) or 'image'
      $kind = (string)$req->get_param('kind');
      $kind = ($kind === 'image') ? 'image' : 'chat';

      $txt = '';
      $image_url = '';
      if ($kind === 'image') {
        $image_url = esc_url_raw((string)$req->get_param('image_url'));
        if ($image_url === '') kkchat_json(['ok'=>false,'err'=>'bad_image'], 400);

        // Validate URL is within WP uploads AND under /kkchat/ subdir
        $up = wp_upload_dir();
        $baseurl = rtrim((string)$up['baseurl'], '/');
        $basedir = rtrim((string)$up['basedir'], DIRECTORY_SEPARATOR);

        $subroot = (string)apply_filters('kkchat_upload_subdir', '/kkchat');
        $must_prefix = $baseurl . rtrim($subroot, '/') . '/';
        if (strpos($image_url, $must_prefix) !== 0) {
          kkchat_json(['ok'=>false,'err'=>'bad_image_scope'], 400);
        }
        if (strpos($image_url, $baseurl . '/') !== 0) {
          kkchat_json(['ok'=>false,'err'=>'bad_image_scope'], 400);
        }

        // Harden path resolution (guard against symlinks/traversal)
        $rel   = ltrim(substr($image_url, strlen($baseurl)), '/');
        $fpath = $basedir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $real_base = wp_normalize_path((string)realpath($basedir));
        $real_path = wp_normalize_path((string)realpath($fpath));
        if (!$real_path || !$real_base || strpos($real_path, trailingslashit($real_base)) !== 0) {
          kkchat_json(['ok'=>false,'err'=>'image_missing'], 400);
        }
        if (!file_exists($fpath)) kkchat_json(['ok'=>false,'err'=>'image_missing'], 400);

      } else {
        $txt = trim((string)$req->get_param('content'));
        if ($txt==='' || mb_strlen($txt) > 2000) kkchat_json(['ok'=>false], 400);
      }

      // === Auto moderation by Word Rules (non-admin only) ===
      if ($kind === 'chat' && !kkchat_is_admin()) {
        $rules = kkchat_rules_active();
        $hit_forbid = null; $hit_watch = null;
        foreach ($rules as $r){
          if (!kkchat_rule_matches($r, $txt)) continue;
          if ($r['kind']==='watch' && !$hit_watch) $hit_watch = $r;
          if ($r['kind']==='forbid' && !$hit_forbid) $hit_forbid = $r;
        }
        if ($hit_watch){
          $affected = $wpdb->update($t['users'], ['watch_flag'=>1,'watch_flag_at'=>time()], ['id'=>$me_id], ['%d','%d'], ['%d']);
          if ((int) $affected > 0) {
            kkchat_admin_presence_cache_flush();
          }
        }
        if ($hit_forbid){
          $now = time();
          $admin = (string)($_SESSION['kkchat_wp_username'] ?? '');
          $cause = 'Forbidden word: "'.$hit_forbid['word'].'"';
          $dur   = $hit_forbid['duration_sec']; // NULL => infinite
          $ip    = kkchat_client_ip();

          if ($hit_forbid['action'] === 'kick'){
            $exp = isset($dur) ? ($now + max(60,(int)$dur)) : null;
            $wpdb->insert($t['blocks'], [
              'type'=>'kick',
              'target_user_id'=>$me_id,
              'target_name'=>$me_nm,
              'target_wp_username'=>$_SESSION['kkchat_wp_username'] ?? null,
              'target_ip'=>null,
              'cause'=>$cause,
              'created_by'=>$admin ?: null,
              'created_at'=>$now,
              'expires_at'=>$exp,
              'active'=>1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);
            if ($exp === null){ $id=(int)$wpdb->insert_id; $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at=NULL WHERE id=%d",$id)); }
          } elseif ($hit_forbid['action'] === 'ipban'){
            $exp = isset($dur) ? ($now + max(60,(int)$dur)) : null;
            $wpdb->insert($t['blocks'], [
              'type'=>'ipban',
              'target_user_id'=>$me_id,
              'target_name'=>$me_nm,
              'target_wp_username'=>$_SESSION['kkchat_wp_username'] ?? null,
              'target_ip'=>kkchat_ip_ban_key($ip),
              'cause'=>$cause,
              'created_by'=>$admin ?: null,
              'created_at'=>$now,
              'expires_at'=>$exp,
              'active'=>1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);
            if ($exp === null){ $id=(int)$wpdb->insert_id; $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at=NULL WHERE id=%d",$id)); }
          }

          // Remove presence and block the send
          $wpdb->delete($t['users'], ['id'=>$me_id], ['%d']);
                kkchat_json(['ok'=>false,'err'=>'auto_moderated'], 403);
        }
      }
      // === end automod ===

      // Minimal anti-flood: min interval between messages
      $minGap = max(0, kkchat_min_interval_seconds());
      if ($minGap > 0) {
        $now = time();
        $recent = (int)$wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$t['messages']} WHERE sender_id = %d AND created_at > %d",
          $me_id, $now - $minGap
        ));
        if ($recent > 0) {
          kkchat_json(
            ['ok' => false, 'err' => 'too_fast', 'cause' => 'Du skriver för snabbt. Försök igen strax.'],
            429
          );
        }
      }

      // DM or room
      $recipient = $req->get_param('recipient_id');
      $recipient = ($recipient !== null && $recipient!=='') ? (int)$recipient : null;
      if ($recipient === $me_id) $recipient = null;

      $room = null; $recipient_name = null; $recipient_ip = null;

      if ($recipient !== null) {
        // Respect my blocklist for DMs
        $mineBlocked = kkchat_blocked_ids($me_id);
        if (in_array($recipient, $mineBlocked, true)) {
          kkchat_json(['ok'=>false,'err'=>'blocked_peer'], 403);
        }

        $urow = $wpdb->get_row($wpdb->prepare("SELECT name, ip FROM {$t['users']} WHERE id=%d", $recipient), ARRAY_A);
        if (!$urow) kkchat_json(['ok'=>false], 400);
        $recipient_name = $urow['name'] ?? null;
        $recipient_ip   = $urow['ip']   ?? null;
      } else {
        $room = kkchat_sanitize_room_slug((string)$req->get_param('room'));
        if ($room === '') $room = 'general';
        if (!kkchat_can_access_room($room)) kkchat_json(['ok'=>false,'err'=>'no_room_access'], 403);
      }

      $now = time();
      $sender_ip = kkchat_client_ip();

      $reply_to_id          = null;
      $reply_to_sender_id   = null;
      $reply_to_sender_name = null;
      $reply_to_excerpt     = '';

      $replyRaw = $req->get_param('reply_to_id');
      if ($replyRaw !== null && $replyRaw !== '') {
        $candidate = (int) $replyRaw;
        if ($candidate > 0) {
          $parent = $wpdb->get_row(
            $wpdb->prepare(
              "SELECT id, sender_id, sender_name, recipient_id, room, kind, content, hidden_at"
              . " FROM {$t['messages']} WHERE id = %d",
              $candidate
            ),
            ARRAY_A
          );

          if (!$parent || !empty($parent['hidden_at'])) {
            kkchat_json(['ok' => false, 'err' => 'bad_reply_target'], 400);
          }

          $parentRoom     = isset($parent['room']) ? kkchat_sanitize_room_slug((string) $parent['room']) : '';
          $parentSenderId = isset($parent['sender_id']) ? (int) $parent['sender_id'] : 0;
          $parentRecipId  = isset($parent['recipient_id']) ? (int) $parent['recipient_id'] : null;

          if ($recipient !== null) {
            // DM reply: ensure same peer relationship
            $valid = (
              ($parentSenderId === $me_id && $parentRecipId === $recipient) ||
              ($parentSenderId === $recipient && $parentRecipId === $me_id)
            );

            if (!$valid) {
              kkchat_json(['ok' => false, 'err' => 'bad_reply_target'], 400);
            }
          } else {
            // Room reply: ensure message lives in same room and isn't a DM
            if ($parentRecipId !== null) {
              kkchat_json(['ok' => false, 'err' => 'bad_reply_target'], 400);
            }
            if ($parentRoom === '') {
              $parentRoom = 'general';
            }
            if ($parentRoom !== $room) {
              kkchat_json(['ok' => false, 'err' => 'bad_reply_target'], 400);
            }
          }

          $reply_to_id          = $candidate;
          $reply_to_sender_id   = $parentSenderId > 0 ? $parentSenderId : null;
          $reply_to_sender_name = trim((string) ($parent['sender_name'] ?? '')) ?: null;
          if ($reply_to_sender_name !== null) {
            $reply_to_sender_name = sanitize_text_field($reply_to_sender_name);
            $reply_to_sender_name = trim($reply_to_sender_name);
            if ($reply_to_sender_name === '') {
              $reply_to_sender_name = null;
            }
          }
          if ($reply_to_sender_name !== null) {
            if (function_exists('mb_substr')) {
              $reply_to_sender_name = mb_substr($reply_to_sender_name, 0, 64);
            } else {
              $reply_to_sender_name = substr($reply_to_sender_name, 0, 64);
            }
          }
          $reply_to_excerpt     = kkchat_reply_excerpt_from_message((string) ($parent['content'] ?? ''), (string) ($parent['kind'] ?? 'chat'));
          if ($reply_to_excerpt !== '') {
            if (function_exists('mb_substr')) {
              $reply_to_excerpt = mb_substr($reply_to_excerpt, 0, 255);
            } else {
              $reply_to_excerpt = substr($reply_to_excerpt, 0, 255);
            }
          }
        }
      }

      // Prepare content + dedupe basis
      if ($kind === 'image') {
        $content = esc_url_raw($image_url);
        $raw     = 'image:' . $content;
      } else {
        $content = $txt;
        $raw     = trim($txt);
      }

      // Strict, small dedupe window on exact content per-context
      $ctx = ($recipient !== null) ? ('dm:' . $recipient) : ('room:' . $room);
      $content_hash = sha1($ctx . '|' . $raw);
      $window = max(1, kkchat_dedupe_window());
      $dupe_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t['messages']}
         WHERE sender_id=%d AND content_hash=%s AND created_at > %d
         ORDER BY id DESC LIMIT 1",
        $me_id, $content_hash, $now - max(1,$window)
      ));
      if ($dupe_id > 0) {
        // Auto-kick repeated duplicate attempts (non-admins)
        if (!kkchat_is_admin()) {
          if (!isset($_SESSION['kk_dupe'])) $_SESSION['kk_dupe'] = [];
          $key = $content_hash;

          $win   = kkchat_dupe_window_seconds();
          $fast  = kkchat_dupe_fast_seconds();
          $max   = kkchat_dupe_max_repeats();
          $mins  = kkchat_dupe_autokick_minutes();

          $rec = $_SESSION['kk_dupe'][$key] ?? ['n'=>0, 'first'=>$now];
          if ($now - $rec['first'] > $win) $rec = ['n'=>0, 'first'=>$now];
          $rec['n']++;
          $_SESSION['kk_dupe'][$key] = $rec;

          if ($mins > 0 && $rec['n'] >= $max && ($now - $rec['first']) <= $fast) {
            $exp = $now + max(60, $mins * 60);
            $admin = (string)($_SESSION['kkchat_wp_username'] ?? '');
            $cause = 'Repeat spam (auto)';

            $wpdb->insert($t['blocks'], [
              'type' => 'kick',
              'target_user_id' => $me_id,
              'target_name' => $me_nm,
              'target_wp_username' => $_SESSION['kkchat_wp_username'] ?? null,
              'target_ip' => null,
              'cause' => $cause,
              'created_by' => $admin ?: null,
              'created_at' => $now,
              'expires_at' => $exp,
              'active' => 1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

            unset($_SESSION['kk_dupe'][$key]);
            $wpdb->delete($t['users'], ['id'=>$me_id], ['%d']);
            kkchat_json(['ok'=>false,'err'=>'auto_moderated','cause'=>$cause], 403);
          }
        }

        kkchat_json(['ok'=>true,'id'=>$dupe_id,'deduped'=>1]);
      }

      $data = [
        'created_at'   => $now,
        'sender_id'    => $me_id,
        'sender_name'  => $me_nm,
        'sender_ip'    => $sender_ip,
        'content_hash' => $content_hash,
        'content'      => $content,
        'kind'         => $kind
      ];
      $format = ['%d','%d','%s','%s','%s','%s','%s'];

      if ($reply_to_id !== null) {
        $data['reply_to_id'] = $reply_to_id;
        $format[] = '%d';

        if ($reply_to_sender_id !== null) {
          $data['reply_to_sender_id'] = $reply_to_sender_id;
          $format[] = '%d';
        }
        if ($reply_to_sender_name !== null) {
          $data['reply_to_sender_name'] = $reply_to_sender_name;
          $format[] = '%s';
        }
        if ($reply_to_excerpt !== '') {
          $data['reply_to_excerpt'] = $reply_to_excerpt;
          $format[] = '%s';
        }
      }

      if ($recipient !== null) {
        $data['recipient_id']   = $recipient;
        $data['recipient_name'] = $recipient_name;
        $data['recipient_ip']   = $recipient_ip;
        $format[] = '%d'; $format[] = '%s'; $format[] = '%s';
      } else {
        $data['room'] = $room;
        $format[] = '%s';
      }

      $ok = $wpdb->insert($t['messages'], $data, $format);
      if ($ok === false) {
        kkchat_wpdb_reconnect_if_needed();
        $ok = $wpdb->insert($t['messages'], $data, $format);
      }
      if ($ok === false) kkchat_json(['ok'=>false,'err'=>'db_insert_failed'], 500);

      $mid = (int)$wpdb->insert_id;

      kkchat_admin_presence_cache_flush();

      kkchat_json([
        'ok'                   => true,
        'id'                   => $mid,
        'time'                 => $now,
        'reply_to_id'          => $reply_to_id,
        'reply_to_sender_id'   => $reply_to_sender_id,
        'reply_to_sender_name' => $reply_to_sender_name,
        'reply_to_excerpt'     => $reply_to_excerpt !== '' ? $reply_to_excerpt : null,
      ]);

    },
    'permission_callback' => '__return_true',
  ]);

