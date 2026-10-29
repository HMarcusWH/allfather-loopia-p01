<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M17\{ReviewDue,Vocabulary};

/**
 * LI-08C disposable-only U05 policy/due journal, without a scheduler.
 *
 * A due row is an immutable human-attention *candidate* for a test fixture.
 * It is neither a model budget, job, alert, action token nor source-use grant.
 * No production constructor exists before U05 adoption.
 */
final class HistoryReviewDueStore
{
    public const VERSION='li08c.disposable-review-due-store.v1';

    private function __construct(private readonly Database $db, private readonly Clock $clock) {}

    public static function forDisposableTest(Database $db, Clock $clock): Outcome
    {
        if (PHP_SAPI!=='cli'
            || !defined('ALLFATHER_LI08C_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_LI08C_DISPOSABLE_SCHEMA_LOADED')!==true
            || !defined('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED')!==true) {
            return Outcome::refuse('LI08C_U05_REVIEW_POLICY_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    /** @param array<string,string> $scope @param array<string,mixed> $policy */
    public function recordPolicyCandidate(array $scope,array $policy): Outcome
    {
        if (!Vocabulary::scope($scope) || !self::policyValid($scope,$policy)) {
            return Outcome::refuse('LI08C_POLICY_CONTRACT_INVALID');
        }
        $digest=Digest::canonicalDigest($policy);
        try {
            return $this->db->transaction(function(Database $tx)use($scope,$policy,$digest):Outcome {
                $old=$tx->one(
                    'SELECT history_review_policy_id,policy_json FROM history_review_policies
                     WHERE tenant_id=? AND workspace_id=? AND competitor_id=?
                       AND question_id=? AND policy_digest=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],$digest],
                );
                $json=json_encode($policy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                if ($old!==null) {
                    if (!hash_equals($old['policy_json'],$json)) {
                        return Outcome::refuse('LI08C_POLICY_IDENTITY_CONFLICT');
                    }
                    return Outcome::ok(['action'=>'EXACT_POLICY_REPLAY_NO_WRITE',
                        'history_review_policy_id'=>$old['history_review_policy_id'],
                        'U05_policy_adopted'=>false]);
                }
                $id=Ids::new();
                $tx->insert('history_review_policies',[
                    'history_review_policy_id'=>$id,
                    'tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id'],
                    'competitor_id'=>$scope['competitor_id'],
                    'question_id'=>$scope['question_id'],
                    'policy_version'=>$policy['version'],
                    'policy_digest'=>$digest,
                    'policy_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                $tx->insert('history_review_policy_events',[
                    'history_review_policy_event_id'=>Ids::new(),
                    'history_review_policy_id'=>$id,
                    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                    'event_kind'=>'CANDIDATE_RECORDED',
                    'event_digest'=>$digest,'occurred_at'=>$this->clock->now(),
                ]);
                return Outcome::ok(['action'=>'DISPOSABLE_POLICY_RECORDED',
                    'history_review_policy_id'=>$id,'policy_digest'=>$digest,
                    'U05_policy_adopted'=>false]);
            });
        } catch(\PDOException $e) {
            return Database::classify($e,'LI08C_POLICY_RACE_REFUSED');
        } catch(\Throwable) {
            return Outcome::failure('LI08C_POLICY_STORAGE_FAILED');
        }
    }

    /**
     * @param array<string,string> $scope
     * @param array<string,mixed> $state ONLY the operator's old watermark,
     * backlog and current-blocked/NOT_ASSESSED signal; the basis/clock/digest
     * come from independently read stored rows, never caller-supplied keys.
     */
    public function recordDueCandidate(
        array $scope,string $policyId,string $basisId,array $state,string $atUtc,
    ): Outcome {
        if (!Vocabulary::scope($scope)||!Ids::isValid($policyId)||!Ids::isValid($basisId)
            || !Vocabulary::utc($atUtc)
            || !Vocabulary::exactKeys($state,[
                'last_considered_basis_digest','last_considered_at_utc',
                'last_queued_at_utc','pending_work','source_eligibility',
            ])) {
            return Outcome::refuse('LI08C_DUE_SELECTOR_INVALID');
        }
        try {
            $row=$this->db->one(
                'SELECT policy_json,policy_digest FROM history_review_policies
                 WHERE history_review_policy_id=? AND tenant_id=? AND workspace_id=?
                   AND competitor_id=? AND question_id=? AND candidate_state=?',
                [$policyId,$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],'TEST_ONLY_NOT_ADMITTED'],
            );
            if ($row===null) return Outcome::refuse('LI08C_POLICY_OR_BASIS_NOT_FOUND');
            $policy=json_decode($row['policy_json'],true,24,JSON_THROW_ON_ERROR);
            if (!is_array($policy) || Digest::canonicalDigest($policy)!==$row['policy_digest']) {
                return Outcome::refuse('LI08C_POLICY_DIGEST_MISMATCH');
            }
            $basisStore=HistoryReviewBasisStore::forDisposableTest($this->db,$this->clock);
            if (!$basisStore->isOk())return Outcome::refuse('LI08C_BASIS_STORE_NOT_ADOPTED');
            $basis=$basisStore->value()->replayCandidate($scope,$basisId);
            if (!$basis->isOk())return Outcome::refuse('LI08C_POLICY_OR_BASIS_NOT_FOUND');
            $manifest=$basis->value()['manifest'];
            // The known-at cut is frozen by owner-basis metadata, never by a
            // caller's claimed event date or current wall time.
            $input=[
                'scope'=>$scope,'policy'=>$policy,'at_utc'=>$atUtc,
                'state'=>[
                    'current_basis_digest'=>$manifest['manifest_digest'],
                    'latest_basis_known_at_utc'=>$manifest['knowledge_cutoff_utc'],
                    'last_considered_basis_digest'=>$state['last_considered_basis_digest'],
                    'last_considered_at_utc'=>$state['last_considered_at_utc'],
                    'last_queued_at_utc'=>$state['last_queued_at_utc'],
                    'pending_work'=>$state['pending_work'],
                    'source_eligibility'=>$state['source_eligibility'],
                ],
            ];
            $due=ReviewDue::assess($input);
            if (!$due->isOk())return $due;
            $decision=$due->value();
            return $this->db->transaction(function(Database $tx)use(
                $scope,$policyId,$basisId,$atUtc,$manifest,$decision
            ):Outcome {
                $old=$tx->one(
                    'SELECT history_review_due_check_id,candidate_digest FROM history_review_due_checks
                     WHERE history_review_policy_id=? AND history_review_basis_id=?
                       AND evaluated_at_utc=? FOR UPDATE',
                    [$policyId,$basisId,$atUtc],
                );
                if ($old!==null) {
                    if (!hash_equals($old['candidate_digest'],$decision['candidate_digest'])) {
                        return Outcome::refuse('LI08C_DUE_CLOCK_IDENTITY_CONFLICT');
                    }
                    return Outcome::ok(['action'=>'EXACT_DUE_REPLAY_NO_WRITE',
                        'history_review_due_check_id'=>$old['history_review_due_check_id'],
                        'disposition'=>$decision['status'],'model_call_authorized'=>false]);
                }
                $id=Ids::new();
                $tx->insert('history_review_due_checks',[
                    'history_review_due_check_id'=>$id,
                    'history_review_policy_id'=>$policyId,
                    'history_review_basis_id'=>$basisId,
                    'tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id'],
                    'evaluated_at_utc'=>$atUtc,
                    'basis_digest'=>$manifest['manifest_digest'],
                    'candidate_digest'=>$decision['candidate_digest'],
                    'disposition'=>$decision['status'],
                    'due_json'=>json_encode($decision,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok(['action'=>'DISPOSABLE_DUE_RECORDED',
                    'history_review_due_check_id'=>$id,
                    'disposition'=>$decision['status'],
                    'basis_digest'=>$manifest['manifest_digest'],
                    'model_call_authorized'=>false,
                    'notification_authorized'=>false,
                    'scheduler_action_authorized'=>false,
                    'U05_policy_adopted'=>false,
                    'U03_historical_reuse_admitted'=>false]);
            });
        } catch(\PDOException $e) {
            return Database::classify($e,'LI08C_DUE_RACE_REFUSED');
        } catch(\Throwable) {
            return Outcome::failure('LI08C_DUE_STORAGE_FAILED');
        }
    }


    /**
     * DU-01: versioned v2 historical policy + HS-01 candidate, using the same
     * append-only family-290 DDL as LI-08C v1. No new production factory.
     *
     * @param array<string,string> $scope
     * @param array<string,mixed> $watermarks Caller-described, NEVER owner rights.
     */
    public function recordDueV2Candidate(
        array $scope,string $policyId,string $basisId,array $watermarks,string $atUtc,
    ):Outcome {
        if(!Vocabulary::scope($scope)||!Ids::isValid($policyId)
            || !Ids::isValid($basisId)||!Vocabulary::utc($atUtc)) {
            return Outcome::refuse('DU01_DUE_STORE_SELECTOR_INVALID');
        }
        try {
            $row=$this->db->one(
                'SELECT policy_json,policy_digest
                 FROM history_review_policies WHERE history_review_policy_id=?
                   AND tenant_id=? AND workspace_id=? AND competitor_id=?
                   AND question_id=? AND candidate_state=?',
                [$policyId,$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],'TEST_ONLY_NOT_ADMITTED'],
            );
            if($row===null)return Outcome::refuse('DU01_POLICY_OR_BASIS_NOT_FOUND');
            $policy=json_decode((string)$row['policy_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($policy)||!HistoryReviewPolicy::verify($policy)->isOk()
                || ($policy['scope']??null)!==$scope
                || !hash_equals((string)$row['policy_digest'],Digest::canonicalDigest($policy))) {
                return Outcome::refuse('DU01_POLICY_NOT_REPLAYABLE');
            }
            $basisStore=HistoryReviewBasisStore::forDisposableTest($this->db,$this->clock);
            if(!$basisStore->isOk())return Outcome::refuse('DU01_BASIS_STORE_NOT_ADOPTED');
            $replayed=$basisStore->value()->replayCandidate($scope,$basisId);
            if(!$replayed->isOk())return Outcome::refuse('DU01_POLICY_OR_BASIS_NOT_FOUND');
            $basis=$replayed->value()['manifest'];
            if(($basis['schema_version']??null)!==PointInTimeHistoricalBasis::VERSION
                || !PointInTimeHistoricalBasis::verify($basis)->isOk()) {
                return Outcome::refuse('DU01_HS01_BASIS_REQUIRED');
            }
            $calc=HistoryReviewDue::assess([
                'policy'=>$policy,'basis'=>$basis,
                'state'=>$watermarks,'at_utc'=>$atUtc,
            ]);
            if(!$calc->isOk())return $calc;
            $decision=$calc->value();
            $basisDigest=$basis['basis_digest'];
            return $this->db->transaction(function(Database $tx)use(
                $scope,$policyId,$basisId,$basisDigest,$atUtc,$decision
            ):Outcome {
                $old=$tx->one(
                    'SELECT history_review_due_check_id,candidate_digest
                     FROM history_review_due_checks
                     WHERE history_review_policy_id=? AND history_review_basis_id=?
                       AND evaluated_at_utc=? FOR UPDATE',
                    [$policyId,$basisId,$atUtc],
                );
                if($old!==null){
                    if(!hash_equals((string)$old['candidate_digest'],$decision['candidate_digest'])){
                        return Outcome::refuse('DU01_DUE_SAME_TICK_STATE_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_DUE_REPLAY_NO_WRITE',
                        'history_review_due_check_id'=>$old['history_review_due_check_id'],
                        'disposition'=>$decision['status'],
                        'U05_policy_adopted'=>false,
                        'model_call_authorized'=>false,
                    ]);
                }
                $id=Ids::new();
                $tx->insert('history_review_due_checks',[
                    'history_review_due_check_id'=>$id,
                    'history_review_policy_id'=>$policyId,
                    'history_review_basis_id'=>$basisId,
                    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                    'evaluated_at_utc'=>$atUtc,
                    'basis_digest'=>$basisDigest,
                    'candidate_digest'=>$decision['candidate_digest'],
                    'disposition'=>$decision['status'],
                    'due_json'=>json_encode($decision,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_DUE_RECORDED',
                    'history_review_due_check_id'=>$id,
                    'disposition'=>$decision['status'],
                    'basis_digest'=>$basisDigest,
                    'candidate_digest'=>$decision['candidate_digest'],
                    'U05_policy_adopted'=>false,
                    'U03_historical_reuse_admitted'=>false,
                    'model_call_authorized'=>false,
                    'scheduler_action_authorized'=>false,
                    'notification_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'DU01_DUE_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('DU01_DUE_STORE_UNAVAILABLE');
        }
    }

    /**
     * Test-only, read-only internal metadata. A later HTTP owner must perform
     * M02 authorization separately and must NOT install this factory in runtime.
     * @param array<string,string> $scope
     */
    public function listDueCandidates(array $scope,int $limit=20):Outcome
    {
        if(!Vocabulary::scope($scope)||$limit<1||$limit>50)
            return Outcome::refuse('LI08C_DUE_LIST_SELECTOR_INVALID');
        try {
            $rows=$this->db->all(
                'SELECT d.history_review_due_check_id,d.evaluated_at_utc,d.disposition,
                        d.basis_digest,d.candidate_digest,p.policy_digest
                 FROM history_review_due_checks d
                 JOIN history_review_policies p
                   ON p.history_review_policy_id=d.history_review_policy_id
                 WHERE d.tenant_id=? AND d.workspace_id=? AND p.competitor_id=?
                   AND p.question_id=? AND d.candidate_state=?
                 ORDER BY d.evaluated_at_utc DESC,d.history_review_due_check_id DESC LIMIT '.$limit,
                [$scope['tenant_id'],$scope['workspace_id'],
                 $scope['competitor_id'],$scope['question_id'],'TEST_ONLY_NOT_ADMITTED'],
            );
            return Outcome::ok(['status'=>'TEST_ONLY_NOT_ADMITTED',
                'rows'=>$rows,'read_only'=>true,
                'historical_reuse_admitted'=>false,'model_call_authorized'=>false]);
        } catch(\Throwable) {
            return Outcome::failure('LI08C_DUE_LIST_DEPENDENCY_FAILED');
        }
    }

    private static function policyValid(array $scope,array $policy):bool
    {
        if(($policy['version']??null)===HistoryReviewPolicy::VERSION){
            return ($policy['scope']??null)===$scope
                && HistoryReviewPolicy::verify($policy)->isOk();
        }
        if(!Vocabulary::exactKeys($policy,[
            'version','cadence_seconds','maximum_wait_seconds','cooldown_seconds','max_pending_work',
        ]))return false;
        $probe=ReviewDue::assess([
            'scope'=>$scope,'policy'=>$policy,'at_utc'=>'2026-10-26T18:00:00Z',
            'state'=>[
                'current_basis_digest'=>str_repeat('0',64),
                'latest_basis_known_at_utc'=>'2026-10-25T18:00:00Z',
                'last_considered_basis_digest'=>null,
                'last_considered_at_utc'=>null,
                'last_queued_at_utc'=>null,
                'pending_work'=>0,'source_eligibility'=>'NOT_ASSESSED',
            ],
        ]);
        return $probe->isOk();
    }
}
