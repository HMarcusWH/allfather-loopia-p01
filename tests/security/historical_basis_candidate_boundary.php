<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\HistoryReviewBasis;
use Allfather\Loopia\Workflow\HistoricalEvidenceEligibility;

$v = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/li05_offline_basis_vectors.json'), true, 64, JSON_THROW_ON_ERROR);
$input = [
    'scope' => $v['scope'], 'period' => $v['period'], 'baseline_period' => $v['baseline_period'],
    'baseline_null_reason' => null, 'knowledge_cutoff_utc' => $v['knowledge_cutoff_utc'],
    'rows' => $v['rows'], 'max_references' => 8, 'coverage' => $v['known_coverage'],
];
$base = HistoryReviewBasis::freeze($input);
Assert::true($base->isOk(), 'synthetic basis construction succeeds without authorization');
foreach (HistoricalEvidenceEligibility::STATES as $state) {
    $r = HistoricalEvidenceEligibility::describe(['reported_disposition' => $state]);
    Assert::true($r->isOk(), "descriptive disposition {$state} is recognized");
    Assert::same('NOT_ASSESSED', $r->value()['rights_disposition'], 'reported state never becomes rights admission');
    Assert::same(false, $r->value()['historical_reuse_admitted'], 'every disposition is not admitted');
    Assert::same($state === 'UNASSESSED', $r->value()['offline_metadata_candidate'],
        'only explicitly unassessed metadata may be retained offline');
}
foreach ([['allowed' => true], ['reported_disposition' => 'ADMITTED'],
          ['reported_disposition' => 'UNASSESSED', 'authorized' => true]] as $spoof) {
    Assert::same('LI05_DISPOSITION_CONTRACT_INVALID', HistoricalEvidenceEligibility::describe($spoof)->reasonCode(),
        'self-asserted historical permission cannot enter the contract');
}
$spoof = $input;
$spoof['rows'][0]['allowed'] = true;
Assert::same('LI05_ROW_CONTRACT_INVALID', HistoryReviewBasis::freeze($spoof)->reasonCode(),
    'a row cannot self-assert current source permission');
$spoof = $input;
$spoof['eligibility'] = 'ADMITTED';
Assert::same('LI05_SELECTOR_CONTRACT_INVALID', HistoryReviewBasis::freeze($spoof)->reasonCode(),
    'unapproved top-level eligibility flag refused');
foreach (['tenant_id','workspace_id','competitor_id','question_id'] as $field) {
    $foreign = $input;
    $foreign['rows'][0][$field] = 'foreign_private_scope';
    $r = HistoryReviewBasis::freeze($foreign);
    Assert::same('LI05_ROW_SCOPE_MISMATCH', $r->reasonCode(), 'foreign scope refused as whole basis');
    Assert::false(str_contains(json_encode($r, JSON_THROW_ON_ERROR), 'foreign_private_scope'),
        'foreign private identity not leaked in refusal');
}
if ($base->isOk()) {
    $m = $base->value();
    $m['historical_reuse_admitted'] = true;
    $n = $m; unset($n['manifest_digest']);
    $m['manifest_digest'] = Digest::canonicalDigest($n);
    Assert::same('LI05_BASIS_SHAPE_INVALID', HistoryReviewBasis::verify($m)->reasonCode(),
        'even a valid new digest cannot forge an admitted manifest');
    $m = $base->value();
    $m['references'][0]['rights_disposition'] = 'ADMITTED';
    $n = $m; unset($n['manifest_digest']);
    $m['manifest_digest'] = Digest::canonicalDigest($n);
    Assert::same('LI05_BASIS_SHAPE_INVALID', HistoryReviewBasis::verify($m)->reasonCode(),
        'hashing a fake per-reference permission still cannot authorize reuse');
}
$src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Workflow/HistoricalEvidenceSelector.php');
Assert::false(str_contains($src, 'implements ModelContextAuthority'), 'selector never becomes dispatch-time authority');
Assert::false(str_contains($src, 'public function execute'), 'selector exposes no execution action');
Assert::false(str_contains($src, 'SourceAdmission::evaluate'), 'selector cannot impersonate M05 rights owner');
Assert::false(str_contains($src, 'file_get_contents('), 'selector never reads source payloads');
