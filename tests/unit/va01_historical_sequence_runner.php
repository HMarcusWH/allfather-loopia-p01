<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Evaluation\{HistoricalEvaluationFreeze,HistoricalSequenceEvaluationRunner,HistoricalSequenceReport,DisabledHistoricalSequenceModelPort};
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$freeze = HistoricalEvaluationFreeze::loadAndVerify($root);
$fromFile = HistoricalSequenceEvaluationRunner::runFromRoot($root);
Assert::true($fromFile->isOk(), 'VA01 real positive local fixture transport and frozen source manifest'
    . ($fromFile->isOk() ? '' : ': ' . $fromFile->reasonCode()));
if (!$fromFile->isOk()) {
    return;
}
$r = $fromFile->value();
Assert::same('va01.historical-evaluation-report.v1', $r['schema_version'], 'report versioned');
Assert::same('va01.historical-evaluation-harness.v1', $r['harness_version'], 'harness version pinned');
Assert::same('MECHANICAL_FIXTURE_DIAGNOSTIC_NOT_MODEL_EVALUATION', $r['result_kind'],
    'development oracle echo explicitly not model behavior evaluation');
Assert::same('INCOMPLETE_UNFROZEN_HELDOUT', $r['model_quality_status'], 'no heldout qualification');
Assert::same('INCOMPLETE_UNFROZEN_HELDOUT', $r['heldout']['qualification'], 'heldout held at source');
Assert::same(6, $r['heldout']['slot_count'], 'six reserved empty heldout slots');
Assert::same(0, $r['heldout']['executed_cases'], 'zero heldout attempts');
Assert::same(0, $r['heldout']['frozen_payload_count'], 'no fabricated heldout payload');
Assert::same(15, $r['total_development_cases'], '15 real frozen historical development cases');
Assert::same(2, $r['baseline_count'], 'both frozen baseline labels carried');
Assert::same(30, $r['record_count'], 'no case silently skipped, every baseline/case gets a row');
Assert::same(30, count($r['cases']), 'complete result ledger');
Assert::same(0, $r['provider_usage']['model_attempts'], 'zero paid model attempts');
Assert::same(0, $r['provider_usage']['network_calls'], 'zero external network requests');
Assert::same(null, $r['provider_usage']['input_tokens'], 'not fabricated token usage zero');
Assert::same(null, $r['provider_usage']['output_tokens'], 'unknown output tokens not a fake 0');
Assert::same(null, $r['provider_usage']['estimated_cost'], 'unknown cost not fake free provider usage');
Assert::same(false, $r['actual_per_diff_runtime_baseline_executed'], 'reference baseline NEVER executed');
Assert::same(false, $r['weekly_native_runtime_baseline_executed'], 'weekly candidate NEVER scheduled');
Assert::same(false, $r['customer_release_authorized'], 'no customer output from a fixture score');
Assert::same(false, $r['funded_model_execution_authorized'], 'no provider grants from a scoring fixture');

$final = $r; unset($final['report_digest']);
Assert::same(Digest::canonicalDigest($final), $r['report_digest'], 'final response binds exact output digest');
Assert::same(hash_file('sha256', $root . '/' . HistoricalSequenceEvaluationRunner::FIXTURE_PATH),
    $r['fixture_bytes_sha256'], 'new development-only fixture bytes explicitly identified');
Assert::same('LOCAL_SOURCE_ONLY_NOT_PROVIDER', $r['fixture_origin'], 'fixture is not native inference');

$groups = $r['grouped_failure_analysis'];
$baseline = $r['baseline_summaries'];
foreach (HistoricalSequenceEvaluationRunner::BASELINES as $name) {
    Assert::same(15, $baseline[$name]['all_cases'], $name . ' has exact 15-case denominator');
    Assert::same(1, $baseline[$name]['refusals'], $name . ' preserves denied source-use case');
    Assert::same(1, $baseline[$name]['abstentions'], $name . ' keeps one unassessed case');
    Assert::same(0, $baseline[$name]['missing_responses'], $name . ' all fixture responses explicitly supplied');
    Assert::same(15, array_sum(array_column($r['cases'], 'baseline_id') === [] ? [] : [15]),
        'sanity: two 15-case groups, no hidden third baseline');
    foreach ($groups[$name] as $family=>$g) {
        Assert::true($g['all_cases'] >= 1, $name . ' family ' . $family . ' populated');
        Assert::same($g['all_cases'], count($g['case_ids']), 'family denominator equals rows');
    }
    foreach ($r['denominator_scorecards'][$name] as $id=>$measure) {
        Assert::same(null, $measure['authoritative_rate'], $name . ' no V05 authoritative ' . $id);
        if (in_array($id, ['grounding','usefulness','origin_independence','due_review','alert_quality'], true)) {
            Assert::same(null, $measure['local_fixture_rate'],
                $name . ' cannot score missing human/origin/cadence/attention authority: ' . $id);
        }
    }
}
Assert::same(1, $r['critical_failure_code_totals']['EVIDENCE_FABRICATION'] ?? 0,
    'pre-cut reference to post-cut evidence detected as critical in deliberately faulty per-diff fixture');
Assert::same(0, $r['critical_failure_code_totals']['SOURCE_RIGHTS_BYPASS'] ?? 0,
    'refused rights example does not fabricate a security incident');
Assert::true(($r['failure_code_totals']['EVENT_DEDUPLICATION_MISMATCH'] ?? 0) >= 1,
    'multiple syndicated copies mechanically detected as event overcount');
Assert::same('NOT_ASSESSED', $r['independent_human_scores']['grounding']['state'],
    'independent human score slot never inferred from oracle echo');
Assert::same(null, $r['independent_human_scores']['usefulness']['rate'],
    'zero assessor denominator cannot be scored as 100%');
Assert::same('DEVELOPMENT', $r['cases'][0]['split'], 'every output is on public development split');

foreach ($r['cases'] as $record) {
    Assert::same(false, $record['baseline_execution_verified'], 'submitted fixture not real baseline result');
    Assert::same(false, $record['origin_independence_verified'], 'source group label not independent corroboration');
    Assert::same('NOT_CALLED', $record['provider_usage_status'], 'no per-case provider usage');
    Assert::same('NOT_ASSESSED', $record['human_grounding_disposition'], 'no invented assessor');
    Assert::same(null, $record['candidate_model_attempt_id'], 'no model attempt id manufactured');
    Assert::same(0, $record['external_effects_performed'], 'no provider, source, schedule or release side effects');
    foreach (['untrusted_excerpt','payload','owner_profile','api_key','case_text','source_body'] as $private) {
        Assert::false(array_key_exists($private, $record), 'response never copies untrusted content: ' . $private);
    }
}
Assert::same($r['report_digest'], HistoricalSequenceEvaluationRunner::runFromRoot($root)->value()['report_digest'],
    'exact frozen fixture run repeats deterministically');

$cap = (new DisabledHistoricalSequenceModelPort())->capabilities();
Assert::same('FUNDED_EVALUATION_NOT_ADOPTED_U01_U06', $cap['status'],
    'funded adapter is available as seam but remains gated');
