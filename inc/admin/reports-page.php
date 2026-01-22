<?php
if (!defined('ABSPATH')) exit;

function kkchat_admin_reports_page() {
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce = 'kkchat_reports';
  $auto_threshold   = kkchat_report_autoban_threshold();
  $auto_window_days = kkchat_report_autoban_window_days();
  if (!kkchat_table_exists($t['reports'])) {
    echo '<div class="notice notice-error"><p>'.esc_html__('Rapport-tabellen saknas. Kör uppdateringen för att skapa databastabellerna.', 'kkchat').'</p></div>';
    return;
  }

  $build_block_map = static function(array $ips) use ($wpdb, $t) {
    $blockedMap = [];
    if (!$ips) {
      return $blockedMap;
    }

    $lookupKeys = [];
    $keyToIps   = [];

    foreach ($ips as $rawIp) {
      $rawIp = trim((string)$rawIp);
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

    if (!$lookupKeys) {
      return $blockedMap;
    }

    $now = time();
    $wpdb->query($wpdb->prepare("UPDATE {$t['blocks']} SET active=0 WHERE active=1 AND expires_at IS NOT NULL AND expires_at <= %d", $now));

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

    return $blockedMap;
  };

  // Åtgärder: lös / reaktivera / ta bort
  if (isset($_GET['act'], $_GET['id']) && check_admin_referer($nonce)) {
    $id = (int)$_GET['id'];
    if ($_GET['act'] === 'resolve') {
      $wpdb->update($t['reports'], ['status'=>'resolved'], ['id'=>$id], ['%s'], ['%d']);
      echo '<div class="updated"><p>Rapport markerad som löst.</p></div>';
    } elseif ($_GET['act'] === 'reopen') {
      $wpdb->update($t['reports'], ['status'=>'open'], ['id'=>$id], ['%s'], ['%d']);
      echo '<div class="updated"><p>Rapport reaktiverad.</p></div>';
    } elseif ($_GET['act'] === 'delete') {
      $wpdb->delete($t['reports'], ['id'=>$id], ['%d']);
      echo '<div class="updated"><p>Rapport raderad.</p></div>';
    }
  }

  if (isset($_POST['kkchat_resolve_blocked']) && check_admin_referer('kkchat_reports_resolve_blocked')) {
    $openIps = $wpdb->get_col("SELECT DISTINCT reported_ip FROM {$t['reports']} WHERE status='open' AND reported_ip <> ''");
    $openIps = array_values(array_filter(array_map('strval', $openIps ?: [])));
    $blockedOpenMap = $build_block_map($openIps);

    $ipsToResolve = [];
    foreach ($blockedOpenMap as $ip => $info) {
      if (!empty($info['active'])) {
        $ipsToResolve[$ip] = true;
      }
    }

    if ($ipsToResolve) {
      $ipsToResolve = array_keys($ipsToResolve);
      $placeholders = implode(',', array_fill(0, count($ipsToResolve), '%s'));
      $sqlUpdate = $wpdb->prepare("UPDATE {$t['reports']} SET status='resolved' WHERE status='open' AND reported_ip IN ($placeholders)", ...$ipsToResolve);
      $wpdb->query($sqlUpdate);
      $affected = (int)$wpdb->rows_affected;
      if ($affected > 0) {
        echo '<div class="updated"><p>'.sprintf(esc_html__('Markerade %d rapport(er) som lösta eftersom IP-adressen redan är blockerad.', 'kkchat'), $affected).'</p></div>';
      } else {
        echo '<div class="notice notice-info"><p>'.esc_html__('Inga rapporter behövde uppdateras.', 'kkchat').'</p></div>';
      }
    } else {
      echo '<div class="notice notice-info"><p>'.esc_html__('Inga rapporter med blockerad IP hittades.', 'kkchat').'</p></div>';
    }
  }

  // Filter
  $status = in_array(($s = sanitize_text_field($_GET['status'] ?? 'any')), ['open','resolved','any'], true) ? $s : 'any';
  $q      = sanitize_text_field($_GET['q'] ?? '');
  $per    = max(10, min(200, (int)($_GET['per'] ?? 50)));
  $page   = max(1, (int)($_GET['paged'] ?? 1));
  $offset = ($page - 1) * $per;

  $searchableColumns = [];
  $searchColumnsKnown = false;
  $availableColumns = $wpdb->get_col("SHOW COLUMNS FROM {$t['reports']}");
  if ($availableColumns) {
    $availableColumns = array_fill_keys($availableColumns, true);
    $searchColumnsKnown = true;
  } else {
    $availableColumns = [];
  }

  foreach (['reporter_name', 'reported_name', 'reporter_ip', 'reported_ip', 'reason', 'reason_label', 'context_label', 'message_excerpt'] as $column) {
    if (isset($availableColumns[$column])) {
      $searchableColumns[] = $column;
    }
  }
  $searchWarning = false;
  if (!$searchableColumns) {
    if ($searchColumnsKnown) {
      $searchableColumns = ['reporter_name', 'reported_name', 'reason'];
    } else {
      $searchWarning = $q !== '';
    }
  }

  $where = []; $params = [];
  if ($status !== 'any') { $where[] = "status = %s"; $params[] = $status; }
  if ($q !== '' && $searchableColumns) {
    $like = '%'.$wpdb->esc_like($q).'%';
    $searchClauses = [];
    foreach ($searchableColumns as $column) {
      $searchClauses[] = "{$column} LIKE %s";
      $params[] = $like;
    }
    $where[] = '(' . implode(' OR ', $searchClauses) . ')';
  }
  $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  if ($params) {
    $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reports']} $whereSql", ...$params));
  } else {
    $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['reports']} $whereSql");
  }

  $rowsQuery = "SELECT * FROM {$t['reports']} $whereSql ORDER BY CASE WHEN status='open' THEN 0 ELSE 1 END, id DESC LIMIT %d OFFSET %d";
  $rowsParams = array_merge($params, [$per, $offset]);
  $rows = $wpdb->get_results($wpdb->prepare($rowsQuery, ...$rowsParams));

  $pages = max(1, (int)ceil($total / $per));
  $reports_base = menu_page_url('kkchat_reports', false);

  $blockedMap = [];
  if ($rows) {
    $ips = [];
    foreach ($rows as $row) {
      if (!empty($row->reporter_ip)) {
        $ips[] = $row->reporter_ip;
      }
      if (!empty($row->reported_ip)) {
        $ips[] = $row->reported_ip;
      }
    }
    $blockedMap = $build_block_map($ips);
  }
  ?>
  <div class="wrap">
    <h1>KKchat – Rapporter</h1>

    <p class="description" style="margin:12px 0 20px;">
      <?php if ($auto_threshold > 0 && $auto_window_days > 0): ?>
        <?php printf(
          esc_html__('Automatiskt IP-ban utlöses när %d unika anmälar-IP-adresser rapporterar inom %d dagar. Sätt värdet till 0 för att stänga av.', 'kkchat'),
          (int) $auto_threshold,
          (int) $auto_window_days
        ); ?>
      <?php else: ?>
        <?php esc_html_e('Automatiskt IP-ban via rapporter är avstängt (tröskeln eller fönstret är 0).', 'kkchat'); ?>
      <?php endif; ?>
    </p>
    <?php if ($searchWarning): ?>
      <div class="notice notice-warning inline"><p><?php esc_html_e('Sökningen kunde inte köras eftersom kolumninformationen saknas. Kontakta support eller uppdatera databasschemat.', 'kkchat'); ?></p></div>
    <?php endif; ?>

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

    <form method="post" style="margin:0 0 16px;">
      <?php wp_nonce_field('kkchat_reports_resolve_blocked'); ?>
      <button class="button" name="kkchat_resolve_blocked" value="1"><?php esc_html_e('Markera blockerade som avklarade', 'kkchat'); ?></button>
      <span class="description" style="margin-left:8px;">
        <?php esc_html_e('Markerar alla öppna rapporter där den anmälda IP-adressen redan är blockerad.', 'kkchat'); ?>
      </span>
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
      <?php if ($rows): foreach ($rows as $r):
        $reporterIp = trim((string)$r->reporter_ip);
        $reportedIp = trim((string)$r->reported_ip);
        $reporterBlocked = $reporterIp !== '' && isset($blockedMap[$reporterIp]) ? $blockedMap[$reporterIp] : null;
        $reportedBlocked = $reportedIp !== '' && isset($blockedMap[$reportedIp]) ? $blockedMap[$reportedIp] : null;
        $reasonLabel = trim((string) ($r->reason_label ?? ''));
        $reasonDetail = trim((string) ($r->reason ?? ''));
        $contextLabel = trim((string) ($r->context_label ?? ''));
        $messageExcerpt = trim((string) ($r->message_excerpt ?? ''));
        $messageKind = trim((string) ($r->message_kind ?? ''));
        $messageId = isset($r->message_id) ? (int) $r->message_id : 0;
      ?>
        <tr>
          <td><?php echo (int)$r->id; ?></td>
          <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$r->created_at)); ?></td>
          <td><?php echo esc_html($r->reporter_name); ?> (<?php echo (int)$r->reporter_id; ?>)</td>
          <td>
            <?php if ($reporterIp !== ''): ?>
              <code class="kkchat-ip<?php echo $reporterBlocked ? ' kkchat-ip--blocked' : ''; ?>"<?php echo $reporterBlocked ? ' title="'.esc_attr($reporterBlocked['tooltip']).'"' : ''; ?>><?php echo esc_html($reporterIp); ?></code>
              <?php if ($reporterBlocked): ?>
                <span class="screen-reader-text"><?php esc_html_e('IP-adressen är blockerad.', 'kkchat'); ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?php echo esc_html($r->reported_name); ?> (<?php echo (int)$r->reported_id; ?>)</td>
          <td>
            <?php if ($reportedIp !== ''): ?>
              <code class="kkchat-ip<?php echo $reportedBlocked ? ' kkchat-ip--blocked' : ''; ?>"<?php echo $reportedBlocked ? ' title="'.esc_attr($reportedBlocked['tooltip']).'"' : ''; ?>><?php echo esc_html($reportedIp); ?></code>
              <?php if ($reportedBlocked): ?>
                <span class="screen-reader-text"><?php esc_html_e('IP-adressen är blockerad.', 'kkchat'); ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($reasonLabel !== ''): ?>
              <strong><?php echo esc_html($reasonLabel); ?></strong><br>
            <?php endif; ?>
            <?php if ($reasonDetail !== ''): ?>
              <span><?php echo esc_html($reasonDetail); ?></span><br>
            <?php endif; ?>
            <?php if ($contextLabel !== ''): ?>
              <span class="kkchat-report-meta"><?php echo esc_html(sprintf('Källa: %s', $contextLabel)); ?></span><br>
            <?php endif; ?>
            <?php if ($messageExcerpt !== '' || $messageId > 0): ?>
              <span class="kkchat-report-meta">
                <?php
                  $excerptLabel = $messageKind === 'image' ? '[Bild]' : $messageExcerpt;
                  $excerptLabel = $excerptLabel !== '' ? $excerptLabel : '—';
                  echo esc_html(sprintf('Meddelande #%d: %s', $messageId, $excerptLabel));
                ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <span class="kkchat-status kkchat-status--<?php echo $r->status === 'open' ? 'open' : 'resolved'; ?>">
              <?php echo $r->status==='open' ? 'Öppen' : 'Löst'; ?>
            </span>
            <?php if ($reportedBlocked && $r->status === 'open'): ?>
              <span class="kkchat-status-pill"><?php esc_html_e('⛔ IP', 'kkchat'); ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $res = wp_nonce_url(add_query_arg(['act' => ($r->status==='open' ? 'resolve' : 'reopen'), 'id' => $r->id], $reports_base), $nonce);
              $del = wp_nonce_url(add_query_arg(['act' => 'delete', 'id' => $r->id], $reports_base), $nonce);
            ?>
            <a class="button" href="<?php echo esc_url($res); ?>"><?php echo $r->status==='open' ? 'Markera som löst' : 'Reaktivera'; ?></a>
            <button class="button kkchat-report-messages" type="button" data-user-id="<?php echo (int) $r->reported_id; ?>" data-user-name="<?php echo esc_attr($r->reported_name); ?>">Se meddelanden</button>
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
    <p class="kkchat-ip-legend">
      <span class="kkchat-ip-flag-text"><?php esc_html_e('Blockerad IP-adress', 'kkchat'); ?></span>
      – <?php esc_html_e('markerade adresser har en aktiv blockering och kan markeras som lösta via knappen ovan.', 'kkchat'); ?>
    </p>

    <div id="kkchat-report-messages-modal" class="kkchat-report-modal" hidden>
      <div class="kkchat-report-modal__box" role="dialog" aria-modal="true" aria-labelledby="kkchat-report-messages-title">
        <div class="kkchat-report-modal__head">
          <strong id="kkchat-report-messages-title">Meddelanden</strong>
          <button type="button" class="button" id="kkchat-report-messages-close">Stäng</button>
        </div>
        <div class="kkchat-report-modal__body">
          <div id="kkchat-report-messages-content" class="kkchat-report-modal__content"></div>
        </div>
      </div>
    </div>

    <script>
      (function(){
        const modal = document.getElementById('kkchat-report-messages-modal');
        const closeBtn = document.getElementById('kkchat-report-messages-close');
        const content = document.getElementById('kkchat-report-messages-content');
        const title = document.getElementById('kkchat-report-messages-title');
        const apiBase = <?php echo wp_json_encode(trailingslashit(rest_url('kkchat/v1'))); ?>;
        const restNonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
        let activeUserId = null;

        function escapeHtml(text){
          const div = document.createElement('div');
          div.textContent = text || '';
          return div.innerHTML;
        }

        function formatTime(ts){
          try {
            return new Date(Number(ts) * 1000).toLocaleString('sv-SE');
          } catch (_) {
            return String(ts || '');
          }
        }

        function messageLabel(msg){
          if (!msg) return '';
          const kind = String(msg.kind || 'chat').toLowerCase();
          if (kind === 'image') return '[Bild]';
          return msg.content || '';
        }

        function showModal(){
          if (!modal) return;
          modal.hidden = false;
          modal.setAttribute('aria-hidden', 'false');
        }

        function hideModal(){
          if (!modal) return;
          modal.hidden = true;
          modal.setAttribute('aria-hidden', 'true');
          if (content) content.innerHTML = '';
          activeUserId = null;
        }

        async function fetchJson(url){
          const resp = await fetch(url, {
            credentials: 'include',
            headers: {
              'X-WP-Nonce': restNonce
            }
          });
          const data = await resp.json().catch(() => ({}));
          if (!resp.ok || !data || data.ok !== true) {
            throw new Error((data && data.err) || 'Kunde inte hämta data');
          }
          return data;
        }

        function renderMessageGroups(rows, userId){
          if (!content) return;
          if (!rows.length) {
            content.innerHTML = '<p>Inga meddelanden hittades.</p>';
            return;
          }

          const rooms = new Map();
          const dms = new Map();

          rows.forEach(msg => {
            if (Number(msg.sender_id) !== Number(userId)) return;
            if (msg.recipient_id) {
              const peerId = Number(msg.recipient_id);
              const existing = dms.get(peerId) || { peerId, peerName: msg.recipient_name || `#${peerId}`, rows: [] };
              existing.rows.push(msg);
              dms.set(peerId, existing);
            } else {
              const roomKey = msg.room || 'Lobby';
              const existing = rooms.get(roomKey) || { room: roomKey, rows: [] };
              existing.rows.push(msg);
              rooms.set(roomKey, existing);
            }
          });

          const htmlParts = [];
          rooms.forEach(group => {
            htmlParts.push(`<div class="kkchat-report-section"><h4>Rum: ${escapeHtml(group.room)}</h4>`);
            htmlParts.push('<ul class="kkchat-report-list">');
            group.rows.forEach(row => {
              htmlParts.push(`<li><span class="kkchat-report-time">${escapeHtml(formatTime(row.time))}</span> ${escapeHtml(messageLabel(row))}</li>`);
            });
            htmlParts.push('</ul></div>');
          });

          dms.forEach(group => {
            htmlParts.push(`<div class="kkchat-report-section"><h4>DM med ${escapeHtml(group.peerName)}</h4>`);
            htmlParts.push(`<button class="button kkchat-report-thread" type="button" data-peer="${group.peerId}">Hämta hela DM-tråden</button>`);
            htmlParts.push('<ul class="kkchat-report-list">');
            group.rows.forEach(row => {
              htmlParts.push(`<li><span class="kkchat-report-time">${escapeHtml(formatTime(row.time))}</span> ${escapeHtml(messageLabel(row))}</li>`);
            });
            htmlParts.push('</ul>');
            htmlParts.push(`<div class="kkchat-report-thread-output" data-peer-output="${group.peerId}"></div>`);
            htmlParts.push('</div>');
          });

          content.innerHTML = htmlParts.join('') || '<p>Inga meddelanden hittades.</p>';
        }

        async function loadUserMessages(userId, userName){
          if (!content) return;
          activeUserId = userId;
          title.textContent = `Meddelanden från ${userName || '#' + userId}`;
          content.innerHTML = '<p>Laddar…</p>';
          showModal();
          try {
            const data = await fetchJson(`${apiBase}admin/user-messages?user_id=${encodeURIComponent(userId)}&limit=500`);
            renderMessageGroups(data.rows || [], userId);
          } catch (err) {
            content.innerHTML = `<p>${escapeHtml(err.message || 'Kunde inte hämta meddelanden.')}</p>`;
          }
        }

        async function loadThread(userId, peerId, outputEl){
          if (!outputEl) return;
          outputEl.innerHTML = '<p>Laddar DM-tråd…</p>';
          try {
            const data = await fetchJson(`${apiBase}admin/dm-thread?user_id=${encodeURIComponent(userId)}&peer_id=${encodeURIComponent(peerId)}&limit=500`);
            const rows = Array.isArray(data.rows) ? data.rows : [];
            const list = rows.map(row => {
              const who = row.sender_name || `#${row.sender_id}`;
              return `<li><span class="kkchat-report-time">${escapeHtml(formatTime(row.time))}</span> <strong>${escapeHtml(who)}</strong>: ${escapeHtml(messageLabel(row))}</li>`;
            }).join('');
            outputEl.innerHTML = list ? `<ul class="kkchat-report-list">${list}</ul>` : '<p>Inga DM-meddelanden hittades.</p>';
          } catch (err) {
            outputEl.innerHTML = `<p>${escapeHtml(err.message || 'Kunde inte hämta DM-tråden.')}</p>`;
          }
        }

        document.querySelectorAll('.kkchat-report-messages').forEach(btn => {
          btn.addEventListener('click', () => {
            const userId = btn.getAttribute('data-user-id');
            const userName = btn.getAttribute('data-user-name') || '';
            loadUserMessages(userId, userName);
          });
        });

        modal?.addEventListener('click', (event) => {
          if (event.target === modal) hideModal();
        });
        closeBtn?.addEventListener('click', hideModal);
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && modal && !modal.hidden) hideModal();
        });

        content?.addEventListener('click', (event) => {
          const btn = event.target.closest('.kkchat-report-thread');
          if (!btn) return;
          const peerId = btn.getAttribute('data-peer');
          const output = content.querySelector(`[data-peer-output="${peerId}"]`);
          if (!output) {
            return;
          }
          if (!activeUserId) {
            output.innerHTML = '<p>Kan inte hitta användar-id.</p>';
            return;
          }
          loadThread(activeUserId, peerId, output);
        });
      })();
    </script>

    <style>
      .kkchat-ip {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        border: 1px solid transparent;
        font-family: SFMono-Regular,Consolas,"Liberation Mono",Menlo,monospace;
      }
      .kkchat-ip--blocked {
        color: #8b0000;
        border-color: #d93025;
        background: #fde8e7;
        font-weight: 600;
      }
      .kkchat-ip-flag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-weight: 600;
        color: #8b0000;
        font-size: 12px;
        margin-left: 4px;
      }
      .kkchat-ip-flag-text {
        font-size: 12px;
      }
      .kkchat-ip-legend {
        margin: 12px 0 0;
        font-size: 12px;
        color: #555;
        display: flex;
        align-items: center;
        gap: 6px;
      }
      .kkchat-report-meta {
        color: #555;
        font-size: 12px;
        display: inline-block;
        margin-top: 2px;
      }
      .kkchat-report-modal {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
      }
      .kkchat-report-modal[hidden] {
        display: none;
      }
      .kkchat-report-modal__box {
        background: #fff;
        border-radius: 10px;
        padding: 16px;
        width: min(900px, 95vw);
        max-height: 85vh;
        overflow: hidden;
        box-shadow: 0 12px 32px rgba(0,0,0,0.2);
      }
      .kkchat-report-modal__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
      }
      .kkchat-report-modal__body {
        overflow: auto;
        max-height: calc(85vh - 80px);
      }
      .kkchat-report-section {
        margin-bottom: 20px;
      }
      .kkchat-report-section h4 {
        margin: 0 0 8px;
      }
      .kkchat-report-list {
        margin: 8px 0;
        padding-left: 18px;
      }
      .kkchat-report-time {
        color: #666;
        font-size: 12px;
        margin-right: 6px;
      }
      .kkchat-status {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
      }
      .kkchat-status--resolved {
        color: #0b5c13;
        background: #dff5e3;
        border: 1px solid #3ca45b;
      }
      .kkchat-status--open {
        color: #ffffff;
        background: #f75f5e;
        border: 1px solid #d63638;
    }
      .kkchat-status-pill {
        display: inline-block;
        margin-left: 6px;
        padding: 2px 6px;
        border-radius: 999px;
        background: #fde8e7;
        color: #8b0000;
        font-size: 11px;
        font-weight: 600;
      }
    </style>
  </div>
  <?php
}

/**
 * Loggar: sök/purge + inline IP-block + bildminiatyrer/lightbox + Samtal-modal
 * – Svensk UI, egen "Samtal"-kolumn efter ID, inga ”Öppna i ny flik”-länkar.
 */
