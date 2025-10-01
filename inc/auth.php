<?php
if (!defined('ABSPATH')) exit;

/* ------------------------------
 * Auth/session helpers
 * ------------------------------ */
function kkchat_require_login() {
  if (!isset($_SESSION['kkchat_user_id'], $_SESSION['kkchat_user_name'])) kkchat_json(['ok'=>false, 'err'=>'Not logged in'], 403);
}
function kkchat_user_ttl() { return 600; }
function kkchat_is_guest(): bool { return !empty($_SESSION['kkchat_is_guest']); }
function kkchat_current_user_id(): int { return (int) ($_SESSION['kkchat_user_id'] ?? 0); }
function kkchat_current_user_name(): string { return (string) ($_SESSION['kkchat_user_name'] ?? ''); }

/**
 * SECURITY/BACK-COMPAT:
 * Read WP username from the canonical key, but migrate legacy `kkchat_wpname` if present.
 */
function kkchat_current_wp_username(): string {
  $v = (string) ($_SESSION['kkchat_wp_username'] ?? '');
  if ($v === '' && !empty($_SESSION['kkchat_wpname'])) {
    $v = (string) $_SESSION['kkchat_wpname'];
    $_SESSION['kkchat_wp_username'] = $v; // migrate
    unset($_SESSION['kkchat_wpname']);
  }
  return $v;
}

/** Revive or create the active_users row for the current session user and store its id in the session. */
function kkchat_touch_active_user(): int {
  global $wpdb; $t = kkchat_tables(); $now = time();

  $name = kkchat_current_user_name();
  if ($name === '') return 0;

  $name_lc = mb_strtolower($name, 'UTF-8');
  $gender  = (string)$_SESSION['kkchat_gender'] ?? '';
  $ip      = kkchat_client_ip();
  $wp_user = kkchat_current_wp_username() ?: null;

  // INSERT … ON DUPLICATE KEY UPDATE keyed by uniq_name_lc(name_lc)
  $wpdb->query($wpdb->prepare(
    "INSERT INTO {$t['users']} (name, name_lc, gender, last_seen, ip, wp_username)
     VALUES (%s, %s, %s, %d, %s, %s)
     ON DUPLICATE KEY UPDATE
       gender = VALUES(gender),
       last_seen = VALUES(last_seen),
       ip = VALUES(ip),
       wp_username = VALUES(wp_username)",
    $name, $name_lc, $gender, $now, $ip, $wp_user
  ));

  // Read the id and bind it to the session
  $id = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$t['users']} WHERE name_lc=%s LIMIT 1",
    $name_lc
  ));
  if ($id > 0) $_SESSION['kkchat_user_id'] = $id;

  return $id;
}
