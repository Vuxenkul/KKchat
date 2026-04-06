<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * User blocklist utilities.
 */
function kkchat_guest_blocklist_key(): string {
    return 'kkchat_guest_block_ids';
}

function kkchat_blocklist_cache_clear(int $blocker_id): void {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
    }
    unset($cache[$blocker_id]);
}

function kkchat_blocked_ids(int $blocker_id): array {
    static $cache = [];
    if ($blocker_id <= 0) {
        return [];
    }
    if (isset($cache[$blocker_id])) {
        return $cache[$blocker_id];
    }

    if (kkchat_is_guest()) {
        $key  = kkchat_guest_blocklist_key();
        $rows = array_map('intval', $_SESSION[$key] ?? []);
        return $cache[$blocker_id] = array_values(array_unique(array_filter($rows, fn($v) => $v > 0 && $v !== $blocker_id)));
    }

    global $wpdb;
    $t    = kkchat_tables();
    $rows = $wpdb->get_col($wpdb->prepare("SELECT target_id FROM {$t['user_blocks']} WHERE blocker_id=%d AND active=1", $blocker_id)) ?: [];
    $rows = array_map('intval', $rows);
    return $cache[$blocker_id] = $rows;
}

function kkchat_is_blocked_by(int $blocker_id, int $target_id): bool {
    if ($blocker_id <= 0 || $target_id <= 0) {
        return false;
    }
    if ($blocker_id === $target_id) {
        return false;
    }
    return in_array($target_id, kkchat_blocked_ids($blocker_id), true);
}

function kkchat_block_add(int $target_id, ?string $target_wp_username = null): array {
    $blocker_id = kkchat_current_user_id();
    if ($blocker_id <= 0) {
        return ['ok' => false, 'now_blocked' => false, 'err' => 'not_logged_in'];
    }
    if ($target_id <= 0) {
        return ['ok' => false, 'now_blocked' => false, 'err' => 'bad_target'];
    }
    if ($blocker_id === $target_id) {
        return ['ok' => false, 'now_blocked' => false, 'err' => 'self_block'];
    }

    if (kkchat_is_admin_id($target_id)) {
        return ['ok' => false, 'now_blocked' => false, 'err' => 'cant_block_admin'];
    }

    if (kkchat_is_guest()) {
        $key = kkchat_guest_blocklist_key();
        $set = array_map('intval', $_SESSION[$key] ?? []);
        $set[] = $target_id;
        $_SESSION[$key] = array_values(array_unique(array_filter($set, fn($v) => $v > 0 && $v !== $blocker_id)));
        kkchat_blocklist_cache_clear($blocker_id);
        return ['ok' => true, 'now_blocked' => true];
    }

    global $wpdb;
    $t   = kkchat_tables();
    $now = time();

    $exists = $wpdb->get_row($wpdb->prepare(
        "SELECT id, active FROM {$t['user_blocks']} WHERE blocker_id=%d AND target_id=%d LIMIT 1",
        $blocker_id,
        $target_id
    ), ARRAY_A);

    if ($exists) {
        if ((int) $exists['active'] === 1) {
            kkchat_blocklist_cache_clear($blocker_id);
            return ['ok' => true, 'now_blocked' => true];
        }
        $wpdb->update(
            $t['user_blocks'],
            ['active' => 1, 'updated_at' => $now],
            ['id' => (int) $exists['id']],
            ['%d', '%d'],
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
            ['%d', '%d', '%d', '%d', '%d']
        );
    }

    kkchat_blocklist_cache_clear($blocker_id);
    return ['ok' => true, 'now_blocked' => true];
}

function kkchat_block_remove(int $target_id): array {
    $blocker_id = kkchat_current_user_id();
    if ($blocker_id <= 0) {
        return ['ok' => false, 'now_blocked' => false, 'err' => 'not_logged_in'];
    }
    if ($target_id <= 0) {
        return ['ok' => false, 'now_blocked' => false, 'err' => 'bad_target'];
    }
    if ($blocker_id === $target_id) {
        return ['ok' => false, 'now_blocked' => false, 'err' => 'self_block'];
    }

    if (kkchat_is_guest()) {
        $key = kkchat_guest_blocklist_key();
        $set = array_map('intval', $_SESSION[$key] ?? []);
        $set = array_values(array_filter($set, fn($v) => $v !== $target_id));
        $_SESSION[$key] = $set;
        kkchat_blocklist_cache_clear($blocker_id);
        return ['ok' => true, 'now_blocked' => false];
    }

    global $wpdb;
    $t   = kkchat_tables();
    $now = time();
    $wpdb->query($wpdb->prepare(
        "UPDATE {$t['user_blocks']} SET active=0, updated_at=%d WHERE blocker_id=%d AND target_id=%d",
        $now,
        $blocker_id,
        $target_id
    ));
    kkchat_blocklist_cache_clear($blocker_id);
    return ['ok' => true, 'now_blocked' => false];
}

function kkchat_block_toggle(int $target_id, ?string $_unused = null): array {
    $blocker_id = kkchat_current_user_id();
    if (kkchat_is_blocked_by($blocker_id, $target_id)) {
        return kkchat_block_remove($target_id);
    }
    // Server resolves admin status by ID; ignore any client-provided username.
    return kkchat_block_add($target_id);
}
