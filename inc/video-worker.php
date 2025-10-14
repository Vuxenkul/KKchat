<?php
if (!defined('ABSPATH')) exit;

function kkchat_video_upload_config(): array {
  $defaults = [
    'bucket'         => defined('KKCHAT_VIDEO_BUCKET') ? constant('KKCHAT_VIDEO_BUCKET') : '',
    'region'         => defined('KKCHAT_VIDEO_REGION') ? constant('KKCHAT_VIDEO_REGION') : '',
    'access_key'     => defined('KKCHAT_VIDEO_ACCESS_KEY') ? constant('KKCHAT_VIDEO_ACCESS_KEY') : '',
    'secret_key'     => defined('KKCHAT_VIDEO_SECRET_KEY') ? constant('KKCHAT_VIDEO_SECRET_KEY') : '',
    'prefix'         => defined('KKCHAT_VIDEO_PREFIX') ? constant('KKCHAT_VIDEO_PREFIX') : 'kkchat/videos',
    'cdn_base_url'   => defined('KKCHAT_VIDEO_CDN_BASE_URL') ? constant('KKCHAT_VIDEO_CDN_BASE_URL') : '',
    'max_bytes'      => defined('KKCHAT_VIDEO_MAX_BYTES') ? (int) constant('KKCHAT_VIDEO_MAX_BYTES') : 200 * 1024 * 1024,
    'allowed_mime'   => ['video/mp4', 'video/webm', 'video/quicktime'],
    'upload_expires' => defined('KKCHAT_VIDEO_UPLOAD_EXPIRES') ? (int) constant('KKCHAT_VIDEO_UPLOAD_EXPIRES') : 3600,
    'presign_ttl'    => defined('KKCHAT_VIDEO_PRESIGN_TTL') ? (int) constant('KKCHAT_VIDEO_PRESIGN_TTL') : 900,
    'webhook_secret' => defined('KKCHAT_VIDEO_WEBHOOK_SECRET') ? constant('KKCHAT_VIDEO_WEBHOOK_SECRET') : '',
    'ffprobe'        => defined('KKCHAT_VIDEO_FFPROBE_PATH') ? constant('KKCHAT_VIDEO_FFPROBE_PATH') : 'ffprobe',
    'ffmpeg'         => defined('KKCHAT_VIDEO_FFMPEG_PATH') ? constant('KKCHAT_VIDEO_FFMPEG_PATH') : 'ffmpeg',
  ];

  return apply_filters('kkchat_video_upload_config', $defaults);
}

function kkchat_video_is_configured(?array $cfg = null): bool {
  $cfg = $cfg ?? kkchat_video_upload_config();
  return !empty($cfg['bucket']) && !empty($cfg['region']) && !empty($cfg['access_key']) && !empty($cfg['secret_key']);
}

function kkchat_video_allowed_mimes(?array $cfg = null): array {
  $cfg = $cfg ?? kkchat_video_upload_config();
  $allowed = $cfg['allowed_mime'] ?? [];
  if (!is_array($allowed)) {
    $allowed = [];
  }
  $allowed = array_values(array_filter(array_map('strval', $allowed)));
  if (!$allowed) {
    $allowed = ['video/mp4', 'video/webm', 'video/quicktime'];
  }
  return array_unique(array_map('strtolower', $allowed));
}

function kkchat_video_extension_for_mime(string $mime): string {
  $mime = strtolower(trim($mime));
  $map = apply_filters('kkchat_video_mime_extensions', [
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',
    'video/ogg'       => 'ogv',
  ]);
  $ext = $map[$mime] ?? '';
  if (!$ext) {
    if (strpos($mime, 'mp4') !== false) {
      $ext = 'mp4';
    } elseif (strpos($mime, 'webm') !== false) {
      $ext = 'webm';
    } elseif (strpos($mime, 'quicktime') !== false) {
      $ext = 'mov';
    }
  }
  return $ext ? ('.' . preg_replace('/[^a-z0-9]/', '', strtolower($ext))) : '';
}

function kkchat_video_bucket_host(array $cfg): string {
  $bucket = trim((string) ($cfg['bucket'] ?? ''));
  $region = trim((string) ($cfg['region'] ?? ''));
  if ($bucket === '') {
    return '';
  }
  if ($region === '' || strtolower($region) === 'us-east-1') {
    return sprintf('%s.s3.amazonaws.com', $bucket);
  }
  return sprintf('%s.s3.%s.amazonaws.com', $bucket, $region);
}

function kkchat_rawurlencode_path(string $path): string {
  $parts = array_map('rawurlencode', explode('/', $path));
  return implode('/', $parts);
}

function kkchat_video_public_url(string $key, ?array $cfg = null): string {
  $cfg  = $cfg ?? kkchat_video_upload_config();
  $path = ltrim(str_replace('\\', '/', $key), '/');

  if (!empty($cfg['cdn_base_url'])) {
    $base = rtrim((string) $cfg['cdn_base_url'], '/');
    return $base . '/' . kkchat_rawurlencode_path($path);
  }

  $host = kkchat_video_bucket_host($cfg);
  if ($host === '') {
    return '';
  }

  return 'https://' . $host . '/' . kkchat_rawurlencode_path($path);
}

function kkchat_video_thumbnail_key(string $key): string {
  $normalized = trim(str_replace('\\', '/', $key), '/');
  $info = pathinfo($normalized);
  $dir = isset($info['dirname']) && $info['dirname'] !== '.' ? $info['dirname'] : '';
  $filename = $info['filename'] ?? ($info['basename'] ?? 'thumb');
  $prefix = $dir !== '' ? rtrim($dir, '/') . '/' : '';
  return $prefix . 'thumb-' . $filename . '.jpg';
}

function kkchat_video_presign(array $cfg, string $method, string $key, array $opts = []) {
  if (!kkchat_video_is_configured($cfg)) {
    return new WP_Error('video_config_missing', 'Video storage is not fully configured');
  }

  $access = (string) $cfg['access_key'];
  $secret = (string) $cfg['secret_key'];
  $region = (string) $cfg['region'];
  $host   = kkchat_video_bucket_host($cfg);
  if ($host === '') {
    return new WP_Error('video_host_missing', 'Could not determine storage host');
  }

  $expires = isset($opts['expires']) ? (int) $opts['expires'] : (int) ($cfg['presign_ttl'] ?? 900);
  if ($expires <= 0) { $expires = 900; }
  if ($expires > 604800) { $expires = 604800; }

  $now = isset($opts['now']) ? (int) $opts['now'] : time();
  $amzDate = gmdate('Ymd\THis\Z', $now);
  $shortDate = gmdate('Ymd', $now);

  $service = 's3';
  $scope = $shortDate . '/' . $region . '/' . $service . '/aws4_request';

  $path = '/' . kkchat_rawurlencode_path(ltrim($key, '/'));

  $query = [
    'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
    'X-Amz-Credential'    => $access . '/' . $scope,
    'X-Amz-Date'          => $amzDate,
    'X-Amz-Expires'       => (string) $expires,
    'X-Amz-SignedHeaders' => 'host',
    'X-Amz-Content-Sha256'=> 'UNSIGNED-PAYLOAD',
  ];
  if (!empty($opts['query']) && is_array($opts['query'])) {
    foreach ($opts['query'] as $k => $v) {
      $query[$k] = $v;
    }
  }
  ksort($query);
  $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

  $canonicalHeaders = 'host:' . strtolower($host) . "\n";
  $signedHeaders = 'host';
  $payloadHash = 'UNSIGNED-PAYLOAD';

  $canonicalRequest = strtoupper($method) . "\n" . $path . "\n" . $canonicalQuery . "\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
  $hashedRequest = hash('sha256', $canonicalRequest);

  $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n{$hashedRequest}";

  $kDate    = hash_hmac('sha256', $shortDate, 'AWS4' . $secret, true);
  $kRegion  = hash_hmac('sha256', $region, $kDate, true);
  $kService = hash_hmac('sha256', $service, $kRegion, true);
  $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
  $signature = hash_hmac('sha256', $stringToSign, $kSigning);

  $query['X-Amz-Signature'] = $signature;
  ksort($query);
  $finalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

  $url = 'https://' . $host . $path . '?' . $finalQuery;

  $headers = $opts['headers'] ?? [];
  $headers['x-amz-content-sha256'] = 'UNSIGNED-PAYLOAD';

  return [
    'url'     => $url,
    'headers' => $headers,
  ];
}

function kkchat_video_fail_asset(int $asset_id, string $code, string $message): WP_Error {
  global $wpdb; $t = kkchat_tables();
  $code = substr(preg_replace('/[^a-z0-9_\-]/i', '', strtolower($code)), 0, 64) ?: 'failed';
  $wpdb->update(
    $t['videos'],
    [
      'status'          => 'failed',
      'failure_code'    => $code,
      'failure_message' => $message,
      'updated_at'      => time(),
      'processed_at'    => time(),
    ],
    ['id' => $asset_id],
    ['%s','%s','%s','%d','%d'],
    ['%d']
  );

  return new WP_Error($code, $message);
}

function kkchat_video_probe_duration(string $file, ?array $cfg = null): ?float {
  $cfg = $cfg ?? kkchat_video_upload_config();
  $bin = trim((string) ($cfg['ffprobe'] ?? 'ffprobe'));
  if ($bin === '') {
    return null;
  }

  $cmd = escapeshellcmd($bin) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($file) . ' 2>&1';
  $out = @shell_exec($cmd);
  if ($out === null) {
    return null;
  }
  $val = trim($out);
  if ($val === '') {
    return null;
  }
  $duration = (float) $val;
  return ($duration > 0) ? $duration : null;
}

function kkchat_video_generate_thumbnail(string $video_path, ?array $cfg = null) {
  $cfg = $cfg ?? kkchat_video_upload_config();
  $bin = trim((string) ($cfg['ffmpeg'] ?? 'ffmpeg'));
  if ($bin === '') {
    return new WP_Error('ffmpeg_missing', 'ffmpeg path not configured');
  }

  $tmp = wp_tempnam('kkchat-thumb');
  if (!$tmp) {
    return new WP_Error('tmp_unavailable', 'Failed to allocate thumbnail temp file');
  }

  $seek = apply_filters('kkchat_video_thumbnail_seek', '00:00:01');
  $cmd = escapeshellcmd($bin)
    . ' -hide_banner -loglevel error -y -ss ' . escapeshellarg($seek)
    . ' -i ' . escapeshellarg($video_path)
    . ' -frames:v 1 -vf "scale=iw*min(1\,480/iw):ih*min(1\,480/ih)" '
    . escapeshellarg($tmp)
    . ' 2>&1';

  $out = @shell_exec($cmd);
  if (!file_exists($tmp) || filesize($tmp) <= 0) {
    @unlink($tmp);
    $msg = trim((string) $out) ?: 'Failed to render thumbnail';
    return new WP_Error('thumb_failed', $msg);
  }

  return $tmp;
}

function kkchat_video_upload_thumbnail(string $thumb_path, string $thumb_key, array $cfg) {
  $signed = kkchat_video_presign($cfg, 'PUT', $thumb_key, [
    'headers' => ['Content-Type' => 'image/jpeg'],
    'expires' => 600,
  ]);
  if (is_wp_error($signed)) {
    return $signed;
  }

  $body = file_get_contents($thumb_path);
  if ($body === false) {
    return new WP_Error('thumb_read_failed', 'Could not read generated thumbnail');
  }

  $resp = wp_remote_request($signed['url'], [
    'method'  => 'PUT',
    'headers' => $signed['headers'] + ['Content-Type' => 'image/jpeg'],
    'timeout' => 60,
    'body'    => $body,
  ]);
  if (is_wp_error($resp)) {
    return $resp;
  }
  $code = (int) wp_remote_retrieve_response_code($resp);
  if ($code < 200 || $code >= 300) {
    return new WP_Error('thumb_upload_failed', 'Thumbnail upload failed', ['status' => $code]);
  }

  return true;
}

function kkchat_video_asset_payload(array $row, ?array $cfg = null): array {
  $cfg = $cfg ?? kkchat_video_upload_config();
  $payload = [
    'id'        => (int) ($row['id'] ?? 0),
    'status'    => (string) ($row['status'] ?? ''),
    'url'       => (string) ($row['public_url'] ?? ''),
    'thumbnail' => (string) ($row['thumbnail_url'] ?? ''),
    'duration'  => isset($row['duration_seconds']) ? (float) $row['duration_seconds'] : null,
    'size'      => isset($row['object_size']) ? (int) $row['object_size'] : null,
    'mime'      => (string) ($row['mime_type'] ?? ''),
    'width'     => isset($row['width_pixels']) ? (int) $row['width_pixels'] : null,
    'height'    => isset($row['height_pixels']) ? (int) $row['height_pixels'] : null,
    'thumbnail_size' => isset($row['thumbnail_size']) ? (int) $row['thumbnail_size'] : null,
    'thumbnail_mime' => (string) ($row['thumbnail_mime'] ?? ''),
    'failure'   => (string) ($row['failure_code'] ?? ''),
    'failure_message' => (string) ($row['failure_message'] ?? ''),
    'key'       => (string) ($row['object_key'] ?? ''),
    'message_id'=> isset($row['message_id']) ? (int) $row['message_id'] : null,
  ];

  if ($payload['url'] === '' && !empty($row['object_key'])) {
    $payload['url'] = kkchat_video_public_url((string) $row['object_key'], $cfg);
  }
  if ($payload['thumbnail'] === '' && !empty($row['thumbnail_key'])) {
    $payload['thumbnail'] = kkchat_video_public_url((string) $row['thumbnail_key'], $cfg);
  }

  return $payload;
}

function kkchat_video_worker_handle_event(array $record) {
  global $wpdb; $t = kkchat_tables();
  $cfg = kkchat_video_upload_config();
  if (!kkchat_video_is_configured($cfg)) {
    return new WP_Error('video_disabled', 'Video uploads are not configured');
  }

  $bucket = (string) ($record['bucket'] ?? ($record['s3']['bucket']['name'] ?? ''));
  $key    = (string) ($record['key'] ?? ($record['s3']['object']['key'] ?? ''));
  $size   = isset($record['size']) ? (int) $record['size'] : (int) ($record['s3']['object']['size'] ?? 0);

  if ($bucket && $bucket !== $cfg['bucket']) {
    return new WP_Error('video_bucket_mismatch', 'Ignoring event for foreign bucket');
  }

  $key = rawurldecode($key);
  $key = ltrim($key, '/');
  if ($key === '') {
    return new WP_Error('video_key_missing', 'Storage event missing object key');
  }

  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['videos']} WHERE object_key = %s", $key), ARRAY_A);
  if (!$row) {
    return new WP_Error('video_asset_missing', 'No matching video asset for object', ['key' => $key]);
  }

  if (isset($row['status']) && (string) $row['status'] === 'ready') {
    // Already finalized by the client flow; nothing to do.
    return true;
  }

  $asset_id = (int) $row['id'];
  $now = time();
  $wpdb->update(
    $t['videos'],
    [
      'status'       => 'processing',
      'updated_at'   => $now,
      'failure_code' => null,
      'failure_message' => null,
    ],
    ['id' => $asset_id],
    ['%s','%d','%s','%s'],
    ['%d']
  );

  $head_signed = kkchat_video_presign($cfg, 'HEAD', $key, ['expires' => 300]);
  if (is_wp_error($head_signed)) {
    return kkchat_video_fail_asset($asset_id, $head_signed->get_error_code(), $head_signed->get_error_message());
  }

  $head = wp_remote_request($head_signed['url'], [
    'method'  => 'HEAD',
    'headers' => $head_signed['headers'],
    'timeout' => 30,
  ]);
  if (is_wp_error($head)) {
    return kkchat_video_fail_asset($asset_id, 'head_failed', $head->get_error_message());
  }
  $status = (int) wp_remote_retrieve_response_code($head);
  if ($status < 200 || $status >= 300) {
    return kkchat_video_fail_asset($asset_id, 'head_bad_status', 'Unexpected status from storage: ' . $status);
  }

  $contentType = (string) wp_remote_retrieve_header($head, 'content-type');
  $contentLength = (int) ($size > 0 ? $size : wp_remote_retrieve_header($head, 'content-length'));

  $allowed = kkchat_video_allowed_mimes($cfg);
  $normalizedMime = strtolower(trim(strtok($contentType, ';')));
  if ($normalizedMime && !in_array($normalizedMime, $allowed, true)) {
    return kkchat_video_fail_asset($asset_id, 'invalid_mime', 'Otillåten videotyp: ' . $normalizedMime);
  }

  $maxBytes = max(0, (int) ($cfg['max_bytes'] ?? 0));
  if ($maxBytes > 0 && $contentLength > $maxBytes) {
    return kkchat_video_fail_asset($asset_id, 'too_large', 'Videon är för stor (' . $contentLength . ' bytes)');
  }

  if (!empty($row['expected_bytes']) && $contentLength > 0) {
    $expected = (int) $row['expected_bytes'];
    $delta = abs($expected - $contentLength);
    $tolerance = max(1, (int) apply_filters('kkchat_video_size_tolerance', 512 * 1024));
    if ($delta > $tolerance) {
      return kkchat_video_fail_asset($asset_id, 'size_mismatch', 'Uppladdad storlek matchar inte förväntat värde');
    }
  }

  $tmp = wp_tempnam('kkchat-video');
  if (!$tmp) {
    return kkchat_video_fail_asset($asset_id, 'tmp_failed', 'Kunde inte skapa temporär fil');
  }

  $get_signed = kkchat_video_presign($cfg, 'GET', $key, ['expires' => 600]);
  if (is_wp_error($get_signed)) {
    @unlink($tmp);
    return kkchat_video_fail_asset($asset_id, $get_signed->get_error_code(), $get_signed->get_error_message());
  }

  $resp = wp_remote_get($get_signed['url'], [
    'timeout'  => 120,
    'stream'   => true,
    'filename' => $tmp,
    'headers'  => $get_signed['headers'],
  ]);
  if (is_wp_error($resp)) {
    @unlink($tmp);
    return kkchat_video_fail_asset($asset_id, 'download_failed', $resp->get_error_message());
  }
  $code = (int) wp_remote_retrieve_response_code($resp);
  if ($code < 200 || $code >= 300) {
    @unlink($tmp);
    return kkchat_video_fail_asset($asset_id, 'download_status', 'Nedladdning misslyckades (' . $code . ')');
  }

  $actualSize = @filesize($tmp);
  if ($actualSize > 0) {
    $contentLength = $actualSize;
  }

  $detectedMime = '';
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $detectedMime = (string) finfo_file($finfo, $tmp);
      finfo_close($finfo);
    }
  }
  if ($detectedMime) {
    $normalizedMime = strtolower(trim($detectedMime));
    if (!in_array($normalizedMime, $allowed, true)) {
      @unlink($tmp);
      return kkchat_video_fail_asset($asset_id, 'invalid_mime', 'Otillåten videotyp: ' . $normalizedMime);
    }
  }

  $duration = kkchat_video_probe_duration($tmp, $cfg);

  $thumbPath = null;
  $thumbKey = null;
  $thumbUrl = null;
  $thumbResult = kkchat_video_generate_thumbnail($tmp, $cfg);
  if (is_wp_error($thumbResult)) {
    error_log('[KKchat] Video thumbnail failed for asset #' . $asset_id . ': ' . $thumbResult->get_error_message());
  } else {
    $thumbPath = $thumbResult;
    $thumbKey  = kkchat_video_thumbnail_key($key);
    $uploadThumb = kkchat_video_upload_thumbnail($thumbPath, $thumbKey, $cfg);
    if (is_wp_error($uploadThumb)) {
      error_log('[KKchat] Thumbnail upload failed for asset #' . $asset_id . ': ' . $uploadThumb->get_error_message());
      $thumbKey = null;
    } else {
      $thumbUrl = kkchat_video_public_url($thumbKey, $cfg);
    }
  }

  if ($thumbPath && file_exists($thumbPath)) {
    @unlink($thumbPath);
  }
  if (file_exists($tmp)) {
    @unlink($tmp);
  }

  $payload = [];
  $formats = [];

  $payload['status']       = 'ready'; $formats[] = '%s';
  $payload['updated_at']   = time();  $formats[] = '%d';
  $payload['processed_at'] = time();  $formats[] = '%d';
  if ($contentLength > 0) {
    $payload['object_size'] = $contentLength; $formats[] = '%d';
  } else {
    $payload['object_size'] = null; $formats[] = '%s';
  }
  $payload['mime_type'] = $normalizedMime; $formats[] = '%s';
  $payload['duration_seconds'] = $duration !== null ? round($duration, 2) : null; $formats[] = '%s';
  $payload['thumbnail_key'] = $thumbKey; $formats[] = '%s';
  $payload['thumbnail_url'] = $thumbUrl; $formats[] = '%s';
  $payload['public_url']    = kkchat_video_public_url($key, $cfg); $formats[] = '%s';
  $payload['failure_code']  = null; $formats[] = '%s';
  $payload['failure_message'] = null; $formats[] = '%s';

  $wpdb->update($t['videos'], $payload, ['id' => $asset_id], $formats, ['%d']);

  return true;
}

function kkchat_video_normalize_record($record): array {
  if (is_array($record)) {
    return $record;
  }
  if (is_object($record)) {
    return (array) $record;
  }
  return [];
}
