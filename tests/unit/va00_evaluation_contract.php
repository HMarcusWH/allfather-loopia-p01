<?php

declare(strict_types=1);

use Allfather\Loopia\Evaluation\HistoricalEvaluationFreeze;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$bundle = HistoricalEvaluationFreeze::loadAndVerify($root);
$m = $bundle['manifest'];
$dev = $bundle['development'];
$heldout = $bundle['heldout'];
$legacy = $bundle['legacy'];
$vectors = json_decode((string) file_get_contents($root . '/tests/fixtures/longitudinal/development/va00_contract_examples_v1.json'), true, 64, JSON_THROW_ON_ERROR);
Assert::same('SYNTHETIC_DEVELOPMENT_ONLY', $vectors['status'], 'vectors never claim product evaluation');
Assert::same(2, count($m['longitudinal']['baselines']), 'both comparison baselines frozen before tuning');
foreach ($vectors['positive_inputs'] as $case) {
    $result = HistoricalEvaluationFreeze::checkDevelopmentCase($case, $dev, $m);
    Assert::same('DEVELOPMENT_SCHEMA_CHECKED', $result['outcome'], 'known development case validates without model execution: ' . $case['case_id']);
    Assert::same('VA00_CANDIDATE_ONLY_NOT_MODEL_RESULT', $result['reason_code'], 'candidate parse is not a model result');
}
$valid = $vectors['positive_inputs'][0];
$held = $valid;
$held['case_id'] = 'heldout-01';
$held['split'] = 'HELD_OUT';
Assert::same('VA00_HELDOUT_UNFROZEN', HistoricalEvaluationFreeze::checkDevelopmentCase($held, $dev, $m)['reason_code'], 'cannot turn reserved heldout into public development training');
$unknown = $valid;
$unknown['case_id'] = 'imaginary-development-case';
Assert::same('VA00_CASE_NOT_IN_PINNED_DEVELOPMENT', HistoricalEvaluationFreeze::checkDevelopmentCase($unknown, $dev, $m)['reason_code'], 'fake development case refused');
$tampered = $valid;
$tampered['source_fixture_sha256'] = str_repeat('a', 64);
Assert::same('VA00_SOURCE_MANIFEST_IDENTITY', HistoricalEvaluationFreeze::checkDevelopmentCase($tampered, $dev, $m)['reason_code'], 'wrong source digest refused');
$outside = $valid;
$outside['execution_grant_id'] = 'FORGED';
Assert::same('VA00_CASE_EXTRA_FIELD', HistoricalEvaluationFreeze::checkDevelopmentCase($outside, $dev, $m)['reason_code'], 'candidate cannot smuggle execution grant');
$wrongcut = $valid;
$wrongcut['knowledge_cut'] = '2026-12-31T00:00:00Z';
Assert::same('VA00_DEVELOPMENT_CONTEXT_MISMATCH', HistoricalEvaluationFreeze::checkDevelopmentCase($wrongcut, $dev, $m)['reason_code'], 'fixture cannot move knowledge cutoff');
$wrongbaseline = $valid;
$wrongbaseline['comparison_baseline'] = 'TUNED_AFTER_ORACLE';
Assert::same('VA00_BASELINE_NOT_FROZEN', HistoricalEvaluationFreeze::checkDevelopmentCase($wrongbaseline, $dev, $m)['reason_code'], 'post-hoc baseline refused');

$unassigned = $vectors['human_score_placeholder'];
Assert::same('NOT_ASSESSED', HistoricalEvaluationFreeze::checkHumanScore($unassigned, $m)['outcome'], 'unassigned score remains unassessed');
$forged = $unassigned;
$forged['assessor_identity'] = 'fake-custodian';
$forged['verdict'] = 'PASS';
Assert::same('VA00_HUMAN_NOT_AUTHENTICATED', HistoricalEvaluationFreeze::checkHumanScore($forged, $m)['reason_code'], 'user-constructed human PASS is not authentication');
$empty = ['mechanical_pass' => 0, 'mechanical_total' => 0, 'grounding_pass' => 0, 'grounding_total' => 0, 'usefulness_pass' => 0, 'usefulness_total' => 0, 'critical_failures' => 0];
$report = HistoricalEvaluationFreeze::preliminaryRates($empty, $m);
Assert::same('INCOMPLETE_UNFROZEN_HELDOUT', $report['status'], 'zero denominator cannot qualify');
Assert::same(null, $report['mechanical_rate'], 'unknown heldout rate null not perfect');
Assert::same(null, $report['grounding_rate'], 'unscored grounding null');
Assert::same(null, $report['usefulness_rate'], 'unscored usefulness null');
$fakePerfect = ['mechanical_pass'=>6,'mechanical_total'=>6,'grounding_pass'=>6,'grounding_total'=>6,'usefulness_pass'=>6,'usefulness_total'=>6,'critical_failures'=>0];
Assert::same('INCOMPLETE_UNFROZEN_HELDOUT',
    HistoricalEvaluationFreeze::preliminaryRates($fakePerfect, $m)['status'],
    'perfect caller-supplied integers cannot bypass missing custody');
Assert::throws(static function () use ($m, $dev, $heldout, $legacy): void {
    $bad = $m;
    $bad['longitudinal']['heldout_slots'][0]['sha256_commitment'] = str_repeat('a', 64);
    HistoricalEvaluationFreeze::validate($bad, $dev, $heldout, $legacy);
}, \DomainException::class, 'synthetic heldout commitment is not a real freeze');
Assert::throws(static function () use ($m, $dev, $heldout, $legacy): void {
    $bad = $m;
    $bad['longitudinal']['custody']['custodian_identity'] = 'someone claimed';
    HistoricalEvaluationFreeze::validate($bad, $dev, $heldout, $legacy);
}, \DomainException::class, 'fictional custodian cannot grant approval');
Assert::throws(static function () use ($m, $dev, $heldout, $legacy): void {
    $bad = $m;
    $bad['longitudinal']['thresholds']['human_grounding_pass_rate_min'] = 0.1;
    HistoricalEvaluationFreeze::validate($bad, $dev, $heldout, $legacy);
}, \DomainException::class, 'inherited quality thresholds cannot be tuned down');
Assert::throws(static function () use ($m, $dev, $heldout, $legacy): void {
    $bad = $m;
    $bad['longitudinal']['denominator_rules'][0]['rate'] = 1.0;
    HistoricalEvaluationFreeze::validate($bad, $dev, $heldout, $legacy);
}, \DomainException::class, 'no metric can invent an achieved rate');
Assert::throws(static fn () => HistoricalEvaluationFreeze::preliminaryRates(
    ['mechanical_pass'=>7,'mechanical_total'=>6,'grounding_pass'=>0,'grounding_total'=>0,'usefulness_pass'=>0,'usefulness_total'=>0,'critical_failures'=>0], $m),
    \DomainException::class, 'pass count greater than denominator refused');
