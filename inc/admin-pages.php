<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/admin/ipban-handler.php';
require_once __DIR__ . '/admin/media-page.php';
require_once __DIR__ . '/admin/settings-page.php';
require_once __DIR__ . '/admin/rooms-page.php';
require_once __DIR__ . '/admin/banners-page.php';
require_once __DIR__ . '/admin/moderation-page.php';
require_once __DIR__ . '/admin/words-page.php';
require_once __DIR__ . '/admin/reports-page.php';
require_once __DIR__ . '/admin/logs-page.php';
require_once __DIR__ . '/admin/menu.php';

add_action('admin_enqueue_scripts', function () {
  if (!isset($_GET['page']) || $_GET['page'] !== 'kkchat_settings') {
    return;
  }

  wp_enqueue_style(
    'kkchat-admin-dark',
    KKCHAT_URL . 'assets/css/admin-dark.css',
    [],
    '1.0.0'
  );
});
