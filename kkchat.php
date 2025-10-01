<?php
/**
 * Plugin Name: KKchat
 * Description: Lightweight public chat + DMs with rooms, unread, autoscroll lock, scheduled banners, and moderation (admin usernames, kick with duration & cause, IP ban, backend unblock). Adds Word Rules (forbid/watchlist) with auto-kick/IP ban and admin UI. Admins see live typing previews. Includes backend logs (searchable by username) with sender/recipient IPs and manual IP ban. Admin sidebar has a 🧾 button to open an overlay with the selected user's full message history (with IPs).
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
 * Core modules
 * ------------------------------ */
require_once KKCHAT_PATH.'inc/core-helpers.php';
require_once KKCHAT_PATH.'inc/network.php';
require_once KKCHAT_PATH.'inc/auth.php';
require_once KKCHAT_PATH.'inc/rooms.php';
require_once KKCHAT_PATH.'inc/security.php';
require_once KKCHAT_PATH.'inc/admin-helpers.php';
require_once KKCHAT_PATH.'inc/moderation.php';
require_once KKCHAT_PATH.'inc/blocklist.php';
require_once KKCHAT_PATH.'inc/session.php';

/* ------------------------------
 * Feature modules
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
