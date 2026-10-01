<?php

declare(strict_types=1);

/**
 * P06 deployed-Monitor boundary smoke.
 *
 * Thin wrapper only. The hardened HTTPS/cookie/redirect/CSRF client lives in staging-smoke.php so
 * Monitor qualification cannot drift into a second transport with weaker secret or redirect rules.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--mode' || str_starts_with($arg, '--mode=')) {
        fwrite(STDERR, "staging-monitor-smoke: --mode is fixed to monitor-boundary\n");
        exit(2);
    }
}

array_splice($argv, 1, 0, ['--mode=monitor-boundary']);
require __DIR__ . '/staging-smoke.php';
