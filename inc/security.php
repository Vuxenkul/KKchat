<?php
if (!defined('ABSPATH')) exit;

/* ------------------------------
 * CSRF
 * ------------------------------ */
function kkchat_check_csrf_or_fail(WP_REST_Request $req) {
  $given = (string) $req->get_param('csrf_token');
  $ok_session = ($given !== '') && isset($_SESSION['kkchat_csrf']) && hash_equals($_SESSION['kkchat_csrf'], $given);
  $wp_hdr = $req->get_header('X-WP-Nonce');
  $ok_wp  = $wp_hdr && wp_verify_nonce($wp_hdr, 'wp_rest');
  if (!($ok_session || $ok_wp)) kkchat_json(['ok'=>false, 'err'=>'CSRF'], 403);
}
