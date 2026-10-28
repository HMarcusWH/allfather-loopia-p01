<?php

declare(strict_types=1);

use Allfather\Loopia\Evaluation\{HistoricalSequenceEvaluationRunner,HistoricalSequenceReport,DisabledHistoricalSequenceModelPort};
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
Assert::same('va01.historical-evaluation-harness.v1', HistoricalSequenceEvaluationRunner::VERSION,
    'VA01 typed runner contract pinned');
Assert::same('va01.historical-evaluation-report.v1', HistoricalSequenceReport::VERSION,
    'VA01 immutable report version pinned');
Assert::same('INCOMPLETE_UNFROZEN_HELDOUT', HistoricalSequenceReport::EVALUATION_STATUS,
    'cannot qualify unseen heldout');
Assert::same(30, HistoricalSequenceEvaluationRunner::RECORD_COUNT,
    'both 15-case baseline comparisons counted');
Assert::same(0, (new DisabledHistoricalSequenceModelPort())->providerCalls,
    'inert funded adapter defaults no paid calls');
$dir = $root . '/app/Evaluation/';
foreach ([
    'HistoricalSequenceEvaluationRunner.php',
    'HistoricalSequenceReport.php',
    'HistoricalSequenceModelExecutionPort.php',
    'DisabledHistoricalSequenceModelPort.php',
] as $path) {
    $source = (string) file_get_contents($dir . $path);
    foreach ([
        'CurlHttpsTransport','OpenAIResponsesTransport','ModelExecutor','new \\PDO(',
        'curl_exec(', 'fsockopen(', 'file_put_contents(', 'shell_exec(', 'proc_open(',
        'mail(', 'new EvidenceStore(', 'new \\PDO(',
    ] as $unsafe) {
        Assert::false(str_contains($source, $unsafe),
            'VA01 offline evaluator cannot fetch/write/model/release: ' . $path . ':' . $unsafe);
    }
}
foreach ([
    'tests/fixtures/monitor_model_eval_cases.json' =>
        'cd21a4224b4b1c54f4378657b1950d5d399a0a743738739799f2341eeddf7c70',
    'tests/fixtures/historical_review_development_cases.json' =>
        '52bc6093d70dcbcd7dd8f37115609d361d6564dd46d9e969ec2d9113ca1a550b',
    'tests/fixtures/historical_review_heldout_manifest.json' =>
        'dc70e10438f6ffdf3acba6f1eba77e2aaa49f2e8e1682890b09aa44f0f1995f8',
] as $path=>$digest) {
    Assert::same($digest, hash_file('sha256', $root . '/' . $path),
        'VA00 frozen source bytes and old DEV7 case split never changed: ' . $path);
}
$op = (string) file_get_contents($root . '/ops/evaluation/history.php');
Assert::true(strpos($op, "PHP_SAPI !== 'cli'") !== false,
    'history CLI refuses every web SAPI');
Assert::true(strpos($op, "PHP_SAPI !== 'cli'") < strpos($op, 'require'),
    'history CLI refuses web requests before importing application');
Assert::contains('VA01_FUNDED_EXECUTION_U01_U06_NOT_ADOPTED', $op,
    'CLI never offers paid execution by a runtime flag');
$matrix=json_decode((string) file_get_contents(
    $root . '/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'), true, 64, JSON_THROW_ON_ERROR);
$item=array_column($matrix['build_packages'], null, 'id')['VA-01'];
Assert::same('BUILT_INERT', $item['build_status'], 'source implementation is not an accepted model');
foreach (['native_permission','customer_visibility','production_activation','live_migration','external_effects'] as $key) {
    Assert::same(false, $item[$key], 'build cannot elevate owner authority: ' . $key);
}
$doc=(string) file_get_contents($root . '/docs/HISTORICAL_REVIEW_EVALUATION_PROTOCOL.md');
foreach (['VA-00','U01','U06','HELD_OUT','NOT_ASSESSED','UNKNOWN','DEV7','V05'] as $key) {
    Assert::contains($key, $doc, 'VA01 protocol documents owner/quality limitations: ' . $key);
}
