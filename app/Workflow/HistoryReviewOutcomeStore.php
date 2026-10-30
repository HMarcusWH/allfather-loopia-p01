<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-04 metadata-only family345 outcome/follow-up ledger.
 *
 * Depends on a real row in the separately disposable AN-03 family330
 * candidate store, NOT a released customer finding. No runtime factory.
 * Later knowledge always appends and never updates the original as-of
 * review or its supporting M05 evidence references.
 */
final class HistoryReviewOutcomeStore
{
    public const VERSION='an04.disposable-longitudinal-outcomes.v1';
    private function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ){}

    public static function forDisposableTest(Database $db,Clock $clock):Outcome
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_AN04_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_AN04_DISPOSABLE_SCHEMA_LOADED')!==true){
            return Outcome::refuse('AN04_FAMILY345_RUNTIME_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    public static function forRuntime(Database $db,Clock $clock,array $owner=[]):Outcome
    {
        return Outcome::refuse('AN04_U03_U07_U10_RUNTIME_OUTCOME_NOT_ADOPTED');
    }

    /** @param array<string,mixed> $finding @param array<string,mixed> $annotation */
    public function recordAnnotation(array $finding,array $annotation):Outcome
    {
        if(!HistoricalFindingAdapter::verify($finding)->isOk()
            || !HistoryReviewOutcomes::verify($annotation)->isOk()
            || $finding['finding_digest']!==$annotation['finding_digest']
            || $finding['scope']!==$annotation['scope']
            || $finding['review_id']!==$annotation['review_id']
            || $finding['basis_digest']!==$annotation['historical_basis_digest']
            || $finding['known_at_cutoff_utc']!==$annotation['historical_knowledge_cutoff_utc']
            || $finding['dependency_graph']['graph_digest']
                !==$annotation['original_dependency_graph_digest']
            || self::originalSources($finding)!==$annotation['original_source_ref_ids']){

            return Outcome::refuse('AN04_ANNOTATION_OR_FINDING_BINDING_INVALID');
        }
        $scope=$finding['scope'];$json=self::json($annotation);
        try{
            return $this->db->transaction(function(Database $tx)use(
                $scope,$finding,$annotation,$json
            ):Outcome{
                $row=$tx->one(
                    'SELECT historical_finding_candidate_id,candidate_json
                     FROM historical_finding_candidates
                     WHERE tenant_id=? AND workspace_id=? AND competitor_id=?
                       AND question_id=? AND finding_digest=?
                       AND candidate_state=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],
                     $finding['finding_digest'],'TEST_ONLY_NOT_ADMITTED'],
                );
                if($row===null || !hash_equals(
                    (string)$row['candidate_json'],self::json($finding))){
                    return Outcome::refuse('AN04_NO_EXACT_DISPOSABLE_AN03_FINDING');
                }
                $findingId=$row['historical_finding_candidate_id'];
                $existing=$tx->one(
                    'SELECT history_review_outcome_annotation_id,annotation_json
                     FROM history_review_outcome_annotations
                     WHERE tenant_id=? AND workspace_id=? AND annotation_digest=?
                     FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $annotation['annotation_digest']],
                );
                if($existing!==null){
                    if(!hash_equals((string)$existing['annotation_json'],$json)){
                        return Outcome::refuse('AN04_OUTCOME_DIGEST_REPLAY_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_OUTCOME_REPLAY_NO_WRITE',
                        'history_review_outcome_annotation_id'=>$existing['history_review_outcome_annotation_id'],
                        'business_outcome_adjudicated'=>false,
                    ]);
                }
                $successorDigest=null;
                if($annotation['prior_annotation_digest']!==null){
                    $prior=$tx->one(
                        'SELECT inspected_at_utc,annotation_json FROM history_review_outcome_annotations
                         WHERE historical_finding_candidate_id=? AND tenant_id=?
                           AND workspace_id=? AND annotation_digest=?
                           AND candidate_state=? FOR UPDATE',
                        [$findingId,$scope['tenant_id'],$scope['workspace_id'],
                         $annotation['prior_annotation_digest'],'TEST_ONLY_NOT_ADMITTED'],
                    );
                    if($prior===null
                        || $prior['inspected_at_utc']>=$annotation['inspected_at_utc']){
                        return Outcome::refuse('AN04_OUTCOME_PREDECESSOR_NOT_IN_SCOPE');
                    }
                    $priorCandidate=json_decode((string)$prior['annotation_json'],
                        true,64,JSON_THROW_ON_ERROR);
                    $successor=HistoryReviewAssessmentSuccessor::compose(
                        $priorCandidate,$annotation,
                    );
                    if(!$successor->isOk()){
                        return Outcome::refuse('AN04_ASSESSMENT_SUCCESSOR_NOT_REPLAYABLE');
                    }
                    $successorDigest=$successor->value()['successor_digest'];
                }
                $id=Ids::new();
                $tx->insert('history_review_outcome_annotations',[
                    'history_review_outcome_annotation_id'=>$id,
                    'historical_finding_candidate_id'=>$findingId,
                    'tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id'],
                    'competitor_id'=>$scope['competitor_id'],
                    'question_id'=>$scope['question_id'],
                    'finding_digest'=>$finding['finding_digest'],
                    'annotation_digest'=>$annotation['annotation_digest'],
                    'prior_annotation_digest'=>$annotation['prior_annotation_digest'],
                    'inspected_at_utc'=>$annotation['inspected_at_utc'],
                    'reported_disposition'=>$annotation['reported_disposition'],
                    'annotation_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_OUTCOME_ANNOTATION_RECORDED',
                    'history_review_outcome_annotation_id'=>$id,
                    'annotation_digest'=>$annotation['annotation_digest'],
                    'prior_annotation_digest'=>$annotation['prior_annotation_digest'],
                    'reported_disposition'=>$annotation['reported_disposition'],
                    'assessment_successor_candidate_digest'=>$successorDigest,
                    'business_outcome_adjudicated'=>false,
                    'source_fetch_or_provider_authorized'=>false,
                    'customer_release_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AN04_OUTCOME_WRITE_RACE_HOLD');
        }catch(\Throwable){
            return Outcome::failure('AN04_DISPOSABLE_OUTCOME_STORAGE_FAILED');
        }
    }

    public function recordFollowUp(array $followup):Outcome
    {
        if(!HistoryReviewFollowUp::verify($followup)->isOk()){
            return Outcome::refuse('AN04_FOLLOWUP_NOT_REPLAYABLE');
        }
        $scope=$followup['scope'];$json=self::json($followup);
        try {
            return $this->db->transaction(function(Database $tx)use(
                $scope,$followup,$json
            ):Outcome{
                $row=$tx->one(
                    'SELECT a.history_review_outcome_annotation_id,a.annotation_json
                     FROM history_review_outcome_annotations a
                     JOIN historical_finding_candidates f
                       ON f.historical_finding_candidate_id=a.historical_finding_candidate_id
                     WHERE a.tenant_id=? AND a.workspace_id=?
                       AND f.competitor_id=? AND f.question_id=?
                       AND a.annotation_digest=? AND a.candidate_state=?
                       AND f.candidate_state=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],
                     $followup['outcome_annotation_digest'],
                     'TEST_ONLY_NOT_ADMITTED','TEST_ONLY_NOT_ADMITTED'],
                );
                if($row===null){
                    return Outcome::refuse('AN04_FOLLOWUP_PARENT_NOT_AVAILABLE');
                }
                $parent=json_decode((string)$row['annotation_json'],true,64,JSON_THROW_ON_ERROR);
                if(!is_array($parent)
                    || !HistoryReviewOutcomes::verify($parent)->isOk()
                    || $parent['finding_digest']!==$followup['finding_digest']
                    || $parent['scope']!==$scope
                    || $parent['review_id']!==$followup['review_id']
                    || $parent['reported_disposition']!==$followup['reported_disposition']
                    || $parent['original_dependency_graph_digest']
                        !==$followup['original_dependency_graph_digest']
                    || $parent['observer']!==$followup['annotation_observer']
                    || array_diff($followup['target_source_ref_ids'],array_values(
                        array_unique([
                            ...$parent['original_source_ref_ids'],
                            ...array_column($parent['later_evidence_refs'],'source_ref_id'),
                        ])
                    ))!==[]
                    || $followup['requested_at_utc']<$parent['inspected_at_utc']){

                    return Outcome::refuse('AN04_FOLLOWUP_PARENT_IDENTITY_MISMATCH');
                }
                $old=$tx->one(
                    'SELECT history_review_followup_condition_id,followup_json
                     FROM history_review_followup_conditions
                     WHERE tenant_id=? AND workspace_id=? AND followup_digest=?
                     FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $followup['followup_digest']],
                );
                if($old!==null){
                    if(!hash_equals((string)$old['followup_json'],$json)){
                        return Outcome::refuse('AN04_FOLLOWUP_DIGEST_REPLAY_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_FOLLOWUP_REPLAY_NO_WRITE',
                        'history_review_followup_condition_id'=>$old['history_review_followup_condition_id'],
                        'scheduler_job_created'=>false,
                    ]);
                }
                $id=Ids::new();
                $tx->insert('history_review_followup_conditions',[
                    'history_review_followup_condition_id'=>$id,
                    'history_review_outcome_annotation_id'=>$row['history_review_outcome_annotation_id'],
                    'tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id'],
                    'followup_digest'=>$followup['followup_digest'],
                    'candidate_kind'=>$followup['followup_kind'],
                    'not_before_utc'=>$followup['not_before_utc'],
                    'deadline_utc'=>$followup['deadline_utc'],
                    'followup_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_FOLLOWUP_CONDITION_RECORDED',
                    'history_review_followup_condition_id'=>$id,
                    'outcome_annotation_digest'=>$followup['outcome_annotation_digest'],
                    'scheduler_job_created'=>false,
                    'source_acquisition_authorized'=>false,
                    'provider_or_customer_action_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AN04_FOLLOWUP_WRITE_RACE_HOLD');
        }catch(\Throwable){
            return Outcome::failure('AN04_DISPOSABLE_FOLLOWUP_STORAGE_FAILED');
        }
    }

    /** An ambiguous same-parent fork stays explicit; no latest-write-wins. */
    public function candidateHeads(array $scope,string $findingDigest):Outcome
    {
        if(!Vocabulary::scope($scope)
            || preg_match('/^[a-f0-9]{64}$/D',$findingDigest)!==1){
            return Outcome::refuse('AN04_HEAD_SELECTOR_INVALID');
        }
        try{
            $heads=$this->db->all(
                'SELECT a.annotation_digest,a.reported_disposition,a.inspected_at_utc
                 FROM history_review_outcome_annotations a
                 JOIN historical_finding_candidates f
                   ON f.historical_finding_candidate_id=a.historical_finding_candidate_id
                 WHERE a.tenant_id=? AND a.workspace_id=?
                   AND f.competitor_id=? AND f.question_id=?
                   AND a.finding_digest=? AND NOT EXISTS(
                     SELECT 1 FROM history_review_outcome_annotations child
                     WHERE child.prior_annotation_digest=a.annotation_digest
                       AND child.historical_finding_candidate_id=a.historical_finding_candidate_id
                       AND child.tenant_id=a.tenant_id AND child.workspace_id=a.workspace_id
                   )
                 ORDER BY a.annotation_digest LIMIT 65',
                [$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],$findingDigest],
            );
            return Outcome::ok([
                'version'=>self::VERSION,
                'status'=>count($heads)>1?'AMBIGUOUS_OUTCOME_HEAD_HOLD'
                    :(count($heads)===1?'ONE_UNADJUDICATED_CANDIDATE_HEAD':'NO_CANDIDATE'),
                'heads'=>$heads,
                'human_business_outcome_adjudicated'=>false,
                'automatic_prompt_or_policy_update'=>false,
                'customer_release_authorized'=>false,
            ]);
        }catch(\Throwable){
            return Outcome::failure('AN04_DISPOSABLE_HEAD_READ_FAILED');
        }
    }

    /** Read-only reconstruction: the original and later candidate cannot be collapsed. */
    public function candidateAssessmentSuccessor(array $scope,string $annotationDigest):Outcome
    {
        if(!Vocabulary::scope($scope)
            || preg_match('/^[a-f0-9]{64}$/D',$annotationDigest)!==1){
            return Outcome::refuse('AN04_SUCCESSOR_SELECTOR_INVALID');
        }
        try{
            $child=$this->db->one(
                'SELECT a.annotation_json,a.prior_annotation_digest,
                        a.historical_finding_candidate_id
                 FROM history_review_outcome_annotations a
                 JOIN historical_finding_candidates f
                   ON f.historical_finding_candidate_id=a.historical_finding_candidate_id
                 WHERE a.tenant_id=? AND a.workspace_id=?
                   AND f.competitor_id=? AND f.question_id=?
                   AND a.annotation_digest=? AND a.candidate_state=?
                   AND f.candidate_state=?',
                [$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],
                 $annotationDigest,'TEST_ONLY_NOT_ADMITTED','TEST_ONLY_NOT_ADMITTED'],
            );
            if($child===null || $child['prior_annotation_digest']===null){
                return Outcome::refuse('AN04_NOT_A_SCOPED_ASSESSMENT_SUCCESSOR');
            }
            $prior=$this->db->one(
                'SELECT annotation_json FROM history_review_outcome_annotations
                 WHERE historical_finding_candidate_id=?
                   AND tenant_id=? AND workspace_id=?
                   AND annotation_digest=? AND candidate_state=?',
                [$child['historical_finding_candidate_id'],
                 $scope['tenant_id'],$scope['workspace_id'],
                 $child['prior_annotation_digest'],'TEST_ONLY_NOT_ADMITTED'],
            );
            if($prior===null){
                return Outcome::refuse('AN04_ASSESSMENT_ANCESTOR_MISSING');
            }
            $priorValue=json_decode((string)$prior['annotation_json'],true,64,JSON_THROW_ON_ERROR);
            $childValue=json_decode((string)$child['annotation_json'],true,64,JSON_THROW_ON_ERROR);
            $res=HistoryReviewAssessmentSuccessor::compose($priorValue,$childValue);
            if(!$res->isOk()){
                return Outcome::refuse('AN04_ASSESSMENT_SUCCESSOR_READBACK_HOLD');
            }
            return $res;
        }catch(\Throwable){
            return Outcome::failure('AN04_ASSESSMENT_SUCCESSOR_READBACK_FAILED');
        }
    }

    private static function originalSources(array $finding):array
    {
        $sources=array_values(array_unique(
            array_column($finding['primary_references'],'source_ref_id'),
        ));
        sort($sources,SORT_STRING);
        return $sources;
    }

    private static function json(array $v):string
    {
        return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
}
