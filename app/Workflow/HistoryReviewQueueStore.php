<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AQ-01: disposable fixture-only evidence that candidate queue identity,
 * membership and claim fencing can be made idempotent and append-only.
 *
 * This is NOT a second real scheduler or a usable JobMachine claim. A
 * fixture claim has no lease, work executor, grant or external effect.
 */
final class HistoryReviewQueueStore
{
    public const VERSION='aq01.disposable-work-candidate-store.v1';

    private function __construct(private readonly Database $db,private readonly Clock $clock){}

    public static function forDisposableTest(Database $db,Clock $clock):Outcome
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_AQ01_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_AQ01_DISPOSABLE_SCHEMA_LOADED')!==true){
            return Outcome::refuse('AQ01_U05_U08_QUEUE_FACTORY_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    public static function forRuntime(Database $db,Clock $clock,array $owner=[]):Outcome
    {
        return Outcome::refuse('AQ01_U05_U08_RUNTIME_QUEUE_NOT_ADOPTED');
    }

    /** @param array<string,mixed> $candidate */
    public function recordCandidate(array $candidate):Outcome
    {
        if(!HistoryReviewQueue::verify($candidate)->isOk()){
            return Outcome::refuse('AQ01_WORK_CANDIDATE_INVALID');
        }
        $scope=$candidate['scope'];
        try {
            return $this->db->transaction(function(Database $tx)use(
                $candidate,$scope
            ):Outcome {
                $old=$tx->one(
                    'SELECT history_review_work_candidate_id,candidate_json
                     FROM history_review_work_candidates
                     WHERE tenant_id=? AND workspace_id=? AND work_digest=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $candidate['candidate_digest']],
                );
                $json=self::json($candidate);
                if($old!==null){
                    if(!hash_equals((string)$old['candidate_json'],$json)){
                        return Outcome::refuse('AQ01_WORK_DIGEST_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_WORK_REPLAY_NO_WRITE',
                        'history_review_work_candidate_id'=>$old['history_review_work_candidate_id'],
                        'live_job_created'=>false,
                    ]);
                }
                if($tx->one(
                    'SELECT 1 FROM workspaces WHERE workspace_id=? AND tenant_id=?',
                    [$scope['workspace_id'],$scope['tenant_id']],
                )===null || $tx->one(
                    'SELECT 1 FROM competitors
                     WHERE competitor_id=? AND tenant_id=? AND workspace_id=?',
                    [$scope['competitor_id'],$scope['tenant_id'],$scope['workspace_id']],
                )===null){
                    return Outcome::refuse('AQ01_NATIVE_TENANT_OR_COMPETITOR_SCOPE_MISSING');
                }
                $id=Ids::new();
                $scopeDb=['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id']];
                $tx->insert('history_review_work_candidates',[
                    'history_review_work_candidate_id'=>$id,...$scopeDb,
                    'competitor_id'=>$scope['competitor_id'],
                    'question_id'=>$scope['question_id'],
                    'work_digest'=>$candidate['candidate_digest'],
                    'policy_digest'=>$candidate['policy_digest'],
                    'basis_digest'=>$candidate['basis_digest'],
                    'due_candidate_digest'=>$candidate['due_candidate_digest'],
                    'candidate_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                foreach($candidate['members'] as $ordinal=>$member){
                    $tx->insert('history_review_work_members',[
                        'history_review_work_member_id'=>Ids::new(),
                        'history_review_work_candidate_id'=>$id,...$scopeDb,
                        'member_ref_id'=>$member['member_id'],
                        'member_kind'=>$member['member_kind'],
                        'reference_digest'=>$member['reference_digest'],
                        'member_ordinal'=>$ordinal,
                        'known_at_utc'=>$member['known_at_utc'],
                        'recorded_at_utc'=>$member['recorded_at_utc'],
                        'member_json'=>self::json($member),
                        'created_at'=>$this->clock->now(),
                    ]);
                }
                return Outcome::ok([
                    'action'=>'DISPOSABLE_WORK_CANDIDATE_RECORDED',
                    'history_review_work_candidate_id'=>$id,
                    'candidate_digest'=>$candidate['candidate_digest'],
                    'member_count'=>count($candidate['members']),
                    'live_job_created'=>false,
                    'human_review_accepted'=>false,
                    'model_call_authorized'=>false,
                    'scheduler_action_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AQ01_WORK_CANDIDATE_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('AQ01_WORK_CANDIDATE_STORAGE_FAILED');
        }
    }

    /**
     * A disposable claim CONFLICT test, not a real lease acquisition.
     * Native JobMachine remains the only runtime job/fencing owner.
     */
    public function recordFixtureClaim(
        array $scope,string $workId,string $claimDigest,
    ):Outcome {
        if(!Vocabulary::scope($scope)||!Ids::isValid($workId)
            || preg_match('/^[a-f0-9]{64}$/D',$claimDigest)!==1){
            return Outcome::refuse('AQ01_FIXTURE_CLAIM_SELECTOR_INVALID');
        }
        try {
            return $this->db->transaction(function(Database $tx)use(
                $scope,$workId,$claimDigest
            ):Outcome {
                $work=$tx->one(
                    'SELECT history_review_work_candidate_id
                     FROM history_review_work_candidates
                     WHERE history_review_work_candidate_id=?
                       AND tenant_id=? AND workspace_id=? AND competitor_id=?
                       AND question_id=? AND candidate_state=? FOR UPDATE',
                    [$workId,$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],'TEST_ONLY_NOT_ADMITTED'],
                );
                if($work===null)return Outcome::refuse('AQ01_WORK_NOT_IN_CURRENT_SCOPE');
                $old=$tx->one(
                    'SELECT history_review_work_claim_candidate_id,claim_key_digest
                     FROM history_review_work_claim_candidates
                     WHERE history_review_work_candidate_id=? FOR UPDATE',
                    [$workId],
                );
                if($old!==null){
                    if(!hash_equals($old['claim_key_digest'],$claimDigest)){
                        return Outcome::refuse('AQ01_DIFFERENT_CLAIM_REJECTED');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_FIXTURE_CLAIM_REPLAY_NO_WRITE',
                        'history_review_work_claim_candidate_id'=>$old['history_review_work_claim_candidate_id'],
                        'runtime_lease_acquired'=>false,
                    ]);
                }
                $id=Ids::new();
                $tx->insert('history_review_work_claim_candidates',[
                    'history_review_work_claim_candidate_id'=>$id,
                    'history_review_work_candidate_id'=>$workId,
                    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                    'claim_key_digest'=>$claimDigest,'fence_generation'=>1,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_FIXTURE_CLAIM_RECORDED',
                    'history_review_work_claim_candidate_id'=>$id,
                    'fence_generation'=>1,
                    'runtime_lease_acquired'=>false,
                    'scheduler_action_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AQ01_CLAIM_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('AQ01_CLAIM_STORAGE_FAILED');
        }
    }

    private static function json(array $value):string
    {
        return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
