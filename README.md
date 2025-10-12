# KKChat

A modernised version of the KKChat plugin featuring a fully fledged WebSocket
pipeline for real-time conversations.

## Features

- Lightweight shortcode (`[kkchat]`) that renders a minimal chat UI.
- REST endpoint (`/wp-json/kkchat/v1/messages`) for history and fallbacks.
- Standalone PHP WebSocket server that persists messages in the WordPress
  database and broadcasts new ones instantly.
- Guest support via secure cookies when visitors are not authenticated.

## Running the WebSocket server

1. Ensure your WordPress installation loads the plugin.
2. From the plugin directory run:

   ```bash
   php bin/kkchat-ws.php
   ```

   The server binds to `0.0.0.0` on port `8090` by default. Update the stored
   option `kkchat_ws_port` if you need another port and restart the server.

Keep the process running (e.g. with Supervisor or systemd) for production use.
