<?php
if (!defined('ABSPATH')) exit;

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
