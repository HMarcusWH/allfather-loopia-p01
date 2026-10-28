<?php

declare(strict_types=1);

/**
 * VA-00 verification is read-only and CLI-only. Never imports a held-out oracle,
 * fetches sources, contacts a provider, mutates an owner decision, or writes SQL.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}
require dirname(__DIR__, 2) . '/app/autoload.php';

use Allfather\Loopia\Evaluation\HistoricalEvaluationFreeze;

if (count($argv) !== 1) {
    fwrite(STDERR, "VA00_REFUSED_UNSUPPORTED_ARGUMENT\n");
    exit(2);
}
try {
    $root = dirname(__DIR__, 2);
    $verified = HistoricalEvaluationFreeze::loadAndVerify($root);
    $m = $verified['manifest'];
    $receipt = [
        'status' => 'VA00_FREEZE_RULES_VERIFIED_HELDOUT_NOT_FROZEN',
        'manifest_sha256' => hash_file('sha256', $root . '/docs/longitudinal/build-first/EVALUATION_FREEZE_MANIFEST.json'),
        'pinned_immutable_files' => count($m['pinned_source_files']),
        'longitudinal_development_cases' => count($verified['development']['cases']),
        'longitudinal_heldout_slots' => count($verified['heldout']['slots']),
        'heldout_payloads_frozen' => 0,
        'holdout_custodian' => 'UNASSIGNED',
        'independent_human_scores' => 0,
        'model_quality' => 'NOT_ACCEPTED',
        'runtime_execution_authorized' => false,
        'full_system_verification' => 'NOT_RUN_DEFERRED_UNTIL_RT02',
    ];
    echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable) {
    fwrite(STDERR, "VA00_FREEZE_VERIFICATION_FAILED\n");
    exit(1);
}
