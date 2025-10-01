<?php
if (!defined('ABSPATH')) exit;

/* ------------------------------
 * Admin helpers
 * ------------------------------ */
function kkchat_admin_usernames(): array {
  $raw = (string) get_option('kkchat_admin_users', '');
  $arr = array_filter(array_map('trim', preg_split('~\R+~', $raw)));
  return array_map('strtolower', $arr);
}
function kkchat_is_admin(): bool {
  if (!empty($_SESSION['kkchat_is_admin'])) return true;
  $wp = wp_get_current_user();
  if ($wp && !empty($wp->user_login)) {
    $set = kkchat_admin_usernames();
    if (in_array(strtolower($wp->user_login), $set, true)) return true;
  }
  return false;
}
/** Check if a *given* WP username is an admin (used when deciding if a target can be blocked). */
function kkchat_is_admin_username(?string $wp_username): bool {
  if (!$wp_username) return false;
  return in_array(strtolower($wp_username), kkchat_admin_usernames(), true);
}

/* ------------------------------
 * Admin lookup (server-side, used by blocking logic)
 * ------------------------------ */
/**
 * Resolve info for a chat user ID from the active users table.
 * We SELECT * to avoid breaking if some columns don't exist on older installs.
 */
function kkchat_get_active_user_row(int $user_id): ?array {
  if ($user_id <= 0) return null;
  global $wpdb; $t = kkchat_tables();
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['users']} WHERE id=%d LIMIT 1", $user_id), ARRAY_A);
  return $row ?: null;
}
/** Get the target's WP username (if any) purely from the server DB. */
function kkchat_get_wp_username_for_user(int $user_id): ?string {
  $row = kkchat_get_active_user_row($user_id);
  if (!$row) return null;
  // Prefer canonical column; fall back to legacy names if present
  if (!empty($row['wp_username'])) return (string)$row['wp_username'];
  if (!empty($row['wpname']))      return (string)$row['wpname'];
  return null;
}
/** True if the given chat user ID maps to an admin, resolved server-side only. */
function kkchat_is_admin_id(int $user_id): bool {
  $row = kkchat_get_active_user_row($user_id);
  if ($row && array_key_exists('is_admin', $row) && (int)$row['is_admin'] === 1) return true;
  $wpu = kkchat_get_wp_username_for_user($user_id);
  if ($wpu && kkchat_is_admin_username($wpu)) return true;
  return false;
}
