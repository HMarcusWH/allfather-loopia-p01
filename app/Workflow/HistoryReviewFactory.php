<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\PromptOps\HistoryReviewCandidateChecks;
use Allfather\Loopia\Substrate\M17\P01WindowProfileV2;

/**
 * AN-02 explicit local, code-generated synthetic analyst / challenge mock.
 *
 * No ModelExecutor / ModelRequestBuilder / credential / network is imported.
 * Callers cannot enable production by passing a flag or an asserted grant.
 * The two roles use separate deterministic code paths but NEITHER is a human,
 * independent empirical source, original model candidate, or approval.
 */
final class HistoryReviewFactory
{
    public const VERSION='an02.local-synthetic-role-fixture.v1';
    public const ROLE_BUNDLE='an02.history-review-v1.inactive';

    public static function forRuntime(array $options=[]):Outcome
    {
        return Outcome::refuse('AN02_U03_U06_PROVIDER_AND_HUMAN_RUNTIME_NOT_ADOPTED');
    }

    /** @return Outcome<array<string,mixed>> */
    public static function mockAnalyst(
        array $request,array $basis,array $memory,string $resultKind='HYPOTHESIS_FOR_HUMAN',
    ):Outcome {
        if(!in_array($resultKind,[
            'HYPOTHESIS_FOR_HUMAN','NO_NEW_INSIGHT',
            'INSUFFICIENT_EVIDENCE','ABSTAIN',
        ],true)){
            return Outcome::refuse('AN02_MOCK_ROLE_SCENARIO_INVALID');
        }
        if(!PointInTimeHistoricalBasis::verify($basis)->isOk()
            || !P01WindowProfileV2::verify($memory)->isOk()){
            return Outcome::refuse('AN02_MOCK_SOURCE_BASIS_INVALID');
        }
        $selected=$basis['selected_references'];
        $support=[];$contrary=[];
        foreach($selected as $ref){
            if($ref['semantic_polarity']==='CONTRARY')$contrary[]=$ref['ref_id'];
            elseif($ref['semantic_polarity']==='SUPPORT')$support[]=$ref['ref_id'];
        }
        // The user may supply metadata where every input is contrary.
        // That is not a license to label a contrary reference as support.
        if($resultKind==='HYPOTHESIS_FOR_HUMAN' && $support===[]){
            return Outcome::refuse('AN02_MOCK_HYPOTHESIS_REQUIRES_SUPPORT');
        }
        $statements=[
            'HYPOTHESIS_FOR_HUMAN'=>'DESCRIPTIVE_SHIFT_HYPOTHESIS',
            'NO_NEW_INSIGHT'=>'NO_DISTINCT_PATTERN_DETECTED',
            'INSUFFICIENT_EVIDENCE'=>'SOURCE_BASIS_INCOMPLETE',
            'ABSTAIN'=>'ABSTAIN_FROM_INTERPRETATION',
        ];
        return HistoryReviewCandidateChecks::analyst(
            $request,$basis,$memory,[
                'review_id'=>$request['review_id'],
                'role'=>'HISTORICAL_ANALYST',
                'mode'=>HistoryReviewCandidateChecks::MOCK_MODE,
                'result_kind'=>$resultKind,
                'observed_ref_ids'=>array_column($selected,'ref_id'),
                'support_ref_ids'=>$resultKind==='HYPOTHESIS_FOR_HUMAN'?$support:[],
                'contrary_ref_ids'=>$contrary,
                'alternative_explanations'=>[
                    'ROUTINE_MAINTENANCE','NONCAUSAL_CORRELATION',
                    'UNKNOWN_EXTERNAL_EXPLANATION',
                ],
                'limitation_codes'=>[
                    'CURRENT_SOURCE_USE_UNADMITTED',
                    'ORIGIN_INDEPENDENCE_UNKNOWN',
                    'COVERAGE_ONLY_WITHIN_SUPPLIED_INPUT',
                    'CAUSALITY_NOT_ESTABLISHED',
                ],
                'would_change_assessment'=>[
                    'REQUEST_HUMAN_SOURCE_REVIEW',
                    'RECONCILE_CONTRARY_REFERENCES',
                ],
                'statement_code'=>$statements[$resultKind],
            ],
        );
    }

    /**
     * Model-like critic is a separate challenge processor, not a second
     * independent primary witness nor either of the two human reviewers.
     */
    public static function mockCritic(array $analyst,string $disposition='PASS_TO_HUMAN'):Outcome
    {
        if(!in_array($disposition,[
            'PASS_TO_HUMAN','REVISE','BLOCK',
            'NO_NEW_INSIGHT','INSUFFICIENT_EVIDENCE',
        ],true)){
            return Outcome::refuse('AN02_MOCK_CRITIC_SCENARIO_INVALID');
        }
        if(!HistoryReviewCandidateChecks::verifyAnalyst($analyst)->isOk()){
            return Outcome::refuse('AN02_MOCK_ANALYST_NOT_REPLAYABLE');
        }
        return HistoryReviewCandidateChecks::critic($analyst,[
            'review_id'=>$analyst['review_id'],
            'role'=>'HISTORICAL_CRITIC',
            'mode'=>HistoryReviewCandidateChecks::MOCK_MODE,
            'analyst_digest'=>$analyst['candidate_digest'],
            'disposition'=>$disposition,
            'challenge_ref_ids'=>$analyst['output']['contrary_ref_ids'],
            'unresolved_contrary_ref_ids'=>$analyst['output']['contrary_ref_ids'],
            'concern_codes'=>[
                'CONTRARY_EVIDENCE_UNRESOLVED',
                'PRIMARY_EVIDENCE_UNVERIFIED',
                'ALTERNATIVE_NOT_TESTED',
            ],
            'next_check_code'=>'RECONCILE_CONTRARY_REFERENCES',
        ]);
    }

    /** Local simulation of an UNKNOWN transport report, not a real send. */
    public static function mockUnknownTransport(array $request,string $knownAtUtc):Outcome
    {
        if(!HistoryReviewAdmission::verify($request)->isOk()){
            return Outcome::refuse('AN02_MOCK_REQUEST_NOT_REPLAYABLE');
        }
        return HistoryReviewReconciliation::inspect([
            'request_candidate'=>$request,
            'attempt_receipt'=>[
                'attempt_id'=>$request['attempt_id'],
                'request_digest'=>$request['request_digest'],
                'receipt_kind'=>'TRANSMISSION_OUTCOME_UNKNOWN',
                'transmitted_at_utc'=>$request['requested_at_utc'],
                'receipt_known_at_utc'=>$knownAtUtc,
                'retained_candidate_digest'=>null,
                'usage_disposition'=>'UNKNOWN',
                'current_provider_owner_verified'=>false,
            ],
            'inspected_at_utc'=>$knownAtUtc,
        ]);
    }
}
