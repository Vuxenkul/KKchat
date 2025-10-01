<?php
$tests = [];

require __DIR__ . '/bootstrap.php';

$tests['cleanup_runs_initially'] = function () {
    $store = 0;
    $ran = kkchat_sync_cleanup_should_run(
        100,
        5,
        function () use (&$store) {
            return $store;
        },
        function (int $ts) use (&$store): void {
            $store = $ts;
        }
    );

    kkchat_test_assert_true($ran, 'Cleanup should run on first invocation');
    kkchat_test_assert_same(100, $store, 'Cleanup timestamp should update');
};

$tests['cleanup_throttles_within_interval'] = function () {
    $store = 100;
    $ran = kkchat_sync_cleanup_should_run(
        103,
        5,
        function () use (&$store) {
            return $store;
        },
        function (int $ts) use (&$store): void {
            $store = $ts;
        }
    );

    kkchat_test_assert_true(!$ran, 'Cleanup should be skipped within interval');
    kkchat_test_assert_same(100, $store, 'Timestamp should remain unchanged when throttled');
};

$tests['cleanup_resumes_after_interval'] = function () {
    $store = 100;
    $ran = kkchat_sync_cleanup_should_run(
        108,
        5,
        function () use (&$store) {
            return $store;
        },
        function (int $ts) use (&$store): void {
            $store = $ts;
        }
    );

    kkchat_test_assert_true($ran, 'Cleanup should resume after interval passes');
    kkchat_test_assert_same(108, $store, 'Timestamp should update after resumed cleanup');
};

$tests['cleanup_runs_when_interval_disabled'] = function () {
    $store = 42;
    $ran = kkchat_sync_cleanup_should_run(
        200,
        0,
        function () use (&$store) {
            return $store;
        },
        function (int $ts) use (&$store): void {
            $store = $ts;
        }
    );

    kkchat_test_assert_true($ran, 'Cleanup should run when interval is disabled');
    kkchat_test_assert_same(42, $store, 'Timestamp should not change when interval disabled and setter skipped');
};

$tests['presence_rows_respect_ttl_filter'] = function () {
    $rows = [
        ['id' => 1, 'last_seen' => 190, 'watch_flag' => 0],
        ['id' => 2, 'last_seen' => 120, 'watch_flag' => 0],
    ];
    $now = 200;
    $filtered = kkchat_filter_presence_rows_by_ttl($rows, $now, 30);

    kkchat_test_assert_same(1, count($filtered), 'Only one row should remain after TTL filtering');
    kkchat_test_assert_same(1, $filtered[0]['id'], 'Old presence row should be filtered out');
};

$tests['presence_flagged_resets_after_ttl'] = function () {
    $row = ['watch_flag' => 1, 'watch_flag_at' => 50];
    $now = 120;

    kkchat_test_assert_same(0, kkchat_presence_flagged_status($row, $now), 'Watch flag should reset after TTL');
};

$tests['presence_flagged_stays_when_recent'] = function () {
    $row = ['watch_flag' => 1, 'watch_flag_at' => time()];
    $now = $row['watch_flag_at'] + 10;

    kkchat_test_assert_same(1, kkchat_presence_flagged_status($row, $now), 'Recent watch flag should stay active');
};

return $tests;
