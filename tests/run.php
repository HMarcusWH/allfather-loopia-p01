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
require __DIR__ . '/support/Seed.php';

use Allfather\Loopia\Ops\DeploymentEvidence;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;

$options = getopt('', ['layer::', 'strict', 'filter::', 'verbose', 'report-json::']);
$onlyLayer = isset($options['layer']) ? (string) $options['layer'] : null;
$filter = isset($options['filter']) ? (string) $options['filter'] : null;
$strict = isset($options['strict']);
$verbose = isset($options['verbose']);

/**
 * Where to write the machine-readable qualification report, if one was asked for.
 *
 * The HUMAN OUTPUT IS UNCHANGED whether or not this is set — a report flag that altered what an
 * operator reads on the terminal would make two runs of the same suite disagree about what
 * happened.
 *
 * The report carries counts, versions and timings. It carries NO credential, no DSN, no tenant
 * fixture, no SQL and no assertion message: a failure message in this projection routinely quotes a
 * governed value, and this file is designed to be uploaded as a CI artifact.
 */
$reportPath = isset($options['report-json']) ? (string) $options['report-json'] : null;
if ($reportPath !== null) {
    if ($reportPath === '') {
        fwrite(STDERR, "--report-json needs a path\n");
        exit(2);
    }
    $reportDir = dirname($reportPath);
    if (!is_dir($reportDir)) {
        fwrite(STDERR, "--report-json: no such directory: {$reportDir}\n");
        exit(2);
    }
    // THE GOVERNED FILES ARE REFUSED HERE, because this is the one writer in the repository that
    // takes a caller-chosen path and lives outside the tree the governed-writer tripwire scans.
    // `--report-json=upstream.lock.json` would otherwise pass the directory check — `dirname()` of a
    // bare name is `.`, which exists — and overwrite the lock with a test report.
    if (DeploymentEvidence::isGovernedPath(dirname(__DIR__), $reportPath)) {
        fwrite(STDERR, "--report-json refuses to write a governed file: {$reportPath}\n");
        exit(2);
    }
}

$layers = ['foundation', 'unit', 'integration', 'conformance', 'acceptance', 'security', 'rollback'];
if ($onlyLayer !== null && !in_array($onlyLayer, $layers, true)) {
    fwrite(STDERR, "unknown layer: {$onlyLayer}\n  known: " . implode(', ', $layers) . "\n");
    exit(2);
}

Harness::$verbose = $verbose;

$totals = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
$allFailures = [];
$allSkips = [];
/** @var array<string, array{pass: int, fail: int, skip: int, files: int, duration_ms: int}> $layerReport */
$layerReport = [];
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
    $ran = 0;
    foreach ($files as $file) {
        if ($filter !== null && !str_contains(basename($file), $filter)) {
            continue;
        }
        $ran++;
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
    $layerReport[$layer] = [
        'pass' => Assert::$passed,
        'fail' => Assert::$failed,
        'skip' => Assert::$skipped,
        'files' => $ran,
        'duration_ms' => (int) round($elapsed),
    ];
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

// ---------------------------------------------------------------- the machine-readable report
//
// WRITTEN BEFORE THE EXIT CODE IS DECIDED, deliberately. The run whose report matters most is the
// one that failed, and a report emitted only on success would be a report that never describes a
// failure.
if ($reportPath !== null) {
    $report = [
        'schema_version' => '1.0',
        'projection_id' => 'LOOPIA_SME_MVP',
        'php_version' => PHP_VERSION,
        // NULL when this run never opened a connection. A report that named a server no test in it
        // touched would be the same dishonesty `TEST_ENV_FILE` exists to prevent, one layer up.
        'database_server_version' => Harness::serverVersionIfConnected(),
        // WHAT WAS RUN, so a partial run cannot be read as a full one. `complete` is true only when
        // neither a layer nor a filter narrowed the suite — a `--layer=foundation` report uploaded
        // as release qualification would otherwise look like the whole thing passed.
        'complete' => $onlyLayer === null && $filter === null,
        'strict' => $strict,
        'layer_filter' => $onlyLayer,
        'file_filter' => $filter,
        'layers' => $layerReport,
        'total' => [
            'pass' => $totals['passed'],
            'fail' => $totals['failed'],
            'skip' => $totals['skipped'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ],
        // A SKIP IS NOT A PASS, and the report says so in its own field rather than leaving a
        // reader to subtract. Under --strict a skip is also a failing exit, and both facts are here.
        'skips_are_failures' => $strict,
        // Counts only. No assertion message, no failure text, no fixture: a failure message in this
        // projection routinely quotes a governed value, and this file is uploaded as an artifact.
        'failure_count' => count($allFailures),
        'skip_count' => count($allSkips),
    ];

    if (@file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
        fwrite(STDERR, "--report-json: could not write {$reportPath}\n");
        exit(2);
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
