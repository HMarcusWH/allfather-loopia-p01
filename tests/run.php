<?php

declare(strict_types=1);

/**
 * The Loopia projection test runner.
 *
 * Layers, in order of cost: unit (no DB) → integration → conformance → acceptance → security →
 * rollback. Run all with `composer test`, or one with `--layer=<name>`.
 *
 * MariaDB-backed layers are SKIPPED LOUDLY when no database is reachable. They are never reported
 * as passing, and a skip makes the exit code non-zero under `--strict` so CI cannot mistake an
 * unreachable database for a green suite.
 */

require __DIR__ . '/../app/autoload.php';
require __DIR__ . '/support/Assert.php';
require __DIR__ . '/support/Harness.php';

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;

$options = getopt('', ['layer::', 'strict', 'filter::', 'verbose']);
$onlyLayer = isset($options['layer']) ? (string) $options['layer'] : null;
$filter = isset($options['filter']) ? (string) $options['filter'] : null;
$strict = isset($options['strict']);
$verbose = isset($options['verbose']);

$layers = ['foundation', 'unit', 'integration', 'conformance', 'acceptance', 'security', 'rollback'];
if ($onlyLayer !== null && !in_array($onlyLayer, $layers, true)) {
    fwrite(STDERR, "unknown layer: {$onlyLayer}\n  known: " . implode(', ', $layers) . "\n");
    exit(2);
}

Harness::$verbose = $verbose;

$totals = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
$allFailures = [];
$allSkips = [];
$started = microtime(true);

foreach ($layers as $layer) {
    if ($onlyLayer !== null && $layer !== $onlyLayer) {
        continue;
    }
    $dir = __DIR__ . '/' . $layer;
    $files = is_dir($dir) ? (glob($dir . '/*.php') ?: []) : [];
    sort($files, SORT_STRING);
    if ($files === []) {
        continue;
    }

    Assert::reset();
    $layerStart = microtime(true);
    foreach ($files as $file) {
        if ($filter !== null && !str_contains(basename($file), $filter)) {
            continue;
        }
        Assert::case(basename($file, '.php'));
        try {
            (static function (string $path): void {
                require $path;
            })($file);
        } catch (\Throwable $error) {
            Assert::fail(sprintf('uncaught %s: %s @ %s:%d', $error::class, $error->getMessage(), $error->getFile(), $error->getLine()));
        }
    }

    $elapsed = (microtime(true) - $layerStart) * 1000;
    printf(
        "%-12s  pass=%-5d fail=%-5d skip=%-5d  (%.0f ms)\n",
        $layer,
        Assert::$passed,
        Assert::$failed,
        Assert::$skipped,
        $elapsed,
    );
    $totals['passed'] += Assert::$passed;
    $totals['failed'] += Assert::$failed;
    $totals['skipped'] += Assert::$skipped;
    foreach (Assert::$failures as $failure) {
        $allFailures[] = "[{$layer}] " . $failure;
    }
    foreach (Assert::$skips as $skip) {
        $allSkips[] = "[{$layer}] " . $skip;
    }
}

echo str_repeat('-', 72) . "\n";
printf(
    "TOTAL         pass=%-5d fail=%-5d skip=%-5d  (%.0f ms)\n",
    $totals['passed'],
    $totals['failed'],
    $totals['skipped'],
    (microtime(true) - $started) * 1000,
);

if ($allSkips !== []) {
    echo "\nSKIPPED (" . count($allSkips) . ") — these are NOT passes:\n";
    foreach ($allSkips as $skip) {
        echo "  ~ {$skip}\n";
    }
}

if ($allFailures !== []) {
    echo "\nFAILURES (" . count($allFailures) . "):\n";
    foreach ($allFailures as $failure) {
        echo "  x {$failure}\n";
    }
    exit(1);
}

if ($strict && $totals['skipped'] > 0) {
    echo "\n--strict: skipped tests are failures in this mode.\n";
    exit(1);
}

if ($totals['passed'] === 0) {
    fwrite(STDERR, "no tests ran — refusing to report success\n");
    exit(1);
}

echo "\nOK\n";
exit(0);
