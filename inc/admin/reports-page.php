<?php
if (!defined('ABSPATH')) exit;

function kkchat_admin_reports_page() {
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce = 'kkchat_reports';

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
    "SELECT * FROM {$t['reports']} $whereSql ORDER BY CASE WHEN status='open' THEN 0 ELSE 1 END, id DESC LIMIT %d OFFSET %d",
    ...array_merge($params, [$per, $offset])
  ));

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
      ?>
        <tr>
          <td><?php echo (int)$r->id; ?></td>
          <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int)$r->created_at)); ?></td>
          <td><?php echo esc_html($r->reporter_name); ?> (<?php echo (int)$r->reporter_id; ?>)</td>
          <td>
            <?php if ($reporterIp !== ''): ?>
              <code class="kkchat-ip<?php echo $reporterBlocked ? ' kkchat-ip--blocked' : ''; ?>"<?php echo $reporterBlocked ? ' title="'.esc_attr($reporterBlocked['tooltip']).'"' : ''; ?>><?php echo esc_html($reporterIp); ?></code>
              <?php if ($reporterBlocked): ?>
                <span class="kkchat-ip-flag" aria-hidden="true">⛔</span>
                <span class="screen-reader-text"><?php esc_html_e('IP-adressen är blockerad.', 'kkchat'); ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?php echo esc_html($r->reported_name); ?> (<?php echo (int)$r->reported_id; ?>)</td>
          <td>
            <?php if ($reportedIp !== ''): ?>
              <code class="kkchat-ip<?php echo $reportedBlocked ? ' kkchat-ip--blocked' : ''; ?>"<?php echo $reportedBlocked ? ' title="'.esc_attr($reportedBlocked['tooltip']).'"' : ''; ?>><?php echo esc_html($reportedIp); ?></code>
              <?php if ($reportedBlocked): ?>
                <span class="kkchat-ip-flag" aria-hidden="true">⛔</span>
                <span class="screen-reader-text"><?php esc_html_e('IP-adressen är blockerad.', 'kkchat'); ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td style="white-space:pre-wrap"><?php echo esc_html($r->reason); ?></td>
          <td>
            <span class="kkchat-status kkchat-status--<?php echo $r->status === 'open' ? 'open' : 'resolved'; ?>">
              <?php echo $r->status==='open' ? 'Öppen' : 'Löst'; ?>
            </span>
            <?php if ($reportedBlocked && $r->status === 'open'): ?>
              <span class="kkchat-status-pill"><?php esc_html_e('Blockerad IP', 'kkchat'); ?></span>
            <?php endif; ?>
          </td>
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
    <p class="kkchat-ip-legend">
      <span class="kkchat-ip-flag" aria-hidden="true">⛔</span>
      <span class="kkchat-ip-flag-text"><?php esc_html_e('Blockerad IP-adress', 'kkchat'); ?></span>
      – <?php esc_html_e('markerade adresser har en aktiv blockering och kan markeras som lösta via knappen ovan.', 'kkchat'); ?>
    </p>

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
      .kkchat-status {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
      }
      .kkchat-status--open {
        color: #0b5c13;
        background: #dff5e3;
        border: 1px solid #3ca45b;
      }
      .kkchat-status--resolved {
        color: #555;
        background: #f0f0f0;
        border: 1px solid #ccc;
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
