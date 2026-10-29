<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{PointInTimeHistoricalBasis,HistoryM17V2CandidateBridge};
use Allfather\Loopia\Substrate\M17\{P01WindowProfileV2,WindowEngine};

$fixture=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$input=[
    'scope'=>$fixture['scope'],'period'=>$fixture['period'],
    'baseline_period'=>$fixture['baseline_period'],'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$fixture['knowledge_cutoff_utc'],
    'rows'=>$fixture['rows'],'max_references'=>8,
    'coverage'=>['state'=>'KNOWN','plan_version_id'=>'synthetic.complete.march',
        'planned_slots'=>4,'captured'=>4,'unchanged'=>2,
        'failed'=>0,'skipped'=>0,'unreported'=>0],
];
$request=[
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$input,
    'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-16T00:00:00Z',
];
$basis=PointInTimeHistoricalBasis::freeze($request);
Assert::true($basis->isOk(),'HS01 metadata basis freezes for a complete supplied fixture');
if(!$basis->isOk())return;
Assert::same('COMPLETE_WITHIN_INPUT',$basis->value()['selection_integrity'],
    'complete means within caller-supplied metadata, never rights');
$first=HistoryM17V2CandidateBridge::compose($basis->value(),'SOURCE_DIFF','MONTH');
Assert::true($first->isOk(),'TM01 M17-P01 v2 bridge produces deterministic candidate');
if(!$first->isOk())return;
$bridge=$first->value();
$candidate=$bridge['candidate_v2'];
Assert::true(HistoryM17V2CandidateBridge::verify($bridge)->isOk(),'whole composition replay hash verifies');
Assert::true(P01WindowProfileV2::verify($candidate)->isOk(),'typed new v2 candidate verifies self-contained shape/hash');
Assert::same('P01_COMPETITOR',$candidate['run_identity']['subject_kind'],
    'subject kind is P01, never a forged P02 twin');
Assert::same($input['scope']['competitor_id'],$candidate['run_identity']['subject_id'],
    'exact P01 subject identity used');
Assert::same($input['scope']['question_id'],$candidate['run_identity']['question_id'],
    'question dimension remains independent of competitor identity');
Assert::same('UTC_FIXED_SECONDS_V1',$candidate['run_identity']['calendar_version'],
    'new time windows restricted to verified stable UTC fallback');
Assert::same('COMPRESSION_RUN_ID_MEMORY_CLASS_OUTPUT_ORDINAL',
    $candidate['memory_output_identity']['required_unique_key'],
    'U04-A output uniqueness is run bound, not period bound');
Assert::same(null,$candidate['memory_output_identity']['compression_run_id'],
    'unpersisted run has no fabricated identity');
Assert::same(2,$candidate['selected_reference_count'],
    'both March references retained');
Assert::same(1,$candidate['contrary_reference_count'],
    'one contradictory reference not silently averaged away');
Assert::same(false,$candidate['source_authority_verified'],
    'self-hashed metadata not M05 source authentication');
Assert::same(false,$candidate['historical_reuse_admitted'],
    'no U03 reuse/adoption');
Assert::same(false,$candidate['model_assisted_compression_authorized'],
    'no LLM or provider');
Assert::same('NOT_PERFORMED_AMBIGUOUS_HEAD_MUST_HOLD',$candidate['head_resolution'],
    'no latest-write-wins head selection');
Assert::same($basis->value()['basis_digest'],$bridge['source_basis_digest'],
    'new bridge binds exact historical cutoff basis');
Assert::same($bridge['m17_v2_run_digest'],$candidate['derivation_identity_digest'],
    'derivation identity matches outer bridge');
Assert::same($candidate['v1_rollup_digest'],$bridge['v1_rollup_digest'],
    'existing deterministic M17 rollup remains intact');

$repeat=HistoryM17V2CandidateBridge::compose($basis->value(),'SOURCE_DIFF','MONTH');
Assert::same($bridge['bridge_digest'],$repeat->value()['bridge_digest'],
    'same frozen inputs produce byte-identical candidate');
Assert::same($candidate['derivation_identity_digest'],$repeat->value()['m17_v2_run_digest'],
    'same run and version imply idempotent digest');
$otherClass=HistoryM17V2CandidateBridge::compose($basis->value(),'COMPANY_SIGNAL','MONTH');
Assert::true($otherClass->isOk(),'v2 closed class namespace is typed');
Assert::notSame($candidate['derivation_identity_digest'],
    $otherClass->value()['m17_v2_run_digest'],
    'changing data class cannot reuse SOURCE_DIFF run');
$wrongPeriod=HistoryM17V2CandidateBridge::compose($basis->value(),'SOURCE_DIFF','DAY');
Assert::same('TM01_WINDOW_PERIOD_MISMATCH',$wrongPeriod->reasonCode(),
    'month period cannot be repackaged as a day that drops refs');

$late=$request;
$late['legacy_input']['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
$late['previous_basis_digest']=$basis->value()['basis_digest'];
$late['review_at_utc']='2026-03-21T00:00:00Z';
$successor=PointInTimeHistoricalBasis::freeze($late);
Assert::true($successor->isOk(),'newly known historical observation makes a new immutable basis');
$second=HistoryM17V2CandidateBridge::compose($successor->value(),'SOURCE_DIFF','MONTH');
Assert::true($second->isOk(),'same-period input-specific M17 successor prepared');
if($second->isOk()){
    Assert::same(3,$second->value()['lineage_count'],'late-known contrary input retained');
    Assert::notSame($candidate['derivation_identity_digest'],
        $second->value()['m17_v2_run_digest'],
        'new input digest creates a different run, same period and engine');
    Assert::same($bridge['frozen_m17_inputs']['profile_candidate']['resolution_digest'],
        $second->value()['frozen_m17_inputs']['profile_candidate']['resolution_digest'],
        'same period candidate freezes the same profile, not an arbitrary new clock');
    Assert::same($basis->value()['basis_digest'],$second->value()['source_basis_predecessor_digest'],
        'source ancestry explicit, old digest unchanged');
    Assert::same('NOT_PERFORMED_AMBIGUOUS_HEAD_MUST_HOLD',
        $second->value()['candidate_v2']['head_resolution'],
        'no automatic current-head promotion');
}
Assert::same('TM01_U03_U04_AND_LIVE_M17_OWNER_ADMISSION_PENDING',
    HistoryM17V2CandidateBridge::activate($bridge)->reasonCode(),
    'even valid candidate cannot activate M17');
$broken=$request;$broken['legacy_input']['coverage']=['state'=>'UNKNOWN'];
$incomplete=PointInTimeHistoricalBasis::freeze($broken);
Assert::same('TM01_HS01_BASIS_NOT_ELIGIBLE_FOR_OFFLINE_DERIVATION',
    HistoryM17V2CandidateBridge::compose($incomplete->value(),'SOURCE_DIFF','MONTH')->reasonCode(),
    'gapped basis not made complete by a summary');

// Original v1 WindowEngine accepts caller-declared Stockholm; the newer v2
// U04 creation boundary must NOT silently inherit that insufficient proof.
$localWindow=WindowEngine::assign([
    'kind'=>'MONTH','observation_at_utc'=>'2026-03-03T12:00:00Z',
    'calendar_config'=>['timezone'=>'Europe/Stockholm',
        'tzdb_version'=>timezone_version_get()],
]);
Assert::true($localWindow->isOk(),'legacy v1 window remains available unchanged');
$nonUtc=$bridge['frozen_m17_inputs'];
$nonUtc['window']=$localWindow->value();
Assert::same('TM01_P01_SCOPE_CALENDAR_PROFILE_NOT_SUPPORTED',
    P01WindowProfileV2::freeze($nonUtc)->reasonCode(),
    'new governed v2 creation refuses non-M13 timezone');
$malformed=$candidate;$malformed['profile_authority_adopted']=true;
$unsigned=$malformed;unset($unsigned['candidate_digest']);
$malformed['candidate_digest']=Digest::canonicalDigest($unsigned);
Assert::same('TM01_V2_AUTHORITY_FORGERY',P01WindowProfileV2::verify($malformed)->reasonCode(),
    'a recomputed digest cannot approve an M17 profile');
