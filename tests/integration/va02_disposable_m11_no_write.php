<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';

use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Substrate\M17\Assurance as M17Assurance;
use Allfather\Loopia\Tests\{Assert,Harness,Seed,Aq02MockAttention,Aq02FixtureAttentionOwner};
use Allfather\Loopia\Workflow\HistoryReviewAssurance;

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$clock=Harness::clock('2026-10-30T12:10:00.000Z');
$scope=Seed::tenantWorkspace($db,$clock,'va02-owner');
if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST'))define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
$baseline=[];
foreach(['audit_runs','audit_findings','incident_cases',
         'decision_records','release_artifacts'] as $table){
    $baseline[$table]=(int)$db->scalar('SELECT COUNT(*) FROM '.$table);
}
$domain=[
    'tenant_id'=>$scope['tenant_id'],
    'workspace_id'=>$scope['workspace_id'],
    'competitor_id'=>\Allfather\Loopia\Core\Ids::new(),
    'question_id'=>'va02_fixture_question',
];
$input=Aq02MockAttention::input($domain);
Assert::true($input->isOk(),'scoped source fixture prepared without native source use');
if(!$input->isOk())return;
$attention=InternalAttention::composeFixture(
    $input->value(),new Aq02FixtureAttentionOwner());
Assert::true($attention->isOk(),'fixed metadata reader composes');
if(!$attention->isOk())return;
$observation=M17Assurance::inspect(
    $input->value()['native_release_proposal'],
    $input->value()['outcome_annotation'],
    $attention->value(),'2026-10-30T12:00:00Z',
);
Assert::true($observation->isOk(),'M17 metadata inspection succeeds');
if(!$observation->isOk())return;
$report=HistoryReviewAssurance::compose([
    'observation'=>$observation->value(),'queue'=>null,
    'coverage'=>[
        'status'=>'NOT_VERIFIED','planned_slots'=>null,
        'reported_slots'=>null,'oldest_missing_at_utc'=>null,
    ],
    'cost'=>[
        'status'=>'UNKNOWN_PROVIDER_OUTCOME','reserved_units'=>null,
        'spent_units'=>null,'unknown_attempts'=>null,
    ],
    'recovery'=>[
        'status'=>'NOT_VERIFIED_HOST','private_bytes'=>'NOT_VERIFIED',
        'credential_rotation'=>'NOT_VERIFIED','source_image'=>'NOT_VERIFIED',
    ],
    'at_utc'=>'2026-10-30T12:00:00Z',
]);
Assert::true($report->isOk(),'candidate report produced on a disposable database host');
if($report->isOk()){
    Assert::same(false,$report->value()['actual_m11_incident_written'],
        'source-only M11 report cannot open real incidents');
    Assert::same(null,$report->value()['provider_spent_units'],
        'disposable MariaDB has no provider-spending proof');
}
foreach($baseline as $table=>$count){
    Assert::same($count,(int)$db->scalar('SELECT COUNT(*) FROM '.$table),
        'metric observation did not write actual M11/M10/M16: '.$table);
}
