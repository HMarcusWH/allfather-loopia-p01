<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';
require_once dirname(__DIR__).'/support/Ui02FixtureReviewOwner.php';

use Allfather\Loopia\Core\{Ids,Digest};
use Allfather\Loopia\Http\ReadAdapters\HistoryReviewAdapter;
use Allfather\Loopia\Tests\{Assert,Ui02FixtureReviewOwner};
use Allfather\Loopia\Workflow\HistoryReviewCommandCandidate;

if(!defined('ALLFATHER_UI02_SYNTHETIC_TEST'))define('ALLFATHER_UI02_SYNTHETIC_TEST',true);
$session=[
  'tenant_id'=>Ids::new(),'active_workspace_id'=>Ids::new(),'operator_id'=>Ids::new(),
  'display_name'=>'Synthetic reviewer',
];
$competitor=Ids::new();$question='ui02_question';
$scope=HistoryReviewAdapter::scope($session,$competitor,$question);
$fixture=new Ui02FixtureReviewOwner($scope);
$adapter=new HistoryReviewAdapter($fixture);
$read=$adapter->list($session,$competitor,$question);
Assert::same('VISIBLE',$read['decision'],'fixture read exercises exact owner and scope');
Assert::same(1,count($read['data']['rows']),'one synthetic reviewer row');
$row=$read['data']['rows'][0];
Assert::same('HUMAN_REVIEW_REQUIRED',$row['status'],
    'critic passing to human does not impersonate human acceptance');
Assert::same(2,$row['selected_count'],'all source references retained');
Assert::same(1,$row['contrary_count'],'contrary evidence retained');
Assert::same(false,$row['human_factual_release_approved'],
    'synthetic reviewer does not approve factual accuracy');
Assert::same(false,$row['customer_visibility_authorized'],
    'source reviewer cannot publish customers');
Assert::same('U03_NOT_ADOPTED',$row['historical_source_rights'],
    'candidate metadata not a source-use entitlement');
Assert::same('NOT_FOUND',
    $adapter->list($session,$competitor,$question,99)['decision'],
    'out-of-range page refused');
Assert::same('VISIBLE',
    $adapter->list($session,$competitor,$question,2)['decision'],
    'next in-range page is an explicit empty record set');
Assert::same([], $adapter->list($session,$competitor,$question,2)['data']['rows'],
    'a later empty page cannot fabricate another historical finding');
$foreign=$session;$foreign['active_workspace_id']=Ids::new();
Assert::same('DENIED',$adapter->list($foreign,$competitor,$question)['decision'],
    'another workspace cannot read this fixture review');
Assert::same('NO_ACTIVE_WORKSPACE',
    $adapter->list(null,$competitor,$question)['decision'],
    'unauthenticated read is not available');
Assert::same('NOT_FOUND',
    $adapter->list($session,'<bad>', $question)['decision'],
    'untrusted selector is rejected before owner call');
Assert::same(0,$fixture->receiptCount(),'all GET reads leave business work untouched');

$command=new HistoryReviewCommandCandidate($adapter);
$plan=$command->reviewPlan($session,$competitor,$question,$row['revision'],
    'REQUEST_HUMAN_REVIEW','CONTRARY_EVIDENCE_UNRESOLVED');
Assert::true($plan->isOk(),'exact scoped reviewed command plan runs in fixture');
if(!$plan->isOk())return;
Assert::same(false,$plan->value()['human_decision_authenticated'],
    'plan is not a real human decision');
Assert::same('UI02_REVIEW_REVISION_STALE_OR_NOT_SCOPED',
    $command->reviewPlan($session,$competitor,$question,str_repeat('a',64),
       'REQUEST_HUMAN_REVIEW','CONTRARY_EVIDENCE_UNRESOLVED')->reasonCode(),
    'wrong review revision cannot act');
Assert::same('UI02_REVIEW_PLAN_STALE_OR_FORGED',
    $command->submit($session,$competitor,$question,$row['revision'],
       'REQUEST_HUMAN_REVIEW','CONTRARY_EVIDENCE_UNRESOLVED',
       str_repeat('f',64))->reasonCode(),
    'correct revision with forged plan digest refuses');
Assert::same(0,$fixture->receiptCount(),'forged command wrote no fixture record');

$submitted=$command->submit($session,$competitor,$question,$row['revision'],
    'REQUEST_HUMAN_REVIEW','CONTRARY_EVIDENCE_UNRESOLVED',
    $plan->value()['plan_digest']);
Assert::true($submitted->isOk(),'fixture owner records bounded candidate without business mutation');
if(!$submitted->isOk())return;
Assert::same(1,$fixture->receiptCount(),'one exact synthetic command recorded');
Assert::same(false,$submitted->value()['actual_business_mutation'],
    'a POST in the fixture cannot mutate native M07 or M10');
Assert::same(false,$submitted->value()['human_decision_admitted'],
    'a proposal is not an operator approval');
Assert::same(1,$command->submit($session,$competitor,$question,$row['revision'],
    'REQUEST_HUMAN_REVIEW','CONTRARY_EVIDENCE_UNRESOLVED',
    $plan->value()['plan_digest'])->value()['record_count'],
    'replaying identical synthetic plan does not duplicate the recorded work');

Assert::same('UI02_U03_U06_U07_U09_NATIVE_COMMAND_NOT_ADOPTED',
    HistoryReviewCommandCandidate::forRuntime()->reasonCode(),
    'production cannot instantiate a newly approved history command');
