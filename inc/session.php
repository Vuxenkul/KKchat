<?php
if (!defined('ABSPATH')) exit;

/* ------------------------------
 * Lazy session start + CSRF cookie helper
 * ------------------------------ */

/** SECURITY: cookie helper with SameSite */
function kkchat_setcookie_samesite(string $name, string $value, int $expires, string $path, string $domain, bool $secure, bool $httponly, string $samesite = 'Lax'): void {
  // PHP 7.3+ supports array options
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
  // Best-effort fallback (no SameSite support pre-7.3)
  @setcookie($name, $value, $expires, $path, $domain, $secure, $httponly);
}

/** Start a session only when the chat actually runs. */
if (!function_exists('kkchat_start_session_if_needed')) {
  function kkchat_start_session_if_needed() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      @session_start();
    }

    // CSRF token in session
    if (empty($_SESSION['kkchat_csrf'])) {
      $_SESSION['kkchat_csrf'] = bin2hex(random_bytes(16));
    }

    // CSRF cookie (SameSite=Lax + HttpOnly)
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

    // Migrate legacy session key for WP username if needed
    if (!empty($_SESSION['kkchat_wpname']) && empty($_SESSION['kkchat_wp_username'])) {
      $_SESSION['kkchat_wp_username'] = (string) $_SESSION['kkchat_wpname'];
      unset($_SESSION['kkchat_wpname']);
    }
  }
}

/**
 * Start session for KKchat REST requests only (e.g., /wp-json/kkchat/v1/...)
 */
add_action('rest_api_init', function () {
  if (
    defined('REST_REQUEST') && REST_REQUEST &&
    isset($_SERVER['REQUEST_URI']) &&
    strpos($_SERVER['REQUEST_URI'], '/wp-json/kkchat/v1') !== false
  ) {
    kkchat_start_session_if_needed();
  }
});

/**
 * Start session only on pages that actually render the [kkchat] shortcode.
 */
add_action('wp', function () {
  if (!is_singular()) return;
  $post = get_post();
  if (!$post || empty($post->post_content)) return;
  if (has_shortcode($post->post_content, 'kkchat')) {
    kkchat_start_session_if_needed();
  }
});

/* ------------------------------
 * REST: Logout endpoint (used by JS doLogout())
 * ------------------------------ */
if (!function_exists('kkchat_rest_logout')) {
  add_action('rest_api_init', function () {
    register_rest_route('kkchat/v1', '/logout', [
      'methods'  => 'POST',
      'callback' => 'kkchat_rest_logout',
      'permission_callback' => '__return_true', // CSRF verified inside
    ]);
  });

  function kkchat_rest_logout(WP_REST_Request $req) {
    // Optional: verify our own CSRF token (matches the JS FormData field)
    $csrf = (string) $req->get_param('csrf_token');
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['kkchat_csrf']) || !hash_equals($_SESSION['kkchat_csrf'], $csrf)) {
      return new WP_REST_Response(['ok'=>false,'err'=>'bad_csrf'], 403);
    }

    // 1) Clear all kkchat data in the session
    $_SESSION = [];

    // 2) Expire the PHP session cookie (path/domain must match)
    if (ini_get('session.use_cookies')) {
      $p = session_get_cookie_params();

      // Pre-7.3 compatible
      setcookie(session_name(), '', time() - 42000,
        $p['path'] ?: '/',
        $p['domain'] ?: '',
        !empty($p['secure']),
        !empty($p['httponly'])
      );

      // PHP ≥ 7.3: respect SameSite too
      if (PHP_VERSION_ID >= 70300) {
        $opts = [
          'expires'  => time() - 42000,
          'path'     => $p['path'] ?: '/',
          'domain'   => $p['domain'] ?: '',
          'secure'   => !empty($p['secure']),
          'httponly' => !empty($p['httponly']),
        ];
        if (!empty($p['samesite'])) { $opts['samesite'] = $p['samesite']; }
        setcookie(session_name(), '', $opts);
      }
    }

    // 3) Destroy the session container
    @session_destroy();

    // 4) Proactively expire any app-specific cookies if you set any
    foreach ($_COOKIE as $name => $val) {
      if (stripos($name, 'kkchat_') === 0) {
        setcookie($name, '', time() - 42000, '/');
      }
    }

    // 5) Prevent caches from serving stale "logged-in" HTML
    nocache_headers();

    return new WP_REST_Response(['ok' => true], 200);
  }
}
