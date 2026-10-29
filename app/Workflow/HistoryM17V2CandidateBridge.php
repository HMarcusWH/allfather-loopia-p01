<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Substrate\M17\{
    InputSet,ProfileResolver,Vocabulary,WindowEngine,P01WindowProfileV2
};

/**
 * TM-01: derivative, offline HS-01 basis -> M17 U04 Option A P01 candidate.
 *
 * Caller metadata remains unauthenticated; source reuse, new calendar,
 * model-assisted processing, memory writes and release are NOT admitted.
 */
final class HistoryM17V2CandidateBridge
{
    public const VERSION='tm01.hs01-to-p01-window-profile-v2.v1';

    /** @param array<string,mixed> $basis */
    public static function compose(array $basis,string $dataClass,string $windowKind): Outcome
    {
        if (!PointInTimeHistoricalBasis::verify($basis)->isOk()
            || ($basis['status']??null)!=='OFFLINE_NOT_ADMITTED'
            || ($basis['selection_integrity']??null)!=='COMPLETE_WITHIN_INPUT'
            || ($basis['historical_reuse_admitted']??null)!==false
            || ($basis['model_context_authorized']??null)!==false
            || ($basis['coverage_owner_verified']??null)!==false
            || ($basis['excluded_counts_by_reason']['SELECTION_CAP']??null)!==0
            || !in_array($dataClass,Vocabulary::SUPPORTED_CLASSES,true)
            || !in_array($windowKind,Vocabulary::WINDOW_KINDS,true)
            || ($basis['selected_references']??[])===[]) {
            return Outcome::refuse('TM01_HS01_BASIS_NOT_ELIGIBLE_FOR_OFFLINE_DERIVATION');
        }
        $scope=$basis['scope'];
        $refs=[];
        foreach($basis['selected_references'] as $row){
            $refs[]=[
                'ref_id'=>$row['ref_id'],
                'ref_type'=>$dataClass,
                'source_ref_id'=>$row['source_ref_id'],
                'observed_at_utc'=>$row['observed_at'],
                'payload_sha256'=>$row['payload_sha256'],
                'magnitude_milli'=>null,
                'polarity'=>$row['semantic_polarity'],
                'conflict_flag'=>$row['semantic_polarity']==='CONTRARY',
                'diagnostic_flag'=>false,
            ];
        }
        $frozen=InputSet::freeze([
            'scope'=>$scope,'data_class'=>$dataClass,'refs'=>$refs,
        ]);
        if(!$frozen->isOk())return Outcome::refuse('TM01_M17_INPUT_NOT_REPLAYABLE');
        $window=WindowEngine::assign([
            'kind'=>$windowKind,
            'observation_at_utc'=>$refs[0]['observed_at_utc'],
            'calendar_config'=>null,
        ]);
        if(!$window->isOk())return Outcome::refuse('TM01_UTC_WINDOW_NOT_AVAILABLE');
        if($window->value()['start_inclusive_utc']!==$basis['period']['start_inclusive_utc']
            || $window->value()['end_exclusive_utc']!==$basis['period']['end_exclusive_utc']){
            return Outcome::refuse('TM01_WINDOW_PERIOD_MISMATCH');
        }
        foreach($refs as $ref) {
            if($ref['observed_at_utc']<$window->value()['start_inclusive_utc']
                || $ref['observed_at_utc']>=$window->value()['end_exclusive_utc']){
                return Outcome::refuse('TM01_REFERENCE_OUTSIDE_WINDOW');
            }
        }
        // Freeze proposed profile at *period start*, not changing later with
        // knowledge-cut successor. Identical period uses the same profile.
        $profile=ProfileResolver::resolve([
            'scope'=>$scope,'data_class'=>$dataClass,
            'evaluation_time_utc'=>$basis['period']['start_inclusive_utc'],
            'candidates'=>[],'floors'=>[],
        ]);
        if(!$profile->isOk())return Outcome::refuse('TM01_PROFILE_NOT_REPLAYABLE');
        $frozenInputs=[
            'input_set'=>$frozen->value(),
            'window'=>$window->value(),
            'profile_candidate'=>$profile->value(),
            'importance_weights'=>null,
        ];
        $candidate=P01WindowProfileV2::freeze($frozenInputs);
        if(!$candidate->isOk())return $candidate;
        if(($candidate->value()['selected_reference_count']??null)!==$basis['selected_count']){
            return Outcome::refuse('TM01_LINEAGE_CARDINALITY_MISMATCH');
        }
        $result=[
            'version'=>self::VERSION,
            'candidate_state'=>'NOT_ADMITTED',
            'source_basis_digest'=>$basis['basis_digest'],
            'source_basis_known_at_cutoff'=>$basis['knowledge_cutoff_utc'],
            'source_basis_predecessor_digest'=>$basis['previous_basis_digest'],
            'source_basis_authority'=>'SELF_HASHED_OFFLINE_METADATA_ONLY',
            'data_class'=>$dataClass,
            'window_kind'=>$windowKind,
            'frozen_m17_inputs'=>$frozenInputs,
            'candidate_v2'=>$candidate->value(),
            'm17_v2_run_digest'=>$candidate->value()['derivation_identity_digest'],
            'v1_rollup_digest'=>$candidate->value()['v1_rollup_digest'],
            'lineage_count'=>$candidate->value()['selected_reference_count'],
            'historical_source_use_admitted'=>false,
            'p01_p02_cross_namespace_admitted'=>false,
            'profile_owner_admitted'=>false,
            'runtime_persistence_authorized'=>false,
            'model_or_customer_output_authorized'=>false,
            'production_authorized'=>false,
        ];
        $result['bridge_digest']=Digest::canonicalDigest($result);
        return Outcome::ok($result);
    }

    public static function verify(array $candidate): Outcome
    {
        if(($candidate['version']??null)!==self::VERSION
            || ($candidate['candidate_state']??null)!=='NOT_ADMITTED'
            || ($candidate['source_basis_authority']??null)!=='SELF_HASHED_OFFLINE_METADATA_ONLY'
            || !is_array($candidate['candidate_v2']??null)
            || !P01WindowProfileV2::verify($candidate['candidate_v2'])->isOk()
            || ($candidate['m17_v2_run_digest']??null)!==$candidate['candidate_v2']['derivation_identity_digest']
            || ($candidate['v1_rollup_digest']??null)!==$candidate['candidate_v2']['v1_rollup_digest']
            || ($candidate['lineage_count']??null)!==$candidate['candidate_v2']['selected_reference_count']
            || !is_string($candidate['bridge_digest']??null)) {
            return Outcome::refuse('TM01_BRIDGE_CONTRACT_INVALID');
        }
        foreach(['historical_source_use_admitted','p01_p02_cross_namespace_admitted',
            'profile_owner_admitted','runtime_persistence_authorized',
            'model_or_customer_output_authorized','production_authorized'] as $key){
            if(($candidate[$key]??null)!==false){
                return Outcome::refuse('TM01_BRIDGE_AUTHORITY_FORGED');
            }
        }
        $digest=$candidate['bridge_digest'];
        unset($candidate['bridge_digest']);
        try {
            if(!hash_equals($digest,Digest::canonicalDigest($candidate))){
                return Outcome::refuse('TM01_BRIDGE_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('TM01_BRIDGE_ENCODING_INVALID');
        }
        return Outcome::ok(['integrity'=>'CANDIDATE_ONLY','historical_reuse_admitted'=>false]);
    }

    /** No provider, external action, owner-approval or memory-writing seam. */
    public static function activate(array $candidate): Outcome
    {
        return Outcome::refuse('TM01_U03_U04_AND_LIVE_M17_OWNER_ADMISSION_PENDING');
    }
}
