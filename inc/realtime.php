<?php
if (!defined('ABSPATH')) exit;

function kkchat_websocket_base_url(): ?string {
  $configured = apply_filters('kkchat_websocket_url', defined('KKCHAT_WEBSOCKET_URL') ? KKCHAT_WEBSOCKET_URL : null);
  if ($configured === false) { return null; }
  if (is_string($configured) && trim($configured) !== '') {
    return trim($configured);
  }

  $home = home_url();
  if (!$home) { return null; }
  $host = parse_url($home, PHP_URL_HOST);
  if (!$host) { return null; }
  $scheme = is_ssl() ? 'wss' : 'ws';
  $port = apply_filters('kkchat_websocket_port', null);
  $authority = $host;
  if ($port !== null && $port !== '' && (int)$port > 0) {
    $port = (int)$port;
    if (!($scheme === 'ws' && $port === 80) && !($scheme === 'wss' && $port === 443)) {
      $authority .= ':' . $port;
    }
  }
  $path = trim(apply_filters('kkchat_websocket_path', 'kkchat'), '/');
  return sprintf('%s://%s/%s', $scheme, $authority, $path);
}

function kkchat_realtime_enabled(): bool {
  return kkchat_websocket_base_url() !== null;
}

function kkchat_realtime_token_ttl(): int {
  $ttl = (int) apply_filters('kkchat_websocket_token_ttl', 90);
  return max(30, $ttl);
}

function kkchat_realtime_sanitize_channel(string $channel): string {
  $channel = strtolower(trim($channel));
  $channel = preg_replace('~[^a-z0-9:_\-]~', '', $channel);
  if ($channel === '') { $channel = 'global'; }
  if (strlen($channel) > 190) {
    $channel = substr($channel, 0, 190);
  }
  return $channel;
}

function kkchat_realtime_url_with_token(string $token): ?string {
  $base = kkchat_websocket_base_url();
  if (!$base) { return null; }
  $sep = str_contains($base, '?') ? '&' : '?';
  return $base . $sep . 'token=' . rawurlencode($token);
}

function kkchat_realtime_issue_token(int $userId, ?array $channels = null, array $meta = []): ?array {
  if ($userId <= 0) { return null; }
  if (!kkchat_realtime_enabled()) { return null; }

  if ($channels === null || empty($channels)) {
    $channels = ['global'];
  }
  $channels = array_values(array_unique(array_map('kkchat_realtime_sanitize_channel', $channels)));
  if (empty($channels)) {
    $channels = ['global'];
  }

  try {
    $token = bin2hex(random_bytes(16));
  } catch (\Exception $e) {
    $token = bin2hex(openssl_random_pseudo_bytes(16));
  }

  $ttl = kkchat_realtime_token_ttl();
  $now = time();
  $data = [
    'user_id'    => $userId,
    'channels'   => $channels,
    'meta'       => $meta,
    'issued_at'  => $now,
    'expires_at' => $now + $ttl,
  ];

  set_transient('kkchat_ws_token_' . $token, $data, $ttl);

  return [
    'token'    => $token,
    'url'      => kkchat_realtime_url_with_token($token),
    'channels' => $channels,
    'expires'  => $data['expires_at'],
  ];
}

function kkchat_realtime_take_token(string $token): ?array {
  if ($token === '') { return null; }
  $key = 'kkchat_ws_token_' . $token;
  $data = get_transient($key);
  if ($data !== false) {
    delete_transient($key);
  }
  return is_array($data) ? $data : null;
}

function kkchat_realtime_events_table(): ?string {
  $tables = kkchat_tables();
  return $tables['realtime_events'] ?? null;
}

function kkchat_realtime_publish(string $channel, array $payload): void {
  if (!kkchat_realtime_enabled()) { return; }
  global $wpdb;
  $table = kkchat_realtime_events_table();
  if (!$table) { return; }

  $channel = kkchat_realtime_sanitize_channel($channel);
  $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) { $json = '{}'; }

  $wpdb->insert($table, [
    'channel'    => $channel,
    'payload'    => $json,
    'created_at' => time(),
  ], ['%s','%s','%d']);

  kkchat_realtime_prune_events();
}

function kkchat_realtime_prune_events(): void {
  global $wpdb;
  $table = kkchat_realtime_events_table();
  if (!$table) { return; }

  $maxAge = (int) apply_filters('kkchat_realtime_max_age', 180);
  if ($maxAge > 0) {
    $cutoff = time() - $maxAge;
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %d", $cutoff));
  }

  $maxRows = (int) apply_filters('kkchat_realtime_max_rows', 2000);
  if ($maxRows > 0) {
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    if ($count > $maxRows) {
      $excess = $count - $maxRows;
      $excess = max(0, (int)$excess);
      if ($excess > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("DELETE FROM {$table} ORDER BY id ASC LIMIT {$excess}");
      }
    }
  }
}

function kkchat_realtime_last_event_id(): int {
  global $wpdb;
  $table = kkchat_realtime_events_table();
  if (!$table) { return 0; }
  $val = $wpdb->get_var("SELECT MAX(id) FROM {$table}");
  return $val ? (int)$val : 0;
}

function kkchat_realtime_fetch_events_since(int $id, int $limit = 200): array {
  global $wpdb;
  $table = kkchat_realtime_events_table();
  if (!$table) { return []; }
  $limit = max(1, min(500, (int)$limit));
  $rows = $wpdb->get_results(
    $wpdb->prepare("SELECT id, channel, payload, created_at FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $id, $limit),
    ARRAY_A
  );
  if (!$rows) { return []; }
  $out = [];
  foreach ($rows as $row) {
    $payload = json_decode((string)($row['payload'] ?? ''), true);
    if (!is_array($payload)) { $payload = []; }
    $out[] = [
      'id'         => (int)($row['id'] ?? 0),
      'channel'    => kkchat_realtime_sanitize_channel((string)($row['channel'] ?? 'global')),
      'payload'    => $payload,
      'created_at' => (int)($row['created_at'] ?? time()),
    ];
  }
  return $out;
}

function kkchat_realtime_authorize_channel(int $userId, string $channel, array $meta = []): bool {
  $channel = kkchat_realtime_sanitize_channel($channel);
  if ($channel === 'global') { return true; }
  if (str_starts_with($channel, 'room:')) {
    $slug = substr($channel, strlen('room:'));
    if ($slug === '') { return false; }
    $room = kkchat_get_room($slug);
    if (!$room) { return false; }
    $isGuest = isset($meta['is_guest']) ? (bool)$meta['is_guest'] : kkchat_is_guest();
    if (!empty($room['member_only']) && $isGuest) { return false; }
    return true;
  }
  if (str_starts_with($channel, 'dm:')) {
    // dm:peerId
    $peer = (int) substr($channel, strlen('dm:'));
    if ($peer <= 0) { return false; }
    // Allow if user is peer or meta allows global DM view (admins)
    if ($peer === $userId) { return true; }
    if (!empty($meta['allow_dm_all'])) { return true; }
    return false;
  }
  return false;
}

