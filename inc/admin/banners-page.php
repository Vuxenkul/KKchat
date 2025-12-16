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
    return kkchat_admin_format_datetime($timestamp, 'Y-m-d\TH:i');
  }
}

if (!function_exists('kkchat_banner_allowed_html')) {
  function kkchat_banner_allowed_html(): array {
    return [
      'a'   => ['href' => [], 'target' => [], 'rel' => []],
      'br'  => [],
      'strong' => [], 'em' => [], 'b' => [], 'i' => [],
      'img' => ['src' => [], 'alt' => [], 'title' => []],
    ];
  }
}

if (!function_exists('kkchat_admin_build_banner_html')) {
  function kkchat_admin_build_banner_html(string $body, ?string $linkUrl, ?string $imageUrl): string {
    $text = trim($body);
    $text = $text !== '' ? nl2br($text) : '';

    if ($text !== '') {
      $text = make_clickable($text);
      if ($linkUrl) {
        $safe = esc_url_raw($linkUrl);
        if ($safe) {
          $text = sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url($safe), $text);
        }
      }
    }

    $html = $text;
    if ($imageUrl) {
      $safeImg = esc_url($imageUrl);
      if ($safeImg !== '') {
        if ($html !== '') { $html .= '<br>'; }
        $html .= '<img src="' . $safeImg . '" alt="" loading="lazy">';
      }
    }

    return wp_kses($html, kkchat_banner_allowed_html());
  }
}

if (!function_exists('kkchat_admin_banner_plaintext')) {
  function kkchat_admin_banner_plaintext(string $html): string {
    return trim(wp_strip_all_tags($html));
  }
}

if (!function_exists('kkchat_admin_describe_banner_schedule')) {
  function kkchat_admin_describe_banner_schedule($row): string {
    $intervalSec  = isset($row->interval_sec) ? (int) $row->interval_sec : (int) ($row['interval_sec'] ?? 0);
    $intervalMin  = $intervalSec > 0 ? max(1, (int) round($intervalSec / 60)) : 0;
    $intervalText = $intervalMin > 0
      ? sprintf('Var %d minut%s', $intervalMin, $intervalMin === 1 ? '' : 'er')
      : sprintf('%d sekunder', max(1, $intervalSec));

    $visibleFrom = isset($row->visible_from) ? (int) $row->visible_from : (int) ($row['visible_from'] ?? 0);
    $visibleUntil = isset($row->visible_until) ? (int) $row->visible_until : (int) ($row['visible_until'] ?? 0);
    $visibility = '';
    if ($visibleFrom || $visibleUntil) {
      if ($visibleFrom && $visibleUntil) {
        $visibility = sprintf('Synlig %s – %s', kkchat_admin_format_datetime($visibleFrom), kkchat_admin_format_datetime($visibleUntil));
      } elseif ($visibleFrom) {
        $visibility = sprintf('Synlig från %s', kkchat_admin_format_datetime($visibleFrom));
      } elseif ($visibleUntil) {
        $visibility = sprintf('Synlig till %s', kkchat_admin_format_datetime($visibleUntil));
      }
    }

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
      $text    = sprintf('Veckodagar: %s · %s · %s', $dayText, $range, $intervalText);
      return $visibility ? ($text . ' · ' . $visibility) : $text;
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
      $text = sprintf('Datumintervall: %s · %s', $range, $intervalText);
      return $visibility ? ($text . ' · ' . $visibility) : $text;
    }

    $text = sprintf('Återkommande · %s', $intervalText);
    return $visibility ? ($text . ' · ' . $visibility) : $text;
  }
}

function kkchat_admin_banners_page(){
  if (!current_user_can('manage_options')) return;
  global $wpdb; $t = kkchat_tables();
  $nonce_key = 'kkchat_banners';
  $rooms = $wpdb->get_results("SELECT slug,title FROM {$t['rooms']} ORDER BY sort ASC, title ASC", ARRAY_A);
  $banners_base = menu_page_url('kkchat_banners', false);

  $editingId  = isset($_GET['kk_edit']) ? (int) $_GET['kk_edit'] : 0;
  $editingRow = $editingId ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $editingId), ARRAY_A) : null;
  if ($editingId && !$editingRow) {
    echo '<div class="error"><p>Kunde inte hitta banderoll att redigera.</p></div>';
    $editingId = 0;
  }

  $defaults = [
    'id'            => 0,
    'content'       => '',
    'link_url'      => '',
    'image_id'      => 0,
    'image_url'     => '',
    'every_min'     => 10,
    'schedule_mode' => 'rolling',
    'weekdays'      => [],
    'weekly_start'  => '08:00',
    'weekly_end'    => '17:00',
    'window_start'  => '',
    'window_end'    => '',
    'visible_from'  => '',
    'visible_until' => '',
    'rooms_selected'=> [],
  ];

  $formValues = $defaults;
  if ($editingRow) {
    $mask = (int) ($editingRow['weekdays_mask'] ?? 0);
    $selectedDays = [];
    foreach (kkchat_admin_weekday_labels() as $idx => $_) {
      if (($mask >> $idx) & 1) { $selectedDays[] = (int) $idx; }
    }
    $formValues = array_merge($formValues, [
      'id'            => (int) $editingRow['id'],
      'content'       => (string) ($editingRow['content'] ?? ''),
      'link_url'      => (string) ($editingRow['link_url'] ?? ''),
      'image_id'      => (int) ($editingRow['image_id'] ?? 0),
      'image_url'     => ($editingRow['image_id'] ?? 0) ? (wp_get_attachment_url((int) $editingRow['image_id']) ?: '') : '',
      'every_min'     => max(1, (int) round(((int) ($editingRow['interval_sec'] ?? 600)) / 60)),
      'schedule_mode' => (string) ($editingRow['schedule_mode'] ?? 'rolling'),
      'weekdays'      => $selectedDays,
      'weekly_start'  => isset($editingRow['daily_start_min']) ? kkchat_admin_format_minutes((int) $editingRow['daily_start_min']) : '08:00',
      'weekly_end'    => isset($editingRow['daily_end_min']) ? kkchat_admin_format_minutes((int) $editingRow['daily_end_min']) : '17:00',
      'window_start'  => kkchat_admin_format_datetime_local_value(isset($editingRow['window_start']) ? (int) $editingRow['window_start'] : null),
      'window_end'    => kkchat_admin_format_datetime_local_value(isset($editingRow['window_end']) ? (int) $editingRow['window_end'] : null),
      'visible_from'  => kkchat_admin_format_datetime_local_value(isset($editingRow['visible_from']) ? (int) $editingRow['visible_from'] : null),
      'visible_until' => kkchat_admin_format_datetime_local_value(isset($editingRow['visible_until']) ? (int) $editingRow['visible_until'] : null),
      'rooms_selected'=> array_values(array_filter(array_map('kkchat_sanitize_room_slug', explode(',', (string) ($editingRow['rooms_csv'] ?? ''))))),
    ]);
  }

  if (isset($_POST['kk_save_banner'])) {
    check_admin_referer($nonce_key);
    $bannerId = (int)($_POST['banner_id'] ?? 0);
    $existing = $bannerId ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $bannerId), ARRAY_A) : null;
    $isEdit   = $existing !== null;

    $content = wp_kses((string)($_POST['content'] ?? ''), kkchat_banner_allowed_html());
    $linkUrlRaw = trim((string)($_POST['link_url'] ?? ''));
    $linkUrl    = $linkUrlRaw !== '' ? esc_url_raw($linkUrlRaw) : '';
    $intervalMin = max(1, (int)($_POST['every_min'] ?? 10));
    $intervalSec = $intervalMin * 60;
    $sel = array_map('kkchat_sanitize_room_slug', (array)($_POST['rooms'] ?? []));
    $sel = array_values(array_unique(array_filter($sel)));
    $mode = strtolower((string)($_POST['schedule_mode'] ?? 'rolling'));
    if (!in_array($mode, ['rolling','weekly','window'], true)) {
      $mode = 'rolling';
    }

    $visibleFromStr  = sanitize_text_field($_POST['visible_from'] ?? '');
    $visibleUntilStr = sanitize_text_field($_POST['visible_until'] ?? '');
    $visibleFrom     = kkchat_admin_parse_datetime_local($visibleFromStr) ?? 0;
    $visibleUntil    = kkchat_admin_parse_datetime_local($visibleUntilStr) ?? 0;

    $errors = [];
    if ($linkUrlRaw !== '' && $linkUrl === '') {
      $errors[] = 'Ogiltig länkadress.';
    }
    if (!$sel) {
      $errors[] = 'Minst ett rum måste väljas.';
    }
    if ($visibleFrom && $visibleUntil && $visibleUntil <= $visibleFrom) {
      $errors[] = 'Slutdatumet för synlighet måste vara senare än startdatumet.';
    }

    $weekdaysMask = 0;
    $dailyStart   = 0;
    $dailyEnd     = 0;
    $windowStart  = 0;
    $windowEnd    = 0;

    $selectedDays = array_map('intval', (array)($_POST['weekdays'] ?? []));
    if ($mode === 'weekly') {
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

    $imageId  = $isEdit ? (int) ($existing['image_id'] ?? 0) : 0;
    $imageUrl = $imageId ? (wp_get_attachment_url($imageId) ?: '') : '';
    $removeImage = !empty($_POST['remove_image']);

    if ($removeImage && $imageId) {
      include_once ABSPATH . 'wp-admin/includes/file.php';
      include_once ABSPATH . 'wp-admin/includes/media.php';
      include_once ABSPATH . 'wp-admin/includes/image.php';
      wp_delete_attachment($imageId, true);
      $imageId = 0;
      $imageUrl = '';
    }

    $hasUpload = !empty($_FILES['banner_image']['name'] ?? '');
    if (!$errors && $hasUpload) {
      include_once ABSPATH . 'wp-admin/includes/file.php';
      include_once ABSPATH . 'wp-admin/includes/media.php';
      include_once ABSPATH . 'wp-admin/includes/image.php';
      $uploadId = media_handle_upload('banner_image', 0);
      if (is_wp_error($uploadId)) {
        $errors[] = 'Kunde inte ladda upp bild: ' . esc_html($uploadId->get_error_message());
      } else {
        if ($imageId && $imageId !== $uploadId) {
          wp_delete_attachment($imageId, true);
        }
        $imageId  = (int) $uploadId;
        $imageUrl = wp_get_attachment_url($uploadId) ?: '';
      }
    }

    if ($content === '' && $imageUrl === '') {
      $errors[] = 'Innehåll eller bild krävs.';
    }

    $contentHtml = kkchat_admin_build_banner_html($content, $linkUrl, $imageUrl);

    $calcRow = [
      'interval_sec'    => $intervalSec,
      'schedule_mode'   => $mode,
      'weekdays_mask'   => $weekdaysMask,
      'daily_start_min' => $dailyStart,
      'daily_end_min'   => $dailyEnd,
      'window_start'    => $windowStart,
      'window_end'      => $windowEnd,
      'visible_from'    => $visibleFrom,
      'visible_until'   => $visibleUntil,
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

    $formValues = array_merge($formValues, [
      'id'            => $bannerId,
      'content'       => $content,
      'link_url'      => $linkUrlRaw,
      'image_id'      => $imageId,
      'image_url'     => $imageUrl,
      'every_min'     => $intervalMin,
      'schedule_mode' => $mode,
      'weekdays'      => $selectedDays,
      'weekly_start'  => sanitize_text_field($_POST['weekly_start'] ?? '08:00'),
      'weekly_end'    => sanitize_text_field($_POST['weekly_end'] ?? '17:00'),
      'window_start'  => sanitize_text_field($_POST['window_start'] ?? ''),
      'window_end'    => sanitize_text_field($_POST['window_end'] ?? ''),
      'visible_from'  => $visibleFromStr,
      'visible_until' => $visibleUntilStr,
      'rooms_selected'=> $sel,
    ]);

    if ($errors) {
      foreach ($errors as $err) {
        echo '<div class="error"><p>' . esc_html($err) . '</p></div>';
      }
    } else {
      $data = [
        'content'         => $content,
        'content_html'    => $contentHtml,
        'link_url'        => $linkUrl,
        'image_id'        => $imageId ?: null,
        'rooms_csv'       => implode(',', $sel),
        'interval_sec'    => $intervalSec,
        'schedule_mode'   => $mode,
        'weekdays_mask'   => $weekdaysMask,
        'daily_start_min' => $dailyStart ?: null,
        'daily_end_min'   => $dailyEnd ?: null,
        'window_start'    => $windowStart ?: null,
        'window_end'      => $windowEnd ?: null,
        'visible_from'    => $visibleFrom ?: null,
        'visible_until'   => $visibleUntil ?: null,
        'next_run'        => $nextRun,
        'active'          => $isEdit ? (int) ($existing['active'] ?? 1) : 1,
      ];

      $formats = ['%s','%s','%s','%d','%s','%d','%s','%d','%d','%d','%d','%d','%d','%d','%d','%d'];

      if ($isEdit) {
        $ok = $wpdb->update($t['banners'], $data, ['id' => $bannerId], $formats, ['%d']);
        if ($ok === false) {
          $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
          echo '<div class="error"><p>Kunde inte uppdatera banderoll: '.$err.'</p></div>';
        } else {
          echo '<div class="updated"><p>Banderoll uppdaterad.</p></div>';
          $editingId = $bannerId;
        }
      } else {
        $ok = $wpdb->insert($t['banners'], $data, $formats);
        if ($ok === false) {
          $err = $wpdb->last_error ? esc_html($wpdb->last_error) : 'Okänt databasfel.';
          echo '<div class="error"><p>Kunde inte spara banderoll: '.$err.'</p></div>';
        } else {
          echo '<div class="updated"><p>Banderoll schemalagd.</p></div>';
          $bannerId = (int) $wpdb->insert_id;
          $editingId = 0;
          $formValues = $defaults;
        }
      }

      if ($nextRun === null && isset($bannerId) && $bannerId > 0) {
        $wpdb->query($wpdb->prepare("UPDATE {$t['banners']} SET next_run = NULL WHERE id = %d", $bannerId));
      }

      if ($editingId && $editingId === $bannerId) {
        $editingRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $editingId), ARRAY_A);
        if ($editingRow) {
          $formValues['next_run'] = $editingRow['next_run'] ?? null;
        }
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
    $row = $wpdb->get_row($wpdb->prepare("SELECT image_id FROM {$t['banners']} WHERE id=%d", $id), ARRAY_A);
    $wpdb->delete($t['banners'], ['id'=>$id], ['%d']);
    if ($row && !empty($row['image_id'])) {
      include_once ABSPATH . 'wp-admin/includes/file.php';
      include_once ABSPATH . 'wp-admin/includes/media.php';
      include_once ABSPATH . 'wp-admin/includes/image.php';
      wp_delete_attachment((int) $row['image_id'], true);
    }
    echo '<div class="updated"><p>Banderoll raderad.</p></div>';
    if ($editingId === $id) {
      $editingId = 0;
      $formValues = $defaults;
    }
  }

  if (isset($_GET['kk_run_now']) && check_admin_referer($nonce_key)) {
    $id = (int)$_GET['kk_run_now'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['banners']} WHERE id=%d", $id), ARRAY_A);
    if ($row && (int)$row['active'] === 1) {
      $now = time();
      $visibleFrom = (int) ($row['visible_from'] ?? 0);
      $visibleUntil = (int) ($row['visible_until'] ?? 0);
      if (($visibleFrom && $now < $visibleFrom) || ($visibleUntil && $now > $visibleUntil)) {
        echo '<div class="error"><p>Banderollen är inte synlig just nu.</p></div>';
      } else {
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
            'content'        => $row['content_html'] !== null && $row['content_html'] !== '' ? $row['content_html'] : $row['content'],
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
          'visible_from'    => (int) ($row['visible_from'] ?? 0),
          'visible_until'   => (int) ($row['visible_until'] ?? 0),
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
  }

  $list = $wpdb->get_results("SELECT * FROM {$t['banners']} ORDER BY id DESC");
  $editing = $editingId > 0 && $editingRow;
  ?>
  <div class="wrap">
    <h1>KKchat – Banderoller</h1>
    <h2><?php echo $editing ? 'Redigera banderoll' : 'Ny schemalagd banderoll'; ?></h2>
    <form method="post" enctype="multipart/form-data"><?php wp_nonce_field($nonce_key); ?>
      <input type="hidden" name="banner_id" value="<?php echo (int)($formValues['id'] ?? 0); ?>">
      <table class="form-table">
        <tr>
          <th><label for="kkb_content">Meddelande</label></th>
          <td>
            <textarea id="kkb_content" name="content" rows="3" class="large-text"><?php echo esc_textarea($formValues['content'] ?? ''); ?></textarea>
            <p class="description">Du kan ange text, klistra in URL:er (de blir klickbara) eller lägga till egna &lt;a&gt;-taggar.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_link">Extern länk</label></th>
          <td>
            <input id="kkb_link" name="link_url" type="url" class="regular-text" value="<?php echo esc_attr($formValues['link_url'] ?? ''); ?>" placeholder="https://exempel.se/">
            <p class="description">(Valfritt) Omsluter hela bannertexten i en länk.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_image">Bild</label></th>
          <td>
            <input id="kkb_image" type="file" name="banner_image" accept="image/*">
            <?php if (!empty($formValues['image_id']) && !empty($formValues['image_url'])): ?>
              <div style="margin-top:8px">
                <strong>Nuvarande bild:</strong><br>
                <img src="<?php echo esc_url($formValues['image_url']); ?>" alt="Förhandsgranskning" style="max-width:240px;height:auto;display:block;margin:6px 0;">
                <label><input type="checkbox" name="remove_image" value="1"> Ta bort bilden</label>
              </div>
            <?php endif; ?>
            <p class="description">(Valfritt) Bilden läggs till under texten.</p>
          </td>
        </tr>
        <tr>
          <th>Rum</th>
          <td>
            <?php foreach ($rooms as $r): $slug = (string)$r['slug']; ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="rooms[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $formValues['rooms_selected'] ?? [], true)); ?>>
                <?php echo esc_html($r['title']); ?> <code><?php echo esc_html($slug); ?></code>
              </label>
            <?php endforeach; ?>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_every">Intervall</label></th>
          <td>
            <input id="kkb_every" name="every_min" type="number" min="1" step="1" value="<?php echo (int)($formValues['every_min'] ?? 10); ?>" class="small-text"> minuter
            <p class="description">Hur ofta banderollen ska postas när schemat är aktivt.</p>
          </td>
        </tr>
        <tr>
          <th><label for="kkb_mode">Schematyp</label></th>
          <td>
            <select id="kkb_mode" name="schedule_mode">
              <option value="rolling" <?php selected(($formValues['schedule_mode'] ?? '')==='rolling'); ?>>Återkommande (utan tidsfönster)</option>
              <option value="weekly" <?php selected(($formValues['schedule_mode'] ?? '')==='weekly'); ?>>Veckodagar och tider</option>
              <option value="window" <?php selected(($formValues['schedule_mode'] ?? '')==='window'); ?>>Datumintervall</option>
            </select>
            <p class="description">Välj om banderollen ska ha ett tidsfönster eller gälla löpande.</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="weekly">
          <th>Veckodagar</th>
          <td>
            <?php foreach (kkchat_admin_weekday_labels() as $idx => $label): ?>
              <label style="display:inline-block;margin:4px 10px 4px 0">
                <input type="checkbox" name="weekdays[]" value="<?php echo (int)$idx; ?>" <?php checked(in_array((int)$idx, $formValues['weekdays'] ?? [], true)); ?>> <?php echo esc_html($label); ?>
              </label>
            <?php endforeach; ?>
            <p class="description">Banner postas endast dessa dagar.</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="weekly">
          <th>Tidsintervall</th>
          <td>
            <input type="time" name="weekly_start" value="<?php echo esc_attr($formValues['weekly_start'] ?? '08:00'); ?>"> – <input type="time" name="weekly_end" value="<?php echo esc_attr($formValues['weekly_end'] ?? '17:00'); ?>">
            <p class="description">Dagligt tidsfönster (lokal tid).</p>
          </td>
        </tr>
        <tr class="kkb-schedule" data-mode="window">
          <th>Datumintervall</th>
          <td>
            <label>Start <input type="datetime-local" name="window_start" value="<?php echo esc_attr($formValues['window_start'] ?? ''); ?>"></label>
            <label style="margin-left:12px">Slut <input type="datetime-local" name="window_end" value="<?php echo esc_attr($formValues['window_end'] ?? ''); ?>"></label>
            <p class="description">Banner postas endast mellan dessa datum/tider (lokal tid).</p>
          </td>
        </tr>
        <tr>
          <th>Synlighet</th>
          <td>
            <label>Från <input type="datetime-local" name="visible_from" value="<?php echo esc_attr($formValues['visible_from'] ?? ''); ?>"></label>
            <label style="margin-left:12px">Till <input type="datetime-local" name="visible_until" value="<?php echo esc_attr($formValues['visible_until'] ?? ''); ?>"></label>
            <p class="description">(Valfritt) Begränsa när banderollen får visas, oavsett schematyp.</p>
          </td>
        </tr>
      </table>
      <p>
        <button class="button button-primary" name="kk_save_banner" value="1"><?php echo $editing ? 'Uppdatera schema' : 'Spara schema'; ?></button>
        <?php if ($editing): ?><a class="button" href="<?php echo esc_url($banners_base); ?>">Avbryt redigering</a><?php endif; ?>
      </p>
    </form>

    <h2>Scheman</h2>
    <table class="widefat striped">
      <thead><tr><th>ID</th><th>Innehåll</th><th>Rum</th><th>Schema</th><th>Nästa körning</th><th>Aktiv</th><th>Åtgärder</th></tr></thead>
      <tbody>
        <?php if ($list): foreach ($list as $b): ?>
          <tr>
            <td><?php echo (int)$b->id; ?></td>
            <td><?php echo esc_html(mb_strimwidth(kkchat_admin_banner_plaintext($b->content_html ?? $b->content ?? ''), 0, 100, '…')); ?></td>
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
