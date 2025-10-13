<?php
/**
 * Plugin Name: KKchat
 * Description: Lightweight public chat + DMs with rooms, unread, autoscroll lock, scheduled banners, and moderation (admin usernames, kick with duration & cause, IP ban, backend unblock). Adds Word Rules (forbid/watchlist) with auto-kick/IP ban and admin UI. Admins see the latest message each user sent. Includes backend logs (searchable by username) with sender/recipient IPs and manual IP ban. Admin sidebar has a ðŸ§¾ button to open an overlay with the selected user's full message history (with IPs).
 * Version: 1.7.0
 * Author: KK
 * Text Domain: kkchat
 */

if (!defined('ABSPATH')) exit;

/* ------------------------------
 * Constants
 * ------------------------------ */
define('KKCHAT_PATH', plugin_dir_path(__FILE__));
define('KKCHAT_URL',  plugin_dir_url(__FILE__));

/* ------------------------------
 * Core helpers (shared across all includes)
 * ------------------------------ */
function kkchat_watch_reset_after(): int {
  return (int) apply_filters('kkchat_watch_reset_after', 60);
}

function kkchat_tables(){
  global $wpdb;
  $p = $wpdb->prefix.'kkchat_';
  return [
    'messages'    => $p.'messages',
    'reads'       => $p.'reads',
    'users'       => $p.'users',      // ← was kkchat_active_users
    'rooms'       => $p.'rooms',
    'banners'     => $p.'banners',
    'blocks'      => $p.'blocks',
    'rules'       => $p.'rules',      // ← was kkchat_word_rules
    'reports'     => $p.'reports',
    'user_blocks' => $p.'user_blocks',
  ];
}


function kkchat_ensure_users_table() {
  global $wpdb; $t = kkchat_tables();
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t['users'])) === $t['users']) return;
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
    `typing_text` VARCHAR(200) DEFAULT NULL,
    `typing_room` VARCHAR(64) DEFAULT NULL,
    `typing_to` INT UNSIGNED DEFAULT NULL,
    `typing_at` INT UNSIGNED DEFAULT NULL,
    `watch_flag` TINYINT(1) NOT NULL DEFAULT 0,
    `watch_flag_at` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_name_lc` (`name_lc`),
    KEY `idx_last_seen` (`last_seen`),
    KEY `idx_wpuser` (`wp_username`)
  ) $charset;");
}
add_action('init', 'kkchat_ensure_users_table', 1);


// Register the front-end stylesheet for the shortcode.
add_action('wp_enqueue_scripts', function () {
    $css_path = plugin_dir_path(__FILE__) . 'assets/css/kkchat.css';
    $ver = file_exists($css_path) ? filemtime($css_path) : null;

    wp_register_style(
        'kkchat',
        plugin_dir_url(__FILE__) . 'assets/css/kkchat.css',
        [],
        $ver
    );
}, 100); // ⬅️ enqueue late so our CSS overrides theme CSS


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
/**
 * Release the global wpdb connection so long-running requests (e.g. long poll)
 * do not keep MySQL connections open while idling. Falls back gracefully if
 * the current WP version lacks wpdb::close().
 */
function kkchat_wpdb_close_connection(): void {
  global $wpdb;
  if (empty($wpdb)) { return; }

  if (method_exists($wpdb, 'close')) {
    $wpdb->close();
    return;
  }

  if (isset($wpdb->dbh) && $wpdb->dbh) {
    // Handle both mysqli and legacy mysql extensions defensively.
    if (!empty($wpdb->use_mysqli) && class_exists('mysqli') && $wpdb->dbh instanceof mysqli) {
      @mysqli_close($wpdb->dbh);
    } elseif (is_resource($wpdb->dbh) && function_exists('mysql_close')) {
      @mysql_close($wpdb->dbh);
    }

    $wpdb->dbh          = null;
    $wpdb->ready        = false;
    $wpdb->has_connected = false;
  }
}

/** Ensure the global wpdb connection is ready after an intentional close(). */
function kkchat_wpdb_reconnect_if_needed(): void {
  global $wpdb;
  if (empty($wpdb)) { return; }

  if (method_exists($wpdb, 'check_connection')) {
    $wpdb->check_connection(false);
    return;
  }

  if (empty($wpdb->dbh) && method_exists($wpdb, 'db_connect')) {
    $wpdb->db_connect(false);
  }
}

/** SECURITY: use ENT_QUOTES so attributes are safe too. */
function kkchat_html_esc($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ------------------------------
 * Duplicate-prevention helpers (turbo)
 * ------------------------------ */
function kkchat_normalize_text(string $s): string {
  // UtgÃ¥r frÃ¥n rÃ¥text (inte HTML-escaped)
  $s = html_entity_decode($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
  // Normalisera URL:er sÃ¥ inte "samma lÃ¤nk" med olika tracking rÃ¤knas som olika
  $s = preg_replace('~https?://\S+~iu', 'url', $s);
  // SlÃ¥ ihop upprepade skiljetecken (!!!!!, ???, â€¦)
  $s = preg_replace('~([!?.,â€¦])\1{1,}~u', '$1', $s);
  // Komprimera whitespace
  $s = preg_replace('~\s+~u', ' ', $s);
  // Trim + till gemener
  $s = trim(mb_strtolower($s, 'UTF-8'));
  return $s;
}

// Tunables
function kkchat_dupe_window_seconds(): int   { return (int) apply_filters('kkchat_dupe_window_seconds',   (int) get_option('kkchat_dupe_window_seconds', 120)); }
function kkchat_dupe_fast_seconds(): int     { return (int) apply_filters('kkchat_dupe_fast_seconds',     (int) get_option('kkchat_dupe_fast_seconds', 30)); }
function kkchat_dupe_max_repeats(): int      { return (int) apply_filters('kkchat_dupe_max_repeats',      (int) get_option('kkchat_dupe_max_repeats', 2)); }
function kkchat_min_interval_seconds(): int  { return (int) apply_filters('kkchat_min_interval_seconds',  (int) get_option('kkchat_min_interval_seconds', 3)); }
function kkchat_dupe_autokick_minutes(): int { return (int) apply_filters('kkchat_dupe_autokick_minutes', (int) get_option('kkchat_dupe_autokick_minutes', 1)); }
function kkchat_dedupe_window(): int         { return (int) apply_filters('kkchat_dedupe_window',         (int) get_option('kkchat_dedupe_window', 10)); }

function kkchat_sanitize_guest_nick(string $nick): string {
  $nick = trim($nick);
  $nick = preg_replace('~[^\p{L}\p{N} _\-]~u', '', $nick);
  $nick = preg_replace('~\s+~', ' ', $nick);
  $nick = trim($nick);
  if ($nick === '') return '';
  if (mb_strlen($nick) > 24) $nick = mb_substr($nick, 0, 24);
  if (!preg_match('~-guest$~i', $nick)) $nick .= '-guest';
  return $nick;
}
function kkchat_sanitize_name_nosuffix(string $name): string {
  $name = trim($name);
  $name = preg_replace('~[^\p{L}\p{N} _\-]~u', '', $name);
  $name = preg_replace('~\s+~', ' ', $name);
  $name = trim($name);
  if ($name === '') return '';
  if (mb_strlen($name) > 24) $name = mb_substr($name, 0, 24);
  return $name;
}
function kkchat_sanitize_room_slug(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('~[^a-z0-9_\-]~', '', $s);
  return substr($s, 0, 64);
}

/* ------------------------------
 * Auth/session helpers
 * ------------------------------ */
function kkchat_logout_session(bool $regenerate_id = true): void {
  global $wpdb; $t = kkchat_tables();

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
    kkchat_json(['ok'=>false, 'err'=>'Not logged in'], 403);
  }

  $ttl = (int) kkchat_user_ttl();
  if ($ttl > 0) {
    $last_active = (int) ($_SESSION['kkchat_last_active_at'] ?? 0);
    if ($last_active > 0 && (time() - $last_active) > $ttl) {
      kkchat_logout_session();
      if (function_exists('kkchat_close_session_if_open')) {
        kkchat_close_session_if_open();
      }
      kkchat_json(['ok'=>false, 'err'=>'Not logged in'], 403);
    }
  }

  if ($refresh_ttl) {
    $_SESSION['kkchat_last_active_at'] = time();
  }
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
function kkchat_touch_active_user(bool $refresh_presence = true, bool $refresh_session_ttl = true): int {
  $now  = time();
  $name = kkchat_current_user_name();

  if ($name === '') {
    if ($refresh_session_ttl) {
      $_SESSION['kkchat_last_active_at'] = $now;
    }
    return 0;
  }

  $id       = (int) ($_SESSION['kkchat_user_id'] ?? 0);
  $name_lc  = mb_strtolower($name, 'UTF-8');

  if ($refresh_presence) {
    global $wpdb; $t = kkchat_tables();

    $gender  = (string) ($_SESSION['kkchat_gender'] ?? '');
    $ip      = kkchat_client_ip();
    $wp_user = kkchat_current_wp_username() ?: null;

    // INSERT … ON DUPLICATE KEY UPDATE keyed by uniq_name_lc(name_lc)
    $wpdb->query($wpdb->prepare(
      "INSERT INTO {$t['users']} (name, name_lc, gender, last_seen, ip, wp_username)
       VALUES (%s, %s, %s, %d, %s, %s)
       ON DUPLICATE KEY UPDATE
       id = LAST_INSERT_ID(id),
         gender = VALUES(gender),
         last_seen = VALUES(last_seen),
         ip = VALUES(ip),
         wp_username = VALUES(wp_username)",
      $name, $name_lc, $gender, $now, $ip, $wp_user
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
  } elseif ($id <= 0) {
    global $wpdb; $t = kkchat_tables();
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

/* ------------------------------
 * CSRF
 * ------------------------------ */
function kkchat_check_csrf_or_fail(WP_REST_Request $req) {
  $given = (string) $req->get_param('csrf_token');
  $ok_session = ($given !== '') && isset($_SESSION['kkchat_csrf']) && hash_equals($_SESSION['kkchat_csrf'], $given);
  $wp_hdr = $req->get_header('X-WP-Nonce');
  $ok_wp  = $wp_hdr && wp_verify_nonce($wp_hdr, 'wp_rest');
  if (!($ok_session || $ok_wp)) kkchat_json(['ok'=>false, 'err'=>'CSRF'], 403);
}

/* ------------------------------
 * Networking
 * ------------------------------ */
/**
 * SECURITY: Only trust proxy headers if explicitly allowed via filters.
 * By default returns REMOTE_ADDR to avoid spoofing.
 *
 * Filters:
 * - kkchat_trust_proxy_headers (bool): master switch (default false)
 * - kkchat_trusted_proxies (array<string>): list of proxy IPs you trust; if provided,
 *   REMOTE_ADDR must be in this list to honor forwarded headers.
 */
function kkchat_client_ip(): string {
  $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
  $remote = preg_replace('~[^0-9a-fA-F\:\.]~', '', $remote);

  $trust = (bool) apply_filters('kkchat_trust_proxy_headers', false);
  $trusted_list = (array) apply_filters('kkchat_trusted_proxies', []);

  if ($trust && $remote && (!empty($trusted_list) ? in_array($remote, $trusted_list, true) : true)) {
    // Prefer Cloudflare header if present
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
      $ip = preg_replace('~[^0-9a-fA-F\:\.]~', '', (string) $_SERVER['HTTP_CF_CONNECTING_IP']);
      if ($ip) return $ip;
    }
    // Then X-Forwarded-For (first hop)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $xff = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
      $ip  = preg_replace('~[^0-9a-fA-F\:\.]~', '', trim($xff[0] ?? ''));
      if ($ip) return $ip;
    }
    // Or X-Real-IP
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
      $ip = preg_replace('~[^0-9a-fA-F\:\.]~', '', (string) $_SERVER['HTTP_X_REAL_IP']);
      if ($ip) return $ip;
    }
  }

  return $remote ?: '0.0.0.0';
}

/* ------------------------------
 * IP helpers for bans
 * ------------------------------ */
function kkchat_is_ipv4(string $ip): bool {
  return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}
function kkchat_is_ipv6(string $ip): bool {
  return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
}
/** Return normalized IPv6 /64 (e.g., "2001:db8:abcd:1234::/64") or null on failure. */
function kkchat_ipv6_prefix64(string $ip): ?string {
  $bin = @inet_pton($ip);
  if ($bin === false || strlen($bin) !== 16) return null;
  // keep first 64 bits, zero the last 64 bits
  $masked = substr($bin, 0, 8) . str_repeat("\x00", 8);
  $net = @inet_ntop($masked);
  if ($net === false) return null;
  return strtolower($net) . '/64';
}
/** The value we store in blocks.target_ip for bans. */
function kkchat_ip_ban_key(?string $ip): ?string {
  if (!$ip) return null;
  $ip = trim($ip);
  if (kkchat_is_ipv4($ip)) return $ip;
  if (kkchat_is_ipv6($ip)) return kkchat_ipv6_prefix64($ip) ?: $ip;
  return $ip;
}

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

/* ------------------------------
 * Moderation (kicks / IP bans)
 * ------------------------------ */
function kkchat_moderation_block_for($uid, $name, $wp_username, $ip) {
  global $wpdb; $t = kkchat_tables(); $now = time();
  // Clean expired
  $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET active=0 WHERE active=1 AND expires_at IS NOT NULL AND expires_at <= %d", $now));

  // Active IP ban?
  if ($ip) {
    $key = kkchat_ip_ban_key($ip);
    if ($key) {
      // Preferred: IPv4 exact OR IPv6 /64
      $ban = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['blocks']} WHERE active=1 AND type='ipban' AND target_ip = %s LIMIT 1",
        $key
      ), ARRAY_A);

      // Back-compat: if IPv6 and no /64 match, also try exact stored IPv6
      if (!$ban && kkchat_is_ipv6($ip)) {
        $norm = strtolower(@inet_ntop(@inet_pton($ip)) ?: $ip);
        $ban = $wpdb->get_row($wpdb->prepare(
          "SELECT * FROM {$t['blocks']} WHERE active=1 AND type='ipban' AND target_ip = %s LIMIT 1",
          $norm
        ), ARRAY_A);
      }

      if ($ban) return ['type'=>'ipban','row'=>$ban];
    }
  }
  // Active kick by wp_username (preferred) or by display name
  if ($wp_username) {
    $kick = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['blocks']} WHERE active=1 AND type='kick' AND target_wp_username = %s LIMIT 1", $wp_username), ARRAY_A);
    if ($kick) return ['type'=>'kick','row'=>$kick];
  }
  if ($name) {
    $kick2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['blocks']} WHERE active=1 AND type='kick' AND target_name = %s LIMIT 1", $name), ARRAY_A);
    if ($kick2) return ['type'=>'kick','row'=>$kick2];
  }
  return null;
}
function kkchat_assert_not_blocked_or_fail() {
  $uid = (int) ($_SESSION['kkchat_user_id'] ?? 0);
  $name = (string) ($_SESSION['kkchat_user_name'] ?? '');
  $wp_username = kkchat_current_wp_username();
  $ip = kkchat_client_ip();
  $b = kkchat_moderation_block_for($uid, $name, $wp_username, $ip);
  if ($b) {
    if ($b['type'] === 'ipban') kkchat_json(['ok'=>false,'err'=>'ip_banned','cause'=>$b['row']['cause'] ?? ''], 403);
    if ($b['type'] === 'kick')  kkchat_json(['ok'=>false,'err'=>'kicked','cause'=>$b['row']['cause'] ?? '', 'until'=>$b['row']['expires_at'] ?? null], 403);
  }
}

/* ------------------------------
 * Word rules
 * ------------------------------ */
function kkchat_seconds_from_unit($n, $unit){
  $n = max(0,(int)$n); $unit = strtolower(trim((string)$unit));
  if ($n === 0) return 0;
  return match($unit){
    'minute','minutes','min','m' => $n*60,
    'hour','hours','h'          => $n*3600,
    'day','days','d'            => $n*86400,
    default                     => $n
  };
}
function kkchat_rules_active(){
  static $cache = null; if ($cache !== null) return $cache;
  global $wpdb; $t = kkchat_tables();
  $rows = $wpdb->get_results("SELECT * FROM {$t['rules']} WHERE enabled=1", ARRAY_A) ?: [];
  foreach ($rows as &$r){
    $r['word'] = trim((string)$r['word']);
    $r['match_type'] = $r['match_type'] ?: 'contains';
  }
  return $cache = $rows;
}

/**
 * SECURITY: guard against catastrophic regexes:
 * - Limit pattern length
 * - Disallow newlines (keeps it single-line)
 * - Use the 'u' modifier; suppress warnings if pattern is invalid
 */
function kkchat_rule_matches(array $rule, string $text): bool{
  $w = (string) ($rule['word'] ?? '');
  if ($w === '') return false;
  $type = $rule['match_type'] ?: 'contains';

  if ($type === 'regex'){
    // hard caps / minimal sanity checks
    if (mb_strlen($w, 'UTF-8') > (int) apply_filters('kkchat_regex_max_len', 256)) return false;
    if (preg_match("~[\r\n]~u", $w)) return false;
    return @preg_match('~' . $w . '~u', $text) === 1;
  }
  if ($type === 'exact'){
    return mb_strtolower(trim($text), 'UTF-8') === mb_strtolower(trim($w), 'UTF-8');
  }
  return mb_stripos($text, $w, 0, 'UTF-8') !== false;
}

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

/* ------------------------------
 * Load modules
 * ------------------------------ */
require_once KKCHAT_PATH.'inc/schema.php';      // activation/deactivation, dbDelta, upgrade, cron
require_once KKCHAT_PATH.'inc/rest.php';        // all REST routes (public + admin)
if (is_admin()) {
  require_once KKCHAT_PATH.'inc/admin-pages.php'; // admin screens
}
require_once KKCHAT_PATH.'inc/shortcode.php';   // [kkchat] UI

/* ------------------------------
 * Hook plugin lifecycle to schema module
 * ------------------------------ */
register_activation_hook(__FILE__, 'kkchat_activate');
register_deactivation_hook(__FILE__, 'kkchat_deactivate');
