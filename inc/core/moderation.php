<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moderation helpers (kicks, bans, and word rules).
 */
function kkchat_cleanup_expired_blocks(bool $force = false): void {
    global $wpdb;
    $t = kkchat_tables();

    $lock_key = 'kkchat_blocks_cleanup_last';
    $now      = time();
    $last     = (int) get_option($lock_key, 0);

    // Throttle repeated requests (e.g. chat polling). Cron runs with $force=true.
    if (!$force && $last > 0 && ($now - $last) < 300) {
        return;
    }

    update_option($lock_key, $now, false);
    $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET active=0 WHERE active=1 AND expires_at IS NOT NULL AND expires_at <= %d", $now));
}

function kkchat_moderation_block_cache_flush(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['kkchat_block_check']);
    }
}

function kkchat_moderation_block_for($uid, $name, $wp_username, $ip) {
    global $wpdb;
    $t = kkchat_tables();

$cache_ttl = 20;
$cache_key = md5((string) $uid . '|' . (string) $name . '|' . (string) $ip);

if (
    session_status() === PHP_SESSION_ACTIVE &&
    isset($_SESSION['kkchat_block_check'])
) {
    $entry = $_SESSION['kkchat_block_check'];

    if (
        is_array($entry) &&
isset($entry['key'], $entry['at']) &&                      
array_key_exists('result', $entry) &&   
        $entry['key'] === $cache_key &&
        (time() - (int) $entry['at']) < $cache_ttl
    ) {
        return $entry['result'];
    }
}

    kkchat_cleanup_expired_blocks();

    if ($ip) {
        $key = kkchat_ip_ban_key($ip);
        if ($key) {
            $ban = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$t['blocks']} WHERE active=1 AND type='ipban' AND target_ip = %s LIMIT 1",
                $key
            ), ARRAY_A);

            if (!$ban && kkchat_is_ipv6($ip)) {
                $norm = strtolower(@inet_ntop(@inet_pton($ip)) ?: $ip);
                $ban  = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$t['blocks']} WHERE active=1 AND type='ipban' AND target_ip = %s LIMIT 1",
                    $norm
                ), ARRAY_A);
            }

            if ($ban) {
        $result = ['type' => 'ipban', 'row' => $ban];
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['kkchat_block_check'] = [
                'key' => $cache_key,
                'at' => time(),
                'result' => $result,
            ];
        }
        
        return $result;            }
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['kkchat_block_check'] = [
        'key' => $cache_key,
        'at' => time(),
        'result' => null,
    ];
}
    return null;
}

function kkchat_assert_not_blocked_or_fail() {
    $uid         = (int) ($_SESSION['kkchat_user_id'] ?? 0);
    $name        = (string) ($_SESSION['kkchat_user_name'] ?? '');
    $wp_username = kkchat_current_wp_username();
    $ip          = kkchat_client_ip();
    $b           = kkchat_moderation_block_for($uid, $name, $wp_username, $ip);
    if ($b) {
        $block_id = isset($b['row']['id']) ? (int) $b['row']['id'] : null;
        $cause    = (string) ($b['row']['cause'] ?? '');

        if (strpos($cause, 'Forbidden word:') === 0) {
            $cause = '';
        }
        if ($b['type'] === 'ipban') {
kkchat_json(['ok' => false, 'err' => 'ip_banned', 'cause' => $cause, 'block_id' => $block_id], 403);        }
    }
}

function kkchat_seconds_from_unit($n, $unit) {
    $n    = max(0, (int) $n);
    $unit = strtolower(trim((string) $unit));
    if ($n === 0) {
        return 0;
    }
    return match ($unit) {
        'minute', 'minutes', 'min', 'm' => $n * 60,
        'hour', 'hours', 'h'          => $n * 3600,
        'day', 'days', 'd'            => $n * 86400,
        default                       => $n,
    };
}

function kkchat_rules_active() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    global $wpdb;
    $t    = kkchat_tables();
    $rows = $wpdb->get_results("SELECT * FROM {$t['rules']} WHERE enabled=1", ARRAY_A) ?: [];
    foreach ($rows as &$r) {
        $r['word']       = trim((string) $r['word']);
        $r['match_type'] = $r['match_type'] ?: 'contains';
    }
    return $cache = $rows;
}

function kkchat_rule_matches(array $rule, string $text): bool {
    $w    = (string) ($rule['word'] ?? '');
    if ($w === '') {
        return false;
    }
    $type = $rule['match_type'] ?: 'contains';

    if ($type === 'regex') {
        if (mb_strlen($w, 'UTF-8') > (int) apply_filters('kkchat_regex_max_len', 256)) {
            return false;
        }
        if (preg_match('~[\r\n]~u', $w)) {
            return false;
        }
        return @preg_match('~' . $w . '~u', $text) === 1;
    }
    if ($type === 'exact') {
        return mb_strtolower(trim($text), 'UTF-8') === mb_strtolower(trim($w), 'UTF-8');
    }
    return mb_stripos($text, $w, 0, 'UTF-8') !== false;
}