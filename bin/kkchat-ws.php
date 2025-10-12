#!/usr/bin/env php
<?php
/**
 * KKChat WebSocket server.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run via the CLI." . PHP_EOL);
    exit(1);
}

$root = locate_wordpress_root(__DIR__);
if (!$root) {
    fwrite(STDERR, "Unable to locate wp-load.php. Run this script from within a WordPress installation." . PHP_EOL);
    exit(1);
}

require_once $root . '/wp-load.php';

if (!class_exists('KKChat\\WebSocketServer')) {
    require_once KKCHAT_DIR . 'includes/websocketserver.php';
}

$port = (int) get_option('kkchat_ws_port', 8090);

$server = new KKChat\WebSocketServer($port);
$server->run();

function locate_wordpress_root(string $startDir): ?string
{
    $dir = $startDir;
    for ($i = 0; $i < 10; $i++) {
        $candidate = $dir . '/wp-load.php';
        if (file_exists($candidate)) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return null;
}
