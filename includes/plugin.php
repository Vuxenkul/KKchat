<?php

namespace KKChat;

use WP_REST_Server;

class Plugin
{
    private static ?Plugin $instance = null;

    private string $table_messages;

    private function __construct()
    {
        global $wpdb;
        $this->table_messages = $wpdb->prefix . 'kkchat_messages';

        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'maybe_set_guest_cookie']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public static function instance(): Plugin
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'kkchat_messages';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender_key VARCHAR(191) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            display_name VARCHAR(191) NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY sender_key (sender_key)
        ) {$charset};";

        dbDelta($sql);

        if (!get_option('kkchat_ws_port')) {
            add_option('kkchat_ws_port', 8090);
        }
    }

    public static function deactivate(): void
    {
        // Intentionally empty, but kept for symmetry and future cleanup hooks.
    }

    public function register_shortcodes(): void
    {
        add_shortcode('kkchat', [$this, 'render_shortcode']);
    }

    public function register_assets(): void
    {
        wp_register_style(
            'kkchat-app',
            KKCHAT_URL . 'assets/css/kkchat.css',
            [],
            file_exists(KKCHAT_DIR . 'assets/css/kkchat.css') ? filemtime(KKCHAT_DIR . 'assets/css/kkchat.css') : null
        );

        wp_register_script(
            'kkchat-app',
            KKCHAT_URL . 'assets/js/kkchat.js',
            [],
            file_exists(KKCHAT_DIR . 'assets/js/kkchat.js') ? filemtime(KKCHAT_DIR . 'assets/js/kkchat.js') : null,
            true
        );

        if (wp_script_is('kkchat-app', 'registered')) {
            wp_localize_script('kkchat-app', 'KKCHAT_SETTINGS', $this->script_settings());
        }
    }

    public function render_shortcode(array $atts = []): string
    {
        $atts = shortcode_atts([
            'title' => __('Community chat', 'kkchat'),
        ], $atts, 'kkchat');

        wp_enqueue_style('kkchat-app');
        wp_enqueue_script('kkchat-app');

        ob_start();
        ?>
        <section class="kkchat" aria-live="polite">
            <header class="kkchat__header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
                <button type="button" class="kkchat__reload" data-action="reload" aria-label="<?php esc_attr_e('Reconnect to chat', 'kkchat'); ?>">
                    <?php esc_html_e('Reconnect', 'kkchat'); ?>
                </button>
            </header>
            <div class="kkchat__status" role="status"></div>
            <ol class="kkchat__messages" data-role="messages"></ol>
            <form class="kkchat__form" data-role="composer">
                <label class="screen-reader-text" for="kkchat-message">
                    <?php esc_html_e('Send a message', 'kkchat'); ?>
                </label>
                <textarea id="kkchat-message" name="message" rows="3" maxlength="500" placeholder="<?php esc_attr_e('Say hello…', 'kkchat'); ?>" required></textarea>
                <button type="submit"><?php esc_html_e('Send', 'kkchat'); ?></button>
            </form>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public function register_routes(): void
    {
        register_rest_route('kkchat/v1', '/messages', [
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback'            => function ($request) {
                $limit = max(1, min((int) $request->get_param('limit') ?: 50, 200));
                return rest_ensure_response($this->get_recent_messages($limit));
            },
        ]);

        register_rest_route('kkchat/v1', '/messages', [
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => function () {
                $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
                if (!wp_verify_nonce($nonce, 'wp_rest')) {
                    return new \WP_Error('kkchat_invalid_nonce', __('Invalid request nonce.', 'kkchat'), ['status' => 403]);
                }

                return is_user_logged_in() || isset($_COOKIE['kkchat_guest_id']);
            },
            'callback'            => function ($request) {
                $body = json_decode($request->get_body(), true);
                if (!is_array($body)) {
                    $body = $request->get_json_params();
                }

                $message = is_array($body) ? ($body['message'] ?? '') : '';

                $participant = $this->current_participant();
                if (!$participant) {
                    return new \WP_Error('kkchat_no_participant', __('Unable to identify sender.', 'kkchat'), ['status' => 403]);
                }

                $message = $this->sanitize_message($message);
                if ($message === '') {
                    return new \WP_Error('kkchat_empty', __('Message cannot be empty.', 'kkchat'), ['status' => 400]);
                }

                $row = $this->store_message($participant, $message);

                return rest_ensure_response($row);
            },
        ]);
    }

    private function script_settings(): array
    {
        $participant = $this->current_participant();

        return [
            'restUrl'   => rest_url('kkchat/v1/messages'),
            'wsUrl'     => $this->websocket_url(),
            'restNonce' => wp_create_nonce('wp_rest'),
            'participant' => $participant,
            'auth'      => [
                'signature' => $participant['signature'] ?? '',
                'nonce'     => $this->create_ws_nonce($participant),
            ],
            'i18n' => [
                'connecting' => __('Connecting…', 'kkchat'),
                'connected'  => __('Connected', 'kkchat'),
                'reconnecting' => __('Reconnecting…', 'kkchat'),
                'disconnected' => __('Disconnected', 'kkchat'),
                'sendFailed' => __('Failed to send message.', 'kkchat'),
                'historyError' => __('Unable to load previous messages.', 'kkchat'),
            ],
        ];
    }

    private function websocket_url(): string
    {
        $scheme = is_ssl() ? 'wss://' : 'ws://';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $port   = (int) get_option('kkchat_ws_port', 8090);

        $host = preg_replace('#:\\d+$#', '', $host);

        return apply_filters('kkchat_websocket_url', sprintf('%s%s:%d', $scheme, $host, $port));
    }

    private function create_ws_nonce(array $participant): string
    {
        $signature = $participant['signature'] ?? 'guest-0';
        return wp_create_nonce('kkchat-ws:' . $signature);
    }

    public function maybe_set_guest_cookie(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        $id = isset($_COOKIE['kkchat_guest_id']) ? sanitize_key($_COOKIE['kkchat_guest_id']) : '';
        if ($id === '' || strlen($id) < 6) {
            $id = strtolower(wp_generate_password(12, false));
            $this->set_cookie('kkchat_guest_id', $id);
        }

        $name = isset($_COOKIE['kkchat_guest_name']) ? sanitize_text_field($_COOKIE['kkchat_guest_name']) : '';
        if ($name === '' || strlen($name) > 30) {
            $name = sprintf(__('Guest %s', 'kkchat'), strtoupper(substr($id, 0, 4)));
            $this->set_cookie('kkchat_guest_name', $name);
        }
    }

    private function set_cookie(string $key, string $value): void
    {
        if (headers_sent()) {
            return;
        }

        $secure = is_ssl();
        $httponly = true;
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        setcookie($key, $value, time() + YEAR_IN_SECONDS, $path, $domain, $secure, $httponly);
        $_COOKIE[$key] = $value;
    }

    private function current_participant(): array
    {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $display = $user->display_name ?: $user->user_login;
            $display = apply_filters('kkchat_display_name', $display, $user);

            return [
                'id'        => (int) $user->ID,
                'display'   => $display,
                'type'      => 'user',
                'signature' => 'user-' . (int) $user->ID,
            ];
        }

        $guestId = isset($_COOKIE['kkchat_guest_id']) ? sanitize_key($_COOKIE['kkchat_guest_id']) : '';
        $guestName = isset($_COOKIE['kkchat_guest_name']) ? sanitize_text_field($_COOKIE['kkchat_guest_name']) : '';

        if ($guestId === '') {
            $guestId = strtolower(wp_generate_password(12, false));
            $this->set_cookie('kkchat_guest_id', $guestId);
        }

        if ($guestName === '') {
            $guestName = sprintf(__('Guest %s', 'kkchat'), strtoupper(substr($guestId, 0, 4)));
            $this->set_cookie('kkchat_guest_name', $guestName);
        }

        return [
            'id'        => 0,
            'display'   => $guestName,
            'type'      => 'guest',
            'guest_id'  => $guestId,
            'signature' => 'guest-' . $guestId,
        ];
    }

    private function sanitize_message(string $message): string
    {
        $message = wp_strip_all_tags($message, true);
        $message = trim(preg_replace('/\s+/', ' ', $message));

        if (strlen($message) > 500) {
            $message = mb_substr($message, 0, 500);
        }

        return $message;
    }

    private function store_message(array $participant, string $message): array
    {
        global $wpdb;

        $data = [
            'sender_key'   => $participant['signature'] ?? 'guest-0',
            'user_id'      => (int) ($participant['id'] ?? 0),
            'display_name' => $participant['display'] ?? __('Guest', 'kkchat'),
            'message'      => $message,
            'created_at'   => current_time('mysql', true),
        ];

        $wpdb->insert($this->table_messages, $data, ['%s', '%d', '%s', '%s', '%s']);

        $data['id'] = (int) $wpdb->insert_id;
        $data['created_at_gmt'] = mysql_to_rfc3339($data['created_at']);

        return $data;
    }

    private function get_recent_messages(int $limit = 50): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sender_key, user_id, display_name, message, created_at
                 FROM {$this->table_messages}
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
}
