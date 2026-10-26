<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Substrate\M17\{
    InputSet,MemoryStore,ProfileResolver,WindowEngine,RollupEngine,Vocabulary
};

/**
 * LI-07C: pure adapter from the existing LI-05A frozen metadata basis
 * to the already-tested LI-07B disposable M17 writer.
 *
 * DOES NOT authenticate M05/source rights or activate persistence. A metadata
 * digest is NOT an evidence proof. The writer still needs its independent
 * explicit CLI/disposable-test-only factory.
 */
final class HistoryM17CandidateBridge
{
    public const VERSION='li07c.offline-history-m17-bridge.v1';

    /** @param array<string,mixed> $basis */
    public static function compose(array $basis,string $dataClass,string $windowKind):Outcome
    {
        if (!HistoryReviewBasis::verify($basis)->isOk()
            || ($basis['status']??null)!=='OFFLINE_NOT_ADMITTED'
            || ($basis['selection_integrity']??null)!=='COMPLETE_WITHIN_INPUT'
            || ($basis['historical_reuse_admitted']??null)!==false
            || !in_array($dataClass,Vocabulary::SUPPORTED_CLASSES,true)
            || !in_array($windowKind,Vocabulary::WINDOW_KINDS,true)
            || ($basis['references']??[])===[]) {
            return Outcome::refuse('LI07C_BASIS_NOT_ADMISSIBLE_FOR_CANDIDATE');
        }
        $scope=$basis['scope'];
        $refs=[];
        foreach ($basis['references'] as $row) {
            // LI-05A has NO authenticated economic magnitude or independent
            // diagnostic flag. A bridge cannot invent them from polarity.
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
        $set=InputSet::freeze(['scope'=>$scope,'data_class'=>$dataClass,'refs'=>$refs]);
        if (!$set->isOk())return Outcome::refuse('LI07C_INPUT_SET_NOT_REPLAYABLE');
        $window=WindowEngine::assign([
            'kind'=>$windowKind,
            'observation_at_utc'=>$refs[0]['observed_at_utc'],
            // UTC/ISO only. Non-UTC M13 calendar artifact not yet adopted.
            'calendar_config'=>null,
        ]);
        if (!$window->isOk())return Outcome::refuse('LI07C_WINDOW_UNAVAILABLE');
        if ($window->value()['start_inclusive_utc']!==$basis['period']['start_inclusive_utc']
            || $window->value()['end_exclusive_utc']!==$basis['period']['end_exclusive_utc']) {
            return Outcome::refuse('LI07C_PERIOD_WINDOW_MISMATCH');
        }
        foreach ($refs as $ref) {
            if ($ref['observed_at_utc']<$window->value()['start_inclusive_utc']
                || $ref['observed_at_utc']>=$window->value()['end_exclusive_utc']) {
                return Outcome::refuse('LI07C_REF_OUTSIDE_WINDOW');
            }
        }
        $profile=ProfileResolver::resolve([
            'scope'=>$scope,'data_class'=>$dataClass,
            'evaluation_time_utc'=>$basis['knowledge_cutoff_utc'],
            'candidates'=>[],'floors'=>[],
        ]);
        if (!$profile->isOk())return Outcome::refuse('LI07C_PROFILE_UNAVAILABLE');
        $inputs=[
            'input_set'=>$set->value(),'window'=>$window->value(),
            'profile_candidate'=>$profile->value(),'importance_weights'=>null,
        ];
        $preview=MemoryStore::preview($inputs);
        if (!$preview->isOk())return Outcome::refuse('LI07C_ROLLUP_NOT_REPLAYABLE');
        $rollup=RollupEngine::derive($inputs);
        if (!$rollup->isOk()
            || ($rollup->value()['lineage']['selected_count']??null)!==$basis['selected_count']
            || $preview->value()['rollup']['rollup_digest']!==$rollup->value()['rollup_digest']) {
            return Outcome::refuse('LI07C_LINEAGE_COUNT_OR_DIGEST_MISMATCH');
        }
        return Outcome::ok([
            'version'=>self::VERSION,
            'source_basis_digest'=>$basis['manifest_digest'],
            'source_basis_status'=>'NOT_AUTHENTICATED_FOR_U03',
            'candidate_state'=>'NOT_ADMITTED',
            'frozen_m17_inputs'=>$inputs,
            'm17_derivation_identity_digest'=>$preview->value()['derivation_identity_digest'],
            'm17_rollup_digest'=>$rollup->value()['rollup_digest'],
            'lineage_count'=>$rollup->value()['lineage']['selected_count'],
            'historical_reuse_admitted'=>false,
            'runtime_persistence_authorized'=>false,
            'model_or_customer_output_authorized'=>false,
        ]);
    }

    /** No production M17 writer can be reached via this pure projection. */
    public static function activate(array $candidate): Outcome
    {
        return Outcome::refuse('LI07C_U03_AND_RUNTIME_GRANTS_NOT_ADOPTED');
    }
}
