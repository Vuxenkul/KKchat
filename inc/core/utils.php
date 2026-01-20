<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * General-purpose helpers shared across plugin modules.
 */
function kkchat_watch_reset_after(): int {
    return (int) apply_filters('kkchat_watch_reset_after', 60);
}

function kkchat_tables() {
    global $wpdb;
    $p = $wpdb->prefix . 'kkchat_';
    return [
        'messages'       => $p . 'messages',
        'reads'          => $p . 'reads',
        'last_reads'     => $p . 'last_reads',
        'last_dm_reads'  => $p . 'last_dm_reads',
        'users'          => $p . 'users',
        'rooms'          => $p . 'rooms',
        'banners'        => $p . 'banners',
        'blocks'         => $p . 'blocks',
        'rules'          => $p . 'rules',
        'reports'        => $p . 'reports',
        'user_blocks'    => $p . 'user_blocks',
    ];
}

function kkchat_ensure_users_table() {
    global $wpdb;
    $t = kkchat_tables();
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t['users'])) === $t['users']) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE `{$t['users']}` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL,
    `name_lc` VARCHAR(64) NOT NULL,
    `gender` VARCHAR(32) NOT NULL,
    `last_seen` INT UNSIGNED NOT NULL,
    `ip` VARCHAR(45) DEFAULT NULL,
    `wp_username` VARCHAR(64) DEFAULT NULL,
    `watch_flag` TINYINT(1) NOT NULL DEFAULT 0,
    `watch_flag_at` INT UNSIGNED NULL,
    `is_hidden` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_name_lc` (`name_lc`),
    KEY `idx_last_seen` (`last_seen`),
    KEY `idx_wpuser` (`wp_username`)
  ) $charset;");
}
add_action('init', 'kkchat_ensure_users_table', 1);

// Register the front-end stylesheet for the shortcode.
// Enqueue late so our CSS overrides theme CSS.
add_action('wp_enqueue_scripts', function () {
    $css_path = KKCHAT_PATH . 'assets/css/kkchat.css';
    $ver      = file_exists($css_path) ? filemtime($css_path) : null;

    wp_register_style(
        'kkchat',
        KKCHAT_URL . 'assets/css/kkchat.css',
        [],
        $ver
    );
}, 100);

function kkchat_json($data, int $code = 200) {
    $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $json    = wp_json_encode($data, $options);

    if ($json === false) {
        $json = 'null';
    }

    status_header($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        header('Pragma: no-cache');
    }

    $output     = $json;
    $gzip_ready = function_exists('gzencode') && !ini_get('zlib.output_compression');

    if ($gzip_ready) {
        $accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        if (stripos($accept_encoding, 'gzip') !== false) {
            $gzipped = gzencode($json, 6, FORCE_GZIP);
            if ($gzipped !== false) {
                $output = $gzipped;
                if (!headers_sent()) {
                    header('Content-Encoding: gzip');
                    header('Vary: Accept-Encoding');
                }
            }
        }
    }

    if (!headers_sent()) {
        header('Content-Length: ' . strlen($output));
    }

    echo $output;

    if (wp_doing_ajax()) {
        wp_die();
    }

    exit;
}

function kkchat_wpdb_close_connection(): void {
    global $wpdb;
    if (empty($wpdb)) {
        return;
    }

    if (method_exists($wpdb, 'close')) {
        $wpdb->close();
        return;
    }

    if (isset($wpdb->dbh) && $wpdb->dbh) {
        if (!empty($wpdb->use_mysqli) && class_exists('mysqli') && $wpdb->dbh instanceof mysqli) {
            @mysqli_close($wpdb->dbh);
        } elseif (is_resource($wpdb->dbh) && function_exists('mysql_close')) {
            @mysql_close($wpdb->dbh);
        }

        $wpdb->dbh           = null;
        $wpdb->ready         = false;
        $wpdb->has_connected = false;
    }
}

function kkchat_wpdb_reconnect_if_needed(): void {
    global $wpdb;
    if (empty($wpdb)) {
        return;
    }

    if (method_exists($wpdb, 'check_connection')) {
        $wpdb->check_connection(false);
        return;
    }

    if (empty($wpdb->dbh) && method_exists($wpdb, 'db_connect')) {
        $wpdb->db_connect(false);
    }
}

function kkchat_html_esc($s) {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kkchat_normalize_text(string $s): string {
    // Utgår från råtext (inte HTML-escaped)
    $s = html_entity_decode($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = preg_replace('~\s+~u', ' ', $s);
    $s = trim(mb_strtolower($s, 'UTF-8'));
    return $s;
}

function kkchat_dupe_window_seconds(): int {
    return (int) apply_filters('kkchat_dupe_window_seconds', (int) get_option('kkchat_dupe_window_seconds', 120));
}

function kkchat_dupe_fast_seconds(): int {
    return (int) apply_filters('kkchat_dupe_fast_seconds', (int) get_option('kkchat_dupe_fast_seconds', 30));
}

function kkchat_dupe_max_repeats(): int {
    return (int) apply_filters('kkchat_dupe_max_repeats', (int) get_option('kkchat_dupe_max_repeats', 2));
}

function kkchat_min_interval_seconds(): int {
    return (int) apply_filters('kkchat_min_interval_seconds', (int) get_option('kkchat_min_interval_seconds', 3));
}

function kkchat_dupe_autokick_minutes(): int {
    return (int) apply_filters('kkchat_dupe_autokick_minutes', (int) get_option('kkchat_dupe_autokick_minutes', 1));
}

function kkchat_dedupe_window(): int {
    return (int) apply_filters('kkchat_dedupe_window', (int) get_option('kkchat_dedupe_window', 10));
}

function kkchat_report_autoban_threshold(): int {
    $value = max(0, (int) get_option('kkchat_report_autoban_threshold', 0));
    return (int) apply_filters('kkchat_report_autoban_threshold', $value);
}

function kkchat_report_autoban_window_days(): int {
    $value = max(0, (int) get_option('kkchat_report_autoban_window_days', 0));
    return (int) apply_filters('kkchat_report_autoban_window_days', $value);
}

function kkchat_report_reason_rules(): array {
    $raw = get_option('kkchat_report_reason_rules', []);
    if (!is_array($raw)) {
        $raw = [];
    }

    $rules = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
        $label = isset($row['label']) ? trim((string) $row['label']) : '';
        if ($key === '' || $label === '') {
            continue;
        }
        $threshold = isset($row['threshold']) ? max(0, (int) $row['threshold']) : 0;
        $window_days = isset($row['window_days']) ? max(0, (int) $row['window_days']) : 0;

        $rules[] = [
            'key' => $key,
            'label' => $label,
            'threshold' => $threshold,
            'window_days' => $window_days,
        ];
    }

    return apply_filters('kkchat_report_reason_rules', $rules);
}

function kkchat_report_reason_rule_for_key(string $key): ?array {
    $key = sanitize_key($key);
    if ($key === '') {
        return null;
    }
    foreach (kkchat_report_reason_rules() as $rule) {
        if (($rule['key'] ?? '') === $key) {
            return $rule;
        }
    }
    return null;
}

function kkchat_sanitize_guest_nick(string $nick): string {
    $nick = preg_replace('~[^\p{L}\p{N} _\-]~u', '', $nick);
    $nick = trim($nick);
    if ($nick === '') {
        $nick = 'Guest';
    }
    if (!preg_match('~-guest$~i', $nick)) {
        $nick .= '-guest';
    }
    return substr($nick, 0, 32);
}

function kkchat_sanitize_name_nosuffix(string $name): string {
    $name = preg_replace('~[^\p{L}\p{N} _\-]~u', '', $name);
    $name = trim($name);
    if ($name === '') {
        $name = 'Guest';
    }
    return substr($name, 0, 32);
}

function kkchat_sanitize_room_slug(string $s): string {
    $s = preg_replace('~[^a-z0-9_\-]~', '', $s);
    $s = trim($s);
    return substr($s, 0, 64);
}

function kkchat_client_ip(): string {
    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    $remote = preg_replace('~[^0-9a-fA-F:\\.]~', '', $remote);

    $trust        = (bool) apply_filters('kkchat_trust_proxy_headers', false);
    $trusted_list = (array) apply_filters('kkchat_trusted_proxies', []);

    if ($trust && $remote && (!empty($trusted_list) ? in_array($remote, $trusted_list, true) : true)) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = preg_replace('~[^0-9a-fA-F:\\.]~', '', (string) $_SERVER['HTTP_CF_CONNECTING_IP']);
            if ($ip) {
                return $ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $xff = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip  = preg_replace('~[^0-9a-fA-F:\\.]~', '', trim($xff[0] ?? ''));
            if ($ip) {
                return $ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = preg_replace('~[^0-9a-fA-F:\\.]~', '', (string) $_SERVER['HTTP_X_REAL_IP']);
            if ($ip) {
                return $ip;
            }
        }
    }

    return $remote ?: '0.0.0.0';
}

function kkchat_is_ipv4(string $ip): bool {
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}

function kkchat_is_ipv6(string $ip): bool {
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
}

function kkchat_normalize_ip_for_lookup(string $ip): string {
    $ip = preg_replace('~[^0-9a-fA-F:\\.]~', '', trim($ip));
    if ($ip === '') {
        return '';
    }

    if (kkchat_is_ipv4($ip)) {
        return $ip;
    }

    if (kkchat_is_ipv6($ip)) {
        if (function_exists('inet_pton') && function_exists('inet_ntop')) {
            $packed = @inet_pton($ip);
            if ($packed !== false) {
                $decoded = @inet_ntop($packed);
                if ($decoded !== false) {
                    $ip = $decoded;
                }
            }
        }

        return strtolower($ip);
    }

    return '';
}

function kkchat_ipv6_prefix64(string $ip): ?string {
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) {
        return null;
    }

    $masked = substr($bin, 0, 8) . str_repeat("\x00", 8);
    $net    = @inet_ntop($masked);
    if ($net === false) {
        return null;
    }

    return strtolower($net) . '/64';
}

function kkchat_ip_ban_key(?string $ip): ?string {
    if (!$ip) {
        return null;
    }
    $ip = trim($ip);
    if (kkchat_is_ipv4($ip)) {
        return $ip;
    }
    if (kkchat_is_ipv6($ip)) {
        return kkchat_ipv6_prefix64($ip) ?: $ip;
    }
    return $ip;
}

function kkchat_tor_exit_cache_key(): string {
    return 'kkchat_tor_exit_ips';
}

function kkchat_tor_exit_cache_ttl(): int {
    $base = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
    $default = 45 * $base;
    $ttl = (int) apply_filters('kkchat_tor_exit_cache_ttl', $default);
    if ($ttl < $base) {
        $ttl = $base;
    }
    return $ttl;
}

function kkchat_tor_exit_cache_failure_ttl(): int {
    $ttl = (int) apply_filters('kkchat_tor_exit_cache_failure_ttl', 10 * (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60));
    if ($ttl < 60) {
        $ttl = 60;
    }
    return $ttl;
}

function kkchat_tor_exit_source_url(): string {
    return (string) apply_filters(
        'kkchat_tor_exit_source_url',
        'https://onionoo.torproject.org/details?type=relay&running=true&flag=exit&fields=exit_addresses'
    );
}

function kkchat_tor_exit_normalize_list(array $ips): array {
    $map = [];
    foreach ($ips as $ip) {
        $normalized = kkchat_normalize_ip_for_lookup((string) $ip);
        if ($normalized !== '') {
            $map[$normalized] = true;
        }
    }

    return array_keys($map);
}

function kkchat_fetch_tor_exit_ips(): array {
    if (!function_exists('wp_remote_get')) {
        return [];
    }

    $response = wp_remote_get(kkchat_tor_exit_source_url(), [
        'timeout'    => 15,
        'user-agent' => sprintf(
            'KKchat Tor Exit Fetch (+%s)',
            function_exists('home_url') ? home_url('/') : (function_exists('site_url') ? site_url('/') : '')
        ),
    ]);

    if (is_wp_error($response)) {
        return [];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return [];
    }

    $body = (string) wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [];
    }

    $relays = $json['relays'] ?? [];
    if (!is_array($relays)) {
        return [];
    }

    $ips = [];
    foreach ($relays as $relay) {
        if (empty($relay['exit_addresses']) || !is_array($relay['exit_addresses'])) {
            continue;
        }
        foreach ($relay['exit_addresses'] as $address) {
            $normalized = kkchat_normalize_ip_for_lookup((string) $address);
            if ($normalized !== '') {
                $ips[$normalized] = true;
            }
        }
    }

    return array_keys($ips);
}

function kkchat_get_tor_exit_ips(bool $force_refresh = false): array {
    static $cache = null;
    if (!$force_refresh && is_array($cache)) {
        return $cache;
    }

    $key = kkchat_tor_exit_cache_key();

    if (!$force_refresh) {
        $stored = get_transient($key);
        if (is_array($stored)) {
            return $cache = kkchat_tor_exit_normalize_list($stored);
        }
    }

    $ips = kkchat_fetch_tor_exit_ips();
    if (!empty($ips)) {
        set_transient($key, $ips, kkchat_tor_exit_cache_ttl());
        return $cache = $ips;
    }

    $cache = [];
    set_transient($key, $cache, kkchat_tor_exit_cache_failure_ttl());
    return $cache;
}

function kkchat_is_tor_exit_ip(?string $ip): bool {
    $normalized = kkchat_normalize_ip_for_lookup((string) $ip);
    if ($normalized === '') {
        return false;
    }

    $list = kkchat_get_tor_exit_ips();
    return in_array($normalized, $list, true);
}
