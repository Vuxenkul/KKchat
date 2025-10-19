<?php
if (!defined('ABSPATH')) exit;

add_action('init', function () {
  if (!defined('DOING_CRON') || !DOING_CRON) { return; }
  if (function_exists('rest_get_server')) {
    rest_get_server();
  }
}, 5);

/**
 * REST API (public + admin)
 *
 * Namespace: kkchat/v1
 */
add_action('rest_api_init', function () {
  $ns = 'kkchat/v1';

  /* =========================================================
   *  Session unlock helper — prevents PHP session file lock
   * ========================================================= */
  if (!function_exists('kkchat_close_session_if_open')) {
    function kkchat_close_session_if_open(): void {
      if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
    }
  }

  if (!function_exists('kkchat_public_presence_snapshot')) {
    /**
     * Shared helper for public presence payloads (non-admin views).
     *
     * @param int   $now           Current unix timestamp for freshness/TTL checks.
     * @param int   $window        Seconds of recency to include (<=0 disables the cutoff).
     * @param array $admin_names   Lower-cased admin WP usernames for flagging.
     * @param array $opts          Optional flags: include_flagged.
     */
    function kkchat_public_presence_snapshot(int $now, int $window, array $admin_names, array $opts = []): array {
      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();

      $includeFlagged = !empty($opts['include_flagged']);

      $limit = (int) apply_filters('kkchat_public_presence_limit', 400);
      if ($limit <= 0) { $limit = 400; }
      $limit = max(1, $limit);

      $cols = ['id','name','gender','wp_username'];
      if ($includeFlagged) { $cols[] = 'watch_flag'; }

      $select = implode(',', $cols);

      $cacheTtl = max(0, (int) apply_filters('kkchat_public_presence_cache_ttl', 2));
      $cacheKey = null;
      if ($cacheTtl > 0 && function_exists('wp_cache_get')) {
        $bucket   = max(1, $cacheTtl);
        $cacheKey = sprintf(
          'presence:%s:%d:%d:%d',
          $includeFlagged ? 'f1' : 'f0',
          $window,
          $limit,
          (int) floor($now / $bucket)
        );
        $cached = wp_cache_get($cacheKey, 'kkchat');
        if (is_array($cached)) {
          return $cached;
        }
      }

      if ($window > 0) {
        $sql = $wpdb->prepare(
          "SELECT {$select}
             FROM {$t['users']}
            WHERE %d - last_seen <= %d
            ORDER BY name_lc ASC
            LIMIT %d",
          $now,
          $window,
          $limit
        );
      } else {
        $sql = $wpdb->prepare(
          "SELECT {$select}
             FROM {$t['users']}
            ORDER BY name_lc ASC
            LIMIT %d",
          $limit
        );
      }

      $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
      $out  = [];

      foreach ($rows as $r) {
        $entry = [
          'id'       => (int) ($r['id'] ?? 0),
          'name'     => (string) ($r['name'] ?? ''),
          'gender'   => (string) ($r['gender'] ?? ''),
          'is_admin' => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
        ];

        if ($includeFlagged) {
          $entry['flagged'] = !empty($r['watch_flag']) ? 1 : 0;
        }

        if ($entry['id'] > 0) {
          $out[] = $entry;
        }
      }

      if ($cacheTtl > 0 && $cacheKey && function_exists('wp_cache_set')) {
        wp_cache_set($cacheKey, $out, 'kkchat', $cacheTtl);
      }

      return $out;
    }
  }

  if (!function_exists('kkchat_sync_build_context')) {
    function kkchat_sync_build_context(WP_REST_Request $req): array {
      kkchat_require_login(false);
      kkchat_assert_not_blocked_or_fail();
      nocache_headers();

      global $wpdb; $t = kkchat_tables();

      kkchat_touch_active_user(false, false);

      $since_pub = isset($_SESSION['kkchat_seen_at_public']) ? (int) $_SESSION['kkchat_seen_at_public'] : 0;

      $me             = kkchat_current_user_id();
      $guest          = kkchat_is_guest() ? 1 : 0;
      $is_admin_viewer = kkchat_is_admin();

      kkchat_close_session_if_open();

      $since = max(-1, (int) $req->get_param('since'));
      $room  = kkchat_sanitize_room_slug((string) $req->get_param('room'));
      if ($room === '') { $room = 'general'; }
      $peer  = $req->get_param('to') !== null ? (int) $req->get_param('to') : null;
      $onlyPub = $req->get_param('public') !== null;

      $limit = (int) $req->get_param('limit');
      if ($limit <= 0) { $limit = 200; }
      $limit = max(1, min($limit, 200));

      return [
        'tables'          => $t,
        'me'              => $me,
        'guest'           => $guest,
        'is_admin_viewer' => $is_admin_viewer,
        'since_pub'       => $since_pub,
        'since'           => $since,
        'room'            => $room,
        'peer'            => $peer,
        'only_public'     => $onlyPub,
        'limit'           => $limit,
      ];
    }
  }

  if (!function_exists('kkchat_sync_is_disabled')) {
    function kkchat_sync_is_disabled(): bool {
      return (int) get_option('kkchat_sync_enabled', 1) !== 1;
    }
  }

  if (!function_exists('kkchat_sync_metrics_get')) {
    function kkchat_sync_metrics_get(): array {
      $defaults = [
        'total_requests'      => 0,
        'total_success'       => 0,
        'rate_limited'        => 0,
        'disabled_hits'       => 0,
        'breaker_opens'       => 0,
        'breaker_denied'      => 0,
        'concurrency_denied'  => 0,
        'last_duration_ms'    => 0.0,
        'avg_duration_ms'     => 0.0,
        'duration_samples'    => 0,
        'last_request_at'     => 0,
        'queue_enqueued'      => 0,
        'queue_completed'     => 0,
        'queue_failed'        => 0,
        'queue_active'        => 0,
      ];

      $stored = get_option('kkchat_sync_metrics');
      if (!is_array($stored)) { $stored = []; }

      return array_merge($defaults, $stored);
    }
  }

  if (!function_exists('kkchat_sync_metrics_store')) {
    function kkchat_sync_metrics_store(array $metrics): void {
      update_option('kkchat_sync_metrics', $metrics, false);
    }
  }

  if (!function_exists('kkchat_sync_metrics_note_duration')) {
    function kkchat_sync_metrics_note_duration(float $durationMs): void {
      $metrics = kkchat_sync_metrics_get();
      $metrics['last_duration_ms'] = $durationMs;
      $metrics['duration_samples'] = (int) $metrics['duration_samples'] + 1;
      $samples = max(1, (int) $metrics['duration_samples']);
      $prevAvg = (float) $metrics['avg_duration_ms'];
      $metrics['avg_duration_ms'] = $prevAvg + (($durationMs - $prevAvg) / $samples);
      kkchat_sync_metrics_store($metrics);
    }
  }

  if (!function_exists('kkchat_sync_metrics_bump')) {
    function kkchat_sync_metrics_bump(string $field, int $delta = 1): void {
      $metrics = kkchat_sync_metrics_get();
      if (!array_key_exists($field, $metrics)) {
        $metrics[$field] = 0;
      }
      $metrics[$field] = (int) $metrics[$field] + $delta;
      $metrics['last_request_at'] = time();
      kkchat_sync_metrics_store($metrics);
    }
  }

  if (!function_exists('kkchat_sync_store_context')) {
    function kkchat_sync_store_context(array $ctx): array {
      $keys = ['me','guest','is_admin_viewer','since_pub','since','room','peer','only_public','limit'];
      $stored = [];
      foreach ($keys as $key) {
        if (array_key_exists($key, $ctx)) {
          $stored[$key] = $ctx[$key];
        }
      }
      return $stored;
    }
  }

  if (!function_exists('kkchat_sync_restore_context')) {
    function kkchat_sync_restore_context(array $stored): array {
      $ctx = $stored;
      $ctx['tables'] = kkchat_tables();
      return $ctx;
    }
  }

  if (!function_exists('kkchat_sync_metrics_refresh_queue')) {
    function kkchat_sync_metrics_refresh_queue(): void {
      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();
      $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['sync_jobs']} WHERE status IN ('pending','running')");
      $metrics = kkchat_sync_metrics_get();
      $metrics['queue_active'] = $active;
      kkchat_sync_metrics_store($metrics);
    }
  }

  if (!function_exists('kkchat_sync_schedule_runner')) {
    function kkchat_sync_schedule_runner(int $delay = 0): void {
      if (!function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled')) { return; }

      $hook   = 'kkchat_sync_queue_run';
      $target = time() + max(0, $delay);
      $next   = wp_next_scheduled($hook);
      $shouldSpawn = false;
      $dueAt = $next ?: $target;

      if ($next && $next <= $target) {
        $shouldSpawn = true;
        $dueAt       = $next;
      } else {
        if ($next && function_exists('wp_unschedule_event')) {
          wp_unschedule_event($next, $hook);
        }

        $scheduled = wp_schedule_single_event($target, $hook);
        if ($scheduled !== false) {
          $shouldSpawn = true;
          $dueAt       = $target;
        }
      }

      if ($shouldSpawn) {
        kkchat_sync_trigger_cron_runner($dueAt);
      }
    }
  }

  if (!function_exists('kkchat_sync_trigger_cron_runner')) {
    function kkchat_sync_trigger_cron_runner(int $dueAt): void {
      if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) { return; }
      if (function_exists('wp_doing_cron') && wp_doing_cron()) { return; }
      if (!function_exists('spawn_cron') && !function_exists('wp_cron')) { return; }

      $now       = time();
      $threshold = (int) apply_filters('kkchat_sync_cron_spawn_threshold', 5);
      if ($threshold < 0) { $threshold = 0; }
      if ($dueAt - $now > $threshold) { return; }

      $lockKey  = 'kkchat_sync_spawn_lock';
      $throttle = max(1, (int) apply_filters('kkchat_sync_cron_spawn_throttle', 5));
      if (function_exists('get_transient') && get_transient($lockKey)) { return; }
      if (function_exists('set_transient')) {
        set_transient($lockKey, 1, $throttle);
      }

      if (function_exists('spawn_cron')) {
        spawn_cron($now);
        return;
      }

      if (function_exists('wp_cron')) {
        wp_cron();
      }
    }
  }

  if (!function_exists('kkchat_sync_enqueue_job')) {
    function kkchat_sync_enqueue_job(array $ctx) {
      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();

      $stored = kkchat_sync_store_context($ctx);
      $encoded = wp_json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($encoded === false) { $encoded = '{}'; }

      $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : wp_generate_password(32, false, false);
      $now   = time();

      $inserted = $wpdb->insert(
        $t['sync_jobs'],
        [
          'user_id'      => (int) ($ctx['me'] ?? 0),
          'view_key'     => kkchat_sync_view_key($ctx),
          'status'       => 'pending',
          'context_json' => $encoded,
          'access_token' => $token,
          'created_at'   => $now,
        ],
        ['%d','%s','%s','%s','%s','%d']
      );

      if ($inserted === false) {
        return new WP_Error('kkchat_sync_enqueue_failed', __('Unable to enqueue sync job', 'kkchat'));
      }

      $jobId = (int) $wpdb->insert_id;

      kkchat_sync_metrics_bump('queue_enqueued');
      kkchat_sync_metrics_refresh_queue();
      kkchat_sync_schedule_runner(0);

      $poll = add_query_arg(['token' => $token], rest_url(sprintf('kkchat/v1/sync/jobs/%d', $jobId)));

      return [
        'id'     => $jobId,
        'token'  => $token,
        'status' => 'pending',
        'poll'   => $poll,
      ];
    }
  }

  if (!function_exists('kkchat_sync_claim_job')) {
    function kkchat_sync_claim_job(): ?array {
      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();

      while (true) {
        $row = $wpdb->get_row("SELECT * FROM {$t['sync_jobs']} WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1", ARRAY_A);
        if (!$row) { return null; }

        $now      = time();
        $attempts = max(0, (int) ($row['attempts'] ?? 0)) + 1;
        $updated  = $wpdb->update(
          $t['sync_jobs'],
          [
            'status'     => 'running',
            'started_at' => $now,
            'attempts'   => $attempts,
          ],
          [
            'id'     => (int) $row['id'],
            'status' => 'pending',
          ],
          ['%s','%d','%d'],
          ['%d','%s']
        );

        if ($updated === 1) {
          $row['status']     = 'running';
          $row['started_at'] = $now;
          $row['attempts']   = $attempts;
          return $row;
        }
      }
    }
  }

  if (!function_exists('kkchat_sync_mark_job_pending')) {
    function kkchat_sync_mark_job_pending(int $jobId): void {
      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();
      $wpdb->update(
        $t['sync_jobs'],
        [
          'status'     => 'pending',
          'started_at' => null,
        ],
        ['id' => $jobId],
        ['%s','%s'],
        ['%d']
      );
    }
  }

  if (!function_exists('kkchat_sync_prepare_response')) {
    function kkchat_sync_prepare_response(array $payload, array $ctx): array {
      $since   = (int) ($ctx['since'] ?? -1);
      $cursor  = kkchat_sync_max_cursor($payload, $since);
      $hasChanges = ($since < 0) ? true : ($cursor > $since);

      $retryAfter = kkchat_sync_retry_after_hint($payload, $ctx, $hasChanges);
      $etag       = kkchat_sync_build_etag($ctx, $cursor);

      $headers = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma'        => 'no-cache',
      ];

      if ($retryAfter > 0) { $headers['Retry-After'] = (string) $retryAfter; }
      if ($etag !== '')    { $headers['ETag']        = $etag; }

      if (!$hasChanges) {
        return [
          'status'      => 204,
          'headers'     => $headers,
          'body'        => null,
          'cursor'      => $cursor,
          'retry_after' => $retryAfter,
          'etag'        => $etag,
          'has_changes' => false,
        ];
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

      return [
        'status'      => 200,
        'headers'     => $headers,
        'body'        => $data,
        'cursor'      => $cursor,
        'retry_after' => $retryAfter,
        'etag'        => $etag,
        'has_changes' => true,
      ];
    }
  }

  if (!function_exists('kkchat_sync_process_queue')) {
    function kkchat_sync_process_queue(): void {
      $batchLimit = (int) apply_filters('kkchat_sync_worker_batch', kkchat_sync_concurrency_limit());
      if ($batchLimit <= 0) { $batchLimit = kkchat_sync_concurrency_limit(); }
      if ($batchLimit <= 0) { $batchLimit = 1; }

      $processed = 0;

      while ($processed < $batchLimit) {
        $job = kkchat_sync_claim_job();
        if (!$job) { break; }

        $slotToken = kkchat_sync_acquire_slot();
        if ($slotToken === null) {
          kkchat_sync_mark_job_pending((int) $job['id']);
          kkchat_sync_metrics_bump('concurrency_denied');
          $retryBusy = (int) apply_filters('kkchat_sync_busy_retry', 8);
          kkchat_sync_schedule_runner(max(1, $retryBusy));
          break;
        }

        $processed++;
        $startedAt = microtime(true);

        try {
          $stored = json_decode((string) ($job['context_json'] ?? '{}'), true);
          if (!is_array($stored)) { $stored = []; }
          $ctx = kkchat_sync_restore_context($stored);

          $payload = kkchat_sync_build_payload($ctx);

          if (is_wp_error($payload)) {
            $cooldown = (int) apply_filters('kkchat_sync_failure_retry', 30, $payload);
            kkchat_sync_breaker_note_failure();
            kkchat_wpdb_reconnect_if_needed();
            global $wpdb; $t = kkchat_tables();
            $wpdb->update(
              $t['sync_jobs'],
              [
                'status'       => 'failed',
                'error_message'=> $payload->get_error_message(),
                'finished_at'  => time(),
                'retry_after'  => $cooldown,
              ],
              ['id' => (int) $job['id']],
              ['%s','%s','%d','%d'],
              ['%d']
            );
            kkchat_sync_metrics_bump('queue_failed');
            kkchat_sync_metrics_note_duration((microtime(true) - $startedAt) * 1000);
            continue;
          }

          $response = kkchat_sync_prepare_response($payload, $ctx);
          $encoded  = wp_json_encode(
            [
              'status'  => $response['status'],
              'headers' => $response['headers'],
              'body'    => $response['body'],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
          );
          if ($encoded === false) { $encoded = '{}'; }

          kkchat_wpdb_reconnect_if_needed();
          global $wpdb; $t = kkchat_tables();
          $wpdb->update(
            $t['sync_jobs'],
            [
              'status'       => 'complete',
              'response_json'=> $encoded,
              'error_message'=> null,
              'finished_at'  => time(),
              'cursor'       => (int) $response['cursor'],
              'retry_after'  => (int) $response['retry_after'],
              'etag'         => (string) $response['etag'],
            ],
            ['id' => (int) $job['id']],
            ['%s','%s','%s','%d','%d','%d','%s'],
            ['%d']
          );

          kkchat_sync_breaker_note_success();
          kkchat_sync_metrics_bump('queue_completed');
          kkchat_sync_metrics_bump('total_success');
          kkchat_sync_metrics_note_duration((microtime(true) - $startedAt) * 1000);
        } catch (\Throwable $th) {
          kkchat_sync_breaker_note_failure();
          kkchat_wpdb_reconnect_if_needed();
          global $wpdb; $t = kkchat_tables();
          $cooldown = (int) apply_filters('kkchat_sync_failure_retry', 30, $th);
          $wpdb->update(
            $t['sync_jobs'],
            [
              'status'       => 'failed',
              'error_message'=> $th->getMessage(),
              'finished_at'  => time(),
              'retry_after'  => $cooldown,
            ],
            ['id' => (int) $job['id']],
            ['%s','%s','%d','%d'],
            ['%d']
          );
          kkchat_sync_metrics_bump('queue_failed');
          kkchat_sync_metrics_note_duration((microtime(true) - $startedAt) * 1000);
        } finally {
          kkchat_sync_release_slot($slotToken);
        }
      }

      kkchat_sync_metrics_refresh_queue();

      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();
      $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['sync_jobs']} WHERE status = 'pending'");
      if ($pending > 0) {
        kkchat_sync_schedule_runner(0);
      }
    }
  }

  add_action('kkchat_sync_queue_run', 'kkchat_sync_process_queue');

  if (!function_exists('kkchat_sync_queue_tick')) {
    function kkchat_sync_queue_tick(): void {
      kkchat_sync_schedule_runner(0);
    }
  }
  add_action('kkchat_cron_tick', 'kkchat_sync_queue_tick');

  if (!function_exists('kkchat_sync_concurrency_limit')) {
    function kkchat_sync_concurrency_limit(): int {
      $limit = (int) apply_filters('kkchat_sync_concurrency_limit', (int) get_option('kkchat_sync_concurrency', 1));
      return max(1, $limit);
    }
  }

  if (!function_exists('kkchat_sync_acquire_slot')) {
    function kkchat_sync_acquire_slot(): ?string {
      $limit = kkchat_sync_concurrency_limit();
      $key   = 'kkchat_sync_inflight';
      $ttl   = max(10, (int) apply_filters('kkchat_sync_slot_ttl', 20));
      $now   = microtime(true);
      $slots = get_transient($key);
      if (!is_array($slots)) { $slots = []; }

      foreach ($slots as $token => $started) {
        if (!is_float($started) && !is_int($started)) { unset($slots[$token]); continue; }
        if ($started + $ttl < $now) { unset($slots[$token]); }
      }

      if (count($slots) >= $limit) {
        set_transient($key, $slots, $ttl);
        return null;
      }

      $token = wp_generate_uuid4();
      $slots[$token] = $now;
      set_transient($key, $slots, $ttl);
      return $token;
    }
  }

  if (!function_exists('kkchat_sync_release_slot')) {
    function kkchat_sync_release_slot(?string $token): void {
      if ($token === null) { return; }
      $key   = 'kkchat_sync_inflight';
      $ttl   = max(10, (int) apply_filters('kkchat_sync_slot_ttl', 20));
      $slots = get_transient($key);
      if (!is_array($slots)) { return; }
      if (isset($slots[$token])) { unset($slots[$token]); }
      set_transient($key, $slots, $ttl);
    }
  }

  if (!function_exists('kkchat_sync_breaker_config')) {
    function kkchat_sync_breaker_config(): array {
      $threshold = (int) apply_filters('kkchat_sync_breaker_threshold', get_option('kkchat_sync_breaker_threshold', 5));
      $window    = (int) apply_filters('kkchat_sync_breaker_window', get_option('kkchat_sync_breaker_window', 60));
      $cooldown  = (int) apply_filters('kkchat_sync_breaker_cooldown', get_option('kkchat_sync_breaker_cooldown', 90));
      return [
        'threshold' => max(1, $threshold),
        'window'    => max(5, $window),
        'cooldown'  => max(10, $cooldown),
      ];
    }
  }

  if (!function_exists('kkchat_sync_breaker_state')) {
    function kkchat_sync_breaker_state(): array {
      $state = get_transient('kkchat_sync_breaker_state');
      if (!is_array($state)) {
        $state = ['failures'=>0,'first_failure'=>0,'open_until'=>0];
      }
      return $state;
    }
  }

  if (!function_exists('kkchat_sync_breaker_store')) {
    function kkchat_sync_breaker_store(array $state): void {
      set_transient('kkchat_sync_breaker_state', $state, 2 * 60 * 60);
    }
  }

  if (!function_exists('kkchat_sync_breaker_is_open')) {
    function kkchat_sync_breaker_is_open(?int &$retryAfter = null): bool {
      $state = kkchat_sync_breaker_state();
      $now   = time();
      if (!empty($state['open_until']) && $state['open_until'] > $now) {
        $retryAfter = (int) max(1, $state['open_until'] - $now);
        return true;
      }
      if (!empty($state['open_until']) && $state['open_until'] <= $now) {
        $state['open_until'] = 0;
        $state['failures'] = 0;
        $state['first_failure'] = 0;
        kkchat_sync_breaker_store($state);
      }
      return false;
    }
  }

  if (!function_exists('kkchat_sync_breaker_note_failure')) {
    function kkchat_sync_breaker_note_failure(): void {
      $cfg   = kkchat_sync_breaker_config();
      $state = kkchat_sync_breaker_state();
      $now   = time();

      if ($state['first_failure'] === 0 || ($now - (int) $state['first_failure']) > $cfg['window']) {
        $state['first_failure'] = $now;
        $state['failures'] = 1;
      } else {
        $state['failures'] = (int) $state['failures'] + 1;
      }

      if ($state['failures'] >= $cfg['threshold']) {
        $state['open_until'] = $now + $cfg['cooldown'];
        kkchat_sync_metrics_bump('breaker_opens');
      }

      kkchat_sync_breaker_store($state);
    }
  }

  if (!function_exists('kkchat_sync_breaker_note_success')) {
    function kkchat_sync_breaker_note_success(): void {
      $state = kkchat_sync_breaker_state();
      if ($state['failures'] !== 0 || $state['first_failure'] !== 0 || $state['open_until'] !== 0) {
        $state['failures'] = 0;
        $state['first_failure'] = 0;
        $state['open_until'] = 0;
        kkchat_sync_breaker_store($state);
      }
    }
  }

  if (!function_exists('kkchat_sync_queue_housekeeping')) {
    function kkchat_sync_queue_housekeeping(): void {
      if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) { return; }
      if (wp_next_scheduled('kkchat_sync_housekeeping')) { return; }
      wp_schedule_single_event(time() + 2, 'kkchat_sync_housekeeping');
    }
  }

  if (!function_exists('kkchat_sync_run_housekeeping')) {
    function kkchat_sync_run_housekeeping(): void {
      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();
      $now = time();
      $wpdb->query($wpdb->prepare(
        "DELETE FROM {$t['users']} WHERE %d - last_seen > %d",
        $now,
        kkchat_user_ttl()
      ));
      $wpdb->query($wpdb->prepare(
        "UPDATE {$t['users']}\n            SET watch_flag = 0, watch_flag_at = NULL\n          WHERE watch_flag = 1\n            AND watch_flag_at IS NOT NULL\n            AND %d - watch_flag_at > %d",
        $now,
        kkchat_watch_reset_after()
      ));

      $jobTtl = max(300, (int) apply_filters('kkchat_sync_job_ttl', 1800));
      $wpdb->query($wpdb->prepare(
        "DELETE FROM {$t['sync_jobs']}\n          WHERE status IN ('complete','failed')\n            AND finished_at IS NOT NULL\n            AND %d - finished_at > %d",
        $now,
        $jobTtl
      ));
    }
  }
  add_action('kkchat_sync_housekeeping', 'kkchat_sync_run_housekeeping');

  if (!function_exists('kkchat_sync_build_payload')) {
    function kkchat_sync_build_payload(array $ctx): array {
      global $wpdb; $t = kkchat_tables();

      $now = time();

      $me      = (int) ($ctx['me'] ?? 0);
      $guest   = !empty($ctx['guest']) ? 1 : 0;
      $since   = (int) ($ctx['since'] ?? -1);
      $room    = (string) ($ctx['room'] ?? 'general');
      $peer    = $ctx['peer'] !== null ? (int) $ctx['peer'] : null;
      $onlyPub = !empty($ctx['only_public']);
      $limit   = (int) ($ctx['limit'] ?? 200);
      $limit   = max(1, min($limit, 200));
      $since_pub = (int) ($ctx['since_pub'] ?? 0);
      $is_admin_viewer = !empty($ctx['is_admin_viewer']);

      kkchat_sync_queue_housekeeping();
      $admin_names = kkchat_admin_usernames();
      $presence    = [];
      if ($is_admin_viewer) {
        $presence_rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT u.id,
                    u.name,
                    u.gender,
                    u.watch_flag,
                    u.wp_username,
                    u.last_seen,
                    lm.last_content,
                    lm.last_room,
                    lm.last_recipient_id,
                    lm.last_recipient_name,
                    lm.last_kind,
                    lm.last_created_at
               FROM {$t['users']} u
          LEFT JOIN (
                SELECT m.sender_id,
                       SUBSTRING(m.content, 1, 200) AS last_content,
                       m.room AS last_room,
                       m.recipient_id AS last_recipient_id,
                       m.recipient_name AS last_recipient_name,
                       m.kind AS last_kind,
                       m.created_at AS last_created_at
                  FROM {$t['messages']} m
            INNER JOIN (
                      SELECT sender_id, MAX(id) AS last_id
                        FROM {$t['messages']}
                       WHERE hidden_at IS NULL
                    GROUP BY sender_id
                    ) latest
                    ON latest.sender_id = m.sender_id AND latest.last_id = m.id
                 WHERE m.hidden_at IS NULL
              ) lm ON lm.sender_id = u.id
              WHERE %d - u.last_seen <= %d
              ORDER BY u.name_lc ASC
              LIMIT %d",
            $now,
            max(30, (int) apply_filters('kkchat_presence_active_sec', 60)),
            max(50, (int) apply_filters('kkchat_presence_limit', 1200))
          ),
          ARRAY_A
        ) ?: [];

        foreach ($presence_rows as $r) {
          $lastMsg = null;
          if (
            isset($r['last_content']) ||
            isset($r['last_room']) ||
            isset($r['last_recipient_id']) ||
            isset($r['last_kind'])
          ) {
            $lastMsg = [
              'text'            => (string) ($r['last_content'] ?? ''),
              'room'            => ($r['last_room'] ?? '') !== '' ? (string) $r['last_room'] : null,
              'to'              => isset($r['last_recipient_id']) ? (int) $r['last_recipient_id'] : null,
              'recipient_name'  => ($r['last_recipient_name'] ?? '') !== '' ? (string) $r['last_recipient_name'] : null,
              'kind'            => (string) ($r['last_kind'] ?? 'chat'),
              'time'            => isset($r['last_created_at']) ? (int) $r['last_created_at'] : null,
            ];
          }

          $presence[] = [
            'id'            => (int) ($r['id'] ?? 0),
            'name'          => (string) ($r['name'] ?? ''),
            'gender'        => (string) ($r['gender'] ?? ''),
            'flagged'       => !empty($r['watch_flag']) ? 1 : 0,
            'is_admin'      => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
            'last_seen'     => (int) ($r['last_seen'] ?? 0),
            'last_message'  => $lastMsg,
          ];
        }
      } else {
        $publicPresenceWindow = max(0, (int) apply_filters('kkchat_public_presence_window', 120));
        $presence = kkchat_public_presence_snapshot($now, $publicPresenceWindow, $admin_names, [
          'include_flagged' => false,
        ]);
      }

      $blocked = kkchat_blocked_ids($me);

      $per = [];
      $perRoom = [];
      $totPriv = 0;
      $totPub  = 0;
      $latestPerId  = 0;
      $latestRoomId = 0;
      $roomLastSeen = [];

      $blkPer = '';
      $paramsPer = [$me];
      if ($blocked) {
        $blkPer = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
        foreach ($blocked as $bid) { $paramsPer[] = (int) $bid; }
      }
      $paramsPer[] = $me;

      $sqlPer = "
        SELECT src.peer_id,
               src.max_id,
               COALESCE(ldr.last_msg_id, 0) AS last_seen
          FROM (
            SELECT m.sender_id AS peer_id,
                   MAX(m.id) AS max_id
              FROM {$t['messages']} m
             WHERE m.recipient_id = %d
               AND m.hidden_at IS NULL
               $blkPer
             GROUP BY m.sender_id
          ) src
          LEFT JOIN {$t['last_dm_reads']} ldr
            ON ldr.user_id = %d AND ldr.peer_id = src.peer_id";
      $rowsPer = $wpdb->get_results($wpdb->prepare($sqlPer, ...$paramsPer), ARRAY_A) ?: [];
      foreach ($rowsPer as $r) {
        $peer      = (int) ($r['peer_id'] ?? 0);
        $maxId     = (int) ($r['max_id'] ?? 0);
        $lastSeen  = (int) ($r['last_seen'] ?? 0);
        if ($peer <= 0) { continue; }
        if ($maxId > $lastSeen) {
          $per[$peer] = 1;
          $totPriv++;
        } else {
          $per[$peer] = 0;
        }
        $latestPerId = max($latestPerId, $maxId);
      }

      $blkRoom = '';
      $paramsRoom = [$me, $since_pub, $guest];
      if ($blocked) {
        $blkRoom = ' AND m.sender_id NOT IN (' . implode(',', array_fill(0, count($blocked), '%d')) . ') ';
        foreach ($blocked as $bid) { $paramsRoom[] = (int) $bid; }
      }
      $paramsRoom[] = $me;

      $sqlRoom = "
        SELECT src.slug,
               src.max_id,
               COALESCE(lr.last_msg_id, 0) AS last_seen
          FROM (
            SELECT m.room AS slug,
                   MAX(m.id) AS max_id
              FROM {$t['messages']} m
         LEFT JOIN {$t['rooms']} rr ON rr.slug = m.room
             WHERE m.recipient_id IS NULL
               AND m.sender_id <> %d
               AND rr.slug IS NOT NULL
               AND m.created_at > %d
               AND (%d = 0 OR rr.member_only = 0)
               AND m.hidden_at IS NULL
               $blkRoom
             GROUP BY m.room
          ) src
          LEFT JOIN {$t['last_reads']} lr
            ON lr.user_id = %d AND lr.room_slug = src.slug";
      $rowsRoom = $wpdb->get_results($wpdb->prepare($sqlRoom, ...$paramsRoom), ARRAY_A) ?: [];
      foreach ($rowsRoom as $r) {
        $slug     = (string) ($r['slug'] ?? '');
        $maxId    = (int) ($r['max_id'] ?? 0);
        $lastSeen = (int) ($r['last_seen'] ?? 0);
        if ($slug === '') { continue; }
        $roomLastSeen[$slug] = $lastSeen;
        if ($maxId > $lastSeen) {
          $perRoom[$slug] = 1;
          $totPub++;
        } else {
          $perRoom[$slug] = 0;
        }
        $latestRoomId = max($latestRoomId, $maxId);
      }

      $unreadLatest = max($latestPerId, $latestRoomId);

      $unread = [
        'totPriv' => $totPriv,
        'totPub'  => $totPub,
        'per'     => (object) $per,
        'rooms'   => (object) $perRoom,
        'latest'  => $unreadLatest,
      ];
      $msgs = [];
      $msgColumns = 'id, room, sender_id, sender_name, recipient_id, recipient_name, content, created_at, kind, hidden_at';
      if ($onlyPub) {
        if ($since < 0) {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE recipient_id IS NULL
                 AND room = %s
                 AND hidden_at IS NULL
               ORDER BY id DESC
               LIMIT %d",
              $room, $limit
            ),
            ARRAY_A
          ) ?: [];
          $rows = array_reverse($rows);
        } else {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE id > %d
                 AND recipient_id IS NULL
                 AND room = %s
                 AND hidden_at IS NULL
               ORDER BY id ASC
               LIMIT %d",
              $since, $room, $limit
            ),
            ARRAY_A
          ) ?: [];
        }
      } else {
        if ($peer) {
          if ($since < 0) {
            $rows = $wpdb->get_results(
              $wpdb->prepare(
                "SELECT $msgColumns FROM {$t['messages']}
                 WHERE hidden_at IS NULL
                   AND ((sender_id = %d AND recipient_id = %d) OR
                        (sender_id = %d AND recipient_id = %d))
                 ORDER BY id DESC
                 LIMIT %d",
                $me, $peer, $peer, $me, $limit
              ),
              ARRAY_A
            ) ?: [];
            $rows = array_reverse($rows);
          } else {
            $rows = $wpdb->get_results(
              $wpdb->prepare(
                "SELECT $msgColumns FROM {$t['messages']}
                 WHERE id > %d
                   AND hidden_at IS NULL
                   AND ((sender_id = %d AND recipient_id = %d) OR
                        (sender_id = %d AND recipient_id = %d))
                 ORDER BY id ASC
                 LIMIT %d",
                $since, $me, $peer, $peer, $me, $limit
              ),
              ARRAY_A
            ) ?: [];
          }
        } else {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE id > %d
                 AND (recipient_id = %d OR sender_id = %d)
                 AND hidden_at IS NULL
               ORDER BY id ASC
               LIMIT %d",
              $since, $me, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
        }
      }

      if (!empty($rows)) {
        if ($blocked) {
          $rows = array_values(array_filter($rows, function ($r) use ($blocked, $me, $onlyPub) {
            $sid = (int) $r['sender_id'];
            if (!$onlyPub && $sid === $me) { return true; }
            return !in_array($sid, $blocked, true);
          }));
        }

        if (!empty($rows)) {
          $ids = array_map(fn($r) => (int) $r['id'], $rows);
          $placeholders = implode(',', array_fill(0, count($ids), '%d'));
          $read_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT message_id, user_id FROM {$t['reads']} WHERE message_id IN ($placeholders)", ...$ids),
            ARRAY_A
          ) ?: [];
          $read_map = [];
          foreach ($read_rows as $rr) {
            $mid = (int) $rr['message_id'];
            $uid = (int) $rr['user_id'];
            $read_map[$mid][] = $uid;
          }
          foreach ($rows as $r) {
            $mid = (int) $r['id'];
            $msgs[] = [
              'id'             => $mid,
              'time'           => (int) $r['created_at'],
              'kind'           => $r['kind'] ?: 'chat',
              'room'           => $r['room'] ?: null,
              'sender_id'      => (int) $r['sender_id'],
              'sender_name'    => (string) $r['sender_name'],
              'recipient_id'   => isset($r['recipient_id']) ? (int) $r['recipient_id'] : null,
              'recipient_name' => $r['recipient_name'] ?: null,
              'content'        => $r['content'],
              'read_by'        => isset($read_map[$mid]) ? array_values(array_map('intval', $read_map[$mid])) : [],
            ];
          }
        }
      }

      $urow = $wpdb->get_row($wpdb->prepare(
        "SELECT name, wp_username FROM {$t['users']} WHERE id = %d LIMIT 1", $me
      ), ARRAY_A);
      $dispName = trim((string) ($urow['name'] ?? '')) ?: ('User' . $me);
      $wpUser   = trim((string) ($urow['wp_username'] ?? ''));
      $parts    = array_filter([preg_quote($dispName, '/'), $wpUser !== '' ? preg_quote($wpUser, '/') : null]);
      $nameAlt  = implode('|', $parts);
      $mentionRe = $nameAlt !== '' ? "/(^|[^\\w])@(?:{$nameAlt})(?=$|\\W)/i" : null;

      $mention_bumps = [];
      if ($mentionRe && !empty($perRoom)) {
        $mentionSlugs = [];
        foreach ($perRoom as $slug => $count) {
          if ((int) $count > 0) {
            $mentionSlugs[] = (string) $slug;
          }
        }

        if ($mentionSlugs) {
          foreach ($mentionSlugs as $slug) {
            $slug = (string) $slug;
            if ($slug === '') { continue; }
            $lastSeenId = (int) ($roomLastSeen[$slug] ?? 0);

            $paramsMB = [$me, $guest, $lastSeenId];
            if ($blocked) {
              foreach ($blocked as $bid) { $paramsMB[] = (int) $bid; }
              $blkList = implode(',', array_fill(0, count($blocked), '%d'));
              $blkSql  = " AND m.sender_id NOT IN ($blkList)";
            } else {
              $blkSql = '';
            }
            $paramsMB[] = $slug;

            $rowsMB = $wpdb->get_results(
              $wpdb->prepare(
                "SELECT m.content
                   FROM {$t['messages']} m
              LEFT JOIN {$t['rooms']} rr ON rr.slug = m.room
                  WHERE m.recipient_id IS NULL
                    AND m.sender_id <> %d
                    AND rr.slug IS NOT NULL
                    AND (%d = 0 OR rr.member_only = 0)
                    AND m.id > %d
                    AND m.hidden_at IS NULL
                    $blkSql
                    AND m.room = %s
               ORDER BY m.id DESC
                  LIMIT 30",
                ...$paramsMB
              ),
              ARRAY_A
            ) ?: [];

            $hit = false;
            foreach ($rowsMB as $rowMB) {
              $content = (string) ($rowMB['content'] ?? '');
              if ($content !== '' && preg_match($mentionRe, $content)) { $hit = true; break; }
            }
            $mention_bumps[$slug] = $hit ? true : false;
          }
        }
      }

      return [
        'now'           => $now,
        'unread'        => $unread,
        'presence'      => $presence,
        'messages'      => $msgs,
        'mention_bumps' => (object) $mention_bumps,
      ];
    }
  }

    if (!function_exists('kkchat_sync_max_cursor')) {
      function kkchat_sync_max_cursor(array $payload, int $current): int {
        $max = $current;
        foreach (($payload['messages'] ?? []) as $msg) {
          $mid = isset($msg['id']) ? (int) $msg['id'] : null;
          if ($mid !== null) {
            $max = max($max, $mid);
          }
        }

        $unread = $payload['unread'] ?? null;
        if (is_array($unread)) {
          $latest = isset($unread['latest']) ? (int) $unread['latest'] : 0;
          $max = max($max, $latest);
        } elseif (is_object($unread) && isset($unread->latest)) {
          $max = max($max, (int) $unread->latest);
        }

        return $max;
      }
    }

  if (!function_exists('kkchat_sync_retry_after_hint')) {
    function kkchat_sync_retry_after_hint(array $payload, array $ctx, bool $hasChanges): int {
      $fast = (int) apply_filters('kkchat_sync_retry_after_fast', 3, $ctx, $payload, $hasChanges);
      if ($fast <= 0) { $fast = 3; }

      $idle = (int) apply_filters('kkchat_sync_retry_after_idle', 12, $ctx, $payload, $hasChanges);
      if ($idle <= 0) { $idle = 12; }

      $hint = $hasChanges ? $fast : $idle;
      return max(1, $hint);
    }
  }

  if (!function_exists('kkchat_sync_format_events')) {
    function kkchat_sync_format_events(array $payload, bool $initial): array {
      $event = [
        'type'          => $initial ? 'snapshot' : 'delta',
        'messages'      => array_values($payload['messages'] ?? []),
        'unread'        => $payload['unread'] ?? new stdClass(),
        'presence'      => $payload['presence'] ?? [],
        'mention_bumps' => $payload['mention_bumps'] ?? new stdClass(),
      ];

      return [$event];
    }
  }

  if (!function_exists('kkchat_sync_view_key')) {
    function kkchat_sync_view_key(array $ctx): string {
      $peer = $ctx['peer'] ?? null;
      if ($peer !== null) {
        return 'dm-' . (int) $peer;
      }

      $room = (string) ($ctx['room'] ?? 'general');
      if ($room === '') { $room = 'general'; }
      return 'room-' . $room;
    }
  }

  if (!function_exists('kkchat_sync_build_etag')) {
    function kkchat_sync_build_etag(array $ctx, int $cursor): string {
      $key = kkchat_sync_view_key($ctx);
      return sprintf('W/"%s-%d"', $key, max(-1, $cursor));
    }
  }

  if (!function_exists('kkchat_sync_rate_guard')) {
    function kkchat_sync_rate_guard(int $userId): int {
      if ($userId <= 0) { return 0; }

      $bucket = 'kkchat';
      $now    = microtime(true);

      if (function_exists('wp_cache_get') && function_exists('wp_cache_set')) {
        $banKey = 'sync:penalty:' . $userId;
        $ban    = wp_cache_get($banKey, $bucket);
        if (is_array($ban) && !empty($ban['until']) && $ban['until'] > $now) {
          return (int) ceil($ban['until'] - $now);
        }

        $lastKey = 'sync:last:' . $userId;
        $last    = wp_cache_get($lastKey, $bucket);
        wp_cache_set($lastKey, $now, $bucket, 30);

        if (is_numeric($last) && ($now - (float) $last) < 0.9) {
          $penalty = max(2, (int) apply_filters('kkchat_sync_rate_penalty', 6));
          $until   = $now + $penalty;
          wp_cache_set($banKey, ['until' => $until], $bucket, $penalty);
          return $penalty;
        }
      }

      return 0;
    }
  }

  /* =========================================================
   *                     AUTH
   * ========================================================= */

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
      kkchat_logout_session();
      kkchat_close_session_if_open(); // unlock after session mutation
      kkchat_json(['ok' => true]);
    },
    'permission_callback' => '__return_true',
  ]);

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


/* ---------- MENTION HELPERS ---------- */

function kk_buildMentionRegex(string $displayName, string $username): string {
    $dn = preg_quote($displayName, '/');
    $un = preg_quote($username, '/');
    // Match "@Display Name" or "@username" with boundaries, case-insensitive
    return "/(^|[^\\w])@(?:{$dn}|{$un})(?=$|\\W)/i";
}
function kk_textMentionsUser(string $content, string $mentionRegex): bool {
    return (bool)preg_match($mentionRegex, $content);
}

/**
 * Returns ['slugOrDmKey' => true|false] for sources whose unread increased.
 * Excludes self-sent messages. Scans up to LIMIT rows for speed.
 */
function kk_computeMentionBumps(PDO $db, array $authUser, array $perRoom, array $perDm = []): array {
    $userId    = (int)$authUser['id'];
    $dispName  = (string)($authUser['display_name'] ?? $authUser['name'] ?? '');
    $username  = (string)($authUser['username'] ?? $authUser['handle'] ?? $authUser['login'] ?? $dispName);
    $re        = kk_buildMentionRegex($dispName, $username);
    $out       = [];

    // ---- ROOMS ----
    foreach ($perRoom as $slug => $delta) {
        if ((int)$delta <= 0) { continue; }

        // last read id for this user/room (adjust table/columns if different)
        $stmt = $db->prepare("
            SELECT lr.last_msg_id
            FROM last_reads lr
            WHERE lr.user_id = :uid AND lr.room_slug = :slug
            LIMIT 1
        ");
        $stmt->execute([':uid'=>$userId, ':slug'=>$slug]);
        $lastId = (int)($stmt->fetchColumn() ?: 0);

        // Check up to 30 new messages for a mention (exclude self)
        $stmt = $db->prepare("
            SELECT m.content
            FROM messages m
            WHERE m.room_slug = :slug
              AND m.id > :lastId
              AND m.sender_id <> :uid
            ORDER BY m.id DESC
            LIMIT 30
        ");
        $stmt->execute([':slug'=>$slug, ':lastId'=>$lastId, ':uid'=>$userId]);

        $hint = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (kk_textMentionsUser((string)($row['content'] ?? ''), $re)) { $hint = true; break; }
        }
        $out[$slug] = $hint;
    }

    // ---- DMs ---- (skip if your app doesn’t have per-DM counters)
    foreach ($perDm as $dmKey => $delta) {
        if ((int)$delta <= 0) { continue; }

        $stmt = $db->prepare("
            SELECT ldr.last_msg_id
            FROM last_dm_reads ldr
            WHERE ldr.user_id = :uid AND ldr.dm_key = :dmk
            LIMIT 1
        ");
        $stmt->execute([':uid'=>$userId, ':dmk'=>$dmKey]);
        $lastId = (int)($stmt->fetchColumn() ?: 0);

        $stmt = $db->prepare("
            SELECT m.content
            FROM messages m
            WHERE m.dm_key = :dmk
              AND m.id > :lastId
              AND m.sender_id <> :uid
            ORDER BY m.id DESC
            LIMIT 30
        ");
        $stmt->execute([':dmk'=>$dmKey, ':lastId'=>$lastId, ':uid'=>$userId]);

        $hint = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (kk_textMentionsUser((string)($row['content'] ?? ''), $re)) { $hint = true; break; }
        }
        $out[$dmKey] = $hint;
    }

    return $out;
}


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

    $job = kkchat_sync_enqueue_job($ctx);
    if (is_wp_error($job)) {
      $cooldown = (int) apply_filters('kkchat_sync_failure_retry', 30, $job);
      $resp = new WP_REST_Response(['err' => 'sync_unavailable'], 503);
      if ($cooldown > 0) { $resp->header('Retry-After', (string) $cooldown); }
      return $resp;
    }

    $retryPoll = (int) apply_filters('kkchat_sync_job_poll_retry', 2, $job, $ctx);
    if ($retryPoll <= 0) { $retryPoll = 2; }

    $resp = new WP_REST_Response([
      'job' => [
        'id'     => (int) ($job['id'] ?? 0),
        'status' => (string) ($job['status'] ?? 'pending'),
        'token'  => (string) ($job['token'] ?? ''),
        'poll'   => (string) ($job['poll'] ?? ''),
      ],
    ], 202);

    $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $resp->header('Pragma', 'no-cache');
    $resp->header('Retry-After', (string) $retryPoll);

    return $resp;
  },
  'permission_callback' => '__return_true',
]);

  register_rest_route($ns, '/sync/jobs/(?P<id>\d+)', [
    'methods'  => 'GET',
    'callback' => function (WP_REST_Request $req) use ($ns) {
      $jobId = (int) $req->get_param('id');
      $token = (string) $req->get_param('token');

      if ($jobId <= 0) {
        return new WP_REST_Response(['err' => 'invalid_job'], 400);
      }

      if ($token === '') {
        return new WP_REST_Response(['err' => 'missing_token'], 400);
      }

      kkchat_require_login(false);
      kkchat_assert_not_blocked_or_fail();

      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();

      $row = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$t['sync_jobs']} WHERE id = %d AND access_token = %s LIMIT 1",
          $jobId,
          $token
        ),
        ARRAY_A
      );

      if (!$row) {
        return new WP_REST_Response(['err' => 'job_not_found'], 404);
      }

      $currentUser = (int) kkchat_current_user_id();
      $jobUser     = isset($row['user_id']) ? (int) $row['user_id'] : 0;
      if ($jobUser > 0 && $currentUser > 0 && $jobUser !== $currentUser) {
        return new WP_REST_Response(['err' => 'job_forbidden'], 403);
      }

      $poll = add_query_arg(['token' => $token], rest_url(sprintf('%s/sync/jobs/%d', $ns, $jobId)));
      $status = (string) ($row['status'] ?? 'pending');

      if ($status === 'complete') {
        $payload = json_decode((string) ($row['response_json'] ?? ''), true);
        if (!is_array($payload)) {
          return new WP_REST_Response(['err' => 'job_corrupt'], 500);
        }

        $body    = $payload['body'] ?? null;
        $code    = isset($payload['status']) ? (int) $payload['status'] : 200;
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];

        $resp = new WP_REST_Response($body, $code);
        foreach ($headers as $hk => $hv) {
          if ($hk !== '' && $hv !== null && $hv !== '') {
            $resp->header($hk, (string) $hv);
          }
        }
        $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $resp->header('Pragma', 'no-cache');
        $resp->header('X-KKchat-Job-Status', 'complete');
        $resp->header('X-KKchat-Job-Id', (string) $jobId);
        $resp->header('X-KKchat-Job-Poll', $poll);
        $resp->header('X-KKchat-Job-Token', $token);
        return $resp;
      }

      if ($status === 'failed') {
        $retry = isset($row['retry_after']) ? (int) $row['retry_after'] : 0;
        if ($retry <= 0) { $retry = (int) apply_filters('kkchat_sync_failure_retry', 30, $row); }
        $resp = new WP_REST_Response([
          'err'     => 'job_failed',
          'message' => (string) ($row['error_message'] ?? ''),
          'job'     => [
            'id'     => $jobId,
            'status' => $status,
            'poll'   => $poll,
            'token'  => $token,
          ],
        ], 503);
        $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $resp->header('Pragma', 'no-cache');
        if ($retry > 0) { $resp->header('Retry-After', (string) $retry); }
        return $resp;
      }

      $retry = isset($row['retry_after']) ? (int) $row['retry_after'] : 0;
      if ($retry <= 0) { $retry = (int) apply_filters('kkchat_sync_job_poll_retry', 2, $row); }
      if ($retry <= 0) { $retry = 2; }

      $resp = new WP_REST_Response([
        'job' => [
          'id'         => $jobId,
          'status'     => $status,
          'created_at' => isset($row['created_at']) ? (int) $row['created_at'] : null,
          'started_at' => isset($row['started_at']) ? (int) $row['started_at'] : null,
          'poll'       => $poll,
          'token'      => $token,
        ],
      ], 202);
      $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
      $resp->header('Pragma', 'no-cache');
      $resp->header('Retry-After', (string) $retry);
      $resp->header('X-KKchat-Job-Status', $status);
      $resp->header('X-KKchat-Job-Id', (string) $jobId);
      $resp->header('X-KKchat-Job-Poll', $poll);
      $resp->header('X-KKchat-Job-Token', $token);
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

register_rest_route($ns, '/users', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) {
    // Must be logged in and not blocked. These may read/write session.
    kkchat_require_login();
    kkchat_assert_not_blocked_or_fail();

    // Presence is “writey”; do it before releasing the session lock.
    kkchat_touch_active_user();

    // Cache control: presence should not be cached.
    nocache_headers();

    global $wpdb;
    $t   = kkchat_tables();
    $now = time();

    // Read any session-driven bits BEFORE closing session.
    $is_admin_viewer = kkchat_is_admin();
    $admin_names     = kkchat_admin_usernames();

    // Public presence lists should stay lean — limit non-admin views to
    // recently active users (default: last 2 minutes).
    $publicPresenceWindow = max(0, (int) apply_filters('kkchat_public_presence_window', 120));

    // Release the PHP session lock early so this GET can long-run
    // without blocking other requests (especially long-pollers).
    kkchat_close_session_if_open();

    // Housekeeping: purge stale presences
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$t['users']} WHERE %d - last_seen > %d",
        $now,
        kkchat_user_ttl()
      )
    );

    // Auto-clear watchlist highlights after N seconds
    $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$t['users']}
            SET watch_flag = 0, watch_flag_at = NULL
          WHERE watch_flag = 1
            AND watch_flag_at IS NOT NULL
            AND %d - watch_flag_at > %d",
        $now,
        kkchat_watch_reset_after()
      )
    );

    if ($is_admin_viewer) {
      $rows = $wpdb->get_results(
        "SELECT u.id,
                u.name,
                u.gender,
                u.watch_flag,
                u.wp_username,
                u.last_seen,
                lm.last_content,
                lm.last_room,
                lm.last_recipient_id,
                lm.last_recipient_name,
                lm.last_kind,
                lm.last_created_at
           FROM {$t['users']} u
      LEFT JOIN (
            SELECT m.sender_id,
                   SUBSTRING(m.content, 1, 200) AS last_content,
                   m.room AS last_room,
                   m.recipient_id AS last_recipient_id,
                   m.recipient_name AS last_recipient_name,
                   m.kind AS last_kind,
                   m.created_at AS last_created_at
              FROM {$t['messages']} m
        INNER JOIN (
                  SELECT sender_id, MAX(id) AS last_id
                    FROM {$t['messages']}
                   WHERE hidden_at IS NULL
                GROUP BY sender_id
                ) latest
                ON latest.sender_id = m.sender_id AND latest.last_id = m.id
             WHERE m.hidden_at IS NULL
          ) lm ON lm.sender_id = u.id
       ORDER BY u.name ASC",
        ARRAY_A
      );

      $out = [];
      foreach ($rows ?? [] as $r) {
        $lastMsg = null;
        if (
          isset($r['last_content']) ||
          isset($r['last_room']) ||
          isset($r['last_recipient_id']) ||
          isset($r['last_kind'])
        ) {
          $lastMsg = [
            'text'            => (string) ($r['last_content'] ?? ''),
            'room'            => ($r['last_room'] ?? '') !== '' ? (string) $r['last_room'] : null,
            'to'              => isset($r['last_recipient_id']) ? (int) $r['last_recipient_id'] : null,
            'recipient_name'  => ($r['last_recipient_name'] ?? '') !== '' ? (string) $r['last_recipient_name'] : null,
            'kind'            => (string) ($r['last_kind'] ?? 'chat'),
            'time'            => isset($r['last_created_at']) ? (int) $r['last_created_at'] : null,
          ];
        }

        $out[] = [
          'id'           => (int) $r['id'],
          'name'         => (string) $r['name'],
          'gender'       => (string) ($r['gender'] ?? ''),
          'flagged'      => !empty($r['watch_flag']) ? 1 : 0,
          'is_admin'     => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
          'last_seen'    => (int) ($r['last_seen'] ?? 0),
          'last_message' => $lastMsg,
        ];
      }
      return kkchat_json($out);
    }

    // NON-ADMIN: minimal fields
    $rows = kkchat_public_presence_snapshot($now, $publicPresenceWindow, $admin_names, [
      'include_flagged' => false,
    ]);

    $out = array_map(function ($r) {
      return [
        'id'       => (int) ($r['id'] ?? 0),
        'name'     => (string) ($r['name'] ?? ''),
        'gender'   => (string) ($r['gender'] ?? ''),
        'is_admin' => !empty($r['is_admin']) ? 1 : 0,
      ];
    }, $rows ?? []);

    return kkchat_json($out);
  },
  // We gate inside with kkchat_require_login(); this stays open for REST discovery.
  'permission_callback' => '__return_true',
]);

register_rest_route($ns, '/ping', [
  // just add POST; keep everything else unchanged
  'methods'  => ['GET','POST'],
  'callback' => function () {
    // Auth & access checks (may read/write session)
    kkchat_require_login(false);
    kkchat_assert_not_blocked_or_fail();

    // Pings should never be cached
    nocache_headers();

    // Ensure/refresh my presence row (also ensures session knows my id)
    $uid = kkchat_touch_active_user(true, false);

    // Release the PHP session lock ASAP — ping is frequent
    kkchat_close_session_if_open();

    kkchat_wpdb_reconnect_if_needed();
    global $wpdb;
    $t   = kkchat_tables();
    $now = time();

    // Clear expired watch flags
    $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$t['users']}
            SET watch_flag = 0, watch_flag_at = NULL
          WHERE watch_flag = 1
            AND watch_flag_at IS NOT NULL
            AND %d - watch_flag_at > %d",
        $now,
        kkchat_watch_reset_after()
      )
    );

    // (Optional) light, probabilistic purge of very stale presences
    if (mt_rand(1, 20) === 1) {
      $wpdb->query(
        $wpdb->prepare(
          "DELETE FROM {$t['users']}
            WHERE %d - last_seen > %d",
          $now,
          kkchat_user_ttl()
        )
      );
    }

    // --- Admin-only: open report count + rising-edge anchor (cheap) ---
    $reports_open   = 0; // NEW
    $reports_max_id = 0; // NEW
    if (function_exists('kkchat_is_admin') && kkchat_is_admin()) {
      // COUNT(*) on status index; MAX(id) on PK — both fast
      $reports_open   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['reports']} WHERE status='open'");
      $reports_max_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$t['reports']}");
    }

    return kkchat_json([
      'ok'               => true,
      'uid'              => (int)$uid,
      'now'              => $now,
      'reports_open'     => $reports_open,     // NEW
      'reports_max_id'   => $reports_max_id,   // NEW
    ]);
  },
  'permission_callback' => '__return_true',
]);

register_rest_route($ns, '/rooms', [
  'methods'  => 'GET',
  'callback' => function () {
    kkchat_require_login();
    kkchat_assert_not_blocked_or_fail();

    // Rooms are mostly static — allow caching if you want.
    // (Leave nocache_headers() disabled to let client/proxy cache briefly.)
    // nocache_headers();

    // Refresh presence so this request counts as activity
    kkchat_touch_active_user();

    // Release the PHP session lock ASAP
    kkchat_close_session_if_open();

    global $wpdb;
    $t = kkchat_tables();

    // Simple read-only query
    $rows = $wpdb->get_results(
      "SELECT slug, title, member_only, sort
         FROM {$t['rooms']}
        ORDER BY sort ASC, title ASC",
      ARRAY_A
    );

    $guest = kkchat_is_guest();

    $out = array_map(function ($r) use ($guest) {
      $mo = ((int)$r['member_only'] === 1);
      return [
        'slug'        => (string)$r['slug'],
        'title'       => (string)$r['title'],
        'member_only' => $mo,
        'allowed'     => $guest ? !$mo : true,
      ];
    }, $rows ?? []);

    return kkchat_json($out);
  },
  'permission_callback' => '__return_true',
]);


register_rest_route($ns, '/fetch', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) {
    kkchat_require_login(); kkchat_assert_not_blocked_or_fail();
    nocache_headers();

    // Keep presence warm on every fetch
    kkchat_touch_active_user();

    // Unlock session before heavy DB work
    kkchat_close_session_if_open();

    global $wpdb; $t = kkchat_tables();
    $me    = kkchat_current_user_id();
    $since = max(-1, (int)$req->get_param('since'));
    $onlyPublic = $req->get_param('public') !== null;
    $roomParam  = kkchat_sanitize_room_slug((string)$req->get_param('room'));
    if ($roomParam === '') $roomParam = 'general';
    $peer = $req->get_param('to') !== null ? (int)$req->get_param('to') : null;

    // Soft cap to keep payloads small on first load
    $limit = (int)$req->get_param('limit');
    if ($limit <= 0) $limit = 200;                   // sane default
    $limit = max(1, min($limit, 200));               // 1..500

    $blocked = kkchat_blocked_ids($me);
    $msgColumns = 'id, room, sender_id, sender_name, recipient_id, recipient_name, content, created_at, kind, hidden_at';

    if ($onlyPublic) {
      if ($since < 0) {
        // First load: last N messages in the room (ASC order for display)
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT $msgColumns FROM {$t['messages']}
             WHERE recipient_id IS NULL
               AND room = %s
               AND hidden_at IS NULL
             ORDER BY id DESC
             LIMIT %d",
            $roomParam, $limit
          ),
          ARRAY_A
        ) ?: [];
        $rows = array_reverse($rows);
      } else {
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT $msgColumns FROM {$t['messages']}
             WHERE id > %d
               AND recipient_id IS NULL
               AND room = %s
               AND hidden_at IS NULL
             ORDER BY id ASC
             LIMIT %d",
            $since, $roomParam, $limit
          ),
          ARRAY_A
        ) ?: [];
      }
      if ($blocked) {
        $rows = array_values(array_filter($rows, function($r) use ($blocked){
          $sid = (int)$r['sender_id'];
          return !in_array($sid, $blocked, true);
        }));
      }
    } else {
      // DMs
      if ($peer) {
        if ($since < 0) {
          // Last N in thread with specific peer
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE hidden_at IS NULL
                 AND ((sender_id = %d AND recipient_id = %d) OR
                      (sender_id = %d AND recipient_id = %d))
               ORDER BY id DESC
               LIMIT %d",
              $me, $peer, $peer, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
          $rows = array_reverse($rows);
        } else {
          $rows = $wpdb->get_results(
            $wpdb->prepare(
              "SELECT $msgColumns FROM {$t['messages']}
               WHERE id > %d
                 AND hidden_at IS NULL
                 AND ((sender_id = %d AND recipient_id = %d)
                   OR  (sender_id = %d AND recipient_id = %d))
               ORDER BY id ASC
               LIMIT %d",
              $since, $me, $peer, $peer, $me, $limit
            ),
            ARRAY_A
          ) ?: [];
        }
      } else {
        // Legacy: all DMs to/from me (kept for backward compatibility)
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT $msgColumns FROM {$t['messages']}
             WHERE id > %d
               AND (recipient_id = %d OR sender_id = %d)
               AND hidden_at IS NULL
             ORDER BY id ASC
             LIMIT %d",
            $since, $me, $me, $limit
          ),
          ARRAY_A
        ) ?: [];
      }
      if ($blocked) {
        $rows = array_values(array_filter($rows, function($r) use ($blocked, $me){
          $sid = (int)$r['sender_id'];
          if ($sid === $me) return true;        // always show own messages
          return !in_array($sid, $blocked, true);
        }));
      }
    }

    $out = [];
    if ($rows) {
      foreach ($rows as $r) {
        $mid = (int)$r['id'];
        $out[] = [
          'id'           => $mid,
          'time'         => (int)$r['created_at'],
          'kind'         => $r['kind'] ?: 'chat',
          'room'         => $r['room'] ?: null,
          'sender_id'    => (int)$r['sender_id'],
          'sender_name'  => $r['sender_name'],
          'recipient_id' => isset($r['recipient_id']) ? (int)$r['recipient_id'] : null,
          'recipient_name'=> $r['recipient_name'] ?: null,
          'content'      => $r['content']
        ];
      }
    }
    kkchat_json($out);
  },
  'permission_callback' => '__return_true',
]);

  register_rest_route($ns, '/message', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_assert_not_blocked_or_fail(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();
      kkchat_wpdb_reconnect_if_needed();
      $me_id = kkchat_current_user_id();
      $me_nm = kkchat_current_user_name();

      // Kind: 'chat' (default) or 'image'
      $kind = (string)$req->get_param('kind');
      $kind = ($kind === 'image') ? 'image' : 'chat';

      $txt = '';
      $image_url = '';
      if ($kind === 'image') {
        $image_url = esc_url_raw((string)$req->get_param('image_url'));
        if ($image_url === '') kkchat_json(['ok'=>false,'err'=>'bad_image'], 400);

        // Validate URL is within WP uploads AND under /kkchat/ subdir
        $up = wp_upload_dir();
        $baseurl = rtrim((string)$up['baseurl'], '/');
        $basedir = rtrim((string)$up['basedir'], DIRECTORY_SEPARATOR);

        $subroot = (string)apply_filters('kkchat_upload_subdir', '/kkchat');
        $must_prefix = $baseurl . rtrim($subroot, '/') . '/';
        if (strpos($image_url, $must_prefix) !== 0) {
          kkchat_json(['ok'=>false,'err'=>'bad_image_scope'], 400);
        }
        if (strpos($image_url, $baseurl . '/') !== 0) {
          kkchat_json(['ok'=>false,'err'=>'bad_image_scope'], 400);
        }

        // Harden path resolution (guard against symlinks/traversal)
        $rel   = ltrim(substr($image_url, strlen($baseurl)), '/');
        $fpath = $basedir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $real_base = wp_normalize_path((string)realpath($basedir));
        $real_path = wp_normalize_path((string)realpath($fpath));
        if (!$real_path || !$real_base || strpos($real_path, trailingslashit($real_base)) !== 0) {
          kkchat_json(['ok'=>false,'err'=>'image_missing'], 400);
        }
        if (!file_exists($fpath)) kkchat_json(['ok'=>false,'err'=>'image_missing'], 400);

      } else {
        $txt = trim((string)$req->get_param('content'));
        if ($txt==='' || mb_strlen($txt) > 2000) kkchat_json(['ok'=>false], 400);
      }

      // === Auto moderation by Word Rules (non-admin only) ===
      if ($kind === 'chat' && !kkchat_is_admin()) {
        $rules = kkchat_rules_active();
        $hit_forbid = null; $hit_watch = null;
        foreach ($rules as $r){
          if (!kkchat_rule_matches($r, $txt)) continue;
          if ($r['kind']==='watch' && !$hit_watch) $hit_watch = $r;
          if ($r['kind']==='forbid' && !$hit_forbid) $hit_forbid = $r;
        }
        if ($hit_watch){
          $wpdb->update($t['users'], ['watch_flag'=>1,'watch_flag_at'=>time()], ['id'=>$me_id], ['%d','%d'], ['%d']);
        }
        if ($hit_forbid){
          $now = time();
          $admin = (string)($_SESSION['kkchat_wp_username'] ?? '');
          $cause = 'Forbidden word: "'.$hit_forbid['word'].'"';
          $dur   = $hit_forbid['duration_sec']; // NULL => infinite
          $ip    = kkchat_client_ip();

          if ($hit_forbid['action'] === 'kick'){
            $exp = isset($dur) ? ($now + max(60,(int)$dur)) : null;
            $wpdb->insert($t['blocks'], [
              'type'=>'kick',
              'target_user_id'=>$me_id,
              'target_name'=>$me_nm,
              'target_wp_username'=>$_SESSION['kkchat_wp_username'] ?? null,
              'target_ip'=>null,
              'cause'=>$cause,
              'created_by'=>$admin ?: null,
              'created_at'=>$now,
              'expires_at'=>$exp,
              'active'=>1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);
            if ($exp === null){ $id=(int)$wpdb->insert_id; $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at=NULL WHERE id=%d",$id)); }
          } elseif ($hit_forbid['action'] === 'ipban'){
            $exp = isset($dur) ? ($now + max(60,(int)$dur)) : null;
            $wpdb->insert($t['blocks'], [
              'type'=>'ipban',
              'target_user_id'=>$me_id,
              'target_name'=>$me_nm,
              'target_wp_username'=>$_SESSION['kkchat_wp_username'] ?? null,
              'target_ip'=>kkchat_ip_ban_key($ip),
              'cause'=>$cause,
              'created_by'=>$admin ?: null,
              'created_at'=>$now,
              'expires_at'=>$exp,
              'active'=>1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);
            if ($exp === null){ $id=(int)$wpdb->insert_id; $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at=NULL WHERE id=%d",$id)); }
          }

          // Remove presence and block the send
          $wpdb->delete($t['users'], ['id'=>$me_id], ['%d']);
          kkchat_json(['ok'=>false,'err'=>'auto_moderated','cause'=>$cause], 403);
        }
      }
      // === end automod ===

      // Minimal anti-flood: min interval between messages
      $minGap = max(0, kkchat_min_interval_seconds());
      if ($minGap > 0) {
        $now = time();
        $recent = (int)$wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$t['messages']} WHERE sender_id = %d AND created_at > %d",
          $me_id, $now - $minGap
        ));
        if ($recent > 0) {
          kkchat_json(
            ['ok' => false, 'err' => 'too_fast', 'cause' => 'Du skriver för snabbt. Försök igen strax.'],
            429
          );
        }
      }

      // DM or room
      $recipient = $req->get_param('recipient_id');
      $recipient = ($recipient !== null && $recipient!=='') ? (int)$recipient : null;
      if ($recipient === $me_id) $recipient = null;

      $room = null; $recipient_name = null; $recipient_ip = null;

      if ($recipient !== null) {
        // Respect my blocklist for DMs
        $mineBlocked = kkchat_blocked_ids($me_id);
        if (in_array($recipient, $mineBlocked, true)) {
          kkchat_json(['ok'=>false,'err'=>'blocked_peer'], 403);
        }

        $urow = $wpdb->get_row($wpdb->prepare("SELECT name, ip FROM {$t['users']} WHERE id=%d", $recipient), ARRAY_A);
        if (!$urow) kkchat_json(['ok'=>false], 400);
        $recipient_name = $urow['name'] ?? null;
        $recipient_ip   = $urow['ip']   ?? null;
      } else {
        $room = kkchat_sanitize_room_slug((string)$req->get_param('room'));
        if ($room === '') $room = 'general';
        if (!kkchat_can_access_room($room)) kkchat_json(['ok'=>false,'err'=>'no_room_access'], 403);
      }

      $now = time();
      $sender_ip = kkchat_client_ip();

      // Prepare content + dedupe basis
      if ($kind === 'image') {
        $content = esc_url_raw($image_url);
        $raw     = 'image:' . $content;
      } else {
        $content = $txt;
        $raw     = trim($txt);
      }

      // Strict, small dedupe window on exact content per-context
      $ctx = ($recipient !== null) ? ('dm:' . $recipient) : ('room:' . $room);
      $content_hash = sha1($ctx . '|' . $raw);
      $window = max(1, kkchat_dedupe_window());
      $dupe_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$t['messages']}
         WHERE sender_id=%d AND content_hash=%s AND created_at > %d
         ORDER BY id DESC LIMIT 1",
        $me_id, $content_hash, $now - max(1,$window)
      ));
      if ($dupe_id > 0) {
        // Auto-kick repeated duplicate attempts (non-admins)
        if (!kkchat_is_admin()) {
          if (!isset($_SESSION['kk_dupe'])) $_SESSION['kk_dupe'] = [];
          $key = $content_hash;

          $win   = kkchat_dupe_window_seconds();
          $fast  = kkchat_dupe_fast_seconds();
          $max   = kkchat_dupe_max_repeats();
          $mins  = kkchat_dupe_autokick_minutes();

          $rec = $_SESSION['kk_dupe'][$key] ?? ['n'=>0, 'first'=>$now];
          if ($now - $rec['first'] > $win) $rec = ['n'=>0, 'first'=>$now];
          $rec['n']++;
          $_SESSION['kk_dupe'][$key] = $rec;

          if ($mins > 0 && $rec['n'] >= $max && ($now - $rec['first']) <= $fast) {
            $exp = $now + max(60, $mins * 60);
            $admin = (string)($_SESSION['kkchat_wp_username'] ?? '');
            $cause = 'Repeat spam (auto)';

            $wpdb->insert($t['blocks'], [
              'type' => 'kick',
              'target_user_id' => $me_id,
              'target_name' => $me_nm,
              'target_wp_username' => $_SESSION['kkchat_wp_username'] ?? null,
              'target_ip' => null,
              'cause' => $cause,
              'created_by' => $admin ?: null,
              'created_at' => $now,
              'expires_at' => $exp,
              'active' => 1
            ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

            unset($_SESSION['kk_dupe'][$key]);
            $wpdb->delete($t['users'], ['id'=>$me_id], ['%d']);
            kkchat_json(['ok'=>false,'err'=>'auto_moderated','cause'=>$cause], 403);
          }
        }

        kkchat_json(['ok'=>true,'id'=>$dupe_id,'deduped'=>1]);
      }

      $data = [
        'created_at'   => $now,
        'sender_id'    => $me_id,
        'sender_name'  => $me_nm,
        'sender_ip'    => $sender_ip,
        'content_hash' => $content_hash,
        'content'      => $content,
        'kind'         => $kind
      ];
      $format = ['%d','%d','%s','%s','%s','%s','%s'];

      if ($recipient !== null) {
        $data['recipient_id']   = $recipient;
        $data['recipient_name'] = $recipient_name;
        $data['recipient_ip']   = $recipient_ip;
        $format[] = '%d'; $format[] = '%s'; $format[] = '%s';
      } else {
        $data['room'] = $room;
        $format[] = '%s';
      }

      $ok = $wpdb->insert($t['messages'], $data, $format);
      if ($ok === false) {
        kkchat_wpdb_reconnect_if_needed();
        $ok = $wpdb->insert($t['messages'], $data, $format);
      }
      if ($ok === false) kkchat_json(['ok'=>false,'err'=>'db_insert_failed'], 500);

      $mid = (int)$wpdb->insert_id;

    kkchat_json(['ok'=>true,'id'=>$mid]);

    },
    'permission_callback' => '__return_true',
  ]);

register_rest_route($ns, '/reads/mark', [
  'methods'  => 'POST',
  'callback' => function (WP_REST_Request $req) {
    kkchat_require_login();
    kkchat_assert_not_blocked_or_fail();
    kkchat_check_csrf_or_fail($req);   // ✅ require CSRF
    nocache_headers();

    // keep presence warm — but DON'T close the session yet (we'll write to $_SESSION below)
    kkchat_touch_active_user();

    global $wpdb;
    $t  = kkchat_tables();
    $me = (int) kkchat_current_user_id();
    if ($me <= 0) {
      return new WP_REST_Response(['ok' => false, 'error' => 'not_logged_in'], 401);
    }

    // Payload
    $dms           = $req->get_param('dms');                 // array of DM message IDs (legacy)
    $public_since  = (int) ($req->get_param('public_since') ?? 0); // server "now" watermark
    $dm_peer       = (int) ($req->get_param('dm_peer') ?? 0);
    $dm_last_id    = (int) ($req->get_param('dm_last_id') ?? 0);
    $room_slug_raw = (string) ($req->get_param('room_slug') ?? '');
    $room_slug     = kkchat_sanitize_room_slug($room_slug_raw);
    $room_last_id  = (int) ($req->get_param('room_last_id') ?? 0);

    $now         = time();
    $dmUpdates   = [];
    $roomUpdates = [];
    $roomSeenAt  = [];

    if ($dm_peer > 0 && $dm_last_id > 0) {
      $row = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT sender_id, recipient_id FROM {$t['messages']} WHERE id = %d LIMIT 1",
          $dm_last_id
        ),
        ARRAY_A
      );

      if ($row) {
        $sender    = (int) ($row['sender_id'] ?? 0);
        $recipient = (int) ($row['recipient_id'] ?? 0);
        $isPeer    = ($sender === $me && $recipient === $dm_peer) || ($sender === $dm_peer && $recipient === $me);
        if ($isPeer) {
          $dmUpdates[$dm_peer] = max($dmUpdates[$dm_peer] ?? 0, $dm_last_id);
        }
      }
    }

    if (is_array($dms) && !empty($dms)) {
      $ids = array_values(
        array_unique(
          array_filter(array_map('intval', $dms), fn($x) => $x > 0)
        )
      );

      if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
          $wpdb->prepare(
            "SELECT id, sender_id, recipient_id FROM {$t['messages']} WHERE id IN ($placeholders)",
            ...$ids
          ),
          ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
          $mid       = (int) ($row['id'] ?? 0);
          $sender    = (int) ($row['sender_id'] ?? 0);
          $recipient = (int) ($row['recipient_id'] ?? 0);
          if ($mid <= 0 || $recipient === 0 || $recipient === null) { continue; }

          if ($sender === $me && $recipient > 0) {
            $dmUpdates[$recipient] = max($dmUpdates[$recipient] ?? 0, $mid);
          } elseif ($recipient === $me && $sender > 0) {
            $dmUpdates[$sender] = max($dmUpdates[$sender] ?? 0, $mid);
          }
        }
      }
    }

    if (!empty($dmUpdates) && !empty($t['last_dm_reads'])) {
      foreach ($dmUpdates as $peer => $mid) {
        $peer = (int) $peer;
        $mid  = (int) $mid;
        if ($peer <= 0 || $mid <= 0) { continue; }

        $wpdb->query(
          $wpdb->prepare(
            "INSERT INTO {$t['last_dm_reads']} (user_id, peer_id, last_msg_id, updated_at)
             VALUES (%d,%d,%d,%d)
             ON DUPLICATE KEY UPDATE last_msg_id = GREATEST(last_msg_id, VALUES(last_msg_id)),
                                     updated_at = VALUES(updated_at)",
            $me,
            $peer,
            $mid,
            $now
          )
        );
      }
    }

    if ($room_slug !== '' && $room_last_id > 0) {
      $row = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT room, created_at FROM {$t['messages']} WHERE id = %d LIMIT 1",
          $room_last_id
        ),
        ARRAY_A
      );

      if ($row) {
        $room = (string) ($row['room'] ?? '');
        if ($room === $room_slug) {
          $roomUpdates[$room_slug] = max($roomUpdates[$room_slug] ?? 0, $room_last_id);
          $roomSeenAt[$room_slug]  = max($roomSeenAt[$room_slug] ?? 0, (int) ($row['created_at'] ?? 0));
        }
      }
    }

    if (!empty($roomUpdates) && !empty($t['last_reads'])) {
      foreach ($roomUpdates as $slug => $mid) {
        $slug = kkchat_sanitize_room_slug((string) $slug);
        $mid  = (int) $mid;
        if ($slug === '' || $mid <= 0) { continue; }

        $wpdb->query(
          $wpdb->prepare(
            "INSERT INTO {$t['last_reads']} (user_id, room_slug, last_msg_id, updated_at)
             VALUES (%d,%s,%d,%d)
             ON DUPLICATE KEY UPDATE last_msg_id = GREATEST(last_msg_id, VALUES(last_msg_id)),
                                     updated_at = VALUES(updated_at)",
            $me,
            $slug,
            $mid,
            $now
          )
        );

        $seenAt = (int) ($roomSeenAt[$slug] ?? 0);
        if ($seenAt > 0) {
          $prev = (int) ($_SESSION['kkchat_seen_at_public'] ?? 0);
          if ($seenAt > $prev) {
            $_SESSION['kkchat_seen_at_public'] = $seenAt;
          }
        }
      }
    }

    if ($public_since > 0) {
      $prev = (int) ($_SESSION['kkchat_seen_at_public'] ?? 0);
      if ($public_since > $prev) {
        $_SESSION['kkchat_seen_at_public'] = $public_since;
      }
    }

    // ✅ Now it's safe to release the session lock
    kkchat_close_session_if_open();

    return kkchat_json(['ok' => true]);
  },
  'permission_callback' => '__return_true',
]);


  /* =========================================================
   *                     User Reports
   * ========================================================= */

  register_rest_route($ns, '/report', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login();
      kkchat_check_csrf_or_fail($req);

      global $wpdb; $t = kkchat_tables();
      $me_id = kkchat_current_user_id();
      $me_nm = kkchat_current_user_name();
      if ($me_id <= 0 || $me_nm === '') kkchat_json(['ok'=>false,'err'=>'not_logged_in'], 403);

      $reported_id = max(0, (int)$req->get_param('reported_id'));
      $reason = trim((string)$req->get_param('reason'));
      if ($reported_id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_user'], 400);
      if ($reported_id === $me_id) kkchat_json(['ok'=>false,'err'=>'self_report'], 400);
      if ($reason === '' || mb_strlen($reason) > 1000) kkchat_json(['ok'=>false,'err'=>'bad_reason'], 400);

      $u = $wpdb->get_row($wpdb->prepare("SELECT id,name,ip FROM {$t['users']} WHERE id=%d", $reported_id), ARRAY_A);
      if (!$u) kkchat_json(['ok'=>false,'err'=>'user_gone'], 400);

      $now = time();
      $wpdb->insert($t['reports'], [
        'created_at'    => $now,
        'reporter_id'   => $me_id,
        'reporter_name' => $me_nm,
        'reporter_ip'   => kkchat_client_ip(),
        'reported_id'   => (int)$u['id'],
        'reported_name' => (string)$u['name'],
        'reported_ip'   => (string)($u['ip'] ?? ''),
        'reason'        => $reason,
        'status'        => 'open',
      ], ['%d','%d','%s','%s','%d','%s','%s','%s','%s']);

      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);
      kkchat_json(['ok'=>true]);
    },
    'permission_callback' => '__return_true',
  ]);
  // =========================================================
  // Admin: list open/resolved reports (default: open)
  // =========================================================
  register_rest_route($ns, '/reports', [
    'methods'  => 'GET',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login();
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $status = strtolower((string)($req->get_param('status') ?? 'open'));
      if (!in_array($status, ['open','resolved'], true)) $status = 'open';

      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, created_at, reporter_id, reporter_name,
                  reported_id, reported_name, reason
             FROM {$t['reports']}
            WHERE status = %s
            ORDER BY id DESC
            LIMIT 200",
          $status
        ),
        ARRAY_A
      );

      kkchat_json(['ok'=>true,'rows'=>array_map(static function($r){
        return [
          'id'            => (int)$r['id'],
          'created_at'    => (int)$r['created_at'],
          'reporter_id'   => (int)$r['reporter_id'],
          'reporter_name' => (string)$r['reporter_name'],
          'reported_id'   => (int)$r['reported_id'],
          'reported_name' => (string)$r['reported_name'],
          'reason'        => (string)$r['reason'],
        ];
      }, $rows)]);
    },
    'permission_callback' => '__return_true',
  ]);

  // =========================================================
  // Admin: resolve (open -> resolved). No "resolved_by" stored.
  // =========================================================
  register_rest_route($ns, '/reports/resolve', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_check_csrf_or_fail($req);
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $id  = max(0, (int)$req->get_param('id'));
      if ($id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);

      $now = time();
      $updated = $wpdb->update(
        $t['reports'],
        ['status'=>'resolved','resolved_at'=>$now],               // no "resolved_by"
        ['id'=>$id,'status'=>'open'],
        ['%s','%d'],
        ['%d','%s']
      );
      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);

      kkchat_json(['ok'=>true,'updated'=>(int)$updated]);
    },
    'permission_callback' => '__return_true',
  ]);

  // =========================================================
  // Admin: delete report permanently
  // =========================================================
  register_rest_route($ns, '/reports/delete', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_check_csrf_or_fail($req);
      if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'forbidden'], 403);

      global $wpdb; $t = kkchat_tables();
      $id = max(0, (int)$req->get_param('id'));
      if ($id <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);

      $deleted = $wpdb->delete($t['reports'], ['id'=>$id], ['%d']);
      if ($wpdb->last_error) kkchat_json(['ok'=>false,'err'=>'db'], 500);

      kkchat_json(['ok'=>true,'deleted'=>(int)$deleted]);
    },
    'permission_callback' => '__return_true',
  ]);

  /* =========================================================
   *                     Per-user block (uses kkchat.php helpers)
   * ========================================================= */

  // List my blocked IDs
  register_rest_route($ns, '/block/list', [
    'methods'  => 'GET',
    'callback' => function () {
      kkchat_require_login();
      nocache_headers();
      kkchat_close_session_if_open();
      $ids = kkchat_blocked_ids(kkchat_current_user_id());
      kkchat_json(['ok'=>true, 'ids'=>$ids]);
    },
    'permission_callback' => '__return_true',
  ]);

  // Toggle block/unblock target (server-enforced: admins can't be blocked)
  register_rest_route($ns, '/block/toggle', [
    'methods'  => 'POST',
    'callback' => function (WP_REST_Request $req) {
      kkchat_require_login(); kkchat_check_csrf_or_fail($req);
      $target = max(0, (int)$req->get_param('target_id'));
      if ($target <= 0) kkchat_json(['ok'=>false,'err'=>'bad_target'], 400);

      // Hard block: if target is an admin (by ID), forbid immediately
      if (kkchat_is_admin_id($target)) {
        kkchat_json(['ok'=>false,'err'=>'cant_block_admin'], 403);
      }

      // Toggle using server-only logic (kkchat_block_add checks admin again)
      $res = kkchat_block_toggle($target);
      if (!empty($res['ok']) && array_key_exists('now_blocked', $res)) {
        kkchat_json(['ok'=>true, 'now_blocked'=>!empty($res['now_blocked'])]);
      }

      // Map helper errors to HTTP
      $err = (string)($res['err'] ?? 'error');
      if ($err === 'cant_block_admin') kkchat_json(['ok'=>false,'err'=>$err], 403);
      if ($err === 'not_logged_in' || $err === 'self_block') kkchat_json(['ok'=>false,'err'=>$err], 400);
      kkchat_json(['ok'=>false,'err'=>$err], 400);
    },
    'permission_callback' => '__return_true',
  ]);

  /* =========================================================
   *                     Moderation (admins only)
   * ========================================================= */

  $require_admin = function() {
    kkchat_require_login();
    if (!kkchat_is_admin()) kkchat_json(['ok'=>false,'err'=>'not_admin'], 403);
  };

    // Hide a message (admins only)
register_rest_route($ns, '/moderate/hide-message', [
  'methods'  => 'POST',
  'callback' => function (WP_REST_Request $req) use ($require_admin) {
    $require_admin(); kkchat_check_csrf_or_fail($req);
    global $wpdb; $t = kkchat_tables();

    $mid = (int) $req->get_param('message_id');
    if ($mid <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);

    // Fetch the message so we know if it's public (room) or a DM (sender/recipient)
    $msg = $wpdb->get_row($wpdb->prepare(
      "SELECT id, room, sender_id, recipient_id FROM {$t['messages']} WHERE id = %d",
      $mid
    ), ARRAY_A);
    if (!$msg) kkchat_json(['ok'=>false,'err'=>'no_message'], 404);

    $cause = sanitize_text_field((string)$req->get_param('cause'));

    // Mark as hidden
    $wpdb->query($wpdb->prepare(
      "UPDATE {$t['messages']}
          SET hidden_at = %d,
              hidden_by = %d,
              hidden_cause = %s
        WHERE id = %d",
      time(), get_current_user_id() ?: 0, ($cause !== '' ? $cause : null), $mid
    ));

    // Emit an invisible moderation event to wake long-poll clients
    // Clients must ignore kind 'mod_hide' and use its content payload to remove the message.
    if (empty($msg['recipient_id'])) {
      // Public message (room)
      $wpdb->insert($t['messages'], [
        'created_at'     => time(),
        'kind'           => 'mod_hide',
        'room'           => $msg['room'],
        'sender_id'      => 0,          // system
        'sender_name'    => '',
        'recipient_id'   => null,
        'recipient_name' => null,
        'content'        => wp_json_encode(['id' => (int)$mid, 'action' => 'hide']),
      ]);
    } else {
      // Direct message: insert into the same sender/recipient channel so both sides wake up
      $wpdb->insert($t['messages'], [
        'created_at'     => time(),
        'kind'           => 'mod_hide',
        'room'           => null,
        'sender_id'      => (int)$msg['sender_id'],
        'sender_name'    => '',
        'recipient_id'   => (int)$msg['recipient_id'],
        'recipient_name' => null,
        'content'        => wp_json_encode(['id' => (int)$mid, 'action' => 'hide']),
      ]);
    }

    kkchat_json(['ok'=>true]);
  },
]);
    
    // Unhide a message (admins only)
    register_rest_route($ns, '/moderate/unhide-message', [
      'methods'  => 'POST',
      'callback' => function (WP_REST_Request $req) use ($require_admin) {
        $require_admin(); kkchat_check_csrf_or_fail($req);
        global $wpdb; $t = kkchat_tables();
    
        $mid = (int) $req->get_param('message_id');
        if ($mid <= 0) kkchat_json(['ok'=>false,'err'=>'bad_id'], 400);
    
        $exists = (int)$wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$t['messages']} WHERE id = %d", $mid
        ));
        if ($exists === 0) kkchat_json(['ok'=>false,'err'=>'no_message'], 404);
    
        // Use raw SQL so NULLs are truly NULL (wpdb->update can coerce)
        $wpdb->query($wpdb->prepare(
          "UPDATE {$t['messages']}
              SET hidden_at = NULL,
                  hidden_by = NULL,
                  hidden_cause = NULL
            WHERE id = %d",
          $mid
        ));
    
        kkchat_json(['ok'=>true]);
      },
    ]);

  register_rest_route($ns, '/moderate/kick', [
    'methods'=>'POST',
    'callback'=>function(WP_REST_Request $req) use ($require_admin) {
      $require_admin(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();
      $uid = (int)$req->get_param('user_id');
      $minutes = max(1, (int)$req->get_param('minutes'));
      $cause = trim((string)$req->get_param('cause'));
      $admin = (string)($_SESSION['kkchat_wp_username'] ?? '');

      $u = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['users']} WHERE id=%d", $uid), ARRAY_A);
      if (!$u) kkchat_json(['ok'=>false,'err'=>'no_user'], 400);

      $now = time();
      $exp = $now + $minutes*60;

      $wpdb->insert($t['blocks'], [
        'type'=>'kick',
        'target_user_id'=>$uid,
        'target_name'=>$u['name'] ?? null,
        'target_wp_username'=>$u['wp_username'] ?? null,
        'target_ip'=>null,
        'cause'=>$cause ?: null,
        'created_by'=>$admin ?: null,
        'created_at'=>$now,
        'expires_at'=>$exp,
        'active'=>1
      ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

      // Drop presence immediately
      $wpdb->delete($t['users'], ['id'=>$uid], ['%d']);

      kkchat_json(['ok'=>true]);
    },
    'permission_callback'=>'__return_true',
  ]);

  register_rest_route($ns, '/moderate/ipban', [
    'methods'=>'POST',
    'callback'=>function(WP_REST_Request $req) use ($require_admin) {
      $require_admin(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();

      $uid     = (int)$req->get_param('user_id');
      $minutes = (int)$req->get_param('minutes'); // 0 => forever
      $cause   = trim((string)$req->get_param('cause'));
      $admin   = (string)($_SESSION['kkchat_wp_username'] ?? '');

      $u = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['users']} WHERE id=%d", $uid), ARRAY_A);
      if (!$u || empty($u['ip'])) kkchat_json(['ok'=>false,'err'=>'no_ip'], 400);

      $now = time();
      $exp = ($minutes > 0) ? ($now + $minutes*60) : null;

      $wpdb->insert($t['blocks'], [
        'type'               => 'ipban',
        'target_user_id'     => $uid,
        'target_name'        => $u['name'] ?? null,
        'target_wp_username' => $u['wp_username'] ?? null,
        'target_ip'          => kkchat_ip_ban_key($u['ip']),
        'cause'              => $cause ?: null,
        'created_by'         => $admin ?: null,
        'created_at'         => $now,
        'expires_at'         => $exp,  // may be null
        'active'             => 1
      ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

      $id = (int)$wpdb->insert_id;
      if ($exp === null) {
        $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at = NULL WHERE id=%d", $id));
      }

      $wpdb->delete($t['users'], ['id'=>$uid], ['%d']);

      kkchat_json(['ok'=>true]);
    },
    'permission_callback'=>'__return_true',
  ]);

  register_rest_route($ns, '/moderate/unblock', [
    'methods'=>'POST',
    'callback'=>function(WP_REST_Request $req) use ($require_admin) {
      $require_admin(); kkchat_check_csrf_or_fail($req);
      global $wpdb; $t = kkchat_tables();
      $id = (int)$req->get_param('block_id');
      $wpdb->update($t['blocks'], ['active'=>0], ['id'=>$id], ['%d'], ['%d']);
      kkchat_json(['ok'=>true]);
    },
    'permission_callback'=>'__return_true',
  ]);

  // Admin: fetch a user's messages (for the receipt overlay)
register_rest_route($ns, '/admin/user-messages', [
  'methods'  => 'GET',
  'callback' => function (WP_REST_Request $req) use ($require_admin) {
    // Auth (may touch/read session)
    $require_admin();

    // This is a read-only, potentially heavy GET — don’t cache and free the session lock
    nocache_headers();
    kkchat_close_session_if_open();

    global $wpdb; $t = kkchat_tables();

    $uid    = max(0, (int)$req->get_param('user_id'));
    $name   = trim((string)$req->get_param('name'));
    $limit  = max(20, min(500, (int)($req->get_param('limit') ?: 200)));
    $before = max(0, (int)$req->get_param('before_id'));

    if ($uid === 0 && $name === '') kkchat_json(['ok'=>false,'err'=>'need_user'], 400);

    $where  = [];
    $params = [];

    if ($uid > 0) {
      $where[]  = "(sender_id = %d OR recipient_id = %d)";
      $params[] = $uid; $params[] = $uid;
    }
    if ($name !== '') {
      $where[]  = "(sender_name = %s OR recipient_name = %s)";
      $params[] = $name; $params[] = $name;
    }
    if ($before > 0) {
      $where[]  = "id < %d";
      $params[] = $before;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', array_map(fn($w)=>"($w)", $where))) : '';

    $sql = "SELECT id,created_at,kind,room,
                   sender_id,sender_name,sender_ip,
                   recipient_id,recipient_name,recipient_ip,
                   content
              FROM {$t['messages']}
              $whereSql
          ORDER BY id DESC
             LIMIT %d";

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$limit])), ARRAY_A) ?: [];

    $out = array_map(function($m){
      return [
        'id'             => (int)$m['id'],
        'time'           => (int)$m['created_at'],
        'kind'           => $m['kind'] ?: 'chat',
        'room'           => $m['room'] ?: null,
        'sender_id'      => (int)$m['sender_id'],
        'sender_name'    => (string)$m['sender_name'],
        'sender_ip'      => $m['sender_ip'] ?: null,
        'recipient_id'   => isset($m['recipient_id']) ? (int)$m['recipient_id'] : null,
        'recipient_name' => $m['recipient_name'] ?: null,
        'recipient_ip'   => $m['recipient_ip'] ?: null,
        'content'        => $m['content'],
      ];
    }, $rows);

    $next_before = (count($rows) === $limit) ? (int)end($rows)['id'] : null;

    kkchat_json(['ok'=>true,'rows'=>$out,'next_before'=>$next_before]);
  },
  'permission_callback' => '__return_true',
]);

});