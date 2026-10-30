<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';
require_once dirname(__DIR__).'/support/Aq02FixtureAttentionOwner.php';
require_once dirname(__DIR__).'/support/Aq02MockAttention.php';
require_once dirname(__DIR__).'/support/Ui02FixtureReviewOwner.php';

use Allfather\Loopia\Core\{Ids,Outcome};
use Allfather\Loopia\Http\{Request,Csrf,View};
use Allfather\Loopia\Http\Controllers\HistoryReviewController;
use Allfather\Loopia\Http\ReadAdapters\{
    HistoryReviewAdapter,HistoryReviewWorkbenchOwnerPort,
};
use Allfather\Loopia\Tests\{Assert,Ui02FixtureReviewOwner};
use Allfather\Loopia\Workflow\HistoryReviewCommandCandidate;

if(!defined('ALLFATHER_UI02_SYNTHETIC_TEST'))define('ALLFATHER_UI02_SYNTHETIC_TEST',true);
$token=Csrf::issue();
$session=[
    'tenant_id'=>Ids::new(),'active_workspace_id'=>Ids::new(),
    'operator_id'=>Ids::new(),'display_name'=>'<img src=x onerror=alert(1)>',
    'csrf_token_hash'=>Csrf::digest($token),'csrf_token'=>$token,
];
$competitor=Ids::new();$question='ui02_review_case';
$scope=HistoryReviewAdapter::scope($session,$competitor,$question);
$owner=new Ui02FixtureReviewOwner($scope);
$adapter=new HistoryReviewAdapter($owner);
$commands=new HistoryReviewCommandCandidate($adapter);
$view=new View(dirname(__DIR__,2).'/app/Http/templates');
$ctl=new HistoryReviewController($adapter,$commands,$view);

$get=Request::fromGlobals(
    ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/console/history-review'],
    ['competitor'=>$competitor,'question'=>$question],[],[],
);
$page=$ctl->show($get,$session);
Assert::same(200,$page->status,'fixture GET renders scoped metadata');
Assert::same(0,$owner->receiptCount(),'GET does not propose, approve or release');
Assert::false(str_contains($page->body,'<img src=x onerror'),
    'session content remains HTML-escaped');
Assert::true(str_contains($page->body,'&lt;img src=x onerror='),
    'user supplied text is encoded, never interpreted as HTML');
Assert::false(str_contains($page->body,'source_page_body'),
    'no source bytes are emitted');

$revision=$adapter->list($session,$competitor,$question)['data']['rows'][0]['revision'];
$body=[
    'csrf_token'=>$token,'competitor'=>$competitor,'question'=>$question,
    'expected_revision'=>$revision,'review_action'=>'REQUEST_HUMAN_REVIEW',
    'reason_code'=>'CONTRARY_EVIDENCE_UNRESOLVED','command_mode'=>'plan',
];
$post=static fn(array $payload):Request=>Request::fromGlobals(
    ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/console/history-review'],[],
    $payload,[],
);
$missing=$body;unset($missing['csrf_token']);
Assert::same(403,$ctl->submit($post($missing),$session)->status,
    'direct POST lacking CSRF is refused before owner composition');
Assert::same(0,$owner->receiptCount(),
    'CSRF denied before any owner-side candidate append');
$bad=$body;$bad['csrf_token']='forged';
Assert::same(403,$ctl->submit($post($bad),$session)->status,
    'forged form token cannot enter a review command');

$planned=$commands->plan($session,$competitor,$question,$revision,
    'REQUEST_HUMAN_REVIEW','CONTRARY_EVIDENCE_UNRESOLVED');
Assert::true($planned->isOk(),'fixture plan available for replay/stale tests');
if(!$planned->isOk())return;
$withPlan=$body;
$withPlan['command_mode']='propose';
$withPlan['plan_digest']=str_repeat('f',64);
Assert::same(409,$ctl->submit($post($withPlan),$session)->status,
    'stale plan digest denied with no business mutation');
Assert::same(0,$owner->receiptCount(),'bad plan does not create a fixture record');
$withPlan['plan_digest']=$planned->value()['plan_digest'];
Assert::same(200,$ctl->submit($post($withPlan),$session)->status,
    'exact reviewed plan can create an inert fixture work proposal');
Assert::same(1,$owner->receiptCount(),'one fixture in-memory proposal, not M07/M10');
$foreign=$session;$foreign['active_workspace_id']=Ids::new();
Assert::same(409,$ctl->submit($post($withPlan),$foreign)->status,
    'foreign workspace cannot apply the original workspace review digest');

$rogue=new class implements HistoryReviewWorkbenchOwnerPort {
    public function read(array $scope,int $page,int $limit):Outcome {
        return Outcome::ok(['decision'=>'VISIBLE','all_grants'=>true]);
    }
    public function proposeCommand(array $scope,array $candidate):Outcome {
        return Outcome::ok(['approved'=>true]);
    }
};
Assert::same('UNAVAILABLE',
    (new HistoryReviewAdapter($rogue))->list($session,$competitor,$question)['decision'],
    'an arbitrary caller owner is not an accepted real/fixture M02 read');
foreach([
    'app/Http/Controllers/HistoryReviewController.php',
    'app/Http/ReadAdapters/HistoryReviewAdapter.php',
    'app/Workflow/HistoryReviewCommandCandidate.php',
] as $path){
    $text=(string)file_get_contents(dirname(__DIR__,2).'/'.$path);
    foreach(['curl_exec(', 'new ModelExecutor(', 'mail(', 'new DecisionLedger(',
             'releaseP01(', 'releaseRoleDistribution(', 'DELETE FROM',
             'new SourceCaptureStage('] as $needle){
        Assert::false(str_contains($text,$needle),
            'UI02 workbench may not dispatch or mutate native release: '.$needle);
    }
}
