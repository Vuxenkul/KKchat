<?php
if (!defined('ABSPATH')) exit;

/**
 * Adminmeny (Svenska etiketter)
 */
add_action('admin_menu', function () {
  $cap = 'manage_options';

  // Top-level "KKchat" (öppnar Rum)
  add_menu_page(
    'KKchat',             // sidtitel
    'KKchat',             // menytext
    $cap,
    'kkchat_rooms',       // top-level slug (samma som Rum)
    'kkchat_admin_rooms_page',
    'dashicons-format-chat',
    60
  );

  // Undermenyer
  add_submenu_page('kkchat_rooms', 'Rum',          'Rum',          $cap, 'kkchat_rooms',        'kkchat_admin_rooms_page');
  add_submenu_page('kkchat_rooms', 'Banderoller',  'Banderoller',  $cap, 'kkchat_banners',      'kkchat_admin_banners_page');
  add_submenu_page('kkchat_rooms', 'Moderering',   'Moderering',   $cap, 'kkchat_moderation',   'kkchat_admin_moderation_page');
  add_submenu_page('kkchat_rooms', 'Ord',          'Ord',          $cap, 'kkchat_words',        'kkchat_admin_words_page');
  add_submenu_page('kkchat_rooms', 'Loggar',       'Loggar',       $cap, 'kkchat_admin_logs',   'kkchat_admin_logs_page');
  add_submenu_page('kkchat_rooms', 'Bildmoderering', 'Bildmoderering', $cap, 'kkchat_media', 'kkchat_admin_media_page');
  add_submenu_page('kkchat_rooms', 'Rapporter',    'Rapporter',    $cap, 'kkchat_reports',      'kkchat_admin_reports_page');
  add_submenu_page('kkchat_rooms', 'Inställningar','Inställningar',$cap, 'kkchat_settings',     'kkchat_admin_settings_page');
});
