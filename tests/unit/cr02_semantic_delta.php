<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Cr02Fixture.php';

use Allfather\Loopia\Product\P01\SemanticDelta;
use Allfather\Loopia\Product\P01\SectionFingerprint;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Cr02Fixture;

$registry = Cr02Fixture::cases();
Assert::same('cr02.semantic-delta-vectors.v1', $registry['schema_version'], 'CR02 golden corpus exact revision');
Assert::same('SYNTHETIC_PRIVATE_FIXTURE_ONLY', $registry['scope'], 'fixture never grants M05 source permission');
Assert::same(11, $registry['case_count'], 'frozen case count');
Assert::same(11, count($registry['cases']), 'all fixture cases exist');
$results = [];
foreach ($registry['cases'] as $case) {
    $left = Cr02Fixture::syntheticProjection($case, 'before');
    $right = Cr02Fixture::syntheticProjection($case, 'after');
    $l = SectionFingerprint::fromProjection($left);
    $r = SectionFingerprint::fromProjection($right);
    Assert::true($l->isOk(), $case['id'] . ': synthetically-constructed CR01 envelope has strict source contract');
    Assert::true($r->isOk(), $case['id'] . ': after projection validates under same contract');
    if (!$l->isOk() || !$r->isOk()) {
        continue;
    }
    $a = SemanticDelta::compare($left, $right);
    Assert::true($a->isOk(), $case['id'] . ': real deterministic semantic delta positive path'
        . ($a->isOk() ? '' : ' reason=' . $a->reasonCode()));
    if (!$a->isOk()) {
        continue;
    }
    $v = $a->value();
    $results[$case['id']] = $v;
    $types = array_column($v['deltas'], 'change_kind');
    Assert::true(in_array($case['expected_kind'], $types, true),
        $case['id'] . ': exact expected section disposition ' . $case['expected_kind']);
    Assert::same($case['expected_structural'] ?? (
        $case['expected_kind'] === 'ORDER_ONLY' ? 'ORDER_ONLY' : (
            $case['expected_kind'] === 'SECTION_UNCHANGED' ? 'NO_TYPED_CHANGE' : 'TYPED_CHANGE'
        )), $v['structural_classification'], $case['id'] . ': structural classification independent of business materiality');
    Assert::same($left['source_binding']['capture_ref_id'], $v['before']['capture_ref_id'],
        $case['id'] . ': exact original before capture ID');
    Assert::same($right['source_binding']['capture_ref_id'], $v['after']['capture_ref_id'],
        $case['id'] . ': exact original after capture ID');
    Assert::same($left['source_binding']['raw_sha256'], $v['before']['raw_sha256'],
        $case['id'] . ': original HTML source body digest carried without reinterpretation');
    Assert::same($right['source_binding']['raw_sha256'], $v['after']['raw_sha256'],
        $case['id'] . ': after source bytes separate from normalized section digest');
    Assert::same('cr02.semantic-delta.v1', $v['schema_version'], $case['id'] . ': versioned candidate output');
    Assert::same('cr02.section-fingerprint.v1', $v['fingerprint_version'], $case['id'] . ': exact algorithm fingerprint version');
    Assert::same($registry['algorithm_version'], $v['algorithm_version'], $case['id'] . ': algorithm revision pinned');
    Assert::same(false, $v['commercial_significance'] !== 'NOT_ASSESSED',
        $case['id'] . ': no commercial materiality inferred from text');
    foreach (['semantic_truth_verified','independent_materiality_assessed',
        'source_use_rights_rechecked','native_runtime_authorized',
        'provider_or_model_authorized','notification_authorized',
        'customer_release_authorized','production_authorized','persisted'] as $forbidden) {
        Assert::same(false, $v[$forbidden], $case['id'] . ': no new grant ' . $forbidden);
    }
    Assert::same([], array_filter($v['deltas'],
        static fn (array $d): bool => $d['original_byte_offsets_verified'] !== false
            || $d['source_excerpt_not_exported'] !== true),
        $case['id'] . ': no original-byte source offset or untrusted source excerpt published');
    Assert::true($v['alignment_effort']['pair_comparisons_performed'] <= SemanticDelta::MAX_PAIR_COMPARISONS,
        $case['id'] . ': bounded alignment workload');
    Assert::true($v['delta_count'] <= SemanticDelta::MAX_DELTAS, $case['id'] . ': bounded result count');
    Assert::same($v['delta_digest'], SemanticDelta::compare($left, $right)->value()['delta_digest'],
        $case['id'] . ': deterministic byte-identical candidate digest');
    if (isset($case['expected_sensitive'])) {
        $found = [];
        foreach ($v['deltas'] as $d) {
            $found = array_merge($found, $d['sensitive_token_kinds']);
        }
        sort($found, SORT_STRING);
        $wanted = $case['expected_sensitive'];
        sort($wanted, SORT_STRING);
        Assert::same($wanted, array_values(array_unique($found)), $case['id'] . ': exact sensitive token families preserved');
    }
    if (($case['expected_partial'] ?? false) === true) {
        Assert::true(in_array('BOUNDED_EXCERPT_COVERAGE', $v['unknown_dimensions'], true),
            $case['id'] . ': missing excerpt coverage must remain explicit unknown');
    }
    if (isset($case['expected_unpaired_after'])) {
        Assert::same($case['expected_unpaired_after'], $v['counts']['unaligned_after'],
            $case['id'] . ': novel unmatched section not assumed to be a proven fact');
        Assert::true(in_array('UNALIGNED_SECTION_PRESENCE_OR_IDENTITY', $v['unknown_dimensions'], true),
            $case['id'] . ': section insertion, drift or removal remains uncertain');
    }
}
Assert::same(11, count($results), 'all eleven synthetic pairs produced deterministic non-live deltas');
$nav = $results['navigation-reorder'];
Assert::same('ORDER_ONLY', $nav['structural_classification'], 'reordered labels mean ORDER_ONLY, never PRICE_CHANGED');
Assert::same(1, $nav['counts']['order_only'], 'one nav order-only section identified');
Assert::same(0, $nav['counts']['sensitive_token_changes'], 'nav label order does not produce a price signal');
Assert::same('PRICE_CHANGED', $results['price-one-digit']['deltas'][0]['change_kind'],
    'one-digit price difference cannot be normalized away');
Assert::same('NEGATION_CHANGED', $results['negation-flipped']['deltas'][0]['change_kind'],
    'one-word NOT insertion cannot be normalized away');
Assert::same('OFFER_CHANGED', $results['offer-availability']['deltas'][0]['change_kind'],
    'offer availability change retained');
Assert::same('CONTRACT_CHANGED', $results['contract-change']['deltas'][0]['change_kind'],
    'contract wording change retained');
Assert::same('DATE_CHANGED', $results['schedule-date-change']['deltas'][0]['change_kind'],
    'published date change retained without asserting event occurrence');
Assert::same(1, $results['same-content-path-moved']['counts']['weak_alignment'],
    'a derived selector move is a weak content identity, not verified source identity');
Assert::same('NO_TYPED_CHANGE', $results['raw-only-script']['structural_classification'],
    'untrusted script edit does not become an extracted content change');
Assert::true(in_array('RAW_SOURCE_BYTES_DIFFER_WITHOUT_TYPED_SECTION_CHANGE',
    $results['raw-only-script']['unknown_dimensions'], true),
    'unexplained raw byte difference remains explicitly uncertain');
Assert::same('TEXT_CHANGED', $results['excerpt-hidden-price']['deltas'][0]['change_kind'],
    'value outside excerpt is not misclassified as a fully observed PRICE_CHANGED');
