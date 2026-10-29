<?php

declare(strict_types=1);

namespace Allfather\Loopia\Jobs;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Substrate\M17\{MemoryStore,P01WindowProfileV2};

/**
 * AQ-01: M17 deterministic candidate rollup preflight only.
 *
 * Does not bypass the existing disposable 280 factory or create a job,
 * scheduler registration, memory row or paid provider attempt.
 */
final class TemporalRollupHandler
{
    public const VERSION='aq01.inert-temporal-rollup-handler.v1';

    /** @param array<string,mixed> $frozenInputs */
    public static function preview(array $frozenInputs):Outcome
    {
        $id=P01WindowProfileV2::freeze($frozenInputs);
        if(!$id->isOk())return $id;
        $preview=MemoryStore::preview($frozenInputs);
        if(!$preview->isOk())return $preview;
        $run=$id->value();
        if($run['v1_rollup_digest']!==$preview->value()['rollup']['rollup_digest']){
            return Outcome::refuse('AQ01_M17_ROLLUP_REPLAY_MISMATCH');
        }
        return Outcome::ok([
            'version'=>self::VERSION,
            'status'=>'OFFLINE_DETERMINISTIC_ROLLUP_CANDIDATE_ONLY',
            'v2_run_digest'=>$run['derivation_identity_digest'],
            'v1_rollup_digest'=>$run['v1_rollup_digest'],
            'selected_reference_count'=>$run['selected_reference_count'],
            'contrary_reference_count'=>$run['contrary_reference_count'],
            'source_use_admitted'=>false,
            'M17_runtime_factory_admitted'=>false,
            'scheduler_job_created'=>false,
            'provider_model_call_authorized'=>false,
            'customer_output_authorized'=>false,
        ]);
    }

    public static function activate(array $decision=[]):Outcome
    {
        return Outcome::refuse('AQ01_U04_U05_U08_TEMPORAL_JOB_NOT_ADOPTED');
    }
}
