<?php
if (!defined('ABSPATH')) exit;

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
