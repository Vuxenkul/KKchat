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

if (!function_exists('kkchat_admin_default_banner_color')) {
  function kkchat_admin_default_banner_color(): string {
    return '#e35754';
  }
}

if (!function_exists('kkchat_admin_sanitize_hex_color')) {
  function kkchat_admin_sanitize_hex_color(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if ($value[0] !== '#') { $value = '#' . $value; }
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $value, $m)) {
      return '#' . strtolower($m[1]);
    }
    return '';
  }
}

if (!function_exists('kkchat_admin_banner_allowed_html')) {
  function kkchat_admin_banner_allowed_html(): array {
    return [
      'a' => [
        'href'   => true,
        'target' => true,
        'rel'    => true,
      ],
      'br'     => [],
      'strong' => [],
      'em'     => [],
      'b'      => [],
      'i'      => [],
      'u'      => [],
    ];
  }
}

if (!function_exists('kkchat_admin_sanitize_banner_html')) {
  function kkchat_admin_sanitize_banner_html(string $raw): string {
    $clean = wp_kses($raw, kkchat_admin_banner_allowed_html());
    $with_breaks = nl2br($clean, false);
    return make_clickable($with_breaks);
  }
}

if (!function_exists('kkchat_admin_format_datetime_local_input')) {
  function kkchat_admin_format_datetime_local_input(?int $timestamp): string {
    if (!$timestamp) return '';
    return kkchat_admin_format_datetime($timestamp, 'Y-m-d\TH:i');
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
  $defaultColor = kkchat_admin_default_banner_color();

  $editing = null;
  if (isset($_GET['kk_edit'])) {
    $editId = (int) $_GET['kk_edit'];
    $editing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $editId), ARRAY_A);
    if (!$editing) {
      echo '<div class="error"><p>Kunde inte hitta banderollen att redigera.</p></div>';
    }
  }

  $formState = [
    'content'       => '',
    'rooms'         => [],
    'every_min'     => 10,
    'schedule_mode' => 'rolling',
    'weekdays'      => [],
    'weekly_start'  => '08:00',
    'weekly_end'    => '17:00',
    'window_start'  => '',
    'window_end'    => '',
    'link_url'      => '',
    'bg_color'      => $defaultColor,
    'image_id'      => null,
    'image_url'     => '',
  ];

  if ($editing) {
    $formState['content'] = (string)($editing['content'] ?? '');
    $formState['rooms'] = array_filter(array_map('kkchat_sanitize_room_slug', explode(',', (string)$editing['rooms_csv'])));
    $formState['every_min'] = max(1, (int) round(((int)($editing['interval_sec'] ?? 600)) / 60));
    $formState['schedule_mode'] = $editing['schedule_mode'] ?? 'rolling';
    $mask = (int) ($editing['weekdays_mask'] ?? 0);
    $weekdays = [];
    foreach (kkchat_admin_weekday_labels() as $idx => $_) {
      if ($mask & (1 << $idx)) { $weekdays[] = (string)$idx; }
    }
    $formState['weekdays'] = $weekdays;
    $formState['weekly_start'] = kkchat_admin_format_minutes((int)($editing['daily_start_min'] ?? 480));
    $formState['weekly_end']   = kkchat_admin_format_minutes((int)($editing['daily_end_min'] ?? 1020));
    $formState['window_start'] = kkchat_admin_format_datetime_local_input((int)($editing['window_start'] ?? 0));
    $formState['window_end']   = kkchat_admin_format_datetime_local_input((int)($editing['window_end'] ?? 0));
    $formState['link_url']     = (string)($editing['link_url'] ?? '');
    $formState['bg_color']     = $editing['bg_color'] ? (string)$editing['bg_color'] : $defaultColor;
    $formState['image_id']     = isset($editing['image_id']) ? (int)$editing['image_id'] : null;
    $formState['image_url']    = (string)($editing['image_url'] ?? '');
  }

  $errors = [];
  $success = '';
  $imageToDelete = null;

  $isSubmit = isset($_POST['kk_add_banner']) || isset($_POST['kk_update_banner']);
  if ($isSubmit) {
    check_admin_referer($nonce_key);
    $isUpdate = isset($_POST['kk_update_banner']);
    $bannerId = $isUpdate ? (int)($_POST['banner_id'] ?? 0) : 0;
    $existing = $isUpdate ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $bannerId), ARRAY_A) : null;
    if ($isUpdate && !$existing) {
      $errors[] = 'Kunde inte hitta banderollen att uppdatera.';
    }

    // Preserve entered values
    $formState['content']      = (string) wp_unslash($_POST['content'] ?? $formState['content']);
    $formState['rooms']        = array_values(array_unique(array_filter(array_map('kkchat_sanitize_room_slug', (array)($_POST['rooms'] ?? $formState['rooms'])))));
    $formState['every_min']    = max(1, (int)($_POST['every_min'] ?? $formState['every_min']));
    $formState['schedule_mode']= strtolower((string)($_POST['schedule_mode'] ?? $formState['schedule_mode']));
    $formState['weekdays']     = array_map('strval', array_map('intval', (array)($_POST['weekdays'] ?? $formState['weekdays'])));
    $formState['weekly_start'] = sanitize_text_field($_POST['weekly_start'] ?? $formState['weekly_start']);
    $formState['weekly_end']   = sanitize_text_field($_POST['weekly_end'] ?? $formState['weekly_end']);
    $formState['window_start'] = sanitize_text_field($_POST['window_start'] ?? $formState['window_start']);
    $formState['window_end']   = sanitize_text_field($_POST['window_end'] ?? $formState['window_end']);
    $formState['link_url']     = sanitize_text_field($_POST['link_url'] ?? $formState['link_url']);
    $bgPicker = sanitize_text_field($_POST['bg_color'] ?? '');
    $bgHex    = sanitize_text_field($_POST['bg_color_hex'] ?? '');
    $formState['bg_color']     = $bgHex !== '' ? $bgHex : ($bgPicker !== '' ? $bgPicker : $formState['bg_color']);

    $contentHtml = kkchat_admin_sanitize_banner_html($formState['content']);
    if (trim(wp_strip_all_tags($contentHtml)) === '') {
      $errors[] = 'Innehåll krävs.';
    }
    if (!$formState['rooms']) {
      $errors[] = 'Minst ett rum måste väljas.';
    }

    $linkUrl = '';
    if ($formState['link_url'] !== '') {
      $linkUrl = esc_url_raw($formState['link_url']);
      if (!$linkUrl) {
        $errors[] = 'Ogiltig länk. Ange en fullständig URL.';
      }
    }

    $bgColor = kkchat_admin_sanitize_hex_color($formState['bg_color']);
    if ($formState['bg_color'] !== '' && $bgColor === '') {
      $errors[] = 'Ogiltig färgkod.';
    }
    if ($bgColor === '') {
      $bgColor = $existing['bg_color'] ?? $defaultColor;
    }

    $mode = in_array($formState['schedule_mode'], ['rolling','weekly','window'], true) ? $formState['schedule_mode'] : 'rolling';
    $weekdaysMask = 0;
    $dailyStart   = 0;
    $dailyEnd     = 0;
    $windowStart  = 0;
    $windowEnd    = 0;

    if ($mode === 'weekly') {
      foreach ($formState['weekdays'] as $d) {
        $val = (int)$d;
        if ($val >= 0 && $val <= 6) { $weekdaysMask |= (1 << $val); }
      }
      if ($weekdaysMask === 0) {
        $errors[] = 'Välj minst en veckodag.';
      }
      $startMin = kkchat_admin_parse_time_to_minutes($formState['weekly_start']);
      $endMin   = kkchat_admin_parse_time_to_minutes($formState['weekly_end']);
      if ($startMin === null || $endMin === null) {
        $errors[] = 'Ogiltigt tidsintervall.';
      } elseif ($endMin <= $startMin) {
        $errors[] = 'Sluttiden måste vara senare än starttiden.';
      } else {
        $dailyStart = $startMin;
        $dailyEnd   = $endMin;
      }
    } elseif ($mode === 'window') {
      $startTs = kkchat_admin_parse_datetime_local($formState['window_start']);
      $endTs   = kkchat_admin_parse_datetime_local($formState['window_end']);
      if (!$startTs || !$endTs) {
        $errors[] = 'Start- och sluttid måste anges.';
      } elseif ($endTs <= $startTs) {
        $errors[] = 'Slutdatumet måste vara senare än startdatumet.';
      } else {
        $windowStart = $startTs;
        $windowEnd   = $endTs;
      }
    }

    $intervalSec = $formState['every_min'] * 60;
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
      if ($mode !== 'rolling') { $afterTs -= 1; }
      $nextRun = kkchat_banner_next_run($calcRow, $afterTs);
      if ($nextRun === null) {
        $errors[] = 'Schemat saknar framtida körningar med vald konfiguration.';
      }
    }

    $imageId = $existing['image_id'] ?? null;
    $imageUrl = $existing['image_url'] ?? '';
    $removeImage = !empty($_POST['remove_image']);

    if (!empty($_FILES['banner_image']) && is_array($_FILES['banner_image']) && !empty($_FILES['banner_image']['name'])) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
      $mimes = [
        'jpg|jpeg' => 'image/jpeg',
        'png'      => 'image/png',
        'gif'      => 'image/gif',
        'webp'     => 'image/webp',
      ];
      $upload = media_handle_upload('banner_image', 0, [], ['mimes'=>$mimes, 'test_form'=>false]);
      if (is_wp_error($upload)) {
        $errors[] = 'Kunde inte ladda upp bild: ' . $upload->get_error_message();
      } else {
        if ($imageId && $imageId !== (int)$upload) {
          $imageToDelete = $imageId;
        }
        $imageId = (int)$upload;
        $imageUrl = wp_get_attachment_url($upload) ?: '';
        if ($imageUrl === '') {
          $errors[] = 'Kunde inte läsa bild-URL.';
        }
      }
    } elseif ($removeImage) {
      if ($imageId) { $imageToDelete = $imageId; }
      $imageId = null;
      $imageUrl = '';
    }

    if ($errors && isset($upload) && is_numeric($upload)) {
      wp_delete_attachment((int)$upload, true);
    }

    if (!$errors) {
      $data = [
        'content'         => $contentHtml,
        'link_url'        => $linkUrl,
        'image_id'        => $imageId ?: null,
        'image_url'       => $imageUrl,
        'bg_color'        => $bgColor,
        'rooms_csv'       => implode(',', $formState['rooms']),
        'interval_sec'    => $intervalSec,
        'schedule_mode'   => $mode,
        'weekdays_mask'   => $weekdaysMask,
        'daily_start_min' => $dailyStart,
        'daily_end_min'   => $dailyEnd,
        'window_start'    => $windowStart,
        'window_end'      => $windowEnd,
        'next_run'        => $nextRun,
      ];

      if ($isUpdate) {
        $formats = ['%s','%s','%d','%s','%s','%s','%d','%s','%d','%d','%d','%d','%d','%d'];
        $updated = $wpdb->update($t['banners'], $data, ['id'=>$bannerId], $formats, ['%d']);
        if ($updated === false) {
          $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
          $errors[] = 'Kunde inte spara banderoll: ' . $err;
        } else {
          $success = 'Banderoll uppdaterad.';
          $editing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $bannerId), ARRAY_A);
          if ($imageToDelete) {
            wp_delete_attachment((int)$imageToDelete, true);
          }
        }
      } else {
        $data['active'] = 1;
        $formats = ['%s','%s','%d','%s','%s','%s','%d','%s','%d','%d','%d','%d','%d','%d','%d'];
        $ok = $wpdb->insert($t['banners'], $data, $formats);
        if ($ok === false) {
          $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
          $errors[] = 'Kunde inte spara banderoll: ' . $err;
        } else {
          $success = 'Banderoll schemalagd.';
          $formState = [
            'content'       => '',
            'rooms'         => [],
            'every_min'     => 10,
            'schedule_mode' => 'rolling',
            'weekdays'      => [],
            'weekly_start'  => '08:00',
            'weekly_end'    => '17:00',
            'window_start'  => '',
            'window_end'    => '',
            'link_url'      => '',
            'bg_color'      => $defaultColor,
            'image_id'      => null,
            'image_url'     => '',
          ];
          if ($imageToDelete) {
            wp_delete_attachment((int)$imageToDelete, true);
          }
        }
      }
    }
  }

  if ($errors) {
    foreach ($errors as $err) {
      echo '<div class="error"><p>' . esc_html($err) . '</p></div>';
    }
  }
  if ($success) {
    echo '<div class="updated"><p>' . esc_html($success) . '</p></div>';
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
    $row = $wpdb->get_row($wpdb->prepare("SELECT image_id FROM {$t['banners']} WHERE id=%d", $id), ARRAY_A);
    $wpdb->delete($t['banners'], ['id'=>$id], ['%d']);
    if ($row && !empty($row['image_id'])) {
      wp_delete_attachment((int)$row['image_id'], true);
    }
    echo '<div class="updated"><p>Banderoll raderad.</p></div>';
  }
  if (isset($_GET['kk_run_now']) && check_admin_referer($nonce_key)) {
    $id = (int)$_GET['kk_run_now'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $id), ARRAY_A);
    if ($row && (int)$row['active'] === 1) {
      $now = time();
      $roomsSel = array_filter(array_map('kkchat_sanitize_room_slug', explode(',', (string)$row['rooms_csv'])));
      $content = kkchat_banner_message_content($row);
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
          'content'        => $content,
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
  $isEditing = (bool)$editing;
  $formHeadline = $isEditing ? 'Redigera banderoll' : 'Ny schemalagd banderoll';
  $submitLabel = $isEditing ? 'Uppdatera banderoll' : 'Spara schema';
  ?>
  <div class="wrap">
    <h1>KKchat – Banderoller</h1>
    <h2><?php echo esc_html($formHeadline); ?></h2>
    <form method="post" enctype="multipart/form-data"><?php wp_nonce_field($nonce_key); ?>
      <?php if ($isEditing): ?><input type="hidden" name="banner_id" value="<?php echo (int)$editing['id']; ?>"><?php endif; ?>
      <table class="form-table">
        <tr>
          <th><label for="kkb_content">Meddelande</label></th>
          <td>
            <textarea id="kkb_content" name="content" rows="4" class="large-text" required><?php echo esc_textarea($formState['content']); ?></textarea>
            <p class="description">Stöd för klickbara länkar (URL eller &lt;a&gt;). Rader bevaras.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_link">Länk (valfri)</label></th>
          <td>
            <input id="kkb_link" name="link_url" type="url" value="<?php echo esc_attr($formState['link_url']); ?>" class="regular-text" placeholder="https://exempel.se">
            <p class="description">Om du anger en länk öppnas bannertext och bild i ny flik.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_bg">Bakgrundsfärg</label></th>
          <td>
            <input id="kkb_bg" name="bg_color" type="color" value="<?php echo esc_attr($formState['bg_color']); ?>">
            <input name="bg_color_hex" type="text" value="<?php echo esc_attr($formState['bg_color']); ?>" class="regular-text" style="width:120px; margin-left:8px" aria-label="HEX-kod">
            <p class="description">Lämna tomt för standardfärgen.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_image">Bild (valfri)</label></th>
          <td>
            <input id="kkb_image" type="file" name="banner_image" accept="image/*">
            <?php if (!empty($formState['image_url'])): ?>
              <div style="margin-top:8px">
                <strong>Nuvarande bild:</strong> <a href="<?php echo esc_url($formState['image_url']); ?>" target="_blank" rel="noopener">Öppna</a><br>
                <img src="<?php echo esc_url($formState['image_url']); ?>" alt="Bannerbild" style="max-width:240px; height:auto; margin-top:6px; display:block; border:1px solid #ddd; padding:4px; background:#fff">
                <label style="display:block; margin-top:6px"><input type="checkbox" name="remove_image" value="1"> Ta bort bild</label>
              </div>
            <?php endif; ?>
            <p class="description">Uppladdad bild kopplas till banderollen och raderas om banderollen tas bort.</p>
          </td>
        </tr>
        <tr>
          <th>Rum</th>
          <td>
            <?php foreach ($rooms as $r): ?>
              <?php $checked = in_array($r['slug'], $formState['rooms'], true) ? 'checked' : ''; ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="rooms[]" value="<?php echo esc_attr($r['slug']); ?>" <?php echo $checked; ?>>
                <?php echo esc_html($r['title']); ?> <code><?php echo esc_html($r['slug']); ?></code>
              </label>
            <?php endforeach; ?>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_every">Intervall</label></th>
          <td>
            <input id="kkb_every" name="every_min" type="number" min="1" step="1" value="<?php echo (int)$formState['every_min']; ?>" class="small-text"> minuter
            <p class="description">Hur ofta banderollen ska postas när schemat är aktivt.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_mode">Schematyp</label></th>
          <td>
            <select id="kkb_mode" name="schedule_mode">
              <option value="rolling" <?php selected($formState['schedule_mode'], 'rolling'); ?>>Återkommande (utan tidsfönster)</option>
              <option value="weekly" <?php selected($formState['schedule_mode'], 'weekly'); ?>>Veckodagar och tider</option>
              <option value="window" <?php selected($formState['schedule_mode'], 'window'); ?>>Datumintervall</option>
            </select>
            <p class="description">Välj om banderollen ska ha ett tidsfönster eller gälla löpande.</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="weekly">
          <th>Veckodagar</th>
          <td>
            <?php foreach (kkchat_admin_weekday_labels() as $idx => $label): ?>
              <?php $wChecked = in_array((string)$idx, $formState['weekdays'], true) ? 'checked' : ''; ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="weekdays[]" value="<?php echo (int)$idx; ?>" <?php echo $wChecked; ?>> <?php echo esc_html($label); ?>
              </label>
            <?php endforeach; ?>
            <p class="description">Banner postas endast dessa dagar.</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="weekly">
          <th>Tidsintervall</th>
          <td>
            <input type="time" name="weekly_start" value="<?php echo esc_attr($formState['weekly_start']); ?>"> – <input type="time" name="weekly_end" value="<?php echo esc_attr($formState['weekly_end']); ?>">
            <p class="description">Dagligt tidsfönster (lokal tid).</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="window">
          <th>Datumintervall</th>
          <td>
            <label>Start <input type="datetime-local" name="window_start" value="<?php echo esc_attr($formState['window_start']); ?>"></label>
            <label style="margin-left:12px">Slut <input type="datetime-local" name="window_end" value="<?php echo esc_attr($formState['window_end']); ?>"></label>
            <p class="description">Banner postas endast mellan dessa datum/tider (lokal tid).</p>
          </td>
        </tr>
      </table>
      <p><button class="button button-primary" name="<?php echo $isEditing ? 'kk_update_banner' : 'kk_add_banner'; ?>" value="1"><?php echo esc_html($submitLabel); ?></button></p>
    </form>

    <h2>Scheman</h2>
    <table class="widefat striped">
      <thead><tr><th>ID</th><th>Innehåll</th><th>Rum</th><th>Schema</th><th>Nästa körning</th><th>Aktiv</th><th>Åtgärder</th></tr></thead>
      <tbody>
        <?php if ($list): foreach ($list as $b): ?>
          <tr>
            <td><?php echo (int)$b->id; ?></td>
            <td><?php echo esc_html(mb_strimwidth(wp_strip_all_tags((string)$b->content), 0, 100, '…')); ?></td>
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
                $edit_url = add_query_arg('kk_edit', $b->id, $banners_base);
              ?>
              <a class="button" href="<?php echo esc_url($run_url); ?>">Kör nu</a>
              <a class="button" href="<?php echo esc_url($tog_url); ?>"><?php echo $b->active ? 'Inaktivera' : 'Aktivera'; ?></a>
              <a class="button" href="<?php echo esc_url($edit_url); ?>">Redigera</a>
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
      const bgPicker = document.getElementById('kkb_bg');
      const bgHex = document.querySelector('input[name="bg_color_hex"]');

      function syncScheduleRows(){
        const value = mode ? mode.value : '';
        rows.forEach(function(row){
          const showFor = (row.getAttribute('data-mode') || '').split(',');
          row.style.display = showFor.includes(value) ? '' : 'none';
        });
      }

      function normalizeHex(value){
        const trimmed = (value || '').trim();
        if (!trimmed) return '';
        const withHash = trimmed.startsWith('#') ? trimmed : '#'+trimmed;
        return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(withHash) ? withHash.toLowerCase() : '';
      }

      function syncFromPicker(){
        if (bgPicker && bgHex) {
          bgHex.value = bgPicker.value || '';
        }
      }

      function syncFromHex(){
        if (!bgPicker || !bgHex) return;
        const normalized = normalizeHex(bgHex.value);
        if (normalized) {
          bgHex.value = normalized;
          bgPicker.value = normalized;
        }
      }

      if (bgPicker && bgHex) {
        bgPicker.addEventListener('input', syncFromPicker);
        bgHex.addEventListener('change', syncFromHex);
        bgHex.addEventListener('blur', syncFromHex);
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
