<?php
if (!defined('ABSPATH')) exit;

/* ==============================================================
 *                     USER BLOCKLIST HELPERS
 * ==============================================================
 */

/**
 * Return the in-session key for guest blocklist (ephemeral).
 */
function kkchat_guest_blocklist_key(): string {
  return 'kkchat_guest_block_ids';
}

/**
 * Clear the cached blocklist for a given blocker (per-request memory cache).
 */
function kkchat_blocklist_cache_clear(int $blocker_id): void {
  static $cache = null;
  if ($cache === null) { $cache = []; }
  unset($cache[$blocker_id]);
}

/**
 * Get array<int> of target user IDs blocked by $blocker_id.
 * - Registered users: read from DB table kkchat_user_blocks (active=1)
 * - Guests: read from session only
 */
function kkchat_blocked_ids(int $blocker_id): array {
  static $cache = [];
  if (isset($cache[$blocker_id])) return $cache[$blocker_id];

  if ($blocker_id <= 0) return $cache[$blocker_id] = [];

  if (kkchat_is_guest() || $blocker_id !== kkchat_current_user_id()) {
    // Guests (or if somehow asking for a different user while guest): session-scoped
    $ids = array_map('intval', array_values($_SESSION[kkchat_guest_blocklist_key()] ?? []));
    $ids = array_values(array_unique(array_filter($ids, fn($v)=>$v>0)));
    return $cache[$blocker_id] = $ids;
  }

  global $wpdb; $t = kkchat_tables();
  $rows = $wpdb->get_col($wpdb->prepare("SELECT target_id FROM {$t['user_blocks']} WHERE blocker_id=%d AND active=1", $blocker_id)) ?: [];
  $rows = array_values(array_unique(array_map('intval', $rows)));
  return $cache[$blocker_id] = $rows;
}

/** Check if $target_id is in $blocker_id's blocklist. */
function kkchat_is_blocked_by(int $blocker_id, int $target_id): bool {
  if ($blocker_id <= 0 || $target_id <= 0) return false;
  if ($blocker_id === $target_id) return false;
  return in_array($target_id, kkchat_blocked_ids($blocker_id), true);
}

/**
 * Add target to current user's blocklist.
 * Returns ['ok'=>bool, 'now_blocked'=>bool, 'err'=>?]
 */
function kkchat_block_add(int $target_id, ?string $target_wp_username = null): array {
  $blocker_id = kkchat_current_user_id();
  if ($blocker_id <= 0) return ['ok'=>false, 'now_blocked'=>false, 'err'=>'not_logged_in'];
  if ($target_id <= 0) return ['ok'=>false, 'now_blocked'=>false, 'err'=>'bad_target'];
  if ($blocker_id === $target_id) return ['ok'=>false, 'now_blocked'=>false, 'err'=>'self_block'];

  // Admins cannot be blocked (server-enforced; do not trust client input)
  if (kkchat_is_admin_id($target_id)) {
    return ['ok'=>false, 'now_blocked'=>false, 'err'=>'cant_block_admin'];
  }

  // Guests -> session-only
  if (kkchat_is_guest()) {
    $key = kkchat_guest_blocklist_key();
    $set = array_map('intval', $_SESSION[$key] ?? []);
    $set[] = $target_id;
    $_SESSION[$key] = array_values(array_unique(array_filter($set, fn($v)=>$v>0 && $v!==$blocker_id)));
    kkchat_blocklist_cache_clear($blocker_id);
    return ['ok'=>true, 'now_blocked'=>true];
  }

  // Registered -> persist in DB
  global $wpdb; $t = kkchat_tables(); $now = time();

  // Try update existing row
  $exists = $wpdb->get_row($wpdb->prepare(
    "SELECT id, active FROM {$t['user_blocks']} WHERE blocker_id=%d AND target_id=%d LIMIT 1",
    $blocker_id, $target_id
  ), ARRAY_A);

  if ($exists) {
    if ((int)$exists['active'] === 1) {
      // already blocked
      kkchat_blocklist_cache_clear($blocker_id);
      return ['ok'=>true, 'now_blocked'=>true];
    }
    $wpdb->update(
      $t['user_blocks'],
      ['active'=>1, 'updated_at'=>$now],
      ['id'=>(int)$exists['id']],
      ['%d','%d'],
      ['%d']
    );
  } else {
    $wpdb->insert(
      $t['user_blocks'],
      [
        'blocker_id' => $blocker_id,
        'target_id'  => $target_id,
        'active'     => 1,
        'created_at' => $now,
        'updated_at' => $now,
      ],
      ['%d','%d','%d','%d','%d']
    );
  }

  kkchat_blocklist_cache_clear($blocker_id);
  return ['ok'=>true, 'now_blocked'=>true];
}

/**
 * Remove target from current user's blocklist.
 * Returns ['ok'=>bool, 'now_blocked'=>bool]
 */
function kkchat_block_remove(int $target_id): array {
  $blocker_id = kkchat_current_user_id();
  if ($blocker_id <= 0) return ['ok'=>false, 'now_blocked'=>false, 'err'=>'not_logged_in'];
  if ($target_id <= 0) return ['ok'=>false, 'now_blocked'=>false, 'err'=>'bad_target'];
  if ($blocker_id === $target_id) return ['ok'=>false, 'now_blocked'=>false, 'err'=>'self_block'];

  if (kkchat_is_guest()) {
    $key = kkchat_guest_blocklist_key();
    $set = array_map('intval', $_SESSION[$key] ?? []);
    $set = array_values(array_filter($set, fn($v)=>$v !== $target_id));
    $_SESSION[$key] = $set;
    kkchat_blocklist_cache_clear($blocker_id);
    return ['ok'=>true, 'now_blocked'=>false];
  }

  global $wpdb; $t = kkchat_tables(); $now = time();
  $wpdb->query($wpdb->prepare(
    "UPDATE {$t['user_blocks']} SET active=0, updated_at=%d WHERE blocker_id=%d AND target_id=%d",
    $now, $blocker_id, $target_id
  ));
  kkchat_blocklist_cache_clear($blocker_id);
  return ['ok'=>true, 'now_blocked'=>false];
}

/**
 * Toggle block state for current user against $target_id.
 * Optionally pass $target_wp_username so we can enforce the "admins can't be blocked" rule.
 * Returns ['ok'=>bool, 'now_blocked'=>bool, 'err'=>?]
 */
function kkchat_block_toggle(int $target_id, ?string $_unused = null): array {
  $blocker_id = kkchat_current_user_id();
  if (kkchat_is_blocked_by($blocker_id, $target_id)) {
    return kkchat_block_remove($target_id);
  }
  // Server resolves admin status by ID; ignore any client-provided username.
  return kkchat_block_add($target_id);
}
