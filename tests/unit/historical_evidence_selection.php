<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\HistoricalEvidenceSelector;
use Allfather\Loopia\Workflow\HistoryReviewBasis;

$v = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/li05_offline_basis_vectors.json'), true, 64, JSON_THROW_ON_ERROR);
$input = [
    'scope' => $v['scope'], 'period' => $v['period'],
    'baseline_period' => $v['baseline_period'], 'baseline_null_reason' => null,
    'knowledge_cutoff_utc' => $v['knowledge_cutoff_utc'],
    'rows' => $v['rows'], 'max_references' => 8, 'coverage' => $v['known_coverage'],
];
$cut = $input;
$cut['max_references'] = 1;
$cap = HistoryReviewBasis::freeze($cut);
Assert::true($cap->isOk(), 'bounded selection produces a reasoned candidate');
if ($cap->isOk()) {
    $c = $cap->value();
    Assert::same('INSUFFICIENT_BASIS', $c['status'], 'dropped essential contrary forces insufficient basis');
    Assert::same(true, $c['contra_evidence_not_included'], 'missed contrary evidence called out');
    Assert::same(1, $c['excluded_counts_by_reason']['SELECTION_CAP'], 'cap omission counted exactly');
    Assert::same(['ref_a'], array_column($c['references'], 'ref_id'), 'deterministic oldest-ID cap');
}
$quarantine = $input;
$quarantine['rows'][1]['reported_disposition'] = 'QUARANTINED';
$q = HistoryReviewBasis::freeze($quarantine);
Assert::same('INSUFFICIENT_BASIS', $q->value()['selection_integrity'], 'quarantined contrary never implies completeness');
Assert::same(true, $q->value()['contra_evidence_not_included'], 'quarantined contradiction is not concealed');
Assert::same(['ref_a'], array_column($q->value()['references'], 'ref_id'), 'quarantine excludes all metadata candidates');
Assert::same(1, $q->value()['excluded_counts_by_reason']['REPORTED_NOT_USABLE'], 'quarantine omission explicit');
foreach (['DENIED', 'UNKNOWN', 'REVOKED', 'MISSING_BYTES'] as $blocked) {
    $row = $input;
    $row['rows'][0]['reported_disposition'] = $blocked;
    Assert::same(1, HistoryReviewBasis::freeze($row)->value()['excluded_counts_by_reason']['REPORTED_NOT_USABLE'],
        "{$blocked} cannot slip into offline metadata selection");
}
$unknownCoverage = $input;
$unknownCoverage['coverage'] = ['state' => 'UNKNOWN'];
Assert::same('INSUFFICIENT_BASIS', HistoryReviewBasis::freeze($unknownCoverage)->value()['status'],
    'unknown coverage cannot masquerade as a complete basis');
$none = $input; $none['rows'] = [];
Assert::same('INSUFFICIENT_BASIS', HistoryReviewBasis::freeze($none)->value()['status'],
    'zero observations do not establish no change');

// Repeated captures of identical bytes increase captured slots, never corroborating events.
$repeated = $input;
$repeated['rows'] = [];
$repeated['max_references'] = 32;
for ($i=0; $i<20; $i++) {
    $row = $input['rows'][0];
    foreach (['ref_id','source_ref_id','evidence_id','snapshot_id'] as $id) {
        $row[$id] .= '_' . $i;
    }
    $repeated['rows'][] = $row;
}
$repeated['coverage'] = ['state'=>'KNOWN','plan_version_id'=>'fixture.repeat.v1',
    'planned_slots'=>25,'captured'=>20,'unchanged'=>19,'failed'=>1,'skipped'=>1,'unreported'=>3];
$rep = HistoryReviewBasis::freeze($repeated)->value();
Assert::same(20, $rep['selected_count'], '20 distinct capture refs remain visible as captures');
Assert::same(null, $rep['coverage']['independent_event_count'], '20 repeated-byte captures do not imply independent events');
Assert::same(19, $rep['coverage']['unchanged'], 'coverage unchanged count remains a capture statistic');
Assert::same('OFFLINE_NOT_ADMITTED', $rep['status'], 'complete input metadata still has zero admission');
$repeated['max_references'] = 3;
Assert::same('INSUFFICIENT_BASIS', HistoryReviewBasis::freeze($repeated)->value()['status'],
    'capped repeat series cannot claim complete basis');
$invalid = $input; $invalid['rows'][0]['ref_id'] = $invalid['rows'][1]['ref_id'];
Assert::same('LI05_ROW_DUPLICATE_IDENTITY', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'duplicate primary ref refused');
$invalid = $input; $invalid['rows'][0]['evidence_id'] = $invalid['rows'][1]['evidence_id'];
Assert::same('LI05_ROW_DUPLICATE_IDENTITY', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'duplicate evidence owner identity refused');
$invalid = $input; $invalid['rows'][0]['assessment_id'] = null;
Assert::same('LI05_ROW_IDENTITY_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(),
    'assessed time requires an assessment owner reference');
$invalid = $input; $invalid['rows'][2]['assessment_id'] = 'unbound_future_assessment';
Assert::same('LI05_ROW_IDENTITY_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(),
    'unclocked assessment identity cannot enter basis');
$invalid = $input; $invalid['rows'][0]['payload_sha256'] = str_repeat('F',64);
Assert::same('LI05_ROW_CLOCK_OR_DIGEST_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'noncanonical digest refused');
$invalid = $input; $invalid['coverage']['unchanged'] = 20;
Assert::same('LI05_COVERAGE_CONTRACT_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'unchanged cannot exceed captured');
$invalid = $input; $invalid['coverage']['unreported'] = 0;
Assert::same('LI05_COVERAGE_CONTRACT_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'coverage counts must exactly account for plan');
$invalid = $input; $invalid['max_references'] = HistoricalEvidenceSelector::MAX_REFERENCES + 1;
Assert::same('LI05_SELECTOR_BOUNDS_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'max references bounded');
$invalid = $input; $invalid['rows'] = array_fill(0, HistoricalEvidenceSelector::MAX_INPUT_ROWS + 1, $input['rows'][0]);
Assert::same('LI05_SELECTOR_BOUNDS_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'input row count bounded before duplicates');
$invalid = $input; $invalid['rows'][0]['provenance'] = 'TRUSTED_OFFICIAL';
Assert::same('LI05_ROW_VOCABULARY_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'invented authority provenance refused');
$invalid = $input; $invalid['rows'][0]['display_text'] = 'Untrusted alleged finding';
Assert::same('LI05_ROW_CONTRACT_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'untrusted payload/body cannot enter basis');
$invalid = $input; $invalid['rows'][0]['event_at'] = "\xff";
Assert::same('LI05_SELECTOR_ENCODING_INVALID', HistoryReviewBasis::freeze($invalid)->reasonCode(), 'invalid UTF-8 fails closed');
