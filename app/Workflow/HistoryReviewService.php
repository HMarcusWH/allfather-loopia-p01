<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\PromptOps\HistoryReviewCandidateChecks;
use Allfather\Loopia\Substrate\M17\P01WindowProfileV2;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-02: deterministic historical-review STATE MACHINE, not MonitorService.
 * Terminal Monitor runs are never opened/revised by any method here.
 * All steps are proposals for a local synthetic journal, not paid execution.
 *
 * A mock critic is a separate challenge role, NEVER independent scientific
 * corroboration or a human's release/delivery review.
 */
final class HistoryReviewService
{
    public const VERSION='an02.inert-history-review-run.v1';
    public const EVENT_VERSION='an02.inert-review-event.v1';
    private const HEX='/^[0-9a-f]{64}$/D';
    private const RUN_KEYS=[
        'version','state','scope','review_id','requested_at_utc','request_digest',
        'request_candidate_digest','basis_digest','m17_run_digest',
        'knowledge_cutoff_utc','selected_count','contrary_count',
        'purpose','transport_mode','model_attempts_authorized',
        'source_use_admitted','human_factual_release_approved',
        'human_delivery_approved','customer_release_authorized',
        'run_digest',
    ];
    private const EVENT_KEYS=[
        'version','run_digest','sequence_no','prior_event_digest',
        'event_kind','state_from','state_to','at_utc',
        'artifact_kind','artifact_digest','request_candidate_digest',
        'historical_source_rights_verified','external_provider_dispatched',
        'model_critic_is_human','human_factual_release_approved',
        'human_delivery_approved','customer_release_authorized','event_digest',
    ];
    private const TERMINAL=[
        'HUMAN_REVIEW_REQUIRED','NO_NEW_INSIGHT',
        'REVISE_HOLD','BLOCK_HOLD','INSUFFICIENT_EVIDENCE_HOLD',
        'UNKNOWN_OUTCOME_HOLD',
    ];

    /** Freeze an exact analytical BASIS/REQUEST identity before any artifact. */
    public static function freezeRun(
        array $request,array $basis,?array $memory,
    ):Outcome {
        $insufficient=($basis['selection_integrity']??null)!=='COMPLETE_WITHIN_INPUT'
            || ($basis['selected_count']??0)===0;
        if(!HistoryReviewAdmission::verify($request)->isOk()
            || !PointInTimeHistoricalBasis::verify($basis)->isOk()
            || ($insufficient && $memory!==null)
            || (!$insufficient && (
                !is_array($memory)
                || !P01WindowProfileV2::verify($memory)->isOk()
                || $memory['run_identity']['tenant_id']!==$request['scope']['tenant_id']
                || $memory['run_identity']['workspace_id']!==$request['scope']['workspace_id']
                || $memory['run_identity']['subject_id']!==$request['scope']['competitor_id']
                || $memory['run_identity']['question_id']!==$request['scope']['question_id']
                || $memory['selected_reference_count']!==$basis['selected_count']
            ))
            || $request['scope']!==$basis['scope']
            || $request['basis_digest']!==$basis['basis_digest']
            || $request['requested_at_utc']<$basis['review_at_utc']
            || $basis['historical_reuse_admitted']!==false
            || $request['purpose']!==HistoryReviewAdmission::PURPOSE
            || $request['mode']!==HistoryReviewAdmission::MODE) {
            return Outcome::refuse('AN02_RUN_REQUEST_BASIS_MEMORY_CONFLICT');
        }
        if(!$insufficient){
            $derived=HistoryM17V2CandidateBridge::compose(
                $basis,$memory['run_identity']['data_class'],
                $memory['run_identity']['window_kind'],
            );
            if(!$derived->isOk()
                || $derived->value()['candidate_v2']['candidate_digest']
                    !==$memory['candidate_digest']){
                return Outcome::refuse('AN02_M17_RUN_NOT_DERIVED_FROM_THIS_BASIS');
            }
        }
        $v=[
            'version'=>self::VERSION,
            'state'=>$insufficient?'INSUFFICIENT_EVIDENCE_HOLD':'REQUEST_INSPECTION_REQUIRED',
            'scope'=>$request['scope'],
            'review_id'=>$request['review_id'],
            'requested_at_utc'=>$request['requested_at_utc'],
            'request_digest'=>$request['request_digest'],
            'request_candidate_digest'=>$request['candidate_digest'],
            'basis_digest'=>$basis['basis_digest'],
            'm17_run_digest'=>$insufficient?null:$memory['derivation_identity_digest'],
            'knowledge_cutoff_utc'=>$basis['knowledge_cutoff_utc'],
            'selected_count'=>$basis['selected_count'],
            'contrary_count'=>$basis['selected_contrary_count'],
            'purpose'=>HistoryReviewAdmission::PURPOSE,
            'transport_mode'=>HistoryReviewAdmission::MODE,
            'model_attempts_authorized'=>false,
            'source_use_admitted'=>false,
            'human_factual_release_approved'=>false,
            'human_delivery_approved'=>false,
            'customer_release_authorized'=>false,
        ];
        $v['run_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    /** Immutable event: no original monitor ID or runtime grant appears here. */
    public static function next(
        array $run,array $priorEvents,string $action,
        ?array $artifact,string $atUtc,
    ):Outcome {
        if(!self::verifyRun($run)->isOk()
            || !Vocabulary::utc($atUtc)
            || !array_is_list($priorEvents)
            || count($priorEvents)>16){
            return Outcome::refuse('AN02_REVIEW_EVENT_CONTRACT_INVALID');
        }
        $current=$run['state'];$lastDigest=null;
        foreach($priorEvents as $n=>$event){
            if(!is_array($event)||!self::verifyEvent($event)->isOk()
                || $event['run_digest']!==$run['run_digest']
                || $event['sequence_no']!==$n+1
                || $event['prior_event_digest']!==$lastDigest
                || $event['state_from']!==$current){
                return Outcome::refuse('AN02_REVIEW_EVENT_CHAIN_INVALID');
            }
            $lastDigest=$event['event_digest'];$current=$event['state_to'];
            if($n>0 && $event['at_utc']<$priorEvents[$n-1]['at_utc']){
                return Outcome::refuse('AN02_REVIEW_EVENT_CLOCK_REVERSED');
            }
        }
        if($atUtc<$run['requested_at_utc']){
            return Outcome::refuse('AN02_REVIEW_EVENT_CLOCK_BEFORE_REQUEST');
        }
        if($priorEvents!==[] && $atUtc<end($priorEvents)['at_utc']){
            return Outcome::refuse('AN02_REVIEW_EVENT_CLOCK_REVERSED');
        }
        if(in_array($current,self::TERMINAL,true)){
            return Outcome::refuse('AN02_TERMINAL_REVIEW_HOLD_NOT_ADVANCEABLE');
        }
        $target=null;$kind=null;$artKind=null;$artDigest=null;
        if($action==='INSPECT_SYNTHETIC' && $current==='REQUEST_INSPECTION_REQUIRED'
            && $artifact===null){
            $target='ANALYST_READY';
            $kind='SYNTHETIC_REQUEST_INSPECTED_NO_GRANT';
        }elseif($action==='ANALYST' && $current==='ANALYST_READY'
            && is_array($artifact)
            && HistoryReviewCandidateChecks::verifyAnalyst($artifact)->isOk()
            && $artifact['request_candidate_digest']===$run['request_candidate_digest']
            && $artifact['basis_digest']===$run['basis_digest']
            && $artifact['m17_run_digest']===$run['m17_run_digest']
            && $artifact['scope']===$run['scope']
            && $artifact['review_id']===$run['review_id']
            && $artifact['basis_selected_reference_count']===$run['selected_count']){
            $target='CRITIC_REQUIRED';
            $kind='ANALYST_CANDIDATE_RETAINED';
            $artKind='ANALYST';$artDigest=$artifact['candidate_digest'];
        }elseif($action==='CRITIC' && $current==='CRITIC_REQUIRED'
            && is_array($artifact)
            && HistoryReviewCandidateChecks::verifyCritic($artifact)->isOk()
            && $artifact['request_candidate_digest']===$run['request_candidate_digest']
            && $artifact['basis_digest']===$run['basis_digest']
            && $artifact['scope']===$run['scope']
            && $artifact['review_id']===$run['review_id']
            && $priorEvents!==[]
            && end($priorEvents)['artifact_kind']==='ANALYST'
            && $artifact['analyst_candidate_digest']===end($priorEvents)['artifact_digest']){
            $map=[
                'PASS_TO_HUMAN'=>'HUMAN_REVIEW_REQUIRED',
                'REVISE'=>'REVISE_HOLD',
                'BLOCK'=>'BLOCK_HOLD',
                'NO_NEW_INSIGHT'=>'NO_NEW_INSIGHT',
                'INSUFFICIENT_EVIDENCE'=>'INSUFFICIENT_EVIDENCE_HOLD',
            ];
            $target=$map[$artifact['output']['disposition']]??null;
            $kind='CRITIC_CHALLENGE_RETAINED';
            $artKind='CRITIC';$artDigest=$artifact['candidate_digest'];
        }elseif($action==='UNKNOWN_OUTCOME'
            && in_array($current,['REQUEST_INSPECTION_REQUIRED',
                'ANALYST_READY','CRITIC_REQUIRED'],true)
            && is_array($artifact)
            && HistoryReviewReconciliation::verify($artifact)->isOk()
            && $artifact['request_candidate_digest']===$run['request_candidate_digest']
            && $artifact['request_digest']===$run['request_digest']
            && $artifact['status']==='UNKNOWN_OUTCOME_NO_REDISPATCH_HOLD'){
            $target='UNKNOWN_OUTCOME_HOLD';
            $kind='UNKNOWN_PROVIDER_OUTCOME_NO_REDISPATCH';
            $artKind='RECONCILIATION';
            $artDigest=$artifact['reconciliation_digest'];
        }
        if($target===null||$kind===null){
            return Outcome::refuse('AN02_STAGE_OUT_OF_ORDER_OR_ARTIFACT_NOT_REPLAYABLE');
        }
        $v=[
            'version'=>self::EVENT_VERSION,
            'run_digest'=>$run['run_digest'],
            'sequence_no'=>count($priorEvents)+1,
            'prior_event_digest'=>$lastDigest,
            'event_kind'=>$kind,
            'state_from'=>$current,'state_to'=>$target,
            'at_utc'=>$atUtc,
            'artifact_kind'=>$artKind,'artifact_digest'=>$artDigest,
            'request_candidate_digest'=>$run['request_candidate_digest'],
            'historical_source_rights_verified'=>false,
            'external_provider_dispatched'=>false,
            'model_critic_is_human'=>false,
            'human_factual_release_approved'=>false,
            'human_delivery_approved'=>false,
            'customer_release_authorized'=>false,
        ];
        $v['event_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verifyRun(array $run):Outcome
    {
        if(!Vocabulary::exactKeys($run,self::RUN_KEYS)
            || $run['version']!==self::VERSION
            || !Vocabulary::scope($run['scope'])
            || !Vocabulary::id($run['review_id'])
            || !Vocabulary::utc($run['knowledge_cutoff_utc'])
            || !Vocabulary::utc($run['requested_at_utc'])
            || $run['requested_at_utc']<$run['knowledge_cutoff_utc']
            || !in_array($run['state'],[
                'REQUEST_INSPECTION_REQUIRED','INSUFFICIENT_EVIDENCE_HOLD',
            ],true)
            || $run['purpose']!==HistoryReviewAdmission::PURPOSE
            || $run['transport_mode']!==HistoryReviewAdmission::MODE
            || !self::flags($run,[
                'model_attempts_authorized','source_use_admitted',
                'human_factual_release_approved','human_delivery_approved',
                'customer_release_authorized',
            ])
            || !self::validDigest($run,'run_digest')
            || !self::validDigest($run,'basis_digest')
            || ($run['state']==='INSUFFICIENT_EVIDENCE_HOLD'
                ? $run['m17_run_digest']!==null
                : !self::validDigest($run,'m17_run_digest'))
            || !self::validDigest($run,'request_digest')
            || !self::validDigest($run,'request_candidate_digest')
            || !is_int($run['selected_count'])
            || $run['selected_count']<0 || $run['selected_count']>32
            || !is_int($run['contrary_count'])
            || $run['contrary_count']<0
            || $run['contrary_count']>$run['selected_count']){
            return Outcome::refuse('AN02_REVIEW_RUN_IDENTITY_INVALID');
        }
        return self::checksum($run,'run_digest')?Outcome::ok(['integrity_only'=>true]):
            Outcome::refuse('AN02_REVIEW_RUN_DIGEST_INVALID');
    }

    public static function verifyEvent(array $event):Outcome
    {
        if(!Vocabulary::exactKeys($event,self::EVENT_KEYS)
            || $event['version']!==self::EVENT_VERSION
            || !self::validDigest($event,'run_digest')
            || !self::validDigest($event,'request_candidate_digest')
            || !self::validDigest($event,'event_digest')
            || ($event['prior_event_digest']!==null
                && !self::validDigest($event,'prior_event_digest'))
            || ($event['artifact_digest']!==null
                && !self::validDigest($event,'artifact_digest'))
            || !is_int($event['sequence_no'])
            || $event['sequence_no']<1 || $event['sequence_no']>16
            || !Vocabulary::utc($event['at_utc'])
            || !in_array($event['event_kind'],[
                'SYNTHETIC_REQUEST_INSPECTED_NO_GRANT',
                'ANALYST_CANDIDATE_RETAINED',
                'CRITIC_CHALLENGE_RETAINED',
                'UNKNOWN_PROVIDER_OUTCOME_NO_REDISPATCH',
            ],true)
            || !in_array($event['state_from'],[
                'REQUEST_INSPECTION_REQUIRED','ANALYST_READY','CRITIC_REQUIRED',
            ],true)
            || !in_array($event['state_to'],[
                'ANALYST_READY','CRITIC_REQUIRED',
                'HUMAN_REVIEW_REQUIRED','REVISE_HOLD','BLOCK_HOLD',
                'NO_NEW_INSIGHT','INSUFFICIENT_EVIDENCE_HOLD',
                'UNKNOWN_OUTCOME_HOLD',
            ],true)
            || !self::flags($event,[
                'historical_source_rights_verified','external_provider_dispatched',
                'model_critic_is_human','human_factual_release_approved',
                'human_delivery_approved','customer_release_authorized',
            ])){
            return Outcome::refuse('AN02_REVIEW_EVENT_IDENTITY_INVALID');
        }
        $route=$event['event_kind'];
        $validRoute=match($route){
            'SYNTHETIC_REQUEST_INSPECTED_NO_GRANT'=>
                $event['state_from']==='REQUEST_INSPECTION_REQUIRED'
                && $event['state_to']==='ANALYST_READY'
                && $event['artifact_kind']===null && $event['artifact_digest']===null,
            'ANALYST_CANDIDATE_RETAINED'=>
                $event['state_from']==='ANALYST_READY'
                && $event['state_to']==='CRITIC_REQUIRED'
                && $event['artifact_kind']==='ANALYST'
                && self::validDigest($event,'artifact_digest'),
            'CRITIC_CHALLENGE_RETAINED'=>
                $event['state_from']==='CRITIC_REQUIRED'
                && in_array($event['state_to'],[
                    'HUMAN_REVIEW_REQUIRED','REVISE_HOLD','BLOCK_HOLD',
                    'NO_NEW_INSIGHT','INSUFFICIENT_EVIDENCE_HOLD',
                ],true)
                && $event['artifact_kind']==='CRITIC'
                && self::validDigest($event,'artifact_digest'),
            'UNKNOWN_PROVIDER_OUTCOME_NO_REDISPATCH'=>
                in_array($event['state_from'],[
                    'REQUEST_INSPECTION_REQUIRED','ANALYST_READY','CRITIC_REQUIRED',
                ],true)
                && $event['state_to']==='UNKNOWN_OUTCOME_HOLD'
                && $event['artifact_kind']==='RECONCILIATION'
                && self::validDigest($event,'artifact_digest'),
            default=>false,
        };
        if(!$validRoute)return Outcome::refuse('AN02_REVIEW_EVENT_ROUTE_INVALID');
        return self::checksum($event,'event_digest')?Outcome::ok(['integrity_only'=>true]):
            Outcome::refuse('AN02_REVIEW_EVENT_DIGEST_INVALID');
    }

    private static function flags(array $v,array $names):bool
    {
        foreach($names as $name)if(($v[$name]??null)!==false)return false;
        return true;
    }
    private static function validDigest(array $v,string $name):bool
    {
        return is_string($v[$name]??null)
            && preg_match(self::HEX,$v[$name])===1;
    }
    private static function checksum(array $v,string $name):bool
    {
        try{
            $d=$v[$name];unset($v[$name]);
            return hash_equals($d,Digest::canonicalDigest($v));
        }catch(\Throwable){return false;}
    }
}
