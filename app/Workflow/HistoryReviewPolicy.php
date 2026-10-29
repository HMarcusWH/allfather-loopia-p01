<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * DU-01 versioned, caller-supplied deterministic historical review policy.
 * A syntactically valid policy is not a U05 adoption, mission, schedule or grant.
 * v1 M17 ReviewDue/ReviewDueStore identities remain replayable without rewriting.
 */
final class HistoryReviewPolicy
{
    public const VERSION='du01.history-review-policy.v2';
    public const MAX_SOURCES=20;
    public const MAX_PENDING=100;
    private const FIELDS=[
        'version','scope','monitored_source_refs','cadence_seconds',
        'maximum_wait_seconds','cooldown_seconds','max_pending_work',
        'max_basis_references','unique_event_threshold',
        'maximum_reviews_per_utc_day','calendar','supported_triggers',
    ];

    /** @param array<string,mixed> $input */
    public static function freeze(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input,self::FIELDS)
            || ($input['version']??null)!==self::VERSION
            || !Vocabulary::scope($input['scope']??null)
            || !is_array($input['monitored_source_refs'])
            || !array_is_list($input['monitored_source_refs'])
            || count($input['monitored_source_refs'])<1
            || count($input['monitored_source_refs'])>self::MAX_SOURCES
            || ($input['calendar']??null)!=='UTC_SECONDS_V1'
            || !is_array($input['supported_triggers']??null)
            || !Vocabulary::exactKeys($input['supported_triggers'],[
                'new_evidence','review_clock','verified_event_count','contradiction',
            ])) {
            return Outcome::refuse('DU01_POLICY_CONTRACT_INVALID');
        }
        $refs=$input['monitored_source_refs'];
        foreach($refs as $ref){
            if(!Vocabulary::id($ref)){
                return Outcome::refuse('DU01_POLICY_SOURCE_SCOPE_INVALID');
            }
        }
        if(count(array_unique($refs))!==count($refs)){
            return Outcome::refuse('DU01_POLICY_DUPLICATE_SOURCE');
        }
        sort($refs,SORT_STRING);
        $ranges=[
            'cadence_seconds'=>[60,90*86400],
            'maximum_wait_seconds'=>[60,365*86400],
            'cooldown_seconds'=>[0,90*86400],
            'max_pending_work'=>[1,self::MAX_PENDING],
            'max_basis_references'=>[1,HistoricalEvidenceSelector::MAX_REFERENCES],
            'maximum_reviews_per_utc_day'=>[1,24],
        ];
        foreach($ranges as $key=>[$lo,$hi]){
            if(!is_int($input[$key])||$input[$key]<$lo||$input[$key]>$hi){
                return Outcome::refuse('DU01_POLICY_LIMIT_INVALID');
            }
        }
        if($input['maximum_wait_seconds']<$input['cadence_seconds']
            || ($input['unique_event_threshold']!==null
                && (!is_int($input['unique_event_threshold'])
                    || $input['unique_event_threshold']<1
                    || $input['unique_event_threshold']>10000))) {
            return Outcome::refuse('DU01_POLICY_LIMIT_INVALID');
        }
        $triggers=$input['supported_triggers'];
        foreach($triggers as $value){
            if(!is_bool($value)){
                return Outcome::refuse('DU01_POLICY_TRIGGER_INVALID');
            }
        }
        // U02 independent real-world event identity and contradiction
        // admission are not implemented in the observation owner. Never
        // infer them from raw capture counts, model sentiment or a URL.
        if($triggers['review_clock']!==true
            || $triggers['new_evidence']!==true
            || $triggers['verified_event_count']!==false
            || $triggers['contradiction']!==false
            || $input['unique_event_threshold']!==null) {
            return Outcome::refuse('DU01_UNRATIFIED_SEMANTIC_TRIGGER');
        }
        $record=$input;
        $record['monitored_source_refs']=$refs;
        $record['origin_event_count_authoritative']=false;
        $record['owner_policy_adopted']=false;
        $record['historical_source_use_admitted']=false;
        $record['scheduler_activation_authorized']=false;
        $record['provider_spend_authorized']=false;
        $record['notification_authorized']=false;
        $record['customer_output_authorized']=false;
        $record['policy_digest']=Digest::canonicalDigest($record);
        return Outcome::ok($record);
    }

    /** Integrity of the candidate alone, not owner policy ratification. */
    public static function verify(array $candidate): Outcome
    {
        if (!is_array($candidate)
            || !Vocabulary::exactKeys($candidate,[
                ...self::FIELDS,'origin_event_count_authoritative',
                'owner_policy_adopted','historical_source_use_admitted',
                'scheduler_activation_authorized','provider_spend_authorized',
                'notification_authorized','customer_output_authorized','policy_digest',
            ])
            || !is_string($candidate['policy_digest'])
            || preg_match('/^[0-9a-f]{64}$/D',$candidate['policy_digest'])!==1) {
            return Outcome::refuse('DU01_POLICY_DIGEST_OR_SHAPE_INVALID');
        }
        $input=[];
        foreach(self::FIELDS as $field)$input[$field]=$candidate[$field];
        $rebuild=self::freeze($input);
        if (!$rebuild->isOk()
            || Digest::canonicalDigest($rebuild->value())!==Digest::canonicalDigest($candidate)) {
            return Outcome::refuse('DU01_POLICY_REPLAY_MISMATCH');
        }
        return Outcome::ok(['candidate_only'=>true,'U05_policy_adopted'=>false]);
    }
}
