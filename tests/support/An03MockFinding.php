<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Workflow\{
    HistoryReviewAdmission,HistoryReviewFactory,HistoryReviewService,
    HistoryM17V2CandidateBridge,PointInTimeHistoricalBasis
};

/**
 * AN-03 fixed synthetic multi-evidence proposed finding over the actual
 * HS-01/AN-01/TM-02/AN-02 algorithms. Deliberately NOT an M05 owner proof.
 */
final class An03MockFinding
{
    public static function inputs(?array $scope=null):Outcome
    {
        try{
            $catalog=json_decode((string)file_get_contents(
                dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
                true,64,JSON_THROW_ON_ERROR,
            );
            $scope ??= $catalog['scope'];
            $rows=$catalog['rows'];
            foreach($rows as &$row)foreach($scope as $key=>$value)$row[$key]=$value;
            unset($row);
            $basis=PointInTimeHistoricalBasis::freeze([
                'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
                'legacy_input'=>[
                    'scope'=>$scope,
                    'period'=>$catalog['period'],
                    'baseline_period'=>$catalog['baseline_period'],
                    'baseline_null_reason'=>null,
                    'knowledge_cutoff_utc'=>$catalog['knowledge_cutoff_utc'],
                    'rows'=>$rows,'max_references'=>8,
                    'coverage'=>[
                        'state'=>'KNOWN',
                        'plan_version_id'=>'an03.synthetic.local',
                        'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
                        'failed'=>0,'skipped'=>0,'unreported'=>0,
                    ],
                ],
                'previous_basis_digest'=>null,
                'review_at_utc'=>'2026-03-15T00:00:00Z',
            ]);
            if(!$basis->isOk())return Outcome::refuse('AN03_FIXTURE_HS01_UNAVAILABLE');
            $bridge=HistoryM17V2CandidateBridge::compose(
                $basis->value(),'SOURCE_DIFF','MONTH',
            );
            if(!$bridge->isOk())return Outcome::refuse('AN03_FIXTURE_M17_UNAVAILABLE');
            $request=HistoryReviewAdmission::freeze([
                'version'=>HistoryReviewAdmission::VERSION,
                'scope'=>$scope,
                'review_id'=>'an03_fixed_synthetic_review',
                'basis_digest'=>$basis->value()['basis_digest'],
                'question_digest'=>hash('sha256','an03.local.question'),
                'prompt_bundle_digest'=>hash('sha256','an03.no_real_model'),
                'model_policy_digest'=>hash('sha256','an03.synthetic.only'),
                'credential_ref_digest'=>hash('sha256','an03.no_credential'),
                'parent_grant_digest'=>hash('sha256','an03.no_grant'),
                'app_fingerprint'=>hash('sha256','an03.test.build'),
                'request_digest'=>hash('sha256','an03.test.request'),
                'attempt_id'=>'an03_fixed_synthetic_attempt',
                'purpose'=>HistoryReviewAdmission::PURPOSE,
                'mode'=>HistoryReviewAdmission::MODE,
                'requested_at_utc'=>'2026-10-29T12:00:00Z',
                'valid_from_utc'=>'2026-10-29T11:00:00Z',
                'valid_until_utc'=>'2026-10-29T14:00:00Z',
                'parent_max_attempts'=>8,
                'review_max_attempts'=>4,
                'max_input_tokens'=>4096,'max_output_tokens'=>1024,
                'disclosure_class'=>'NO_BYTES_LOCAL_METADATA',
            ]);
            if(!$request->isOk())return Outcome::refuse('AN03_FIXTURE_AN01_UNAVAILABLE');
            $run=HistoryReviewService::freezeRun(
                $request->value(),$basis->value(),
                $bridge->value()['candidate_v2'],
            );
            if(!$run->isOk())return Outcome::refuse('AN03_FIXTURE_AN02_RUN_UNAVAILABLE');
            $first=HistoryReviewService::next(
                $run->value(),[],'INSPECT_SYNTHETIC',null,'2026-10-29T12:00:01Z',
            );
            $analyst=HistoryReviewFactory::mockAnalyst(
                $request->value(),$basis->value(),$bridge->value()['candidate_v2'],
            );
            if(!$first->isOk()||!$analyst->isOk()){
                return Outcome::refuse('AN03_FIXTURE_ANALYST_UNAVAILABLE');
            }
            $second=HistoryReviewService::next(
                $run->value(),[$first->value()],'ANALYST',
                $analyst->value(),'2026-10-29T12:00:02Z',
            );
            if(!$second->isOk())return Outcome::refuse('AN03_FIXTURE_REVIEW_EVENT_MISSING');
            $critic=HistoryReviewFactory::mockCritic($analyst->value());
            if(!$critic->isOk())return Outcome::refuse('AN03_FIXTURE_CRITIC_UNAVAILABLE');
            $third=HistoryReviewService::next(
                $run->value(),[$first->value(),$second->value()],
                'CRITIC',$critic->value(),'2026-10-29T12:00:03Z',
            );
            if(!$third->isOk())return Outcome::refuse('AN03_FIXTURE_CRITIC_EVENT_MISSING');
            return Outcome::ok([
                'basis'=>$basis->value(),
                'review_run'=>$run->value(),
                'review_events'=>[$first->value(),$second->value(),$third->value()],
                'analyst'=>$analyst->value(),'critic'=>$critic->value(),
            ]);
        }catch(\Throwable){
            return Outcome::failure('AN03_FIXED_LOCAL_FIXTURE_FAILED');
        }
    }
}
