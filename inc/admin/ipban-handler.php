<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
 * Admin-post handler: IP-block direkt från Loggar (fixar blank sida)
 * – Formuläret postar till admin-post.php?action=kkchat_logs_ipban
 * ============================================================ */

add_action('admin_post_kkchat_logs_ipban', 'kkchat_handle_logs_ipban');
function kkchat_handle_logs_ipban() {
  if ( ! current_user_can('manage_options')) wp_die(__('Åtkomst nekad.', 'kkchat'));
  check_admin_referer('kkchat_logs_ipban');

  global $wpdb; $t = kkchat_tables();
  $ip      = trim((string)($_POST['ip'] ?? ''));
  $minutes = max(0, (int)($_POST['minutes'] ?? 0));
  $cause   = sanitize_text_field($_POST['cause'] ?? '');
  $back    = wp_get_referer() ?: admin_url('admin.php?page=kkchat_admin_logs');

  if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    wp_safe_redirect( add_query_arg('kkbanerr', rawurlencode('Ogiltig IP-adress.'), $back) ); exit;
  }

  // Normalisera till lagringsnyckel (IPv4 exakt / IPv6 -> /64)
  $ipKey = kkchat_ip_ban_key($ip);
  if (!$ipKey) {
    wp_safe_redirect( add_query_arg('kkbanerr', rawurlencode('Kunde inte normalisera IP-adressen.'), $back) ); exit;
  }

  // Finns redan ett aktivt block på nyckeln?
  $exists = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$t['blocks']} WHERE type='ipban' AND target_ip=%s AND active=1", $ipKey
  ));
  if ($exists > 0) {
    wp_safe_redirect( add_query_arg('kkbanok', rawurlencode("Det finns redan ett aktivt block för IP $ipKey."), $back) ); exit;
  }

  // -----------------------------
  // NYTT: Försök fylla "Mål" fälten
  // -----------------------------
  $target_user_id = null;
  $target_name = null;
  $target_wp_username = null;
  $posted_name = sanitize_text_field($_POST['user_name'] ?? '');
  $posted_wp_username = sanitize_text_field($_POST['user_wp_username'] ?? '');

  // 1) Om user_id skickas med (vi har det från loggraden) – använd det
  $uid = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
  if ($uid > 0) {
    $urow = $wpdb->get_row($wpdb->prepare("SELECT id,name,wp_username FROM {$t['users']} WHERE id=%d LIMIT 1", $uid), ARRAY_A);
    if ($urow) {
      $target_user_id     = (int)$urow['id'];
      $target_name        = (string)($urow['name'] ?? '');
      $target_wp_username = (string)($urow['wp_username'] ?? '');
    }
  }

  // 2) Annars, härleda från senaste loggrad på denna IP (avsändare/mottagare)
  if (($target_name === null || $target_name === '') && $posted_name !== '') {
    $target_name = $posted_name;
  }
  if (($target_wp_username === null || $target_wp_username === '') && $posted_wp_username !== '') {
    $target_wp_username = $posted_wp_username;
  }

  if ($target_user_id === null && $target_name === null && $target_wp_username === null) {
    $m = $wpdb->get_row($wpdb->prepare(
      "SELECT sender_id, sender_name
         FROM {$t['messages']}
        WHERE sender_ip=%s OR recipient_ip=%s
        ORDER BY id DESC LIMIT 1",
      $ip, $ip
    ), ARRAY_A);
    if ($m) {
      $sid = (int)($m['sender_id'] ?? 0);
      if ($sid > 0) {
        $target_user_id = $sid;
        $target_name    = (string)($m['sender_name'] ?? '');
        // Hämta WP-användarnamn om vi har en user-rad
        $urow = $wpdb->get_row($wpdb->prepare("SELECT wp_username,name FROM {$t['users']} WHERE id=%d LIMIT 1", $sid), ARRAY_A);
        if ($urow) {
          $target_wp_username = (string)($urow['wp_username'] ?? '');
          // Om namnet saknas i loggen, fyll från users-tabellen
          if (!$target_name && !empty($urow['name'])) $target_name = (string)$urow['name'];
        }
      } else {
        // Gäst: vi kan åtminstone spara visningsnamnet från loggen
        $target_name = (string)($m['sender_name'] ?? '');
      }
    }
  }

  $now = time();
  $exp = $minutes > 0 ? $now + $minutes * 60 : null;
  $admin = wp_get_current_user()->user_login ?? '';

  $ok = $wpdb->insert($t['blocks'], [
    'type'               => 'ipban',
    'target_user_id'     => $target_user_id,            // ← nu ifyllt när möjligt
    'target_name'        => $target_name ?: null,       // ← nu ifyllt när möjligt
    'target_wp_username' => $target_wp_username ?: null,// ← nu ifyllt när möjligt
    'target_ip'          => $ipKey,
    'cause'              => $cause ?: null,
    'created_by'         => $admin ?: null,
    'created_at'         => $now,
    'expires_at'         => $exp,
    'active'             => 1
  ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

  if ($ok === false) {
    $err = $wpdb->last_error ? $wpdb->last_error : 'Okänt databasfel.';
    wp_safe_redirect( add_query_arg('kkbanerr', rawurlencode($err), $back) ); exit;
  }

  if ($exp === null) {
    $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at = NULL WHERE id=%d", (int)$wpdb->insert_id));
  }
  $msg = 'IP '.$ipKey.' blockerad '.($exp ? 'till '.date_i18n('Y-m-d H:i:s', $exp) : 'för alltid').'.';
  wp_safe_redirect( add_query_arg('kkbanok', rawurlencode($msg), $back) ); exit;
}
