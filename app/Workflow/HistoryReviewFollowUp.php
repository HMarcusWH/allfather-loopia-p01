<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-04 inert next-observation proposal, not a new crawler, schedule,
 * provider call, human adjudication or permitted release.
 */
final class HistoryReviewFollowUp
{
    public const VERSION='an04.inert-follow-up-condition.v1';
    public const MAX_TARGETS=16;
    private const HEX='/^[a-f0-9]{64}$/D';
    private const KEYS=[
        'annotation','followup_kind','target_source_ref_ids',
        'requested_at_utc','not_before_utc','deadline_utc',
        'operator_note_code',
    ];
    public const KINDS=[
        'REQUEST_SOURCE_OWNER_REVIEW','WAIT_NEXT_ROUTINE_OBSERVATION',
        'PROPOSE_NEW_BOUNDED_REVIEW','PROPOSE_CONTRARY_RECHECK',
        'HOLD_INSUFFICIENT_COVERAGE',
    ];

    public static function freeze(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,self::KEYS)
            || !is_array($input['annotation'])
            || !HistoryReviewOutcomes::verify($input['annotation'])->isOk()
            || !in_array($input['followup_kind'],self::KINDS,true)
            || !Vocabulary::utc($input['requested_at_utc'])
            || !Vocabulary::utc($input['not_before_utc'])
            || !Vocabulary::utc($input['deadline_utc'])
            || $input['requested_at_utc']<$input['annotation']['inspected_at_utc']
            || $input['not_before_utc']<$input['requested_at_utc']
            || $input['deadline_utc']<$input['not_before_utc']
            || !in_array($input['operator_note_code'],[
                'REQUIRES_HUMAN_EVIDENCE_ASSESSMENT',
                'NO_SCHEDULE_OR_NEW_SOURCE_PERMISSION',
                'REQUEST_OWNER_SOURCE_USE_AUTHORIZATION',
                'WAIT_WITH_NO_NEW_ACTION',
            ],true)
            || !is_array($input['target_source_ref_ids'])
            || !array_is_list($input['target_source_ref_ids'])
            || count($input['target_source_ref_ids'])>self::MAX_TARGETS){
            return Outcome::refuse('AN04_FOLLOWUP_CONTRACT_INVALID');
        }
        $seen=[];
        foreach($input['target_source_ref_ids'] as $id){
            if(!Vocabulary::id($id)||isset($seen[$id])){
                return Outcome::refuse('AN04_FOLLOWUP_TARGETS_NOT_BOUNDED');
            }
            $seen[$id]=true;
        }
        $annotation=$input['annotation'];
        $result=[
            'version'=>self::VERSION,
            'status'=>'UNSCHEDULED_FOLLOWUP_CANDIDATE_ONLY',
            'scope'=>$annotation['scope'],
            'review_id'=>$annotation['review_id'],
            'finding_digest'=>$annotation['finding_digest'],
            'outcome_annotation_digest'=>$annotation['annotation_digest'],
            'reported_disposition'=>$annotation['reported_disposition'],
            'followup_kind'=>$input['followup_kind'],
            'target_source_ref_ids'=>$input['target_source_ref_ids'],
            'requested_at_utc'=>$input['requested_at_utc'],
            'not_before_utc'=>$input['not_before_utc'],
            'deadline_utc'=>$input['deadline_utc'],
            'operator_note_code'=>$input['operator_note_code'],
            'original_finding_unchanged'=>true,
            'source_scope_authorized'=>false,
            'new_capture_authorized'=>false,
            'provider_model_authorized'=>false,
            'scheduler_handler_created'=>false,
            'job_reserved'=>false,
            'human_outcome_adjudicated'=>false,
            'automatic_policy_or_prompt_update'=>false,
            'm10_release_or_reliance_updated'=>false,
            'p02_twin_updated'=>false,
            'p04_recipient_notified'=>false,
            'customer_release_authorized'=>false,
        ];
        $result['followup_digest']=Digest::canonicalDigest($result);
        return Outcome::ok($result);
    }

    public static function verify(array $candidate):Outcome
    {
        if(($candidate['version']??null)!==self::VERSION
            || ($candidate['status']??null)!=='UNSCHEDULED_FOLLOWUP_CANDIDATE_ONLY'
            || !Vocabulary::scope($candidate['scope']??null)
            || !Vocabulary::id($candidate['review_id']??null)
            || !in_array($candidate['followup_kind']??null,self::KINDS,true)
            || !Vocabulary::utc($candidate['requested_at_utc']??null)
            || !Vocabulary::utc($candidate['not_before_utc']??null)
            || !Vocabulary::utc($candidate['deadline_utc']??null)
            || $candidate['not_before_utc']<$candidate['requested_at_utc']
            || $candidate['deadline_utc']<$candidate['not_before_utc']
            || !is_array($candidate['target_source_ref_ids']??null)
            || !array_is_list($candidate['target_source_ref_ids'])
            || count($candidate['target_source_ref_ids'])>self::MAX_TARGETS
            || ($candidate['original_finding_unchanged']??null)!==true
            || !is_string($candidate['followup_digest']??null)
            || preg_match(self::HEX,$candidate['followup_digest'])!==1){
            return Outcome::refuse('AN04_FOLLOWUP_SHAPE_INVALID');
        }
        foreach([
            'source_scope_authorized','new_capture_authorized',
            'provider_model_authorized','scheduler_handler_created',
            'job_reserved','human_outcome_adjudicated',
            'automatic_policy_or_prompt_update','m10_release_or_reliance_updated',
            'p02_twin_updated','p04_recipient_notified','customer_release_authorized',
        ] as $flag){
            if(($candidate[$flag]??null)!==false){
                return Outcome::refuse('AN04_FOLLOWUP_FORGED_AUTHORITY');
            }
        }
        try{
            $copy=$candidate;$hash=$copy['followup_digest'];
            unset($copy['followup_digest']);
            if(!hash_equals($hash,Digest::canonicalDigest($copy))){
                return Outcome::refuse('AN04_FOLLOWUP_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('AN04_FOLLOWUP_HASH_UNAVAILABLE');
        }
        return Outcome::ok(['integrity'=>'UNADMITTED_CANDIDATE_ONLY',
            'scheduler_or_external_action_authorized'=>false]);
    }

    public static function dispatch(array $candidate):Outcome
    {
        return Outcome::refuse('AN04_U03_U07_U10_SOURCE_OR_MODEL_ACTION_NOT_ADOPTED');
    }
}
