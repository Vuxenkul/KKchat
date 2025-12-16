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

if (!function_exists('kkchat_admin_format_datetime_local_value')) {
  function kkchat_admin_format_datetime_local_value(?int $timestamp): string {
    if (!$timestamp) { return ''; }
    try {
      $tz = kkchat_banner_timezone();
      return (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format('Y-m-d\TH:i');
    } catch (Exception $e) {
      return '';
    }
  }
}

if (!function_exists('kkchat_admin_banner_image_url')) {
  function kkchat_admin_banner_image_url(?int $attachmentId, string $fallback = ''): string {
    if ($attachmentId && $attachmentId > 0) {
      $url = wp_get_attachment_url($attachmentId);
      if ($url) { return $url; }
    }
    return $fallback;
  }
}

if (!function_exists('kkchat_admin_banner_delete_image_if_unused')) {
  function kkchat_admin_banner_delete_image_if_unused(int $attachmentId, string $table, int $skipId = 0): void {
    if ($attachmentId <= 0) { return; }
    global $wpdb;
    $exists = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$table} WHERE image_id = %d AND id <> %d",
      $attachmentId,
      $skipId
    ));
    if ($exists === 0) {
      wp_delete_attachment($attachmentId, true);
    }
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

  $editingId = isset($_GET['kk_edit']) ? (int) $_GET['kk_edit'] : 0;
  $editingRow = $editingId ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $editingId), ARRAY_A) : null;
  if (!$editingRow) { $editingId = 0; }

  $defaultValues = [
    'content'      => '',
    'rooms'        => [],
    'every_min'    => 10,
    'schedule_mode'=> 'rolling',
    'weekdays'     => [],
    'weekly_start' => '08:00',
    'weekly_end'   => '17:00',
    'window_start' => '',
    'window_end'   => '',
    'bg_color'     => '',
    'image_id'     => null,
    'image_url'    => '',
  ];

  $fromRow = function(array $row) {
    $mode = $row['schedule_mode'] ?? 'rolling';
    $weekdays = [];
    $mask = isset($row['weekdays_mask']) ? (int) $row['weekdays_mask'] : 0;
    for ($i = 0; $i <= 6; $i++) {
      if ($mask & (1 << $i)) { $weekdays[] = $i; }
    }

    $intervalMin = isset($row['interval_sec']) ? (int) $row['interval_sec'] : 600;
    $intervalMin = $intervalMin > 0 ? max(1, (int) round($intervalMin / 60)) : 10;

    $bgColor = kkchat_banner_sanitize_color($row['bg_color'] ?? '');
    $imageId = isset($row['image_id']) ? (int) $row['image_id'] : null;

    return [
      'content'      => (string) ($row['content'] ?? ''),
      'rooms'        => array_values(array_filter(array_map('kkchat_sanitize_room_slug', explode(',', (string) ($row['rooms_csv'] ?? ''))))),
      'every_min'    => $intervalMin,
      'schedule_mode'=> $mode,
      'weekdays'     => $weekdays,
      'weekly_start' => $mode === 'weekly'
        ? kkchat_admin_format_minutes((int) ($row['daily_start_min'] ?? 0))
        : '08:00',
      'weekly_end'   => $mode === 'weekly'
        ? kkchat_admin_format_minutes((int) ($row['daily_end_min'] ?? 0))
        : '17:00',
      'window_start' => $mode === 'window' ? kkchat_admin_format_datetime_local_value((int) ($row['window_start'] ?? 0)) : '',
      'window_end'   => $mode === 'window' ? kkchat_admin_format_datetime_local_value((int) ($row['window_end'] ?? 0)) : '',
      'bg_color'     => $bgColor ?: '',
      'image_id'     => $imageId,
      'image_url'    => kkchat_admin_banner_image_url($imageId, (string) ($row['image_url'] ?? '')),
    ];
  };

  $form_values = $defaultValues;
  if ($editingRow) {
    $form_values = array_merge($form_values, $fromRow($editingRow));
  }

  if (isset($_POST['kk_save_banner'])) {
    check_admin_referer($nonce_key);

    $bannerId = (int) ($_POST['banner_id'] ?? 0);
    $isEdit = $bannerId > 0;
    $existing = $isEdit ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $bannerId), ARRAY_A) : null;
    if ($isEdit && !$existing) {
      echo '<div class="error"><p>Banderollen kunde inte hittas.</p></div>';
      $editingId = 0;
    } else {
      $form_values = array_merge($form_values, [
        'content'       => (string) ($_POST['content'] ?? ''),
        'rooms'         => array_values(array_unique(array_filter(array_map('kkchat_sanitize_room_slug', (array) ($_POST['rooms'] ?? []))))),
        'every_min'     => max(1, (int) ($_POST['every_min'] ?? 10)),
        'schedule_mode' => strtolower((string) ($_POST['schedule_mode'] ?? 'rolling')),
        'weekdays'      => array_map('intval', (array) ($_POST['weekdays'] ?? [])),
        'weekly_start'  => sanitize_text_field($_POST['weekly_start'] ?? '08:00'),
        'weekly_end'    => sanitize_text_field($_POST['weekly_end'] ?? '17:00'),
        'window_start'  => sanitize_text_field($_POST['window_start'] ?? ''),
        'window_end'    => sanitize_text_field($_POST['window_end'] ?? ''),
        'bg_color'      => trim((string) ($_POST['bg_color'] ?? '')),
      ]);

      if ($isEdit && $existing) {
        $form_values['image_id'] = isset($existing['image_id']) ? (int) $existing['image_id'] : null;
        $form_values['image_url'] = kkchat_admin_banner_image_url($form_values['image_id'], (string) ($existing['image_url'] ?? ''));
      }

      $mode = in_array($form_values['schedule_mode'], ['rolling','weekly','window'], true) ? $form_values['schedule_mode'] : 'rolling';
      $errors = [];

      $contentPrepared = kkchat_banner_prepare_content($form_values['content']);
      if (trim($contentPrepared['plain']) === '') {
        $errors[] = 'Innehåll krävs.';
      }

      if (!$form_values['rooms']) {
        $errors[] = 'Minst ett rum måste väljas.';
      }

      $color = kkchat_banner_sanitize_color($form_values['bg_color']);
      if ($form_values['bg_color'] !== '' && !$color) {
        $errors[] = 'Ogiltig färgkod. Använd t.ex. #e35754.';
      }
      $form_values['bg_color'] = $color ?: '';

      $intervalSec = max(60, $form_values['every_min'] * 60);
      $weekdaysMask = 0; $dailyStart = 0; $dailyEnd = 0; $windowStart = 0; $windowEnd = 0;
      if ($mode === 'weekly') {
        foreach ($form_values['weekdays'] as $d) {
          $d = (int) $d;
          if ($d >= 0 && $d <= 6) { $weekdaysMask |= (1 << $d); }
        }
        if ($weekdaysMask === 0) {
          $errors[] = 'Välj minst en veckodag.';
        }
        $startMin = kkchat_admin_parse_time_to_minutes($form_values['weekly_start']);
        $endMin   = kkchat_admin_parse_time_to_minutes($form_values['weekly_end']);
        if ($startMin === null || $endMin === null) {
          $errors[] = 'Ogiltigt tidsintervall.';
        } elseif ($endMin <= $startMin) {
          $errors[] = 'Sluttiden måste vara senare än starttiden.';
        } else {
          $dailyStart = $startMin;
          $dailyEnd   = $endMin;
        }
      } elseif ($mode === 'window') {
        $startTs = kkchat_admin_parse_datetime_local($form_values['window_start']);
        $endTs   = kkchat_admin_parse_datetime_local($form_values['window_end']);
        if (!$startTs || !$endTs) {
          $errors[] = 'Start- och sluttid måste anges.';
        } elseif ($endTs <= $startTs) {
          $errors[] = 'Slutdatumet måste vara senare än startdatumet.';
        } else {
          $windowStart = $startTs;
          $windowEnd   = $endTs;
        }
      }

      $imageId = $form_values['image_id'];
      $imageUrl = $form_values['image_url'];
      $oldImageId = $imageId;
      $removeImage = !empty($_POST['remove_image']);
      if (!empty($_FILES['banner_image']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $upload = media_handle_upload('banner_image', 0);
        if (is_wp_error($upload)) {
          $errors[] = 'Kunde inte ladda upp bild: ' . $upload->get_error_message();
        } else {
          $imageId = (int) $upload;
          $imageUrl = kkchat_admin_banner_image_url($imageId, '');
        }
      } elseif ($removeImage) {
        $imageId = null; $imageUrl = '';
      }
      $form_values['image_id'] = $imageId;
      $form_values['image_url'] = $imageUrl;

      $calcRow = [
        'interval_sec'    => $intervalSec,
        'schedule_mode'   => $mode,
        'weekdays_mask'   => $weekdaysMask,
        'daily_start_min' => $dailyStart,
        'daily_end_min'   => $dailyEnd,
        'window_start'    => $windowStart,
        'window_end'      => $windowEnd,
      ];

      $nextRun = null;
      if (!$errors) {
        $afterTs = time();
        if ($mode !== 'rolling') { $afterTs -= 1; }
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
          'content'         => $contentPrepared['text'],
          'content_html'    => $contentPrepared['html'],
          'rooms_csv'       => implode(',', $form_values['rooms']),
          'interval_sec'    => $intervalSec,
          'schedule_mode'   => $mode,
          'weekdays_mask'   => $weekdaysMask,
          'daily_start_min' => $dailyStart,
          'daily_end_min'   => $dailyEnd,
          'window_start'    => $windowStart,
          'window_end'      => $windowEnd,
          'image_id'        => $imageId ?: null,
          'image_url'       => $imageUrl ?: null,
          'bg_color'        => $form_values['bg_color'] ?: null,
          'next_run'        => $nextRun,
        ];

        $formats = ['%s','%s','%s','%d','%s','%d','%d','%d','%d','%d','%d','%s','%s','%d'];

        if ($isEdit) {
          $ok = $wpdb->update($t['banners'], $data, ['id' => $bannerId], $formats, ['%d']);
          if ($ok === false) {
            $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
            echo '<div class="error"><p>Kunde inte uppdatera banderoll: ' . $err . '</p></div>';
          } else {
            echo '<div class="updated"><p>Banderoll uppdaterad.</p></div>';
            if ($oldImageId && $oldImageId !== $imageId) {
              kkchat_admin_banner_delete_image_if_unused($oldImageId, $t['banners'], $bannerId);
            }
            $editingId = $bannerId;
            $editingRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $bannerId), ARRAY_A);
            $form_values = $editingRow ? array_merge($defaultValues, $fromRow($editingRow)) : $defaultValues;
          }
        } else {
          $data['active'] = 1;
          $formats[] = '%d';
          $ok = $wpdb->insert($t['banners'], $data, $formats);
          if ($ok === false) {
            $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
            echo '<div class="error"><p>Kunde inte spara banderoll: ' . $err . '</p></div>';
          } else {
            echo '<div class="updated"><p>Banderoll schemalagd.</p></div>';
            if ($oldImageId && $oldImageId !== $imageId && $imageId) {
              kkchat_admin_banner_delete_image_if_unused($oldImageId, $t['banners'], (int) $wpdb->insert_id);
            }
            $form_values = $defaultValues;
            $editingId = 0;
          }
        }
      }
    }
  }

  if (isset($_GET['kk_toggle']) && check_admin_referer($nonce_key)) {
    $id  = (int) $_GET['kk_toggle'];
    $cur = (int) $wpdb->get_var($wpdb->prepare("SELECT active FROM {$t['banners']} WHERE id=%d", $id));
    if ($cur !== null) {
      $wpdb->update($t['banners'], ['active' => $cur ? 0 : 1], ['id' => $id], ['%d'], ['%d']);
      echo '<div class="updated"><p>Status uppdaterad.</p></div>';
    }
  }

  if (isset($_GET['kk_delete']) && check_admin_referer($nonce_key)) {
    $id = (int) $_GET['kk_delete'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, image_id FROM {$t['banners']} WHERE id=%d", $id), ARRAY_A);
    if ($row) {
      $wpdb->delete($t['banners'], ['id' => $id], ['%d']);
      if (!empty($row['image_id'])) {
        kkchat_admin_banner_delete_image_if_unused((int) $row['image_id'], $t['banners']);
      }
      if ($editingId === $id) {
        $editingId = 0; $form_values = $defaultValues;
      }
      echo '<div class="updated"><p>Banderoll raderad.</p></div>';
    }
  }

  if (isset($_GET['kk_run_now']) && check_admin_referer($nonce_key)) {
    $id = (int) $_GET['kk_run_now'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $id), ARRAY_A);
    if ($row && (int) $row['active'] === 1) {
      $now = time();
      $roomsSel = array_filter(array_map('kkchat_sanitize_room_slug', explode(',', (string) $row['rooms_csv'])));

      $contentText = (string) ($row['content'] ?? '');
      $contentHtml = isset($row['content_html']) ? (string) $row['content_html'] : '';
      if ($contentHtml === '') {
        $prepared = kkchat_banner_prepare_content($contentText);
        $contentText = $prepared['text'];
        $contentHtml = $prepared['html'];
      }
      $imageUrl = kkchat_admin_banner_image_url((int) ($row['image_id'] ?? 0), (string) ($row['image_url'] ?? ''));
      $bgColor = kkchat_banner_sanitize_color($row['bg_color'] ?? '') ?? '';

      foreach ($roomsSel as $slug) {
        $wpdb->insert($t['messages'], [
          'created_at'       => $now,
          'sender_id'        => 0,
          'sender_name'      => 'System',
          'recipient_id'     => null,
          'recipient_name'   => null,
          'recipient_ip'     => null,
          'room'             => $slug,
          'kind'             => 'banner',
          'content'          => $contentText,
          'banner_html'      => $contentHtml,
          'banner_image_url' => $imageUrl,
          'banner_bg_color'  => $bgColor,
        ], ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
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
        $wpdb->update($t['banners'], ['last_run' => $now, 'next_run' => $nextRun], ['id' => $id], ['%d','%d'], ['%d']);
      } else {
        $wpdb->update($t['banners'], ['last_run' => $now], ['id' => $id], ['%d'], ['%d']);
        $wpdb->query($wpdb->prepare("UPDATE {$t['banners']} SET next_run = NULL WHERE id = %d", $id));
      }
      echo '<div class="updated"><p>Banderoll postad.</p></div>';
    }
  }

  if ($editingId && !$editingRow) {
    $editingRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $editingId), ARRAY_A);
    if ($editingRow) {
      $form_values = array_merge($defaultValues, $fromRow($editingRow));
    }
  }

  $list = $wpdb->get_results("SELECT * FROM {$t['banners']} ORDER BY id DESC");
  $banners_base = menu_page_url('kkchat_banners', false);
  $colorDefault = kkchat_banner_default_color();
  ?>
  <div class="wrap">
    <h1>KKchat – Banderoller</h1>
    <h2><?php echo $editingId ? 'Redigera banderoll #' . (int) $editingId : 'Ny schemalagd banderoll'; ?></h2>
    <form method="post" enctype="multipart/form-data"><?php wp_nonce_field($nonce_key); ?>
      <input type="hidden" name="banner_id" value="<?php echo (int) $editingId; ?>">
      <table class="form-table">
        <tr>
          <th><label for="kkb_content">Meddelande</label></th>
          <td>
            <textarea id="kkb_content" name="content" rows="3" class="large-text" required><?php echo esc_textarea($form_values['content']); ?></textarea>
            <p class="description">Stödjer klickbara länkar (klistra in URL eller använd &lt;a&gt;-taggar). Radbrytningar sparas.</p>
          </td>
        </tr>
        <tr>
          <th>Rum</th>
          <td>
            <?php foreach ($rooms as $r): $checked = in_array($r['slug'], $form_values['rooms'], true); ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="rooms[]" value="<?php echo esc_attr($r['slug']); ?>" <?php checked($checked); ?>>
                <?php echo esc_html($r['title']); ?> <code><?php echo esc_html($r['slug']); ?></code>
              </label>
            <?php endforeach; ?>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_every">Intervall</label></th>
          <td>
            <input id="kkb_every" name="every_min" type="number" min="1" step="1" value="<?php echo (int) $form_values['every_min']; ?>" class="small-text"> minuter
            <p class="description">Hur ofta banderollen ska postas när schemat är aktivt.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_mode">Schematyp</label></th>
          <td>
            <select id="kkb_mode" name="schedule_mode">
              <option value="rolling" <?php selected($form_values['schedule_mode'], 'rolling'); ?>>Återkommande (utan tidsfönster)</option>
              <option value="weekly" <?php selected($form_values['schedule_mode'], 'weekly'); ?>>Veckodagar och tider</option>
              <option value="window" <?php selected($form_values['schedule_mode'], 'window'); ?>>Datumintervall</option>
            </select>
            <p class="description">Välj om banderollen ska ha ett tidsfönster eller gälla löpande.</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="weekly">
          <th>Veckodagar</th>
          <td>
            <?php foreach (kkchat_admin_weekday_labels() as $idx => $label): ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="weekdays[]" value="<?php echo (int) $idx; ?>" <?php checked(in_array((int) $idx, $form_values['weekdays'], true)); ?>> <?php echo esc_html($label); ?>
              </label>
            <?php endforeach; ?>
            <p class="description">Banner postas endast dessa dagar.</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="weekly">
          <th>Tidsintervall</th>
          <td>
            <input type="time" name="weekly_start" value="<?php echo esc_attr($form_values['weekly_start']); ?>"> – <input type="time" name="weekly_end" value="<?php echo esc_attr($form_values['weekly_end']); ?>">
            <p class="description">Dagligt tidsfönster (lokal tid).</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="window">
          <th>Datumintervall</th>
          <td>
            <label>Start <input type="datetime-local" name="window_start" value="<?php echo esc_attr($form_values['window_start']); ?>"></label>
            <label style="margin-left:12px">Slut <input type="datetime-local" name="window_end" value="<?php echo esc_attr($form_values['window_end']); ?>"></label>
            <p class="description">Banner postas endast mellan dessa datum/tider (lokal tid).</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_color_value">Bakgrundsfärg</label></th>
          <td>
            <input type="color" id="kkb_color_picker" value="<?php echo esc_attr($form_values['bg_color'] ?: $colorDefault); ?>" data-default-color="<?php echo esc_attr($colorDefault); ?>" aria-label="Färgplockare">
            <input type="text" id="kkb_color_value" name="bg_color" value="<?php echo esc_attr($form_values['bg_color']); ?>" placeholder="<?php echo esc_attr($colorDefault); ?>" pattern="^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$" style="width:120px;">
            <p class="description">Välj egen färg med plockaren eller ange HEX-kod. Lämna tomt för standardfärgen.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_image">Bannerbild</label></th>
          <td>
            <input type="file" name="banner_image" id="kkb_image" accept="image/*">
            <?php if ($form_values['image_url']): ?>
              <div style="margin:8px 0">
                <img src="<?php echo esc_url($form_values['image_url']); ?>" alt="Bannerbild" style="max-width:260px;height:auto;border:1px solid #ddd;padding:4px;border-radius:6px;display:block;">
                <label style="display:block;margin-top:6px"><input type="checkbox" name="remove_image" value="1"> Ta bort bild</label>
              </div>
            <?php endif; ?>
            <p class="description">Ladda upp en valfri bild som visas under texten.</p>
          </td>
        </tr>
      </table>
      <p>
        <button class="button button-primary" name="kk_save_banner" value="1"><?php echo $editingId ? 'Uppdatera schema' : 'Spara schema'; ?></button>
        <?php if ($editingId): ?>
          <a class="button" href="<?php echo esc_url($banners_base); ?>">Avbryt redigering</a>
        <?php endif; ?>
      </p>
    </form>

    <h2>Scheman</h2>
    <table class="widefat striped">
      <thead><tr><th>ID</th><th>Innehåll</th><th>Utseende</th><th>Rum</th><th>Schema</th><th>Nästa körning</th><th>Aktiv</th><th>Åtgärder</th></tr></thead>
      <tbody>
        <?php if ($list): foreach ($list as $b): ?>
          <?php $appearanceColor = kkchat_banner_sanitize_color($b->bg_color ?? ''); ?>
          <tr>
            <td><?php echo (int) $b->id; ?></td>
            <td><?php echo esc_html(mb_strimwidth(kkchat_banner_plain_text((string) ($b->content_html ?? $b->content)), 0, 100, '…')); ?></td>
            <td>
              <?php if (!empty($b->image_url)): ?><span class="dashicons dashicons-format-image" title="Har bild" style="vertical-align:middle"></span><?php endif; ?>
              <?php if ($appearanceColor): ?>
                <span title="Färg" style="display:inline-block;width:18px;height:18px;border-radius:4px;border:1px solid #ccc;background:<?php echo esc_attr($appearanceColor); ?>;vertical-align:middle;margin-left:6px"></span>
              <?php else: ?>
                <span class="description" style="margin-left:6px">Standard</span>
              <?php endif; ?>
            </td>
            <td><?php echo esc_html((string) $b->rooms_csv); ?></td>
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
                $edit_url = add_query_arg('kk_edit', $b->id, $banners_base);
              ?>
              <a class="button" href="<?php echo esc_url($run_url); ?>">Kör nu</a>
              <a class="button" href="<?php echo esc_url($tog_url); ?>"><?php echo $b->active ? 'Inaktivera' : 'Aktivera'; ?></a>
              <a class="button" href="<?php echo esc_url($edit_url); ?>">Redigera</a>
              <a class="button button-danger" href="<?php echo esc_url($del_url); ?>" onclick="return confirm('Ta bort detta schema?');">Ta bort</a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="8">Inga banderollscheman ännu.</td></tr>
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

      const picker = document.getElementById('kkb_color_picker');
      const colorInput = document.getElementById('kkb_color_value');
      if (picker && colorInput) {
        const def = picker.dataset.defaultColor || '';
        if (!colorInput.value && def) {
          picker.value = def;
        }
        picker.addEventListener('input', function(){
          colorInput.value = picker.value || '';
        });
        colorInput.addEventListener('input', function(){
          const v = (colorInput.value || '').trim();
          if (/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/.test(v)) {
            picker.value = v;
          }
        });
      }
    });
  </script>
  <?php
}

/**
 * Moderering
 */
