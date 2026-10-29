<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * TM-01: proposed P01 WindowProfile.v2 / U04 Option A identity.
 *
 * Reuses v1 deterministic input/profile/window/rollup algorithms without
 * rewriting historical hashes. A v2 run identity is a proposal, not an
 * installed CompressionRun, granted source use or current head selection.
 */
final class P01WindowProfileV2
{
    public const VERSION = 'tm01.p01-window-profile.v2';
    public const SCOPE_VERSION = 'm17.p01-competitor-question.v2';
    public const CALENDAR_VERSION = 'UTC_FIXED_SECONDS_V1';

    /** @param array<string,mixed> $inputs */
    public static function freeze(array $inputs): Outcome
    {
        if (!Vocabulary::exactKeys($inputs,[
            'input_set','window','profile_candidate','importance_weights',
        ]) || !is_array($inputs['input_set']) || !is_array($inputs['window'])
            || !is_array($inputs['profile_candidate'])
            || $inputs['importance_weights'] !== null
            || !InputSet::verify($inputs['input_set'])->isOk()
            || !WindowEngine::verifyWindow($inputs['window'])->isOk()
            || !ProfileResolver::verify($inputs['profile_candidate'])->isOk()) {
            return Outcome::refuse('TM01_FROZEN_INPUT_CONTRACT_INVALID');
        }
        $set=$inputs['input_set'];
        $window=$inputs['window'];
        $profile=$inputs['profile_candidate'];
        $scope=$set['scope'];
        if (!Vocabulary::scope($scope)
            || ($window['calendar_timezone']??null)!=='UTC'
            || ($window['calendar_origin']??null)!=='UTC_ISO_FALLBACK'
            || ($window['start_offset_seconds']??null)!==0
            || ($window['end_offset_seconds']??null)!==0
            || ($profile['scope']??null)!==$scope
            || ($profile['data_class']??null)!==$set['data_class']
            || ($profile['status']??null)!=='OFFLINE_NOT_ADMITTED'
            || ($profile['evaluation_time_utc']??null)!==$window['start_inclusive_utc']
            || $set['refs']===[]) {
            return Outcome::refuse('TM01_P01_SCOPE_CALENDAR_PROFILE_NOT_SUPPORTED');
        }
        foreach($set['refs'] as $ref){
            if ($ref['observed_at_utc']<$window['start_inclusive_utc']
                || $ref['observed_at_utc']>=$window['end_exclusive_utc']) {
                return Outcome::refuse('TM01_REF_OUTSIDE_FROZEN_WINDOW');
            }
        }
        $preview=MemoryStore::preview($inputs);
        if (!$preview->isOk()) return Outcome::refuse('TM01_M17_ROLLUP_NOT_REPLAYABLE');
        $rollup=$preview->value()['rollup'];
        if (($rollup['lineage']['selected_count']??null)!==count($set['refs'])
            || ($rollup['caveats']['commercial_materiality']??null)!=='NOT_ASSESSED'
            || ($rollup['measures']['independent_event_count']??null)!==null) {
            return Outcome::refuse('TM01_LINEAGE_OR_CLAIM_CEILING_INVALID');
        }
        // Run identity includes exact typed P01 subject/question, input and
        // resolved profile; it must NOT include a mutable memory output or a
        // period-only uniqueness key. Run FK and output identity are separate.
        $identity=[
            'version'=>self::VERSION,
            'scope_schema_version'=>self::SCOPE_VERSION,
            'tenant_id'=>$scope['tenant_id'],
            'workspace_id'=>$scope['workspace_id'],
            'subject_kind'=>'P01_COMPETITOR',
            'subject_id'=>$scope['competitor_id'],
            'question_id'=>$scope['question_id'],
            'data_class'=>$set['data_class'],
            'window_kind'=>$window['kind'],
            'period_start_utc'=>$window['start_inclusive_utc'],
            'period_end_utc'=>$window['end_exclusive_utc'],
            'calendar_version'=>self::CALENDAR_VERSION,
            'source_tzdb_version'=>$window['tzdb_version'],
            'engine_version'=>$rollup['engine_version'],
            'input_digest'=>$set['input_digest'],
            'resolved_profile_digest'=>$profile['resolution_digest'],
        ];
        $candidate=[
            'version'=>self::VERSION,
            'scope_schema_version'=>self::SCOPE_VERSION,
            'candidate_state'=>'OFFLINE_NOT_ADMITTED',
            'run_identity'=>$identity,
            'derivation_identity_digest'=>Digest::canonicalDigest($identity),
            'v1_rollup_digest'=>$rollup['rollup_digest'],
            'selected_reference_count'=>count($set['refs']),
            'contrary_reference_count'=>count(array_filter($set['refs'],
                static fn(array $r):bool=>$r['polarity']==='CONTRARY')),
            'memory_output_identity'=>[
                'compression_run_id'=>null,
                'memory_class'=>'PERIOD_SUMMARY',
                'output_ordinal'=>0,
                'required_unique_key'=>'COMPRESSION_RUN_ID_MEMORY_CLASS_OUTPUT_ORDINAL',
            ],
            'head_resolution'=>'NOT_PERFORMED_AMBIGUOUS_HEAD_MUST_HOLD',
            'frozen_v1_input_set_digest'=>$set['input_digest'],
            'frozen_v1_window_digest'=>$window['window_digest'],
            'frozen_v1_profile_digest'=>$profile['resolution_digest'],
            'source_authority_verified'=>false,
            'historical_reuse_admitted'=>false,
            'profile_authority_adopted'=>false,
            'native_persistence_authorized'=>false,
            'model_assisted_compression_authorized'=>false,
            'customer_release_authorized'=>false,
        ];
        $candidate['candidate_digest']=Digest::canonicalDigest($candidate);
        return Outcome::ok($candidate);
    }

    /** Self-hash/contract check; not the U04/M05 authority or live run certificate. */
    public static function verify(array $value): Outcome
    {
        if (!Vocabulary::exactKeys($value,[
            'version','scope_schema_version','candidate_state','run_identity',
            'derivation_identity_digest','v1_rollup_digest','selected_reference_count',
            'contrary_reference_count','memory_output_identity','head_resolution',
            'frozen_v1_input_set_digest','frozen_v1_window_digest','frozen_v1_profile_digest',
            'source_authority_verified','historical_reuse_admitted','profile_authority_adopted',
            'native_persistence_authorized','model_assisted_compression_authorized',
            'customer_release_authorized','candidate_digest',
        ]) || $value['version']!==self::VERSION
            || $value['scope_schema_version']!==self::SCOPE_VERSION
            || $value['candidate_state']!=='OFFLINE_NOT_ADMITTED'
            || $value['head_resolution']!=='NOT_PERFORMED_AMBIGUOUS_HEAD_MUST_HOLD'
            || !is_array($value['run_identity'])
            || !self::hex($value['candidate_digest'])
            || !self::hex($value['derivation_identity_digest'])
            || !self::hex($value['v1_rollup_digest'])
            || !self::hex($value['frozen_v1_input_set_digest'])
            || !self::hex($value['frozen_v1_window_digest'])
            || !self::hex($value['frozen_v1_profile_digest'])
            || !is_int($value['selected_reference_count'])
            || $value['selected_reference_count']<1
            || $value['selected_reference_count']>InputSet::MAX_REFS
            || !is_int($value['contrary_reference_count'])
            || $value['contrary_reference_count']<0
            || $value['contrary_reference_count']>$value['selected_reference_count']
            || !Vocabulary::exactKeys($value['memory_output_identity'],[
                'compression_run_id','memory_class','output_ordinal','required_unique_key',
            ])
            || $value['memory_output_identity']['compression_run_id']!==null
            || $value['memory_output_identity']['memory_class']!=='PERIOD_SUMMARY'
            || $value['memory_output_identity']['output_ordinal']!==0
            || $value['memory_output_identity']['required_unique_key']!=='COMPRESSION_RUN_ID_MEMORY_CLASS_OUTPUT_ORDINAL') {
            return Outcome::refuse('TM01_V2_CANDIDATE_CONTRACT_INVALID');
        }
        foreach(['source_authority_verified','historical_reuse_admitted','profile_authority_adopted',
                 'native_persistence_authorized','model_assisted_compression_authorized',
                 'customer_release_authorized'] as $field){
            if($value[$field]!==false)return Outcome::refuse('TM01_V2_AUTHORITY_FORGERY');
        }
        $identity=$value['run_identity'];
        if (($identity['version']??null)!==self::VERSION
            || ($identity['scope_schema_version']??null)!==self::SCOPE_VERSION
            || ($identity['subject_kind']??null)!=='P01_COMPETITOR'
            || ($identity['calendar_version']??null)!==self::CALENDAR_VERSION
            || ($identity['input_digest']??null)!==$value['frozen_v1_input_set_digest']
            || ($identity['resolved_profile_digest']??null)!==$value['frozen_v1_profile_digest']
            || !hash_equals(Digest::canonicalDigest($identity),$value['derivation_identity_digest'])) {
            return Outcome::refuse('TM01_V2_RUN_IDENTITY_MISMATCH');
        }
        try {
            $digest=$value['candidate_digest'];unset($value['candidate_digest']);
            if(!hash_equals($digest,Digest::canonicalDigest($value))){
                return Outcome::refuse('TM01_V2_CANDIDATE_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('TM01_V2_CANDIDATE_INVALID');
        }
        return Outcome::ok(['integrity'=>'OFFLINE_CANDIDATE_ONLY','runtime_admitted'=>false]);
    }
}
