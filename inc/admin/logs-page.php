<?php
if (!defined('ABSPATH')) exit;

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
                 content, is_xxx, hidden_at, hidden_by, hidden_cause
          FROM {$t['messages']} $whereSql
          ORDER BY id DESC
          LIMIT %d OFFSET %d";
  $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per, $offset])));

  // Markera IP-adresser som redan är blockerade
  $blockedMap = [];
  if ($rows) {
    // Städa bort utgångna blockeringar innan vi slår upp
    $now = time();
    $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET active=0 WHERE active=1 AND expires_at IS NOT NULL AND expires_at <= %d", $now));

    $lookupKeys = [];
    $keyToIps   = [];
    foreach ($rows as $row) {
      foreach (['sender_ip', 'recipient_ip'] as $field) {
        $rawIp = trim((string)($row->$field ?? ''));
        if ($rawIp === '') continue;

        $keysForIp = [];
        $key = kkchat_ip_ban_key($rawIp);
        if ($key) {
          $keysForIp[] = $key;
        }
        if (kkchat_is_ipv6($rawIp)) {
          $packed = @inet_pton($rawIp);
          if ($packed !== false) {
            $canon = strtolower((string)@inet_ntop($packed));
            if ($canon !== '') {
              $keysForIp[] = $canon;
            }
          }
        }
        if (!$keysForIp) continue;

        $keysForIp = array_values(array_unique(array_filter($keysForIp, 'strlen')));
        foreach ($keysForIp as $keyStr) {
          $lookupKeys[$keyStr] = true;
          if (!isset($keyToIps[$keyStr])) {
            $keyToIps[$keyStr] = [];
          }
          $keyToIps[$keyStr][$rawIp] = true;
        }
      }
    }

    if ($lookupKeys) {
      $keyList      = array_keys($lookupKeys);
      $placeholders = implode(',', array_fill(0, count($keyList), '%s'));
      $sqlBlocks    = "SELECT target_ip,cause,expires_at,created_at,created_by FROM {$t['blocks']} WHERE active=1 AND type='ipban' AND target_ip IN ($placeholders)";
      $blockRows    = $wpdb->get_results($wpdb->prepare($sqlBlocks, ...$keyList), ARRAY_A);

      foreach ($blockRows as $banRow) {
        $target = (string)($banRow['target_ip'] ?? '');
        if ($target === '' || empty($keyToIps[$target])) continue;

        $expires = isset($banRow['expires_at']) ? (int)$banRow['expires_at'] : 0;
        $cause   = trim((string)($banRow['cause'] ?? ''));
        $creator = trim((string)($banRow['created_by'] ?? ''));
        $parts   = [];

        if ($expires > 0) {
          $parts[] = 'Blockerad till ' . date_i18n('Y-m-d H:i', $expires);
        } else {
          $parts[] = 'Blockerad tills vidare';
        }
        if (strpos($target, '/64') !== false) {
          $parts[] = 'Gäller hela IPv6-/64-nätet';
        }
        if ($cause !== '') {
          $parts[] = 'Orsak: ' . wp_strip_all_tags($cause);
        }
        if ($creator !== '') {
          $parts[] = 'Skapad av ' . $creator;
        }
        $tooltip = implode(' • ', $parts);

        foreach (array_keys($keyToIps[$target]) as $originalIp) {
          $blockedMap[$originalIp] = [
            'active'  => true,
            'tooltip' => $tooltip,
            'key'     => $target,
          ];
        }
      }
    }
  }

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
      "SELECT id,created_at,kind,room,sender_id,sender_name,recipient_id,recipient_name,content,is_xxx
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

        <p class="kkchat-ip-legend">
          <span class="kkchat-ip-flag">
            <span aria-hidden="true">⛔</span>
            <span class="kkchat-ip-flag-text">Blockerad IP</span>
            <span class="screen-reader-text">Blockerad IP-adress</span>
          </span>
          – adressen har en aktiv IP-blockering.
        </p>

        <!-- Stilar: miniatyr + lightbox + samtal -->
        <style>
          .kkchat-msg { white-space: pre-wrap; }
          .kkchat-thumb { width:96px; height:96px; object-fit:cover; border:1px solid #e3e3e3; border-radius:6px; cursor:zoom-in; background:#fafafa; }
          .kkchat-ip { display:inline-block; padding:2px 6px; border-radius:4px; border:1px solid transparent; font-family:SFMono-Regular,Consolas,"Liberation Mono",Menlo,monospace; }
          .kkchat-ip--blocked { color:#8b0000; border-color:#d93025; background:#fde8e7; font-weight:600; }
          .kkchat-ip-flag { display:inline-flex; align-items:center; gap:4px; font-weight:600; color:#8b0000; font-size:12px; margin-left:4px; }
          .kkchat-ip-flag-text { font-size:12px; }
          .kkchat-ip-legend { margin:6px 0 14px; font-size:12px; color:#555; display:flex; align-items:center; gap:6px; }
          .kkchat-ip-blocked-btn[disabled] { cursor:not-allowed; opacity:0.9; color:#831313; background:#f7d8d8; border-color:#e0a5a5; }
          .kkchat-ip-blocked-btn[disabled]:hover { color:#831313; background:#f7d8d8; border-color:#e0a5a5; }
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
                          <?php if (!empty($r->is_xxx)): ?><div><strong>XXX-märkt</strong></div><?php endif; ?>
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
              <th>XXX</th>
              <th>Status</th>
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
              <td><?php echo !empty($m->is_xxx) ? '<strong>XXX-märkt</strong>' : 'Ej XXX'; ?></td>
              <td><?php echo !empty($m->hidden_at) ? 'Blockad' : 'Tillåten'; ?></td>
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
                  <?php
                    $senderIp      = (string)$m->sender_ip;
                    $senderBlocked = $senderIp !== '' && isset($blockedMap[$senderIp]) ? $blockedMap[$senderIp] : null;
                    $senderTitle   = $senderBlocked && !empty($senderBlocked['tooltip']) ? $senderBlocked['tooltip'] : '';
                  ?>
                  <code class="kkchat-ip<?php echo $senderBlocked ? ' kkchat-ip--blocked' : ''; ?>"<?php echo $senderTitle !== '' ? ' title="'.esc_attr($senderTitle).'"' : ''; ?>><?php echo esc_html($senderIp); ?></code>
                  <?php if ($senderBlocked): ?>
                    <span class="kkchat-ip-flag"<?php echo $senderTitle !== '' ? ' title="'.esc_attr($senderTitle).'"' : ''; ?>>
                      <span aria-hidden="true">⛔</span>
                      <span class="kkchat-ip-flag-text">Blockerad</span>
                      <span class="screen-reader-text">IP-adressen är blockerad</span>
                    </span>
                    <button type="button" class="button kkchat-ip-blocked-btn" style="margin-left:6px" disabled aria-disabled="true"<?php echo $senderTitle !== '' ? ' title="'.esc_attr($senderTitle).'"' : ''; ?>>Blockerad</button>
                  <?php else: ?>
                    <button type="button" class="button" style="margin-left:6px"
                            data-ip="<?php echo esc_attr($senderIp); ?>"
                            data-user-id="<?php echo (int)$m->sender_id; ?>"
                            data-user-name="<?php echo esc_attr($m->sender_name); ?>"
                            data-who="avsändare"
                            onclick="kkBanIPFromLogs(this)">Blockera IP</button>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="description">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($m->recipient_id): ?>
                  <?php echo esc_html($m->recipient_name ?: ('#'.$m->recipient_id)); ?> (#<?php echo (int)$m->recipient_id; ?>)
                  <br>
                  <?php if (!empty($m->recipient_ip)): ?>
                    <?php
                      $recipientIp      = (string)$m->recipient_ip;
                      $recipientBlocked = $recipientIp !== '' && isset($blockedMap[$recipientIp]) ? $blockedMap[$recipientIp] : null;
                      $recipientTitle   = $recipientBlocked && !empty($recipientBlocked['tooltip']) ? $recipientBlocked['tooltip'] : '';
                    ?>
                    <code class="kkchat-ip<?php echo $recipientBlocked ? ' kkchat-ip--blocked' : ''; ?>"<?php echo $recipientTitle !== '' ? ' title="'.esc_attr($recipientTitle).'"' : ''; ?>><?php echo esc_html($recipientIp); ?></code>
                    <?php if ($recipientBlocked): ?>
                      <span class="kkchat-ip-flag"<?php echo $recipientTitle !== '' ? ' title="'.esc_attr($recipientTitle).'"' : ''; ?>>
                        <span aria-hidden="true">⛔</span>
                        <span class="kkchat-ip-flag-text">Blockerad</span>
                        <span class="screen-reader-text">IP-adressen är blockerad</span>
                      </span>
                      <button type="button" class="button kkchat-ip-blocked-btn" style="margin-left:6px" disabled aria-disabled="true"<?php echo $recipientTitle !== '' ? ' title="'.esc_attr($recipientTitle).'"' : ''; ?>>Blockerad</button>
                    <?php else: ?>
                      <button type="button" class="button" style="margin-left:6px"
                              data-ip="<?php echo esc_attr($recipientIp); ?>"
                              data-user-id="<?php echo (int)$m->recipient_id; ?>"
                              data-user-name="<?php echo esc_attr($m->recipient_name); ?>"
                              data-who="mottagare"
                              onclick="kkBanIPFromLogs(this)">Blockera IP</button>
                    <?php endif; ?>
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