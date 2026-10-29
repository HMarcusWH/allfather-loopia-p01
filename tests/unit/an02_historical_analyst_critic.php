<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\PromptOps\HistoryReviewCandidateChecks;
use Allfather\Loopia\Workflow\{
    HistoryReviewAdmission,HistoryReviewFactory,
    HistoryReviewService,HistoryReviewReconciliation,
    HistoryM17V2CandidateBridge,PointInTimeHistoricalBasis
};

$fixture=json_decode((string)file_get_contents(
    dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$input=[
    'scope'=>$fixture['scope'],
    'period'=>$fixture['period'],'baseline_period'=>$fixture['baseline_period'],
    'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$fixture['knowledge_cutoff_utc'],
    'rows'=>$fixture['rows'],'max_references'=>8,
    'coverage'=>[
        'state'=>'KNOWN','plan_version_id'=>'an02.synthetic',
        'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
        'failed'=>0,'skipped'=>0,'unreported'=>0,
    ],
];
$basis=PointInTimeHistoricalBasis::freeze([
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$input,'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-15T00:00:00Z',
]);
Assert::true($basis->isOk(),'replayable known-cutoff historical basis is a synthetic input');
if(!$basis->isOk())return;
$bridge=HistoryM17V2CandidateBridge::compose($basis->value(),'SOURCE_DIFF','MONTH');
Assert::true($bridge->isOk(),'M17 v2 memory is derived only from frozen primary ref metadata');
if(!$bridge->isOk())return;
$memory=$bridge->value()['candidate_v2'];
$raw=[
    'version'=>HistoryReviewAdmission::VERSION,'scope'=>$input['scope'],
    'review_id'=>'an02_mock_review_01',
    'basis_digest'=>$basis->value()['basis_digest'],
    'question_digest'=>hash('sha256','synthetic-question'),
    'prompt_bundle_digest'=>hash('sha256','history-review-v1-fixture'),
    'model_policy_digest'=>hash('sha256','no-model-fixture'),
    'credential_ref_digest'=>hash('sha256','NO_REAL_CREDENTIAL'),
    'parent_grant_digest'=>hash('sha256','NOT_REAL_GRANT'),
    'app_fingerprint'=>hash('sha256','AN02_LOCAL_MOCK'),
    'request_digest'=>hash('sha256','AN02_LOCAL_INSPECTED_REQUEST'),
    'attempt_id'=>'an02_mock_attempt_01',
    'purpose'=>HistoryReviewAdmission::PURPOSE,
    'mode'=>HistoryReviewAdmission::MODE,
    'requested_at_utc'=>'2026-10-29T12:00:00Z',
    'valid_from_utc'=>'2026-10-29T11:00:00Z',
    'valid_until_utc'=>'2026-10-29T14:00:00Z',
    'parent_max_attempts'=>8,'review_max_attempts'=>4,
    'max_input_tokens'=>4096,'max_output_tokens'=>1024,
    'disclosure_class'=>'NO_BYTES_LOCAL_METADATA',
];
$request=HistoryReviewAdmission::freeze($raw);
Assert::true($request->isOk(),'AN01 exact source-closed request survives into AN02');
if(!$request->isOk())return;
$run=HistoryReviewService::freezeRun($request->value(),$basis->value(),$memory);
Assert::true($run->isOk(),'AN02 run begins in separate request-inspection state');
if(!$run->isOk())return;
$run=$run->value();
Assert::same('REQUEST_INSPECTION_REQUIRED',$run['state'],
    'no real provider call before inspected request');
Assert::same(false,$run['model_attempts_authorized'],
    'AN01 metadata request cannot become paid authorization');
Assert::same(false,$run['human_delivery_approved'],
    'human routing boundary is distinct from factual product review');
Assert::true(HistoryReviewService::verifyRun($run)->isOk(),
    'exact run identity verifies without pretending M05 owner authenticity');

$stage1=HistoryReviewService::next(
    $run,[],'INSPECT_SYNTHETIC',null,'2026-10-29T12:00:01Z');
Assert::true($stage1->isOk(),'explicit synthetic inspect has no U06 admission');
if(!$stage1->isOk())return;
Assert::same('ANALYST_READY',$stage1->value()['state_to'],
    'readied synthetic role is not a live provider dispatch');
Assert::same(false,$stage1->value()['external_provider_dispatched'],
    'no external model attempted');
$analyst=HistoryReviewFactory::mockAnalyst(
    $request->value(),$basis->value(),$memory);
Assert::true($analyst->isOk(),'local source-only analyst role composes cited hypothesis');
if(!$analyst->isOk())return;
Assert::same(['ref_b'],$analyst->value()['output']['contrary_ref_ids'],
    'contrary historical source cannot disappear');
Assert::same(['ref_a'],$analyst->value()['output']['support_ref_ids'],
    'only selected supporting primary source metadata can support proposal');
Assert::same(false,$analyst->value()['primary_evidence_text_checked'],
    'a citation is not proof a claim follows from raw source text');
Assert::same('HISTORICAL_ANALYST',$analyst->value()['role'],
    'analyst role is type distinct from human/critic');
$stage2=HistoryReviewService::next(
    $run,[$stage1->value()],'ANALYST',$analyst->value(),
    '2026-10-29T12:00:02Z');
Assert::true($stage2->isOk(),'analyst candidate transition has an immutable digest');
if(!$stage2->isOk())return;
Assert::same('CRITIC_REQUIRED',$stage2->value()['state_to'],
    'analyst does not approve itself');
$critic=HistoryReviewFactory::mockCritic($analyst->value());
Assert::true($critic->isOk(),'separate fixture critic challenges cited alternatives');
if(!$critic->isOk())return;
Assert::same(false,$critic->value()['model_critic_is_human'],
    'second LLM-like reviewer is never human review');
$events=[$stage1->value(),$stage2->value()];
$stage3=HistoryReviewService::next(
    $run,$events,'CRITIC',$critic->value(),'2026-10-29T12:00:03Z');
Assert::true($stage3->isOk(),'critic challenge retained for human review');
if(!$stage3->isOk())return;
Assert::same('HUMAN_REVIEW_REQUIRED',$stage3->value()['state_to'],
    'critic approval is a human-HOLD, not product approval');
Assert::same(false,$stage3->value()['human_factual_release_approved'],
    'first human release boundary never auto-crossed');
Assert::same(false,$stage3->value()['human_delivery_approved'],
    'second independent delivery/routing approval never auto-crossed');
Assert::same('AN02_TERMINAL_REVIEW_HOLD_NOT_ADVANCEABLE',
    HistoryReviewService::next($run,[$stage1->value(),$stage2->value(),
        $stage3->value()],'INSPECT_SYNTHETIC',null,'2026-10-29T12:00:04Z')->reasonCode(),
    'cannot turn a human HOLD into automatic COMPLETE');
Assert::same('AN02_STAGE_OUT_OF_ORDER_OR_ARTIFACT_NOT_REPLAYABLE',
    HistoryReviewService::next($run,[$stage1->value()],
        'CRITIC',$critic->value(),'2026-10-29T12:00:02Z')->reasonCode(),
    'critic cannot skip analyst retention');

foreach(['REVISE'=>'REVISE_HOLD','BLOCK'=>'BLOCK_HOLD'] as $code=>$expected){
    $challenge=HistoryReviewFactory::mockCritic($analyst->value(),$code);
    Assert::true($challenge->isOk(),'synthetic critic '.$code.' is a valid hold candidate');
    $out=HistoryReviewService::next($run,$events,'CRITIC',
        $challenge->value(),'2026-10-29T12:00:03Z');
    Assert::same($expected,$out->value()['state_to'],
        'critical critic resolution never yields product release');
}
$noNew=HistoryReviewFactory::mockAnalyst(
    $request->value(),$basis->value(),$memory,'NO_NEW_INSIGHT');
Assert::true($noNew->isOk(),'quiet history may correctly propose no new insight');
$noEvent=HistoryReviewService::next(
    $run,[$stage1->value()],'ANALYST',
    $noNew->value(),'2026-10-29T12:00:02Z');
$noCritic=HistoryReviewFactory::mockCritic($noNew->value(),'NO_NEW_INSIGHT');
Assert::true($noCritic->isOk(),'critic can reject a manufactured insight');
Assert::same('NO_NEW_INSIGHT',HistoryReviewService::next(
    $run,[$stage1->value(),$noEvent->value()],
    'CRITIC',$noCritic->value(),'2026-10-29T12:00:03Z')->value()['state_to'],
    'NO_NEW_INSIGHT is a legitimate terminal review result');
$unknown=HistoryReviewFactory::mockUnknownTransport(
    $request->value(),'2026-10-29T12:03:00Z');
Assert::true($unknown->isOk(),'unknown worker/provider transport has a mocked metadata report');
if($unknown->isOk()){
    Assert::same('UNKNOWN_OUTCOME_NO_REDISPATCH_HOLD',
        $unknown->value()['status'],'unknown provider use cannot be retried/refunded');
    $event=HistoryReviewService::next(
        $run,[$stage1->value()],'UNKNOWN_OUTCOME',
        $unknown->value(),'2026-10-29T12:03:00Z');
    Assert::same('UNKNOWN_OUTCOME_HOLD',$event->value()['state_to'],
        'unknown result is held before another model attempt');
}

$bad=$analyst->value();
$bad['output']['support_ref_ids']=['invented_evidence'];
$unsigned=$bad;unset($unsigned['candidate_digest']);
$bad['candidate_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN02_ANALYST_CANDIDATE_NOT_REPLAYABLE',
    HistoryReviewCandidateChecks::verifyAnalyst(array_merge($bad,[
        'human_factual_release_approved'=>true,
    ]))->reasonCode(),
    'a rehashed self-approval never authorizes factual release');
$badCritic=$critic->value();$badCritic['model_critic_is_human']=true;
$unsigned=$badCritic;unset($unsigned['candidate_digest']);
$badCritic['candidate_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AN02_CRITIC_CANDIDATE_NOT_REPLAYABLE',
    HistoryReviewCandidateChecks::verifyCritic($badCritic)->reasonCode(),
    'critic role cannot impersonate either human review boundary');

$incompleteInput=$input;
$incompleteInput['coverage']['state']='KNOWN';
$incompleteInput['coverage']['planned_slots']=3;
$incompleteInput['coverage']['failed']=1;
$incomplete=PointInTimeHistoricalBasis::freeze([
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$incompleteInput,
    'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-15T00:00:00Z',
]);
Assert::true($incomplete->isOk(),'incomplete source coverage remains a valid HOLD metadata input');
if($incomplete->isOk()){
    $requestBad=$raw;$requestBad['basis_digest']=$incomplete->value()['basis_digest'];
    $req=HistoryReviewAdmission::freeze($requestBad);
    Assert::true($req->isOk(),'AN01 inspected request may name an incomplete basis');
    if($req->isOk()){
        $held=HistoryReviewService::freezeRun($req->value(),$incomplete->value(),null);
        Assert::true($held->isOk(),'insufficient evidence produces a bounded run HOLD');
        if($held->isOk()){
            Assert::same('INSUFFICIENT_EVIDENCE_HOLD',$held->value()['state'],
                'no empty or gapped basis can trigger analyst processing');
            Assert::same(null,$held->value()['m17_run_digest'],
                'incomplete history never invents an M17 run');
            Assert::same('AN02_TERMINAL_REVIEW_HOLD_NOT_ADVANCEABLE',
                HistoryReviewService::next($held->value(),[],
                    'INSPECT_SYNTHETIC',null,'2026-10-29T12:00:01Z')->reasonCode(),
                'insufficient evidence cannot be overridden by a mock');
        }
    }
}
Assert::same('AN02_U03_U06_PROVIDER_AND_HUMAN_RUNTIME_NOT_ADOPTED',
    HistoryReviewFactory::forRuntime(['caller_claims_owner_signed'=>true])->reasonCode(),
    'real mode cannot activate with a caller-provided flag');
