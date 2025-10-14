<?php
if (!defined('ABSPATH')) exit;

// Define DB schema version if not already defined (bump when schema changes)
if (!defined('KKCHAT_DB_VERSION')) {
  define('KKCHAT_DB_VERSION', '10');
}

/**
 * Activation: create tables, seed data, schedule cron
 */
function kkchat_activate() {
  global $wpdb;
  $charset  = $wpdb->get_charset_collate();
  $t = kkchat_tables();

  // Seed default tunables (only if missing)
  foreach ([
    'kkchat_dupe_window_seconds'   => 120,
    'kkchat_dupe_fast_seconds'     => 30,
    'kkchat_dupe_max_repeats'      => 2,
    'kkchat_min_interval_seconds'  => 3,
    'kkchat_dupe_autokick_minutes' => 1,
    'kkchat_dedupe_window'         => 10,
  ] as $k => $def) {
    if (get_option($k) === false) add_option($k, $def);
  }

  // Messages (includes soft-delete columns + helpful composite indexes)
  $sql1 = "CREATE TABLE IF NOT EXISTS `{$t['messages']}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` INT UNSIGNED NOT NULL,
    `sender_id` INT UNSIGNED NOT NULL,
    `sender_name` VARCHAR(64) NOT NULL,
    `sender_ip` VARCHAR(45) NULL,
    `recipient_id` INT UNSIGNED NULL,
    `recipient_name` VARCHAR(64) NULL,
    `recipient_ip` VARCHAR(45) NULL,
    `room` VARCHAR(64) NULL,
    `kind` VARCHAR(16) NOT NULL DEFAULT 'chat',
    `content_hash` CHAR(40) NULL,
    `content` TEXT NOT NULL,
    `hidden_at` INT UNSIGNED NULL,
    `hidden_by` INT UNSIGNED NULL,
    `hidden_cause` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_recipient` (`recipient_id`),
    KEY `idx_sender` (`sender_id`),
    KEY `idx_room` (`room`),
    KEY `idx_sender_hash` (`sender_id`,`content_hash`,`created_at`),
    KEY `idx_room_id` (`room`,`id`),
    KEY `idx_sender_recipient_id` (`sender_id`,`recipient_id`,`id`),
    KEY `idx_recipient_sender_id` (`recipient_id`,`sender_id`,`id`),
    KEY `idx_hidden_at` (`hidden_at`),
    KEY `idx_room_hidden_id` (`room`,`hidden_at`,`id`),
    KEY `idx_recipient_hidden_id` (`recipient_id`,`hidden_at`,`id`)
  ) $charset;";

$sql2 = "CREATE TABLE IF NOT EXISTS `{$t['reads']}` (
  `message_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`message_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) $charset;";


  $sql3 = "CREATE TABLE IF NOT EXISTS `{$t['users']}` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL,
    `name_lc` VARCHAR(64) NOT NULL,
    `gender` VARCHAR(32) NOT NULL,
    `last_seen` INT UNSIGNED NOT NULL,
    `ip` VARCHAR(45) DEFAULT NULL,
    `wp_username` VARCHAR(64) DEFAULT NULL,
    `watch_flag` TINYINT(1) NOT NULL DEFAULT 0,
    `watch_flag_at` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_name_lc` (`name_lc`),
    KEY `idx_last_seen` (`last_seen`),
    KEY `idx_wpuser` (`wp_username`)
  ) $charset;";

  $sql4 = "CREATE TABLE IF NOT EXISTS `{$t['rooms']}` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(64) NOT NULL,
    `title` VARCHAR(64) NOT NULL,
    `member_only` TINYINT(1) NOT NULL DEFAULT 0,
    `sort` INT NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_slug` (`slug`),
    KEY `idx_sort` (`sort`)
  ) $charset;";

  $sql5 = "CREATE TABLE IF NOT EXISTS `{$t['banners']}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `content` TEXT NOT NULL,
    `rooms_csv` TEXT NOT NULL,
    `interval_sec` INT UNSIGNED NOT NULL,
    `next_run` INT UNSIGNED NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_next_active` (`next_run`,`active`)
  ) $charset;";

  $sql6 = "CREATE TABLE IF NOT EXISTS `{$t['blocks']}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` ENUM('kick','ipban') NOT NULL,
    `target_user_id` INT UNSIGNED NULL,
    `target_name` VARCHAR(64) NULL,
    `target_wp_username` VARCHAR(64) NULL,
    `target_ip` VARCHAR(45) NULL,
    `cause` TEXT NULL,
    `created_by` VARCHAR(64) NULL,
    `created_at` INT UNSIGNED NOT NULL,
    `expires_at` INT UNSIGNED NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_active_type` (`active`,`type`),
    KEY `idx_exp` (`expires_at`),
    KEY `idx_target_ip` (`target_ip`),
    KEY `idx_target_wp` (`target_wp_username`)
  ) $charset;";

  $sql7 = "CREATE TABLE IF NOT EXISTS `{$t['rules']}`(
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `word` VARCHAR(128) NOT NULL,
    `kind` ENUM('forbid','watch') NOT NULL DEFAULT 'forbid',
    `match_type` ENUM('contains','exact','regex') NOT NULL DEFAULT 'contains',
    `action` ENUM('kick','ipban') DEFAULT NULL,
    `duration_sec` INT UNSIGNED NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT NULL,
    `created_by` VARCHAR(64) DEFAULT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY(`id`),
    KEY `idx_enabled_kind` (`enabled`,`kind`)
  ) $charset;";

  // Reports table
  $sql8 = "CREATE TABLE IF NOT EXISTS `{$t['reports']}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` INT UNSIGNED NOT NULL,
    `reporter_id` INT UNSIGNED NOT NULL,
    `reporter_name` VARCHAR(64) NOT NULL,
    `reporter_ip` VARCHAR(45) NULL,
    `reported_id` INT UNSIGNED NOT NULL,
    `reported_name` VARCHAR(64) NOT NULL,
    `reported_ip` VARCHAR(45) NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('open','resolved') NOT NULL DEFAULT 'open',
    `resolved_at` INT UNSIGNED DEFAULT NULL,
    `resolved_by` VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status_created` (`status`,`created_at`),
    KEY `idx_reported_id` (`reported_id`),
    KEY `idx_reporter_id` (`reporter_id`)
  ) $charset;";

  // Per-user blocks (matches kkchat_block_* helpers)
  $tbl_user_blocks = $t['user_blocks'];
  $sql9 = "CREATE TABLE IF NOT EXISTS `{$tbl_user_blocks}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `blocker_id` INT UNSIGNED NOT NULL,
    `target_id`  INT UNSIGNED NOT NULL,
    `active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` INT UNSIGNED NOT NULL,
    `updated_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_pair` (`blocker_id`,`target_id`),
    KEY `idx_blocker` (`blocker_id`),
    KEY `idx_target` (`target_id`),
    KEY `idx_active` (`active`)
  ) $charset;";

  $videos_table = $t['videos'];
  $sql10 = "CREATE TABLE IF NOT EXISTS `{$videos_table}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` INT UNSIGNED NOT NULL,
    `updated_at` INT UNSIGNED NOT NULL,
    `processed_at` INT UNSIGNED NULL,
    `uploader_id` INT UNSIGNED NOT NULL,
    `object_key` VARCHAR(255) NOT NULL,
    `object_size` BIGINT UNSIGNED NULL,
    `expected_bytes` BIGINT UNSIGNED NULL,
    `expected_mime` VARCHAR(128) NULL,
    `mime_type` VARCHAR(128) NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'pending_upload',
    `duration_seconds` DECIMAL(10,2) NULL,
    `width_px` INT UNSIGNED NULL,
    `height_px` INT UNSIGNED NULL,
    `thumbnail_key` VARCHAR(255) NULL,
    `thumbnail_url` TEXT NULL,
    `thumbnail_mime` VARCHAR(64) NULL,
    `thumbnail_bytes` INT UNSIGNED NULL,
    `public_url` TEXT NULL,
    `failure_code` VARCHAR(64) NULL,
    `failure_message` TEXT NULL,
    `upload_token_hash` CHAR(64) NULL,
    `upload_expires_at` INT UNSIGNED NULL,
    `message_id` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_uploader_status` (`uploader_id`,`status`),
    KEY `idx_message` (`message_id`),
    KEY `idx_object_key` (`object_key`(191))
  ) $charset;";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql1); dbDelta($sql2); dbDelta($sql3); dbDelta($sql4);
  dbDelta($sql5); dbDelta($sql6); dbDelta($sql7); dbDelta($sql8);
  dbDelta($sql9); dbDelta($sql10);

  // Remove legacy typing columns if they linger after dbDelta
  foreach (['typing_text', 'typing_room', 'typing_to', 'typing_at'] as $col) {
    if (kkchat_column_exists($t['users'], $col)) {
      $wpdb->query("ALTER TABLE `{$t['users']}` DROP COLUMN `{$col}`");
    }
  }

  // Seed default room if none exists
  $has_rooms = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$t['rooms']}`");
  if (!$has_rooms) {
    $wpdb->insert($t['rooms'], ['slug'=>'lobby','title'=>'Lobby','member_only'=>0,'sort'=>10], ['%s','%s','%d','%d']);
  }

  // Schedule cron (for banners)
  if (!wp_next_scheduled('kkchat_cron_tick')) {
    wp_schedule_event(time() + 30, 'kkchat_minutely', 'kkchat_cron_tick');
  }

  // Default settings option
  if (get_option('kkchat_admin_users') === false) {
    add_option('kkchat_admin_users', "");
  }
}

function kkchat_table_exists($table){
  global $wpdb;
  $exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
    $table
  ));
  return !empty($exists);
}

function kkchat_column_exists($table, $column){
  global $wpdb;
  $exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
    $table, $column
  ));
  return !empty($exists);
}

// Ensures schema is present and soft-delete columns exist (version-gated)
function kkchat_maybe_migrate(){
  global $wpdb;
  if (!function_exists('kkchat_tables')) return; // safety
  $t = kkchat_tables();

  // 1) Ensure messages table exists (fallback to installer)
  if (!kkchat_table_exists($t['messages']) && function_exists('kkchat_activate')) {
    kkchat_activate();
  }

  // 2) Add soft-delete columns / indexes if missing
  if (kkchat_table_exists($t['messages'])) {
    if (!kkchat_column_exists($t['messages'], 'hidden_at')) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD COLUMN `hidden_at` INT UNSIGNED NULL AFTER `content`");
    }
    if (!kkchat_column_exists($t['messages'], 'hidden_by')) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD COLUMN `hidden_by` INT UNSIGNED NULL AFTER `hidden_at`");
    }
    if (!kkchat_column_exists($t['messages'], 'hidden_cause')) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD COLUMN `hidden_cause` VARCHAR(255) NULL AFTER `hidden_by`");
    }

    // Index helpers
    $has_index = function($name) use ($wpdb, $t){
      return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s LIMIT 1",
         $t['messages'], $name
      ));
    };

    // Either idx_hidden_at OR legacy idx_hidden counts
    $has_hidden_at = $has_index('idx_hidden_at') || $has_index('idx_hidden');
    if (!$has_hidden_at) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD INDEX `idx_hidden_at` (`hidden_at`)");
    }
    if (!$has_index('idx_room_hidden_id')) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD INDEX `idx_room_hidden_id` (`room`,`hidden_at`,`id`)");
    }
    if (!$has_index('idx_recipient_hidden_id')) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD INDEX `idx_recipient_hidden_id` (`recipient_id`,`hidden_at`,`id`)");
    }
    if (!$has_index('idx_room_id')) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD INDEX `idx_room_id` (`room`,`id`)");
    }
    if (!$has_index('idx_sender_recipient_id')) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD INDEX `idx_sender_recipient_id` (`sender_id`,`recipient_id`,`id`)");
    }
    if (!$has_index('idx_recipient_sender_id')) {
      $wpdb->query("ALTER TABLE `{$t['messages']}` ADD INDEX `idx_recipient_sender_id` (`recipient_id`,`sender_id`,`id`)");
    }
  }

  if (!kkchat_table_exists($t['videos'])) {
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $tbl = $t['videos'];
    $sql = "CREATE TABLE IF NOT EXISTS `{$tbl}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `created_at` INT UNSIGNED NOT NULL,
      `updated_at` INT UNSIGNED NOT NULL,
      `processed_at` INT UNSIGNED NULL,
      `uploader_id` INT UNSIGNED NOT NULL,
      `object_key` VARCHAR(255) NOT NULL,
      `object_size` BIGINT UNSIGNED NULL,
      `expected_bytes` BIGINT UNSIGNED NULL,
      `expected_mime` VARCHAR(128) NULL,
      `mime_type` VARCHAR(128) NULL,
      `status` VARCHAR(32) NOT NULL DEFAULT 'pending_upload',
      `duration_seconds` DECIMAL(10,2) NULL,
      `width_px` INT UNSIGNED NULL,
      `height_px` INT UNSIGNED NULL,
      `thumbnail_key` VARCHAR(255) NULL,
      `thumbnail_url` TEXT NULL,
      `thumbnail_mime` VARCHAR(64) NULL,
      `thumbnail_bytes` INT UNSIGNED NULL,
      `public_url` TEXT NULL,
      `failure_code` VARCHAR(64) NULL,
      `failure_message` TEXT NULL,
      `upload_token_hash` CHAR(64) NULL,
      `upload_expires_at` INT UNSIGNED NULL,
      `message_id` BIGINT UNSIGNED NULL,
      PRIMARY KEY (`id`),
      KEY `idx_status` (`status`),
      KEY `idx_uploader_status` (`uploader_id`,`status`),
      KEY `idx_message` (`message_id`),
      KEY `idx_object_key` (`object_key`(191))
    ) $charset;";
    dbDelta($sql);
  }

  // 3) Save version so we don’t re-run unnecessarily
  update_option('kkchat_db_version', KKCHAT_DB_VERSION);
}

// Run once on load if version bumped or option missing
add_action('plugins_loaded', function(){
  $cur = get_option('kkchat_db_version');
  if ($cur !== KKCHAT_DB_VERSION) {
    kkchat_maybe_migrate();
  }
});

/**
 * Deactivation: unschedule cron
 */
function kkchat_deactivate(){
  // Remove *all* scheduled events for this hook
  if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('kkchat_cron_tick');
  } else {
    $ts = wp_next_scheduled('kkchat_cron_tick');
    if ($ts) wp_unschedule_event($ts, 'kkchat_cron_tick');
  }
}

/**
 * Add a minutely schedule for KKchat
 */
add_filter('cron_schedules', function($s){
  if (!isset($s['kkchat_minutely'])) $s['kkchat_minutely'] = ['interval'=>60, 'display'=>'KKchat every minute'];
  return $s;
});

// Self-healing: (re)seed the event if it ever goes missing
add_action('init', function () {
  if (!wp_next_scheduled('kkchat_cron_tick')) {
    wp_schedule_event(time() + 60, 'kkchat_minutely', 'kkchat_cron_tick');
  }
});

/**
 * Cron runner for scheduled banners
 */
add_action('kkchat_cron_tick', 'kkchat_run_scheduled_banners');
function kkchat_run_scheduled_banners(){
  global $wpdb; $t = kkchat_tables();
  $now = time();

  // Fetch due, active banners
  $rows = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM {$t['banners']} WHERE active=1 AND next_run <= %d", $now),
    ARRAY_A
  );
  if (!$rows) return;

  // ① Build maps: valid slugs and lowercase title->slug
  $rows_rooms = $wpdb->get_results("SELECT slug, title FROM {$t['rooms']}", ARRAY_A);
  $valid = []; $by_title = [];
  if ($rows_rooms) {
    foreach ($rows_rooms as $rm) {
      $slug = (string)$rm['slug'];
      $valid[$slug] = true;
      $by_title[mb_strtolower(trim((string)$rm['title']), 'UTF-8')] = $slug;
    }
  }

  foreach ($rows as $r) {
    $attempts  = 0;
    $successes = 0;

    // ② Parse tokens as entered (accept titles OR slugs)
    $tokens = array_filter(array_map('trim', explode(',', (string)$r['rooms_csv'])));
    $rooms  = [];
    foreach ($tokens as $tok) {
      $slug = kkchat_sanitize_room_slug($tok);            // try as slug first
      if (isset($valid[$slug])) { $rooms[] = $slug; continue; }
      $key = mb_strtolower($tok, 'UTF-8');                // else try by title
      if (isset($by_title[$key])) $rooms[] = $by_title[$key];
    }
    $rooms = array_values(array_unique($rooms));

    if (!$tokens) {
      error_log("[KKchat] Banner #{$r['id']} has empty rooms_csv");
    } elseif (!$rooms) {
      error_log("[KKchat] Banner #{$r['id']} rooms not found: {$r['rooms_csv']}");
    } else {
      // Insert one banner message per valid room
      $content = (string)$r['content'];
      foreach ($rooms as $slug) {
        $attempts++;
        $ok = $wpdb->insert($t['messages'], [
          'created_at'  => $now,
          'sender_id'   => 0,
          'sender_name' => 'System',
          'room'        => $slug,
          'kind'        => 'banner',
          'content'     => $content,
        ], ['%d','%d','%s','%s','%s','%s']);

        if ($ok === false) {
          error_log('[KKchat] Banner insert failed (room='.$slug.'): '.$wpdb->last_error);
        } else {
          $successes++;
        }
      }
    }

    // Always stamp last_run
    $fields = ['last_run' => $now];

    // Advance when nothing was attempted (misconfig) OR at least one insert succeeded.
    // Only hold back (retry soon) when we tried inserts and ALL of them failed.
    if ($attempts === 0 || $successes > 0) {
      $fields['next_run'] = $now + max(60, (int)$r['interval_sec']);
    }

    $wpdb->update(
      $t['banners'],
      $fields,
      ['id' => (int)$r['id']],
      array_fill(0, count($fields), '%d'),
      ['%d']
    );
  }
}

/**
 * Runtime schema check / upgrades after updates (always runs)
 */
add_action('plugins_loaded', 'kkchat_maybe_upgrade_schema');
add_action('plugins_loaded', 'kkchat_maybe_upgrade_schema');
function kkchat_maybe_upgrade_schema() {
  global $wpdb; $t = kkchat_tables();
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  $charset = $wpdb->get_charset_collate();

  // Helpers
  $table_exists = function(string $tbl) use ($wpdb) {
    return (bool)$wpdb->get_var($wpdb->prepare(
      "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
      $tbl
    ));
  };
  $has_index = function($tbl, $name) use ($wpdb) {
    return (bool)$wpdb->get_var($wpdb->prepare(
      "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s LIMIT 1",
       $tbl, $name
    ));
  };

  /* =========================
   * Ensure core tables exist
   * ========================= */

  // messages
  if (!$table_exists($t['messages'])) {
    $sql1 = "CREATE TABLE IF NOT EXISTS `{$t['messages']}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `created_at` INT UNSIGNED NOT NULL,
      `sender_id` INT UNSIGNED NOT NULL,
      `sender_name` VARCHAR(64) NOT NULL,
      `sender_ip` VARCHAR(45) NULL,
      `recipient_id` INT UNSIGNED NULL,
      `recipient_name` VARCHAR(64) NULL,
      `recipient_ip` VARCHAR(45) NULL,
      `room` VARCHAR(64) NULL,
      `kind` VARCHAR(16) NOT NULL DEFAULT 'chat',
      `content_hash` CHAR(40) NULL,
      `content` TEXT NOT NULL,
      `hidden_at` INT UNSIGNED NULL,
      `hidden_by` INT UNSIGNED NULL,
      `hidden_cause` VARCHAR(255) NULL,
      PRIMARY KEY (`id`),
      KEY `idx_created` (`created_at`),
      KEY `idx_recipient` (`recipient_id`),
      KEY `idx_sender` (`sender_id`),
      KEY `idx_room` (`room`),
      KEY `idx_sender_hash` (`sender_id`,`content_hash`,`created_at`),
      KEY `idx_room_id` (`room`,`id`),
      KEY `idx_sender_recipient_id` (`sender_id`,`recipient_id`,`id`),
      KEY `idx_recipient_sender_id` (`recipient_id`,`sender_id`,`id`),
      KEY `idx_hidden_at` (`hidden_at`),
      KEY `idx_room_hidden_id` (`room`,`hidden_at`,`id`),
      KEY `idx_recipient_hidden_id` (`recipient_id`,`hidden_at`,`id`)
    ) $charset;";
    dbDelta($sql1);
  }

  // reads (now includes created_at + idx_created)
  if (!$table_exists($t['reads'])) {
    $sql2 = "CREATE TABLE IF NOT EXISTS `{$t['reads']}` (
      `message_id` BIGINT UNSIGNED NOT NULL,
      `user_id` INT UNSIGNED NOT NULL,
      `created_at` INT UNSIGNED NOT NULL,
      PRIMARY KEY (`message_id`,`user_id`),
      KEY `idx_user` (`user_id`),
      KEY `idx_created` (`created_at`)
    ) $charset;";
    dbDelta($sql2);
  } else {
    // Legacy upgrades for reads
    $has_reads_created = $wpdb->get_var("SHOW COLUMNS FROM {$t['reads']} LIKE 'created_at'");
    if (!$has_reads_created) {
      @ $wpdb->query("ALTER TABLE `{$t['reads']}` ADD `created_at` INT UNSIGNED NOT NULL DEFAULT 0");
    }
    if (!$has_index($t['reads'], 'idx_user')) {
      @ $wpdb->query("ALTER TABLE `{$t['reads']}` ADD KEY `idx_user` (`user_id`)");
    }
    if (!$has_index($t['reads'], 'idx_created')) {
      @ $wpdb->query("ALTER TABLE `{$t['reads']}` ADD KEY `idx_created` (`created_at`)");
    }
  }

  // users (active users/presence)
  if (!$table_exists($t['users'])) {
    $sql3 = "CREATE TABLE IF NOT EXISTS `{$t['users']}` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(64) NOT NULL,
      `name_lc` VARCHAR(64) NOT NULL,
      `gender` VARCHAR(32) NOT NULL,
      `last_seen` INT UNSIGNED NOT NULL,
      `ip` VARCHAR(45) DEFAULT NULL,
      `wp_username` VARCHAR(64) DEFAULT NULL,
      `watch_flag` TINYINT(1) NOT NULL DEFAULT 0,
      `watch_flag_at` INT UNSIGNED NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_name_lc` (`name_lc`),
      KEY `idx_last_seen` (`last_seen`),
      KEY `idx_wpuser` (`wp_username`)
    ) $charset;";
    dbDelta($sql3);
  }

  // rooms
  if (!$table_exists($t['rooms'])) {
    $sql4 = "CREATE TABLE IF NOT EXISTS `{$t['rooms']}` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `slug` VARCHAR(64) NOT NULL,
      `title` VARCHAR(64) NOT NULL,
      `member_only` TINYINT(1) NOT NULL DEFAULT 0,
      `sort` INT NOT NULL DEFAULT 100,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_slug` (`slug`),
      KEY `idx_sort` (`sort`)
    ) $charset;";
    dbDelta($sql4);
  }

  // banners
  if (!$table_exists($t['banners'])) {
    $sql5 = "CREATE TABLE IF NOT EXISTS `{$t['banners']}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `content` TEXT NOT NULL,
      `rooms_csv` TEXT NOT NULL,
      `interval_sec` INT UNSIGNED NOT NULL,
      `next_run` INT UNSIGNED NOT NULL,
      `active` TINYINT(1) NOT NULL DEFAULT 1,
      `last_run` INT UNSIGNED NULL,
      PRIMARY KEY (`id`),
      KEY `idx_next_active` (`next_run`,`active`)
    ) $charset;";
    dbDelta($sql5);
  }

  // blocks (kicks/ipbans)
  if (!$table_exists($t['blocks'])) {
    $sql6 = "CREATE TABLE IF NOT EXISTS `{$t['blocks']}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `type` ENUM('kick','ipban') NOT NULL,
      `target_user_id` INT UNSIGNED NULL,
      `target_name` VARCHAR(64) NULL,
      `target_wp_username` VARCHAR(64) NULL,
      `target_ip` VARCHAR(45) NULL,
      `cause` TEXT NULL,
      `created_by` VARCHAR(64) NULL,
      `created_at` INT UNSIGNED NOT NULL,
      `expires_at` INT UNSIGNED NULL,
      `active` TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (`id`),
      KEY `idx_active_type` (`active`,`type`),
      KEY `idx_exp` (`expires_at`),
      KEY `idx_target_ip` (`target_ip`),
      KEY `idx_target_wp` (`target_wp_username`)
    ) $charset;";
    dbDelta($sql6);
  }

  // rules
  if (!$table_exists($t['rules'])) {
    $sql7 = "CREATE TABLE IF NOT EXISTS `{$t['rules']}`(
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `word` VARCHAR(128) NOT NULL,
      `kind` ENUM('forbid','watch') NOT NULL DEFAULT 'forbid',
      `match_type` ENUM('contains','exact','regex') NOT NULL DEFAULT 'contains',
      `action` ENUM('kick','ipban') DEFAULT NULL,
      `duration_sec` INT UNSIGNED NULL,
      `enabled` TINYINT(1) NOT NULL DEFAULT 1,
      `notes` TEXT NULL,
      `created_by` VARCHAR(64) DEFAULT NULL,
      `created_at` INT UNSIGNED NOT NULL,
      PRIMARY KEY(`id`),
      KEY `idx_enabled_kind` (`enabled`,`kind`)
    ) $charset;";
    dbDelta($sql7);
  }

  // reports
  if (!$table_exists($t['reports'])) {
    $sql8 = "CREATE TABLE IF NOT EXISTS `{$t['reports']}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `created_at` INT UNSIGNED NOT NULL,
      `reporter_id` INT UNSIGNED NOT NULL,
      `reporter_name` VARCHAR(64) NOT NULL,
      `reporter_ip` VARCHAR(45) NULL,
      `reported_id` INT UNSIGNED NOT NULL,
      `reported_name` VARCHAR(64) NOT NULL,
      `reported_ip` VARCHAR(45) NULL,
      `reason` TEXT NOT NULL,
      `status` ENUM('open','resolved') NOT NULL DEFAULT 'open',
      `resolved_at` INT UNSIGNED DEFAULT NULL,
      `resolved_by` VARCHAR(64) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_status_created` (`status`,`created_at`),
      KEY `idx_reported_id` (`reported_id`),
      KEY `idx_reporter_id` (`reporter_id`)
    ) $charset;";
    dbDelta($sql8);
  } else {
    $has_resolved_at = $wpdb->get_var("SHOW COLUMNS FROM {$t['reports']} LIKE 'resolved_at'");
    if (!$has_resolved_at) { @ $wpdb->query("ALTER TABLE {$t['reports']} ADD `resolved_at` INT UNSIGNED DEFAULT NULL"); }
    $has_resolved_by = $wpdb->get_var("SHOW COLUMNS FROM {$t['reports']} LIKE 'resolved_by'");
    if (!$has_resolved_by) { @ $wpdb->query("ALTER TABLE {$t['reports']} ADD `resolved_by` VARCHAR(64) DEFAULT NULL"); }
  }

  // user_blocks
  $tbl_user_blocks = $t['user_blocks'];
  if (!$table_exists($tbl_user_blocks)) {
    $sql9 = "CREATE TABLE IF NOT EXISTS `{$tbl_user_blocks}` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `blocker_id` INT UNSIGNED NOT NULL,
      `target_id`  INT UNSIGNED NOT NULL,
      `active`     TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` INT UNSIGNED NOT NULL,
      `updated_at` INT UNSIGNED NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_pair` (`blocker_id`,`target_id`),
      KEY `idx_blocker` (`blocker_id`),
      KEY `idx_target` (`target_id`),
      KEY `idx_active` (`active`)
    ) $charset;";
    dbDelta($sql9);
  } else {
    // Migrate old schemas gently
    $has_target  = $wpdb->get_var("SHOW COLUMNS FROM {$tbl_user_blocks} LIKE 'target_id'");
    $has_blocked = $wpdb->get_var("SHOW COLUMNS FROM {$tbl_user_blocks} LIKE 'blocked_id'");
    if (!$has_target && $has_blocked) {
      @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} ADD `target_id` INT UNSIGNED NULL AFTER `blocker_id`");
      @ $wpdb->query("UPDATE {$tbl_user_blocks} SET `target_id` = `blocked_id` WHERE `target_id` IS NULL");
    }
    $has_active = $wpdb->get_var("SHOW COLUMNS FROM {$tbl_user_blocks} LIKE 'active'");
    if (!$has_active) {
      @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} ADD `active` TINYINT(1) NOT NULL DEFAULT 1");
      @ $wpdb->query("UPDATE {$tbl_user_blocks} SET `active` = 1 WHERE `active` IS NULL");
    }
    $has_updated = $wpdb->get_var("SHOW COLUMNS FROM {$tbl_user_blocks} LIKE 'updated_at'");
    if (!$has_updated) {
      @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} ADD `updated_at` INT UNSIGNED NOT NULL DEFAULT 0");
      @ $wpdb->query("UPDATE {$tbl_user_blocks} SET `updated_at` = IFNULL(`created_at`, UNIX_TIMESTAMP())");
    }
    $has_id = $wpdb->get_var("SHOW COLUMNS FROM {$tbl_user_blocks} LIKE 'id'");
    if (!$has_id) {
      @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} DROP PRIMARY KEY");
      @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} ADD `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
    }
    // Ensure indexes
    $has_uniq = $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_pair'",
      $tbl_user_blocks
    ));
    if (!$has_uniq) { @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} ADD UNIQUE KEY `uniq_pair` (`blocker_id`,`target_id`)"); }
    if (!$has_index($tbl_user_blocks, 'idx_blocker')) { @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} ADD KEY `idx_blocker` (`blocker_id`)"); }
    if (!$has_index($tbl_user_blocks, 'idx_target'))  { @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} ADD KEY `idx_target` (`target_id`)"); }
    if (!$has_index($tbl_user_blocks, 'idx_active'))  { @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} ADD KEY `idx_active` (`active`)"); }
    if ($has_blocked) { @ $wpdb->query("ALTER TABLE {$tbl_user_blocks} DROP COLUMN `blocked_id`"); }
  }

  /* =========================================
   * Column/index upgrades for existing tables
   * ========================================= */

  // messages: add columns if missing
  $has_kind  = $wpdb->get_var("SHOW COLUMNS FROM {$t['messages']} LIKE 'kind'");
  if (!$has_kind) { @ $wpdb->query("ALTER TABLE {$t['messages']} ADD `kind` VARCHAR(16) NOT NULL DEFAULT 'chat'"); }
  $has_sip   = $wpdb->get_var("SHOW COLUMNS FROM {$t['messages']} LIKE 'sender_ip'");
  if (!$has_sip) { @ $wpdb->query("ALTER TABLE {$t['messages']} ADD `sender_ip` VARCHAR(45) NULL"); }
  $has_rip   = $wpdb->get_var("SHOW COLUMNS FROM {$t['messages']} LIKE 'recipient_ip'");
  if (!$has_rip) { @ $wpdb->query("ALTER TABLE {$t['messages']} ADD `recipient_ip` VARCHAR(45) NULL"); }
  $has_rname = $wpdb->get_var("SHOW COLUMNS FROM {$t['messages']} LIKE 'recipient_name'");
  if (!$has_rname){ @ $wpdb->query("ALTER TABLE {$t['messages']} ADD `recipient_name` VARCHAR(64) NULL"); }
  $has_ch    = $wpdb->get_var("SHOW COLUMNS FROM {$t['messages']} LIKE 'content_hash'");
  if (!$has_ch) { @ $wpdb->query("ALTER TABLE {$t['messages']} ADD `content_hash` CHAR(40) NULL"); }

  // hidden_* columns (idempotent)
  if (!$wpdb->get_var("SHOW COLUMNS FROM {$t['messages']} LIKE 'hidden_at'")) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD `hidden_at` INT UNSIGNED NULL AFTER `content`");
  }
  if (!$wpdb->get_var("SHOW COLUMNS FROM {$t['messages']} LIKE 'hidden_by'")) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD `hidden_by` INT UNSIGNED NULL AFTER `hidden_at`");
  }
  if (!$wpdb->get_var("SHOW COLUMNS FROM {$t['messages']} LIKE 'hidden_cause'")) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD `hidden_cause` VARCHAR(255) NULL AFTER `hidden_by`");
  }

  // messages: ensure useful indexes (legacy hardening)
  if (!$has_index($t['messages'], 'idx_sender_hash')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_sender_hash` (`sender_id`,`content_hash`,`created_at`)");
  }
  if (!$has_index($t['messages'], 'idx_created')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_created` (`created_at`)");
  }
  if (!$has_index($t['messages'], 'idx_sender')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_sender` (`sender_id`)");
  }
  if (!$has_index($t['messages'], 'idx_recipient')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_recipient` (`recipient_id`)");
  }
  if (!$has_index($t['messages'], 'idx_room')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_room` (`room`)");
  }
  // Either idx_hidden_at OR legacy idx_hidden
  $has_hidden_at = $has_index($t['messages'], 'idx_hidden_at') || $has_index($t['messages'], 'idx_hidden');
  if (!$has_hidden_at) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_hidden_at` (`hidden_at`)");
  }
  if (!$has_index($t['messages'], 'idx_room_hidden_id')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_room_hidden_id` (`room`,`hidden_at`,`id`)");
  }
  if (!$has_index($t['messages'], 'idx_recipient_hidden_id')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_recipient_hidden_id` (`recipient_id`,`hidden_at`,`id`)");
  }
  if (!$has_index($t['messages'], 'idx_room_id')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_room_id` (`room`,`id`)");
  }
  if (!$has_index($t['messages'], 'idx_sender_recipient_id')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_sender_recipient_id` (`sender_id`,`recipient_id`,`id`)");
  }
  if (!$has_index($t['messages'], 'idx_recipient_sender_id')) {
    @ $wpdb->query("ALTER TABLE {$t['messages']} ADD KEY `idx_recipient_sender_id` (`recipient_id`,`sender_id`,`id`)");
  }

  // users: add modern columns if missing
  $has_ip = $wpdb->get_var("SHOW COLUMNS FROM {$t['users']} LIKE 'ip'");
  if (!$has_ip) { @ $wpdb->query("ALTER TABLE {$t['users']} ADD `ip` VARCHAR(45) DEFAULT NULL"); }
  $has_wp = $wpdb->get_var("SHOW COLUMNS FROM {$t['users']} LIKE 'wp_username'");
  if (!$has_wp) { @ $wpdb->query("ALTER TABLE {$t['users']} ADD `wp_username` VARCHAR(64) DEFAULT NULL, ADD KEY `idx_wpuser` (`wp_username`)"); }

  $has_wf = $wpdb->get_var("SHOW COLUMNS FROM {$t['users']} LIKE 'watch_flag'");
  if (!$has_wf) {
    @ $wpdb->query("ALTER TABLE {$t['users']}
      ADD `watch_flag` TINYINT(1) NOT NULL DEFAULT 0,
      ADD `watch_flag_at` INT UNSIGNED NULL");
  }

  // users: drop legacy typing columns (formerly used by /typing endpoint)
  foreach (['typing_text', 'typing_room', 'typing_to', 'typing_at'] as $col) {
    if (kkchat_column_exists($t['users'], $col)) {
      @ $wpdb->query("ALTER TABLE {$t['users']} DROP COLUMN `{$col}`");
    }
  }

  /* =========================
   * Seed defaults / options
   * ========================= */

  // Default room
  if ($table_exists($t['rooms'])) {
    $has_rooms = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['rooms']}");
    if (!$has_rooms) {
      $wpdb->insert($t['rooms'], ['slug'=>'lobby','title'=>'Lobby','member_only'=>0,'sort'=>10], ['%s','%s','%d','%d']);
    }
  }

  // Tunables
  foreach ([
    'kkchat_dupe_window_seconds'   => 120,
    'kkchat_dupe_fast_seconds'     => 30,
    'kkchat_dupe_max_repeats'      => 2,
    'kkchat_min_interval_seconds'  => 3,
    'kkchat_dupe_autokick_minutes' => 1,
    'kkchat_dedupe_window'         => 10,
  ] as $k => $def) {
    if (get_option($k) === false) add_option($k, $def);
  }

  // Admin list option (idempotent)
  if (get_option('kkchat_admin_users') === false) {
    add_option('kkchat_admin_users', "");
  }
}
