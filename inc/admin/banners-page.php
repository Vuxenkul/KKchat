<?php
if (!defined('ABSPATH')) exit;

/**
 * Banderoller
 */
if (!function_exists('kkchat_admin_parse_time_to_minutes')) {
  function kkchat_admin_parse_time_to_minutes(string $value): ?int {
    $value = trim($value);
    if ($value === '') { return null; }
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) { return null; }
    [$h, $m] = array_map('intval', explode(':', $value));
    return ($h * 60) + $m;
  }
}

if (!function_exists('kkchat_admin_parse_datetime_local')) {
  function kkchat_admin_parse_datetime_local(string $value): ?int {
    $value = trim($value);
    if ($value === '') { return null; }
    try {
      $tz = kkchat_banner_timezone();
      $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $tz);
      if (!$dt) { return null; }
      return $dt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    } catch (Exception $e) {
      return null;
    }
  }
}

if (!function_exists('kkchat_admin_format_minutes')) {
  function kkchat_admin_format_minutes(int $minutes): string {
    $minutes = max(0, $minutes);
    $h = (int) floor($minutes / 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', $h, $m);
  }
}

if (!function_exists('kkchat_admin_weekday_labels')) {
  function kkchat_admin_weekday_labels(): array {
    return [
      0 => 'Sön',
      1 => 'Mån',
      2 => 'Tis',
      3 => 'Ons',
      4 => 'Tors',
      5 => 'Fre',
      6 => 'Lör',
    ];
  }
}

if (!function_exists('kkchat_admin_format_datetime')) {
  function kkchat_admin_format_datetime(int $timestamp, string $format = 'Y-m-d H:i'): string {
    $tz = kkchat_banner_timezone();
    if (function_exists('wp_date')) {
      return wp_date($format, $timestamp, $tz);
    }
    return date_i18n($format, $timestamp);
  }
}

if (!function_exists('kkchat_admin_describe_banner_schedule')) {
  function kkchat_admin_describe_banner_schedule($row): string {
    $intervalSec  = isset($row->interval_sec) ? (int) $row->interval_sec : (int) ($row['interval_sec'] ?? 0);
    $intervalMin  = $intervalSec > 0 ? max(1, (int) round($intervalSec / 60)) : 0;
    $intervalText = $intervalMin > 0
      ? sprintf('Var %d minut%s', $intervalMin, $intervalMin === 1 ? '' : 'er')
      : sprintf('%d sekunder', max(1, $intervalSec));

    $mode = strtolower((string) (isset($row->schedule_mode) ? $row->schedule_mode : ($row['schedule_mode'] ?? 'rolling')));

    if ($mode === 'weekly') {
      $mask  = (int) (isset($row->weekdays_mask) ? $row->weekdays_mask : ($row['weekdays_mask'] ?? 0));
      $start = isset($row->daily_start_min) ? (int) $row->daily_start_min : (int) ($row['daily_start_min'] ?? 0);
      $end   = isset($row->daily_end_min) ? (int) $row->daily_end_min : (int) ($row['daily_end_min'] ?? 0);
      $labels = kkchat_admin_weekday_labels();
      $days = [];
      foreach ($labels as $idx => $label) {
        if ($mask & (1 << $idx)) {
          $days[] = $label;
        }
      }
      $dayText = $days ? implode(', ', $days) : 'Inga dagar valda';
      $range   = sprintf('%s–%s', kkchat_admin_format_minutes($start), kkchat_admin_format_minutes($end));
      return sprintf('Veckodagar: %s · %s · %s', $dayText, $range, $intervalText);
    }

    if ($mode === 'window') {
      $startTs = isset($row->window_start) ? (int) $row->window_start : (int) ($row['window_start'] ?? 0);
      $endTs   = isset($row->window_end) ? (int) $row->window_end : (int) ($row['window_end'] ?? 0);
      $startStr = $startTs ? kkchat_admin_format_datetime($startTs) : null;
      $endStr   = $endTs ? kkchat_admin_format_datetime($endTs) : null;
      if ($startStr && $endStr) {
        $range = sprintf('%s – %s', $startStr, $endStr);
      } elseif ($startStr) {
        $range = sprintf('Fr.o.m %s', $startStr);
      } elseif ($endStr) {
        $range = sprintf('Till %s', $endStr);
      } else {
        $range = 'Ingen tidsram angiven';
      }
      return sprintf('Datumintervall: %s · %s', $range, $intervalText);
    }

    return sprintf('Återkommande · %s', $intervalText);
  }
}

function kkchat_admin_banners_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce_key = 'kkchat_banners';
  $rooms = $wpdb->get_results("SELECT slug,title FROM {$t['rooms']} ORDER BY sort ASC, title ASC", ARRAY_A);

  if (isset($_POST['kk_add_banner'])) {
    check_admin_referer($nonce_key);
    $content = sanitize_textarea_field($_POST['content'] ?? '');
    $intervalMin = max(1, (int)($_POST['every_min'] ?? 10));
    $intervalSec = $intervalMin * 60;
    $sel = array_map('kkchat_sanitize_room_slug', (array)($_POST['rooms'] ?? []));
    $sel = array_values(array_unique(array_filter($sel)));
    $mode = strtolower((string)($_POST['schedule_mode'] ?? 'rolling'));
    if (!in_array($mode, ['rolling','weekly','window'], true)) {
      $mode = 'rolling';
    }

    $errors = [];
    if (!$content) {
      $errors[] = 'Innehåll krävs.';
    }
    if (!$sel) {
      $errors[] = 'Minst ett rum måste väljas.';
    }

    $weekdaysMask = 0;
    $dailyStart   = 0;
    $dailyEnd     = 0;
    $windowStart  = 0;
    $windowEnd    = 0;

    if ($mode === 'weekly') {
      $selectedDays = array_map('intval', (array)($_POST['weekdays'] ?? []));
      foreach ($selectedDays as $d) {
        if ($d >= 0 && $d <= 6) {
          $weekdaysMask |= (1 << $d);
        }
      }
      if ($weekdaysMask === 0) {
        $errors[] = 'Välj minst en veckodag.';
      }
      $startStr = sanitize_text_field($_POST['weekly_start'] ?? '');
      $endStr   = sanitize_text_field($_POST['weekly_end'] ?? '');
      $startMin = kkchat_admin_parse_time_to_minutes($startStr);
      $endMin   = kkchat_admin_parse_time_to_minutes($endStr);
      if ($startMin === null || $endMin === null) {
        $errors[] = 'Ogiltigt tidsintervall.';
      } elseif ($endMin <= $startMin) {
        $errors[] = 'Sluttiden måste vara senare än starttiden.';
      } else {
        $dailyStart = $startMin;
        $dailyEnd   = $endMin;
      }
    } elseif ($mode === 'window') {
      $startStr = sanitize_text_field($_POST['window_start'] ?? '');
      $endStr   = sanitize_text_field($_POST['window_end'] ?? '');
      $startTs  = kkchat_admin_parse_datetime_local($startStr);
      $endTs    = kkchat_admin_parse_datetime_local($endStr);
      if (!$startTs || !$endTs) {
        $errors[] = 'Start- och sluttid måste anges.';
      } elseif ($endTs <= $startTs) {
        $errors[] = 'Slutdatumet måste vara senare än startdatumet.';
      } else {
        $windowStart = $startTs;
        $windowEnd   = $endTs;
      }
    }

    $calcRow = [
      'interval_sec'   => $intervalSec,
      'schedule_mode'  => $mode,
      'weekdays_mask'  => $weekdaysMask,
      'daily_start_min'=> $dailyStart,
      'daily_end_min'  => $dailyEnd,
      'window_start'   => $windowStart,
      'window_end'     => $windowEnd,
    ];

    $nextRun = null;
    if (!$errors) {
      $afterTs = time();
      if ($mode !== 'rolling') {
        $afterTs -= 1;
      }
      $nextRun = kkchat_banner_next_run($calcRow, $afterTs);
      if ($nextRun === null) {
        $errors[] = 'Schemat saknar framtida körningar med vald konfiguration.';
      }
    }

    if ($errors) {
      foreach ($errors as $err) {
        echo '<div class="error"><p>' . esc_html($err) . '</p></div>';
      }
    } else {
      $data = [
        'content'        => $content,
        'rooms_csv'      => implode(',', $sel),
        'interval_sec'   => $intervalSec,
        'schedule_mode'  => $mode,
        'weekdays_mask'  => $weekdaysMask,
        'daily_start_min'=> $dailyStart,
        'daily_end_min'  => $dailyEnd,
        'window_start'   => $windowStart,
        'window_end'     => $windowEnd,
        'next_run'       => $nextRun,
        'active'         => 1,
      ];

      $formats = ['%s','%s','%d','%s','%d','%d','%d','%d','%d','%d','%d'];

      $ok = $wpdb->insert($t['banners'], $data, $formats);
      if ($ok === false) {
        $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
        echo '<div class="error"><p>Kunde inte spara banderoll: '.$err.'</p></div>';
      } else {
        echo '<div class="updated"><p>Banderoll schemalagd.</p></div>';
      }
    }
  }

  if (isset($_GET['kk_toggle']) && check_admin_referer($nonce_key)) {
    $id  = (int)$_GET['kk_toggle'];
    $cur = (int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t['banners']} WHERE id=%d", $id));
    if ($cur !== null) {
      $wpdb->update($t['banners'], ['active'=>$cur?0:1], ['id'=>$id], ['%d'], ['%d']);
      echo '<div class="updated"><p>Status uppdaterad.</p></div>';
    }
  }
  if (isset($_GET['kk_delete']) && check_admin_referer($nonce_key)) {
    $id = (int)$_GET['kk_delete'];
    $wpdb->delete($t['banners'], ['id'=>$id], ['%d']);
    echo '<div class="updated"><p>Banderoll raderad.</p></div>';
  }
  if (isset($_GET['kk_run_now']) && check_admin_referer($nonce_key)) {
    $id = (int)$_GET['kk_run_now'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $id), ARRAY_A);
    if ($row && (int)$row['active'] === 1) {
      $now = time();
      $roomsSel = array_filter(array_map('kkchat_sanitize_room_slug', explode(',', (string)$row['rooms_csv'])));
      foreach ($roomsSel as $slug) {
        $wpdb->insert($t['messages'], [
          'created_at'     => $now,
          'sender_id'      => 0,
          'sender_name'    => 'System',
          'recipient_id'   => null,
          'recipient_name' => null,
          'recipient_ip'   => null,
          'room'           => $slug,
          'kind'           => 'banner',
          'content'        => kkchat_html_esc($row['content']),
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%s']);
      }
      $calcRow = [
        'interval_sec'    => (int) ($row['interval_sec'] ?? 0),
        'schedule_mode'   => $row['schedule_mode'] ?? 'rolling',
        'weekdays_mask'   => (int) ($row['weekdays_mask'] ?? 0),
        'daily_start_min' => (int) ($row['daily_start_min'] ?? 0),
        'daily_end_min'   => (int) ($row['daily_end_min'] ?? 0),
        'window_start'    => (int) ($row['window_start'] ?? 0),
        'window_end'      => (int) ($row['window_end'] ?? 0),
      ];
      $nextRun = kkchat_banner_next_run($calcRow, $now);
      if ($nextRun !== null) {
        $wpdb->update($t['banners'], ['last_run'=>$now, 'next_run'=>$nextRun], ['id'=>$id], ['%d','%d'], ['%d']);
      } else {
        $wpdb->update($t['banners'], ['last_run'=>$now], ['id'=>$id], ['%d'], ['%d']);
        $wpdb->query($wpdb->prepare("UPDATE {$t['banners']} SET next_run = NULL WHERE id = %d", $id));
      }
      echo '<div class="updated"><p>Banderoll postad.</p></div>';
    }
  }

  $list = $wpdb->get_results("SELECT * FROM {$t['banners']} ORDER BY id DESC");
  $banners_base = menu_page_url('kkchat_banners', false);
  ?>
  <div class="wrap">
    <h1>KKchat – Banderoller</h1>
    <h2>Ny schemalagd banderoll</h2>
    <form method="post"><?php wp_nonce_field($nonce_key); ?>
      <table class="form-table">
        <tr>
          <th><label for="kkb_content">Meddelande</label></th>
          <td>
            <textarea id="kkb_content" name="content" rows="3" class="large-text" required></textarea>
            <p class="description">Ren text. Visas centrerat som banderoll i valda rum.</p>
          </td>
        </tr>
        <tr>
          <th>Rum</th>
          <td>
            <?php foreach ($rooms as $r): ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="rooms[]" value="<?php echo esc_attr($r['slug']); ?>">
                <?php echo esc_html($r['title']); ?> <code><?php echo esc_html($r['slug']); ?></code>
              </label>
            <?php endforeach; ?>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_every">Intervall</label></th>
          <td>
            <input id="kkb_every" name="every_min" type="number" min="1" step="1" value="10" class="small-text"> minuter
            <p class="description">Hur ofta banderollen ska postas när schemat är aktivt.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_mode">Schematyp</label></th>
          <td>
            <select id="kkb_mode" name="schedule_mode">
              <option value="rolling">Återkommande (utan tidsfönster)</option>
              <option value="weekly">Veckodagar och tider</option>
              <option value="window">Datumintervall</option>
            </select>
            <p class="description">Välj om banderollen ska ha ett tidsfönster eller gälla löpande.</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="weekly">
          <th>Veckodagar</th>
          <td>
            <?php foreach (kkchat_admin_weekday_labels() as $idx => $label): ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="weekdays[]" value="<?php echo (int)$idx; ?>"> <?php echo esc_html($label); ?>
              </label>
            <?php endforeach; ?>
            <p class="description">Banner postas endast dessa dagar.</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="weekly">
          <th>Tidsintervall</th>
          <td>
            <input type="time" name="weekly_start" value="08:00"> – <input type="time" name="weekly_end" value="17:00">
            <p class="description">Dagligt tidsfönster (lokal tid).</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="window">
          <th>Datumintervall</th>
          <td>
            <label>Start <input type="datetime-local" name="window_start"></label>
            <label style="margin-left:12px">Slut <input type="datetime-local" name="window_end"></label>
            <p class="description">Banner postas endast mellan dessa datum/tider (lokal tid).</p>
          </td>
        </tr>
      </table>
      <p><button class="button button-primary" name="kk_add_banner" value="1">Spara schema</button></p>
    </form>

    <h2>Scheman</h2>
    <table class="widefat striped">
      <thead><tr><th>ID</th><th>Innehåll</th><th>Rum</th><th>Schema</th><th>Nästa körning</th><th>Aktiv</th><th>Åtgärder</th></tr></thead>
      <tbody>
        <?php if ($list): foreach ($list as $b): ?>
          <tr>
            <td><?php echo (int)$b->id; ?></td>
            <td><?php echo esc_html(mb_strimwidth((string)$b->content, 0, 100, '…')); ?></td>
            <td><?php echo esc_html((string)$b->rooms_csv); ?></td>
            <td><?php echo esc_html(kkchat_admin_describe_banner_schedule($b)); ?></td>
            <td>
              <?php
                $nextTs = isset($b->next_run) ? (int) $b->next_run : 0;
                if ($nextTs > 0) {
                  echo esc_html(kkchat_admin_format_datetime($nextTs, 'Y-m-d H:i:s'));
                } else {
                  echo '—';
                }
              ?>
            </td>
            <td><?php echo $b->active ? 'Ja' : 'Nej'; ?></td>
            <td>
              <?php
                $run_url = wp_nonce_url(add_query_arg('kk_run_now', $b->id, $banners_base), $nonce_key);
                $tog_url = wp_nonce_url(add_query_arg('kk_toggle',  $b->id, $banners_base), $nonce_key);
                $del_url = wp_nonce_url(add_query_arg('kk_delete',  $b->id, $banners_base), $nonce_key);
              ?>
              <a class="button" href="<?php echo esc_url($run_url); ?>">Kör nu</a>
              <a class="button" href="<?php echo esc_url($tog_url); ?>"><?php echo $b->active ? 'Inaktivera' : 'Aktivera'; ?></a>
              <a class="button button-danger" href="<?php echo esc_url($del_url); ?>" onclick="return confirm('Ta bort detta schema?');">Ta bort</a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7">Inga banderollscheman ännu.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      const mode = document.getElementById('kkb_mode');
      const rows = document.querySelectorAll('.kkb-schedule');
      function syncScheduleRows(){
        const value = mode ? mode.value : '';
        rows.forEach(function(row){
          const showFor = (row.getAttribute('data-mode') || '').split(',');
          row.style.display = showFor.includes(value) ? '' : 'none';
        });
      }
      if (mode) {
        mode.addEventListener('change', syncScheduleRows);
        syncScheduleRows();
      }
    });
  </script>
  <?php
}

/**
 * Moderering
 */
