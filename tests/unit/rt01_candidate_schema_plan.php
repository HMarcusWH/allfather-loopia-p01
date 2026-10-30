<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';

use Allfather\Loopia\Ops\{
    BuildFirstRegistry,HistorySchemaPlan,RuntimeInvariantProbe,
};
use Allfather\Loopia\Workflow\HistoryReviewFactory;
use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Tests\{Assert,Aq02MockAttention,Aq02FixtureAttentionOwner};

$root=dirname(__DIR__,2);
$plan=HistorySchemaPlan::inspect($root);
Assert::true($plan->isOk(),'RT01 source SQL and immutable registry reconcile: '.($plan->isOk()?'OK':$plan->reasonCode()));
if(!$plan->isOk())return;
$p=$plan->value();
Assert::true(HistorySchemaPlan::verify($root,$p)->isOk(),
    'schema manifest reconstructs from source and immutable 900');
Assert::same(12,$p['family_count'],'all twelve frozen schema families present');
Assert::same(38,$p['table_count'],'38 physical candidate tables across 12 SQL families');
Assert::same(76,$p['trigger_count'],'76 append-only candidate triggers');
Assert::same(false,$p['legacy_900_modified_or_rerun'],
    'older 900 migration source is protected, never replayed');
Assert::same(false,$p['live_schema_installed'],
    'candidate SQL under docs is not a runtime migration');
Assert::same([
    '260','300','310','330','340','350',
],$p['provisional_registry_differences'],
    'all provisional registry names not matching actual SQL are explicit');
foreach([
    ['260','265'],['270','290'],['330','345'],
] as [$prior,$after]){
    $locBefore=array_search($prior,array_map('strval',$p['install_order']),true);
    $locAfter=array_search($after,array_map('strval',$p['install_order']),true);
    Assert::true($locBefore!==false&&$locAfter!==false&&$locBefore<$locAfter,
        'FK topological family dependency '.$prior.' before '.$after);
}
$again=HistorySchemaPlan::inspect($root);
Assert::same($p['plan_digest'],$again->value()['plan_digest'],
    'source plan identity deterministic');
$modified=$p;$modified['live_schema_installed']=true;
Assert::same('RT01_PHYSICAL_SCHEMA_PLAN_STALE_OR_FORGED',
    HistorySchemaPlan::verify($root,$modified)->reasonCode(),
    'a caller cannot rehash the plan into a live migration');
$modified=$p;$modified['families'][0]['source_sha256']=str_repeat('0',64);
Assert::same('RT01_PHYSICAL_SCHEMA_PLAN_STALE_OR_FORGED',
    HistorySchemaPlan::verify($root,$modified)->reasonCode(),
    'source SQL digest drift refuses');
$status=RuntimeInvariantProbe::candidateSchemaStatus($root);
Assert::true($status->isOk(),'existing runtime invariant owner exposes inert schema state');
if($status->isOk()){
    Assert::same(false,$status->value()['candidate_tables_installed_on_runtime'],
        'source index cannot assert actual installed database schema');
    Assert::same(false,$status->value()['provider_source_scheduler_enabled'],
        'source plan cannot admit model or scheduler');
}
if(!defined('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST')){
    define('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST',true);
}
if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST')){
    define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
}
$f=Aq02MockAttention::input();
Assert::true($f->isOk(),'source-only AN03/AN04/AQ02 fixture exists');
if(!$f->isOk())return;
$attention=InternalAttention::composeFixture(
    $f->value(),new Aq02FixtureAttentionOwner());
Assert::true($attention->isOk(),'fixed P04 owner metadata fixture');
if(!$attention->isOk())return;
$composed=HistoryReviewFactory::composeFullCandidate($root,[
    'schema_plan'=>$p,
    'historical_finding'=>$f->value()['native_release_proposal']['historical_finding'],
    'native_release_proposal'=>$f->value()['native_release_proposal'],
    'outcome_annotation'=>$f->value()['outcome_annotation'],
    'internal_attention'=>$attention->value(),
]);
Assert::true($composed->isOk(),'full source graph binds owner-typed fixture contracts');
if(!$composed->isOk())return;
Assert::same('COMPLETE_SYNTHETIC_SOURCE_GRAPH_NO_RUNTIME_PERMISSION',
    $composed->value()['status'],'positive fixture is not runtime GO');
Assert::same(14,$composed->value()['source_module_count'],
    '14 typed owner module boundaries compiled');
Assert::same(false,$composed->value()['native_m10_record_written'],
    'composed source graph cannot write customer release');
Assert::same(10,count($composed->value()['owner_gate_states']),
    'U01-U10 all remain not adopted');
Assert::same('AN02_U03_U06_PROVIDER_AND_HUMAN_RUNTIME_NOT_ADOPTED',
    HistoryReviewFactory::forRuntime(['activate'=>true])->reasonCode(),
    'caller cannot activate real inference via a runtime flag');
