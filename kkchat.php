<?php
/**
 * Plugin Name: KKchat
 * Description: Lightweight public chat + DMs with rooms, unread, autoscroll lock, scheduled banners, and moderation (admin usernames, kick with duration & cause, IP ban, backend unblock). Adds Word Rules (forbid/watchlist) with auto-kick/IP ban and admin UI. Admins see the latest message each user sent. Includes backend logs (searchable by username) with sender/recipient IPs and manual IP ban. Admin sidebar has a ðŸ§¾ button to open an overlay with the selected user's full message history (with IPs).
 * Version: 1.7.0
 * Author: KK
 * Text Domain: kkchat
 */

if (!defined('ABSPATH')) {
    exit;
}

// Core constants used across the plugin.
define('KKCHAT_PATH', plugin_dir_path(__FILE__));
define('KKCHAT_URL', plugin_dir_url(__FILE__));

// Split core responsibilities into dedicated modules for readability.
require_once KKCHAT_PATH . 'inc/core/utils.php';
require_once KKCHAT_PATH . 'inc/core/session.php';
require_once KKCHAT_PATH . 'inc/core/rooms.php';
require_once KKCHAT_PATH . 'inc/core/admin.php';
require_once KKCHAT_PATH . 'inc/core/moderation.php';
require_once KKCHAT_PATH . 'inc/core/blocklist.php';

// Load the remaining feature modules.
require_once KKCHAT_PATH . 'inc/schema.php';
require_once KKCHAT_PATH . 'inc/rest.php';
if (is_admin()) {
    require_once KKCHAT_PATH . 'inc/admin-pages.php';
}
require_once KKCHAT_PATH . 'inc/shortcode.php';

// Hook plugin lifecycle to schema module
register_activation_hook(__FILE__, 'kkchat_activate');
register_deactivation_hook(__FILE__, 'kkchat_deactivate');
