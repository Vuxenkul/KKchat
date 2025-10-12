#!/usr/bin/env php
<?php
declare(strict_types=1);

$longOpts = [
  'wp-root:',
  'host::',
  'port::',
  'poll::',
];
$options = getopt('', $longOpts);
$wpRoot = $options['wp-root'] ?? getenv('WP_ROOT');
if (!$wpRoot) {
  fwrite(STDERR, "Usage: realtime-server.php --wp-root=/path/to/wordpress [--host=0.0.0.0] [--port=9503]\n");
  exit(1);
}
$wpRoot = rtrim((string)$wpRoot, DIRECTORY_SEPARATOR);
$wpLoad = $wpRoot . DIRECTORY_SEPARATOR . 'wp-load.php';
if (!file_exists($wpLoad)) {
  fwrite(STDERR, "wp-load.php not found at {$wpLoad}\n");
  exit(1);
}

require_once $wpLoad;

if (!function_exists('kkchat_realtime_enabled')) {
  require_once __DIR__ . '/../kkchat.php';
}
require_once __DIR__ . '/../inc/realtime.php';

$host = $options['host'] ?? getenv('KKCHAT_WS_HOST') ?? '0.0.0.0';
$port = (int) ($options['port'] ?? getenv('KKCHAT_WS_PORT') ?? 9503);
if ($port <= 0) { $port = 9503; }
$pollInterval = (float) ($options['poll'] ?? getenv('KKCHAT_WS_POLL') ?? 0.5);
if ($pollInterval <= 0) { $pollInterval = 0.5; }

$socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
if (!$socket) {
  fwrite(STDERR, "Failed to bind {$host}:{$port} ({$errno}) {$errstr}\n");
  exit(1);
}
stream_set_blocking($socket, false);

$clients = [];
$running = true;
$lastEventId = kkchat_realtime_last_event_id();
$lastPoll = microtime(true);
$lastPing = microtime(true);
$pingInterval = (int) apply_filters('kkchat_websocket_server_ping_interval', 20);
if ($pingInterval <= 5) { $pingInterval = 20; }
$idleTimeout = (int) apply_filters('kkchat_websocket_server_idle_timeout', 90);
if ($idleTimeout <= 10) { $idleTimeout = 90; }

if (function_exists('pcntl_async_signals')) {
  pcntl_async_signals(true);
  $handler = function() use (&$running) { $running = false; };
  if (defined('SIGINT'))  pcntl_signal(SIGINT, $handler);
  if (defined('SIGTERM')) pcntl_signal(SIGTERM, $handler);
}

logLine("KKchat realtime server started on {$host}:{$port}");

while ($running) {
  $read = [$socket];
  foreach ($clients as $id => $client) {
    $read[] = $client['socket'];
  }
  $write = null; $except = null;
  $select = @stream_select($read, $write, $except, 1, 0);
  if ($select === false) {
    continue;
  }

  if (in_array($socket, $read, true)) {
    $conn = @stream_socket_accept($socket, 0);
    if ($conn) {
      stream_set_blocking($conn, false);
      $cid = (int) $conn;
      $clients[$cid] = [
        'socket'       => $conn,
        'handshake'    => false,
        'buffer'       => '',
        'subscriptions'=> [],
        'user_id'      => 0,
        'meta'         => [],
        'last_pong'    => microtime(true),
        'connected_at' => time(),
      ];
    }
  }

  foreach ($clients as $cid => &$client) {
    $sock = $client['socket'];
    if (!in_array($sock, $read, true)) {
      continue;
    }
    $chunk = @fread($sock, 8192);
    if ($chunk === '' || $chunk === false) {
      closeClient($clients, $cid, 'eof');
      continue;
    }
    $client['buffer'] .= $chunk;

    if (!$client['handshake']) {
      if (!processHandshake($client, $cid, $clients)) {
        continue;
      }
    }

    while ($client['handshake']) {
      $frame = websocketReceiveFrame($client['buffer']);
      if ($frame === null) { break; }
      handleFrame($clients, $cid, $frame);
    }
  }
  unset($client);

  $now = microtime(true);
  if ($now - $lastPoll >= $pollInterval) {
    $lastPoll = $now;
    $events = kkchat_realtime_fetch_events_since($lastEventId, 200);
    if ($events) {
      foreach ($events as $event) {
        $lastEventId = max($lastEventId, (int)$event['id']);
        broadcastEvent($clients, $event['channel'], [
          'type'       => 'event',
          'id'         => (int)$event['id'],
          'channel'    => $event['channel'],
          'payload'    => $event['payload'],
          'created_at' => (int)$event['created_at'],
        ]);
      }
    }
  }

  if ($now - $lastPing >= $pingInterval) {
    $lastPing = $now;
    foreach ($clients as $cid => &$client) {
      if (!$client['handshake']) { continue; }
      $age = $now - $client['last_pong'];
      if ($age > $idleTimeout) {
        closeClient($clients, $cid, 'idle');
        continue;
      }
      $payload = json_encode(['type' => 'ping', 'ts' => time()]);
      if ($payload !== false) {
        sendFrame($client['socket'], $payload, 'text');
      }
    }
    unset($client);
  }
}

foreach ($clients as $cid => $client) {
  @fclose($client['socket']);
}
@fclose($socket);
logLine('KKchat realtime server stopped.');
exit(0);

function logLine(string $line): void {
  $ts = date('Y-m-d H:i:s');
  fwrite(STDOUT, "[{$ts}] {$line}\n");
}

function closeClient(array &$clients, int $cid, string $reason = ''): void {
  if (!isset($clients[$cid])) { return; }
  $client = $clients[$cid];
  @fclose($client['socket']);
  unset($clients[$cid]);
  if (!empty($client['handshake'])) {
    logLine("Client {$cid} disconnected ({$reason})");
  }
}

function processHandshake(array &$client, int $cid, array &$clients): bool {
  $pos = strpos($client['buffer'], "\r\n\r\n");
  if ($pos === false) {
    return false;
  }
  $raw = substr($client['buffer'], 0, $pos);
  $client['buffer'] = substr($client['buffer'], $pos + 4);

  $lines = explode("\r\n", $raw);
  $request = array_shift($lines);
  if (!$request || !preg_match('#^GET\s+(\S+)#i', $request, $m)) {
    sendHttpError($client['socket'], 400, 'Bad Request');
    closeClient($clients, $cid, 'bad_request');
    return false;
  }
  $target = $m[1];
  $headers = [];
  foreach ($lines as $line) {
    if (strpos($line, ':') === false) { continue; }
    [$name, $value] = explode(':', $line, 2);
    $headers[strtolower(trim($name))] = trim($value);
  }

  $key = $headers['sec-websocket-key'] ?? '';
  if ($key === '') {
    sendHttpError($client['socket'], 400, 'Missing Key');
    closeClient($clients, $cid, 'missing_key');
    return false;
  }

  $parts = parse_url($target);
  $query = [];
  if (!empty($parts['query'])) {
    parse_str($parts['query'], $query);
  }
  $token = isset($query['token']) ? (string)$query['token'] : '';
  $info  = kkchat_realtime_take_token($token);
  if (!$info || empty($info['user_id'])) {
    sendHttpError($client['socket'], 401, 'Unauthorized');
    closeClient($clients, $cid, 'unauthorized');
    return false;
  }

  $accept = base64_encode(sha1(trim($key) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
  $response = "HTTP/1.1 101 Switching Protocols\r\n" .
              "Upgrade: websocket\r\n" .
              "Connection: Upgrade\r\n" .
              "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
  fwrite($client['socket'], $response);

  $channels = $info['channels'] ?? ['global'];
  if (!is_array($channels) || empty($channels)) {
    $channels = ['global'];
  }
  $channels = array_values(array_unique(array_map('kkchat_realtime_sanitize_channel', $channels)));
  if (!in_array('global', $channels, true)) {
    $channels[] = 'global';
  }

  $subs = [];
  foreach ($channels as $ch) {
    if (kkchat_realtime_authorize_channel((int)$info['user_id'], $ch, (array)($info['meta'] ?? []))) {
      $subs[$ch] = true;
    }
  }
  if (!isset($subs['global'])) {
    $subs['global'] = true;
  }

  $client['handshake'] = true;
  $client['subscriptions'] = $subs;
  $client['user_id'] = (int)($info['user_id'] ?? 0);
  $client['meta'] = is_array($info['meta'] ?? null) ? $info['meta'] : [];
  $client['last_pong'] = microtime(true);

  logLine("Client {$cid} connected as user {$client['user_id']}");

  $welcome = json_encode([
    'type'      => 'ready',
    'server_ts' => time(),
    'channels'  => array_keys($client['subscriptions']),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($welcome !== false) {
    sendFrame($client['socket'], $welcome, 'text');
  }

  return true;
}

function sendHttpError($socket, int $code, string $text): void {
  $body = $code . ' ' . $text;
  $resp = "HTTP/1.1 {$code} {$text}\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
  @fwrite($socket, $resp);
}

function handleFrame(array &$clients, int $cid, array $frame): void {
  if (!isset($clients[$cid])) { return; }
  $client =& $clients[$cid];
  $opcode = $frame['opcode'];
  $payload = $frame['payload'];
  if ($opcode === 0x8) { // close
    closeClient($clients, $cid, 'close');
    return;
  }
  if ($opcode === 0x9) { // ping
    sendFrame($client['socket'], $payload, 'pong');
    return;
  }
  if ($opcode === 0xA) { // pong
    $client['last_pong'] = microtime(true);
    return;
  }
  if ($opcode !== 0x1) {
    return;
  }
  $data = json_decode($payload, true);
  if (!is_array($data)) {
    return;
  }
  $type = $data['type'] ?? '';
  if ($type === 'pong') {
    $client['last_pong'] = microtime(true);
    return;
  }
  if ($type === 'ping') {
    $resp = json_encode(['type' => 'pong', 'ts' => time()]);
    if ($resp !== false) {
      sendFrame($client['socket'], $resp, 'text');
    }
    return;
  }
  if ($type === 'subscribe') {
    $channels = $data['channels'] ?? [];
    if (!is_array($channels)) { return; }
    foreach ($channels as $ch) {
      $ch = kkchat_realtime_sanitize_channel((string)$ch);
      if ($ch === '') { continue; }
      if (kkchat_realtime_authorize_channel($client['user_id'], $ch, $client['meta'])) {
        $client['subscriptions'][$ch] = true;
      }
    }
    $ack = json_encode([
      'type'      => 'subscribed',
      'channels'  => array_keys($client['subscriptions'])
    ]);
    if ($ack !== false) {
      sendFrame($client['socket'], $ack, 'text');
    }
    return;
  }
  if ($type === 'unsubscribe') {
    $channels = $data['channels'] ?? [];
    if (!is_array($channels)) { return; }
    foreach ($channels as $ch) {
      $ch = kkchat_realtime_sanitize_channel((string)$ch);
      if ($ch === 'global') { continue; }
      unset($client['subscriptions'][$ch]);
    }
    $ack = json_encode([
      'type'      => 'subscribed',
      'channels'  => array_keys($client['subscriptions'])
    ]);
    if ($ack !== false) {
      sendFrame($client['socket'], $ack, 'text');
    }
    return;
  }
}

function broadcastEvent(array &$clients, string $channel, array $message): void {
  $channel = kkchat_realtime_sanitize_channel($channel);
  $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($payload === false) { return; }
  foreach ($clients as $cid => $client) {
    if (empty($client['handshake'])) { continue; }
    if (!isset($client['subscriptions'][$channel]) && !isset($client['subscriptions']['global']) && $channel !== 'global') {
      continue;
    }
    sendFrame($client['socket'], $payload, 'text');
  }
}

function websocketReceiveFrame(string &$buffer): ?array {
  $length = strlen($buffer);
  if ($length < 2) { return null; }
  $b1 = ord($buffer[0]);
  $b2 = ord($buffer[1]);
  $opcode = $b1 & 0x0F;
  $masked = ($b2 & 0x80) !== 0;
  $len = $b2 & 0x7F;
  $offset = 2;

  if ($len === 126) {
    if ($length < 4) { return null; }
    $len = unpack('n', substr($buffer, 2, 2))[1];
    $offset = 4;
  } elseif ($len === 127) {
    if ($length < 10) { return null; }
    $parts = unpack('N2', substr($buffer, 2, 8));
    $len = ($parts[1] * 4294967296) + $parts[2];
    $offset = 10;
  }

  $mask = '';
  if ($masked) {
    if ($length < $offset + 4) { return null; }
    $mask = substr($buffer, $offset, 4);
    $offset += 4;
  }

  if ($length < $offset + $len) { return null; }
  $payload = substr($buffer, $offset, $len);
  $buffer = substr($buffer, $offset + $len);

  if ($masked && $mask !== '') {
    $payload = websocketUnmask($payload, $mask);
  }

  return [
    'opcode'  => $opcode,
    'payload' => $payload,
  ];
}

function websocketUnmask(string $payload, string $mask): string {
  $len = strlen($payload);
  $out = '';
  for ($i = 0; $i < $len; $i++) {
    $out .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
  }
  return $out;
}

function sendFrame($socket, string $payload, string $type = 'text'): void {
  switch ($type) {
    case 'pong': $opcode = 0xA; break;
    case 'ping': $opcode = 0x9; break;
    case 'close': $opcode = 0x8; break;
    default: $opcode = 0x1; break;
  }
  $len = strlen($payload);
  $frame = chr(0x80 | $opcode);
  if ($len <= 125) {
    $frame .= chr($len);
  } elseif ($len <= 65535) {
    $frame .= chr(126) . pack('n', $len);
  } else {
    $high = (int) floor($len / 4294967296);
    $low  = (int) ($len - ($high * 4294967296));
    $frame .= chr(127) . pack('N2', $high, $low);
  }
  $frame .= $payload;
  @fwrite($socket, $frame);
}
