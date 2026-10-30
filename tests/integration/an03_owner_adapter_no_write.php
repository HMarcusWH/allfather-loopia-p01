<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/An03FixtureReleaseOwner.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Tests\{Assert,An03MockFinding,An03FixtureReleaseOwner,Harness,Seed};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Workflow\{HistoryReviewRelease,NativeReleaseProposal};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$clock=Harness::clock('2026-10-29T12:06:00.000Z');
$owner=Seed::tenantWorkspace($db,$clock,'an03-release-fixture-owner');
$foreign=Seed::tenantWorkspace($db,$clock,'an03-release-fixture-foreign');
$scope=[
    'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
    'competitor_id'=>Ids::new(),'question_id'=>Ids::new(),
];
$input=An03MockFinding::inputs($scope);
Assert::true($input->isOk(),'scoped multi-evidence fixture for native owner adapter');
if(!$input->isOk())return;
$f=HistoricalFindingAdapter::propose($input->value());
Assert::true($f->isOk(),'historical finding lineage is complete');
if(!$f->isOk())return;
$p=NativeReleaseProposal::fromFinding($f->value());
Assert::true($p->isOk(),'typed owner proposal produced from immutable finding');
if(!$p->isOk())return;
$before=[];
foreach(['proposal_envelopes','evidence_bundles','hmwh_results','arbiter_verdicts',
         'decision_records','release_artifacts','customer_output_projections',
         'company_signals','role_distribution_briefings'] as $table){
    $before[$table]=(int)$db->scalar('SELECT COUNT(*) FROM '.$table);
}
if(!defined('ALLFATHER_AN03_FIXTURE_OWNER_TEST'))define('ALLFATHER_AN03_FIXTURE_OWNER_TEST',true);
$fixture=new An03FixtureReleaseOwner();
$adopted=HistoryReviewRelease::exerciseFixtureOwnerPath($p->value(),$fixture);
Assert::true($adopted->isOk(),'fully ordered fixture owner path on disposable DB');
if(!$adopted->isOk())return;
Assert::same(HistoryReviewRelease::FIXTURE_OWNER_STAGES,$fixture->stageCalls,
    'all native owner-facing stages were invoked in order');
Assert::same(false,$adopted->value()['native_m10_record_written'],
    'fixture did not append any real M10 record');
Assert::same(false,$adopted->value()['customer_visibility_authorized'],
    'fixture did not activate product visibility');
foreach($before as $table=>$count){
    Assert::same($count,(int)$db->scalar('SELECT COUNT(*) FROM '.$table),
        'no native owner write in synthetic fixture: '.$table);
}
$wrong=$p->value();
$wrong['scope']['tenant_id']=$foreign['tenant_id'];
$wrong['scope']['workspace_id']=$foreign['workspace_id'];
$port=new An03FixtureReleaseOwner();
Assert::same('AN03_OWNER_PROPOSAL_INVALID',
    HistoryReviewRelease::exerciseFixtureOwnerPath($wrong,$port)->reasonCode(),
    'foreign scope cannot enter owner stage zero');
Assert::same([],$port->stageCalls,'foreign request dispatched no owner method');
Assert::same('AN03_U03_U06_U07_M10_HISTORICAL_RELEASE_NOT_ADOPTED',
    HistoryReviewRelease::release($f->value(),$adopted->value())->reasonCode(),
    'fixture success cannot be submitted as native release');
