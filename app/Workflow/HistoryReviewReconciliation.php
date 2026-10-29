<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-01: conservative replay classification for a caller-described attempt.
 *
 * No retry method. A lost worker, expired lease or unknown provider outcome
 * never refunds a reservation, re-dispatches a request or invents a success.
 * Source owner, model provider, M06 budget and attempt receipts remain owned
 * by their existing services. This is a source-only planning contract.
 */
final class HistoryReviewReconciliation
{
    public const VERSION='an01.inert-provider-reconciliation.v1';
    private const INPUT_KEYS=[
        'request_candidate','attempt_receipt','inspected_at_utc',
    ];
    private const RECEIPT_KEYS=[
        'attempt_id','request_digest','receipt_kind','transmitted_at_utc',
        'receipt_known_at_utc','retained_candidate_digest',
        'usage_disposition','current_provider_owner_verified',
    ];
    private const KINDS=[
        'NOT_SENT_CONFIRMED','TRANSMISSION_OUTCOME_UNKNOWN',
        'PROVIDER_RETURN_RETAINED_METADATA',
    ];
    private const HEX='/^[a-f0-9]{64}$/D';

    /** @param array<string,mixed> $input */
    public static function inspect(array $input): Outcome
    {
        if(!Vocabulary::exactKeys($input,self::INPUT_KEYS)
            || !is_array($input['request_candidate'])
            || !HistoryReviewAdmission::verify($input['request_candidate'])->isOk()
            || !is_array($input['attempt_receipt'])
            || !Vocabulary::exactKeys($input['attempt_receipt'],self::RECEIPT_KEYS)
            || !Vocabulary::utc($input['inspected_at_utc'])) {
            return Outcome::refuse('AN01_RECONCILIATION_CONTRACT_INVALID');
        }
        $p=$input['request_candidate'];$r=$input['attempt_receipt'];
        if($r['attempt_id']!==$p['attempt_id']
            || $r['request_digest']!==$p['request_digest']
            || !in_array($r['receipt_kind'],self::KINDS,true)
            || $r['current_provider_owner_verified']!==false
            || !Vocabulary::utc($r['receipt_known_at_utc'])
            || $r['receipt_known_at_utc']>$input['inspected_at_utc']
            || !in_array($r['usage_disposition'],['UNKNOWN','KNOWN_ZERO','KNOWN_NONZERO'],true)) {
            return Outcome::refuse('AN01_RECONCILIATION_IDENTITY_OR_OWNER_INVALID');
        }
        if($r['receipt_kind']==='NOT_SENT_CONFIRMED'){
            if($r['transmitted_at_utc']!==null
                || $r['retained_candidate_digest']!==null
                || $r['usage_disposition']!=='KNOWN_ZERO'){
                return Outcome::refuse('AN01_RECONCILIATION_FALSE_NOT_SENT');
            }
            $status='NOT_SENT_CANDIDATE_CONFIRMATION_ONLY';
        }elseif($r['receipt_kind']==='TRANSMISSION_OUTCOME_UNKNOWN'){
            if(!Vocabulary::utc($r['transmitted_at_utc'])
                || $r['transmitted_at_utc']>$r['receipt_known_at_utc']
                || $r['retained_candidate_digest']!==null
                || $r['usage_disposition']!=='UNKNOWN'){
                return Outcome::refuse('AN01_RECONCILIATION_UNKNOWN_OUTCOME_CONTRACT_INVALID');
            }
            $status='UNKNOWN_OUTCOME_NO_REDISPATCH_HOLD';
        }else{
            if(!Vocabulary::utc($r['transmitted_at_utc'])
                || $r['transmitted_at_utc']>$r['receipt_known_at_utc']
                || !is_string($r['retained_candidate_digest'])
                || preg_match(self::HEX,$r['retained_candidate_digest'])!==1){
                return Outcome::refuse('AN01_RECONCILIATION_RETAINED_ARTIFACT_INVALID');
            }
            // A caller-provided metadata hash is neither a recovered M05
            // source nor an independently validated M01 provider artifact.
            $status='RETAINED_METADATA_NOT_ADOPTED_FOR_USE';
        }
        $result=[
            'version'=>self::VERSION,
            'status'=>$status,
            'request_candidate_digest'=>$p['candidate_digest'],
            'attempt_id'=>$p['attempt_id'],
            'request_digest'=>$p['request_digest'],
            'inspected_at_utc'=>$input['inspected_at_utc'],
            'receipt_kind'=>$r['receipt_kind'],
            'usage_disposition'=>$r['usage_disposition'],
            'usage_counted_as_spent_or_unknown'=>($r['receipt_kind']!=='NOT_SENT_CONFIRMED'),
            'no_automatic_retry'=>true,
            'same_attempt_id_replay_required'=>true,
            'provider_owner_current_verified'=>false,
            'provider_response_bytes_authenticated'=>false,
            'candidate_accepted_for_model_history'=>false,
            'parent_budget_refunded'=>false,
            'new_attempt_authorized'=>false,
            'source_historical_reuse_admitted'=>false,
            'customer_release_authorized'=>false,
        ];
        $result['reconciliation_digest']=Digest::canonicalDigest($result);
        return Outcome::ok($result);
    }

    public static function verify(array $candidate): Outcome
    {
        if(($candidate['version']??null)!==self::VERSION
            || !is_string($candidate['reconciliation_digest']??null)
            || preg_match(self::HEX,$candidate['reconciliation_digest'])!==1
            || ($candidate['no_automatic_retry']??null)!==true
            || ($candidate['same_attempt_id_replay_required']??null)!==true
            || ($candidate['provider_owner_current_verified']??null)!==false
            || ($candidate['provider_response_bytes_authenticated']??null)!==false
            || ($candidate['candidate_accepted_for_model_history']??null)!==false
            || ($candidate['parent_budget_refunded']??null)!==false
            || ($candidate['new_attempt_authorized']??null)!==false
            || ($candidate['source_historical_reuse_admitted']??null)!==false
            || ($candidate['customer_release_authorized']??null)!==false) {
            return Outcome::refuse('AN01_RECONCILIATION_AUTHORITY_OR_SHAPE_INVALID');
        }
        try{
            $dig=$candidate['reconciliation_digest'];
            unset($candidate['reconciliation_digest']);
            if(!hash_equals($dig,Digest::canonicalDigest($candidate))){
                return Outcome::refuse('AN01_RECONCILIATION_DIGEST_MISMATCH');
            }
        }catch(\Throwable) {
            return Outcome::refuse('AN01_RECONCILIATION_ENCODING_INVALID');
        }
        return Outcome::ok(['integrity'=>'METADATA_ONLY_NO_PROVIDER_APPROVAL']);
    }
}
