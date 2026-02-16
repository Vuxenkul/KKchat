<?php
if (!defined('ABSPATH')) exit;

function kkchat_admin_moderation_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce_key = 'kkchat_moderation';

  $ip_lookup_status = null; // 'blocked', 'clear', or 'error'
  $ip_lookup_row    = null;
  $ip_lookup_input  = '';

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

  // Slå upp IP-blockering
  if (isset($_POST['kk_lookup_ip'])) {
    check_admin_referer($nonce_key);

    $lookup_ip        = trim((string) ($_POST['lookup_ip'] ?? ''));
    $ip_lookup_input  = $lookup_ip;
    $ip_lookup_status = 'error';

    if (!filter_var($lookup_ip, FILTER_VALIDATE_IP)) {
      echo '<div class="error"><p>Ogiltig IP-adress.</p></div>';
    } else {
      $key = kkchat_ip_ban_key($lookup_ip);
      if (!$key) {
        echo '<div class="error"><p>Kunde inte normalisera IP-adressen.</p></div>';
      } else {
        $ban = $wpdb->get_row($wpdb->prepare(
          "SELECT * FROM {$t['blocks']} WHERE active=1 AND type='ipban' AND target_ip = %s LIMIT 1",
          $key
        ));

        if (!$ban && kkchat_is_ipv6($lookup_ip)) {
          $norm = strtolower(@inet_ntop(@inet_pton($lookup_ip)) ?: $lookup_ip);
          $ban  = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['blocks']} WHERE active=1 AND type='ipban' AND target_ip = %s LIMIT 1",
            $norm
          ));
        }

        if ($ban) {
          $ip_lookup_status = 'blocked';
          $ip_lookup_row    = $ban;
        } else {
          $ip_lookup_status = 'clear';
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

  $moderation_base = add_query_arg([
    'active_page' => $active_page,
    'recent_page' => $recent_page,
  ], menu_page_url('kkchat_moderation', false));
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

    <h3>Slå upp IP</h3>
    <form method="post"><?php wp_nonce_field($nonce_key); ?>
      <table class="form-table">
        <tr>
          <th><label for="lookup_ip">IP-adress</label></th>
          <td><input id="lookup_ip" name="lookup_ip" class="regular-text" value="<?php echo esc_attr($ip_lookup_input); ?>" placeholder="t.ex. 203.0.113.5 eller 2001:db8::1" required></td>
        </tr>
      </table>
      <p><button class="button" name="kk_lookup_ip" value="1">Slå upp IP</button></p>
    </form>
    <?php if ($ip_lookup_status === 'blocked' && $ip_lookup_row): ?>
      <div class="notice notice-error"><p><strong>Blockerad:</strong> IP:n matchar en aktiv blockering.</p></div>
      <table class="widefat striped">
        <thead><tr><th>ID</th><th>Typ</th><th>Mål</th><th>IP</th><th>Orsak</th><th>Av</th><th>Skapad</th><th>Löper ut</th><th>Åtgärd</th></tr></thead>
        <tbody>
          <?php $b = $ip_lookup_row; ?>
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
        </tbody>
      </table>
    <?php elseif ($ip_lookup_status === 'clear'): ?>
      <div class="notice notice-success"><p><strong>Inte blockerad:</strong> Inga aktiva regler för IP-adressen.</p></div>
    <?php endif; ?>

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