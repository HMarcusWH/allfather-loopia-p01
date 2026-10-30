<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\{Assurance as M17Assurance,Vocabulary};

/**
 * VA-02: bounded local M11-oriented scorecard and incident receipt CANDIDATE.
 * Does not write M11 audit/incident tables or correct M10/policies on its own.
 */
final class HistoryReviewAssurance
{
    public const VERSION='va02.history-assurance-scorecard.v1';
    private const INPUTS=[
        'observation','queue','coverage','cost','recovery','at_utc',
    ];
    private const COST=['UNKNOWN_PROVIDER_OUTCOME','FIXTURE_ACCOUNTED_ONLY'];
    private const RECOVERY=['NOT_VERIFIED_HOST','FIXTURE_ONLY_NOT_HOST'];
    private const HEX='/^[a-f0-9]{64}$/D';

    public static function compose(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,self::INPUTS)
            || !is_array($input['observation'])
            || !M17Assurance::verify($input['observation'])->isOk()
            || !Vocabulary::utc($input['at_utc']??null)
            || $input['at_utc']<$input['observation']['observed_at_utc']
            || !is_array($input['cost'])
            || !Vocabulary::exactKeys($input['cost'],[
                'status','reserved_units','spent_units','unknown_attempts',
            ])
            || !in_array($input['cost']['status']??null,self::COST,true)
            || !is_array($input['coverage'])
            || !Vocabulary::exactKeys($input['coverage'],[
                'status','planned_slots','reported_slots','oldest_missing_at_utc',
            ])
            || !in_array($input['coverage']['status']??null,[
                'NOT_VERIFIED','FIXTURE_REPORTED_ONLY',
            ],true)
            || !is_array($input['recovery'])
            || !Vocabulary::exactKeys($input['recovery'],[
                'status','private_bytes','credential_rotation','source_image',
            ])
            || !in_array($input['recovery']['status']??null,self::RECOVERY,true)
            || !in_array($input['recovery']['private_bytes']??null,[
                'NOT_VERIFIED','FIXTURE_METADATA_ONLY',
            ],true)
            || ($input['recovery']['credential_rotation']??null)!=='NOT_VERIFIED'
            || ($input['recovery']['source_image']??null)!=='NOT_VERIFIED'){
            return Outcome::refuse('VA02_ASSURANCE_INPUT_INVALID');
        }
        $obs=$input['observation'];$cost=$input['cost'];$cover=$input['coverage'];
        $queue=$input['queue'];
        if($queue!==null){
            if(!is_array($queue)||!HistoryReviewQueue::verify($queue)->isOk()
                || $queue['scope']!==$obs['scope']
                || $queue['at_utc']>$input['at_utc']
                || $queue['basis_digest']!==$obs['basis_digest']){
                return Outcome::refuse('VA02_QUEUE_SCOPE_OR_LINEAGE_MISMATCH');
            }
        }
        if($cost['status']==='UNKNOWN_PROVIDER_OUTCOME'){
            if($cost['reserved_units']!==null
                || $cost['spent_units']!==null
                || $cost['unknown_attempts']!==null){
                return Outcome::refuse('VA02_UNKNOWN_PROVIDER_COST_CANNOT_BE_ZERO');
            }
        }elseif(!is_int($cost['reserved_units'])
            || !is_int($cost['spent_units'])
            || !is_int($cost['unknown_attempts'])
            || min($cost['reserved_units'],$cost['spent_units'],
                $cost['unknown_attempts'])<0){
            return Outcome::refuse('VA02_FIXTURE_COST_INVALID');
        }
        if($cover['status']==='NOT_VERIFIED'){
            if($cover['planned_slots']!==null || $cover['reported_slots']!==null
                || $cover['oldest_missing_at_utc']!==null){
                return Outcome::refuse('VA02_COVERAGE_UNKNOWN_NOT_ZERO');
            }
            $gap=null;
        }else{
            if(!is_int($cover['planned_slots'])
                || !is_int($cover['reported_slots'])
                || $cover['planned_slots']<0
                || $cover['planned_slots']>100000
                || $cover['reported_slots']<0
                || $cover['reported_slots']>$cover['planned_slots']
                || ($cover['oldest_missing_at_utc']!==null
                    && (!Vocabulary::utc($cover['oldest_missing_at_utc'])
                        || $cover['oldest_missing_at_utc']>$input['at_utc']))){
                return Outcome::refuse('VA02_COVERAGE_FIXTURE_INVALID');
            }
            $gap=$cover['planned_slots']-$cover['reported_slots'];
        }
        if($input['recovery']['status']==='NOT_VERIFIED_HOST'
            && $input['recovery']['private_bytes']!=='NOT_VERIFIED'){
            return Outcome::refuse('VA02_RECOVERY_HOST_PROOF_FORGED');
        }
        $pending=$queue['pending_reviews']??null;
        $oldest=$queue['oldest_pending_at_utc']??null;
        $lag=null;
        if($oldest!==null){
            $t=strtotime($oldest);$now=strtotime($input['at_utc']);
            if($t===false||$now===false||$now<$t){
                return Outcome::refuse('VA02_QUEUE_PENDING_CLOCK_INVALID');
            }
            $lag=$now-$t;
        }
        $reasons=['SOURCE_USE_NOT_ADMITTED'];
        if($cost['status']==='UNKNOWN_PROVIDER_OUTCOME'){
            $reasons[]='PROVIDER_COST_UNKNOWN_HOLD';
        }
        if($cover['status']==='NOT_VERIFIED'){
            $reasons[]='COVERAGE_NOT_VERIFIED';
        }elseif($gap>0){
            $reasons[]='COVERAGE_GAP_REPORTED';
        }
        if($input['recovery']['status']==='NOT_VERIFIED_HOST'){
            $reasons[]='HOST_RECOVERY_NOT_VERIFIED';
        }
        if(str_starts_with($obs['attention_status'],'SUPPRESSED_')){
            $reasons[]='ATTENTION_SUPPRESSION_REVIEW';
        }
        sort($reasons,SORT_STRING);
        $v=[
            'version'=>self::VERSION,
            'status'=>'M11_ASSESSMENT_CANDIDATE_NOT_OWNER_AUDIT',
            'scope'=>$obs['scope'],
            'observed_at_utc'=>$input['at_utc'],
            'provenance_observation_digest'=>$obs['observation_digest'],
            'lineage_status'=>$obs['lineage_status'],
            'review_stage'=>$obs['review_stage'],
            'reported_outcome_status'=>$obs['outcome_status'],
            'rights_status'=>'U03_NOT_ADOPTED',
            'attention_status'=>$obs['attention_status'],
            'selected_reference_count'=>$obs['selected_reference_count'],
            'contrary_reference_count'=>$obs['contrary_reference_count'],
            'queue_pending_reviews'=>$pending,
            'oldest_pending_age_seconds'=>$lag,
            'coverage_status'=>$cover['status'],
            'coverage_gap_count'=>$gap,
            'budget_status'=>$cost['status'],
            'provider_reserved_units'=>$cost['reserved_units'],
            'provider_spent_units'=>$cost['spent_units'],
            'provider_unknown_attempts'=>$cost['unknown_attempts'],
            'recovery_status'=>$input['recovery']['status'],
            'private_bytes_status'=>$input['recovery']['private_bytes'],
            'credential_rotation_status'=>'NOT_VERIFIED',
            'source_image_status'=>'NOT_VERIFIED',
            'reason_codes'=>$reasons,
            'incident_receipt_status'=>'CANDIDATE_REVIEW_REQUIRED',
            'actual_m11_incident_written'=>false,
            'actual_host_proof_authenticated'=>false,
            'auto_policy_or_model_correction'=>false,
            'customer_visibility_authorized'=>false,
        ];
        $v['report_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verify(array $v):Outcome
    {
        if(($v['version']??null)!==self::VERSION
            || ($v['status']??null)!=='M11_ASSESSMENT_CANDIDATE_NOT_OWNER_AUDIT'
            || !Vocabulary::scope($v['scope']??null)
            || !Vocabulary::utc($v['observed_at_utc']??null)
            || !is_array($v['reason_codes']??null)
            || !array_is_list($v['reason_codes'])
            || count($v['reason_codes'])>8
            || $v['reason_codes']===[]
            || ($v['rights_status']??null)!=='U03_NOT_ADOPTED'
            || !in_array($v['budget_status']??null,self::COST,true)
            || !in_array($v['recovery_status']??null,self::RECOVERY,true)
            || ($v['incident_receipt_status']??null)!=='CANDIDATE_REVIEW_REQUIRED'
            || !is_string($v['report_digest']??null)
            || preg_match(self::HEX,$v['report_digest'])!==1){
            return Outcome::refuse('VA02_SCORECARD_INVALID');
        }
        foreach([
            'actual_m11_incident_written','actual_host_proof_authenticated',
            'auto_policy_or_model_correction','customer_visibility_authorized',
        ] as $flag){
            if(($v[$flag]??null)!==false){
                return Outcome::refuse('VA02_SCORECARD_FORGED_AUTHORITY');
            }
        }
        if($v['budget_status']==='UNKNOWN_PROVIDER_OUTCOME'
            && ($v['provider_reserved_units']!==null
                || $v['provider_spent_units']!==null
                || $v['provider_unknown_attempts']!==null)){
            return Outcome::refuse('VA02_SCORECARD_UNKNOWN_BUDGET_MUTATED');
        }
        try{$copy=$v;$hash=$copy['report_digest'];unset($copy['report_digest']);
            if(!hash_equals($hash,Digest::canonicalDigest($copy))){
                return Outcome::refuse('VA02_SCORECARD_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('VA02_SCORECARD_ENCODING_INVALID');
        }
        return Outcome::ok([
            'integrity'=>'SELF_HASHED_CANDIDATE_ONLY',
            'm11_owner_verified'=>false,
        ]);
    }

    public static function forRuntime():Outcome
    {
        return Outcome::refuse('VA02_M11_OWNER_READ_AND_LOG_NOT_ADOPTED');
    }
}
