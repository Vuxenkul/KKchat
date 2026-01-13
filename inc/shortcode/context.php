<?php
if (!defined('ABSPATH')) exit;

function kkchat_shortcode_context() {
  $ns = esc_js( wp_make_link_relative( rest_url('kkchat/v1') ) );
  $session_csrf = $_SESSION['kkchat_csrf'] ?? '';
  $csrf = esc_js($session_csrf);

  $me_logged = isset($_SESSION['kkchat_user_id'], $_SESSION['kkchat_user_name']);
  if ($me_logged && empty($_SESSION['kkchat_seen_at_public'])) {
    $_SESSION['kkchat_seen_at_public'] = time();
  }

  $me_id = $me_logged ? (int) $_SESSION['kkchat_user_id'] : 0;
  $me_nm = $me_logged ? (string) $_SESSION['kkchat_user_name'] : '';

  $wp_user = wp_get_current_user();
  $wp_logged = $wp_user && !empty($wp_user->ID);
  $wp_username = $wp_logged ? $wp_user->user_login : '';
  if ($wp_logged) {
    $_SESSION['kkchat_wp_username'] = $wp_username;
  }

  $is_admin = !empty($_SESSION['kkchat_is_admin']) && kkchat_is_admin();

  $admin_links = [];
  if ($is_admin) {
    $admin_links = [
      ['text' => 'Rum',           'url' => admin_url('admin.php?page=kkchat_rooms')],
      ['text' => 'Banderoller',   'url' => admin_url('admin.php?page=kkchat_banners')],
      ['text' => 'Moderering',    'url' => admin_url('admin.php?page=kkchat_moderation')],
      ['text' => 'Ord',           'url' => admin_url('admin.php?page=kkchat_words')],
      ['text' => 'Loggar',        'url' => admin_url('admin.php?page=kkchat_admin_logs')],
      ['text' => 'Bildmoderering','url' => admin_url('admin.php?page=kkchat_media')],
      ['text' => 'Rapporter',     'url' => admin_url('admin.php?page=kkchat_reports')],
      ['text' => 'Inställningar', 'url' => admin_url('admin.php?page=kkchat_settings')],
    ];
  }

  $poll_hidden_threshold = max(0, (int) get_option('kkchat_poll_hidden_threshold', 90));
  $poll_hidden_delay     = max(0, (int) get_option('kkchat_poll_hidden_delay', 30));
  $poll_hot_interval     = max(1, (int) get_option('kkchat_poll_hot_interval', 4));
  $poll_medium_interval  = max($poll_hot_interval, (int) get_option('kkchat_poll_medium_interval', 8));
  $poll_slow_interval    = max($poll_medium_interval, (int) get_option('kkchat_poll_slow_interval', 16));
  $poll_medium_after     = max(0, (int) get_option('kkchat_poll_medium_after', 3));
  $poll_slow_after       = max($poll_medium_after, (int) get_option('kkchat_poll_slow_after', 5));
  $poll_extra_2g         = max(0, (int) get_option('kkchat_poll_extra_2g', 20));
  $poll_extra_3g         = max(0, (int) get_option('kkchat_poll_extra_3g', 10));
  $first_load_limit      = max(1, min(200, (int) get_option('kkchat_first_load_limit', 20)));
  $first_load_exclude_banners = !empty(get_option('kkchat_first_load_exclude_banners', 1));

  $poll_settings = [
    'hiddenThresholdMs' => $poll_hidden_threshold * 1000,
    'hiddenDelayMs'     => $poll_hidden_delay * 1000,
    'hotIntervalMs'     => $poll_hot_interval * 1000,
    'mediumIntervalMs'  => $poll_medium_interval * 1000,
    'slowIntervalMs'    => $poll_slow_interval * 1000,
    'mediumAfterMs'     => $poll_medium_after * 60 * 1000,
    'slowAfterMs'       => $poll_slow_after * 60 * 1000,
    'extra2gMs'         => $poll_extra_2g * 1000,
    'extra3gMs'         => $poll_extra_3g * 1000,
  ];

  $rest_nonce = esc_js( wp_create_nonce('wp_rest') );
  $open_dm = isset($_GET['dm']) ? (int) $_GET['dm'] : 'null';

  $plugin_root_url = KKCHAT_URL;
  $audio         = esc_url($plugin_root_url . 'assets/notification.mp3');
  $mention_audio = esc_url($plugin_root_url . 'assets/mention.mp3');
  $report_audio  = esc_url($plugin_root_url . 'assets/report.mp3');
  $gender_icon_base = esc_url($plugin_root_url . 'assets/genders/');

  return [
    'ns'              => $ns,
    'csrf'            => $csrf,
    'session_csrf'    => $session_csrf,
    'me_logged'       => $me_logged,
    'me_id'           => $me_id,
    'me_nm'           => $me_nm,
    'wp_logged'       => $wp_logged,
    'wp_username'     => $wp_username,
    'is_admin'        => $is_admin,
    'admin_links'     => $admin_links,
    'poll_settings'   => $poll_settings,
    'first_load_limit'=> $first_load_limit,
    'first_load_exclude_banners' => $first_load_exclude_banners,
    'rest_nonce'      => $rest_nonce,
    'open_dm'         => $open_dm,
    'audio'           => $audio,
    'mention_audio'   => $mention_audio,
    'report_audio'    => $report_audio,
    'gender_icon_base'=> $gender_icon_base,
  ];
}
