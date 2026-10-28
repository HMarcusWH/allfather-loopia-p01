<?php

declare(strict_types=1);

use Allfather\Loopia\Evaluation\{HistoricalEvaluationFreeze,HistoricalSequenceEvaluationRunner,DisabledHistoricalSequenceModelPort};
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$freeze = HistoricalEvaluationFreeze::loadAndVerify($root);
$fixture = json_decode((string) file_get_contents($root . '/' . HistoricalSequenceEvaluationRunner::FIXTURE_PATH),
    true, 64, JSON_THROW_ON_ERROR);
$baseline = HistoricalSequenceEvaluationRunner::evaluate($freeze, $fixture);
Assert::true($baseline->isOk(), 'QA source fixture positive before tampering');
if (!$baseline->isOk()) {
    return;
}
$mutate = static function (callable $change) use ($fixture, $freeze) {
    $copy = $fixture;
    $change($copy);
    return HistoricalSequenceEvaluationRunner::evaluate($freeze, $copy);
};
Assert::same('VA01_UNKNOWN_OR_HELDOUT_CASE_REFUSED',
    $mutate(static function (&$v) {
        $v['responses'][0]['case_id'] = 'heldout-01';
    })->reasonCode(), 'unfrozen heldout cannot enter scoring through fake case selector');
Assert::same('VA01_UNKNOWN_OR_HELDOUT_CASE_REFUSED',
    $mutate(static function (&$v) {
        $v['responses'][0]['case_id'] = 'unknown-case';
    })->reasonCode(), 'unknown external case refused');
Assert::same('VA01_DUPLICATE_CASE_BASELINE_RESPONSE',
    $mutate(static function (&$v) {
        $v['responses'][] = $v['responses'][0];
    })->reasonCode(), 'duplicate fixture baseline+case cannot inflate denominator');
Assert::same('VA01_FIXTURE_CONTRACT_NOT_ADMITTED',
    $mutate(static function (&$v) {
        $v['execution_grant_id'] = 'fake-grant';
    })->reasonCode(), 'caller cannot insert an execution grant into fixture contract');
Assert::same('VA01_FIXTURE_CONTRACT_NOT_ADMITTED',
    $mutate(static function (&$v) {
        $v['source_development_fixture_sha256'] = str_repeat('a', 64);
    })->reasonCode(), 'source identity spoof refused');
Assert::same('VA01_FIXTURE_CONTRACT_NOT_ADMITTED',
    $mutate(static function (&$v) {
        $v['baseline_ids'][] = 'TUNED_AFTER_ORACLE';
    })->reasonCode(), 'post-hoc baseline cannot enter scoring');
Assert::same('VA01_FIXTURE_CONTRACT_NOT_ADMITTED',
    $mutate(static function (&$v) {
        $v['classification'] = 'HELDOUT_INDEPENDENTLY_ASSESSED';
    })->reasonCode(), 'caller cannot fake heldout acceptance');

$missing = $fixture;
array_pop($missing['responses']);
$incomplete = HistoricalSequenceEvaluationRunner::evaluate($freeze, $missing);
Assert::true($incomplete->isOk(), 'missing response is captured as NOT_RUN row, not skipped');
if ($incomplete->isOk()) {
    Assert::same(30, $incomplete->value()['record_count'],
        'missing case cannot shrink report denominator');
    Assert::same('INCOMPLETE_UNFROZEN_HELDOUT', $incomplete->value()['model_quality_status'],
        'less complete fixture cannot claim success');
    Assert::same(1, array_sum(array_column($incomplete->value()['baseline_summaries'], 'missing_responses')),
        'a single missing result is recorded explicitly');
}
$badResponse = $fixture;
$badResponse['responses'][0]['provider_key'] = 'fake-key-should-never-be-accepted';
$invalid = HistoricalSequenceEvaluationRunner::evaluate($freeze, $badResponse);
Assert::true($invalid->isOk(), 'single invalid known response recorded as failure, not report-wide exception');
if ($invalid->isOk()) {
    Assert::same('REFUSED_INVALID_RESPONSE_CONTRACT',
        $invalid->value()['cases'][0]['response_disposition'],
        'no unknown response field becomes grant or a model request');
    Assert::same(false, $invalid->value()['funded_model_execution_authorized'],
        'schema failure does not release an executor');
}
$policy = new DisabledHistoricalSequenceModelPort();
foreach ([
    [],
    ['execution_grant_id' => 'fake', 'purpose' => 'MODEL_EVALUATION'],
    ['U01' => 'APPROVED', 'U06' => 'APPROVED', 'holdout_unblinded' => true],
] as $forgedGrant) {
    $denied = $policy->dispatch($forgedGrant, ['case_id'=>'heldout-01','payload'=>'private?']);
    Assert::same('VA01_FUNDED_EXECUTION_U01_U06_NOT_ADOPTED', $denied->reasonCode(),
        'fabricated grant and heldout do not activate model port');
    Assert::same(0, $policy->providerCalls, 'model adapter never calls provider');
    Assert::same(0, $policy->networkCalls, 'no model network side effect');
}
Assert::same('RESERVED_NOT_FROZEN_NOT_RUN', $fixture['source_provenance'] === 'INVALID' ? '' :
    $freeze['heldout']['status'], 'heldout registry unchanged by dev fixture');
