<?php
if (!defined('ABSPATH')) exit;

  /* =========================================================
   *  NEW: Single batched sync endpoint
   *  Returns unread counts, presence, and new messages
   * ========================================================= */
register_rest_route($ns, '/sync', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) {
    kkchat_sync_metrics_bump('total_requests');
    $disableRetry = (int) apply_filters('kkchat_sync_disabled_retry', 120);
    if (kkchat_sync_is_disabled()) {
      $resp = new WP_REST_Response(['err' => 'sync_disabled'], 503);
      if ($disableRetry > 0) { $resp->header('Retry-After', (string) $disableRetry); }
      kkchat_sync_metrics_bump('disabled_hits');
      return $resp;
    }

    $breakerRetry = null;
    if (kkchat_sync_breaker_is_open($breakerRetry)) {
      $resp = new WP_REST_Response(['err' => 'sync_overloaded'], 503);
      if ($breakerRetry !== null && $breakerRetry > 0) {
        $resp->header('Retry-After', (string) $breakerRetry);
      }
      kkchat_sync_metrics_bump('breaker_denied');
      return $resp;
    }

    $ctx = kkchat_sync_build_context($req);

    $penalty = kkchat_sync_rate_guard((int) ($ctx['me'] ?? 0));
    if ($penalty > 0) {
      $resp = new WP_REST_Response(['err' => 'rate_limited'], 429);
      $resp->header('Retry-After', (string) $penalty);
      kkchat_sync_metrics_bump('rate_limited');
      return $resp;
    }

    $slotToken = kkchat_sync_acquire_slot();
    if ($slotToken === null) {
      $retryBusy = (int) apply_filters('kkchat_sync_busy_retry', 8);
      $resp = new WP_REST_Response(['err' => 'sync_busy'], 429);
      if ($retryBusy > 0) { $resp->header('Retry-After', (string) $retryBusy); }
      kkchat_sync_metrics_bump('concurrency_denied');
      return $resp;
    }

    $startedAt = microtime(true);
    try {
      $payload = kkchat_sync_build_payload($ctx);
    } catch (\Throwable $th) {
      kkchat_sync_breaker_note_failure();
      $cooldown = (int) apply_filters('kkchat_sync_failure_retry', 30, $th);
      $resp = new WP_REST_Response(['err' => 'sync_unavailable'], 503);
      if ($cooldown > 0) { $resp->header('Retry-After', (string) $cooldown); }
      if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('KKchat sync failure: ' . $th->getMessage());
      }
      kkchat_sync_metrics_note_duration((microtime(true) - $startedAt) * 1000);
      return $resp;
    } finally {
      kkchat_sync_release_slot($slotToken);
    }

    if (is_wp_error($payload)) {
      kkchat_sync_breaker_note_failure();
      $cooldown = (int) apply_filters('kkchat_sync_failure_retry', 30, $payload);
      $resp = new WP_REST_Response(['err' => 'sync_unavailable'], 503);
      if ($cooldown > 0) { $resp->header('Retry-After', (string) $cooldown); }
      kkchat_sync_metrics_note_duration((microtime(true) - $startedAt) * 1000);
      return $resp;
    }

    kkchat_sync_breaker_note_success();

    $since   = (int) ($ctx['since'] ?? -1);
    $cursor  = kkchat_sync_max_cursor($payload, $since);
    $hasChanges = ($since < 0) ? true : ($cursor > $since);

    $retryAfter = kkchat_sync_retry_after_hint($payload, $ctx, $hasChanges);
    $etag       = kkchat_sync_build_etag($ctx, $cursor);

    $headers = [
      'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
      'Pragma'        => 'no-cache',
    ];

    if ($retryAfter > 0) {
      $headers['Retry-After'] = (string) $retryAfter;
    }
    if ($etag !== '') {
      $headers['ETag'] = $etag;
    }

    $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) : '';

    if (!$hasChanges && $etag !== '' && $ifNoneMatch !== '' && $ifNoneMatch === $etag) {
      $resp = new WP_REST_Response(null, 304);
      foreach ($headers as $hk => $hv) { $resp->header($hk, $hv); }
      kkchat_sync_metrics_bump('total_success');
      kkchat_sync_metrics_note_duration((microtime(true) - $startedAt) * 1000);
      return $resp;
    }

    if (!$hasChanges) {
      $resp = new WP_REST_Response(null, 204);
      foreach ($headers as $hk => $hv) { $resp->header($hk, $hv); }
      kkchat_sync_metrics_bump('total_success');
      kkchat_sync_metrics_note_duration((microtime(true) - $startedAt) * 1000);
      return $resp;
    }

    $events = kkchat_sync_format_events($payload, $since < 0);

    $data = [
      'now'        => (int) ($payload['now'] ?? time()),
      'next'       => $cursor,
      'retryAfter' => $retryAfter,
      'events'     => $events,
    ];

    unset($payload['now']);
    foreach ($payload as $k => $v) {
      if (!array_key_exists($k, $data)) {
        $data[$k] = $v;
      }
    }

    $resp = new WP_REST_Response($data, 200);
    kkchat_sync_metrics_bump('total_success');
    kkchat_sync_metrics_note_duration((microtime(true) - $startedAt) * 1000);
    foreach ($headers as $hk => $hv) { $resp->header($hk, $hv); }
    return $resp;
  },
  'permission_callback' => '__return_true',
]);

  $legacy_gone = function () {
    $resp = new WP_REST_Response(['err' => 'gone'], 410);
    $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    return $resp;
  };

  register_rest_route($ns, '/poll', [
    'methods'  => 'GET',
    'callback' => $legacy_gone,
    'permission_callback' => '__return_true',
  ]);

  register_rest_route($ns, '/sync-old', [
    'methods'  => 'GET',
    'callback' => $legacy_gone,
    'permission_callback' => '__return_true',
  ]);
