<?php
// Basic bootstrap for running lightweight unit tests without WordPress.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

// Simple in-memory option store used by helper stubs.
$GLOBALS['kkchat_test_options'] = [];

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) {
        // no-op for tests
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return rtrim(dirname($file), '/') . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'http://example.com/';
    }
}

if (!defined('KKCHAT_PATH')) {
    define('KKCHAT_PATH', __DIR__ . '/../');
}

if (!defined('KKCHAT_URL')) {
    define('KKCHAT_URL', 'http://example.com/kkchat/');
}

if (!function_exists('wp_register_style')) {
    function wp_register_style($handle, $src, $deps = [], $ver = null) {
        // no-op in tests
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['kkchat_test_options'][$name] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['kkchat_test_options'][$name] = $value;
        return true;
    }
}

require_once __DIR__ . '/../inc/core-helpers.php';

function kkchat_test_assert_true($condition, string $message = 'Expected condition to be truthy'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function kkchat_test_assert_same($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        $prefix = $message !== '' ? $message . ': ' : '';
        throw new RuntimeException($prefix . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}
