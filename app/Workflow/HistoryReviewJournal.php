<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\PromptOps\HistoryReviewCandidateChecks;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-02: CLI/disposable-only, immutable local historical run journal.
 *
 * The M02/M03/M06/M01 provider owners and native MonitorService are never
 * called or modified. Candidate 320 SQL must be separately loaded in a
 * disposable database; there is NO production constructor.
 *
 * Every stage locks its run, verifies the complete stored predecessor chain,
 * writes its digest-bound artifact FIRST and the event cursor SECOND in one
 * transaction. A crash cannot advance a cursor without a retained artifact.
 */
final class HistoryReviewJournal
{
    public const VERSION='an02.disposable-history-journal.v1';
    public const MAX_EVENTS=16;

    private function __construct(private readonly Database $db,private readonly Clock $clock){}

    public static function forDisposableTest(Database $db,Clock $clock):Outcome
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_AN02_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_AN02_DISPOSABLE_SCHEMA_LOADED')!==true){
            return Outcome::refuse('AN02_FAMILY320_PRODUCTION_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    public static function forRuntime(Database $db,Clock $clock,array $owner=[]):Outcome
    {
        return Outcome::refuse('AN02_U03_U06_RUNTIME_REVIEW_NOT_ADOPTED');
    }

    public function startRun(array $run):Outcome
    {
        if(!HistoryReviewService::verifyRun($run)->isOk()){
            return Outcome::refuse('AN02_START_RUN_NOT_REPLAYABLE');
        }
        $scope=$run['scope'];$json=self::json($run);
        try {
            return $this->db->transaction(function(Database $tx)use(
                $run,$scope,$json
            ):Outcome{
                $old=$tx->one(
                    'SELECT history_review_run_id,run_json
                     FROM history_review_runs
                     WHERE tenant_id=? AND workspace_id=? AND run_digest=?
                       AND candidate_state=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],
                     $run['run_digest'],'TEST_ONLY_NOT_ADMITTED'],
                );
                if($old!==null){
                    if(!hash_equals((string)$old['run_json'],$json)){
                        return Outcome::refuse('AN02_RUN_IDENTITY_COLLISION');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_RUN_REPLAY_NO_WRITE',
                        'history_review_run_id'=>$old['history_review_run_id'],
                        'run_digest'=>$run['run_digest'],
                        'initial_state'=>$run['state'],
                        'runtime_authorized'=>false,
                    ]);
                }
                if($tx->one(
                    'SELECT 1 FROM workspaces WHERE workspace_id=? AND tenant_id=?',
                    [$scope['workspace_id'],$scope['tenant_id']],
                )===null || $tx->one(
                    'SELECT 1 FROM competitors
                     WHERE competitor_id=? AND tenant_id=? AND workspace_id=?
                       AND status IN (?,?)',
                    [$scope['competitor_id'],$scope['tenant_id'],$scope['workspace_id'],
                     'ACTIVE','PAUSED'],
                )===null){
                    return Outcome::refuse('AN02_NATIVE_OWNER_SCOPE_NOT_AVAILABLE');
                }
                $id=Ids::new();
                $tx->insert('history_review_runs',[
                    'history_review_run_id'=>$id,
                    'tenant_id'=>$scope['tenant_id'],
                    'workspace_id'=>$scope['workspace_id'],
                    'competitor_id'=>$scope['competitor_id'],
                    'question_id'=>$scope['question_id'],
                    'review_ref_id'=>$run['review_id'],
                    'run_digest'=>$run['run_digest'],
                    'request_candidate_digest'=>$run['request_candidate_digest'],
                    'basis_digest'=>$run['basis_digest'],
                    'm17_run_digest'=>$run['m17_run_digest'],
                    'initial_state'=>$run['state'],
                    'run_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_RUN_RECORDED',
                    'history_review_run_id'=>$id,
                    'run_digest'=>$run['run_digest'],
                    'initial_state'=>$run['state'],
                    'external_provider_called'=>false,
                    'human_release_approved'=>false,
                    'runtime_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AN02_RUN_IDENTITY_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('AN02_DISPOSABLE_RUN_STORAGE_FAILED');
        }
    }

    /** @param ?array<string,mixed> $artifact Exactly local mock role/metadata. */
    public function appendStage(
        array $scope,string $runId,string $action,?array $artifact,string $atUtc,
    ):Outcome {
        if(!Vocabulary::scope($scope)||!Ids::isValid($runId)
            || !Vocabulary::utc($atUtc)
            || !in_array($action,[
                'INSPECT_SYNTHETIC','ANALYST','CRITIC','UNKNOWN_OUTCOME',
            ],true)){
            return Outcome::refuse('AN02_STAGE_SELECTOR_OR_ACTION_INVALID');
        }
        try{
            return $this->db->transaction(function(Database $tx)use(
                $scope,$runId,$action,$artifact,$atUtc
            ):Outcome {
                // The immutable run acts as the serialization lock for its
                // exact event sequence, preventing overlapping workers from
                // reserving the same seq or skipping an artifact.
                $runRow=$tx->one(
                    'SELECT run_json FROM history_review_runs
                     WHERE history_review_run_id=? AND tenant_id=? AND workspace_id=?
                       AND competitor_id=? AND question_id=? AND candidate_state=? FOR UPDATE',
                    [$runId,$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],
                     'TEST_ONLY_NOT_ADMITTED'],
                );
                if($runRow===null)return Outcome::refuse('AN02_RUN_NOT_IN_OWNER_SCOPE');
                $run=self::decode($runRow['run_json']);
                if(!HistoryReviewService::verifyRun($run)->isOk()
                    || $run['scope']!==$scope){
                    return Outcome::refuse('AN02_RUN_STORED_IDENTITY_INVALID');
                }
                $loaded=$this->loadAndVerify($tx,$runId,$scope,$run);
                if(!$loaded->isOk())return $loaded;
                $events=$loaded->value()['events'];
                $last=$events===[]?null:end($events);
                $kind=match($action){
                    'INSPECT_SYNTHETIC'=>'SYNTHETIC_REQUEST_INSPECTED_NO_GRANT',
                    'ANALYST'=>'ANALYST_CANDIDATE_RETAINED',
                    'CRITIC'=>'CRITIC_CHALLENGE_RETAINED',
                    'UNKNOWN_OUTCOME'=>'UNKNOWN_PROVIDER_OUTCOME_NO_REDISPATCH',
                };
                $artDigest=self::artifactDigest($action,$artifact);
                if($last!==null && $last['event_kind']===$kind){
                    if($last['at_utc']===$atUtc
                        && $last['artifact_digest']===$artDigest){
                        $stored=$tx->one(
                            'SELECT artifact_json FROM history_review_artifact_refs
                             WHERE history_review_run_id=? AND tenant_id=?
                               AND workspace_id=? AND sequence_no=?',
                            [$runId,$scope['tenant_id'],$scope['workspace_id'],
                             $last['sequence_no']],
                        );
                        if($artifact===null ? $stored!==null
                            : ($stored===null
                                || !hash_equals((string)$stored['artifact_json'],
                                    self::json($artifact)))){
                            return Outcome::refuse('AN02_SAME_STAGE_REPLAY_PAYLOAD_CONFLICT');
                        }
                        return Outcome::ok([
                            'action'=>'EXACT_STAGE_REPLAY_NO_WRITE',
                            'sequence_no'=>$last['sequence_no'],
                            'event_digest'=>$last['event_digest'],
                            'state'=>$last['state_to'],
                            'provider_dispatch_authorized'=>false,
                        ]);
                    }
                    return Outcome::refuse('AN02_SAME_STAGE_RETRY_CONFLICT');
                }
                $next=HistoryReviewService::next(
                    $run,$events,$action,$artifact,$atUtc,
                );
                if(!$next->isOk())return $next;
                $event=$next->value();
                $num=$event['sequence_no'];
                if($num>self::MAX_EVENTS)return Outcome::refuse('AN02_EVENT_COUNT_LIMIT');
                $scoped=['tenant_id'=>$scope['tenant_id'],
                         'workspace_id'=>$scope['workspace_id']];
                // ARTIFACT FIRST: no event cursor can point to a missing
                // candidate, even on an interrupted transaction. A claimed
                // provider receipt is metadata only, never real response bytes.
                if($artifact!==null){
                    $valid=match($action){
                        'ANALYST'=>HistoryReviewCandidateChecks::verifyAnalyst($artifact),
                        'CRITIC'=>HistoryReviewCandidateChecks::verifyCritic($artifact),
                        'UNKNOWN_OUTCOME'=>HistoryReviewReconciliation::verify($artifact),
                        default=>Outcome::refuse('AN02_UNEXPECTED_ARTIFACT'),
                    };
                    if(!$valid->isOk()
                        || $artDigest!==$event['artifact_digest']){
                        return Outcome::refuse('AN02_ARTIFACT_NOT_VALID_FOR_EVENT');
                    }
                    $tx->insert('history_review_artifact_refs',[
                        'history_review_artifact_ref_id'=>Ids::new(),
                        'history_review_run_id'=>$runId,...$scoped,
                        'sequence_no'=>$num,
                        'artifact_role'=>$event['artifact_kind'],
                        'artifact_digest'=>$artDigest,
                        'artifact_json'=>self::json($artifact),
                        'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                        'created_at'=>$this->clock->now(),
                    ]);
                }
                $tx->insert('history_review_events',[
                    'history_review_event_id'=>Ids::new(),
                    'history_review_run_id'=>$runId,...$scoped,
                    'sequence_no'=>$num,
                    'event_kind'=>$event['event_kind'],
                    'state_from'=>$event['state_from'],
                    'state_to'=>$event['state_to'],
                    'event_digest'=>$event['event_digest'],
                    'previous_event_digest'=>$event['prior_event_digest'],
                    'event_json'=>self::json($event),
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_REVIEW_EVENT_RECORDED',
                    'sequence_no'=>$num,
                    'event_digest'=>$event['event_digest'],
                    'state'=>$event['state_to'],
                    'artifact_digest'=>$event['artifact_digest'],
                    'source_use_admitted'=>false,
                    'provider_dispatch_authorized'=>false,
                    'human_review_approved'=>false,
                    'customer_release_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'AN02_REVIEW_EVENT_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('AN02_REVIEW_EVENT_STORAGE_FAILED');
        }
    }

    /**
     * Scoped, metadata-only replay; no candidate prose or original evidence.
     * Current M02/M05 entitlement still needs a separate native check later.
     */
    public function replayCandidate(array $scope,string $runId):Outcome
    {
        if(!Vocabulary::scope($scope)||!Ids::isValid($runId)){
            return Outcome::refuse('AN02_REPLAY_SELECTOR_INVALID');
        }
        try{
            return $this->db->transaction(function(Database $tx)use(
                $scope,$runId
            ):Outcome {
                $row=$tx->one(
                    'SELECT run_json FROM history_review_runs
                     WHERE history_review_run_id=? AND tenant_id=? AND workspace_id=?
                       AND competitor_id=? AND question_id=? AND candidate_state=?',
                    [$runId,$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],
                     'TEST_ONLY_NOT_ADMITTED'],
                );
                if($row===null)return Outcome::refuse('AN02_RUN_NOT_IN_OWNER_SCOPE');
                $run=self::decode($row['run_json']);
                if(!HistoryReviewService::verifyRun($run)->isOk()
                    || $run['scope']!==$scope){
                    return Outcome::refuse('AN02_REPLAY_RUN_DIGEST_INVALID');
                }
                $rows=$this->loadAndVerify($tx,$runId,$scope,$run);
                if(!$rows->isOk())return $rows;
                $events=$rows->value()['events'];
                $state=$events===[]?$run['state']:end($events)['state_to'];
                $summaries=[];
                foreach($events as $event){
                    $summaries[]=[
                        'sequence_no'=>$event['sequence_no'],
                        'event_kind'=>$event['event_kind'],
                        'state_to'=>$event['state_to'],
                        'event_digest'=>$event['event_digest'],
                        'artifact_digest'=>$event['artifact_digest'],
                    ];
                }
                return Outcome::ok([
                    'version'=>self::VERSION,
                    'scope'=>$scope,
                    'review_id'=>$run['review_id'],
                    'run_digest'=>$run['run_digest'],
                    'request_candidate_digest'=>$run['request_candidate_digest'],
                    'basis_digest'=>$run['basis_digest'],
                    'm17_run_digest'=>$run['m17_run_digest'],
                    'state'=>$state,
                    'event_count'=>count($events),
                    'events'=>$summaries,
                    'journal_integrity'=>'SYNTHETIC_CANDIDATE_CHAIN_VERIFIED',
                    'source_evidence_authenticated'=>false,
                    'provider_result_authenticated'=>false,
                    'human_factual_release_approved'=>false,
                    'human_delivery_approved'=>false,
                    'customer_release_authorized'=>false,
                ]);
            });
        }catch(\Throwable){
            return Outcome::failure('AN02_REPLAY_CANDIDATE_UNAVAILABLE');
        }
    }

    private function loadAndVerify(
        Database $tx,string $runId,array $scope,array $run,
    ):Outcome {
        $rows=$tx->all(
            'SELECT sequence_no,event_digest,event_json FROM history_review_events
             WHERE history_review_run_id=? AND tenant_id=? AND workspace_id=?
             ORDER BY sequence_no',
            [$runId,$scope['tenant_id'],$scope['workspace_id']],
        );
        if(count($rows)>self::MAX_EVENTS){
            return Outcome::refuse('AN02_REPLAY_EVENT_LIMIT');
        }
        $raw=$tx->all(
            'SELECT sequence_no,artifact_role,artifact_digest,artifact_json
             FROM history_review_artifact_refs
             WHERE history_review_run_id=? AND tenant_id=? AND workspace_id=?
             ORDER BY sequence_no',
            [$runId,$scope['tenant_id'],$scope['workspace_id']],
        );
        $artifacts=[];
        foreach($raw as $r){
            $seq=(int)$r['sequence_no'];
            if(isset($artifacts[$seq]))return Outcome::refuse('AN02_REPLAY_DUPLICATE_ARTIFACT');
            $a=self::decode($r['artifact_json']);
            $valid=match($r['artifact_role']){
                'ANALYST'=>HistoryReviewCandidateChecks::verifyAnalyst($a),
                'CRITIC'=>HistoryReviewCandidateChecks::verifyCritic($a),
                'RECONCILIATION'=>HistoryReviewReconciliation::verify($a),
                default=>Outcome::refuse('AN02_REPLAY_ARTIFACT_ROLE_UNSUPPORTED'),
            };
            if(!$valid->isOk()
                || self::artifactDigest($r['artifact_role'],$a)!==(string)$r['artifact_digest']){
                return Outcome::refuse('AN02_REPLAY_STORED_ARTIFACT_INVALID');
            }
            $artifacts[$seq]=$r;
        }
        $events=[];$expectedState=$run['state'];$prior=null;$lastTime=null;
        foreach($rows as $i=>$row){
            $event=self::decode($row['event_json']);
            $seq=$i+1;
            if(!HistoryReviewService::verifyEvent($event)->isOk()
                || (int)$row['sequence_no']!==$seq
                || $event['sequence_no']!==$seq
                || $event['run_digest']!==$run['run_digest']
                || $event['request_candidate_digest']!==$run['request_candidate_digest']
                || $event['event_digest']!==$row['event_digest']
                || $event['prior_event_digest']!==$prior
                || $event['state_from']!==$expectedState
                || ($lastTime!==null && $event['at_utc']<$lastTime)){
                return Outcome::refuse('AN02_REPLAY_EVENT_CHAIN_INVALID');
            }
            $hasArtifact=$event['artifact_kind']!==null;
            if($hasArtifact){
                if(!isset($artifacts[$seq])
                    || $artifacts[$seq]['artifact_role']!==$event['artifact_kind']
                    || $artifacts[$seq]['artifact_digest']!==$event['artifact_digest']){
                    return Outcome::refuse('AN02_EVENT_REQUIRES_RETAINED_ARTIFACT');
                }
            }elseif(isset($artifacts[$seq])){
                return Outcome::refuse('AN02_UNREFERENCED_ARTIFACT_NOT_PERMITTED');
            }
            $events[]=$event;
            $prior=$event['event_digest'];
            $expectedState=$event['state_to'];$lastTime=$event['at_utc'];
        }
        if(count($artifacts)!==count(array_filter($events,
            static fn(array $e):bool=>$e['artifact_kind']!==null))){
            return Outcome::refuse('AN02_UNREFERENCED_ARTIFACT_NOT_PERMITTED');
        }
        return Outcome::ok(['events'=>$events,'state'=>$expectedState]);
    }

    private static function artifactDigest(string $action,?array $artifact):?string
    {
        if($artifact===null)return null;
        return match($action){
            'ANALYST','CRITIC'=>(is_string($artifact['candidate_digest']??null)
                ? $artifact['candidate_digest'] : null),
            'UNKNOWN_OUTCOME','RECONCILIATION'=>
                (is_string($artifact['reconciliation_digest']??null)
                    ? $artifact['reconciliation_digest'] : null),
            default=>null,
        };
    }

    /** @return array<string,mixed> */
    private static function decode(string $json):array
    {
        $v=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($v))throw new \UnexpectedValueException('Invalid JSON candidate');
        return $v;
    }
    private static function json(array $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
}
