<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * OB-03 disposable MariaDB metadata-only candidate persistence.
 *
 * Owner gates U02/U03 and family265 DDL are unratified; there is no runtime
 * constructor/factory or native migration. Tests alone install candidate 260
 * then 265 and set the in-process marker on a TEST workspace. Every call
 * reuses the current P01/M02/M05 owner history reader and M05 source coherence.
 *
 * No raw source text, customer record, model, source-use grant or true
 * independent-origin assertion is persisted.
 */
final class ObservationFeatureCandidateStore
{
    public const VERSION = 'ob03.disposable-feature-origin-store.v1';

    private function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ObservationHistory $history,
    ) {}

    public static function forDisposableTest(
        Database $db, Clock $clock, ObservationHistory $history,
    ): Outcome {
        if (PHP_SAPI !== 'cli'
            || !defined('ALLFATHER_OB03_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_OB03_DISPOSABLE_SCHEMA_LOADED') !== true
            || !defined('ALLFATHER_OB02_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_OB02_DISPOSABLE_SCHEMA_LOADED') !== true) {
            return Outcome::refuse('OB03_U02_U03_PERSISTENCE_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db, $clock, $history));
    }

    /**
     * This method writes only to an explicitly installed disposable QA
     * candidate schema in a TEST workspace with no external effects.
     *
     * @param array<string,mixed> $actor actual existing M02 principal
     * @param array<string,mixed> $feature pure OB03 feature candidate
     */
    public function recordFixture(
        array $actor, string $competitorId, string $assessmentId, array $feature,
    ): Outcome {
        if (!Ids::isValid($assessmentId) || !Ids::isValid($competitorId)
            || !ObservationFeatureSet::verify($feature)) {
            return Outcome::refuse('OB03_FEATURE_CANDIDATE_INVALID');
        }
        $scope = $feature['scope'] ?? null;
        if (!is_array($scope)
            || ($scope['tenant_id'] ?? null) !== ($actor['tenant_id'] ?? null)
            || ($scope['workspace_id'] ?? null) !== ($actor['workspace_id'] ?? null)
            || ($scope['competitor_id'] ?? null) !== $competitorId
            || ($feature['source_lineage']['after_snapshot_id'] ?? null) === null
            || !Ids::isValid((string)($feature['source_lineage']['after_snapshot_id'] ?? ''))) {
            return Outcome::refuse('OB03_CANDIDATE_SCOPE_MISMATCH', [], 'SCOPE','S4');
        }
        $workspace = $this->db->one(
            'SELECT environment_class,external_side_effects_allowed FROM workspaces
             WHERE tenant_id=? AND workspace_id=?',
            [$actor['tenant_id'],$actor['workspace_id']],
        );
        if ($workspace === null || $workspace['environment_class'] !== 'TEST'
            || (int)$workspace['external_side_effects_allowed'] !== 0) {
            return Outcome::refuse('OB03_DISPOSABLE_WORKSPACE_REQUIRED');
        }
        $owner = (new SourceOriginAssertionReader($this->db,$this->history))->inspect(
            $actor, $competitorId, [$feature['source_lineage']['after_snapshot_id']],
        );
        if (!$owner->isOk()) {
            return $owner;
        }
        $origin = $owner->value();
        $ref = $origin['references'][0] ?? null;
        if (!is_array($ref)
            || $ref['source_ref_id'] !== ($scope['source_ref_id'] ?? null)
            || $ref['source_license_card_id'] !== ($scope['source_card_id'] ?? null)
            || $ref['monitored_source_id'] !== ($scope['monitored_source_id'] ?? null)
            || $ref['p01_canonical_content_hash'] !==
                ($feature['source_lineage']['p01_canonical_hash_not_raw_html'] ?? null)
            || $ref['evidence_id'] !== ($feature['source_lineage']['after_evidence_id'] ?? null)) {
            return Outcome::refuse('OB03_CURRENT_M05_LINEAGE_REFUSED');
        }
        $assessment = $this->db->one(
            'SELECT record_json,lineage_digest,source_snapshot_id,evidence_id,record_kind
             FROM p01_observation_assessments
             WHERE p01_observation_assessment_id=? AND tenant_id=? AND workspace_id=?
               AND competitor_id=? AND candidate_state=?',
            [$assessmentId,$actor['tenant_id'],$actor['workspace_id'],$competitorId,'TEST_ONLY_NOT_ADMITTED'],
        );
        if ($assessment === null) {
            return Outcome::refuse('OB03_OB02_ASSESSMENT_REFERENCE_UNAVAILABLE');
        }
        try {
            $json = json_decode($assessment['record_json'],true,64,JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return Outcome::refuse('OB03_OB02_METADATA_CORRUPT');
        }
        if (!is_array($json) || !ObservationAssessment::verify($json)->isOk()
            || $assessment['lineage_digest'] !== ($feature['source_lineage']['assessment_metadata_digest'] ?? null)
            || $assessment['lineage_digest'] !== ($json['lineage_digest'] ?? null)
            || $assessment['source_snapshot_id'] !== $ref['source_snapshot_id']
            || $assessment['evidence_id'] !== $ref['evidence_id']
            || $assessment['record_kind'] !== 'LEGACY_METADATA_ONLY') {
            return Outcome::refuse('OB03_OB02_LINEAGE_MISMATCH');
        }
        $now = $this->clock->now();
        try {
            return $this->db->transaction(function (Database $tx) use (
                $actor,$competitorId,$assessmentId,$feature,$ref,$origin,$now
            ): Outcome {
                $prior = $tx->one(
                    'SELECT p01_observation_feature_id,feature_digest,feature_json
                     FROM p01_observation_features
                     WHERE tenant_id=? AND workspace_id=?
                       AND p01_observation_assessment_id=? FOR UPDATE',
                    [$actor['tenant_id'],$actor['workspace_id'],$assessmentId],
                );
                $encoded = json_encode($feature,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                if ($prior !== null) {
                    if ($prior['feature_digest'] !== $feature['feature_digest']
                        || $prior['feature_json'] !== $encoded) {
                        return Outcome::refuse('OB03_IMMUTABLE_FEATURE_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_REPLAY_NO_WRITE',
                        'feature_id'=>$prior['p01_observation_feature_id'],
                        'features_inserted'=>0,
                        'origin_assertions_inserted'=>0,
                        'native_source_use_admitted'=>false,
                    ]);
                }
                $featureId = Ids::new();
                $tx->insert('p01_observation_features', [
                    'p01_observation_feature_id'=>$featureId,
                    'tenant_id'=>$actor['tenant_id'],
                    'workspace_id'=>$actor['workspace_id'],
                    'competitor_id'=>$competitorId,
                    'p01_observation_assessment_id'=>$assessmentId,
                    'source_snapshot_id'=>$ref['source_snapshot_id'],
                    'evidence_id'=>$ref['evidence_id'],
                    'semantic_delta_digest'=>$feature['source_lineage']['semantic_delta_digest'],
                    'feature_digest'=>$feature['feature_digest'],
                    'feature_json'=>$encoded,
                    'result_kind'=>'OBSERVATION_FEATURE_CANDIDATE_ONLY',
                    'event_count_state'=>'UNKNOWN',
                    'commercial_materiality'=>'NOT_ASSESSED',
                    'created_at'=>$now,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                ]);
                $assertion = [
                    'schema_version'=>SourceOriginAssertionReader::VERSION,
                    'scope'=>$origin['scope'],
                    'source_ref_id'=>$ref['source_ref_id'],
                    'monitored_source_id'=>$ref['monitored_source_id'],
                    'source_snapshot_id'=>$ref['source_snapshot_id'],
                    'evidence_id'=>$ref['evidence_id'],
                    'p01_canonical_content_hash'=>$ref['p01_canonical_content_hash'],
                    'current_owner_assertion_digest'=>$origin['assertion_digest'],
                    'publisher_origin'=>'UNKNOWN',
                    'independent_origin_state'=>'NOT_ASSESSED_U02',
                    'u03_historical_reuse_admitted'=>false,
                ];
                $assertionDigest = \Allfather\Loopia\Core\Digest::canonicalDigest($assertion);
                $tx->insert('p01_source_origin_assertions', [
                    'p01_source_origin_assertion_id'=>Ids::new(),
                    'tenant_id'=>$actor['tenant_id'],
                    'workspace_id'=>$actor['workspace_id'],
                    'competitor_id'=>$competitorId,
                    'source_ref_id'=>$ref['source_ref_id'],
                    'monitored_source_id'=>$ref['monitored_source_id'],
                    'source_snapshot_id'=>$ref['source_snapshot_id'],
                    'evidence_id'=>$ref['evidence_id'],
                    'p01_canonical_content_hash'=>$ref['p01_canonical_content_hash'],
                    'assertion_digest'=>$assertionDigest,
                    'assertion_json'=>json_encode($assertion,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                    'origin_identity'=>'UNKNOWN',
                    'independent_evidence_state'=>'NOT_ASSESSED_U02',
                    'created_at'=>$now,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_METADATA_REFERENCES_RECORDED',
                    'feature_id'=>$featureId,
                    'features_inserted'=>1,
                    'origin_assertions_inserted'=>1,
                    'feature_digest'=>$feature['feature_digest'],
                    'independent_corroboration_count'=>null,
                    'native_source_use_admitted'=>false,
                ]);
            });
        } catch (\PDOException $e) {
            return Database::classify($e,'OB03_DISPOSABLE_UNIQUE_CONFLICT');
        } catch (\Throwable) {
            return Outcome::failure('OB03_DISPOSABLE_PERSISTENCE_FAILED');
        }
    }
}
