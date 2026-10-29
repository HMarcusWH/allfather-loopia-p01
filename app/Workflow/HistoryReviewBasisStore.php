<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Ids,Outcome};
use Allfather\Loopia\Db\Database;

/**
 * LI-05C: disposable-only storage for pre-U03 *metadata* basis candidates.
 * No runtime factory, no source permission, no model context and no source bytes.
 * The DDL is held in docs/longitudinal/proposals, outside runtime migrations.
 */
final class HistoryReviewBasisStore
{
    public const VERSION='li05c.disposable-basis-store.v1';

    private function __construct(private readonly Database $db, private readonly Clock $clock) {}

    public static function forDisposableTest(Database $db,Clock $clock): Outcome
    {
        if (PHP_SAPI!=='cli'
            || !defined('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED')!==true) {
            return Outcome::refuse('LI05C_PERSISTENCE_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    /** @param array<string,mixed> $manifest */
    public function recordCandidate(array $manifest): Outcome
    {
        $v2=($manifest['schema_version']??null)===PointInTimeHistoricalBasis::VERSION;
        $verified=$v2 ? PointInTimeHistoricalBasis::verify($manifest) : HistoryReviewBasis::verify($manifest);
        if (!$verified->isOk()) return Outcome::refuse('LI05C_BASIS_MANIFEST_INVALID');
        // Source eligibility never becomes true merely by writing metadata.
        if ($manifest['historical_reuse_admitted']!==false
            || !in_array($manifest['status'],['OFFLINE_NOT_ADMITTED','INSUFFICIENT_BASIS'],true)) {
            return Outcome::refuse('LI05C_SOURCE_AUTHORITY_UNAVAILABLE');
        }
        $scope=$manifest['scope'];
        $digest=$v2?$manifest['basis_digest']:$manifest['manifest_digest'];
        try {
            return $this->db->transaction(function(Database $tx)use($scope,$manifest,$digest,$v2):Outcome {
                $prior=$tx->one(
                    'SELECT history_review_basis_id,manifest_json FROM history_review_bases
                     WHERE tenant_id=? AND workspace_id=? AND competitor_id=?
                       AND question_id=? AND basis_digest=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],$digest],
                );
                $encoded=json_encode($manifest, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                if ($prior!==null) {
                    if (!hash_equals((string)$prior['manifest_json'],$encoded)) {
                        return Outcome::refuse('LI05C_BASIS_IDENTITY_CONFLICT');
                    }
                    return Outcome::ok(['action'=>'EXACT_REPLAY_NO_WRITE',
                        'history_review_basis_id'=>$prior['history_review_basis_id'],
                        'historical_reuse_admitted'=>false]);
                }
                $id=Ids::new();
                $tx->insert('history_review_bases',[
                    'history_review_basis_id'=>$id,
                    'tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id'],
                    'competitor_id'=>$scope['competitor_id'],
                    'question_id'=>$scope['question_id'],
                    'basis_digest'=>$digest,
                    'manifest_json'=>$encoded,
                    'period_start_utc'=>$manifest['period']['start_inclusive_utc'],
                    'period_end_utc'=>$manifest['period']['end_exclusive_utc'],
                    'knowledge_cutoff_utc'=>$manifest['knowledge_cutoff_utc'],
                    'selected_count'=>$manifest['selected_count'],
                    'selection_integrity'=>$manifest['selection_integrity'],
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                foreach (($v2?$manifest['selected_references']:$manifest['references']) as $ordinal=>$ref) {
                    $tx->insert('history_review_basis_refs',[
                        'history_review_basis_ref_id'=>Ids::new(),
                        'history_review_basis_id'=>$id,
                        'tenant_id'=>$scope['tenant_id'],
                        'workspace_id'=>$scope['workspace_id'],
                        'ordinal_no'=>$ordinal,
                        'ref_id'=>$ref['ref_id'],
                        'source_ref_id'=>$ref['source_ref_id'],
                        'evidence_id'=>$ref['evidence_id'],
                        'snapshot_id'=>$ref['snapshot_id'],
                        'observed_at_utc'=>$ref['observed_at'],
                        'known_at_utc'=>$ref['known_at'],
                        'payload_sha256'=>$ref['payload_sha256'],
                        'semantic_polarity'=>$ref['semantic_polarity'],
                        'created_at'=>$this->clock->now(),
                    ]);
                }
                return Outcome::ok(['action'=>'DISPOSABLE_BASIS_RECORDED',
                    'history_review_basis_id'=>$id,
                    'basis_digest'=>$digest,
                    'selected_count'=>$manifest['selected_count'],
                    'historical_reuse_admitted'=>false]);
            });
        } catch (\PDOException $e) {
            return Database::classify($e,'LI05C_BASIS_RACE_REFUSED');
        } catch (\Throwable) {
            return Outcome::failure('LI05C_DISPOSABLE_BASIS_PERSISTENCE_FAILED');
        }
    }

    /**
     * Read-back always requires exact tenant, workspace, competitor and question.
     * IDs with no matching owner scope are indistinguishable from missing IDs.
     * No raw bytes or model-access tokens are returned.
     * @param array<string,string> $scope
     */
    public function replayCandidate(array $scope,string $basisId): Outcome
    {
        if (!self::scope($scope)||!Ids::isValid($basisId)) {
            return Outcome::refuse('LI05C_BASIS_SELECTOR_INVALID');
        }
        try {
            $row=$this->db->one(
                'SELECT manifest_json,basis_digest,selected_count
                 FROM history_review_bases
                 WHERE history_review_basis_id=? AND tenant_id=? AND workspace_id=?
                   AND competitor_id=? AND question_id=? AND candidate_state=?',
                [$basisId,$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],'TEST_ONLY_NOT_ADMITTED'],
            );
            if ($row===null) return Outcome::refuse('LI05C_BASIS_NOT_FOUND');
            $manifest=json_decode($row['manifest_json'],true,64,JSON_THROW_ON_ERROR);
            if (!is_array($manifest)
                || (($manifest['basis_digest']??$manifest['manifest_digest']??null)!==$row['basis_digest'])
                || ($manifest['selected_count']??null)!==(int)$row['selected_count']
                || $manifest['scope']!==$scope
                || !(($manifest['schema_version']??null)===PointInTimeHistoricalBasis::VERSION
                    ? PointInTimeHistoricalBasis::verify($manifest)
                    : HistoryReviewBasis::verify($manifest))->isOk()) {
                return Outcome::refuse('LI05C_BASIS_REPLAY_MISMATCH');
            }
            $refs=$this->db->all(
                'SELECT ref_id,source_ref_id,evidence_id,snapshot_id,
                        observed_at_utc,known_at_utc,payload_sha256,semantic_polarity
                 FROM history_review_basis_refs
                 WHERE history_review_basis_id=? AND tenant_id=? AND workspace_id=?
                 ORDER BY ordinal_no',
                [$basisId,$scope['tenant_id'],$scope['workspace_id']],
            );
            if (count($refs)!==$manifest['selected_count']) {
                return Outcome::refuse('LI05C_BASIS_REPLAY_REF_COUNT_MISMATCH');
            }
            foreach ($refs as $ordinal=>$rowRef) {
                $referenceRows=($manifest['schema_version']??null)===PointInTimeHistoricalBasis::VERSION
                    ? $manifest['selected_references'] : $manifest['references'];
                $ref=$referenceRows[$ordinal]??null;
                if ($ref===null
                    || $rowRef['ref_id']!==$ref['ref_id']
                    || $rowRef['source_ref_id']!==$ref['source_ref_id']
                    || $rowRef['evidence_id']!==$ref['evidence_id']
                    || $rowRef['snapshot_id']!==$ref['snapshot_id']
                    || $rowRef['observed_at_utc']!==$ref['observed_at']
                    || $rowRef['known_at_utc']!==$ref['known_at']
                    || $rowRef['payload_sha256']!==$ref['payload_sha256']
                    || $rowRef['semantic_polarity']!==$ref['semantic_polarity']) {
                    return Outcome::refuse('LI05C_BASIS_REPLAY_REF_MISMATCH');
                }
            }
            return Outcome::ok(['integrity'=>'MATCH_METADATA_ONLY',
                'manifest'=>$manifest,'historical_reuse_admitted'=>false,
                'model_context_authorized'=>false]);
        } catch (\Throwable) {
            return Outcome::failure('LI05C_BASIS_REPLAY_DEPENDENCY_FAILED');
        }
    }

    private static function scope(array $scope): bool
    {
        if (array_keys($scope)!==['tenant_id','workspace_id','competitor_id','question_id']) {
            return false;
        }
        foreach ($scope as $id) {
            if (!is_string($id)||preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$id)!==1) {
                return false;
            }
        }
        return true;
    }
}
