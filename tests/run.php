<?php
$tests = require __DIR__ . '/SyncCleanupTest.php';

$total = count($tests);
$failures = [];

foreach ($tests as $name => $test) {
    try {
        $test();
        echo ".";
    } catch (Throwable $e) {
        $failures[$name] = $e->getMessage();
        echo "F";
    }
}

echo PHP_EOL;

echo sprintf("Ran %d tests\n", $total);

if ($failures) {
    echo sprintf("FAILED (%d failures)\n", count($failures));
    foreach ($failures as $name => $message) {
        echo sprintf(" - %s: %s\n", $name, $message);
    }
    exit(1);
}

echo "OK\n";
