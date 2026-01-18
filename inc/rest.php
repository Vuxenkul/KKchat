<?php
if (!defined('ABSPATH')) exit;

/**
 * REST API (public + admin)
 *
 * Namespace: kkchat/v1
 */
add_action('rest_api_init', function () {
  $ns = 'kkchat/v1';
  $base = __DIR__ . '/rest';

  require_once $base . '/helpers.php';
  require_once $base . '/auth.php';
  require_once $base . '/upload.php';
  require_once $base . '/sync.php';
  require_once $base . '/users.php';
  require_once $base . '/ping.php';
  require_once $base . '/rooms.php';
  require_once $base . '/fetch.php';
  require_once $base . '/message.php';
  require_once $base . '/reads.php';
  require_once $base . '/reports.php';
  require_once $base . '/blocks.php';
  require_once $base . '/moderation.php';
  require_once $base . '/admin.php';
});
