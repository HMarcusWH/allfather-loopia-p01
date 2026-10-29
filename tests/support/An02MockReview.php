<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Workflow\{
    HistoryReviewAdmission,HistoryReviewFactory,HistoryReviewService,
    HistoryM17V2CandidateBridge,PointInTimeHistoricalBasis
};

/**
 * AN-02 safe, deterministic local candidate fixture.
 * No real bytes, M02 actor, transport, model, paid attempt or human review.
 * The fixture is under tests/support and is never runtime PSR-4 autoloaded.
 */
final class An02MockReview
{
    public static function simulate():Outcome
    {
        try {
            $catalog=json_decode((string)file_get_contents(
                dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
                true,64,JSON_THROW_ON_ERROR,
            );
            $basis=PointInTimeHistoricalBasis::freeze([
                'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
                'legacy_input'=>[
                    'scope'=>$catalog['scope'],
                    'period'=>$catalog['period'],
                    'baseline_period'=>$catalog['baseline_period'],
                    'baseline_null_reason'=>null,
                    'knowledge_cutoff_utc'=>$catalog['knowledge_cutoff_utc'],
                    'rows'=>$catalog['rows'],'max_references'=>8,
                    'coverage'=>[
                        'state'=>'KNOWN','plan_version_id'=>'an02.fixed.mock',
                        'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
                        'failed'=>0,'skipped'=>0,'unreported'=>0,
                    ],
                ],
                'previous_basis_digest'=>null,
                'review_at_utc'=>'2026-03-15T00:00:00Z',
            ]);
            if(!$basis->isOk())return Outcome::refuse('AN02_FIXED_BASIS_REFUSED');
            $bridge=HistoryM17V2CandidateBridge::compose(
                $basis->value(),'SOURCE_DIFF','MONTH',
            );
            if(!$bridge->isOk())return Outcome::refuse('AN02_FIXED_MEMORY_REFUSED');
            $request=HistoryReviewAdmission::freeze([
                'version'=>HistoryReviewAdmission::VERSION,
                'scope'=>$catalog['scope'],
                'review_id'=>'an02_fixed_synthetic_review',
                'basis_digest'=>$basis->value()['basis_digest'],
                'question_digest'=>hash('sha256','an02.fixed.question'),
                'prompt_bundle_digest'=>hash('sha256','an02.fixed.prompt'),
                'model_policy_digest'=>hash('sha256','an02.fixed.no_model'),
                'credential_ref_digest'=>hash('sha256','an02.fixed.no_credential'),
                'parent_grant_digest'=>hash('sha256','an02.fixed.no_grant'),
                'app_fingerprint'=>hash('sha256','an02.fixed.app'),
                'request_digest'=>hash('sha256','an02.fixed.request'),
                'attempt_id'=>'an02_fixed_synthetic_attempt',
                'purpose'=>HistoryReviewAdmission::PURPOSE,
                'mode'=>HistoryReviewAdmission::MODE,
                'requested_at_utc'=>'2026-10-29T12:00:00Z',
                'valid_from_utc'=>'2026-10-29T11:00:00Z',
                'valid_until_utc'=>'2026-10-29T14:00:00Z',
                'parent_max_attempts'=>8,'review_max_attempts'=>4,
                'max_input_tokens'=>4096,'max_output_tokens'=>1024,
                'disclosure_class'=>'NO_BYTES_LOCAL_METADATA',
            ]);
            if(!$request->isOk())return Outcome::refuse('AN02_FIXED_REQUEST_REFUSED');
            $memory=$bridge->value()['candidate_v2'];
            $run=HistoryReviewService::freezeRun(
                $request->value(),$basis->value(),$memory,
            );
            if(!$run->isOk())return Outcome::refuse('AN02_FIXED_RUN_REFUSED');
            $first=HistoryReviewService::next(
                $run->value(),[],'INSPECT_SYNTHETIC',null,
                '2026-10-29T12:00:01Z',
            );
            if(!$first->isOk())return Outcome::refuse('AN02_FIXED_INSPECTION_REFUSED');
            $analyst=HistoryReviewFactory::mockAnalyst(
                $request->value(),$basis->value(),$memory,
            );
            if(!$analyst->isOk())return Outcome::refuse('AN02_FIXED_ANALYST_REFUSED');
            $second=HistoryReviewService::next(
                $run->value(),[$first->value()],'ANALYST',$analyst->value(),
                '2026-10-29T12:00:02Z',
            );
            if(!$second->isOk())return Outcome::refuse('AN02_FIXED_ANALYST_STAGE_REFUSED');
            $critic=HistoryReviewFactory::mockCritic($analyst->value());
            if(!$critic->isOk())return Outcome::refuse('AN02_FIXED_CRITIC_REFUSED');
            $third=HistoryReviewService::next(
                $run->value(),[$first->value(),$second->value()],
                'CRITIC',$critic->value(),'2026-10-29T12:00:03Z',
            );
            if(!$third->isOk())return Outcome::refuse('AN02_FIXED_CRITIC_STAGE_REFUSED');
            return Outcome::ok([
                'version'=>'an02.mock-preview.v1',
                'run_digest'=>$run->value()['run_digest'],
                'request_candidate_digest'=>$request->value()['candidate_digest'],
                'basis_digest'=>$basis->value()['basis_digest'],
                'm17_run_digest'=>$memory['derivation_identity_digest'],
                'event_digests'=>[
                    $first->value()['event_digest'],
                    $second->value()['event_digest'],
                    $third->value()['event_digest'],
                ],
                'final_status'=>$third->value()['state_to'],
                'contrary_ref_count'=>count($analyst->value()['output']['contrary_ref_ids']),
                'source_bytes_exported'=>false,
                'external_model_calls'=>0,
                'human_factual_release_approved'=>false,
                'human_delivery_approved'=>false,
                'customer_release_authorized'=>false,
            ]);
        }catch(\Throwable){
            return Outcome::failure('AN02_FIXED_SYNTHETIC_FIXTURE_UNAVAILABLE');
        }
    }
}
