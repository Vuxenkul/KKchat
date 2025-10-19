<?php
/**
 * CLI helper to invalidate all KKchat sessions and log everyone out.
 *
 * Usage: php logout-everyone.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$pluginDir = __DIR__;
$wpRoot    = dirname($pluginDir, 3);
$wpLoad    = $wpRoot . '/wp-load.php';

if (!file_exists($wpLoad)) {
    fwrite(STDERR, "Unable to locate wp-load.php (looked in {$wpLoad}).\n");
    exit(1);
}

require_once $wpLoad;

if (!function_exists('kkchat_logout_everyone_now')) {
    fwrite(STDERR, "KKchat plugin functions are not available. Is the plugin active?\n");
    exit(1);
}

$info = kkchat_logout_everyone_now();

fwrite(STDOUT, "All chat sessions invalidated.\n");
fwrite(STDOUT, sprintf("New session epoch: %d\n", (int) ($info['epoch'] ?? 0)));
fwrite(STDOUT, sprintf("Presence rows removed: %d\n", (int) ($info['removed'] ?? 0)));
