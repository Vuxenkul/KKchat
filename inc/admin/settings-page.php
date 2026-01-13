<?php
if (!defined('ABSPATH')) exit;

function kkchat_admin_settings_page() {
  if (!current_user_can('manage_options')) return;
  $nonce_key = 'kkchat_settings';

  // Spara
  if (isset($_POST['kk_save_settings'])) {
    check_admin_referer($nonce_key);

    $sync_enabled            = !empty($_POST['sync_enabled']) ? 1 : 0;
    $sync_concurrency        = max(1, (int) ($_POST['sync_concurrency'] ?? 1));
    $sync_breaker_threshold  = max(1, (int) ($_POST['sync_breaker_threshold'] ?? 5));
    $sync_breaker_window     = max(5, (int) ($_POST['sync_breaker_window'] ?? 60));
    $sync_breaker_cooldown   = max(10, (int) ($_POST['sync_breaker_cooldown'] ?? 90));

    $dupe_window_seconds   = max(1, (int)($_POST['dupe_window_seconds'] ?? 120));
    $dupe_fast_seconds     = max(1, (int)($_POST['dupe_fast_seconds'] ?? 30));
    $dupe_max_repeats      = max(1, (int)($_POST['dupe_max_repeats'] ?? 2));
    $min_interval_seconds  = max(0, (int)($_POST['min_interval_seconds'] ?? 3)); // 0 = av
    $dupe_autokick_minutes = max(0, (int)($_POST['dupe_autokick_minutes'] ?? 1)); // 0 = av
    $dedupe_window         = max(1, (int)($_POST['dedupe_window'] ?? 10)); // direkt-spamskydd

    $report_autoban_threshold   = max(0, (int)($_POST['report_autoban_threshold'] ?? 0));
    $report_autoban_window_days = max(0, (int)($_POST['report_autoban_window_days'] ?? 0));
    $poll_hidden_threshold = max(0, (int)($_POST['poll_hidden_threshold'] ?? 90));
    $poll_hidden_delay     = max(0, (int)($_POST['poll_hidden_delay'] ?? 30));
    $poll_hot_interval     = max(1, (int)($_POST['poll_hot_interval'] ?? 4));
    $poll_medium_interval  = max(1, (int)($_POST['poll_medium_interval'] ?? 8));
    $poll_slow_interval    = max(1, (int)($_POST['poll_slow_interval'] ?? 16));
    $poll_medium_after     = max(0, (int)($_POST['poll_medium_after'] ?? 3));
    $poll_slow_after       = max($poll_medium_after, (int)($_POST['poll_slow_after'] ?? 5));
    $poll_extra_2g         = max(0, (int)($_POST['poll_extra_2g'] ?? 20));
    $poll_extra_3g         = max(0, (int)($_POST['poll_extra_3g'] ?? 10));
    $first_load_limit      = max(1, min(200, (int)($_POST['first_load_limit'] ?? 20)));
    $public_presence_cache_ttl = max(0, (int)($_POST['public_presence_cache_ttl'] ?? 8));
    $admin_presence_cache_ttl  = max(0, (int)($_POST['admin_presence_cache_ttl'] ?? 10));
    $admin_auto_incognito  = !empty($_POST['admin_auto_incognito']) ? 1 : 0;
    $presence_cleanup_interval_minutes = max(1, (int)($_POST['presence_cleanup_interval_minutes'] ?? 2));

    $poll_medium_interval  = max($poll_hot_interval, $poll_medium_interval);
    $poll_slow_interval    = max($poll_medium_interval, $poll_slow_interval);

    update_option('kkchat_sync_enabled',            $sync_enabled);
    update_option('kkchat_sync_concurrency',        $sync_concurrency);
    update_option('kkchat_sync_breaker_threshold',  $sync_breaker_threshold);
    update_option('kkchat_sync_breaker_window',     $sync_breaker_window);
    update_option('kkchat_sync_breaker_cooldown',   $sync_breaker_cooldown);
    update_option('kkchat_dupe_window_seconds',   $dupe_window_seconds);
    update_option('kkchat_dupe_fast_seconds',     $dupe_fast_seconds);
    update_option('kkchat_dupe_max_repeats',      $dupe_max_repeats);
    update_option('kkchat_min_interval_seconds',  $min_interval_seconds);
    update_option('kkchat_dupe_autokick_minutes', $dupe_autokick_minutes);
    update_option('kkchat_dedupe_window',         $dedupe_window);
    update_option('kkchat_report_autoban_threshold',   $report_autoban_threshold);
    update_option('kkchat_report_autoban_window_days', $report_autoban_window_days);
    update_option('kkchat_poll_hidden_threshold', $poll_hidden_threshold);
    update_option('kkchat_poll_hidden_delay',     $poll_hidden_delay);
    update_option('kkchat_poll_hot_interval',     $poll_hot_interval);
    update_option('kkchat_poll_medium_interval',  $poll_medium_interval);
    update_option('kkchat_poll_slow_interval',    $poll_slow_interval);
    update_option('kkchat_poll_medium_after',     $poll_medium_after);
    update_option('kkchat_poll_slow_after',       $poll_slow_after);
    update_option('kkchat_poll_extra_2g',         $poll_extra_2g);
    update_option('kkchat_poll_extra_3g',         $poll_extra_3g);
    update_option('kkchat_first_load_limit',      $first_load_limit);
    update_option('kkchat_public_presence_cache_ttl', $public_presence_cache_ttl);
    update_option('kkchat_admin_presence_cache_ttl',  $admin_presence_cache_ttl);
    update_option('kkchat_admin_auto_incognito',  $admin_auto_incognito);
    update_option('kkchat_presence_cleanup_interval_minutes', $presence_cleanup_interval_minutes);

    if (function_exists('kkchat_presence_cleanup_reschedule')) {
      kkchat_presence_cleanup_reschedule();
    }

    echo '<div class="updated"><p>Inställningar sparade.</p></div>';
  }

  // Värden
  $v_sync_enabled            = (int) get_option('kkchat_sync_enabled', 1);
  $v_sync_concurrency        = max(1, (int) get_option('kkchat_sync_concurrency', 1));
  $v_sync_breaker_threshold  = (int) get_option('kkchat_sync_breaker_threshold', 5);
  $v_sync_breaker_window     = (int) get_option('kkchat_sync_breaker_window', 60);
  $v_sync_breaker_cooldown   = (int) get_option('kkchat_sync_breaker_cooldown', 90);
  $v_dupe_window_seconds   = (int)get_option('kkchat_dupe_window_seconds', 120);
  $v_dupe_fast_seconds     = (int)get_option('kkchat_dupe_fast_seconds', 30);
  $v_dupe_max_repeats      = (int)get_option('kkchat_dupe_max_repeats', 2);
  $v_min_interval_seconds  = (int)get_option('kkchat_min_interval_seconds', 3);
  $v_dupe_autokick_minutes = (int)get_option('kkchat_dupe_autokick_minutes', 1);
  $v_dedupe_window         = (int)get_option('kkchat_dedupe_window', 10);
  $v_report_autoban_threshold   = (int) get_option('kkchat_report_autoban_threshold', 0);
  $v_report_autoban_window_days = (int) get_option('kkchat_report_autoban_window_days', 0);
  $v_poll_hidden_threshold = (int)get_option('kkchat_poll_hidden_threshold', 90);
  $v_poll_hidden_delay     = (int)get_option('kkchat_poll_hidden_delay', 30);
  $v_poll_hot_interval     = (int)get_option('kkchat_poll_hot_interval', 4);
  $v_poll_medium_interval  = (int)get_option('kkchat_poll_medium_interval', 8);
  $v_poll_slow_interval    = (int)get_option('kkchat_poll_slow_interval', 16);
  $v_poll_medium_after     = (int)get_option('kkchat_poll_medium_after', 3);
  $v_poll_slow_after       = (int)get_option('kkchat_poll_slow_after', 5);
  $v_poll_extra_2g         = (int)get_option('kkchat_poll_extra_2g', 20);
  $v_poll_extra_3g         = (int)get_option('kkchat_poll_extra_3g', 10);
  $v_first_load_limit      = max(1, min(200, (int)get_option('kkchat_first_load_limit', 20)));
  $v_public_presence_cache_ttl = (int) get_option('kkchat_public_presence_cache_ttl', 8);
  $v_admin_presence_cache_ttl  = (int) get_option('kkchat_admin_presence_cache_ttl', 10);
  $v_admin_auto_incognito  = (int)get_option('kkchat_admin_auto_incognito', 0);
  $v_presence_cleanup_interval_minutes = (int)get_option('kkchat_presence_cleanup_interval_minutes', 2);

  $sync_metrics_defaults = [
    'total_requests'     => 0,
    'total_success'      => 0,
    'rate_limited'       => 0,
    'disabled_hits'      => 0,
    'breaker_opens'      => 0,
    'breaker_denied'     => 0,
    'concurrency_denied' => 0,
    'last_duration_ms'   => 0.0,
    'avg_duration_ms'    => 0.0,
    'duration_samples'   => 0,
    'last_request_at'    => 0,
  ];
  $sync_metrics = get_option('kkchat_sync_metrics');
  if (!is_array($sync_metrics)) { $sync_metrics = []; }
  $sync_metrics = array_merge($sync_metrics_defaults, $sync_metrics);
  $sync_last_request_at = (int) $sync_metrics['last_request_at'];
  $sync_last_request_at_display = $sync_last_request_at > 0 ? date_i18n('Y-m-d H:i:s', $sync_last_request_at) : '–';
  $sync_last_duration_ms = number_format_i18n((float) $sync_metrics['last_duration_ms'], 1);
  $sync_avg_duration_ms  = number_format_i18n((float) $sync_metrics['avg_duration_ms'], 1);
  ?>
  <div class="wrap">
    <h1>KKchat – Inställningar</h1>
    <h2>Synk-status</h2>
    <table class="widefat striped">
      <tbody>
        <tr><th>Totala förfrågningar</th><td><?php echo esc_html(number_format_i18n((int) $sync_metrics['total_requests'])); ?></td></tr>
        <tr><th>Lyckade svar</th><td><?php echo esc_html(number_format_i18n((int) $sync_metrics['total_success'])); ?></td></tr>
        <tr><th>Rate limit-svar</th><td><?php echo esc_html(number_format_i18n((int) $sync_metrics['rate_limited'])); ?></td></tr>
        <tr><th>Kill switch-träffar</th><td><?php echo esc_html(number_format_i18n((int) $sync_metrics['disabled_hits'])); ?></td></tr>
        <tr><th>Breaker öppnades</th><td><?php echo esc_html(number_format_i18n((int) $sync_metrics['breaker_opens'])); ?></td></tr>
        <tr><th>Breaker nekade</th><td><?php echo esc_html(number_format_i18n((int) $sync_metrics['breaker_denied'])); ?></td></tr>
        <tr><th>Avvisade p.g.a. samtidighet</th><td><?php echo esc_html(number_format_i18n((int) $sync_metrics['concurrency_denied'])); ?></td></tr>
        <tr><th>Senaste svarstid (ms)</th><td><?php echo esc_html($sync_last_duration_ms); ?></td></tr>
        <tr><th>Snitt (ms)</th><td><?php echo esc_html($sync_avg_duration_ms); ?></td></tr>
        <tr><th>Senaste förfrågan</th><td><?php echo esc_html($sync_last_request_at_display); ?></td></tr>
      </tbody>
    </table>
    <p class="description">Mätvärdena nollställs vid databasrensning eller om alternativet tas bort manuellt.</p>
    <form method="post"><?php wp_nonce_field($nonce_key); ?>
      <h2>Synk-skydd</h2>
      <table class="form-table">
        <tr>
          <th><label for="sync_enabled">Aktivera sync-endpoint</label></th>
          <td>
            <label><input type="checkbox" id="sync_enabled" name="sync_enabled" value="1" <?php checked($v_sync_enabled, 1); ?>> Tillåt /sync</label>
            <p class="description">Avmarkera för att omedelbart stänga av synk-routen vid incidenter.</p>
          </td>
        </tr>
        <tr>
          <th><label for="sync_concurrency">Max samtidiga synkar</label></th>
          <td>
            <input id="sync_concurrency" name="sync_concurrency" type="number" class="small-text" min="1" step="1" value="<?php echo (int) $v_sync_concurrency; ?>">
            <p class="description">Tillåt så här många samtidiga byggen av synk-payload. Fler försök får svar med "try again".</p>
          </td>
        </tr>
        <tr>
          <th><label for="sync_breaker_threshold">Breaker-tröskel</label></th>
          <td>
            <input id="sync_breaker_threshold" name="sync_breaker_threshold" type="number" class="small-text" min="1" step="1" value="<?php echo (int) $v_sync_breaker_threshold; ?>">
            <p class="description">Antal misslyckanden inom fönstret innan synken stängs av temporärt.</p>
          </td>
        </tr>
        <tr>
          <th><label for="sync_breaker_window">Breaker-fönster (s)</label></th>
          <td>
            <input id="sync_breaker_window" name="sync_breaker_window" type="number" class="small-text" min="5" step="5" value="<?php echo (int) $v_sync_breaker_window; ?>">
            <p class="description">Tid (sekunder) vi räknar misslyckanden innan räknaren nollas.</p>
          </td>
        </tr>
        <tr>
          <th><label for="sync_breaker_cooldown">Breaker-cooldown (s)</label></th>
          <td>
            <input id="sync_breaker_cooldown" name="sync_breaker_cooldown" type="number" class="small-text" min="10" step="5" value="<?php echo (int) $v_sync_breaker_cooldown; ?>">
            <p class="description">Hur länge (sekunder) synken hålls avstängd när breakern löser ut.</p>
          </td>
        </tr>
      </table>
      <h2>Antispam</h2>
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
      <h2><?php esc_html_e('Rapporter', 'kkchat'); ?></h2>
      <p class="description">
        <?php esc_html_e('Konfigurera auto-IP-ban baserat på anmälningar från unika IP-adresser. Ange 0 för att stänga av ett fält.', 'kkchat'); ?>
      </p>
      <table class="form-table">
        <tr>
          <th><label for="report_autoban_threshold"><?php esc_html_e('Auto-IP-ban – tröskel', 'kkchat'); ?></label></th>
          <td>
            <input id="report_autoban_threshold" name="report_autoban_threshold" type="number" class="small-text" min="0" step="1" value="<?php echo esc_attr((int) $v_report_autoban_threshold); ?>">
            <p class="description"><?php esc_html_e('Antal unika anmälar-IP-adresser som krävs innan IP-adressen spärras automatiskt. Ange 0 för att stänga av.', 'kkchat'); ?></p>
          </td>
        </tr>
        <tr>
          <th><label for="report_autoban_window_days"><?php esc_html_e('Auto-IP-ban – tidsfönster (dagar)', 'kkchat'); ?></label></th>
          <td>
            <input id="report_autoban_window_days" name="report_autoban_window_days" type="number" class="small-text" min="0" step="1" value="<?php echo esc_attr((int) $v_report_autoban_window_days); ?>">
            <p class="description"><?php esc_html_e('Så här många dagar bakåt i tiden rapporter räknas när tröskeln kontrolleras. Ange 0 för att stänga av.', 'kkchat'); ?></p>
          </td>
        </tr>
      </table>
      <h2>Polling</h2>
      <table class="form-table">
        <tr>
          <th><label for="poll_hidden_threshold">Dold flik – tröskel</label></th>
          <td>
            <input id="poll_hidden_threshold" name="poll_hidden_threshold" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_poll_hidden_threshold; ?>"> sekunder
            <p class="description">Efter så här många sekunder dold flik eller ofokuserat fönster räknas som "borta".</p>
          </td>
        </tr>
        <tr>
          <th><label for="poll_hidden_delay">Dold flik – intervall</label></th>
          <td>
            <input id="poll_hidden_delay" name="poll_hidden_delay" type="number" class="small-text" min="0" step="1" value="<?php echo (int)$v_poll_hidden_delay; ?>"> sekunder
            <p class="description">Så lång tid väntar vi mellan pollningar när fliken är dold eller fönstret varit ofokuserat längre än tröskeln.</p>
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
      <h2>Klient</h2>
      <table class="form-table">
        <tr>
          <th><label for="first_load_limit">Första laddningen (max 200)</label></th>
          <td>
            <input id="first_load_limit" name="first_load_limit" type="number" class="small-text" min="1" max="200" step="1" value="<?php echo (int) $v_first_load_limit; ?>">
            <p class="description">Antal meddelanden som laddas när chatten öppnas första gången.</p>
          </td>
        </tr>
      </table>
      <h2>Närvaro-cache</h2>
      <table class="form-table">
        <tr>
          <th><label for="public_presence_cache_ttl">Publik närvaro-cache (s)</label></th>
          <td>
            <input id="public_presence_cache_ttl" name="public_presence_cache_ttl" type="number" class="small-text" min="0" step="1" value="<?php echo (int) $v_public_presence_cache_ttl; ?>">
            <p class="description">Hur länge (sekunder) närvarolistan för publika vyer cachelagras. 0 = av.</p>
          </td>
        </tr>
        <tr>
          <th><label for="admin_presence_cache_ttl">Admin närvaro-cache (s)</label></th>
          <td>
            <input id="admin_presence_cache_ttl" name="admin_presence_cache_ttl" type="number" class="small-text" min="0" step="1" value="<?php echo (int) $v_admin_presence_cache_ttl; ?>">
            <p class="description">Hur länge (sekunder) admin-närvaron cachelagras. 0 = av.</p>
          </td>
        </tr>
      </table>
      <h2>Närvarorensning</h2>
      <table class="form-table">
        <tr>
          <th><label for="presence_cleanup_interval_minutes">Rensningsintervall</label></th>
          <td>
            <input id="presence_cleanup_interval_minutes" name="presence_cleanup_interval_minutes" type="number" class="small-text" min="1" step="1" value="<?php echo (int) $v_presence_cleanup_interval_minutes; ?>"> minuter
            <p class="description">Hur ofta vi rensar gamla närvarorader och nollställer watch-flag (körs via cron).</p>
          </td>
        </tr>
      </table>
      <h2>Administratörer</h2>
      <table class="form-table">
        <tr>
          <th><label for="admin_auto_incognito">Automatiskt incognito-läge</label></th>
          <td>
            <label><input id="admin_auto_incognito" name="admin_auto_incognito" type="checkbox" value="1" <?php checked($v_admin_auto_incognito, 1); ?>> Logga in administratörer som dolda</label>
            <p class="description">Aktivera för att låta administratörer starta sessioner som gömda i användarlistan. De kan fortfarande växla synlighet manuellt i appen.</p>
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
