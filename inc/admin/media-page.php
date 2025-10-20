<?php
if (!defined('ABSPATH')) exit;

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
