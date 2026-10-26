<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\HistoryReviewBasis;
use Allfather\Loopia\Workflow\HistoricalEvidenceSelector;

$v = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/li05_offline_basis_vectors.json'), true, 64, JSON_THROW_ON_ERROR);
$input = [
    'scope' => $v['scope'], 'period' => $v['period'],
    'baseline_period' => $v['baseline_period'], 'baseline_null_reason' => null,
    'knowledge_cutoff_utc' => $v['knowledge_cutoff_utc'],
    'rows' => $v['rows'], 'max_references' => 8, 'coverage' => $v['known_coverage'],
];
$basis = HistoryReviewBasis::freeze($input);
Assert::true($basis->isOk(), 'initial four-clock basis is well formed');
if ($basis->isOk()) {
    $m = $basis->value();
    Assert::same('li05.offline-basis-candidate.v1', $m['schema_version'], 'schema is version-locked');
    Assert::same('OFFLINE_NOT_ADMITTED', $m['status'], 'complete metadata does not authorize historical use');
    Assert::same('COMPLETE_WITHIN_INPUT', $m['selection_integrity'], 'complete means only the supplied metadata');
    Assert::same(['ref_a', 'ref_b'], array_column($m['references'], 'ref_id'), 'clock ordering and stable ID ties');
    Assert::same(2, $m['selected_count'], 'observation start inclusive and cutoff inclusive');
    Assert::same(['BEFORE_WINDOW'=>1, 'AFTER_WINDOW'=>1, 'NOT_YET_KNOWN'=>1,
        'REPORTED_NOT_USABLE'=>0, 'SELECTION_CAP'=>0], $m['excluded_counts_by_reason'],
        'half-open time period and late-known exclusion counted separately');
    Assert::same(null, $m['references'][0]['analyzed_at'], 'future analysis hidden from past knowledge basis');
    Assert::same(null, $m['references'][0]['assessment_id'], 'future assessment identity hidden');
    Assert::same('NOT_KNOWN', $m['references'][0]['analysis_as_of_cutoff'], 'no future analysis disclosure');
    Assert::same('2026-03-15T00:00:00Z', $m['references'][1]['known_at'], 'exact cutoff equality included');
    Assert::same('2026-01-10T00:00:00Z', $m['references'][0]['event_at'],
        'claimed earlier event date does not decide window membership');
    Assert::same('NOT_ASSESSED', $m['rights_disposition'], 'historical source use not assessed');
    Assert::same(false, $m['historical_reuse_admitted'], 'candidate cannot grant reuse');
    Assert::true(HistoryReviewBasis::verify($m)->isOk(), 'manifest digest verifies');
    $raw = $m;
    unset($raw['manifest_digest']);
    Assert::same(Digest::canonicalDigest($raw), $m['manifest_digest'], 'uses existing canonical digest law');
    $tamper = $m;
    $tamper['references'][0]['known_at'] = '2026-03-02T00:00:00Z';
    Assert::same('LI05_BASIS_DIGEST_MISMATCH', HistoryReviewBasis::verify($tamper)->reasonCode(),
        'mutating frozen metadata fails hash-bound verification');
    $tamper = $m;
    $tamper['authorized'] = true;
    Assert::same('LI05_BASIS_SHAPE_INVALID', HistoryReviewBasis::verify($tamper)->reasonCode(),
        'unknown authorization field cannot join immutable manifest');
}
$reordered = $input;
$reordered['rows'] = array_reverse($reordered['rows']);
Assert::same($basis->value()['manifest_digest'], HistoryReviewBasis::freeze($reordered)->value()['manifest_digest'],
    'different caller input order gives identical candidate digest');
$late = $input;
$late['knowledge_cutoff_utc'] = '2026-03-20T00:00:00Z';
$successor = HistoryReviewBasis::freeze($late);
Assert::true($successor->isOk(), 'later knowledge cutoff builds a successor candidate');
if ($successor->isOk()) {
    $s = $successor->value();
    Assert::same(['ref_a','ref_b','ref_late'], array_column($s['references'], 'ref_id'),
        'later known evidence is allowed only in later as-of candidate');
    Assert::notSame($basis->value()['manifest_digest'], $s['manifest_digest'],
        'new cutoff and newly known evidence produce a distinct candidate identity');
    Assert::same('2026-03-20T00:00:00Z', $s['references'][0]['analyzed_at'],
        'later cutoff can include analysis first available then');
}
foreach (['2026-03-15T01:00:00+01:00','2026-03-15T01:00:00','2026-02-29T00:00:00Z',
          '2026-03-15T25:00:00Z','2026-03-15T00:00:60Z','not-a-date'] as $bad) {
    Assert::false(HistoricalEvidenceSelector::utc($bad), 'invalid, offset or ambiguous UTC is refused');
}
foreach (['2024-02-29T23:59:59Z', '2026-03-01T00:00:00Z'] as $valid) {
    Assert::true(HistoricalEvidenceSelector::utc($valid), 'normalized UTC accepts valid leap dates');
}
$bad = $input; $bad['period']['start_inclusive_utc'] = $bad['period']['end_exclusive_utc'];
Assert::same('LI05_SELECTOR_TIME_INVALID', HistoryReviewBasis::freeze($bad)->reasonCode(), 'empty half-open window refused');
$bad = $input; $bad['baseline_period']['end_exclusive_utc'] = '2026-03-02T00:00:00Z';
Assert::same('LI05_BASELINE_CONTRACT_INVALID', HistoryReviewBasis::freeze($bad)->reasonCode(), 'baseline overlapping review period refused');
$bad = $input; $bad['baseline_period'] = null; $bad['baseline_null_reason'] = null;
Assert::same('LI05_BASELINE_CONTRACT_INVALID', HistoryReviewBasis::freeze($bad)->reasonCode(), 'absent baseline needs an explicit reason');
$bad['baseline_null_reason'] = 'NO_BASELINE_AVAILABLE';
Assert::true(HistoryReviewBasis::freeze($bad)->isOk(), 'reasoned no-baseline state valid');
$bad = $input; $bad['rows'][0]['known_at'] = '2026-02-28T00:00:00Z';
Assert::same('LI05_ROW_CLOCK_ORDER_INVALID', HistoryReviewBasis::freeze($bad)->reasonCode(), 'knowledge cannot predate observation');
$bad = $input; $bad['rows'][0]['analyzed_at'] = '2026-02-28T00:00:00Z';
Assert::same('LI05_ROW_CLOCK_ORDER_INVALID', HistoryReviewBasis::freeze($bad)->reasonCode(), 'analysis before first knowledge invalid');
$bad = $input; $bad['rows'][0]['observed_at'] = '2026-03-01T01:00:00+01:00';
Assert::same('LI05_ROW_CLOCK_OR_DIGEST_INVALID', HistoryReviewBasis::freeze($bad)->reasonCode(), 'offset times refused rather than guessed');
