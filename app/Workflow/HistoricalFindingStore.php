<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-03 disposable metadata ledger over proposed family 330.
 *
 * It is NOT the M04/M05 evidence owner, the M07/M08 adjudicator, or the
 * M10/M16 release ledger; an immutable finding row never becomes a product
 * release. No production constructor, provider, source fetch or P02 write.
 */
final class HistoricalFindingStore
{
    public const VERSION='an03.disposable-multi-reference-binding-store.v1';

    private function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ){}

    public static function forDisposableTest(Database $db,Clock $clock):Outcome
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_AN03_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_AN03_DISPOSABLE_SCHEMA_LOADED')!==true){
            return Outcome::refuse('AN03_FAMILY330_RUNTIME_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    public static function forRuntime(Database $db,Clock $clock,array $owner=[]):Outcome
    {
        return Outcome::refuse('AN03_U03_U06_U07_NATIVE_FINDING_OWNER_NOT_ADOPTED');
    }

    /** Strict source-only transactional candidate insertion. */
    public function persistCandidate(array $finding):Outcome
    {
        if(!HistoricalFindingAdapter::verify($finding)->isOk()){
            return Outcome::refuse('AN03_CANDIDATE_NOT_REPLAYABLE');
        }
        $scope=$finding['scope'];$json=self::json($finding);
        try {
            return $this->db->transaction(function(Database $tx)use(
                $finding,$scope,$json
            ):Outcome{
                if($tx->one(
                    'SELECT 1 FROM workspaces WHERE workspace_id=? AND tenant_id=?',
                    [$scope['workspace_id'],$scope['tenant_id']],
                )===null || $tx->one(
                    'SELECT 1 FROM competitors WHERE competitor_id=?
                       AND tenant_id=? AND workspace_id=?
                       AND status IN (?,?)',
                    [$scope['competitor_id'],$scope['tenant_id'],$scope['workspace_id'],
                     'ACTIVE','PAUSED'],
                )===null){
                    return Outcome::refuse('AN03_NATIVE_TENANT_COMPETITOR_SCOPE_NOT_AVAILABLE');
                }
                $existing=$tx->one(
                    'SELECT historical_finding_candidate_id,candidate_json
                     FROM historical_finding_candidates
                     WHERE tenant_id=? AND workspace_id=? AND finding_digest=?
                       AND candidate_state=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $finding['finding_digest'],'TEST_ONLY_NOT_ADMITTED'],
                );
                if($existing!==null){
                    if(!hash_equals((string)$existing['candidate_json'],$json)){
                        return Outcome::refuse('AN03_REPLAY_CANDIDATE_DIGEST_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_FINDING_REPLAY_NO_WRITE',
                        'historical_finding_candidate_id'=>$existing['historical_finding_candidate_id'],
                        'source_reuse_admitted'=>false,
                        'm10_release_authorized'=>false,
                    ]);
                }
                $id=Ids::new();
                $smallScope=['tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id']];
                $tx->insert('historical_finding_candidates',[
                    'historical_finding_candidate_id'=>$id,
                    ...$smallScope,
                    'competitor_id'=>$scope['competitor_id'],
                    'question_id'=>$scope['question_id'],
                    'review_id'=>$finding['review_id'],
                    'finding_digest'=>$finding['finding_digest'],
                    'review_run_digest'=>$finding['review_run_digest'],
                    'basis_digest'=>$finding['basis_digest'],
                    'dependency_graph_digest'=>$finding['dependency_graph']['graph_digest'],
                    'candidate_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                foreach($finding['primary_references'] as $ordinal=>$ref){
                    $tx->insert('historical_finding_basis_bindings',[
                        'historical_finding_basis_binding_id'=>Ids::new(),
                        'historical_finding_candidate_id'=>$id,
                        ...$smallScope,
                        'ref_ordinal'=>$ordinal,
                        'ref_id'=>$ref['ref_id'],
                        'source_ref_id'=>$ref['source_ref_id'],
                        'evidence_id'=>$ref['evidence_id'],
                        'snapshot_id'=>$ref['snapshot_id'],
                        'source_content_digest'=>$ref['payload_sha256'],
                        'semantic_polarity'=>$ref['polarity'],
                        'cited_as_support'=>$ref['cited_as_support']?1:0,
                        'contrary_retained'=>$ref['contrary_retained']?1:0,
                        'known_at_utc'=>$ref['known_at_utc'],
                        'reference_json'=>self::json($ref),
                        'created_at'=>$this->clock->now(),
                    ]);
                }
                $events=$finding['review_chain_event_digests'];
                $tx->insert('historical_finding_review_links',[
                    'historical_finding_review_link_id'=>Ids::new(),
                    'historical_finding_candidate_id'=>$id,
                    ...$smallScope,
                    'analyst_candidate_digest'=>$finding['analyst_candidate_digest'],
                    'critic_candidate_digest'=>$finding['critic_candidate_digest'],
                    'review_event_1_digest'=>$events[0],
                    'review_event_2_digest'=>$events[1],
                    'review_event_3_digest'=>$events[2],
                    'human_factual_review_approved'=>0,
                    'human_delivery_review_approved'=>0,
                    'm10_release_authorized'=>0,
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_FINDING_CANDIDATE_RECORDED',
                    'historical_finding_candidate_id'=>$id,
                    'finding_digest'=>$finding['finding_digest'],
                    'bound_primary_ref_count'=>count($finding['primary_references']),
                    'two_human_approvals_pending'=>true,
                    'native_m10_release_authorized'=>false,
                    'customer_visibility_authorized'=>false,
                ]);
            });
        }catch(\PDOException $error){
            return Database::classify($error,'AN03_FINDING_CANDIDATE_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('AN03_DISPOSABLE_FINDING_STORAGE_FAILED');
        }
    }

    /**
     * Metadata-only, exact-scope replay verifies the candidate and ALL
     * original individual refs plus the journal digest binding. A DB row
     * never grants historical M05 source use or releases a CompanySignal.
     */
    public function replayCandidate(array $scope,string $findingId):Outcome
    {
        if(!Vocabulary::scope($scope)||!Ids::isValid($findingId)){
            return Outcome::refuse('AN03_FINDING_REPLAY_SELECTOR_INVALID');
        }
        try{
            $row=$this->db->one(
                'SELECT finding_digest,basis_digest,review_run_digest,
                    dependency_graph_digest,candidate_json
                 FROM historical_finding_candidates
                 WHERE historical_finding_candidate_id=?
                    AND tenant_id=? AND workspace_id=?
                    AND competitor_id=? AND question_id=?
                    AND candidate_state=?',
                [$findingId,$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],
                 'TEST_ONLY_NOT_ADMITTED'],
            );
            if($row===null)return Outcome::refuse('AN03_FINDING_NOT_IN_CURRENT_SCOPE');
            $candidate=json_decode((string)$row['candidate_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($candidate)
                || !HistoricalFindingAdapter::verify($candidate)->isOk()
                || $candidate['scope']!==$scope
                || $candidate['finding_digest']!==$row['finding_digest']
                || $candidate['basis_digest']!==$row['basis_digest']
                || $candidate['review_run_digest']!==$row['review_run_digest']
                || $candidate['dependency_graph']['graph_digest']!==$row['dependency_graph_digest']){
                return Outcome::refuse('AN03_STORED_FINDING_DIGEST_OR_SCOPE_INVALID');
            }
            $rows=$this->db->all(
                'SELECT ref_ordinal,ref_id,source_ref_id,evidence_id,
                        snapshot_id,source_content_digest,semantic_polarity,
                        cited_as_support,contrary_retained,known_at_utc,
                        reference_json
                 FROM historical_finding_basis_bindings
                 WHERE historical_finding_candidate_id=? AND tenant_id=?
                    AND workspace_id=? ORDER BY ref_ordinal',
                [$findingId,$scope['tenant_id'],$scope['workspace_id']],
            );
            if(count($rows)!==count($candidate['primary_references'])){
                return Outcome::refuse('AN03_STORED_PRIMARY_BINDING_COUNT_MISMATCH');
            }
            foreach($rows as $i=>$record){
                $ref=$candidate['primary_references'][$i];
                if((int)$record['ref_ordinal']!==$i
                    || $record['ref_id']!==$ref['ref_id']
                    || $record['source_ref_id']!==$ref['source_ref_id']
                    || $record['evidence_id']!==$ref['evidence_id']
                    || $record['snapshot_id']!==$ref['snapshot_id']
                    || $record['source_content_digest']!==$ref['payload_sha256']
                    || $record['semantic_polarity']!==$ref['polarity']
                    || (int)$record['cited_as_support']!==($ref['cited_as_support']?1:0)
                    || (int)$record['contrary_retained']!==($ref['contrary_retained']?1:0)
                    || $record['known_at_utc']!==$ref['known_at_utc']
                    || !hash_equals((string)$record['reference_json'],self::json($ref))){
                    return Outcome::refuse('AN03_STORED_PRIMARY_BINDING_INVALID');
                }
            }
            $review=$this->db->one(
                'SELECT analyst_candidate_digest,critic_candidate_digest,
                        review_event_1_digest,review_event_2_digest,
                        review_event_3_digest,
                        human_factual_review_approved,human_delivery_review_approved,
                        m10_release_authorized
                 FROM historical_finding_review_links
                 WHERE historical_finding_candidate_id=?
                   AND tenant_id=? AND workspace_id=?',
                [$findingId,$scope['tenant_id'],$scope['workspace_id']],
            );
            $e=$candidate['review_chain_event_digests'];
            if($review===null
                || $review['analyst_candidate_digest']!==$candidate['analyst_candidate_digest']
                || $review['critic_candidate_digest']!==$candidate['critic_candidate_digest']
                || $review['review_event_1_digest']!==$e[0]
                || $review['review_event_2_digest']!==$e[1]
                || $review['review_event_3_digest']!==$e[2]
                || (int)$review['human_factual_review_approved']!==0
                || (int)$review['human_delivery_review_approved']!==0
                || (int)$review['m10_release_authorized']!==0){
                return Outcome::refuse('AN03_STORED_HUMAN_HOLD_LINK_INVALID');
            }
            return Outcome::ok([
                'version'=>self::VERSION,
                'status'=>'PROVISIONAL_MULTI_REFERENCE_HUMAN_REVIEW_HOLD',
                'finding_digest'=>$candidate['finding_digest'],
                'review_run_digest'=>$candidate['review_run_digest'],
                'basis_digest'=>$candidate['basis_digest'],
                'dependency_graph_digest'=>$candidate['dependency_graph']['graph_digest'],
                'primary_ref_count'=>count($rows),
                'support_ref_count'=>$candidate['support_ref_count'],
                'contrary_ref_count'=>$candidate['contrary_ref_count'],
                'source_reuse_authenticated'=>false,
                'native_m10_release_authorized'=>false,
                'human_factual_release_approved'=>false,
                'human_delivery_approved'=>false,
                'p02_mutation_authorized'=>false,
                'p04_distribution_authorized'=>false,
                'customer_visibility_authorized'=>false,
            ]);
        }catch(\Throwable){
            return Outcome::failure('AN03_DISPOSABLE_FINDING_REPLAY_FAILED');
        }
    }

    private static function json(array $v):string
    {
        return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
}
