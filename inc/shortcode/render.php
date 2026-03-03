<?php
if (!defined('ABSPATH')) exit;

function kkchat_render_shortcode(array $context) {
  wp_enqueue_style('kkchat');

  ob_start();
  extract($context, EXTR_SKIP);
  include __DIR__ . '/templates/wrapper.php';
  return ob_get_clean();
}
