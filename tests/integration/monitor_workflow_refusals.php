<?php
declare(strict_types=1);
require_once __DIR__.'/../support/MonitorModelFixture.php';

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Ingestion\{FetchRequest,FetchResponse,FetchTransport,FixedHostResolver};
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Provider\{FixtureModelTransport,ModelResponse};
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Tests\{Assert,Harness,MonitorModelFixture,Seed};
use Allfather\Loopia\Workflow\{MissionConfiguration,MonitorFactory,RunJournal};

$db=Harness::freshDb();if($db===null){Assert::skip(Harness::unavailableReason());return;}
$clock=Harness::clock();
$value=static function(Outcome $outcome,string $label):mixed {
    Assert::true($outcome->isOk(),$label.($outcome->isOk()?'':': '.$outcome->reasonCode()));
    if(!$outcome->isOk())throw new RuntimeException($label.': '.$outcome->reasonCode());
    return $outcome->value();
};

// Every case starts through the public composition service, with actual baseline and changed
// captures. The fixtures grant explicit authority; no run cursor/model result is fabricated.
$setup=static function(string $label,Closure $respond)use($db,$clock,$value):array {
    $f=MonitorModelFixture::create($db,$clock);$scope=$f['scope'];
    $role=Seed::role($db,$clock,'monitor-refusal-'.$label,['mission.read','mission.create','mission.activate','mission.transition','evidence.read','tool.invoke','company_signal.review','distribution.route','distribution.configure_targets']);
    $membership=Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$role['role_id']);
    $mission=$f['context_selection']['mission_id'];
    $db->run('UPDATE missions SET mission_type=? WHERE mission_id=?',['COMPETITION_MONITORING',$mission]);
    $f['context_selection']['purpose']=$db->scalar('SELECT objective FROM missions WHERE mission_id=?',[$mission]);
    $policies=new PolicySets($db,$clock);
    $p01=$value($policies->seed(PolicySets::P01_COMPETITION_RELEVANCE,$scope['tenant_id'],$scope['workspace_id']),$label.' P01 policy');
    $p04=$value($policies->seed(PolicySets::P04_DISTRIBUTION_ADMISSION,$scope['tenant_id'],$scope['workspace_id']),$label.' P04 policy');
    $value((new Distribution($db,$clock,new DecisionLedger($db,$clock)))->configureRoleRedactionPolicy($scope+['target_org_role'=>'SALES','redaction_rules'=>[]]),$label.' configured target');
    $definition=['mission_id'=>$mission,'monitored_source_id'=>$f['monitored_source_id'],'capture_url'=>$f['capture_url'],
        'capture_governance'=>$f['capture_governance'],'model_governance'=>['author'=>$f['author_governance'],'critic'=>$f['critic_governance']],
        'release_governance'=>['mission_id'=>$mission,'producing_valkyrie_id'=>$f['author_governance']['valkyrie_id'],'capability_contract_id'=>$f['author_governance']['capability_contract_id']],
        'context_selection'=>$f['context_selection'],'credential_id'=>$f['credential_id'],'target_org_role'=>'SALES',
        'p01_policy_id'=>$p01['arbiter_policy_set_id'],'p04_policy_id'=>$p04['arbiter_policy_set_id']];
    $value((new MissionConfiguration($db,$clock))->configure($scope,$definition,'FIXTURE'),$label.' configured existing authority');
    $source=new class([$f['before_body'],$f['after_body']]) implements FetchTransport {
        public int $calls=0;
        public function __construct(private array $pages){}
        public function send(FetchRequest $request):FetchResponse{return new FetchResponse(200,'text/plain; charset=utf-8',null,$this->pages[$this->calls++]??'Unexpected extra capture');}
    };
    $model=new FixtureModelTransport($respond);
    $service=MonitorFactory::forFixture($db,$clock,$f['evidence_store'],$f['credential_store'],$source,new FixedHostResolver(['sources.example'=>['93.184.216.34']]),$model);
    $baseline=$value($service->startConfigured($scope,$mission,'baseline'),$label.' baseline start');
    $baseline=$value($service->advance($scope,$baseline['monitor_run_id']),$label.' baseline capture');
    Assert::same('BASELINE',$baseline['stage'],$label.' first observation remains baseline');
    Assert::same(0,$model->calls,$label.' baseline invokes no model');
    $clock->advanceDays(1);
    $run=$value($service->startConfigured($scope,$mission,'changed'),$label.' changed start');$id=$run['monitor_run_id'];
    Assert::same('CAPTURED',$value($service->advance($scope,$id),$label.' changed capture')['stage'],$label.' actual second capture');
    Assert::same('DIFFED',$value($service->advance($scope,$id),$label.' change detection')['stage'],$label.' real diff ready');
    $detail=$value($service->detail($scope,$id),$label.' request inspection');
    Assert::true(is_string($detail['preview_request']['request_digest']??null),$label.' exact proposed request is inspectable');
    return compact('f','scope','service','source','model','id','membership','detail');
};
$noRelease=static function(array $case,string $label)use($db):void {
    foreach(['released_briefings','released_distribution_outputs'] as $table)Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM '.$table.' WHERE tenant_id=? AND workspace_id=?',[$case['scope']['tenant_id'],$case['scope']['workspace_id']]),$label.' has no '.$table);
};
$historyPrefix=static function(array $before,array $after,string $label):void {
    Assert::same($before['events'],array_slice($after['events'],0,count($before['events'])),$label.' retains exact prior event records');
    Assert::same('PASS',$after['integrity'],$label.' complete event chain verifies');
};

// Quota reaches the real provider executor once, then becomes a terminal run without retry.
$quota=$setup('quota',static fn($request)=>new ModelResponse(429,'{"error":{"code":"insufficient_quota","type":"billing","message":"RAW_PROVIDER_MESSAGE_MUST_NOT_ESCAPE"}}'));
$blocked=$value($quota['service']->advance($quota['scope'],$quota['id'],$quota['detail']['preview_request']['request_digest']),'quota becomes recorded refusal');
Assert::same('BLOCKED',$blocked['stage'],'quota blocks classifier stage');
Assert::same('MODEL_QUOTA_EXHAUSTED',$blocked['cursor']['reason_code'],'quota classification remains specific');
Assert::same(1,$quota['model']->calls,'quota made exactly one fixture attempt');
Assert::false(str_contains(json_encode($blocked),'RAW_PROVIDER_MESSAGE_MUST_NOT_ESCAPE'),'raw provider error not exposed in run');
$after=$value($quota['service']->detail($quota['scope'],$quota['id']),'quota replay');
$historyPrefix($quota['detail'],$after,'quota');
Assert::same(1,count($after['artifacts']),'quota retains exact inspected request without invented candidate');
Assert::same('MONITOR_STAGE_REQUIRES_REVIEW_OR_IS_TERMINAL',$quota['service']->advance($quota['scope'],$quota['id'],$quota['detail']['preview_request']['request_digest'])->reasonCode(),'blocked quota cannot advance again');
Assert::same(1,$quota['model']->calls,'repeated advance never retries quota');
Assert::same(2,$quota['source']->calls,'blocked retry never recaptures');
$again=$value($quota['service']->detail($quota['scope'],$quota['id']),'quota replay after refused advance');
Assert::same($after['events'],$again['events'],'repeated advance does not alter quota history');
$noRelease($quota,'quota');

foreach(['HOLD'=>'BLOCKED','REJECT'=>'STOPPED'] as $decision=>$expectedStage){
    $case=$setup(strtolower($decision),MonitorModelFixture::response(...));
    foreach(['ANALYZED','DRAFTED','REVIEW_REQUIRED'] as $expected){
        $detail=$value($case['service']->detail($case['scope'],$case['id']),$decision.' exact request');
        $run=$value($case['service']->advance($case['scope'],$case['id'],$detail['preview_request']['request_digest']),$decision.' confirmed model step');
        Assert::same($expected,$run['stage'],$decision.' progresses to '.$expected);
    }
    Assert::same(3,$case['model']->calls,$decision.' reaches human review after three calls');
    $before=$value($case['service']->detail($case['scope'],$case['id']),$decision.' review packet');
    $evidenceBefore=(int)$db->scalar('SELECT COUNT(*) FROM evidence_objects WHERE tenant_id=? AND workspace_id=?',[$case['scope']['tenant_id'],$case['scope']['workspace_id']]);
    $review=$value($case['service']->review($case['scope'],$case['id'],['decision'=>$decision,'assessment'=>'Synthetic operator assessment: preserve the observed evidence, but do not release this candidate.','candidate_digest'=>$before['review_digest']]),$decision.' human decision recorded');
    Assert::same($expectedStage,$review['stage'],$decision.' is terminal before any release work');
    Assert::same('MONITOR_HUMAN_'.$decision,$review['cursor']['reason_code'],$decision.' stops for the human decision itself');
    $after=$value($case['service']->detail($case['scope'],$case['id']),$decision.' history after decision');
    $historyPrefix($before,$after,$decision);
    Assert::same(7,count($after['artifacts']),$decision.' preserves three requests/candidates and the human assessment');
    Assert::same($evidenceBefore,(int)$db->scalar('SELECT COUNT(*) FROM evidence_objects WHERE tenant_id=? AND workspace_id=?',[$case['scope']['tenant_id'],$case['scope']['workspace_id']]),$decision.' preserves evidence rows');
    $human=array_values(array_filter($after['artifacts'],static fn($a)=>$a['kind']==='human_review'))[0]['payload'];
    Assert::same($decision,$human['decision'],$decision.' actual operator choice retained');
    Assert::same($case['scope']['operator_id'],$human['operator_id'],$decision.' actual operator identity retained');
    Assert::same('MONITOR_NOT_AWAITING_REVIEW',$case['service']->review($case['scope'],$case['id'],['decision'=>'APPROVE','assessment'=>'A later request cannot overwrite the recorded human stop.','candidate_digest'=>$before['review_digest']])->reasonCode(),$decision.' cannot be overwritten by stale approval');
    Assert::same('MONITOR_STAGE_REQUIRES_REVIEW_OR_IS_TERMINAL',$case['service']->advance($case['scope'],$case['id'])->reasonCode(),$decision.' repeated advance refuses');
    Assert::same(3,$case['model']->calls,$decision.' no model call after human stop');
    Assert::same(2,$case['source']->calls,$decision.' no fetch after human stop');
    $noRelease($case,$decision);
}

// Revocations happen after inspection but before the first model dispatch.
$role=$setup('role-revoked',MonitorModelFixture::response(...));
$db->run("UPDATE workspace_memberships SET status='REVOKED' WHERE membership_id=?",[$role['membership']]);
Assert::same('MONITOR_ACCESS_DENIED',$role['service']->advance($role['scope'],$role['id'],$role['detail']['preview_request']['request_digest'])->reasonCode(),'revoked workflow role refuses inspected dispatch');
Assert::same(0,$role['model']->calls,'role revocation invokes no provider');
$replay=$value((new RunJournal($db,$clock))->replay($role['scope'],$role['id']),'role revocation internal integrity check');
Assert::same($role['detail']['events'],$replay['events'],'role revocation leaves prior run history unchanged');
Assert::same('DIFFED',$replay['run']['stage'],'access denial does not fabricate executed model stage');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts WHERE tenant_id=?',[$role['scope']['tenant_id']]),'role refusal reserves no model attempt');
$noRelease($role,'revoked role');

$source=$setup('source-revoked',MonitorModelFixture::response(...));
$db->run("UPDATE source_references SET status='REVOKED' WHERE source_ref_id=?",[$source['f']['card']['source_ref_id']]);
Assert::same('CONTEXT_SOURCE_NOT_ADMISSIBLE',$source['service']->advance($source['scope'],$source['id'],$source['detail']['preview_request']['request_digest'])->reasonCode(),'revoked source invalidates the inspected request before dispatch');
Assert::same(0,$source['model']->calls,'source revocation invokes no provider');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM provider_model_attempts WHERE tenant_id=?',[$source['scope']['tenant_id']]),'source refusal reserves no model attempt');
$replay=$value((new RunJournal($db,$clock))->replay($source['scope'],$source['id']),'source revocation integrity check');
Assert::same($source['detail']['events'],$replay['events'],'source revocation preserves prior event history');
Assert::same('DIFFED',$replay['run']['stage'],'source refusal never reports analysis performed');
Assert::same(2,$source['source']->calls,'source revocation does not initiate another fetch');
$noRelease($source,'revoked source');
