<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/LongitudinalBuildHarness.php';
require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';
require_once dirname(__DIR__).'/support/Va03MockRecovery.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Ops\{
    BuildFreezeInspector,HistorySchemaPlan,HistoryRecoveryManifest,
    HistoryRecoveryPreflight,
};
use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M17\Assurance as M17Assurance;
use Allfather\Loopia\Tests\{
    Assert,Harness,Seed,Aq02MockAttention,Aq02FixtureAttentionOwner,
    LongitudinalBuildHarness,Va03MockRecovery,
};
use Allfather\Loopia\Workflow\{
    HistoryReviewFactory,HistoryReviewRelease,HistoryReviewAssurance,
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$freeze=BuildFreezeInspector::inspect($root);
Assert::true($freeze->isOk(),'29-package source inventory can be recomposed on real disposable MariaDB');
if(!$freeze->isOk())return;
Assert::same(29,$freeze->value()['package_count'],'no package is missing from freeze');

$clock=Harness::clock('2026-10-30T12:10:00.000Z');
$owner=Seed::tenantWorkspace($db,$clock,'rt02-owner');
$foreign=Seed::tenantWorkspace($db,$clock,'rt02-foreign');
$guard=new AccessGuard($db,$clock);
$deny=$guard->evaluate('OPERATOR',$owner['operator_id'],
    $owner['tenant_id'],$owner['workspace_id'],
    'evidence.read','EVIDENCE',null,null,true);
Assert::same('DENY',$deny['decision'],
    'real M02 denies synthetic operator without membership');
$role=Seed::role($db,$clock,'rt02-evidence-reader',['evidence.read']);
Seed::membership($db,$clock,$owner['tenant_id'],$owner['workspace_id'],
    $owner['operator_id'],$role['role_id']);
$allow=$guard->evaluate('OPERATOR',$owner['operator_id'],
    $owner['tenant_id'],$owner['workspace_id'],
    'evidence.read','EVIDENCE',null,null,true);
Assert::same('ALLOW',$allow['decision'],
    'real M02 explicitly allows owned TEST role for evidence read');
$other=$guard->evaluate('OPERATOR',$owner['operator_id'],
    $foreign['tenant_id'],$foreign['workspace_id'],
    'evidence.read','EVIDENCE',null,null,true);
Assert::same('DENY',$other['decision'],
    'M02 cannot borrow a sibling tenant grant');

$baseline=[];
foreach([
    'source_snapshots','monitor_runs','monitor_execution_grants',
    'decision_records','release_artifacts','role_distribution_briefings',
    'customer_output_projections','provider_credentials',
] as $table){
    $baseline[$table]=(int)$db->scalar('SELECT COUNT(*) FROM '.$table);
}

$transport=LongitudinalBuildHarness::forCase('allowed');
Assert::same('FETCHED',$transport->observe()['outcome'],
    'real EgressGuard permits one synthetic bounded source route');
Assert::same(['https://bf01.example/pricing'],$transport->attemptedUrls(),
    'no outside request from fixed source transport');
Assert::same(false,$transport->modelPosture()['is_live'],
    'model transport cannot become live by admission');
Assert::same(0,$transport->modelPosture()['calls'],
    'no provider attempt in source-only end to end');
$redirect=LongitudinalBuildHarness::forCase('redirect_refused');
Assert::same('REFUSED',$redirect->observe()['outcome'],
    'out-of-scope source redirect refuses before follow-up');

if(!defined('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST')){
    define('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST',true);
}
if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST')){
    define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
}
$scope=[
    'tenant_id'=>$owner['tenant_id'],
    'workspace_id'=>$owner['workspace_id'],
    'competitor_id'=>Ids::new(),
    'question_id'=>Ids::new(),
];
$input=Aq02MockAttention::input($scope);
Assert::true($input->isOk(),'point-in-time and later outcome fixture has exact scope');
if(!$input->isOk())return;
$attention=InternalAttention::composeFixture(
    $input->value(),new Aq02FixtureAttentionOwner());
Assert::true($attention->isOk(),'internal attention fixture is non-dispatching');
if(!$attention->isOk())return;
$plan=HistorySchemaPlan::inspect($root);
Assert::true($plan->isOk(),'full twelve-family physical source schema available');
if(!$plan->isOk())return;
$composed=HistoryReviewFactory::composeFullCandidate($root,[
    'schema_plan'=>$plan->value(),
    'historical_finding'=>$input->value()['native_release_proposal']['historical_finding'],
    'native_release_proposal'=>$input->value()['native_release_proposal'],
    'outcome_annotation'=>$input->value()['outcome_annotation'],
    'internal_attention'=>$attention->value(),
]);
Assert::true($composed->isOk(),'real typed source owners compose in CLI synthetic mode');
if(!$composed->isOk())return;
Assert::same('COMPLETE_SYNTHETIC_SOURCE_GRAPH_NO_RUNTIME_PERMISSION',
    $composed->value()['status'],'source graph cannot grant runtime');
Assert::same(false,$composed->value()['provider_executed'],
    'no model or provider call in positive composition');

$m17=M17Assurance::inspect(
    $input->value()['native_release_proposal'],
    $input->value()['outcome_annotation'],
    $attention->value(),'2026-10-30T12:00:00Z');
Assert::true($m17->isOk(),'source lineage is verifiable without real M05 permission');
if(!$m17->isOk())return;
$scorecard=HistoryReviewAssurance::compose([
    'observation'=>$m17->value(),'queue'=>null,
    'cost'=>[
        'status'=>'UNKNOWN_PROVIDER_OUTCOME','reserved_units'=>null,
        'spent_units'=>null,'unknown_attempts'=>null,
    ],
    'coverage'=>[
        'status'=>'NOT_VERIFIED','planned_slots'=>null,
        'reported_slots'=>null,'oldest_missing_at_utc'=>null,
    ],
    'recovery'=>[
        'status'=>'NOT_VERIFIED_HOST','private_bytes'=>'NOT_VERIFIED',
        'credential_rotation'=>'NOT_VERIFIED','source_image'=>'NOT_VERIFIED',
    ],
    'at_utc'=>'2026-10-30T12:00:00Z',
]);
Assert::true($scorecard->isOk(),'assurance emits explicit missing proof without M11 case write');
if($scorecard->isOk()){
    Assert::same(null,$scorecard->value()['provider_spent_units'],
        'unknown paid model cost cannot become zero');
    Assert::same(false,$scorecard->value()['actual_m11_incident_written'],
        'M11 incident owner not exercised');
}
$recovery=Va03MockRecovery::manifest($root,$scope);
Assert::true($recovery->isOk(),
    'full candidate recovery manifest lists private artifact identities');
if($recovery->isOk()){
    Assert::true(HistoryRecoveryManifest::verify($recovery->value())->isOk(),
        'candidate recovery is replayable without actual host restore');
    Assert::same(false,$recovery->value()['physical_independent_restore_proved'],
        'source metadata is not an independent physical restore');
}

Assert::false(HistoryReviewFactory::forRuntime()->isOk(),
    'runtime historical model factory remains off');
Assert::false(HistoryReviewRelease::release(
    $input->value()['native_release_proposal']['historical_finding'])->isOk(),
    'an AN03 finding is not a native M10 release');
Assert::false(HistoryRecoveryPreflight::requestRestore()->isOk(),
    'candidate recovery cannot invoke host restore');
foreach($baseline as $table=>$count){
    Assert::same($count,(int)$db->scalar('SELECT COUNT(*) FROM '.$table),
        'composed synthetic path wrote no real owner rows: '.$table);
}
