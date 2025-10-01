<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    $ns = kkchat_rest_namespace();

register_rest_route($ns, '/login', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_check_csrf_or_fail($req);

      $gender  = trim((string)$req->get_param('login_gender'));
      $allowed = ['Man','Woman','Couple','Trans (MTF)','Trans (FTM)','Non-binary/other'];
      if (!in_array($gender, $allowed, true)) {
        kkchat_json(['ok'=>false,'err'=>'Välj en giltig kategori']);
      }

      $via_wp = ((string)$req->get_param('via_wp') === '1');

      $nick = '';
      $wp_username = '';

      if ($via_wp) {
        // Only allow via_wp for *authenticated* WP users.
        // Trust wp_get_current_user() only — never POST/session.
        if (!is_user_logged_in()) {
          kkchat_json(['ok'=>false,'err'=>'Du är inte inloggad i WordPress'], 403);
        }
        $wp_user = wp_get_current_user();
        if (!$wp_user || empty($wp_user->ID) || empty($wp_user->user_login)) {
          kkchat_json(['ok'=>false,'err'=>'Kunde inte läsa WP-användarnamn'], 400);
        }
        $wp_username = (string)$wp_user->user_login;
        $nick = kkchat_sanitize_name_nosuffix($wp_username);
      } else {
        $nick = kkchat_sanitize_guest_nick((string)$req->get_param('login_nick'));
        if ($nick === '') kkchat_json(['ok'=>false,'err'=>'Välj ett giltigt smeknamn']);
      }

      // Check moderation blocks BEFORE inserting presence
      $ip = kkchat_client_ip();
      $block = kkchat_moderation_block_for(0, $nick, $wp_username, $ip);
      if ($block) {
        if ($block['type']==='ipban') kkchat_json(['ok'=>false,'err'=>'ip_banned','cause'=>$block['row']['cause']??''], 403);
        if ($block['type']==='kick')  kkchat_json(['ok'=>false,'err'=>'kicked','cause'=>$block['row']['cause']??'', 'until'=>$block['row']['expires_at']??null], 403);
      }

      global $wpdb; $t = kkchat_tables();
      $now = time();

      // Clean old presences
      $wpdb->query($wpdb->prepare("DELETE FROM {$t['users']} WHERE %d - last_seen > %d", $now, kkchat_user_ttl()));

      // Insert presence (wp_username is NULL for guests)
      $name_lc = mb_strtolower($nick, 'UTF-8');
      $ins = $wpdb->insert($t['users'], [
        'name'        => $nick,
        'name_lc'     => $name_lc,
        'gender'      => $gender,
        'last_seen'   => $now,
        'ip'          => $ip,
        'wp_username' => $via_wp ? $wp_username : null,
      ], ['%s','%s','%s','%d','%s','%s']);

      if (!$ins) {
        if ($via_wp) {
          // For WP users, replace abandoned presence with same name_lc
          $wpdb->delete($t['users'], ['name_lc' => $name_lc], ['%s']);
          $ins2 = $wpdb->insert($t['users'], [
            'name'        => $nick,
            'name_lc'     => $name_lc,
            'gender'      => $gender,
            'last_seen'   => $now,
            'ip'          => $ip,
            'wp_username' => $wp_username,
          ], ['%s','%s','%s','%d','%s','%s']);
          if (!$ins2) kkchat_json(['ok'=>false,'err'=>'Namnet är upptaget']);
        } else {
          kkchat_json(['ok'=>false,'err'=>'Namnet är upptaget']);
        }
      }

      // Establish chat session
      $_SESSION['kkchat_user_id']        = (int)$wpdb->insert_id;
      $_SESSION['kkchat_user_name']      = $nick;
      $_SESSION['kkchat_gender']         = $gender;
      $_SESSION['kkchat_is_guest']       = $via_wp ? 0 : 1;
      $_SESSION['kkchat_seen_at_public'] = time();
      kkchat_touch_active_user();

      if ($via_wp) {
        $_SESSION['kkchat_wp_username'] = $wp_username;
      } else {
        unset($_SESSION['kkchat_wp_username']);
      }

      // Admin determined strictly by configured list vs real WP username
      $_SESSION['kkchat_is_admin'] = ($via_wp && kkchat_is_admin_username($wp_username)) ? 1 : 0;

      // Prevent session fixation after auth
      if (function_exists('session_regenerate_id')) @session_regenerate_id(true);

      kkchat_json(['ok'=>true, 'is_admin'=>!empty($_SESSION['kkchat_is_admin'])]);
    },
    'permission_callback' => '__return_true',
  ]);


  register_rest_route($ns, '/logout', [
    'methods'  => ['GET','POST'],
    'callback' => function () {
      global $wpdb; $t = kkchat_tables();
      if (!empty($_SESSION['kkchat_user_id'])) {
        $wpdb->delete($t['users'], ['id' => (int)$_SESSION['kkchat_user_id']], ['%d']);
      }
      // Keep only CSRF in session
      $_SESSION = array_intersect_key($_SESSION, ['kkchat_csrf'=>true]);
      if (function_exists('session_regenerate_id')) @session_regenerate_id(true);
      kkchat_close_session_if_open(); // unlock after session mutation
      kkchat_json(['ok' => true]);
    },
    'permission_callback' => '__return_true',
  ]);

  
});
