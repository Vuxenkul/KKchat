<?php
if (!defined('ABSPATH')) exit;

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

      $cols = ['id','name','gender','wp_username','is_hidden'];
      if ($includeFlagged) { $cols[] = 'watch_flag'; }

      $select = implode(',', $cols);

      $cacheTtlOption = (int) get_option('kkchat_public_presence_cache_ttl', 2);
      $cacheTtl = max(0, (int) apply_filters('kkchat_public_presence_cache_ttl', $cacheTtlOption));
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
              AND is_hidden = 0
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
            WHERE is_hidden = 0
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

  if (!function_exists('kkchat_public_presence_cache_flush')) {
    function kkchat_public_presence_cache_flush(): void {
      if (!function_exists('wp_cache_delete')) { return; }

      $ttlOption = (int) get_option('kkchat_public_presence_cache_ttl', 2);
      $ttl = max(0, (int) apply_filters('kkchat_public_presence_cache_ttl', $ttlOption));
      if ($ttl <= 0) { return; }

      $bucketSize = max(1, $ttl);
      $now = time();
      $buckets = array_unique([
        (int) floor($now / $bucketSize),
        (int) floor(($now - 1) / $bucketSize),
      ]);

      $window = max(0, (int) apply_filters('kkchat_public_presence_window', 120));
      $limit  = max(1, (int) apply_filters('kkchat_public_presence_limit', 400));

      foreach ([false, true] as $includeFlagged) {
        $flagKey = $includeFlagged ? 'f1' : 'f0';
        foreach ($buckets as $bucket) {
          $cacheKey = sprintf('presence:%s:%d:%d:%d', $flagKey, $window, $limit, $bucket);
          wp_cache_delete($cacheKey, 'kkchat');
        }
      }
    }
  }

  if (!function_exists('kkchat_admin_presence_normalize_opts')) {
    function kkchat_admin_presence_normalize_opts(array $opts = []): array {
      $defaults = [
        'active_window'   => max(30, (int) apply_filters('kkchat_presence_active_sec', 60)),
        'limit'           => max(50, (int) apply_filters('kkchat_presence_limit', 1200)),
        'order_column'    => 'name_lc',
        'order_direction' => 'ASC',
      ];

      $cfg = array_merge($defaults, $opts);

      $cfg['active_window'] = max(0, (int) ($cfg['active_window'] ?? 0));
      $cfg['limit']         = max(0, (int) ($cfg['limit'] ?? 0));

      $allowedColumns = ['name_lc', 'name', 'last_seen'];
      if (!in_array($cfg['order_column'], $allowedColumns, true)) {
        $cfg['order_column'] = 'name_lc';
      }

      $dir = strtoupper((string) ($cfg['order_direction'] ?? 'ASC'));
      $cfg['order_direction'] = in_array($dir, ['ASC', 'DESC'], true) ? $dir : 'ASC';

      return $cfg;
    }
  }

  if (!function_exists('kkchat_admin_presence_cache_key')) {
    function kkchat_admin_presence_cache_key(array $cfg, int $bucket): string {
      $direction = strtolower($cfg['order_direction'] ?? 'asc');
      return sprintf(
        'presence_admin:%d:%d:%s:%s:%d',
        (int) ($cfg['active_window'] ?? 0),
        (int) ($cfg['limit'] ?? 0),
        (string) ($cfg['order_column'] ?? 'name_lc'),
        $direction,
        $bucket
      );
    }
  }

  if (!function_exists('kkchat_admin_presence_cache_flush')) {
    function kkchat_admin_presence_cache_flush(?array $opts = null): void {
      if (!function_exists('wp_cache_delete')) { return; }

      $ttlOption = (int) get_option('kkchat_admin_presence_cache_ttl', 2);
      $ttl = max(0, (int) apply_filters('kkchat_admin_presence_cache_ttl', $ttlOption));
      if ($ttl <= 0) { return; }

      $bucketSize = max(1, $ttl);
      $now = time();
      $buckets = array_unique([
        (int) floor($now / $bucketSize),
        (int) floor(($now - 1) / $bucketSize),
      ]);

      $variants = [];
      if ($opts === null) {
        $variants = [
          kkchat_admin_presence_normalize_opts([]),
          kkchat_admin_presence_normalize_opts([
            'active_window' => 0,
            'limit'         => 0,
            'order_column'  => 'name',
          ]),
        ];
        $variants = apply_filters('kkchat_admin_presence_cache_flush_variants', $variants);
      } else {
        $variants = [kkchat_admin_presence_normalize_opts($opts)];
      }

      foreach ($variants as $cfg) {
        foreach ($buckets as $bucket) {
          wp_cache_delete(kkchat_admin_presence_cache_key($cfg, $bucket), 'kkchat');
        }
      }
    }
  }

  if (!function_exists('kkchat_admin_presence_snapshot')) {
    /**
     * Admin-only presence payload (includes latest-message metadata).
     * Results are cached briefly to smooth repeated sync polls.
     *
     * Filters:
     *   - kkchat_admin_presence_cache_ttl: seconds to retain the cached snapshot (default 2s).
     *   - kkchat_admin_presence_cache_flush_variants: adjust cache-flush variants when busting.
     */
    function kkchat_admin_presence_snapshot(int $now, array $admin_names, array $opts = []): array {
      kkchat_wpdb_reconnect_if_needed();
      global $wpdb; $t = kkchat_tables();

      $cfg = kkchat_admin_presence_normalize_opts($opts);

      $cacheTtlOption = (int) get_option('kkchat_admin_presence_cache_ttl', 2);
      $cacheTtl = max(0, (int) apply_filters('kkchat_admin_presence_cache_ttl', $cacheTtlOption));
      $cacheKey = null;
      if ($cacheTtl > 0 && function_exists('wp_cache_get')) {
        $bucketSize = max(1, $cacheTtl);
        $cacheKey   = kkchat_admin_presence_cache_key($cfg, (int) floor($now / $bucketSize));
        $cached     = wp_cache_get($cacheKey, 'kkchat');
        if (is_array($cached)) {
          return $cached;
        }
      }

      $sql = "SELECT u.id,
                     u.name,
                     u.gender,
                     u.watch_flag,
                     u.is_hidden,
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
               ) lm ON lm.sender_id = u.id";

      $clauses = [];
      if ($cfg['active_window'] > 0) {
        $clauses[] = $wpdb->prepare('%d - u.last_seen <= %d', $now, $cfg['active_window']);
      }
      if ($clauses) {
        $sql .= ' WHERE ' . implode(' AND ', $clauses);
      }

      $sql .= sprintf(' ORDER BY u.%s %s', $cfg['order_column'], $cfg['order_direction']);

      if ($cfg['limit'] > 0) {
        $sql .= $wpdb->prepare(' LIMIT %d', $cfg['limit']);
      }

      $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
      $out  = [];

      foreach ($rows as $r) {
        $lastMsg = null;
        if (
          isset($r['last_content']) ||
          isset($r['last_room']) ||
          isset($r['last_recipient_id']) ||
          isset($r['last_kind'])
        ) {
          $lastMsg = [
            'text'           => (string) ($r['last_content'] ?? ''),
            'room'           => ($r['last_room'] ?? '') !== '' ? (string) $r['last_room'] : null,
            'to'             => isset($r['last_recipient_id']) ? (int) $r['last_recipient_id'] : null,
            'recipient_name' => ($r['last_recipient_name'] ?? '') !== '' ? (string) $r['last_recipient_name'] : null,
            'kind'           => (string) ($r['last_kind'] ?? 'chat'),
            'time'           => isset($r['last_created_at']) ? (int) $r['last_created_at'] : null,
          ];
        }

        $out[] = [
          'id'           => (int) ($r['id'] ?? 0),
          'name'         => (string) ($r['name'] ?? ''),
          'gender'       => (string) ($r['gender'] ?? ''),
          'flagged'      => !empty($r['watch_flag']) ? 1 : 0,
          'hidden'       => !empty($r['is_hidden']) ? 1 : 0,
          'is_admin'     => (!empty($r['wp_username']) && in_array(strtolower($r['wp_username']), $admin_names, true)) ? 1 : 0,
          'last_seen'    => (int) ($r['last_seen'] ?? 0),
          'last_message' => $lastMsg,
        ];
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

  if (!function_exists('kkchat_sync_metrics_defaults')) {
    function kkchat_sync_metrics_defaults(): array {
      return [
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
      ];
    }
  }

  if (!function_exists('kkchat_sync_metrics_state')) {
    function &kkchat_sync_metrics_state(): array {
      static $metrics = null;
      if ($metrics === null) {
        $stored = get_option('kkchat_sync_metrics');
        if (!is_array($stored)) { $stored = []; }
        $metrics = array_merge(kkchat_sync_metrics_defaults(), $stored);
      }
      return $metrics;
    }
  }

  if (!function_exists('kkchat_sync_metrics_dirty_flag')) {
    function kkchat_sync_metrics_dirty_flag(?bool $set = null): bool {
      static $dirty = false;
      if ($set !== null) {
        $dirty = (bool) $set;
      }
      return $dirty;
    }
  }

  if (!function_exists('kkchat_sync_metrics_register_flush')) {
    function kkchat_sync_metrics_register_flush(): void {
      static $registered = false;
      if ($registered) { return; }
      $registered = true;
      register_shutdown_function('kkchat_sync_metrics_flush_now');
    }
  }

  if (!function_exists('kkchat_sync_metrics_flush_now')) {
    function kkchat_sync_metrics_flush_now(): void {
      if (!kkchat_sync_metrics_dirty_flag()) {
        return;
      }
      $metrics = kkchat_sync_metrics_get();
      update_option('kkchat_sync_metrics', $metrics, false);
      kkchat_sync_metrics_dirty_flag(false);
    }
  }

  if (!function_exists('kkchat_sync_metrics_get')) {
    function kkchat_sync_metrics_get(): array {
      $state = kkchat_sync_metrics_state();
      return $state;
    }
  }

  if (!function_exists('kkchat_sync_metrics_store')) {
    function kkchat_sync_metrics_store(array $metrics): void {
      $state =& kkchat_sync_metrics_state();
      $state = array_merge(kkchat_sync_metrics_defaults(), $metrics);
      kkchat_sync_metrics_dirty_flag(true);
      kkchat_sync_metrics_register_flush();
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
      $deleted = (int) $wpdb->query($wpdb->prepare(
        "DELETE FROM {$t['users']} WHERE %d - last_seen > %d",
        $now,
        kkchat_user_ttl()
      ));
      $cleared = (int) $wpdb->query($wpdb->prepare(
        "UPDATE {$t['users']}\n            SET watch_flag = 0, watch_flag_at = NULL\n          WHERE watch_flag = 1\n            AND watch_flag_at IS NOT NULL\n            AND %d - watch_flag_at > %d",
        $now,
        kkchat_watch_reset_after()
      ));

      if ($deleted > 0 || $cleared > 0) {
        kkchat_admin_presence_cache_flush();
      }
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
      if ($is_admin_viewer) {
        $presence = kkchat_admin_presence_snapshot($now, $admin_names);
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
      $msgColumns = 'id, room, sender_id, sender_name, recipient_id, recipient_name, content, is_explicit, created_at, kind, hidden_at, reply_to_id, reply_to_sender_id, reply_to_sender_name, reply_to_excerpt';
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
              'id'                   => $mid,
              'time'                 => (int) $r['created_at'],
              'kind'                 => $r['kind'] ?: 'chat',
              'room'                 => $r['room'] ?: null,
              'sender_id'            => (int) $r['sender_id'],
              'sender_name'          => (string) $r['sender_name'],
              'recipient_id'         => isset($r['recipient_id']) ? (int) $r['recipient_id'] : null,
              'recipient_name'       => $r['recipient_name'] ?: null,
              'content'              => $r['content'],
              'is_explicit'          => !empty($r['is_explicit']),
              'reply_to_id'          => isset($r['reply_to_id']) ? (int) $r['reply_to_id'] : null,
              'reply_to_sender_id'   => isset($r['reply_to_sender_id']) ? (int) $r['reply_to_sender_id'] : null,
              'reply_to_sender_name' => $r['reply_to_sender_name'] ?: null,
              'reply_to_excerpt'     => $r['reply_to_excerpt'] ?: null,
              'read_by'              => isset($read_map[$mid]) ? array_values(array_map('intval', $read_map[$mid])) : [],
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
