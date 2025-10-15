<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
 * Admin-post handler: IP-block direkt från Loggar (fixar blank sida)
 * – Formuläret postar till admin-post.php?action=kkchat_logs_ipban
 * ============================================================ */

add_action('admin_post_kkchat_logs_ipban', 'kkchat_handle_logs_ipban');
function kkchat_handle_logs_ipban() {
  if ( ! current_user_can('manage_options')) wp_die(__('Åtkomst nekad.', 'kkchat'));
  check_admin_referer('kkchat_logs_ipban');

  global $wpdb; $t = kkchat_tables();
  $ip      = trim((string)($_POST['ip'] ?? ''));
  $minutes = max(0, (int)($_POST['minutes'] ?? 0));
  $cause   = sanitize_text_field($_POST['cause'] ?? '');
  $back    = wp_get_referer() ?: admin_url('admin.php?page=kkchat_admin_logs');

  if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    wp_safe_redirect( add_query_arg('kkbanerr', rawurlencode('Ogiltig IP-adress.'), $back) ); exit;
  }

  // Normalisera till lagringsnyckel (IPv4 exakt / IPv6 -> /64)
  $ipKey = kkchat_ip_ban_key($ip);
  if (!$ipKey) {
    wp_safe_redirect( add_query_arg('kkbanerr', rawurlencode('Kunde inte normalisera IP-adressen.'), $back) ); exit;
  }

  // Finns redan ett aktivt block på nyckeln?
  $exists = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$t['blocks']} WHERE type='ipban' AND target_ip=%s AND active=1", $ipKey
  ));
  if ($exists > 0) {
    wp_safe_redirect( add_query_arg('kkbanok', rawurlencode("Det finns redan ett aktivt block för IP $ipKey."), $back) ); exit;
  }

  // -----------------------------
  // NYTT: Försök fylla "Mål" fälten
  // -----------------------------
  $target_user_id = null;
  $target_name = null;
  $target_wp_username = null;
  $posted_name = sanitize_text_field($_POST['user_name'] ?? '');
  $posted_wp_username = sanitize_text_field($_POST['user_wp_username'] ?? '');

  // 1) Om user_id skickas med (vi har det från loggraden) – använd det
  $uid = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
  if ($uid > 0) {
    $urow = $wpdb->get_row($wpdb->prepare("SELECT id,name,wp_username FROM {$t['users']} WHERE id=%d LIMIT 1", $uid), ARRAY_A);
    if ($urow) {
      $target_user_id     = (int)$urow['id'];
      $target_name        = (string)($urow['name'] ?? '');
      $target_wp_username = (string)($urow['wp_username'] ?? '');
    }
  }

  // 2) Annars, härleda från senaste loggrad på denna IP (avsändare/mottagare)
  if (($target_name === null || $target_name === '') && $posted_name !== '') {
    $target_name = $posted_name;
  }
  if (($target_wp_username === null || $target_wp_username === '') && $posted_wp_username !== '') {
    $target_wp_username = $posted_wp_username;
  }

  if ($target_user_id === null && $target_name === null && $target_wp_username === null) {
    $m = $wpdb->get_row($wpdb->prepare(
      "SELECT sender_id, sender_name
         FROM {$t['messages']}
        WHERE sender_ip=%s OR recipient_ip=%s
        ORDER BY id DESC LIMIT 1",
      $ip, $ip
    ), ARRAY_A);
    if ($m) {
      $sid = (int)($m['sender_id'] ?? 0);
      if ($sid > 0) {
        $target_user_id = $sid;
        $target_name    = (string)($m['sender_name'] ?? '');
        // Hämta WP-användarnamn om vi har en user-rad
        $urow = $wpdb->get_row($wpdb->prepare("SELECT wp_username,name FROM {$t['users']} WHERE id=%d LIMIT 1", $sid), ARRAY_A);
        if ($urow) {
          $target_wp_username = (string)($urow['wp_username'] ?? '');
          // Om namnet saknas i loggen, fyll från users-tabellen
          if (!$target_name && !empty($urow['name'])) $target_name = (string)$urow['name'];
        }
      } else {
        // Gäst: vi kan åtminstone spara visningsnamnet från loggen
        $target_name = (string)($m['sender_name'] ?? '');
      }
    }
  }

  $now = time();
  $exp = $minutes > 0 ? $now + $minutes * 60 : null;
  $admin = wp_get_current_user()->user_login ?? '';

  $ok = $wpdb->insert($t['blocks'], [
    'type'               => 'ipban',
    'target_user_id'     => $target_user_id,            // ← nu ifyllt när möjligt
    'target_name'        => $target_name ?: null,       // ← nu ifyllt när möjligt
    'target_wp_username' => $target_wp_username ?: null,// ← nu ifyllt när möjligt
    'target_ip'          => $ipKey,
    'cause'              => $cause ?: null,
    'created_by'         => $admin ?: null,
    'created_at'         => $now,
    'expires_at'         => $exp,
    'active'             => 1
  ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);

  if ($ok === false) {
    $err = $wpdb->last_error ? $wpdb->last_error : 'Okänt databasfel.';
    wp_safe_redirect( add_query_arg('kkbanerr', rawurlencode($err), $back) ); exit;
  }

  if ($exp === null) {
    $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at = NULL WHERE id=%d", (int)$wpdb->insert_id));
  }
  $msg = 'IP '.$ipKey.' blockerad '.($exp ? 'till '.date_i18n('Y-m-d H:i:s', $exp) : 'för alltid').'.';
  wp_safe_redirect( add_query_arg('kkbanok', rawurlencode($msg), $back) ); exit;
}

/**
 * Adminmeny (Svenska etiketter)
 */
add_action('admin_menu', function () {
  $cap = 'manage_options';

  // Top-level "KKchat" (öppnar Rum)
  add_menu_page(
    'KKchat',             // sidtitel
    'KKchat',             // menytext
    $cap,
    'kkchat_rooms',       // top-level slug (samma som Rum)
    'kkchat_admin_rooms_page',
    'dashicons-format-chat',
    60
  );

  // Undermenyer
  add_submenu_page('kkchat_rooms', 'Rum',          'Rum',          $cap, 'kkchat_rooms',        'kkchat_admin_rooms_page');
  add_submenu_page('kkchat_rooms', 'Banderoller',  'Banderoller',  $cap, 'kkchat_banners',      'kkchat_admin_banners_page');
  add_submenu_page('kkchat_rooms', 'Moderering',   'Moderering',   $cap, 'kkchat_moderation',   'kkchat_admin_moderation_page');
  add_submenu_page('kkchat_rooms', 'Ord',          'Ord',          $cap, 'kkchat_words',        'kkchat_admin_words_page');
  add_submenu_page('kkchat_rooms', 'Loggar',       'Loggar',       $cap, 'kkchat_admin_logs',   'kkchat_admin_logs_page');
  add_submenu_page('kkchat_rooms', 'Bildmoderering', 'Bildmoderering', $cap, 'kkchat_media', 'kkchat_admin_media_page');
  add_submenu_page('kkchat_rooms', 'Rapporter',    'Rapporter',    $cap, 'kkchat_reports',      'kkchat_admin_reports_page');
  add_submenu_page('kkchat_rooms', 'Inställningar','Inställningar',$cap, 'kkchat_settings',     'kkchat_admin_settings_page');
});
/**
 * Bildmoderering — som Loggar fast endast bilder, i ett galleri (grid-kort)
 * Slug: ?page=kkchat_media
 */
function kkchat_admin_media_page() {
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();

  // ------ helpers ------
  $is_image_url = function($url) {
    if (!$url) return false;
    if (!preg_match('~^https?://~i', $url)) return false;
    if (preg_match('~\.(jpe?g|png|gif|webp)$~i', $url)) return true;
    if (strpos($url, '/kkchat/') !== false) return true; // local chat uploads
    return false;
  };
  $h = fn($s) => esc_html($s);
  $u = fn($s) => esc_url($s);

  // ------ actions: hide/unhide (reuse Logs' nonce) ------
  if (isset($_POST['kk_hide']) && check_admin_referer('kkchat_logs_actions')) {
    $mid   = max(0, (int)($_POST['mid'] ?? 0));
    $cause = sanitize_text_field($_POST['cause'] ?? '');
    if ($mid > 0) {
      $wpdb->query($wpdb->prepare(
        "UPDATE {$t['messages']}
           SET hidden_at = %d,
               hidden_by = %d,
               hidden_cause = %s
         WHERE id = %d",
        time(), get_current_user_id() ?: 0, $cause, $mid
      ));
      echo '<div class="updated"><p>Meddelande #'.(int)$mid.' dolt.</p></div>';
    }
  }
  if (isset($_POST['kk_unhide']) && check_admin_referer('kkchat_logs_actions')) {
    $mid = max(0, (int)($_POST['mid'] ?? 0));
    if ($mid > 0) {
      $wpdb->query($wpdb->prepare(
        "UPDATE {$t['messages']}
           SET hidden_at = NULL,
               hidden_by = NULL,
               hidden_cause = NULL
         WHERE id = %d",
        $mid
      ));
      echo '<div class="updated"><p>Meddelande #'.(int)$mid.' visat.</p></div>';
    }
  }

  // ------ filters (similar to Logs) ------
  $q          = sanitize_text_field($_GET['q'] ?? '');
  $room       = sanitize_text_field($_GET['room'] ?? '');
  $sender     = sanitize_text_field($_GET['sender'] ?? '');
  $recipient  = sanitize_text_field($_GET['recipient'] ?? '');
  $from       = sanitize_text_field($_GET['from'] ?? '');
  $to         = sanitize_text_field($_GET['to'] ?? '');
  $per        = max(12, min(1000, (int)($_GET['per'] ?? 50))); // 50 per page, max 1000
  $page       = max(1, (int)($_GET['paged'] ?? 1));
  $offset     = ($page - 1) * $per;

  // interpret q as: #ID, IP, or plain text
  $parsed_ip = '';
  $id_exact  = 0;
  if ($q !== '' && filter_var($q, FILTER_VALIDATE_IP)) $parsed_ip = $q;
  if ($q !== '' && preg_match('/^#?(\d{1,20})$/', $q, $m)) $id_exact = (int)$m[1];

  $where = ["kind = 'image'"];  // only images
  $params = [];

  if ($room !== '') { $where[] = "room = %s"; $params[] = $room; }
  if ($sender !== '') { $where[] = "sender_name LIKE %s"; $params[] = '%'.$wpdb->esc_like($sender).'%'; }
  if ($recipient !== '') { $where[] = "recipient_name LIKE %s"; $params[] = '%'.$wpdb->esc_like($recipient).'%'; }

  if ($from !== '') {
    $ts = strtotime($from.' 00:00:00');
    if ($ts) { $where[] = "created_at >= %d"; $params[] = $ts; }
  }
  if ($to !== '') {
    $ts = strtotime($to.' 23:59:59');
    if ($ts) { $where[] = "created_at <= %d"; $params[] = $ts; }
  }

  if ($id_exact > 0) {
    $where[] = "id = %d"; $params[] = $id_exact;
  } elseif ($parsed_ip !== '') {
    // search both sides by IP
    $where[] = "(sender_ip = %s OR recipient_ip = %s)"; $params[] = $parsed_ip; $params[] = $parsed_ip;
  } elseif ($q !== '') {
    // plain text search across url-content + names + room
    $like = '%'.$wpdb->esc_like($q).'%';
    $where[] = "(content LIKE %s OR sender_name LIKE %s OR recipient_name LIKE %s OR room LIKE %s)";
    array_push($params, $like, $like, $like, $like);
  }

  $whereSql = $where ? ('WHERE ' . implode(' AND ', array_map(fn($w)=>"($w)", $where))) : '';

  // total count
  $total = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$t['messages']} $whereSql", ...$params
  ));

  // page rows
  $sql = "SELECT id,created_at,kind,room,
                 sender_id,sender_name,sender_ip,
                 recipient_id,recipient_name,recipient_ip,
                 content,hidden_at,hidden_cause
            FROM {$t['messages']}
            $whereSql
        ORDER BY id DESC
           LIMIT %d OFFSET %d";
  $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per, $offset]))) ?: [];

  // rooms for filter dropdown
  $rooms = $wpdb->get_col("SELECT DISTINCT room FROM {$t['messages']} WHERE room IS NOT NULL AND room<>'' ORDER BY room ASC") ?: [];

  // ------ render ------
  ?>
  <div class="wrap">
    <h1>Bildmoderering</h1>

    <!-- Filters -->
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:12px 0 16px">
      <input type="hidden" name="page" value="kkchat_media">
      <input type="search" name="q" placeholder="Sök (#ID, IP eller text)" value="<?php echo esc_attr($q); ?>" class="regular-text" style="min-width:280px;margin-right:8px">
      <select name="room" style="margin-right:8px">
        <option value="">Alla rum</option>
        <?php foreach ($rooms as $r): ?>
          <option value="<?php echo esc_attr($r); ?>" <?php selected($room, $r); ?>><?php echo esc_html($r); ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="sender" placeholder="Avsändare" value="<?php echo esc_attr($sender); ?>" style="width:140px;margin-right:8px">
      <input type="text" name="recipient" placeholder="Mottagare" value="<?php echo esc_attr($recipient); ?>" style="width:140px;margin-right:8px">
      <input type="date" name="from" value="<?php echo esc_attr($from); ?>" style="margin-right:4px">
      <input type="date" name="to"   value="<?php echo esc_attr($to); ?>"   style="margin-right:8px">
<label>Visa antal:
  <input type="number" name="per" min="12" max="1000" value="<?php echo (int)$per; ?>" style="width:90px;margin-left:4px">
</label>
      <button class="button button-primary" style="margin-left:8px">Filtrera</button>
      <?php if ($q || $room || $sender || $recipient || $from || $to): ?>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=kkchat_media')); ?>">Rensa</a>
      <?php endif; ?>
    </form>

    <p style="margin:6px 0 14px; color:#666">
      Totalt: <?php echo (int)$total; ?> bilder.
      <?php if ($total > 0): ?>
        Visar <?php echo (int)min($per, $total - $offset); ?> av <?php echo (int)$total; ?> (sida <?php echo (int)$page; ?>).
      <?php endif; ?>
    </p>

    <!-- Gallery grid -->
    <style>
      .kkgrid { display:grid; grid-template-columns: repeat(auto-fill, minmax(220px,1fr)); gap:14px; }
      .kkcard { border:1px solid #ddd; border-radius:8px; overflow:hidden; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.04); display:flex; flex-direction:column; }
      .kkcard .thumb { width:100%; aspect-ratio:1/1; object-fit:cover; display:block; cursor:zoom-in; background:#fafafa; }
      .kkmeta { padding:10px 10px 6px; font-size:12px; line-height:1.45; color:#222; }
      .kkmeta .muted { color:#666; }
      .kkmeta .badge { display:inline-block; padding:2px 6px; border-radius:4px; border:1px solid #e3a1a1; background:#fbeaea; font-size:11px; margin-bottom:6px; }
      .kkactions { padding:10px; display:flex; gap:6px; flex-wrap:wrap; margin-top:auto; }
      .kkactions .button { height:auto; line-height:20px; padding:3px 8px; }
      .kkrow { margin:3px 0; }
      .kkrow strong { font-weight:600; }
      /* simple lightbox */
      #kkchatLightbox { position:fixed; inset:0; background:rgba(0,0,0,.8); display:none; align-items:center; justify-content:center; z-index:9999; }
      #kkchatLightbox.open { display:flex; }
      #kkchatLightbox img { max-width:95vw; max-height:95vh; }
      #kkchatLightbox .kkchat-close { position:absolute; top:12px; right:16px; color:#fff; font-size:28px; cursor:pointer; }
    </style>

    <div class="kkgrid">
      <?php foreach ($rows as $m): 
        $full = $is_image_url($m->content) ? $m->content : '';
        ?>
        <div class="kkcard">
          <?php if ($full): ?>
 <img src="<?php echo $u($full); ?>" alt="Bild #<?php echo (int)$m->id; ?>" class="thumb kkchat-thumb" data-full="<?php echo $u($full); ?>" loading="lazy" decoding="async">
          <?php else: ?>
            <div class="thumb" style="display:flex;align-items:center;justify-content:center;color:#999">Ingen bild-URL</div>
          <?php endif; ?>

          <div class="kkmeta">
            <?php if (!empty($m->hidden_at)): ?>
              <div class="badge">Dolt</div>
            <?php endif; ?>
            <div class="kkrow"><strong>#<?php echo (int)$m->id; ?></strong> <span class="muted">• <?php echo esc_html(date_i18n('Y-m-d H:i', (int)$m->created_at)); ?></span></div>
            <?php if ($m->room): ?><div class="kkrow"><strong>Rum:</strong> <?php echo $h($m->room); ?></div><?php endif; ?>
            <div class="kkrow"><strong>Avs:</strong> <?php echo $h($m->sender_name ?: ('ID '.$m->sender_id)); ?></div>
            <?php if ($m->recipient_id): ?><div class="kkrow"><strong>→</strong> <?php echo $h($m->recipient_name ?: ('ID '.$m->recipient_id)); ?></div><?php endif; ?>
            <?php if ($m->sender_ip): ?>
              <div class="kkrow"><strong>IP:</strong> <?php echo $h($m->sender_ip); ?></div>
            <?php endif; ?>
            <?php if (!empty($m->hidden_cause)): ?>
              <div class="kkrow muted"><?php echo $h($m->hidden_cause); ?></div>
            <?php endif; ?>
          </div>

          <div class="kkactions">
            <a class="button" href="<?php echo esc_url( admin_url('admin.php?page=kkchat_admin_logs&q=%23'.(int)$m->id) ); ?>">Visa i Loggar</a>

            <?php if ($m->sender_ip): ?>
              <button type="button" class="button"
                      data-ip="<?php echo esc_attr($m->sender_ip); ?>"
                      data-user-id="<?php echo (int)$m->sender_id; ?>"
                      data-user-name="<?php echo esc_attr($m->sender_name); ?>"
                      data-who="<?php echo esc_attr($m->sender_name ?: 'användare'); ?>"
                      onclick="kkBanIPFromLogs(this)">Blockera IP</button>
            <?php endif; ?>

            <form method="post" onsubmit="return confirm('Säker?');" style="display:inline;margin:0">
              <?php wp_nonce_field('kkchat_logs_actions'); ?>
              <input type="hidden" name="mid" value="<?php echo (int)$m->id; ?>">
              <?php if (!empty($m->hidden_at)): ?>
                <button class="button" name="kk_unhide" value="1">Visa</button>
              <?php else: ?>
                <input type="text" name="cause" class="regular-text" placeholder="Anledning (valfritt)" style="width:140px">
                <button class="button" name="kk_hide" value="1">Dölj</button>
              <?php endif; ?>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php
    // pagination
    // pagination: Föregående / Nästa + "Gå till sida"
$pages = max(1, (int)ceil($total / $per));
if ($pages > 1):
  // Build common query args so we keep current filters
  $common = [
    'page'      => 'kkchat_media',
    'q'         => $q,
    'room'      => $room,
    'sender'    => $sender,
    'recipient' => $recipient,
    'from'      => $from,
    'to'        => $to,
    'per'       => $per,
  ];
  $baseUrl = admin_url('admin.php');
  $prevUrl = $page > 1 ? esc_url(add_query_arg(array_merge($common, ['paged' => $page - 1]), $baseUrl)) : '';
  $nextUrl = $page < $pages ? esc_url(add_query_arg(array_merge($common, ['paged' => $page + 1]), $baseUrl)) : '';
  ?>
  <div class="tablenav" style="margin-top:12px">
    <div class="tablenav-pages" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <?php if ($prevUrl): ?>
        <a class="button" href="<?php echo $prevUrl; ?>">&laquo; Föregående</a>
      <?php else: ?>
        <span class="tablenav-pages-navspan">&laquo; Föregående</span>
      <?php endif; ?>

      <span class="pagination-links">Sida <?php echo (int)$page; ?> av <?php echo (int)$pages; ?></span>

      <?php if ($nextUrl): ?>
        <a class="button" href="<?php echo $nextUrl; ?>">Nästa &raquo;</a>
      <?php else: ?>
        <span class="tablenav-pages-navspan">Nästa &raquo;</span>
      <?php endif; ?>

      <form method="get" action="<?php echo esc_url($baseUrl); ?>" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px">
        <?php foreach ($common as $k => $v): ?>
          <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr((string)$v); ?>">
        <?php endforeach; ?>
        <label for="kk_go_paged"><strong>Gå till sida:</strong></label>
        <select id="kk_go_paged" name="paged">
          <?php for ($p = 1; $p <= $pages; $p++): ?>
            <option value="<?php echo (int)$p; ?>" <?php echo selected($p, $page, false); ?>><?php echo (int)$p; ?></option>
          <?php endfor; ?>
        </select>
        <button class="button">Gå</button>
      </form>
    </div>
  </div>
  <?php
endif;

    ?>

    <!-- hidden form reused by IP-ban (same as Logs) -->
    <form id="kkbanip_form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:none">
      <input type="hidden" name="action" value="kkchat_logs_ipban">
      <?php wp_nonce_field('kkchat_logs_ipban'); ?>
      <input type="hidden" name="ip" value="">
      <input type="hidden" name="minutes" value="">
      <input type="hidden" name="cause" value="">
      <input type="hidden" name="user_id" value="">
      <input type="hidden" name="user_name" value="">
      <input type="hidden" name="user_wp_username" value="">
    </form>

    <!-- lightbox & IP-ban prompt -->
    <div id="kkchatLightbox" role="dialog" aria-modal="true" aria-label="Bildförhandsvisning">
      <span class="kkchat-close" aria-label="Stäng">×</span>
      <img src="" alt="">
    </div>

    <script>
      (function(){
        // IP-block prompt (reuse Logs behavior)
        window.kkBanIPFromLogs = function(source, whoLabel) {
          var data = {
            ip: '',
            who: whoLabel || '',
            userId: '',
            userName: '',
            wpUsername: ''
          };
          if (source && typeof source === 'object' && source.dataset) {
            data.ip = source.dataset.ip || '';
            if (!data.who && source.dataset.who) data.who = source.dataset.who;
            data.userId = source.dataset.userId || '';
            data.userName = source.dataset.userName || '';
            data.wpUsername = source.dataset.wpUsername || '';
          } else if (typeof source === 'string') {
            data.ip = source;
          }
          if (!data.ip) return;
          var title = 'Blockera ' + data.ip + ' (' + (data.who || 'användare') + ')';
          var minutesStr = window.prompt(title + '\n\nTid i minuter (0 = för alltid):', '0');
          if (minutesStr === null) return;
          var minutes = parseInt(minutesStr, 10);
          if (isNaN(minutes) || minutes < 0) { alert('Ange ett icke-negativt antal minuter.'); return; }
          var reason = window.prompt('Anledning (valfritt):', '');
          if (reason === null) return;
          var f = document.getElementById('kkbanip_form');
          if (!f) return;
          var setVal = function(name, value) {
            var input = f.querySelector('input[name="' + name + '"]');
            if (input) input.value = value || '';
          };
          setVal('ip', data.ip);
          setVal('minutes', String(minutes));
          setVal('cause', reason || '');
          setVal('user_id', data.userId);
          setVal('user_name', data.userName);
          setVal('user_wp_username', data.wpUsername);
          f.submit();
        };

        // Simple lightbox for .kkchat-thumb
        var lb = document.getElementById('kkchatLightbox');
        var lbImg = lb ? lb.querySelector('img') : null;
        document.addEventListener('click', function(e){
          var t = e.target;
          if (t && t.classList && t.classList.contains('kkchat-thumb')) {
            e.preventDefault();
            var src = t.getAttribute('data-full') || t.src;
            var alt = t.getAttribute('alt') || '';
            if (lb && lbImg) { lbImg.src = src; lbImg.alt = alt; lb.classList.add('open'); }
          } else if (lb && lb.classList.contains('open') && (t === lb || (t && t.classList && t.classList.contains('kkchat-close')) || t === lbImg)) {
            lb.classList.remove('open'); if (lbImg) lbImg.src = '';
          }
        });
        document.addEventListener('keydown', function(e){
          if ((e.key === 'Escape' || e.key === 'Esc') && lb && lb.classList.contains('open')) {
            lb.classList.remove('open'); if (lbImg) lbImg.src = '';
          }
        });
      })();
    </script>
  </div>
  <?php
}

/**
 * Inställningar (anti-spam & dubbletter)
 */
function kkchat_admin_settings_page() {
  if (!current_user_can('manage_options')) return;
  $nonce_key = 'kkchat_settings';

  // Spara
  if (isset($_POST['kk_save_settings'])) {
    check_admin_referer($nonce_key);

    $dupe_window_seconds   = max(1, (int)($_POST['dupe_window_seconds'] ?? 120));
    $dupe_fast_seconds     = max(1, (int)($_POST['dupe_fast_seconds'] ?? 30));
    $dupe_max_repeats      = max(1, (int)($_POST['dupe_max_repeats'] ?? 2));
    $min_interval_seconds  = max(0, (int)($_POST['min_interval_seconds'] ?? 3)); // 0 = av
    $dupe_autokick_minutes = max(0, (int)($_POST['dupe_autokick_minutes'] ?? 1)); // 0 = av
    $dedupe_window         = max(1, (int)($_POST['dedupe_window'] ?? 10)); // direkt-spamskydd

    $poll_hidden_threshold = max(0, (int)($_POST['poll_hidden_threshold'] ?? 90));
    $poll_hidden_delay     = max(0, (int)($_POST['poll_hidden_delay'] ?? 30));
    $poll_hot_interval     = max(1, (int)($_POST['poll_hot_interval'] ?? 4));
    $poll_medium_interval  = max(1, (int)($_POST['poll_medium_interval'] ?? 8));
    $poll_slow_interval    = max(1, (int)($_POST['poll_slow_interval'] ?? 16));
    $poll_medium_after     = max(0, (int)($_POST['poll_medium_after'] ?? 3));
    $poll_slow_after       = max($poll_medium_after, (int)($_POST['poll_slow_after'] ?? 5));
    $poll_extra_2g         = max(0, (int)($_POST['poll_extra_2g'] ?? 20));
    $poll_extra_3g         = max(0, (int)($_POST['poll_extra_3g'] ?? 10));

    $poll_medium_interval  = max($poll_hot_interval, $poll_medium_interval);
    $poll_slow_interval    = max($poll_medium_interval, $poll_slow_interval);

    update_option('kkchat_dupe_window_seconds',   $dupe_window_seconds);
    update_option('kkchat_dupe_fast_seconds',     $dupe_fast_seconds);
    update_option('kkchat_dupe_max_repeats',      $dupe_max_repeats);
    update_option('kkchat_min_interval_seconds',  $min_interval_seconds);
    update_option('kkchat_dupe_autokick_minutes', $dupe_autokick_minutes);
    update_option('kkchat_dedupe_window',         $dedupe_window);
    update_option('kkchat_poll_hidden_threshold', $poll_hidden_threshold);
    update_option('kkchat_poll_hidden_delay',     $poll_hidden_delay);
    update_option('kkchat_poll_hot_interval',     $poll_hot_interval);
    update_option('kkchat_poll_medium_interval',  $poll_medium_interval);
    update_option('kkchat_poll_slow_interval',    $poll_slow_interval);
    update_option('kkchat_poll_medium_after',     $poll_medium_after);
    update_option('kkchat_poll_slow_after',       $poll_slow_after);
    update_option('kkchat_poll_extra_2g',         $poll_extra_2g);
    update_option('kkchat_poll_extra_3g',         $poll_extra_3g);

    echo '<div class="updated"><p>Inställningar sparade.</p></div>';
  }

  // Värden
  $v_dupe_window_seconds   = (int)get_option('kkchat_dupe_window_seconds', 120);
  $v_dupe_fast_seconds     = (int)get_option('kkchat_dupe_fast_seconds', 30);
  $v_dupe_max_repeats      = (int)get_option('kkchat_dupe_max_repeats', 2);
  $v_min_interval_seconds  = (int)get_option('kkchat_min_interval_seconds', 3);
  $v_dupe_autokick_minutes = (int)get_option('kkchat_dupe_autokick_minutes', 1);
  $v_dedupe_window         = (int)get_option('kkchat_dedupe_window', 10);
  $v_poll_hidden_threshold = (int)get_option('kkchat_poll_hidden_threshold', 90);
  $v_poll_hidden_delay     = (int)get_option('kkchat_poll_hidden_delay', 30);
  $v_poll_hot_interval     = (int)get_option('kkchat_poll_hot_interval', 4);
  $v_poll_medium_interval  = (int)get_option('kkchat_poll_medium_interval', 8);
  $v_poll_slow_interval    = (int)get_option('kkchat_poll_slow_interval', 16);
  $v_poll_medium_after     = (int)get_option('kkchat_poll_medium_after', 3);
  $v_poll_slow_after       = (int)get_option('kkchat_poll_slow_after', 5);
  $v_poll_extra_2g         = (int)get_option('kkchat_poll_extra_2g', 20);
  $v_poll_extra_3g         = (int)get_option('kkchat_poll_extra_3g', 10);
  ?>
  <div class="wrap">
    <h1>KKchat – Inställningar</h1>
    <form method="post"><?php wp_nonce_field($nonce_key); ?>
      <table class="form-table">
        <tr>
          <th><label for="min_interval_seconds">Minsta intervall</label></th>
          <td>
            <input id="min_interval_seconds" name="min_interval_seconds" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_min_interval_seconds; ?>"> sekunder
            <p class="description">Anti-flood: minsta tid mellan två meddelanden från samma användare (0 = av).</p>
          </td>
        </tr>
        <tr>
          <th><label for="dedupe_window">Direkt-dedupe</label></th>
          <td>
            <input id="dedupe_window" name="dedupe_window" type="number" class="small-text" min="1" step="1" value="<?php echo (int)$v_dedupe_window; ?>"> sekunder
            <p class="description">Blockera exakt identiskt meddelande i samma rum/DM under detta fönster.</p>
          </td>
        </tr>
        <tr>
          <th><label for="dupe_window_seconds">Räknefönster dubbletter</label></th>
          <td>
            <input id="dupe_window_seconds" name="dupe_window_seconds" type="number" class="small-text" min="1" step="1" value="<?php echo (int)$v_dupe_window_seconds; ?>"> sekunder
            <p class="description">Hur länge vi räknar upprepningar för att kunna auto-agera.</p>
          </td>
        </tr>
        <tr>
          <th><label for="dupe_fast_seconds">Snabbfönster</label></th>
          <td>
            <input id="dupe_fast_seconds" name="dupe_fast_seconds" type="number" class="small-text" min="1" step="1" value="<?php echo (int)$v_dupe_fast_seconds; ?>"> sekunder
            <p class="description">Om samma text repeteras inom detta korta fönster betraktas det som aggressiv spam.</p>
          </td>
        </tr>
        <tr>
          <th><label for="dupe_max_repeats">Max dubbletter</label></th>
          <td>
            <input id="dupe_max_repeats" name="dupe_max_repeats" type="number" class="small-text" min="1" step="1" value="<?php echo (int)$v_dupe_max_repeats; ?>">
            <p class="description">Exempel: 2 = original + 1 upprepning tillåts, sedan åtgärd.</p>
          </td>
        </tr>
        <tr>
          <th><label for="dupe_autokick_minutes">Auto-kick</label></th>
          <td>
            <input id="dupe_autokick_minutes" name="dupe_autokick_minutes" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_dupe_autokick_minutes; ?>"> minuter
            <p class="description">0 = av. Annars kickas användaren i så här många minuter vid dubblett-spam.</p>
          </td>
        </tr>
      </table>
      <h2>Polling</h2>
      <table class="form-table">
        <tr>
          <th><label for="poll_hidden_threshold">Dold flik – tröskel</label></th>
          <td>
            <input id="poll_hidden_threshold" name="poll_hidden_threshold" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_poll_hidden_threshold; ?>"> sekunder
            <p class="description">Efter så här många sekunder dold flik räknas som "borta".</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_hidden_delay">Dold flik – intervall</label></th>
          <td>
            <input id="poll_hidden_delay" name="poll_hidden_delay" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_poll_hidden_delay; ?>"> sekunder
            <p class="description">Så lång tid väntar vi mellan pollningar när fliken varit dold längre än tröskeln.</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_hot_interval">Synlig flik – het polling</label></th>
          <td>
            <input id="poll_hot_interval" name="poll_hot_interval" type="number" class="small-text" min="1" step="1" value="<?php echo (int)$v_poll_hot_interval; ?>"> sekunder
            <p class="description">Intervallet när användaren är aktiv eller nyss återvänt.</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_medium_interval">Synlig flik – mellanläge</label></th>
          <td>
            <input id="poll_medium_interval" name="poll_medium_interval" type="number" class="small-text" min="1" step="1" value="<?php echo (int)$v_poll_medium_interval; ?>"> sekunder
            <p class="description">Intervallet när senaste aktivitet var för 3–5 minuter sedan.</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_slow_interval">Synlig flik – långsamt</label></th>
          <td>
            <input id="poll_slow_interval" name="poll_slow_interval" type="number" class="small-text" min="1" step="1" value="<?php echo (int)$v_poll_slow_interval; ?>"> sekunder
            <p class="description">Intervallet när användaren varit inaktiv i över 5 minuter.</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_medium_after">Aktivitet → mellanläge</label></th>
          <td>
            <input id="poll_medium_after" name="poll_medium_after" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_poll_medium_after; ?>"> minuter
            <p class="description">Efter så här många minuter utan aktivitet byter vi till mellanläget.</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_slow_after">Aktivitet → långsamt</label></th>
          <td>
            <input id="poll_slow_after" name="poll_slow_after" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_poll_slow_after; ?>"> minuter
            <p class="description">Efter så här många minuter utan aktivitet byter vi till långsamt läge.</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_extra_2g">Extra för 2G</label></th>
          <td>
            <input id="poll_extra_2g" name="poll_extra_2g" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_poll_extra_2g; ?>"> sekunder
            <p class="description">Addera så här många sekunder om anslutningen är 2G/slow-2G.</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_extra_3g">Extra för 3G</label></th>
          <td>
            <input id="poll_extra_3g" name="poll_extra_3g" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_poll_extra_3g; ?>"> sekunder
            <p class="description">Addera så här många sekunder om anslutningen är 3G.</p>
          </td>
        </tr>
      </table>
      <p><button class="button button-primary" name="kk_save_settings" value="1">Spara</button></p>
    </form>
  </div>
  <?php
}

/**
 * Rum
 */
function kkchat_admin_rooms_page() {
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();

  if (isset($_POST['kk_add_room'])) {
    check_admin_referer('kkchat_rooms');
    $slug  = kkchat_sanitize_room_slug($_POST['slug'] ?? '');
    $title = sanitize_text_field($_POST['title'] ?? '');
    $mo    = !empty($_POST['member_only']) ? 1 : 0;
    $sort  = (int)($_POST['sort'] ?? 100);

    if (!$slug || !$title) {
      echo '<div class="error"><p>Slug och titel krävs.</p></div>';
    } else {
      $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['rooms']} WHERE slug=%s", $slug));
      if ($exists) {
        echo '<div class="error"><p>Ett rum med den slugen finns redan.</p></div>';
      } else {
        $ok = $wpdb->insert(
          $t['rooms'],
          ['slug'=>$slug, 'title'=>$title, 'member_only'=>$mo, 'sort'=>$sort],
          ['%s','%s','%d','%d']
        );
        if ($ok === false) {
          $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
          echo '<div class="error"><p>Kunde inte spara rum: '.$err.'</p></div>';
        } else {
          echo '<div class="updated"><p>Rummet sparat.</p></div>';
        }
      }
    }
  }

  if (isset($_GET['kk_del_room'])) {
    $slug = kkchat_sanitize_room_slug($_GET['kk_del_room']);
    if ($slug && check_admin_referer('kkchat_rooms_del_'.$slug)) {
      $wpdb->delete($t['rooms'], ['slug'=>$slug], ['%s']);
      echo '<div class="updated"><p>Rummet borttaget.</p></div>';
    }
  }

  $rooms = $wpdb->get_results("SELECT * FROM {$t['rooms']} ORDER BY sort ASC, title ASC");
  ?>
  <div class="wrap">
    <h1>KKchat – Rum</h1>
    <h2>Lägg till / Redigera</h2>
    <form method="post"><?php wp_nonce_field('kkchat_rooms'); ?>
      <table class="form-table">
        <tr><th><label for="slug">Slug</label></th><td><input name="slug" id="slug" required class="regular-text" placeholder="t.ex. general, klubb, vip"></td></tr>
        <tr><th><label for="title">Titel</label></th><td><input name="title" id="title" required class="regular-text" placeholder="Synligt rumsnamn"></td></tr>
        <tr><th><label for="sort">Sortering</label></th><td><input name="sort" id="sort" type="number" value="100" class="small-text"> (lägre = först)</td></tr>
        <tr><th>Endast medlemmar</th><td><label><input type="checkbox" name="member_only" value="1"> Endast inloggade WP-medlemmar (inga gäster)</label></td></tr>
      </table>
      <p><button class="button button-primary" name="kk_add_room" value="1">Spara rum</button></p>
    </form>

    <h2>Befintliga rum</h2>
    <table class="widefat striped">
      <thead><tr><th>Slug</th><th>Titel</th><th>Endast medlemmar</th><th>Sortering</th><th>Åtgärder</th></tr></thead>
      <tbody>
      <?php if ($rooms): foreach ($rooms as $r): ?>
        <tr>
          <td><?php echo esc_html($r->slug); ?></td>
          <td><?php echo esc_html($r->title); ?></td>
          <td><?php echo $r->member_only ? 'Ja' : 'Nej'; ?></td>
          <td><?php echo (int)$r->sort; ?></td>
          <td>
            <?php
              $rooms_base = menu_page_url('kkchat_rooms', false);
              $del_url = wp_nonce_url(add_query_arg('kk_del_room', $r->slug, $rooms_base), 'kkchat_rooms_del_'.$r->slug);
              $conf = sprintf('Ta bort rummet &quot;%s&quot;? Meddelanden finns kvar.', esc_js($r->title));
            ?>
            <a href="<?php echo esc_url($del_url); ?>" class="button button-secondary" onclick="return confirm('<?php echo $conf; ?>');">Ta bort</a>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="5">Inga rum ännu.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
}

/**
 * Banderoller
 */
function kkchat_admin_banners_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce_key = 'kkchat_banners';
  $rooms = $wpdb->get_results("SELECT slug,title FROM {$t['rooms']} ORDER BY sort ASC, title ASC", ARRAY_A);

  if (isset($_POST['kk_add_banner'])) {
    check_admin_referer($nonce_key);
    $content = sanitize_textarea_field($_POST['content'] ?? '');
    $every   = max(60, (int)($_POST['every_sec'] ?? 60));
    $sel     = array_map('kkchat_sanitize_room_slug', (array)($_POST['rooms'] ?? []));
    $sel     = array_values(array_unique(array_filter($sel)));
    if (!$content || !$sel) {
      echo '<div class="error"><p>Innehåll och minst ett rum krävs.</p></div>';
    } else {
      $ok = $wpdb->insert(
        $t['banners'],
        [
          'content'     => $content,
          'rooms_csv'   => implode(',', $sel),
          'interval_sec'=> $every,
          'next_run'    => time() + $every,
          'active'      => 1
        ],
        ['%s','%s','%d','%d','%d']
      );
      if ($ok === false) {
        $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
        echo '<div class="error"><p>Kunde inte spara banderoll: '.$err.'</p></div>';
      } else {
        echo '<div class="updated"><p>Banderoll schemalagd.</p></div>';
      }
    }
  }

  if (isset($_GET['kk_toggle']) && check_admin_referer($nonce_key)) {
    $id  = (int)$_GET['kk_toggle'];
    $cur = (int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t['banners']} WHERE id=%d", $id));
    if ($cur !== null) {
      $wpdb->update($t['banners'], ['active'=>$cur?0:1], ['id'=>$id], ['%d'], ['%d']);
      echo '<div class="updated"><p>Status uppdaterad.</p></div>';
    }
  }
  if (isset($_GET['kk_delete']) && check_admin_referer($nonce_key)) {
    $id = (int)$_GET['kk_delete'];
    $wpdb->delete($t['banners'], ['id'=>$id], ['%d']);
    echo '<div class="updated"><p>Banderoll raderad.</p></div>';
  }
  if (isset($_GET['kk_run_now']) && check_admin_referer($nonce_key)) {
    $id = (int)$_GET['kk_run_now'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $id), ARRAY_A);
    if ($row && (int)$row['active'] === 1) {
      $now = time();
      $roomsSel = array_filter(array_map('kkchat_sanitize_room_slug', explode(',', (string)$row['rooms_csv'])));
      foreach ($roomsSel as $slug) {
        $wpdb->insert($t['messages'], [
          'created_at'     => $now,
          'sender_id'      => 0,
          'sender_name'    => 'System',
          'recipient_id'   => null,
          'recipient_name' => null,
          'recipient_ip'   => null,
          'room'           => $slug,
          'kind'           => 'banner',
          'content'        => kkchat_html_esc($row['content']),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%s']);
      }
      $wpdb->update($t['banners'], ['last_run'=>$now, 'next_run'=>$now + max(60,(int)$row['interval_sec'])], ['id'=>$id], ['%d','%d'], ['%d']);
      echo '<div class="updated"><p>Banderoll postad.</p></div>';
    }
  }

  $list = $wpdb->get_results("SELECT * FROM {$t['banners']} ORDER BY id DESC");
  $banners_base = menu_page_url('kkchat_banners', false);
  ?>
  <div class="wrap">
    <h1>KKchat – Banderoller</h1>
    <h2>Ny schemalagd banderoll</h2>
    <form method="post"><?php wp_nonce_field($nonce_key); ?>
      <table class="form-table">
        <tr>
          <th><label for="kkb_content">Meddelande</label></th>
          <td>
            <textarea id="kkb_content" name="content" rows="3" class="large-text" required></textarea>
            <p class="description">Ren text. Visas centrerat som banderoll i valda rum.</p>
          </td>
        </tr>
        <tr>
          <th>Rum</th>
          <td>
            <?php foreach ($rooms as $r): ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="rooms[]" value="<?php echo esc_attr($r['slug']); ?>">
                <?php echo esc_html($r['title']); ?> <code><?php echo esc_html($r['slug']); ?></code>
              </label>
            <?php endforeach; ?>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_every">Intervall</label></th>
          <td><input id="kkb_every" name="every_sec" type="number" min="60" step="60" value="600" class="small-text"> sekunder</td>
        </tr>
      </table>
      <p><button class="button button-primary" name="kk_add_banner" value="1">Spara schema</button></p>
    </form>

    <h2>Scheman</h2>
    <table class="widefat striped">
      <thead><tr><th>ID</th><th>Innehåll</th><th>Rum</th><th>Intervall</th><th>Nästa körning</th><th>Aktiv</th><th>Åtgärder</th></tr></thead>
      <tbody>
        <?php if ($list): foreach ($list as $b): ?>
          <tr>
            <td><?php echo (int)$b->id; ?></td>
            <td><?php echo esc_html(mb_strimwidth((string)$b->content, 0, 100, '…')); ?></td>
            <td><?php echo esc_html((string)$b->rooms_csv); ?></td>
            <td><?php echo (int)$b->interval_sec; ?> s</td>
            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$b->next_run)); ?></td>
            <td><?php echo $b->active ? 'Ja' : 'Nej'; ?></td>
            <td>
              <?php
                $run_url = wp_nonce_url(add_query_arg('kk_run_now', $b->id, $banners_base), $nonce_key);
                $tog_url = wp_nonce_url(add_query_arg('kk_toggle',  $b->id, $banners_base), $nonce_key);
                $del_url = wp_nonce_url(add_query_arg('kk_delete',  $b->id, $banners_base), $nonce_key);
              ?>
              <a class="button" href="<?php echo esc_url($run_url); ?>">Kör nu</a>
              <a class="button" href="<?php echo esc_url($tog_url); ?>"><?php echo $b->active ? 'Inaktivera' : 'Aktivera'; ?></a>
              <a class="button button-danger" href="<?php echo esc_url($del_url); ?>" onclick="return confirm('Ta bort detta schema?');">Ta bort</a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7">Inga banderollscheman ännu.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
}

/**
 * Moderering
 */
function kkchat_admin_moderation_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce_key = 'kkchat_moderation';

  // Spara adminanvändare
  if (isset($_POST['kk_save_admins'])) {
    check_admin_referer($nonce_key);
    $txt = sanitize_textarea_field($_POST['admin_usernames'] ?? '');
    update_option('kkchat_admin_users', $txt);
    echo '<div class="updated"><p>Admins sparade.</p></div>';
  }

  // Manuell IP-block (med IPv6 /64-nyckel + dubbelkoll)
  if (isset($_POST['kk_manual_ipban'])) {
    check_admin_referer($nonce_key);
    $ip      = trim((string)($_POST['ban_ip'] ?? ''));
    $minutes = max(0, (int)($_POST['ban_minutes'] ?? 0));
    $cause   = sanitize_text_field($_POST['ban_cause'] ?? '');

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      echo '<div class="error"><p>Ogiltig IP-adress.</p></div>';
    } else {
      $ipKey = kkchat_ip_ban_key($ip);
      if (!$ipKey) {
        echo '<div class="error"><p>Kunde inte normalisera IP-adressen.</p></div>';
      } else {
        // redan aktivt?
        $exists = (int)$wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$t['blocks']} WHERE type='ipban' AND target_ip=%s AND active=1", $ipKey
        ));
        if ($exists > 0) {
          echo '<div class="updated"><p>Det finns redan ett aktivt block för IP <code>'.esc_html($ipKey).'</code>.</p></div>';
        } else {
          $now   = time();
          $exp   = $minutes > 0 ? $now + $minutes * 60 : null;
          $admin = wp_get_current_user()->user_login ?? '';
          $ok = $wpdb->insert($t['blocks'], [
            'type'               => 'ipban',
            'target_user_id'     => null,
            'target_name'        => null,
            'target_wp_username' => null,
            'target_ip'          => $ipKey, // <-- spara nyckeln
            'cause'              => $cause ?: null,
            'created_by'         => $admin ?: null,
            'created_at'         => $now,
            'expires_at'         => $exp,
            'active'             => 1
          ], ['%s','%d','%s','%s','%s','%s','%s','%d','%d','%d']);
          if ($ok === false) {
            $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
            echo '<div class="error"><p>Kunde inte skapa IP-block: '.$err.'</p></div>';
          } else {
            if ($exp === null) {
              $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET expires_at = NULL WHERE id=%d", (int)$wpdb->insert_id));
            }
            echo '<div class="updated"><p>IP <code>'.esc_html($ipKey).'</code> blockerad '.($exp ? 'till '.esc_html(date_i18n('Y-m-d H:i:s', $exp)) : 'för alltid').'.</p></div>';
          }
        }
      }
    }
  }

  // Avblockera
  if (isset($_GET['unblock']) && check_admin_referer($nonce_key)) {
    $id = (int)$_GET['unblock'];
    $wpdb->update($t['blocks'], ['active'=>0], ['id'=>$id], ['%d'], ['%d']);
    echo '<div class="updated"><p>Blockering inaktiverad.</p></div>';
  }

  $admins_txt = (string)get_option('kkchat_admin_users','');

  $active_per  = 50;
  $recent_per  = 50;
  $active_page = max(1, (int)($_GET['active_page'] ?? 1));
  $recent_page = max(1, (int)($_GET['recent_page'] ?? 1));

  $active_total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['blocks']} WHERE active=1");
  $active_pages = max(1, (int)ceil($active_total / $active_per));
  if ($active_page > $active_pages) $active_page = $active_pages;
  $active_offset = ($active_page - 1) * $active_per;
  $active = [];
  if ($active_total > 0) {
    $active = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$t['blocks']} WHERE active=1 ORDER BY created_at DESC LIMIT %d OFFSET %d",
      $active_per, $active_offset
    ));
  }

  $recent_total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['blocks']}");
  $recent_pages = max(1, (int)ceil($recent_total / $recent_per));
  if ($recent_page > $recent_pages) $recent_page = $recent_pages;
  $recent_offset = ($recent_page - 1) * $recent_per;
  $recent = [];
  if ($recent_total > 0) {
    $recent = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$t['blocks']} ORDER BY created_at DESC LIMIT %d OFFSET %d",
      $recent_per, $recent_offset
    ));
  }

  $moderation_base = menu_page_url('kkchat_moderation', false);
  ?>
  <div class="wrap">
    <h1>KKchat – Moderering</h1>

    <h2>Admins (via WP-användarnamn)</h2>
    <form method="post"><?php wp_nonce_field($nonce_key); ?>
      <p>Ett användarnamn per rad. Skiftlägesokänsligt. Gäster kan inte vara admins.</p>
      <textarea name="admin_usernames" rows="6" class="large-text" placeholder="alice&#10;bob&#10;eve"><?php echo esc_textarea($admins_txt); ?></textarea>
      <p><button class="button button-primary" name="kk_save_admins" value="1">Spara</button></p>
    </form>

    <h2>Manuell IP-blockering</h2>
    <form method="post"><?php wp_nonce_field($nonce_key); ?>
      <table class="form-table">
        <tr><th><label for="ban_ip">IP-adress</label></th><td><input id="ban_ip" name="ban_ip" class="regular-text" placeholder="t.ex. 203.0.113.5 eller 2001:db8::1" required></td></tr>
        <tr><th><label for="ban_minutes">Varaktighet</label></th><td><input id="ban_minutes" name="ban_minutes" type="number" min="0" class="small-text" value="0"> minuter <span class="description">(0 = för alltid)</span></td></tr>
        <tr><th><label for="ban_cause">Orsak (valfritt)</label></th><td><input id="ban_cause" name="ban_cause" class="regular-text" placeholder="Anledning"></td></tr>
      </table>
      <p><button class="button button-primary" name="kk_manual_ipban" value="1">Blockera IP</button></p>
    </form>

    <h2>Aktiva blockeringar</h2>
    <?php
      $active_count = is_array($active) || $active instanceof Countable ? count($active) : 0;
      if ($active_total > 0 && $active_count > 0):
        $active_from = $active_offset + 1;
        $active_to   = $active_offset + $active_count;
    ?>
      <p class="description">Visar <?php echo (int)$active_from; ?>–<?php echo (int)min($active_to, $active_total); ?> av <?php echo (int)$active_total; ?> (sida <?php echo (int)$active_page; ?> av <?php echo (int)$active_pages; ?>)</p>
    <?php elseif ($active_total > 0): ?>
      <p class="description">Inga poster på denna sida.</p>
    <?php endif; ?>
    <table class="widefat striped">
      <thead><tr><th>ID</th><th>Typ</th><th>Mål</th><th>IP</th><th>Orsak</th><th>Av</th><th>Skapad</th><th>Löper ut</th><th>Åtgärd</th></tr></thead>
      <tbody>
      <?php if ($active): foreach ($active as $b): ?>
        <tr>
          <td><?php echo (int)$b->id; ?></td>
          <td><?php echo esc_html($b->type); ?></td>
          <td><?php echo esc_html($b->target_wp_username ?: $b->target_name ?: ('#'.$b->target_user_id)); ?></td>
          <td><?php echo esc_html($b->target_ip); ?></td>
          <td><?php echo esc_html($b->cause); ?></td>
          <td><?php echo esc_html($b->created_by); ?></td>
          <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$b->created_at)); ?></td>
          <td><?php echo $b->expires_at ? esc_html(date_i18n('Y-m-d H:i:s', (int)$b->expires_at)) : '∞'; ?></td>
          <td>
            <?php $unblock_url = wp_nonce_url(add_query_arg('unblock', $b->id, $moderation_base), $nonce_key); ?>
            <a class="button" href="<?php echo esc_url($unblock_url); ?>">Avblockera</a>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="9">Inga aktiva block.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php if ($active_pages > 1):
      $active_prev = $active_page > 1 ? add_query_arg(['active_page' => $active_page - 1, 'recent_page' => $recent_page], $moderation_base) : '';
      $active_next = $active_page < $active_pages ? add_query_arg(['active_page' => $active_page + 1, 'recent_page' => $recent_page], $moderation_base) : '';
    ?>
      <div class="tablenav" style="margin:10px 0 20px">
        <div class="tablenav-pages" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <?php if ($active_prev): ?>
            <a class="button" href="<?php echo esc_url($active_prev); ?>">&laquo; Föregående</a>
          <?php else: ?>
            <span class="tablenav-pages-navspan">&laquo; Föregående</span>
          <?php endif; ?>
          <span class="pagination-links">Sida <?php echo (int)$active_page; ?> av <?php echo (int)$active_pages; ?></span>
          <?php if ($active_next): ?>
            <a class="button" href="<?php echo esc_url($active_next); ?>">Nästa &raquo;</a>
          <?php else: ?>
            <span class="tablenav-pages-navspan">Nästa &raquo;</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <h2>Senaste åtgärder</h2>
    <?php
      $recent_count = is_array($recent) || $recent instanceof Countable ? count($recent) : 0;
      if ($recent_total > 0 && $recent_count > 0):
        $recent_from = $recent_offset + 1;
        $recent_to   = $recent_offset + $recent_count;
    ?>
      <p class="description">Visar <?php echo (int)$recent_from; ?>–<?php echo (int)min($recent_to, $recent_total); ?> av <?php echo (int)$recent_total; ?> (sida <?php echo (int)$recent_page; ?> av <?php echo (int)$recent_pages; ?>)</p>
    <?php elseif ($recent_total > 0): ?>
      <p class="description">Inga poster på denna sida.</p>
    <?php endif; ?>
    <table class="widefat striped">
      <thead><tr><th>ID</th><th>Typ</th><th>Mål</th><th>IP</th><th>Orsak</th><th>Av</th><th>Skapad</th><th>Löper ut</th><th>Aktiv</th></tr></thead>
      <tbody>
      <?php if ($recent): foreach ($recent as $b): ?>
        <tr>
          <td><?php echo (int)$b->id; ?></td>
          <td><?php echo esc_html($b->type); ?></td>
          <td><?php echo esc_html($b->target_wp_username ?: $b->target_name ?: ('#'.$b->target_user_id)); ?></td>
          <td><?php echo esc_html($b->target_ip); ?></td>
          <td><?php echo esc_html($b->cause); ?></td>
          <td><?php echo esc_html($b->created_by); ?></td>
          <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$b->created_at)); ?></td>
          <td><?php echo $b->expires_at ? esc_html(date_i18n('Y-m-d H:i:s', (int)$b->expires_at)) : '∞'; ?></td>
          <td><?php echo $b->active ? 'Ja' : 'Nej'; ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="9">Inga poster.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php if ($recent_pages > 1):
      $recent_prev = $recent_page > 1 ? add_query_arg(['active_page' => $active_page, 'recent_page' => $recent_page - 1], $moderation_base) : '';
      $recent_next = $recent_page < $recent_pages ? add_query_arg(['active_page' => $active_page, 'recent_page' => $recent_page + 1], $moderation_base) : '';
    ?>
      <div class="tablenav" style="margin:10px 0 20px">
        <div class="tablenav-pages" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <?php if ($recent_prev): ?>
            <a class="button" href="<?php echo esc_url($recent_prev); ?>">&laquo; Föregående</a>
          <?php else: ?>
            <span class="tablenav-pages-navspan">&laquo; Föregående</span>
          <?php endif; ?>
          <span class="pagination-links">Sida <?php echo (int)$recent_page; ?> av <?php echo (int)$recent_pages; ?></span>
          <?php if ($recent_next): ?>
            <a class="button" href="<?php echo esc_url($recent_next); ?>">Nästa &raquo;</a>
          <?php else: ?>
            <span class="tablenav-pages-navspan">Nästa &raquo;</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <?php
}

/**
 * Ord (regler)
 */
function kkchat_admin_words_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce = 'kkchat_words';

  // Skapa
  if (isset($_POST['kk_add_rule'])) {
    check_admin_referer($nonce);
    $kind   = in_array($_POST['kind'] ?? '', ['forbid','watch'], true) ? $_POST['kind'] : 'forbid';
    $word   = sanitize_text_field($_POST['word'] ?? '');
    $match  = in_array($_POST['match_type'] ?? '', ['contains','exact','regex'], true) ? $_POST['match_type'] : 'contains';
    $action = in_array($_POST['action'] ?? '', ['kick','ipban'], true) ? $_POST['action'] : null;
    $dur_n  = (int)($_POST['dur_n'] ?? 0);
    $dur_u  = sanitize_text_field($_POST['dur_u'] ?? 'minutes');
    $inf    = !empty($_POST['dur_inf']);
    $notes  = sanitize_text_field($_POST['notes'] ?? '');

    if (!$word) {
      echo '<div class="error"><p>Ord krävs.</p></div>';
    } elseif ($kind==='forbid' && !$action) {
      echo '<div class="error"><p>Åtgärd krävs för &quot;förbjud&quot;-regler.</p></div>';
    } else {
      $sec = $inf ? null : kkchat_seconds_from_unit($dur_n, $dur_u);
      $wpdb->insert($t['rules'], [
        'word'         => $word,
        'kind'         => $kind,
        'match_type'   => $match,
        'action'       => $action,
        'duration_sec' => $sec,
        'enabled'      => 1,
        'notes'        => $notes,
        'created_by'   => wp_get_current_user()->user_login ?? null,
        'created_at'   => time()
      ], ['%s','%s','%s','%s','%d','%d','%s','%s','%d']);
      echo '<div class="updated"><p>Regel sparad.</p></div>';
    }
  }

  // Växla aktivering
  if (isset($_GET['kk_toggle']) && check_admin_referer($nonce)) {
    $id  = (int)$_GET['kk_toggle'];
    $cur = (int)$wpdb->get_var($wpdb->prepare("SELECT enabled FROM {$t['rules']} WHERE id=%d",$id));
    if ($cur!==null){
      $wpdb->update($t['rules'], ['enabled'=>$cur?0:1], ['id'=>$id], ['%d'], ['%d']);
      echo '<div class="updated"><p>Status uppdaterad.</p></div>';
    }
  }
  // Ta bort
  if (isset($_GET['kk_delete']) && check_admin_referer($nonce)) {
    $id=(int)$_GET['kk_delete'];
    $wpdb->delete($t['rules'], ['id'=>$id], ['%d']);
    echo '<div class="updated"><p>Regel borttagen.</p></div>';
  }

  $rules = $wpdb->get_results("SELECT * FROM {$t['rules']} ORDER BY id DESC");
  $words_base = menu_page_url('kkchat_words', false);
  ?>
  <div class="wrap">
    <h1>KKchat – Ord</h1>
    <h2>Lägg till regel</h2>
    <form method="post"><?php wp_nonce_field($nonce); ?>
      <table class="form-table">
        <tr><th>Typ</th><td>
          <label><input type="radio" name="kind" value="forbid" checked> Förbjud</label>
          &nbsp; <label><input type="radio" name="kind" value="watch"> Bevakningslista</label>
        </td></tr>
        <tr><th><label for="kkw_word">Ord / mönster</label></th>
            <td><input id="kkw_word" name="word" class="regular-text" placeholder="t.ex. förbjudet ord eller ^regex$" required></td></tr>
        <tr><th>Matchning</th><td>
          <select name="match_type">
            <option value="contains">innehåller (skiftlägesokänslig)</option>
            <option value="exact">exakt</option>
            <option value="regex">regex (PHP PCRE)</option>
          </select>
        </td></tr>
        <tr><th>Åtgärd</th><td>
          <select name="action">
            <option value="">— (endast bevakning)</option>
            <option value="kick">Kicka</option>
            <option value="ipban">IP-blockera</option>
          </select>
          <p class="description">Ignoreras för bevakningsregler.</p>
        </td></tr>
        <tr><th>Varaktighet</th><td>
          <label><input type="checkbox" name="dur_inf" value="1"> Oändlig</label>
          <div style="margin-top:6px">
            <input type="number" name="dur_n" class="small-text" value="60" min="1">
            <select name="dur_u"><option>minutes</option><option>hours</option><option>days</option></select>
          </div>
        </td></tr>
        <tr><th>Anteckningar</th><td><input name="notes" class="regular-text" placeholder="Valfritt"></td></tr>
      </table>
      <p><button class="button button-primary" name="kk_add_rule" value="1">Spara regel</button></p>
    </form>

    <h2>Regler</h2>
    <table class="widefat striped">
      <thead><tr><th>ID</th><th>Typ</th><th>Ord</th><th>Matchning</th><th>Åtgärd</th><th>Varaktighet</th><th>Aktiv</th><th>Anteckningar</th><th>Åtgärder</th></tr></thead>
      <tbody>
      <?php if ($rules): foreach ($rules as $r):
        $dur = $r->duration_sec===null ? '∞' : ($r->duration_sec.' s'); ?>
        <tr>
          <td><?php echo (int)$r->id; ?></td>
          <td><?php echo esc_html($r->kind === 'forbid' ? 'förbjud' : 'bevakning'); ?></td>
          <td><code><?php echo esc_html($r->word); ?></code></td>
          <td><?php echo esc_html($r->match_type); ?></td>
          <td><?php echo esc_html($r->action ?: '—'); ?></td>
          <td><?php echo esc_html($dur); ?></td>
          <td><?php echo $r->enabled ? 'Ja':'Nej'; ?></td>
          <td><?php echo esc_html($r->notes); ?></td>
          <td>
            <?php
              $toggle_url = wp_nonce_url(add_query_arg('kk_toggle', $r->id, $words_base), $nonce);
              $delete_url = wp_nonce_url(add_query_arg('kk_delete', $r->id, $words_base), $nonce);
            ?>
            <a class="button" href="<?php echo esc_url($toggle_url); ?>"><?php echo $r->enabled ? 'Inaktivera' : 'Aktivera'; ?></a>
            <a class="button button-danger" href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Ta bort regeln?')">Ta bort</a>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="9">Inga regler ännu.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
}

/**
 * Rapporter
 */
function kkchat_admin_reports_page() {
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce = 'kkchat_reports';

  // Åtgärder: lös / återöppna / ta bort
  if (isset($_GET['act'], $_GET['id']) && check_admin_referer($nonce)) {
    $id = (int)$_GET['id'];
    if ($_GET['act'] === 'resolve') {
      $wpdb->update($t['reports'], ['status'=>'resolved'], ['id'=>$id], ['%s'], ['%d']);
      echo '<div class="updated"><p>Rapport markerad som löst.</p></div>';
    } elseif ($_GET['act'] === 'reopen') {
      $wpdb->update($t['reports'], ['status'=>'open'], ['id'=>$id], ['%s'], ['%d']);
      echo '<div class="updated"><p>Rapport återöppnad.</p></div>';
    } elseif ($_GET['act'] === 'delete') {
      $wpdb->delete($t['reports'], ['id'=>$id], ['%d']);
      echo '<div class="updated"><p>Rapport raderad.</p></div>';
    }
  }

  // Filter
  $status = in_array(($s = sanitize_text_field($_GET['status'] ?? 'open')), ['open','resolved','any'], true) ? $s : 'open';
  $q      = sanitize_text_field($_GET['q'] ?? '');
  $per    = max(10, min(200, (int)($_GET['per'] ?? 50)));
  $page   = max(1, (int)($_GET['paged'] ?? 1));
  $offset = ($page - 1) * $per;

  $where = []; $params = [];
  if ($status !== 'any') { $where[] = "status = %s"; $params[] = $status; }
  if ($q !== '') {
    $like = '%'.$wpdb->esc_like($q).'%';
    $where[] = "(reporter_name LIKE %s OR reported_name LIKE %s OR reason LIKE %s)";
    array_push($params, $like, $like, $like);
  }
  $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reports']} $whereSql", ...$params));

  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$t['reports']} $whereSql ORDER BY id DESC LIMIT %d OFFSET %d",
    ...array_merge($params, [$per, $offset])
  ));

  $pages = max(1, (int)ceil($total / $per));
  $reports_base = menu_page_url('kkchat_reports', false);
  ?>
  <div class="wrap">
    <h1>KKchat – Rapporter</h1>

    <form method="get" action="<?php echo esc_url($reports_base); ?>" style="background:#fff;border:1px solid #eee;padding:10px;border-radius:8px;margin:12px 0">
      <table class="form-table">
        <tr>
          <th>Status</th>
          <td>
            <select name="status">
              <option value="open"     <?php selected($status,'open'); ?>>Öppen</option>
              <option value="resolved" <?php selected($status,'resolved'); ?>>Löst</option>
              <option value="any"      <?php selected($status,'any'); ?>>Alla</option>
            </select>
          </td>
          <th><label for="r_q">Sök</label></th>
          <td><input id="r_q" name="q" class="regular-text" value="<?php echo esc_attr($q); ?>" placeholder="Anmälare / Anmäld / Orsak"></td>
          <th>Per sida</th>
          <td><input name="per" type="number" min="10" max="200" value="<?php echo (int)$per; ?>" class="small-text"></td>
          <td><button class="button button-primary">Filtrera</button>
            <a class="button" href="<?php echo esc_url($reports_base); ?>">Återställ</a>
          </td>
        </tr>
      </table>
    </form>

    <table class="widefat striped">
      <thead>
        <tr>
          <th>ID</th>
          <th>Skapad</th>
          <th>Anmälare</th>
          <th>Anmälarens IP</th>
          <th>Anmäld</th>
          <th>Anmäld IP</th>
          <th>Orsak</th>
          <th>Status</th>
          <th>Åtgärder</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows): foreach ($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r->id; ?></td>
          <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$r->created_at)); ?></td>
          <td><?php echo esc_html($r->reporter_name); ?> (<?php echo (int)$r->reporter_id; ?>)</td>
          <td><code><?php echo esc_html($r->reporter_ip); ?></code></td>
          <td><?php echo esc_html($r->reported_name); ?> (<?php echo (int)$r->reported_id; ?>)</td>
          <td><code><?php echo esc_html($r->reported_ip); ?></code></td>
          <td style="white-space:pre-wrap"><?php echo esc_html($r->reason); ?></td>
          <td><?php echo $r->status==='open' ? 'Öppen' : 'Löst'; ?></td>
          <td>
            <?php
              $res = wp_nonce_url(add_query_arg(['act' => ($r->status==='open' ? 'resolve' : 'reopen'), 'id' => $r->id], $reports_base), $nonce);
              $del = wp_nonce_url(add_query_arg(['act' => 'delete', 'id' => $r->id], $reports_base), $nonce);
            ?>
            <a class="button" href="<?php echo esc_url($res); ?>"><?php echo $r->status==='open' ? 'Markera som löst' : 'Återöppna'; ?></a>
            <a class="button button-danger" href="<?php echo esc_url($del); ?>" onclick="return confirm('Radera denna rapport?');">Ta bort</a>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="9">Inga rapporter.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>

    <?php if ($pages > 1):
      $common = ['status'=>$status, 'q'=>$q, 'per'=>$per];
      $prev = $page > 1 ? add_query_arg(array_merge($common, ['paged'=>$page-1]), $reports_base) : '';
      $next = $page < $pages ? add_query_arg(array_merge($common, ['paged'=>$page+1]), $reports_base) : '';
    ?>
      <p>
        <?php if ($prev): ?><a class="button" href="<?php echo esc_url($prev); ?>">&laquo; Föregående</a><?php endif; ?>
        <span style="margin:0 8px">Sida <?php echo (int)$page; ?> / <?php echo (int)$pages; ?></span>
        <?php if ($next): ?><a class="button" href="<?php echo esc_url($next); ?>">Nästa &raquo;</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
  <?php
}

/**
 * Loggar: sök/purge + inline IP-block + bildminiatyrer/lightbox + Samtal-modal
 * – Svensk UI, egen "Samtal"-kolumn efter ID, inga ”Öppna i ny flik”-länkar.
 */
function kkchat_admin_logs_page() {
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();

  /* ---------- Visar ev. redirect-notiser från IP-block ---------- */
  if (!empty($_GET['kkbanok']))  echo '<div class="updated"><p>'.esc_html($_GET['kkbanok']).'</p></div>';
  if (!empty($_GET['kkbanerr'])) echo '<div class="error"><p>'.esc_html($_GET['kkbanerr']).'</p></div>';

  /* ---------- Åtgärder: Dölj / Visa (soft delete) ---------- */
  if (isset($_POST['kk_hide']) && check_admin_referer('kkchat_logs_actions')) {
    $mid   = max(0, (int)($_POST['mid'] ?? 0));
    $cause = sanitize_text_field($_POST['cause'] ?? '');
    if ($mid > 0) {
      $wpdb->query($wpdb->prepare(
        "UPDATE {$t['messages']}
           SET hidden_at = %d,
               hidden_by = %d,
               hidden_cause = %s
         WHERE id = %d",
        time(), get_current_user_id() ?: 0, $cause, $mid
      ));
      echo '<div class="updated"><p>Meddelande #'.(int)$mid.' dolt.</p></div>';
    }
  }
  if (isset($_POST['kk_unhide']) && check_admin_referer('kkchat_logs_actions')) {
    $mid = max(0, (int)($_POST['mid'] ?? 0));
    if ($mid > 0) {
      $wpdb->query($wpdb->prepare(
        "UPDATE {$t['messages']}
            SET hidden_at = NULL,
                hidden_by = NULL,
                hidden_cause = NULL
          WHERE id = %d",
        $mid
      ));
      echo '<div class="updated"><p>Meddelande #'.(int)$mid.' återställt.</p></div>';
    }
  }

  /* ---------- Rensa (purge) ---------- */
  if (isset($_POST['kk_purge_90'])) {
    check_admin_referer('kkchat_logs_purge');
    $days = 90; $threshold = time() - ($days * 86400);
    $cnt_msgs  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['messages']} WHERE created_at < %d", $threshold));
    $cnt_reads = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t['reads']} r
       INNER JOIN {$t['messages']} m ON m.id = r.message_id
       WHERE m.created_at < %d", $threshold
    ));
    $img_urls = $wpdb->get_col($wpdb->prepare(
      "SELECT DISTINCT content FROM {$t['messages']}
       WHERE kind='image' AND created_at < %d", $threshold
    )) ?: [];
    $wpdb->query($wpdb->prepare(
      "DELETE r FROM {$t['reads']} r
       INNER JOIN {$t['messages']} m ON m.id = r.message_id
       WHERE m.created_at < %d", $threshold
    ));
    $wpdb->query($wpdb->prepare("DELETE FROM {$t['messages']} WHERE created_at < %d", $threshold));

    $cnt_imgs = 0;
    if ($img_urls) {
      $up = wp_upload_dir();
      $baseurl = rtrim((string)$up['baseurl'], '/');
      $basedir = rtrim((string)$up['basedir'], DIRECTORY_SEPARATOR);
      $chatUrlPrefix = $baseurl . '/kkchat/';
      $img_urls = array_values(array_unique(array_filter(array_map('strval', $img_urls))));
      foreach ($img_urls as $url) {
        if (strpos($url, $chatUrlPrefix) !== 0) continue;
        $still = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['messages']} WHERE kind='image' AND content=%s", $url));
        if ($still > 0) continue;
        $rel   = ltrim(substr($url, strlen($baseurl)), '/');
        $fpath = $basedir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $norm  = wp_normalize_path($fpath);
        $allow = wp_normalize_path($basedir . '/kkchat/');
        if (strpos($norm, $allow) !== 0) continue;
        if (file_exists($fpath) && is_file($fpath) && @unlink($fpath)) {
          $cnt_imgs++;
          @rmdir(dirname($fpath)); @rmdir(dirname(dirname($fpath)));
        }
      }
    }

    echo '<div class="updated"><p>Tog bort '
      . esc_html(number_format_i18n($cnt_msgs))
      . ' meddelandeloggar, '
      . esc_html(number_format_i18n($cnt_reads))
      . ' läskvitton och '
      . esc_html(number_format_i18n($cnt_imgs))
      . ' chatbilder äldre än '
      . esc_html($days)
      . ' dagar.</p></div>';
  }

  /* ---------- Filter ---------- */
  $q          = sanitize_text_field($_GET['q'] ?? '');
  $u          = sanitize_text_field($_GET['u'] ?? '');
  $sender     = sanitize_text_field($_GET['sender'] ?? '');
  $recipient  = sanitize_text_field($_GET['recipient'] ?? '');
  $room       = kkchat_sanitize_room_slug($_GET['room'] ?? '');
  $kind       = in_array(($_GET['kind'] ?? ''), ['chat','banner','any'], true) ? $_GET['kind'] : 'any';
  $from       = sanitize_text_field($_GET['from'] ?? '');
  $to         = sanitize_text_field($_GET['to'] ?? '');
  $per        = max(10, min(500, (int)($_GET['per'] ?? 100)));
  $page       = max(1, (int)($_GET['paged'] ?? 1));
  $offset     = ($page - 1) * $per;

  // Smart tolkning av "Text" (IP eller #ID)
  $parsed_ip = '';
  if ($q !== '' && filter_var($q, FILTER_VALIDATE_IP)) $parsed_ip = $q;
  $id_exact = 0;
  if ($q !== '' && preg_match('/^#?(\d{1,20})$/', $q, $m_id)) $id_exact = (int)$m_id[1];

  $where = []; $params = [];
  if ($kind !== 'any') { $where[] = "kind = %s"; $params[] = $kind; }
    // Only do text LIKE when q is a plain text search (not IP or #ID)
    if ($q !== '' && $parsed_ip === '' && $id_exact === 0) {
      $where[] = "content LIKE %s";
      $params[] = '%'.$wpdb->esc_like($q).'%';
    }
  if ($parsed_ip !== '') { $where[] = "(sender_ip = %s OR recipient_ip = %s)"; array_push($params, $parsed_ip, $parsed_ip); }
  if ($id_exact > 0)   { $where[] = "id = %d"; $params[] = $id_exact; }
  if ($u !== '')       { $like = '%'.$wpdb->esc_like($u).'%'; $where[] = "(sender_name LIKE %s OR recipient_name LIKE %s)"; array_push($params, $like, $like); }
  if ($sender !== '')  { $where[] = "sender_name LIKE %s";     $params[] = '%'.$wpdb->esc_like($sender).'%'; }
  if ($recipient !== '') { $where[] = "recipient_name LIKE %s"; $params[] = '%'.$wpdb->esc_like($recipient).'%'; }
  if ($room !== '')    { $where[] = "(recipient_id IS NULL AND room = %s)"; $params[] = $room; }
  if ($from !== '')    { $ts = strtotime($from.' 00:00:00'); if ($ts) { $where[] = "created_at >= %d"; $params[] = $ts; } }
  if ($to   !== '')    { $ts = strtotime($to.' 23:59:59'); if ($ts) { $where[] = "created_at <= %d"; $params[] = $ts; } }
  $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['messages']} $whereSql", ...$params));

  $sql = "SELECT id,created_at,kind,room,
                 sender_id,sender_name,sender_ip,
                 recipient_id,recipient_name,recipient_ip,
                 content, hidden_at, hidden_by, hidden_cause
          FROM {$t['messages']} $whereSql
          ORDER BY id DESC
          LIMIT %d OFFSET %d";
  $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per, $offset])));

  // Sidopanel: användarnamn
  $ulike = sanitize_text_field($_GET['ulike'] ?? '');
  if ($ulike !== '') {
    $like = '%'.$wpdb->esc_like($ulike).'%';
    $senders = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT sender_name FROM {$t['messages']} WHERE sender_name LIKE %s ORDER BY sender_name ASC LIMIT 300", $like));
    $recips  = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT recipient_name FROM {$t['messages']} WHERE recipient_name IS NOT NULL AND recipient_name LIKE %s ORDER BY recipient_name ASC LIMIT 300", $like));
  } else {
    $senders = $wpdb->get_col("SELECT DISTINCT sender_name FROM {$t['messages']} WHERE sender_name <> '' ORDER BY sender_name ASC LIMIT 300");
    $recips  = $wpdb->get_col("SELECT DISTINCT recipient_name FROM {$t['messages']} WHERE recipient_name IS NOT NULL AND recipient_name <> '' ORDER BY recipient_name ASC LIMIT 300");
  }
  $usernames = array_values(array_unique(array_merge($senders ?: [], $recips ?: [])));
  natcasesort($usernames);
  $usernames = array_slice(array_values($usernames), 0, 300);

  $pages = max(1, (int)ceil($total / $per));
  $logs_base = menu_page_url('kkchat_admin_logs', false);

  // Hjälpare: bild-URL?
  $is_image_url = function($url) {
    if (!$url) return false;
    if (!preg_match('~^https?://~i', $url)) return false;
    if (preg_match('~\.(jpe?g|png|gif|webp)$~i', $url)) return true;
    if (strpos($url, '/kkchat/') !== false) return true;
    return false;
  };

  /* ---------- Samtalsmodal via GET (?conv_a=&conv_b=) ---------- */
  $convA = max(0, (int)($_GET['conv_a'] ?? 0));
  $convB = max(0, (int)($_GET['conv_b'] ?? 0));
  $convRows = [];
  if ($convA > 0 && $convB > 0) {
    $convRows = $wpdb->get_results($wpdb->prepare(
      "SELECT id,created_at,kind,room,sender_id,sender_name,recipient_id,recipient_name,content
         FROM {$t['messages']}
        WHERE (sender_id=%d AND recipient_id=%d)
           OR (sender_id=%d AND recipient_id=%d)
        ORDER BY id ASC
        LIMIT %d",
      $convA, $convB, $convB, $convA, 1000
    ));
  }
  ?>
  <div class="wrap">
    <h1>KKchat Loggar</h1>
    <p class="description">Sök i alla chatloggar. ”Dolda” meddelanden finns kvar i databasen men syns inte för användare i frontend.</p>

    <!-- Rensa (purge) -->
    <div style="margin:14px 0;padding:12px;border:1px solid #f5c6cb;background:#fff5f5;border-radius:8px">
      <form method="post" onsubmit="return confirm('Radera PERMANENT alla loggar (och läskvitton) äldre än 90 dagar? Detta kan inte ångras.');">
        <?php wp_nonce_field('kkchat_logs_purge'); ?>
        <button class="button button-secondary" name="kk_purge_90" value="1">Ta bort loggar äldre än 90 dagar</button>
        <span class="description" style="margin-left:8px">Tar bort bilder, chatt- och banner-meddelanden samt läskvitton äldre än 90 dagar.</span>
      </form>
    </div>

    <div style="display:grid;grid-template-columns:280px 1fr;gap:16px;align-items:start">
      <aside style="background:#fff;border:1px solid #eee;padding:10px;border-radius:8px">
        <h2>Användarnamn</h2>
        <form method="get" action="<?php echo esc_url( admin_url('admin.php') ); ?>" style="margin-bottom:8px">
          <input type="hidden" name="page" value="kkchat_admin_logs">
          <input type="text" name="ulike" value="<?php echo esc_attr($ulike); ?>" class="regular-text" placeholder="Sök användare…">
          <p><button class="button">Filtrera</button>
            <a class="button" href="<?php echo esc_url($logs_base); ?>">Rensa</a>
          </p>
        </form>
        <div style="max-height:420px;overflow:auto;border-top:1px solid #eee;padding-top:6px">
          <?php if ($usernames): ?>
            <ul>
              <?php foreach ($usernames as $name): ?>
                <li><a href="<?php echo esc_url(add_query_arg('u', rawurlencode($name), $logs_base)); ?>"><?php echo esc_html($name); ?></a></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p>Inga användare hittades.</p>
          <?php endif; ?>
        </div>
      </aside>

      <main>
        <!-- Filter -->
        <form method="get" action="<?php echo esc_url( admin_url('admin.php') ); ?>" style="background:#fff;border:1px solid #eee;padding:10px;border-radius:8px">
          <input type="hidden" name="page" value="kkchat_admin_logs">
          <table class="form-table">
            <tr>
              <th scope="row"><label for="log_q">Text</label></th>
              <td><input id="log_q" name="q" class="regular-text" value="<?php echo esc_attr($q); ?>" placeholder="Sök text, IP (IPv4/IPv6) eller #ID"></td>
              <th><label for="log_kind">Typ</label></th>
              <td>
                <select id="log_kind" name="kind">
                  <option value="any" <?php selected($kind,'any'); ?>>Alla</option>
                  <option value="chat" <?php selected($kind,'chat'); ?>>Chatt</option>
                  <option value="banner" <?php selected($kind,'banner'); ?>>Banner</option>
                </select>
              </td>
            </tr>
            <tr>
              <th><label for="log_u">Deltagare</label></th>
              <td><input id="log_u" name="u" class="regular-text" value="<?php echo esc_attr($u); ?>" placeholder="I avsändare ELLER mottagare"></td>
              <th><label for="log_room">Rum</label></th>
              <td><input id="log_room" name="room" class="regular-text" value="<?php echo esc_attr($room); ?>" placeholder="Slug (publikt)"></td>
            </tr>
            <tr>
              <th><label for="log_sender">Avsändare</label></th>
              <td><input id="log_sender" name="sender" class="regular-text" value="<?php echo esc_attr($sender); ?>"></td>
              <th><label for="log_recipient">Mottagare</label></th>
              <td><input id="log_recipient" name="recipient" class="regular-text" value="<?php echo esc_attr($recipient); ?>"></td>
            </tr>
            <tr>
              <th><label for="log_from">Från</label></th>
              <td><input id="log_from" name="from" type="date" value="<?php echo esc_attr($from); ?>"></td>
              <th><label for="log_to">Till</label></th>
              <td><input id="log_to" name="to" type="date" value="<?php echo esc_attr($to); ?>"></td>
            </tr>
            <tr>
              <th><label for="log_per">Per sida</label></th>
              <td><input id="log_per" name="per" type="number" class="small-text" min="10" max="500" value="<?php echo (int)$per; ?>"></td>
              <td colspan="2"><button class="button button-primary">Sök</button>
                <a class="button" href="<?php echo esc_url($logs_base); ?>">Återställ</a>
              </td>
            </tr>
          </table>
        </form>

        <p><?php echo esc_html(number_format_i18n($total)); ?> resultat. Sida <?php echo (int)$page; ?> / <?php echo (int)$pages; ?></p>

        <!-- Dold IP-block-form (postar till admin-post.php) -->
        <form id="kkbanip_form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:none">
          <input type="hidden" name="action" value="kkchat_logs_ipban">
          <?php wp_nonce_field('kkchat_logs_ipban'); ?>
          <input type="hidden" name="ip" value="">
          <input type="hidden" name="minutes" value="">
          <input type="hidden" name="cause" value="">
          <input type="hidden" name="user_id" value="">
          <input type="hidden" name="user_name" value="">
          <input type="hidden" name="user_wp_username" value="">
        </form>

        <!-- Stilar: miniatyr + lightbox + samtal -->
        <style>
          .kkchat-msg { white-space: pre-wrap; }
          .kkchat-thumb { width:96px; height:96px; object-fit:cover; border:1px solid #e3e3e3; border-radius:6px; cursor:zoom-in; background:#fafafa; }
          .kkchat-lightbox {
            position: fixed; inset: 0; display:none; align-items:center; justify-content:center;
            background: rgba(0,0,0,.85); z-index: 99999;
          }
          .kkchat-lightbox.open { display:flex; }
          .kkchat-lightbox img { max-width: 90vw; max-height: 90vh; box-shadow: 0 10px 40px rgba(0,0,0,.5); border-radius: 8px; cursor: zoom-out; }
          .kkchat-lightbox .kkchat-close { position:absolute; top:16px; right:16px; color:#fff; font-size:20px; cursor:pointer; }

          .kkconv-overlay {
            position: fixed; inset: 0; display: <?php echo ($convA && $convB) ? 'flex' : 'none'; ?>;
            align-items: center; justify-content: center; background: rgba(0,0,0,.55); z-index: 99998;
          }
          .kkconv-panel {
            background: #fff; width: 960px; max-width: 95vw; height: 80vh; max-height: 90vh;
            border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.3); display:flex; flex-direction:column;
          }
          .kkconv-header { padding: 12px 16px; border-bottom: 1px solid #eee; display:flex; align-items:center; justify-content:space-between; }
          .kkconv-title { font-weight:600; }
          .kkconv-body { padding: 12px 16px; overflow:auto; background:#fafafa; }
          .kkconv-msg { margin: 10px 0; max-width: 78%; padding: 8px 10px; border-radius: 10px; background:#fff; border:1px solid #eee; }
          .kkconv-row { display:flex; gap:10px; align-items:flex-end; }
          .kkconv-row.me   { justify-content:flex-end; }
          .kkconv-row.them { justify-content:flex-start; }
          .kkconv-meta { color:#666; font-size:12px; margin-bottom:4px; }
          .kkconv-msg .kkchat-thumb { width:120px; height:120px; }
          .kkconv-close { cursor:pointer; }
        </style>

        <!-- Lightbox -->
        <div id="kkchatLightbox" class="kkchat-lightbox" role="dialog" aria-modal="true" aria-label="Bildförhandsvisning">
          <span class="kkchat-close dashicons dashicons-no" title="Stäng"></span>
          <img src="" alt="">
        </div>

        <!-- Samtalsmodal -->
        <div id="kkconv" class="kkconv-overlay" role="dialog" aria-modal="true" aria-label="Samtal">
          <div class="kkconv-panel">
            <div class="kkconv-header">
              <?php
                $aName = ''; $bName = '';
                if ($convRows) {
                  foreach ($convRows as $r) {
                    if ($r->sender_id == $convA && !$aName) $aName = (string)$r->sender_name;
                    if ($r->sender_id == $convB && !$bName) $bName = (string)$r->sender_name;
                    if ($aName && $bName) break;
                  }
                }
                $aName = $aName ?: ('#'.$convA);
                $bName = $bName ?: ('#'.$convB);
              ?>
              <div class="kkconv-title">Samtal: <?php echo esc_html($aName); ?> (#<?php echo (int)$convA; ?>)
                &nbsp;↔&nbsp; <?php echo esc_html($bName); ?> (#<?php echo (int)$convB; ?>)
              </div>
              <div>
                <button type="button" class="button kkconv-close" onclick="kkCloseConv()">Stäng</button>
              </div>
            </div>
            <div class="kkconv-body" id="kkconvBody">
              <?php if ($convA && $convB && !$convRows): ?>
                <p>Inget samtal hittades mellan dessa användare.</p>
              <?php elseif ($convRows): ?>
                <?php foreach ($convRows as $r): ?>
                  <?php $isMe = ($r->sender_id == $convA); ?>
                  <div class="kkconv-row <?php echo $isMe ? 'me' : 'them'; ?>">
                    <div class="kkconv-msg">
                      <div class="kkconv-meta">
                        <strong><?php echo esc_html($r->sender_name ?: ('#'.$r->sender_id)); ?></strong>
                        • <?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$r->created_at)); ?>
                        <?php if ($r->recipient_id): ?> • DM<?php else: ?> • Rum: <code><?php echo esc_html($r->room); ?></code><?php endif; ?>
                      </div>
                      <div class="kkchat-msg">
                        <?php if ($r->kind === 'image' && $is_image_url($r->content)): ?>
                          <?php $full = esc_url($r->content); ?>
                          <img src="<?php echo $full; ?>" alt="Bild #<?php echo (int)$r->id; ?>" class="kkchat-thumb" data-full="<?php echo $full; ?>" loading="lazy">
                        <?php else: ?>
                          <?php echo $r->content; /* redan escapat vid skrivning */ ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
                <script>(function(){var b=document.getElementById('kkconvBody'); if(b){b.scrollTop=b.scrollHeight;} })();</script>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <table class="widefat striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>Samtal</th>
              <th>Tid</th>
              <th>Typ</th>
              <th>Rum/DM</th>
              <th>Avsändare (IP)</th>
              <th>Mottagare (IP)</th>
              <th>Meddelande</th>
              <th>Dolt</th>
              <th>Åtgärder</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($rows): foreach ($rows as $m): ?>
            <tr>
              <td><?php echo (int)$m->id; ?></td>

              <!-- Ny kolumn: Samtal -->
              <td>
                <?php if ($m->sender_id && $m->recipient_id): ?>
                  <button type="button" class="button button-secondary"
                          onclick="kkOpenConversation(<?php echo (int)$m->sender_id; ?>,<?php echo (int)$m->recipient_id; ?>)">Samtal</button>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>

              <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$m->created_at)); ?></td>
              <td><?php echo esc_html($m->kind); ?></td>
              <td>
                <?php
                if ($m->recipient_id) {
                  echo '<span class="dashicons dashicons-lock"></span> DM';
                } else {
                  echo $m->room ? '<code>'.esc_html($m->room).'</code>' : '—';
                }
                ?>
              </td>
              <td>
                <?php echo esc_html($m->sender_name); ?><?php echo $m->sender_id ? ' (#'.(int)$m->sender_id.')' : ''; ?>
                <br>
                <?php if (!empty($m->sender_ip)): ?>
                  <code><?php echo esc_html($m->sender_ip); ?></code>
                  <button type="button" class="button" style="margin-left:6px"
                          data-ip="<?php echo esc_attr($m->sender_ip); ?>"
                          data-user-id="<?php echo (int)$m->sender_id; ?>"
                          data-user-name="<?php echo esc_attr($m->sender_name); ?>"
                          data-who="avsändare"
                          onclick="kkBanIPFromLogs(this)">Blockera IP</button>
                <?php else: ?>
                  <span class="description">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($m->recipient_id): ?>
                  <?php echo esc_html($m->recipient_name ?: ('#'.$m->recipient_id)); ?> (#<?php echo (int)$m->recipient_id; ?>)
                  <br>
                  <?php if (!empty($m->recipient_ip)): ?>
                    <code><?php echo esc_html($m->recipient_ip); ?></code>
                    <button type="button" class="button" style="margin-left:6px"
                            data-ip="<?php echo esc_attr($m->recipient_ip); ?>"
                            data-user-id="<?php echo (int)$m->recipient_id; ?>"
                            data-user-name="<?php echo esc_attr($m->recipient_name); ?>"
                            data-who="mottagare"
                            onclick="kkBanIPFromLogs(this)">Blockera IP</button>
                  <?php else: ?>
                    <span class="description">—</span>
                  <?php endif; ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="kkchat-msg">
                <?php
                  if ($m->kind === 'image' && $is_image_url($m->content)) {
                    $full = esc_url($m->content);
                    echo '<img src="'.$full.'" alt="Bild #'.(int)$m->id.'" class="kkchat-thumb" data-full="'.$full.'" loading="lazy">';
                  } else {
                    echo $m->content; // redan escapat vid skrivning
                  }
                ?>
              </td>
              <td>
                <?php if (!empty($m->hidden_at)): ?>
                  <span class="badge" style="background:#fbeaea;border:1px solid #e3a1a1;padding:2px 6px;border-radius:4px">Dolt</span><br>
                  <small><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$m->hidden_at)); ?></small>
                  <?php if (!empty($m->hidden_cause)): ?>
                    <br><small class="description"><?php echo esc_html($m->hidden_cause); ?></small>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge" style="background:#e7f7e7;border:1px solid #9acd9a;padding:2px 6px;border-radius:4px">Synligt</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" style="display:inline">
                  <?php wp_nonce_field('kkchat_logs_actions'); ?>
                  <input type="hidden" name="mid" value="<?php echo (int)$m->id; ?>">
                  <?php if (!empty($m->hidden_at)): ?>
                    <button class="button" name="kk_unhide" value="1">Visa</button>
                  <?php else: ?>
                    <input type="text" name="cause" class="regular-text" placeholder="Orsak (valfritt)" style="width:180px;margin-right:6px">
                    <button class="button" name="kk_hide" value="1">Dölj</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="10">Inga meddelanden hittades.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>

        <?php if ($pages > 1):
          $common = [
            'q'=>$q, 'u'=>$u, 'sender'=>$sender, 'recipient'=>$recipient,
            'room'=>$room, 'kind'=>$kind, 'from'=>$from, 'to'=>$to, 'per'=>$per
          ];
          $prev = $page > 1 ? add_query_arg(array_merge($common, ['paged'=>$page-1]), $logs_base) : '';
          $next = $page < $pages ? add_query_arg(array_merge($common, ['paged'=>$page+1]), $logs_base) : '';
        ?>
          <p>
            <?php if ($prev): ?><a class="button" href="<?php echo esc_url($prev); ?>">&laquo; Föregående</a><?php endif; ?>
            <?php if ($next): ?><a class="button" href="<?php echo esc_url($next); ?>">Nästa &raquo;</a><?php endif; ?>
          </p>
        <?php endif; ?>

        <!-- JS: IP-block-prompt + lightbox + samtal -->
        <script>
          (function(){
            // IP-block prompt
            window.kkBanIPFromLogs = function(source, whoLabel) {
              var data = {
                ip: '',
                who: whoLabel || '',
                userId: '',
                userName: '',
                wpUsername: ''
              };
              if (source && typeof source === 'object' && source.dataset) {
                data.ip = source.dataset.ip || '';
                if (!data.who && source.dataset.who) data.who = source.dataset.who;
                data.userId = source.dataset.userId || '';
                data.userName = source.dataset.userName || '';
                data.wpUsername = source.dataset.wpUsername || '';
              } else if (typeof source === 'string') {
                data.ip = source;
              }
              if (!data.ip) return;
              var title = 'Blockera ' + data.ip + ' (' + (data.who || 'användare') + ')';
              var minutesStr = window.prompt(title + '\n\nTid i minuter (0 = för alltid):', '0');
              if (minutesStr === null) return;
              var minutes = parseInt(minutesStr, 10);
              if (isNaN(minutes) || minutes < 0) { alert('Ange ett icke-negativt antal minuter.'); return; }
              var reason = window.prompt('Anledning (valfritt):', '');
              if (reason === null) return;
              var f = document.getElementById('kkbanip_form');
              if (!f) return;
              var setVal = function(name, value) {
                var input = f.querySelector('input[name="' + name + '"]');
                if (input) input.value = value || '';
              };
              setVal('ip', data.ip);
              setVal('minutes', String(minutes));
              setVal('cause', reason || '');
              setVal('user_id', data.userId);
              setVal('user_name', data.userName);
              setVal('user_wp_username', data.wpUsername);
              f.submit();
            };

            // Lightbox för bilder
            var lb = document.getElementById('kkchatLightbox');
            var lbImg = lb ? lb.querySelector('img') : null;
            document.addEventListener('click', function(e){
              var t = e.target;
              if (t && t.classList.contains('kkchat-thumb')) {
                e.preventDefault();
                var src = t.getAttribute('data-full') || t.src;
                var alt = t.getAttribute('alt') || '';
                if (lb && lbImg) {
                  lbImg.src = src; lbImg.alt = alt; lb.classList.add('open');
                }
              } else if (lb && lb.classList.contains('open') && (t === lb || (t && t.classList.contains('kkchat-close')) || t === lbImg)) {
                lb.classList.remove('open'); if (lbImg) lbImg.src = '';
              }
            });
            document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && lb && lb.classList.contains('open')) { lb.classList.remove('open'); if (lbImg) lbImg.src=''; } });

            // Samtal öppna/stäng
            window.kkOpenConversation = function(a, b) {
              if (!a || !b) return;
              var url = new URL(window.location.href);
              url.searchParams.set('conv_a', String(a));
              url.searchParams.set('conv_b', String(b));
              window.location.href = url.toString(); // servern renderar modal med data
            };
            window.kkCloseConv = function() {
              var el = document.getElementById('kkconv');
              if (el) el.style.display = 'none';
              var url = new URL(window.location.href);
              url.searchParams.delete('conv_a');
              url.searchParams.delete('conv_b');
              window.history.replaceState({}, '', url.toString());
            };
          })();
        </script>
      </main>
    </div>
  </div>
  <?php
}
