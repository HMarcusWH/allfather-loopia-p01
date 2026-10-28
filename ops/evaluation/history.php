<?php

declare(strict_types=1);

/**
 * VA-01 evaluation/History CLI. This is a pure PUBLIC SYNTHETIC DEVELOPMENT
 * runner, NEVER the funded MonitorEvaluationRunner or a provider dispatch.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(3);
}
$help = in_array('--help', $argv, true) || in_array('--status', $argv, true);
if ($help) {
    echo json_encode([
        'version' => 'va01.historical-evaluation-harness.v1',
        'operation' => 'OFFLINE_DEVELOPMENT_DIAGNOSTIC',
        'command' => 'php ops/evaluation/history.php --offline-development',
        'heldout' => 'UNFROZEN_NOT_RUN',
        'input' => 'tests/fixtures/longitudinal/development/va01_sequence_responses_v1.json',
        'paid_provider_execution' => 'VA01_FUNDED_EXECUTION_U01_U06_NOT_ADOPTED',
        'can_release' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
if (count($argv) !== 2 || $argv[1] !== '--offline-development') {
    fwrite(STDERR, "VA01_FUNDED_EXECUTION_U01_U06_NOT_ADOPTED\n");
    exit(3);
}
require dirname(__DIR__, 2) . '/app/autoload.php';
$result = \Allfather\Loopia\Evaluation\HistoricalSequenceEvaluationRunner::runFromRoot(
    dirname(__DIR__, 2)
);
if (!$result->isOk()) {
    fwrite(STDERR, $result->reasonCode() . "\n");
    exit(2);
}
echo json_encode(
    $result->value(),
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) . "\n";
