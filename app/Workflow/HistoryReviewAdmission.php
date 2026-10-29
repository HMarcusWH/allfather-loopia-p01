<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-01: pure, inactive historical model request/reservation envelope.
 *
 * It deliberately does NOT extend ExecutionGrantStore::PURPOSES or add an
 * ExecutionAdmission::CHECKPOINT. The current live owner can only authorize
 * its existing closed purposes. An unratified U06 purpose can never borrow
 * MANUAL_MONITOR or MODEL_EVALUATION budgets, roles or credentials.
 *
 * A verified candidate is NOT an M02/M05/M06 grant, not proof of reviewed
 * source rights, and never permission to call a model.
 */
final class HistoryReviewAdmission
{
    public const VERSION='an01.inert-historical-request.v1';
    public const PURPOSE='HISTORICAL_REVIEW_MANUAL_UNADOPTED';
    public const MODE='SYNTHETIC_TEST_INSPECTION_ONLY';
    private const KEYS=[
        'version','scope','review_id','basis_digest','question_digest',
        'prompt_bundle_digest','model_policy_digest','credential_ref_digest',
        'parent_grant_digest','app_fingerprint','request_digest','attempt_id',
        'purpose','mode','requested_at_utc','valid_from_utc','valid_until_utc',
        'parent_max_attempts','review_max_attempts','max_input_tokens',
        'max_output_tokens','disclosure_class',
    ];
    private const HEX='/^[a-f0-9]{64}$/D';

    /** @param array<string,mixed> $input */
    public static function freeze(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input,self::KEYS)
            || $input['version']!==self::VERSION
            || $input['purpose']!==self::PURPOSE
            || $input['mode']!==self::MODE
            || !Vocabulary::scope($input['scope'])
            || !Vocabulary::id($input['review_id'])
            || !Vocabulary::id($input['attempt_id'])
            || !in_array($input['disclosure_class'],['NO_BYTES_LOCAL_METADATA','SOURCE_BYTE_DISCLOSURE_HELD'],true)
            || $input['disclosure_class']!=='NO_BYTES_LOCAL_METADATA'
            || !Vocabulary::utc($input['requested_at_utc'])
            || !Vocabulary::utc($input['valid_from_utc'])
            || !Vocabulary::utc($input['valid_until_utc'])
            || $input['valid_from_utc']>$input['requested_at_utc']
            || $input['requested_at_utc']>=$input['valid_until_utc']
            || strtotime($input['valid_until_utc'])-strtotime($input['valid_from_utc'])>31*86400) {
            return Outcome::refuse('AN01_HISTORICAL_REQUEST_CONTRACT_INVALID');
        }
        foreach([
            'basis_digest','question_digest','prompt_bundle_digest',
            'model_policy_digest','credential_ref_digest','parent_grant_digest',
            'app_fingerprint','request_digest',
        ] as $digest){
            if(!is_string($input[$digest])||preg_match(self::HEX,$input[$digest])!==1){
                return Outcome::refuse('AN01_HISTORICAL_REQUEST_DIGEST_INVALID');
            }
        }
        foreach([
            'parent_max_attempts'=>[1,64],
            'review_max_attempts'=>[1,4],
            'max_input_tokens'=>[1,8192],
            'max_output_tokens'=>[1,3072],
        ] as $field=>[$minimum,$maximum]){
            if(!is_int($input[$field])||$input[$field]<$minimum
                ||$input[$field]>$maximum){
                return Outcome::refuse('AN01_HISTORICAL_BUDGET_INVALID');
            }
        }
        if($input['parent_max_attempts']<$input['review_max_attempts']){
            return Outcome::refuse('AN01_HISTORICAL_BUDGET_INVALID');
        }
        $candidate=$input;
        $candidate['identity_kind']='HISTORICAL_REQUEST_METADATA_ONLY';
        $candidate['binding_authority']='UNATTESTED_CANDIDATE_NO_LIVE_GRANT';
        $candidate['owner_current_source_verified']=false;
        $candidate['owner_U03_ratified']=false;
        $candidate['owner_U06_ratified']=false;
        $candidate['owner_U08_ratified']=false;
        $candidate['parent_budget_authorized']=false;
        $candidate['provider_disclosure_authorized']=false;
        $candidate['model_attempt_reserved']=false;
        $candidate['runtime_execution_authorized']=false;
        $candidate['customer_release_authorized']=false;
        $candidate['candidate_digest']=Digest::canonicalDigest($candidate);
        return Outcome::ok($candidate);
    }

    public static function verify(array $candidate): Outcome
    {
        if(!Vocabulary::exactKeys($candidate,[
            ...self::KEYS,'identity_kind','binding_authority',
            'owner_current_source_verified','owner_U03_ratified',
            'owner_U06_ratified','owner_U08_ratified',
            'parent_budget_authorized','provider_disclosure_authorized',
            'model_attempt_reserved','runtime_execution_authorized',
            'customer_release_authorized','candidate_digest',
        ]) || ($candidate['identity_kind']??null)!=='HISTORICAL_REQUEST_METADATA_ONLY'
            || ($candidate['binding_authority']??null)!=='UNATTESTED_CANDIDATE_NO_LIVE_GRANT'
            || !is_string($candidate['candidate_digest'])
            || preg_match(self::HEX,$candidate['candidate_digest'])!==1) {
            return Outcome::refuse('AN01_CANDIDATE_SHAPE_INVALID');
        }
        foreach([
            'owner_current_source_verified','owner_U03_ratified','owner_U06_ratified',
            'owner_U08_ratified','parent_budget_authorized',
            'provider_disclosure_authorized','model_attempt_reserved',
            'runtime_execution_authorized','customer_release_authorized',
        ] as $flag){
            if($candidate[$flag]!==false){
                return Outcome::refuse('AN01_CANDIDATE_AUTHORITY_FORGED');
            }
        }
        $input=[];
        foreach(self::KEYS as $key)$input[$key]=$candidate[$key];
        $fresh=self::freeze($input);
        if(!$fresh->isOk()
            || $fresh->value()!==$candidate){
            return Outcome::refuse('AN01_CANDIDATE_REPLAY_MISMATCH');
        }
        return Outcome::ok(['integrity'=>'SELF_HASHED_CANDIDATE_ONLY',
            'provider_execution_authorized'=>false]);
    }

    public static function authorize(array $candidate): Outcome
    {
        return Outcome::refuse('AN01_U03_U06_U08_HISTORICAL_MODEL_ADMISSION_NOT_RATIFIED');
    }
}
