<?php

namespace KKChat;

class WebSocketServer
{
    private int $port;

    /** @var resource|null */
    private $socket;

    /** @var array<int, resource> */
    private array $clients = [];

    /** @var array<int, string> */
    private array $buffers = [];

    /** @var array<int, array{handshake:bool,authed:bool,participant:array|null}> */
    private array $states = [];

    private string $table;

    public function __construct(int $port)
    {
        $this->port = $port > 0 ? $port : 8090;

        global $wpdb;
        $this->table = $wpdb->prefix . 'kkchat_messages';
    }

    public function run(): void
    {
        $address = sprintf('tcp://0.0.0.0:%d', $this->port);
        $errno = 0;
        $errstr = '';

        $this->socket = @stream_socket_server($address, $errno, $errstr);
        if (!$this->socket) {
            throw new \RuntimeException(sprintf('Unable to bind WebSocket server on %s (%s)', $address, $errstr));
        }

        stream_set_blocking($this->socket, false);
        $this->log(sprintf('Listening on %s', $address));

        while (true) {
            $this->acceptConnections();
            $this->tick();
            usleep(50000);
        }
    }

    private function acceptConnections(): void
    {
        if (!$this->socket) {
            return;
        }

        while ($conn = @stream_socket_accept($this->socket, 0)) {
            $id = (int) $conn;
            stream_set_blocking($conn, false);
            $this->clients[$id] = $conn;
            $this->buffers[$id] = '';
            $this->states[$id] = [
                'handshake'   => false,
                'authed'      => false,
                'participant' => null,
            ];
            $this->log(sprintf('Client %d connected', $id));
        }
    }

    private function tick(): void
    {
        foreach ($this->clients as $id => $client) {
            $data = @fread($client, 8192);
            if ($data === '' || $data === false) {
                $meta = stream_get_meta_data($client);
                if ($meta['eof'] ?? false) {
                    $this->disconnect($id, 'EOF');
                }
                continue;
            }

            $this->buffers[$id] .= $data;

            if (!$this->states[$id]['handshake']) {
                $this->attemptHandshake($id);
                continue;
            }

            $frames = $this->extractFrames($id);
            foreach ($frames as $frame) {
                $this->handleFrame($id, $frame);
            }
        }
    }

    private function attemptHandshake(int $id): void
    {
        $buffer = $this->buffers[$id] ?? '';
        $pos = strpos($buffer, "\r\n\r\n");
        if ($pos === false) {
            return;
        }

        $request = substr($buffer, 0, $pos + 4);
        $this->buffers[$id] = substr($buffer, $pos + 4);

        $headers = $this->parseHeaders($request);
        $key = $headers['sec-websocket-key'] ?? '';
        if ($key === '') {
            $this->disconnect($id, 'Missing key');
            return;
        }

        $accept = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        fwrite($this->clients[$id], $response);
        $this->states[$id]['handshake'] = true;
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $request): array
    {
        $lines = preg_split('/\r\n/', trim($request));
        $headers = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $headers;
    }

    /**
     * @return array<int, array{opcode:int,payload:string}>
     */
    private function extractFrames(int $id): array
    {
        $frames = [];
        $buffer = &$this->buffers[$id];

        while (strlen($buffer) >= 2) {
            $first = ord($buffer[0]);
            $second = ord($buffer[1]);

            $opcode = $first & 0x0F;
            $masked = ($second >> 7) & 0x1;
            $length = $second & 0x7F;
            $offset = 2;

            if ($length === 126) {
                if (strlen($buffer) < 4) {
                    break;
                }
                $length = unpack('n', substr($buffer, $offset, 2))[1];
                $offset += 2;
            } elseif ($length === 127) {
                if (strlen($buffer) < 10) {
                    break;
                }
                $parts = unpack('N2', substr($buffer, $offset, 8));
                $length = ($parts[1] << 32) + $parts[2];
                $offset += 8;
            }

            $mask = '';
            if ($masked) {
                if (strlen($buffer) < $offset + 4) {
                    break;
                }
                $mask = substr($buffer, $offset, 4);
                $offset += 4;
            }

            if (strlen($buffer) < $offset + $length) {
                break;
            }

            $payload = substr($buffer, $offset, $length);
            $buffer = substr($buffer, $offset + $length);

            if ($masked) {
                $decoded = '';
                for ($i = 0; $i < $length; $i++) {
                    $decoded .= $payload[$i] ^ $mask[$i % 4];
                }
                $payload = $decoded;
            }

            $frames[] = [
                'opcode'  => $opcode,
                'payload' => $payload,
            ];
        }

        return $frames;
    }

    private function handleFrame(int $id, array $frame): void
    {
        $opcode = $frame['opcode'];
        $payload = $frame['payload'];

        switch ($opcode) {
            case 0x8: // close
                $this->disconnect($id, 'Close frame');
                break;
            case 0x9: // ping
                $this->sendFrame($id, $payload, 0xA);
                break;
            case 0x1: // text
                $this->handleTextFrame($id, $payload);
                break;
            default:
                break;
        }
    }

    private function handleTextFrame(int $id, string $payload): void
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return;
        }

        if (!$this->states[$id]['authed']) {
            if (($decoded['type'] ?? '') !== 'auth') {
                $this->sendJson($id, ['type' => 'error', 'message' => 'Authentication required.']);
                return;
            }
            $this->authenticate($id, $decoded);
            return;
        }

        $type = $decoded['type'] ?? '';
        if ($type === 'message') {
            $this->handleMessage($id, $decoded);
        }
    }

    private function authenticate(int $id, array $payload): void
    {
        $signature = isset($payload['signature']) ? sanitize_text_field($payload['signature']) : '';
        $nonce = isset($payload['nonce']) ? sanitize_text_field($payload['nonce']) : '';
        $participant = isset($payload['participant']) && is_array($payload['participant']) ? $payload['participant'] : [];

        if ($signature === '' || $nonce === '') {
            $this->sendJson($id, ['type' => 'error', 'message' => 'Invalid authentication payload.']);
            return;
        }

        if (!wp_verify_nonce($nonce, 'kkchat-ws:' . $signature)) {
            $this->sendJson($id, ['type' => 'error', 'message' => 'Authentication failed.']);
            $this->disconnect($id, 'Auth failed');
            return;
        }

        $participant = $this->sanitizeParticipant($participant, $signature);
        if (!$participant) {
            $this->sendJson($id, ['type' => 'error', 'message' => 'Invalid participant.']);
            $this->disconnect($id, 'Invalid participant');
            return;
        }

        $this->states[$id]['authed'] = true;
        $this->states[$id]['participant'] = $participant;

        $this->sendJson($id, [
            'type'     => 'ready',
            'messages' => $this->recentMessages(),
        ]);
    }

    private function sanitizeParticipant(array $participant, string $signature): ?array
    {
        $display = sanitize_text_field($participant['display'] ?? 'Guest');
        $userId = isset($participant['id']) ? (int) $participant['id'] : 0;
        $type = sanitize_key($participant['type'] ?? 'guest');

        if ($display === '') {
            $display = 'Guest';
        }

        if ($type !== 'user' && $type !== 'guest') {
            $type = 'guest';
        }

        return [
            'id'        => $userId,
            'display'   => $display,
            'type'      => $type,
            'signature' => $signature,
        ];
    }

    private function handleMessage(int $id, array $payload): void
    {
        $text = isset($payload['text']) ? $payload['text'] : '';
        $text = $this->sanitizeMessage($text);
        if ($text === '') {
            $this->sendJson($id, ['type' => 'error', 'message' => 'Message cannot be empty.']);
            return;
        }

        $participant = $this->states[$id]['participant'];
        if (!$participant) {
            return;
        }

        $message = $this->storeMessage($participant, $text);
        $this->broadcast(['type' => 'message', 'message' => $message]);
    }

    private function sanitizeMessage(string $message): string
    {
        $message = wp_strip_all_tags($message, true);
        $message = trim(preg_replace('/\s+/', ' ', $message));
        if (strlen($message) > 500) {
            $message = mb_substr($message, 0, 500);
        }

        return $message;
    }

    private function storeMessage(array $participant, string $message): array
    {
        global $wpdb;

        $data = [
            'sender_key'   => $participant['signature'],
            'user_id'      => (int) $participant['id'],
            'display_name' => $participant['display'],
            'message'      => $message,
            'created_at'   => current_time('mysql', true),
        ];

        $wpdb->insert($this->table, $data, ['%s', '%d', '%s', '%s', '%s']);

        $data['id'] = (int) $wpdb->insert_id;
        $data['created_at_gmt'] = mysql_to_rfc3339($data['created_at']);

        return $data;
    }

    private function recentMessages(int $limit = 50): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sender_key, user_id, display_name, message, created_at
                 FROM {$this->table}
                 ORDER BY id DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        $rows = array_reverse($rows ?: []);

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['user_id'] = (int) $row['user_id'];
            $row['created_at_gmt'] = mysql_to_rfc3339($row['created_at']);
        }

        return $rows;
    }

    private function broadcast(array $payload): void
    {
        foreach (array_keys($this->clients) as $id) {
            if (!$this->states[$id]['authed']) {
                continue;
            }
            $this->sendJson($id, $payload);
        }
    }

    private function sendJson(int $id, array $payload): void
    {
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->sendFrame($id, $json, 0x1);
    }

    private function sendFrame(int $id, string $payload, int $opcode = 0x1): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $length = strlen($payload);
        $frame = chr(0x80 | ($opcode & 0x0F));

        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('N2', 0, $length);
        }

        $frame .= $payload;

        @fwrite($this->clients[$id], $frame);
    }

    private function disconnect(int $id, string $reason = ''): void
    {
        if (isset($this->clients[$id])) {
            fclose($this->clients[$id]);
            unset($this->clients[$id]);
        }
        unset($this->buffers[$id], $this->states[$id]);
        $this->log(sprintf('Client %d disconnected (%s)', $id, $reason));
    }

    private function log(string $message): void
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        fwrite(STDOUT, sprintf('[%s] %s%s', $timestamp, $message, PHP_EOL));
    }
}
