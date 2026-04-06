<?php
if (!defined('ABSPATH')) exit;

  /* =========================================================
   *                     Image upload
   * ========================================================= */

  register_rest_route($ns, '/upload', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_assert_not_blocked_or_fail(); kkchat_check_csrf_or_fail($req);
      nocache_headers();

      // Simple rate limit to deter abuse (default: 3s gap)
      $gap  = (int) apply_filters('kkchat_upload_min_gap', 3);
      $last = (int) ($_SESSION['kk_last_upload_at'] ?? 0);
      if ($gap > 0 && time() - $last < $gap) {
        kkchat_json(['ok'=>false,'err'=>'too_fast'], 429);
      }

      if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        kkchat_json(['ok'=>false,'err'=>'no_file'], 400);
      }
      $file = $_FILES['file'];

      // Size limit (5 MB default, filterable)
      $max_bytes = (int)apply_filters('kkchat_upload_max_bytes', 5 * 1024 * 1024);
      if (!empty($file['size']) && $file['size'] > $max_bytes) {
        kkchat_json(['ok'=>false,'err'=>'too_large','max'=>$max_bytes], 413);
      }

      // Allow-list mimes (filterable)
      $mimes = (array)apply_filters('kkchat_allowed_image_mimes', [
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'gif'      => 'image/gif',
        'webp'     => 'image/webp',
      ]);

      // Force uploads into /uploads/kkchat/YYYY/MM
      require_once ABSPATH . 'wp-admin/includes/file.php';
      $subroot = (string)apply_filters('kkchat_upload_subdir', '/kkchat'); // keep leading slash

      $upload_dir_filter = function($dirs) use ($subroot) {
        $sub            = trailingslashit($subroot) . ltrim((string)$dirs['subdir'], '/'); // /kkchat/2025/09
        $dirs['path']   = trailingslashit((string)$dirs['basedir']) . ltrim($sub, '/');
        $dirs['url']    = trailingslashit((string)$dirs['baseurl']) . ltrim($sub, '/');
        $dirs['subdir'] = $sub;
        return $dirs;
      };

      add_filter('upload_dir', $upload_dir_filter);
      try {
        $overrides = [
          'test_form' => false,
          'mimes'     => $mimes,
          'unique_filename_callback' => null,
        ];
        $moved = wp_handle_upload($file, $overrides);
      } finally {
        remove_filter('upload_dir', $upload_dir_filter);
      }

      if (!is_array($moved) || empty($moved['url']) || empty($moved['file'])) {
        $err = (is_array($moved) && !empty($moved['error'])) ? $moved['error'] : 'upload_failed';
        kkchat_json(['ok'=>false,'err'=>$err], 400);
      }

      // Double-check it's an image
      $type = (string)($moved['type'] ?? '');
      if (strpos($type, 'image/') !== 0) {
        if (file_exists($moved['file'])) @unlink($moved['file']);
        kkchat_json(['ok'=>false,'err'=>'bad_type'], 400);
      }

      // Ensure file is truly under /uploads/kkchat/
      $up       = wp_upload_dir();
      $basedir  = rtrim((string)$up['basedir'], DIRECTORY_SEPARATOR);
      $baseurl  = rtrim((string)$up['baseurl'], '/');
      $allow_fs = wp_normalize_path($basedir . rtrim($subroot, '/') . '/');
      $real     = wp_normalize_path((string)realpath($moved['file']));
      if ($real === '' || strpos($real, $allow_fs) !== 0) {
        if (file_exists($moved['file'])) @unlink($moved['file']);
        kkchat_json(['ok'=>false,'err'=>'bad_image_scope'], 400);
      }

      // Verify it parses as image (guards against spoofed content)
      if (!@getimagesize($moved['file'])) {
        @unlink($moved['file']);
        kkchat_json(['ok'=>false,'err'=>'bad_image'], 400);
      }

      // Optional: strip EXIF/metadata (off by default to preserve quality)
      $strip = (bool)apply_filters('kkchat_upload_strip_exif', false);
      if ($strip) {
        $editor = wp_get_image_editor($moved['file']);
        if (!is_wp_error($editor)) {
          // Re-save in place; this typically strips metadata
          $saved = $editor->save($moved['file']);
          if (!is_wp_error($saved)) {
            clearstatcache(true, $moved['file']);
          }
        }
      }

      $_SESSION['kk_last_upload_at'] = time();
        kkchat_close_session_if_open(); 
      kkchat_json(['ok'=>true,'url'=>$moved['url']]);
    },
    'permission_callback' => '__return_true',
  ]);


