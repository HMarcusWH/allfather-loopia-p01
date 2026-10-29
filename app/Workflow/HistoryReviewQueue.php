<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AQ-01 / U05+U08: pure, bounded human-review work CANDIDATES.
 *
 * This is neither a second job queue nor an extension to the live observer
 * scheduler. It does not generate a JobMachine job ID, lease, runtime
 * occurrence or permission, and cannot cause model usage.
 */
final class HistoryReviewQueue
{
    public const VERSION='aq01.inert-history-review-work.v1';
    public const MAX_MEMBERS=64;
    public const MAX_BYTES=32768;
    public const MAX_PENDING=100;
    private const INPUT_KEYS=['policy','due','graph','members','at_utc','queue_state'];
    private const MEMBER_KEYS=[
        'member_id','member_kind','reference_digest',
        'known_at_utc','recorded_at_utc',
    ];
    private const QUEUE_KEYS=[
        'pending_reviews','pending_member_ids','oldest_pending_at_utc',
        'last_human_reviewed_at_utc',
    ];
    private const KINDS=[
        'INVALIDATION','CONTRARY_EVIDENCE','OBSERVATION','QUIET_REVIEW_CLOCK',
    ];
    private const PRIORITY=[
        'INVALIDATION'=>0,'CONTRARY_EVIDENCE'=>1,
        'OBSERVATION'=>2,'QUIET_REVIEW_CLOCK'=>3,
    ];
    private const HEX='/^[a-f0-9]{64}$/D';

    /** @param array<string,mixed> $input */
    public static function freeze(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,self::INPUT_KEYS)
            || !is_array($input['policy'])
            || !HistoryReviewPolicy::verify($input['policy'])->isOk()
            || !is_array($input['due'])
            || !HistoryReviewDue::verify($input['due'])->isOk()
            || !Vocabulary::utc($input['at_utc'])
            || !is_array($input['members'])
            || !array_is_list($input['members'])
            || count($input['members'])<1
            || count($input['members'])>self::MAX_MEMBERS
            || !is_array($input['queue_state'])
            || !Vocabulary::exactKeys($input['queue_state'],self::QUEUE_KEYS)) {
            return Outcome::refuse('AQ01_QUEUE_CONTRACT_INVALID');
        }
        $policy=$input['policy'];$due=$input['due'];$state=$input['queue_state'];
        $scope=$policy['scope'];
        if($due['scope']!==$scope
            || $due['policy_digest']!==$policy['policy_digest']
            || $due['at_utc']!==$input['at_utc']
            || $due['basis_knowledge_cutoff_utc']>$input['at_utc']) {
            return Outcome::refuse('AQ01_DUE_SCOPE_POLICY_OR_CLOCK_MISMATCH');
        }
        if($input['graph']!==null){
            if(!is_array($input['graph'])
                || !HistoryDependencyIndex::verify($input['graph'])->isOk()
                || $input['graph']['scope']!==$scope
                || $input['graph']['catalog_cutoff_utc']>$input['at_utc']) {
                return Outcome::refuse('AQ01_GRAPH_SCOPE_OR_CUTOFF_HOLD');
            }
        }
        if(!is_int($state['pending_reviews'])
            || $state['pending_reviews']<0
            || $state['pending_reviews']>self::MAX_PENDING
            || !is_array($state['pending_member_ids'])
            || !array_is_list($state['pending_member_ids'])
            || count($state['pending_member_ids'])>self::MAX_MEMBERS) {
            return Outcome::refuse('AQ01_PENDING_STATE_INVALID');
        }
        $pending=[];
        foreach($state['pending_member_ids'] as $id){
            if(!Vocabulary::id($id)||isset($pending[$id])){
                return Outcome::refuse('AQ01_PENDING_ID_DUPLICATED_OR_INVALID');
            }
            $pending[$id]=true;
        }
        if(($state['pending_reviews']===0)!==($state['pending_member_ids']===[])
            || ($state['pending_reviews']===0)!==($state['oldest_pending_at_utc']===null)) {
            return Outcome::refuse('AQ01_PENDING_WATERMARK_INCONSISTENT');
        }
        foreach(['oldest_pending_at_utc','last_human_reviewed_at_utc'] as $key){
            if($state[$key]!==null
                && (!Vocabulary::utc($state[$key])
                    || $state[$key]>$input['at_utc'])) {
                return Outcome::refuse('AQ01_PENDING_WATERMARK_INVALID');
            }
        }
        $members=[];$seen=[];$critical=[];$outOfCutoff=[];$new=[];$replayed=[];
        foreach($input['members'] as $member){
            if(!is_array($member)
                || !Vocabulary::exactKeys($member,self::MEMBER_KEYS)
                || !Vocabulary::id($member['member_id'])
                || isset($seen[$member['member_id']])
                || !in_array($member['member_kind'],self::KINDS,true)
                || !Vocabulary::utc($member['known_at_utc'])
                || !Vocabulary::utc($member['recorded_at_utc'])
                || $member['recorded_at_utc']>$input['at_utc']
                || ($member['member_kind']==='QUIET_REVIEW_CLOCK'
                    ? $member['reference_digest']!==null
                    : (!is_string($member['reference_digest'])
                        || preg_match(self::HEX,$member['reference_digest'])!==1))) {
                return Outcome::refuse('AQ01_MEMBER_ID_OR_CLOCK_INVALID');
            }
            $seen[$member['member_id']]=true;
            $row=$member;
            $row['priority']=self::PRIORITY[$member['member_kind']];
            $row['critical']=$row['priority']<=1;
            // A quiet-clock tick is not new evidence. It must not be
            // silently added to the frozen source basis or treated as
            // late-known source material.
            $row['known_within_frozen_basis']=$member['member_kind']==='QUIET_REVIEW_CLOCK'
                || $member['known_at_utc']<=$due['basis_knowledge_cutoff_utc'];
            $row['already_pending']=isset($pending[$member['member_id']]);
            if($row['critical'])$critical[]=$member['member_id'];
            if(!$row['known_within_frozen_basis'])$outOfCutoff[]=$member['member_id'];
            if($row['already_pending'])$replayed[]=$member['member_id'];
            else $new[]=$member['member_id'];
            $members[]=$row;
        }
        // Coalescing never removes members or converts duplicates to
        // independent real-world events. Critical/invalidation references
        // retain highest priority, regardless of existing queue age.
        usort($members,static fn(array $a,array $b):int=>
            [$a['priority'],$a['known_at_utc'],$a['member_id']]
            <=>
            [$b['priority'],$b['known_at_utc'],$b['member_id']]);
        sort($critical,SORT_STRING);sort($outOfCutoff,SORT_STRING);
        sort($replayed,SORT_STRING);sort($new,SORT_STRING);
        if($critical!==[]){
            $disposition='HOLD_CRITICAL_SOURCE_OR_INVALIDATION_REVIEW';
        }elseif($outOfCutoff!==[]){
            $disposition='HOLD_FROZEN_CUTOFF_EXCEEDED';
        }elseif($due['status']!=='DUE_CANDIDATE_HUMAN_REVIEW_ONLY'){
            $disposition='HOLD_DUE_NOT_ADMITTED_OR_DEFERRED';
        }elseif($state['pending_reviews']>0){
            $disposition='COALESCE_PENDING_HUMAN_CANDIDATE_ONLY';
        }else{
            $disposition='HUMAN_REVIEW_CANDIDATE_ONLY';
        }
        $result=[
            'version'=>self::VERSION,
            'scope'=>$scope,
            'status'=>$disposition,
            'at_utc'=>$input['at_utc'],
            'basis_digest'=>$due['basis_digest'],
            'basis_knowledge_cutoff_utc'=>$due['basis_knowledge_cutoff_utc'],
            'due_candidate_digest'=>$due['candidate_digest'],
            'policy_digest'=>$policy['policy_digest'],
            'dependency_graph_digest'=>$input['graph']['graph_digest']??null,
            'members'=>$members,
            'member_count'=>count($members),
            'member_ids_reused_from_pending'=>$replayed,
            'member_ids_new'=>$new,
            'critical_member_ids'=>$critical,
            'outside_frozen_cutoff_member_ids'=>$outOfCutoff,
            'pending_reviews'=>$state['pending_reviews'],
            'oldest_pending_at_utc'=>$state['oldest_pending_at_utc'],
            'last_human_reviewed_at_utc'=>$state['last_human_reviewed_at_utc'],
            'observed_available_queued_analysed_reviewed_clocks_separate'=>true,
            'independent_event_count'=>null,
            'independent_origin_count'=>null,
            'coalescing_discarded_members'=>false,
            'review_work_reserved'=>false,
            'scheduler_handler_registered'=>false,
            'source_use_admitted'=>false,
            'U05_policy_ratified'=>false,
            'U08_service_execution_ratified'=>false,
            'model_call_authorized'=>false,
            'notification_authorized'=>false,
            'customer_release_authorized'=>false,
        ];
        $result['candidate_digest']=Digest::canonicalDigest($result);
        if(strlen(Digest::canonicalize($result))>self::MAX_BYTES){
            return Outcome::refuse('AQ01_QUEUE_BYTE_LIMIT');
        }
        return Outcome::ok($result);
    }

    /** Digest and posture check, not native authorization or owner fact. */
    public static function verify(array $value):Outcome
    {
        if(($value['version']??null)!==self::VERSION
            || !is_string($value['candidate_digest']??null)
            || preg_match(self::HEX,$value['candidate_digest'])!==1
            || !Vocabulary::scope($value['scope']??null)
            || !is_array($value['members']??null)
            || !array_is_list($value['members'])
            || ($value['member_count']??null)!==count($value['members'])
            || count($value['members'])>self::MAX_MEMBERS
            || !array_key_exists('independent_event_count',$value)
            || $value['independent_event_count']!==null
            || !array_key_exists('independent_origin_count',$value)
            || $value['independent_origin_count']!==null
            || ($value['coalescing_discarded_members']??null)!==false
            || ($value['observed_available_queued_analysed_reviewed_clocks_separate']??null)!==true) {
            return Outcome::refuse('AQ01_QUEUE_SHAPE_INVALID');
        }
        foreach([
            'review_work_reserved','scheduler_handler_registered','source_use_admitted',
            'U05_policy_ratified','U08_service_execution_ratified',
            'model_call_authorized','notification_authorized','customer_release_authorized',
        ] as $flag){
            if(($value[$flag]??null)!==false){
                return Outcome::refuse('AQ01_QUEUE_FORGED_GRANT');
            }
        }
        foreach($value['members'] as $row){
            if(!is_array($row)
                || ($row['critical']??null)!==in_array($row['member_kind']??null,[
                    'INVALIDATION','CONTRARY_EVIDENCE',
                ],true)
                || !Vocabulary::id($row['member_id']??null)
                || !is_int($row['priority']??null)
                || ($row['priority']??null)!==(self::PRIORITY[$row['member_kind']??'']??null)
                || !is_bool($row['known_within_frozen_basis']??null)
                || !is_bool($row['already_pending']??null)) {
                return Outcome::refuse('AQ01_QUEUE_MEMBER_INVALID');
            }
        }
        try {
            if(strlen(Digest::canonicalize($value))>self::MAX_BYTES){
                return Outcome::refuse('AQ01_QUEUE_BYTE_LIMIT');
            }
            $hash=$value['candidate_digest'];unset($value['candidate_digest']);
            if(!hash_equals($hash,Digest::canonicalDigest($value))){
                return Outcome::refuse('AQ01_QUEUE_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('AQ01_QUEUE_REPLAY_INVALID');
        }
        return Outcome::ok(['integrity'=>'CANDIDATE_ONLY','runtime_work_reserved'=>false]);
    }
}
