<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/shortcode/context.php';
require_once __DIR__ . '/shortcode/render.php';

add_shortcode('kkchat', function () {
  $context = kkchat_shortcode_context();
  return kkchat_render_shortcode($context);
});
