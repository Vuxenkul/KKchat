<?php
if (!defined('ABSPATH')) exit;

/* ------------------------------
 * Constants helpers
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
    'users'       => $p.'users',
    'rooms'       => $p.'rooms',
    'banners'     => $p.'banners',
    'blocks'      => $p.'blocks',
    'rules'       => $p.'rules',
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
    $css_path = KKCHAT_PATH . 'assets/css/kkchat.css';
    $ver = file_exists($css_path) ? filemtime($css_path) : null;

    wp_register_style(
        'kkchat',
        KKCHAT_URL . 'assets/css/kkchat.css',
        [],
        $ver
    );
}, 100);

function kkchat_json($data, int $code = 200) {
  status_header($code);
  wp_send_json($data, $code, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** SECURITY: use ENT_QUOTES so attributes are safe too. */
function kkchat_html_esc($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ------------------------------
 * Duplicate-prevention helpers (turbo)
 * ------------------------------ */
function kkchat_normalize_text(string $s): string {
  // Utgår från råtext (inte HTML-escaped)
  $s = html_entity_decode($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
  // Normalisera URL:er så inte "samma länk" med olika tracking räknas som olika
  $s = preg_replace('~https?://\\S+~iu', 'url', $s);
  // Slå ihop upprepade skiljetecken (!!!!!, ???, …)
  $s = preg_replace('~([!?.,…])\\1{1,}~u', '$1', $s);
  // Komprimera whitespace
  $s = preg_replace('~\\s+~u', ' ', $s);
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
  $nick = preg_replace('~[^\\p{L}\\p{N} _\-]~u', '', $nick);
  $nick = preg_replace('~\\s+~', ' ', $nick);
  $nick = trim($nick);
  if ($nick === '') return '';
  if (mb_strlen($nick) > 24) $nick = mb_substr($nick, 0, 24);
  if (!preg_match('~-guest$~i', $nick)) $nick .= '-guest';
  return $nick;
}
function kkchat_sanitize_name_nosuffix(string $name): string {
  $name = trim($name);
  $name = preg_replace('~[^\\p{L}\\p{N} _\-]~u', '', $name);
  $name = preg_replace('~\\s+~', ' ', $name);
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

function kkchat_dm_key(int $a, int $b): string {
  if ($a > $b) {
    $tmp = $a;
    $a   = $b;
    $b   = $tmp;
  }
  return $a . ':' . $b;
}
