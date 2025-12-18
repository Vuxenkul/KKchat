<?php
if (!defined('ABSPATH')) exit;

function kkchat_render_shortcode(array $context) {
  wp_enqueue_style('kkchat');
  wp_enqueue_script('kkchat');
  wp_localize_script('kkchat', 'kkchatConfig', [
    'api'            => $context['ns'] ?? '',
    'csrf'           => $context['csrf'] ?? '',
    'restNonce'      => $context['rest_nonce'] ?? '',
    'meId'           => isset($context['me_id']) ? (int) $context['me_id'] : 0,
    'meName'         => $context['me_nm'] ?? '',
    'openDm'         => $context['open_dm'] ?? null,
    'isAdmin'        => !empty($context['is_admin']),
    'adminLinks'     => $context['admin_links'] ?? [],
    'genderIconBase' => $context['gender_icon_base'] ?? '',
    'pollSettings'   => $context['poll_settings'] ?? [],
  ]);

  ob_start();
  extract($context, EXTR_SKIP);
  include __DIR__ . '/templates/wrapper.php';
  return ob_get_clean();
}
