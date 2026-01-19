<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Room lookup and CSRF helpers.
 */
function kkchat_get_room(string $slug) {
    global $wpdb;
    $t    = kkchat_tables();
    $slug = kkchat_sanitize_room_slug($slug);
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['rooms']} WHERE slug=%s", $slug), ARRAY_A);
}

function kkchat_can_access_room(string $slug): bool {
    $r = kkchat_get_room($slug);
    if (!$r) {
        return false;
    }
    if (!empty($r['member_only']) && kkchat_is_guest()) {
        return false;
    }
    return true;
}

function kkchat_check_csrf_or_fail(WP_REST_Request $req) {
    $given       = (string) $req->get_param('csrf_token');
    $ok_session  = ($given !== '') && isset($_SESSION['kkchat_csrf']) && hash_equals($_SESSION['kkchat_csrf'], $given);
    $wp_hdr      = $req->get_header('X-WP-Nonce');
    $ok_wp_nonce = $wp_hdr && wp_verify_nonce($wp_hdr, 'wp_rest');
    if (!($ok_session || $ok_wp_nonce)) {
        kkchat_json(['ok' => false, 'err' => 'CSRF'], 403);
    }
}
