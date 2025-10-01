<?php
if (!defined('ABSPATH')) exit;

/* ------------------------------
 * Rooms
 * ------------------------------ */
function kkchat_get_room(string $slug) {
  global $wpdb; $t = kkchat_tables();
  $slug = kkchat_sanitize_room_slug($slug);
  return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['rooms']} WHERE slug=%s", $slug), ARRAY_A);
}
function kkchat_can_access_room(string $slug): bool {
  $r = kkchat_get_room($slug);
  if (!$r) return false;
  if (!empty($r['member_only']) && kkchat_is_guest()) return false;
  return true;
}
