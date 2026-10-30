<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';
require_once dirname(__DIR__).'/support/Ui02FixtureReviewOwner.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Http\{Csrf,Request,View};
use Allfather\Loopia\Http\Controllers\HistoryReviewController;
use Allfather\Loopia\Http\ReadAdapters\HistoryReviewAdapter;
use Allfather\Loopia\Tests\{Assert,Harness,Seed,Ui02FixtureReviewOwner};
use Allfather\Loopia\Workflow\HistoryReviewCommandCandidate;

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$clock=Harness::clock('2026-10-30T12:10:00.000Z');
$owner=Seed::tenantWorkspace($db,$clock,'ui02-fixture-owner');
$other=Seed::tenantWorkspace($db,$clock,'ui02-fixture-foreign');
$competitor=Ids::new();
$db->insert('competitors',[
    'competitor_id'=>$competitor,'schema_version'=>'1',
    'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
    'display_name'=>'UI02 synthetic question',
    'competitor_class'=>'DIRECT','status'=>'ACTIVE',
    'created_at'=>$clock->now(),
]);
$csrf=Csrf::issue();
$session=[
    'tenant_id'=>$owner['tenant_id'],
    'active_workspace_id'=>$owner['workspace_id'],
    'operator_id'=>Ids::new(),
    'display_name'=>'UI02 fixture identity; no live membership',
    'csrf_token'=>$csrf,'csrf_token_hash'=>Csrf::digest($csrf),
];
$question='ui02_fixture_question';
$scope=HistoryReviewAdapter::scope($session,$competitor,$question);
if(!defined('ALLFATHER_UI02_SYNTHETIC_TEST'))define('ALLFATHER_UI02_SYNTHETIC_TEST',true);
$fixture=new Ui02FixtureReviewOwner($scope);
$adapter=new HistoryReviewAdapter($fixture);
$commands=new HistoryReviewCommandCandidate($adapter);
$ctl=new HistoryReviewController($adapter,$commands,
    new View(dirname(__DIR__,2).'/app/Http/templates'));
$baseline=[];
foreach([
    'company_signals','proposal_envelopes','decision_records',
    'release_artifacts','customer_output_projections','role_distribution_briefings',
] as $table)$baseline[$table]=(int)$db->scalar('SELECT COUNT(*) FROM '.$table);

$get=Request::fromGlobals(
    ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/console/history-review'],
    ['competitor'=>$competitor,'question'=>$question],[],[],
);
Assert::same(200,$ctl->show($get,$session)->status,
    'GET metadata reads only scoped synthetic owner fixtures');
Assert::same(0,$fixture->receiptCount(),'GET creates no review proposal');
$revision=$adapter->list($session,$competitor,$question)['data']['rows'][0]['revision'];
$plan=$commands->reviewPlan($session,$competitor,$question,$revision,
    'REQUEST_HUMAN_REVIEW','OPERATOR_REVIEW_REQUESTED');
Assert::true($plan->isOk(),'valid bounded plan can be reviewed');
if(!$plan->isOk())return;
$post=Request::fromGlobals(
    ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/console/history-review'],[],
    [
      'csrf_token'=>$csrf,'competitor'=>$competitor,'question'=>$question,
      'expected_revision'=>$revision,'review_action'=>'REQUEST_HUMAN_REVIEW',
      'reason_code'=>'OPERATOR_REVIEW_REQUESTED','command_mode'=>'propose',
      'plan_digest'=>$plan->value()['plan_digest'],
    ],[],
);
Assert::same(200,$ctl->submit($post,$session)->status,
    'POST exact scoped fixture plan writes only in-memory receipt');
Assert::same(1,$fixture->receiptCount(),
    'fixture command is idempotent and does not become M07/M10 approval');
$foreign=$session;
$foreign['tenant_id']=$other['tenant_id'];
$foreign['active_workspace_id']=$other['workspace_id'];
Assert::same(403,$ctl->show($get,$foreign)->status,
    'foreign tenant cannot see prior question');
foreach($baseline as $table=>$count){
    Assert::same($count,(int)$db->scalar('SELECT COUNT(*) FROM '.$table),
        'no native product or governance row changed: '.$table);
}
