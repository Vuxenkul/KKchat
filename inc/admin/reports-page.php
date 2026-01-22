<?php
if (!defined('ABSPATH')) exit;

function kkchat_admin_reports_page() {
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();

  $nonce_key = 'kkchat_reports_actions';

  // Åtgärder
  if (isset($_POST['kk_report_resolve'])) {
    check_admin_referer($nonce_key);
    $id = max(0, (int)($_POST['report_id'] ?? 0));
    if ($id > 0) {
      $wpdb->update(
        $t['reports'],
        ['status' => 'resolved', 'resolved_at' => time()],
        ['id' => $id],
        ['%s','%d'],
        ['%d']
      );
      echo '<div class="updated"><p>Rapport #'.(int)$id.' markerad som löst.</p></div>';
    }
  }

  if (isset($_POST['kk_report_reopen'])) {
    check_admin_referer($nonce_key);
    $id = max(0, (int)($_POST['report_id'] ?? 0));
    if ($id > 0) {
      $wpdb->update(
        $t['reports'],
        ['status' => 'open', 'resolved_at' => null, 'resolved_by' => null],
        ['id' => $id],
        ['%s','%s','%s'],
        ['%d']
      );
      echo '<div class="updated"><p>Rapport #'.(int)$id.' återöppnad.</p></div>';
    }
  }

  if (isset($_POST['kk_report_delete'])) {
    check_admin_referer($nonce_key);
    $id = max(0, (int)($_POST['report_id'] ?? 0));
    if ($id > 0) {
      $wpdb->delete($t['reports'], ['id' => $id], ['%d']);
      echo '<div class="updated"><p>Rapport #'.(int)$id.' borttagen.</p></div>';
    }
  }

  if (isset($_POST['kk_report_bulk_blocked'])) {
    check_admin_referer($nonce_key);
    $now = time();
    $wpdb->query($wpdb->prepare(
      "UPDATE {$t['blocks']} SET active=0 WHERE active=1 AND expires_at IS NOT NULL AND expires_at <= %d",
      $now
    ));
    $updated = $wpdb->query($wpdb->prepare(
      "UPDATE {$t['reports']} r
         INNER JOIN {$t['blocks']} b
           ON b.type='ipban' AND b.active=1 AND b.target_ip = r.reported_ip_key
       SET r.status='resolved', r.resolved_at=%d
     WHERE r.status='open'",
      $now
    ));
    echo '<div class="updated"><p>Markerade '.esc_html((string)max(0, (int)$updated)).' blockerade rapporter som lösta.</p></div>';
  }

  // Filter
  $status_input = isset($_GET['status']) ? (string)$_GET['status'] : '';
  if ($status_input === '') {
    $status_input = 'open';
  }
  $status = in_array($status_input, ['open','resolved','all'], true) ? $status_input : 'open';
  $q      = sanitize_text_field($_GET['q'] ?? '');
  $per    = max(10, min(200, (int)($_GET['per'] ?? 50)));
  $page   = max(1, (int)($_GET['paged'] ?? 1));
  $offset = ($page - 1) * $per;

  $where = []; $params = [];
  if ($status !== 'all') {
    $where[] = "status = %s";
    $params[] = $status;
  }
  if ($q !== '') {
    $like = '%'.$wpdb->esc_like($q).'%';
    $where[] = "(reporter_name LIKE %s OR reported_name LIKE %s OR reason LIKE %s OR message_excerpt LIKE %s OR reporter_ip LIKE %s OR reported_ip LIKE %s)";
    array_push($params, $like, $like, $like, $like, $like, $like);
  }
  $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

  $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['reports']} $whereSql", ...$params));
  $pages = max(1, (int)ceil($total / $per));
  if ($page > $pages) $page = $pages;
  $offset = ($page - 1) * $per;

  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, created_at, reporter_id, reporter_name, reporter_ip, reporter_ip_key,
            reported_id, reported_name, reported_ip, reported_ip_key,
            reason, reason_key, reason_label, message_excerpt, status, context_type, context_label
       FROM {$t['reports']} $whereSql
   ORDER BY id DESC
      LIMIT %d OFFSET %d",
    ...array_merge($params, [$per, $offset])
  ));

  // Markera IP-adresser som redan är blockerade
  $blockedMap = [];
  if ($rows) {
    $now = time();
    $wpdb->query($wpdb->prepare(
      "UPDATE {$t['blocks']} SET active=0 WHERE active=1 AND expires_at IS NOT NULL AND expires_at <= %d",
      $now
    ));

    $lookupKeys = [];
    $keyToIps = [];
    foreach ($rows as $row) {
      foreach (['reporter_ip', 'reported_ip'] as $field) {
        $rawIp = trim((string)($row->$field ?? ''));
        if ($rawIp === '') continue;

        $keysForIp = [];
        $key = kkchat_ip_ban_key($rawIp);
        if ($key) $keysForIp[] = $key;
        if (kkchat_is_ipv6($rawIp)) {
          $packed = @inet_pton($rawIp);
          if ($packed !== false) {
            $canon = strtolower((string)@inet_ntop($packed));
            if ($canon !== '') $keysForIp[] = $canon;
          }
        }
        if (!$keysForIp) continue;
        $keysForIp = array_values(array_unique(array_filter($keysForIp, 'strlen')));
        foreach ($keysForIp as $keyStr) {
          $lookupKeys[$keyStr] = true;
          if (!isset($keyToIps[$keyStr])) $keyToIps[$keyStr] = [];
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
          ];
        }
      }
    }
  }

  $reports_base = menu_page_url('kkchat_reports', false);
  $filter_args = [
    'status' => $status,
    'q' => $q,
    'per' => $per,
    'paged' => $page,
  ];

  // Popup: senaste meddelanden per rapport
  $view_report = max(0, (int)($_GET['view_report'] ?? 0));
  $view_row = null;
  $room_groups = [];
  $dm_groups = [];
  if ($view_report > 0) {
    $view_row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, reported_id, reported_name FROM {$t['reports']} WHERE id=%d",
      $view_report
    ));
    if ($view_row && (int)$view_row->reported_id > 0) {
      $recent = $wpdb->get_results($wpdb->prepare(
        "SELECT id, created_at, kind, room, recipient_id, recipient_name, content
           FROM {$t['messages']}
          WHERE sender_id=%d
          ORDER BY id DESC
          LIMIT %d",
        (int)$view_row->reported_id,
        200
      ));
      if ($recent) {
        foreach ($recent as $msg) {
          if (!empty($msg->recipient_id)) {
            $rid = (int)$msg->recipient_id;
            if (!isset($dm_groups[$rid])) {
              $dm_groups[$rid] = [
                'name' => (string)($msg->recipient_name ?: ('#'.$rid)),
                'rows' => [],
              ];
            }
            $dm_groups[$rid]['rows'][] = $msg;
          } else {
            $room = (string)($msg->room ?: 'okänt');
            if (!isset($room_groups[$room])) $room_groups[$room] = [];
            $room_groups[$room][] = $msg;
          }
        }
      }
    }
  }

  // Popup: full DM-konversation
  $convA = max(0, (int)($_GET['conv_a'] ?? 0));
  $convB = max(0, (int)($_GET['conv_b'] ?? 0));
  $convRows = [];
  if ($convA > 0 && $convB > 0) {
    $convRows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, created_at, kind, room, sender_id, sender_name, recipient_id, recipient_name, content
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
    <h1>KKchat – Rapporter</h1>

    <form method="post" style="margin:12px 0;">
      <?php wp_nonce_field($nonce_key); ?>
      <button class="button" name="kk_report_bulk_blocked" value="1">Markera blockerade som avklarade</button>
      <span class="description" style="margin-left:8px">Markerar öppna rapporter där rapporterad IP redan är blockerad.</span>
    </form>

    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="background:#fff;border:1px solid #eee;padding:12px;border-radius:8px;margin-bottom:12px">
      <input type="hidden" name="page" value="kkchat_reports">
      <table class="form-table">
        <tr>
          <th scope="row"><label for="rep_status">Status</label></th>
          <td>
            <select id="rep_status" name="status">
              <option value="open" <?php selected($status, 'open'); ?>>Öppen</option>
              <option value="resolved" <?php selected($status, 'resolved'); ?>>Löst</option>
              <option value="all" <?php selected($status, 'all'); ?>>Alla</option>
            </select>
          </td>
          <th scope="row"><label for="rep_q">Sök</label></th>
          <td><input id="rep_q" name="q" class="regular-text" value="<?php echo esc_attr($q); ?>" placeholder="Namn, anledning, meddelande, IP"></td>
        </tr>
        <tr>
          <th scope="row"><label for="rep_per">Per sida</label></th>
          <td><input id="rep_per" name="per" type="number" min="10" max="200" class="small-text" value="<?php echo (int)$per; ?>"></td>
          <td colspan="2">
            <button class="button button-primary">Filtrera</button>
            <a class="button" href="<?php echo esc_url($reports_base); ?>">Återställ</a>
          </td>
        </tr>
      </table>
    </form>

    <p><?php echo esc_html(number_format_i18n($total)); ?> rapporter. Sida <?php echo (int)$page; ?> / <?php echo (int)$pages; ?></p>

    <p class="kkchat-ip-legend">
      <span class="kkchat-ip-flag">
        <span aria-hidden="true">⛔</span>
        <span class="kkchat-ip-flag-text">Blockerad IP</span>
        <span class="screen-reader-text">Blockerad IP-adress</span>
      </span>
      – adressen har en aktiv IP-blockering.
    </p>

    <style>
      .kkchat-ip { display:inline-block; padding:2px 6px; border-radius:4px; border:1px solid transparent; font-family:SFMono-Regular,Consolas,"Liberation Mono",Menlo,monospace; }
      .kkchat-ip--blocked { color:#8b0000; border-color:#d93025; background:#fde8e7; font-weight:600; }
      .kkchat-ip-flag { display:inline-flex; align-items:center; gap:4px; font-weight:600; color:#8b0000; font-size:12px; margin-left:4px; }
      .kkchat-ip-flag-text { font-size:12px; }
      .kkchat-ip-legend { margin:6px 0 14px; font-size:12px; color:#555; display:flex; align-items:center; gap:6px; }
      .kkchat-report-actions form { display:inline-block; margin-right:6px; }
      .kkchat-report-actions form:last-child { margin-right:0; }
      .kkchat-msg { white-space: pre-wrap; }
      .kkreport-overlay { position: fixed; inset: 0; display:none; align-items:center; justify-content:center; background: rgba(0,0,0,.55); z-index: 99998; }
      .kkreport-overlay.open { display:flex; }
      .kkreport-panel { background:#fff; width: 980px; max-width: 95vw; height: 80vh; max-height: 90vh; border-radius:10px; box-shadow: 0 10px 40px rgba(0,0,0,.3); display:flex; flex-direction:column; }
      .kkreport-header { padding: 12px 16px; border-bottom:1px solid #eee; display:flex; align-items:center; justify-content:space-between; }
      .kkreport-body { padding: 12px 16px; overflow:auto; background:#fafafa; }
      .kkreport-group { margin-bottom:16px; }
      .kkreport-group h3 { margin: 0 0 8px; font-size: 16px; }
      .kkreport-msg { margin: 8px 0; padding: 8px 10px; border-radius: 8px; background:#fff; border:1px solid #eee; }
      .kkreport-meta { font-size: 12px; color:#666; margin-bottom:4px; }
      .kkreport-dm-header { display:flex; align-items:center; justify-content:space-between; gap:12px; }
    </style>

    <table class="widefat striped">
      <thead>
        <tr>
          <th>ID</th>
          <th>Tid</th>
          <th>Reporter</th>
          <th>Reporter IP</th>
          <th>Rapporterad</th>
          <th>Rapporterad IP</th>
          <th>Anledning</th>
          <th>Status</th>
          <th>Åtgärder</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows): foreach ($rows as $r): ?>
        <?php
          $status_label = ($r->status === 'resolved') ? 'Löst' : 'Öppen';
          $reporter_ip = trim((string)($r->reporter_ip ?? ''));
          $reported_ip = trim((string)($r->reported_ip ?? ''));
          $reporter_blocked = $reporter_ip !== '' && !empty($blockedMap[$reporter_ip]['active']);
          $reported_blocked = $reported_ip !== '' && !empty($blockedMap[$reported_ip]['active']);
          $reporter_tip = $reporter_blocked ? $blockedMap[$reporter_ip]['tooltip'] : '';
          $reported_tip = $reported_blocked ? $blockedMap[$reported_ip]['tooltip'] : '';
          $reason_label = trim((string)($r->reason_label ?? ''));
          $reason_text  = trim((string)($r->reason ?? ''));
          $message_excerpt = trim((string)($r->message_excerpt ?? ''));
          $context_label = trim((string)($r->context_label ?? ''));
        ?>
        <tr>
          <td><?php echo (int)$r->id; ?></td>
          <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$r->created_at)); ?></td>
          <td><?php echo esc_html($r->reporter_name); ?> (#<?php echo (int)$r->reporter_id; ?>)</td>
          <td>
            <?php if ($reporter_ip !== ''): ?>
              <span class="kkchat-ip<?php echo $reporter_blocked ? ' kkchat-ip--blocked' : ''; ?>" title="<?php echo esc_attr($reporter_tip); ?>">
                <?php echo esc_html($reporter_ip); ?>
              </span>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><?php echo esc_html($r->reported_name); ?> (#<?php echo (int)$r->reported_id; ?>)</td>
          <td>
            <?php if ($reported_ip !== ''): ?>
              <span class="kkchat-ip<?php echo $reported_blocked ? ' kkchat-ip--blocked' : ''; ?>" title="<?php echo esc_attr($reported_tip); ?>">
                <?php echo esc_html($reported_ip); ?>
              </span>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td>
            <?php if ($reason_label !== ''): ?>
              <strong><?php echo esc_html($reason_label); ?></strong>
            <?php endif; ?>
            <?php if ($reason_text !== ''): ?>
              <div class="kkchat-msg"><?php echo esc_html($reason_text); ?></div>
            <?php endif; ?>
            <?php if ($context_label !== ''): ?>
              <div class="description">Kontext: <?php echo esc_html($context_label); ?></div>
            <?php endif; ?>
            <?php if ($message_excerpt !== ''): ?>
              <div class="description">Meddelande: <?php echo esc_html($message_excerpt); ?></div>
            <?php endif; ?>
          </td>
          <td><?php echo esc_html($status_label); ?></td>
          <td class="kkchat-report-actions">
            <?php $msg_url = add_query_arg(array_merge($filter_args, ['view_report' => (int)$r->id]), $reports_base); ?>
            <a class="button" href="<?php echo esc_url($msg_url); ?>">Se meddelanden</a>
            <?php if ($r->status === 'open'): ?>
              <form method="post">
                <?php wp_nonce_field($nonce_key); ?>
                <input type="hidden" name="report_id" value="<?php echo (int)$r->id; ?>">
                <button class="button" name="kk_report_resolve" value="1">Markera som löst</button>
              </form>
            <?php else: ?>
              <form method="post">
                <?php wp_nonce_field($nonce_key); ?>
                <input type="hidden" name="report_id" value="<?php echo (int)$r->id; ?>">
                <button class="button" name="kk_report_reopen" value="1">Reaktivera</button>
              </form>
            <?php endif; ?>
            <form method="post" onsubmit="return confirm('Ta bort rapporten permanent?');">
              <?php wp_nonce_field($nonce_key); ?>
              <input type="hidden" name="report_id" value="<?php echo (int)$r->id; ?>">
              <button class="button button-secondary" name="kk_report_delete" value="1">Ta bort</button>
            </form>
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="9"><?php echo $total > 0 ? 'Inga rapporter på denna sida.' : 'Inga rapporter hittades.'; ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>

    <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
      <?php if ($page > 1): ?>
        <?php $prev_url = add_query_arg(array_merge($filter_args, ['paged' => $page - 1]), $reports_base); ?>
        <a class="button" href="<?php echo esc_url($prev_url); ?>">&laquo; Föregående</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
        <?php $next_url = add_query_arg(array_merge($filter_args, ['paged' => $page + 1]), $reports_base); ?>
        <a class="button" href="<?php echo esc_url($next_url); ?>">Nästa &raquo;</a>
      <?php endif; ?>
      <span class="description">Sida <?php echo (int)$page; ?> / <?php echo (int)$pages; ?></span>
    </div>

    <?php
      $messages_open = $view_row ? true : false;
      $conv_open = ($convA > 0 && $convB > 0);
    ?>
    <div id="kkreportMessages" class="kkreport-overlay<?php echo $messages_open ? ' open' : ''; ?>" role="dialog" aria-modal="true" aria-label="Meddelanden">
      <div class="kkreport-panel">
        <div class="kkreport-header">
          <div><strong>Meddelanden från <?php echo $view_row ? esc_html($view_row->reported_name) : ''; ?></strong></div>
          <button type="button" class="button" onclick="kkCloseReportModal()">Stäng</button>
        </div>
        <div class="kkreport-body">
          <?php if (!$view_row): ?>
            <p>Inga meddelanden hittades.</p>
          <?php else: ?>
            <?php if (!$room_groups && !$dm_groups): ?>
              <p>Inga meddelanden hittades för användaren.</p>
            <?php endif; ?>
            <?php if ($room_groups): ?>
              <div class="kkreport-group">
                <h3>Chatrum</h3>
                <?php foreach ($room_groups as $room => $msgs): ?>
                  <div style="margin-bottom:12px;">
                    <div><strong>Rum: <?php echo esc_html($room); ?></strong></div>
                    <?php foreach (array_reverse($msgs) as $msg): ?>
                      <div class="kkreport-msg">
                        <div class="kkreport-meta"><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$msg->created_at)); ?></div>
                        <?php if ($msg->kind === 'image'): ?>
                          <div class="kkchat-msg">[Bild] <?php echo esc_html((string)$msg->content); ?></div>
                        <?php else: ?>
                          <div class="kkchat-msg"><?php echo esc_html((string)$msg->content); ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($dm_groups): ?>
              <div class="kkreport-group">
                <h3>DM</h3>
                <?php foreach ($dm_groups as $dm_id => $group): ?>
                  <div style="margin-bottom:12px;">
                    <div class="kkreport-dm-header">
                      <strong>DM med <?php echo esc_html($group['name']); ?> (#<?php echo (int)$dm_id; ?>)</strong>
                      <?php $conv_url = add_query_arg(array_merge($filter_args, ['view_report' => $view_report, 'conv_a' => (int)$view_row->reported_id, 'conv_b' => (int)$dm_id]), $reports_base); ?>
                      <a class="button" href="<?php echo esc_url($conv_url); ?>">Hämta hela DM-tråden</a>
                    </div>
                    <?php foreach (array_reverse($group['rows']) as $msg): ?>
                      <div class="kkreport-msg">
                        <div class="kkreport-meta"><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$msg->created_at)); ?></div>
                        <?php if ($msg->kind === 'image'): ?>
                          <div class="kkchat-msg">[Bild] <?php echo esc_html((string)$msg->content); ?></div>
                        <?php else: ?>
                          <div class="kkchat-msg"><?php echo esc_html((string)$msg->content); ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div id="kkreportConv" class="kkreport-overlay<?php echo $conv_open ? ' open' : ''; ?>" role="dialog" aria-modal="true" aria-label="DM-konversation">
      <div class="kkreport-panel">
        <div class="kkreport-header">
          <div><strong>DM-tråd</strong></div>
          <button type="button" class="button" onclick="kkCloseConvModal()">Stäng</button>
        </div>
        <div class="kkreport-body">
          <?php if ($conv_open && !$convRows): ?>
            <p>Ingen DM-tråd hittades.</p>
          <?php elseif ($convRows): ?>
            <?php foreach ($convRows as $msg): ?>
              <div class="kkreport-msg">
                <div class="kkreport-meta">
                  <strong><?php echo esc_html((string)($msg->sender_name ?: ('#'.$msg->sender_id))); ?></strong>
                  • <?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$msg->created_at)); ?>
                </div>
                <?php if ($msg->kind === 'image'): ?>
                  <div class="kkchat-msg">[Bild] <?php echo esc_html((string)$msg->content); ?></div>
                <?php else: ?>
                  <div class="kkchat-msg"><?php echo esc_html((string)$msg->content); ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <script>
      function kkCloseReportModal() {
        var url = new URL(window.location.href);
        url.searchParams.delete('view_report');
        url.searchParams.delete('conv_a');
        url.searchParams.delete('conv_b');
        window.location.href = url.toString();
      }
      function kkCloseConvModal() {
        var url = new URL(window.location.href);
        url.searchParams.delete('conv_a');
        url.searchParams.delete('conv_b');
        window.location.href = url.toString();
      }
    </script>
  </div>
  <?php
}
