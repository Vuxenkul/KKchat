<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Session helpers and lifecycle hooks.
 */
function kkchat_logout_session(bool $regenerate_id = true): void {
    global $wpdb;
    $t = kkchat_tables();

    if (!empty($_SESSION['kkchat_user_id'])) {
        $wpdb->delete($t['users'], ['id' => (int) $_SESSION['kkchat_user_id']], ['%d']);
    }

    $preserve = [];
    if (array_key_exists('kkchat_csrf', $_SESSION)) {
        $preserve['kkchat_csrf'] = $_SESSION['kkchat_csrf'];
    }

    $_SESSION = $preserve;

    if ($regenerate_id && function_exists('session_regenerate_id')) {
        @session_regenerate_id(true);
    }
}

function kkchat_require_login(bool $refresh_ttl = true) {
    if (!isset($_SESSION['kkchat_user_id'], $_SESSION['kkchat_user_name'])) {
        kkchat_json(['ok' => false, 'err' => 'Not logged in'], 403);
    }

    $ttl = (int) kkchat_user_ttl();
    if ($ttl > 0) {
        $last_active = (int) ($_SESSION['kkchat_last_active_at'] ?? 0);
        if ($last_active > 0 && (time() - $last_active) > $ttl) {
            kkchat_logout_session();
            if (function_exists('kkchat_close_session_if_open')) {
                kkchat_close_session_if_open();
            }
            kkchat_json(['ok' => false, 'err' => 'Not logged in'], 403);
        }
    }

    if ($refresh_ttl) {
        $_SESSION['kkchat_last_active_at'] = time();
    }
}

function kkchat_user_ttl() {
    return 1200;
}

function kkchat_is_guest(): bool {
    return !empty($_SESSION['kkchat_is_guest']);
}

function kkchat_current_user_id(): int {
    return (int) ($_SESSION['kkchat_user_id'] ?? 0);
}

function kkchat_current_user_name(): string {
    return (string) ($_SESSION['kkchat_user_name'] ?? '');
}

function kkchat_current_wp_username(): string {
    $v = (string) ($_SESSION['kkchat_wp_username'] ?? '');
    if ($v === '' && !empty($_SESSION['kkchat_wpname'])) {
        $v = (string) $_SESSION['kkchat_wpname'];
        $_SESSION['kkchat_wp_username'] = $v;
        unset($_SESSION['kkchat_wpname']);
    }
    return $v;
}

function kkchat_touch_active_user(bool $refresh_presence = true, bool $refresh_session_ttl = true): int {
    $now  = time();
    $name = kkchat_current_user_name();

    if ($name === '') {
        if ($refresh_session_ttl) {
            $_SESSION['kkchat_last_active_at'] = $now;
        }
        return 0;
    }

    $id      = (int) ($_SESSION['kkchat_user_id'] ?? 0);
    $name_lc = mb_strtolower($name, 'UTF-8');

    $refresh_interval     = (int) apply_filters('kkchat_presence_refresh_min_interval', 15);
    $last_presence_at     = (int) ($_SESSION['kkchat_last_presence_refresh'] ?? 0);
    $should_refresh_presence = $refresh_presence;

    if ($should_refresh_presence && $refresh_interval > 0 && $last_presence_at > 0 && ($now - $last_presence_at) < $refresh_interval) {
        $should_refresh_presence = false;
    }

    if ($should_refresh_presence) {
        kkchat_wpdb_reconnect_if_needed();
        global $wpdb;
        $t = kkchat_tables();

        $gender  = (string) ($_SESSION['kkchat_gender'] ?? '');
        $ip      = kkchat_client_ip();
        $wp_user = kkchat_current_wp_username() ?: null;

        $is_hidden = !empty($_SESSION['kkchat_auto_hidden']) ? 1 : 0;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t['users']} (name, name_lc, gender, last_seen, ip, wp_username, is_hidden)
       VALUES (%s, %s, %s, %d, %s, %s, %d)
       ON DUPLICATE KEY UPDATE
       id = LAST_INSERT_ID(id),
         gender = VALUES(gender),
         last_seen = VALUES(last_seen),
         ip = VALUES(ip),
         wp_username = VALUES(wp_username)",
            $name,
            $name_lc,
            $gender,
            $now,
            $ip,
            $wp_user,
            $is_hidden
        ));

        $id = (int) $wpdb->insert_id;
        if ($id <= 0) {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$t['users']} WHERE name_lc=%s LIMIT 1",
                $name_lc
            ));
        }
        if ($id > 0) {
            $_SESSION['kkchat_user_id'] = $id;
        }
        $_SESSION['kkchat_last_presence_refresh'] = $now;
    } elseif ($id <= 0) {
        kkchat_wpdb_reconnect_if_needed();
        global $wpdb;
        $t = kkchat_tables();
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t['users']} WHERE name_lc=%s LIMIT 1",
            $name_lc
        ));
        if ($id > 0) {
            $_SESSION['kkchat_user_id'] = $id;
        }
    }

    if ($refresh_session_ttl) {
        $_SESSION['kkchat_last_active_at'] = $now;
    }

    return $id;
}

function kkchat_setcookie_samesite(string $name, string $value, int $expires, string $path, string $domain, bool $secure, bool $httponly, string $samesite = 'Lax'): void {
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        @setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite,
        ]);
        return;
    }

    @setcookie($name, $value, $expires, $path, $domain, $secure, $httponly);
}

if (!function_exists('kkchat_start_session_if_needed')) {
    function kkchat_start_session_if_needed() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        if (empty($_SESSION['kkchat_csrf'])) {
            $_SESSION['kkchat_csrf'] = bin2hex(random_bytes(16));
        }

        $path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        kkchat_setcookie_samesite(
            'kkchat_csrf',
            (string) $_SESSION['kkchat_csrf'],
            0,
            $path,
            $domain,
            is_ssl(),
            true,
            'Lax'
        );

        if (!empty($_SESSION['kkchat_wpname']) && empty($_SESSION['kkchat_wp_username'])) {
            $_SESSION['kkchat_wp_username'] = (string) $_SESSION['kkchat_wpname'];
            unset($_SESSION['kkchat_wpname']);
        }
    }
}

add_action('rest_api_init', function () {
    if (
        defined('REST_REQUEST') && REST_REQUEST &&
        isset($_SERVER['REQUEST_URI']) &&
        strpos($_SERVER['REQUEST_URI'], '/wp-json/kkchat/v1') !== false
    ) {
        kkchat_start_session_if_needed();
    }
});

add_action('wp', function () {
    if (!is_singular()) {
        return;
    }
    $post = get_post();
    if (!$post || empty($post->post_content)) {
        return;
    }
    if (has_shortcode($post->post_content, 'kkchat')) {
        kkchat_start_session_if_needed();
    }
});

if (!function_exists('kkchat_rest_logout')) {
    add_action('rest_api_init', function () {
        register_rest_route('kkchat/v1', '/logout', [
            'methods'             => 'POST',
            'callback'            => 'kkchat_rest_logout',
            'permission_callback' => '__return_true',
        ]);
    });

    function kkchat_rest_logout(WP_REST_Request $req) {
        $csrf = (string) $req->get_param('csrf_token');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['kkchat_csrf']) || !hash_equals($_SESSION['kkchat_csrf'], $csrf)) {
            return new WP_REST_Response(['ok' => false, 'err' => 'bad_csrf'], 403);
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'] ?: '/',
                $p['domain'] ?: '',
                !empty($p['secure']),
                !empty($p['httponly'])
            );

            if (PHP_VERSION_ID >= 70300) {
                $opts = [
                    'expires'  => time() - 42000,
                    'path'     => $p['path'] ?: '/',
                    'domain'   => $p['domain'] ?: '',
                    'secure'   => !empty($p['secure']),
                    'httponly' => !empty($p['httponly']),
                ];
                if (!empty($p['samesite'])) {
                    $opts['samesite'] = $p['samesite'];
                }
                setcookie(session_name(), '', $opts);
            }
        }

        @session_destroy();

        foreach ($_COOKIE as $name => $val) {
            if (stripos($name, 'kkchat_') === 0) {
                setcookie($name, '', time() - 42000, '/');
            }
        }

        nocache_headers();

        return new WP_REST_Response(['ok' => true], 200);
    }
}
