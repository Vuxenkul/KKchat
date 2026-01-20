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
    $report_reason_keys = isset($_POST['report_reason_key']) && is_array($_POST['report_reason_key'])
      ? array_map('sanitize_key', $_POST['report_reason_key'])
      : [];
    $report_reason_labels = isset($_POST['report_reason_label']) && is_array($_POST['report_reason_label'])
      ? array_map('sanitize_text_field', $_POST['report_reason_label'])
      : [];
    $report_reason_thresholds = isset($_POST['report_reason_threshold']) && is_array($_POST['report_reason_threshold'])
      ? array_map('intval', $_POST['report_reason_threshold'])
      : [];
    $report_reason_windows = isset($_POST['report_reason_window']) && is_array($_POST['report_reason_window'])
      ? array_map('intval', $_POST['report_reason_window'])
      : [];
    $poll_hidden_threshold = max(0, (int)($_POST['poll_hidden_threshold'] ?? 90));
    $poll_hidden_delay     = max(0, (int)($_POST['poll_hidden_delay'] ?? 30));
    $poll_hot_interval     = max(1, (int)($_POST['poll_hot_interval'] ?? 4));
    $poll_medium_interval  = max(1, (int)($_POST['poll_medium_interval'] ?? 8));
    $poll_slow_interval    = max(1, (int)($_POST['poll_slow_interval'] ?? 16));
    $poll_medium_after     = max(0, (int)($_POST['poll_medium_after'] ?? 3));
    $poll_slow_after       = max($poll_medium_after, (int)($_POST['poll_slow_after'] ?? 5));
    $poll_extra_2g         = max(0, (int)($_POST['poll_extra_2g'] ?? 20));
    $poll_extra_3g         = max(0, (int)($_POST['poll_extra_3g'] ?? 10));
    $admin_auto_incognito  = !empty($_POST['admin_auto_incognito']) ? 1 : 0;
    $first_load_limit      = max(1, min(200, (int)($_POST['first_load_limit'] ?? 20)));
    $first_load_exclude_banners = !empty($_POST['first_load_exclude_banners']) ? 1 : 0;

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
    $report_reason_rules = [];
    $max_rules = max(
      count($report_reason_keys),
      count($report_reason_labels),
      count($report_reason_thresholds),
      count($report_reason_windows)
    );
    for ($i = 0; $i < $max_rules; $i++) {
      $key = sanitize_key($report_reason_keys[$i] ?? '');
      $label = trim($report_reason_labels[$i] ?? '');
      if ($key === '' || $label === '') {
        continue;
      }
      $threshold = max(0, (int) ($report_reason_thresholds[$i] ?? 0));
      $window_days = max(0, (int) ($report_reason_windows[$i] ?? 0));
      $report_reason_rules[] = [
        'key' => $key,
        'label' => $label,
        'threshold' => $threshold,
        'window_days' => $window_days,
      ];
    }
    update_option('kkchat_report_reason_rules', $report_reason_rules);
    update_option('kkchat_poll_hidden_threshold', $poll_hidden_threshold);
    update_option('kkchat_poll_hidden_delay',     $poll_hidden_delay);
    update_option('kkchat_poll_hot_interval',     $poll_hot_interval);
    update_option('kkchat_poll_medium_interval',  $poll_medium_interval);
    update_option('kkchat_poll_slow_interval',    $poll_slow_interval);
    update_option('kkchat_poll_medium_after',     $poll_medium_after);
    update_option('kkchat_poll_slow_after',       $poll_slow_after);
    update_option('kkchat_poll_extra_2g',         $poll_extra_2g);
    update_option('kkchat_poll_extra_3g',         $poll_extra_3g);
    update_option('kkchat_admin_auto_incognito',  $admin_auto_incognito);
    update_option('kkchat_first_load_limit',      $first_load_limit);
    update_option('kkchat_first_load_exclude_banners', $first_load_exclude_banners);

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
  $v_report_reason_rules = get_option('kkchat_report_reason_rules', []);
  if (!is_array($v_report_reason_rules)) {
    $v_report_reason_rules = [];
  }
  $report_reason_rows = $v_report_reason_rules;
  if (!$report_reason_rows) {
    $report_reason_rows = [
      ['key' => '', 'label' => '', 'threshold' => 0, 'window_days' => 0],
    ];
  }
  $v_poll_hidden_threshold = (int)get_option('kkchat_poll_hidden_threshold', 90);
  $v_poll_hidden_delay     = (int)get_option('kkchat_poll_hidden_delay', 30);
  $v_poll_hot_interval     = (int)get_option('kkchat_poll_hot_interval', 4);
  $v_poll_medium_interval  = (int)get_option('kkchat_poll_medium_interval', 8);
  $v_poll_slow_interval    = (int)get_option('kkchat_poll_slow_interval', 16);
  $v_poll_medium_after     = (int)get_option('kkchat_poll_medium_after', 3);
  $v_poll_slow_after       = (int)get_option('kkchat_poll_slow_after', 5);
  $v_poll_extra_2g         = (int)get_option('kkchat_poll_extra_2g', 20);
  $v_poll_extra_3g         = (int)get_option('kkchat_poll_extra_3g', 10);
  $v_admin_auto_incognito  = (int)get_option('kkchat_admin_auto_incognito', 0);
  $v_first_load_limit      = max(1, min(200, (int) get_option('kkchat_first_load_limit', 20)));
  $v_first_load_exclude_banners = (int) get_option('kkchat_first_load_exclude_banners', 0);

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
        <tr>
          <th><label for="report_reason_rules"><?php esc_html_e('Förvalda rapportorsaker', 'kkchat'); ?></label></th>
          <td>
            <table class="widefat striped" id="kkchat-report-reasons-table">
              <thead>
                <tr>
                  <th><?php esc_html_e('Nyckel', 'kkchat'); ?></th>
                  <th><?php esc_html_e('Label', 'kkchat'); ?></th>
                  <th><?php esc_html_e('Tröskel', 'kkchat'); ?></th>
                  <th><?php esc_html_e('Dagar', 'kkchat'); ?></th>
                  <th><?php esc_html_e('Åtgärd', 'kkchat'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report_reason_rows as $index => $rule): ?>
                  <tr>
                    <td><input type="text" name="report_reason_key[]" class="regular-text" value="<?php echo esc_attr($rule['key'] ?? ''); ?>" placeholder="t.ex. under18"></td>
                    <td><input type="text" name="report_reason_label[]" class="regular-text" value="<?php echo esc_attr($rule['label'] ?? ''); ?>" placeholder="t.ex. Under 18"></td>
                    <td><input type="number" name="report_reason_threshold[]" class="small-text" min="0" step="1" value="<?php echo esc_attr((int) ($rule['threshold'] ?? 0)); ?>"></td>
                    <td><input type="number" name="report_reason_window[]" class="small-text" min="0" step="1" value="<?php echo esc_attr((int) ($rule['window_days'] ?? 0)); ?>"></td>
                    <td><button type="button" class="button kkchat-remove-reason"><?php esc_html_e('Ta bort', 'kkchat'); ?></button></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="description">
              <?php esc_html_e('Nyckel används internt (endast bokstäver/siffror/underscore). Tröskel och dagar styr auto-ban för just den orsaken. Ange 0 för att stänga av auto-ban för raden.', 'kkchat'); ?>
            </p>
            <p>
              <button type="button" class="button" id="kkchat-add-report-reason"><?php esc_html_e('Lägg till orsak', 'kkchat'); ?></button>
            </p>
          </td>
        </tr>
      </table>
      <script>
        (function() {
          const table = document.getElementById('kkchat-report-reasons-table');
          const addBtn = document.getElementById('kkchat-add-report-reason');
          if (!table || !addBtn) return;

          function addRow() {
            const tbody = table.querySelector('tbody');
            if (!tbody) return;
            const row = document.createElement('tr');
            row.innerHTML = `
              <td><input type="text" name="report_reason_key[]" class="regular-text" placeholder="t.ex. under18"></td>
              <td><input type="text" name="report_reason_label[]" class="regular-text" placeholder="t.ex. Under 18"></td>
              <td><input type="number" name="report_reason_threshold[]" class="small-text" min="0" step="1" value="0"></td>
              <td><input type="number" name="report_reason_window[]" class="small-text" min="0" step="1" value="0"></td>
              <td><button type="button" class="button kkchat-remove-reason"><?php echo esc_js(__('Ta bort', 'kkchat')); ?></button></td>
            `;
            tbody.appendChild(row);
          }

          addBtn.addEventListener('click', addRow);
          table.addEventListener('click', (event) => {
            const btn = event.target.closest('.kkchat-remove-reason');
            if (!btn) return;
            const row = btn.closest('tr');
            if (row) row.remove();
          });
        })();
      </script>
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
      <h2>Första laddningen</h2>
      <table class="form-table">
        <tr>
          <th><label for="first_load_limit">Första laddningen (max 200)</label></th>
          <td>
            <input id="first_load_limit" name="first_load_limit" type="number" class="small-text" min="1" max="200" step="1" value="<?php echo (int) $v_first_load_limit; ?>">
            <p class="description">Max antal meddelanden som hämtas vid första laddningen av ett rum eller DM.</p>
          </td>
        </tr>
        <tr>
          <th><label for="first_load_exclude_banners">Hoppa över banderoller vid första laddningen</label></th>
          <td>
            <label><input id="first_load_exclude_banners" name="first_load_exclude_banners" type="checkbox" value="1" <?php checked($v_first_load_exclude_banners, 1); ?>> Filtrera bort banderoller innan maxgränsen appliceras</label>
            <p class="description">Banderoller filtreras endast bort vid första laddningen, inte vid efterföljande uppdateringar.</p>
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
