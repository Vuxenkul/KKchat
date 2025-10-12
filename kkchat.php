<?php
/**
 * Plugin Name: KKChat
 * Description: Real-time chat experience powered by a standalone WebSocket server.
 * Version: 2.0.0
 * Author: KK
 * Text Domain: kkchat
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KKCHAT_FILE', __FILE__);
define('KKCHAT_DIR', plugin_dir_path(__FILE__));
define('KKCHAT_URL', plugin_dir_url(__FILE__));

autoload_kkchat();

register_activation_hook(__FILE__, [\KKChat\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\KKChat\Plugin::class, 'deactivate']);

\KKChat\Plugin::instance();

function autoload_kkchat(): void
{
    spl_autoload_register(function (string $class): void {
        if (strpos($class, 'KKChat\\') !== 0) {
            return;
        }

        $path = KKCHAT_DIR . 'includes/' . strtolower(str_replace('KKChat\\', '', $class)) . '.php';
        $path = str_replace('\\', '/', $path);

        if (file_exists($path)) {
            require_once $path;
        }
    });
}
