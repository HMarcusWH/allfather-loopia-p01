<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

require_once __DIR__ . '/Cr02Fixture.php';
require_once __DIR__ . '/Ob02Fixture.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\{
    CompetitorScope,ObservationAssessment,ObservationFeatureSet,SemanticDelta
};

/**
 * Bounded synthetic provenance graph for OB-03. No real M05 bytes/rights are
 * represented by the generated projections or the duplicate mirror sources.
 */
final class Ob03Fixture
{
    /** @return array<string,mixed> */
    public static function case(string $caseId): array
    {
        foreach (Cr02Fixture::cases()['cases'] as $case) {
            if ($case['id'] === $caseId) {
                return $case;
            }
        }
        throw new \InvalidArgumentException('OB03_UNKNOWN_SYNTHETIC_CASE');
    }

    /** @return array<string,mixed> */
    public static function pureAssessment(): array
    {
        $actor = Ob02Fixture::actor();
        $native = Ob02Fixture::native(2);
        $history = Ob02Fixture::history(2,'STRUCTURAL_CHANGE_RECORDED',['MATERIAL']);
        $bound = CompetitorScope::bind($actor, $native['competitor_id'], $native, $history);
        if (!$bound->isOk()) {
            throw new \RuntimeException('OB03_SCOPE_TEST_FIXTURE_INVALID: ' . $bound->reasonCode());
        }
        $assessment = ObservationAssessment::fromLegacy($bound->value(), $history);
        if (!$assessment->isOk()) {
            throw new \RuntimeException('OB03_ASSESSMENT_TEST_FIXTURE_INVALID: ' . $assessment->reasonCode());
        }
        return $assessment->value();
    }

    /**
     * @return array{delta:array,assessment:array,captures:list<array>,bounded_input:array,result:array}
     */
    public static function positive(
        string $caseId, ?array $assessment = null, ?string $beforeSnapshotId = null,
        ?string $beforeEvidenceId = null, int $duplicateAfterCaptures = 1,
    ): array {
        $assessment ??= self::pureAssessment();
        $beforeSnapshotId ??= Ob02Fixture::uuid(10);
        $beforeEvidenceId ??= Ob02Fixture::uuid(11);
        $case = self::case($caseId);
        $docs = [];
        foreach (['before','after'] as $side) {
            $doc = Cr02Fixture::syntheticProjection($case, $side);
            $source = $doc['source_binding'];
            $source['tenant_id'] = $assessment['tenant_id'];
            $source['workspace_id'] = $assessment['workspace_id'];
            $source['monitored_source_id'] = $assessment['monitored_source_id'];
            $source['source_ref_id'] = $assessment['source_ref_id'];
            $source['source_card_id'] = $assessment['source_license_card_id'];
            $source['source_card_sha256'] = str_repeat('c',64);
            $source['capture_ref_id'] = $side === 'before'
                ? $beforeSnapshotId : $assessment['source_snapshot_id'];
            $source['evidence_ref_id'] = $side === 'before'
                ? $beforeEvidenceId : $assessment['evidence_id'];
            foreach ($doc['sections'] as &$section) {
                $section['source_card_id'] = $source['source_card_id'];
                $section['origin_id'] = hash('sha256',
                    $source['capture_ref_id'] . "\0" . $source['source_ref_id']
                    . "\0" . $section['selector']);
            }
            unset($section);
            $doc['source_binding'] = $source;
            unset($doc['projection_digest']);
            $doc['projection_digest'] = Digest::canonicalDigest($doc);
            $docs[$side] = $doc;
        }
        $delta = SemanticDelta::compare($docs['before'], $docs['after']);
        if (!$delta->isOk()) {
            throw new \RuntimeException('OB03_CR02_TEST_PAIR_INVALID: '.$delta->reasonCode());
        }
        if ($duplicateAfterCaptures < 1 || $duplicateAfterCaptures > 100) {
            throw new \InvalidArgumentException('OB03_TEST_CAPTURE_COUNT_OUTSIDE_BOUND');
        }
        $captures = [];
        for ($i=0; $i<$duplicateAfterCaptures; $i++) {
            $captures[] = [
                'capture_id'=>$i === 0 ? $assessment['source_snapshot_id'] : 'synthetic-mirror-'.$i,
                'source_ref_id'=>$i === 0 ? $assessment['source_ref_id'] : 'syndicated-source-mirror',
                'payload_sha256'=>$docs['after']['source_binding']['raw_sha256'],
                'tenant_id'=>$assessment['tenant_id'],
                'workspace_id'=>$assessment['workspace_id'],
            ];
        }
        $input = [
            'before_body'=>$case['before_html'],
            'after_body'=>$case['after_html'],
            'before_body_sha256'=>$docs['before']['source_binding']['raw_sha256'],
            'after_body_sha256'=>$docs['after']['source_binding']['raw_sha256'],
            'before_evidence_id'=>$beforeEvidenceId,
            'after_evidence_id'=>$assessment['evidence_id'],
            'before_snapshot_id'=>$beforeSnapshotId,
            'after_snapshot_id'=>$assessment['source_snapshot_id'],
            'format'=>'HTML_NAV_SUBSET',
        ];
        $feature = ObservationFeatureSet::compose(
            $assessment, $delta->value(), $captures, $input,
        );
        if (!$feature->isOk()) {
            throw new \RuntimeException('OB03_FEATURE_TEST_PAIR_INVALID: '.$feature->reasonCode());
        }
        return [
            'assessment'=>$assessment,
            'delta'=>$delta->value(),
            'captures'=>$captures,
            'bounded_input'=>$input,
            'result'=>$feature->value(),
        ];
    }
}
