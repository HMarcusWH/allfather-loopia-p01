<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-01 candidate family 310, CLI + disposable schema only.
 * Reserves hypothetical parent/per-review units but never funds or calls a
 * provider, grants an M06 role, refunds unknown transmission, or emits output.
 *
 * Race safety: parent budget row is locked FOR UPDATE before reading counts;
 * one parent and workspace share one serialized reservation gate. Same
 * request digest cannot be replayed with a different attempt identity.
 */
final class HistoryReviewAttemptStore
{
    public const VERSION='an01.disposable-historical-attempt-store.v1';

    private function __construct(private readonly Database $db,private readonly Clock $clock){}

    public static function forDisposableTest(Database $db,Clock $clock): Outcome
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_AN01_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_AN01_DISPOSABLE_SCHEMA_LOADED')!==true){
            return Outcome::refuse('AN01_FAMILY310_RUNTIME_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    public static function forRuntime(Database $db,Clock $clock,array $grant=[]):Outcome
    {
        return Outcome::refuse('AN01_U03_U06_U08_RUNTIME_MODEL_ADMISSION_NOT_ADOPTED');
    }

    /** @param array<string,mixed> $candidate */
    public function reserveCandidate(array $candidate):Outcome
    {
        if(!HistoryReviewAdmission::verify($candidate)->isOk()){
            return Outcome::refuse('AN01_UNVERIFIED_ATTEMPT_CANDIDATE');
        }
        $scope=$candidate['scope'];$json=self::json($candidate);
        $now=$this->clock->now();
        $nowSecond=is_string($now) && strlen($now)>=20
            ? substr($now,0,19).'Z' : '';
        if($nowSecond<$candidate['valid_from_utc']
            || $nowSecond>=$candidate['valid_until_utc']){
            return Outcome::refuse('AN01_CANDIDATE_REQUEST_EXPIRED_OR_NOT_YET_VALID');
        }
        try {
            return $this->db->transaction(function(Database $tx)use(
                $candidate,$scope,$json
            ):Outcome{
                $parent=$tx->one(
                    'SELECT history_review_budget_candidate_id,parent_max_attempts
                     FROM history_review_budget_candidates
                     WHERE tenant_id=? AND workspace_id=? AND parent_grant_digest=?
                       AND candidate_state=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $candidate['parent_grant_digest'],'TEST_ONLY_NOT_ADMITTED'],
                );
                if($parent===null){
                    $parentId=Ids::new();
                    $tx->insert('history_review_budget_candidates',[
                        'history_review_budget_candidate_id'=>$parentId,
                        'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                        'parent_grant_digest'=>$candidate['parent_grant_digest'],
                        'parent_max_attempts'=>$candidate['parent_max_attempts'],
                        'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                        'created_at'=>$this->clock->now(),
                    ]);
                    // Exact parent row was just created under the unique key.
                }else{
                    $parentId=$parent['history_review_budget_candidate_id'];
                    if((int)$parent['parent_max_attempts']!==$candidate['parent_max_attempts']){
                        return Outcome::refuse('AN01_PARENT_BUDGET_VERSION_CONFLICT');
                    }
                }
                $previous=$tx->one(
                    'SELECT history_review_attempt_candidate_id,request_json
                     FROM history_review_attempt_candidates
                     WHERE history_review_budget_candidate_id=? AND attempt_id=?
                     FOR UPDATE',
                    [$parentId,$candidate['attempt_id']],
                );
                if($previous!==null){
                    if(!hash_equals((string)$previous['request_json'],$json)){
                        return Outcome::refuse('AN01_ATTEMPT_IDENTITY_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_ATTEMPT_REPLAY_NO_WRITE',
                        'history_review_attempt_candidate_id'=>$previous['history_review_attempt_candidate_id'],
                        'model_call_authorized'=>false,
                    ]);
                }
                // Distinct attempt ID for the same exact request is a prohibited
                // blind retry, even if prior worker failed or lease expired.
                $duplicate=$tx->one(
                    'SELECT history_review_attempt_candidate_id
                     FROM history_review_attempt_candidates
                     WHERE history_review_budget_candidate_id=?
                       AND request_digest=? FOR UPDATE',
                    [$parentId,$candidate['request_digest']],
                );
                if($duplicate!==null)return Outcome::refuse('AN01_REQUEST_ALREADY_RESERVED_WITH_DIFFERENT_ATTEMPT');
                $reviewPolicy=$tx->one(
                    'SELECT review_max_attempts FROM history_review_attempt_candidates
                     WHERE history_review_budget_candidate_id=? AND review_id=?
                     ORDER BY history_review_attempt_candidate_id LIMIT 1 FOR UPDATE',
                    [$parentId,$candidate['review_id']],
                );
                if($reviewPolicy!==null
                    && (int)$reviewPolicy['review_max_attempts']!==$candidate['review_max_attempts']){
                    return Outcome::refuse('AN01_REVIEW_LIMIT_VERSION_CONFLICT');
                }
                $parentUsed=(int)$tx->scalar(
                    'SELECT COUNT(*) FROM history_review_attempt_candidates
                     WHERE history_review_budget_candidate_id=?',
                    [$parentId],
                );
                $reviewUsed=(int)$tx->scalar(
                    'SELECT COUNT(*) FROM history_review_attempt_candidates
                     WHERE history_review_budget_candidate_id=? AND review_id=?',
                    [$parentId,$candidate['review_id']],
                );
                if($parentUsed>=$candidate['parent_max_attempts']
                    || $reviewUsed>=$candidate['review_max_attempts']){
                    return Outcome::refuse('AN01_CANDIDATE_PARENT_OR_REVIEW_CAP_EXHAUSTED');
                }
                // Never refund on uncertainty or on a caller-described
                // NOT_SENT. The eventual authority owner must make a separate
                // reviewed accounting decision.
                $id=Ids::new();
                $tx->insert('history_review_attempt_candidates',[
                    'history_review_attempt_candidate_id'=>$id,
                    'history_review_budget_candidate_id'=>$parentId,
                    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                    'review_id'=>$candidate['review_id'],
                    'attempt_id'=>$candidate['attempt_id'],
                    'request_digest'=>$candidate['request_digest'],
                    'candidate_digest'=>$candidate['candidate_digest'],
                    'review_max_attempts'=>$candidate['review_max_attempts'],
                    'request_json'=>$json,'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_ATTEMPT_CANDIDATE_RESERVED',
                    'history_review_attempt_candidate_id'=>$id,
                    'parent_candidate_id'=>$parentId,
                    'parent_units_reserved'=>$parentUsed+1,
                    'review_units_reserved'=>$reviewUsed+1,
                    'candidate_spend_counted_no_refund'=>true,
                    'runtime_execution_authorized'=>false,
                    'provider_transport_invoked'=>false,
                    'customer_output_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AN01_PARENT_RESERVATION_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('AN01_DISPOSABLE_RESERVATION_FAILED');
        }
    }

    /**
     * Records only an integrity-bound REPORT of a provider attempt. Provider
     * owner evidence is intentionally absent: this cannot adopt a response.
     *
     * @param array<string,mixed> $input
     */
    public function recordReconciliationCandidate(
        array $scope,string $attemptCandidateId,array $input,
    ):Outcome {
        if(!Vocabulary::scope($scope)||!Ids::isValid($attemptCandidateId)){
            return Outcome::refuse('AN01_RECONCILIATION_SCOPE_INVALID');
        }
        $observedNow=$this->clock->now();
        $nowSecond=is_string($observedNow)&&strlen($observedNow)>=20
            ? substr($observedNow,0,19).'Z' : '';
        if(!Vocabulary::utc($input['inspected_at_utc']??null)
            || $input['inspected_at_utc']>$nowSecond){
            return Outcome::refuse('AN01_RECONCILIATION_FUTURE_RECEIPT_HOLD');
        }
        $result=HistoryReviewReconciliation::inspect($input);
        if(!$result->isOk())return $result;
        $candidate=$result->value();$json=self::json($candidate);
        try {
            return $this->db->transaction(function(Database $tx)use(
                $scope,$attemptCandidateId,$input,$candidate,$json
            ):Outcome{
                $attempt=$tx->one(
                    'SELECT request_json FROM history_review_attempt_candidates
                     WHERE history_review_attempt_candidate_id=? AND tenant_id=?
                       AND workspace_id=? AND candidate_state=? FOR UPDATE',
                    [$attemptCandidateId,$scope['tenant_id'],$scope['workspace_id'],
                     'TEST_ONLY_NOT_ADMITTED'],
                );
                if($attempt===null)return Outcome::refuse('AN01_ATTEMPT_NOT_IN_OWNER_SCOPE');
                $original=json_decode((string)$attempt['request_json'],true,64,JSON_THROW_ON_ERROR);
                if(!is_array($original)
                    || !HistoryReviewAdmission::verify($original)->isOk()
                    || $original!==$input['request_candidate']
                    || $original['scope']!==$scope){
                    return Outcome::refuse('AN01_RECONCILIATION_ATTEMPT_BINDING_MISMATCH');
                }
                $old=$tx->one(
                    'SELECT history_review_reconciliation_candidate_id,reconciliation_json
                     FROM history_review_reconciliation_candidates
                     WHERE history_review_attempt_candidate_id=?
                       AND reconciliation_digest=? FOR UPDATE',
                    [$attemptCandidateId,$candidate['reconciliation_digest']],
                );
                if($old!==null){
                    if(!hash_equals((string)$old['reconciliation_json'],$json)){
                        return Outcome::refuse('AN01_RECONCILIATION_IDENTITY_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_RECONCILIATION_REPLAY_NO_WRITE',
                        'history_review_reconciliation_candidate_id'=>$old['history_review_reconciliation_candidate_id'],
                        'provider_response_admitted'=>false,
                    ]);
                }
                $id=Ids::new();
                $tx->insert('history_review_reconciliation_candidates',[
                    'history_review_reconciliation_candidate_id'=>$id,
                    'history_review_attempt_candidate_id'=>$attemptCandidateId,
                    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                    'reconciliation_digest'=>$candidate['reconciliation_digest'],
                    'reconciliation_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_RECONCILIATION_RECORDED',
                    'history_review_reconciliation_candidate_id'=>$id,
                    'status'=>$candidate['status'],
                    'provider_response_admitted'=>false,
                    'automatic_paid_retry_authorized'=>false,
                    'parent_budget_refunded'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AN01_RECONCILIATION_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('AN01_DISPOSABLE_RECONCILIATION_FAILED');
        }
    }

    private static function json(array $value):string
    {
        return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
