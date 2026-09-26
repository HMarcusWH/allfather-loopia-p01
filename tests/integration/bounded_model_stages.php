<?php
declare(strict_types=1);
require_once __DIR__.'/../support/MonitorModelFixture.php';
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Ingestion\{FetchResponse,FixedHostResolver,FixtureTransport};
use Allfather\Loopia\Product\P01\Capture;
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\Provider\{FixtureModelTransport,ModelExecutor,ModelRequestBuilder};
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M06\Gateway;
use Allfather\Loopia\Tests\{Assert,Harness,MonitorModelFixture};
use Allfather\Loopia\Workflow\ContextEnvelope;
$db=Harness::freshDb();if($db===null){Assert::skip(Harness::unavailableReason());return;}
$clock=Harness::clock();$fixture=MonitorModelFixture::create($db,$clock);$scope=$fixture['scope'];
$gateway=new Gateway($db,$clock,new AccessGuard($db,$clock),new Eligibility($db,$clock),new Invocation($db,$clock));
$capture=new Capture($db,$clock,$gateway,new EvidenceSpine($db,$clock));$input=$fixture['context_selection'];
foreach([$fixture['before_body'],$fixture['after_body']] as $i=>$body){
 if($i)$clock->advanceDays(1);
 $r=$capture->captureMonitoredSource(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'monitored_source_id'=>$fixture['monitored_source_id'],'capture_url'=>$fixture['capture_url'],'idempotency_key'=>'stage-capture-'.$i,'correlation_id'=>Ids::new(),'governance'=>$fixture['capture_governance']],new FixtureTransport([$fixture['capture_url']=>new FetchResponse(200,'text/html',null,$body)]),new FixedHostResolver(['sources.example'=>['93.184.216.34']]));
 Assert::true($r->isOk(),'stage fixture uses real capture '.$i);if(!$r->isOk())return;
 $eid=$db->scalar('SELECT evidence_id FROM source_snapshots WHERE source_snapshot_id=?',[$r->value()['source_snapshot_id']]);$input['observation_evidence_ids'][]=$eid;$input['observation_payloads'][$eid]=['url'=>$fixture['capture_url'],'body'=>$body];
}
$contextService=new ContextEnvelope($db,$clock,$fixture['evidence_store']);$result=$contextService->prepare($scope,$input);
Assert::true($result->isOk(),'shared stage context');if(!$result->isOk())return;$context=$result->value();
$transport=new FixtureModelTransport(MonitorModelFixture::response(...));
$executor=new ModelExecutor($db,$clock,$gateway,$fixture['credential_store'],$transport,$contextService);
$prior=null;
foreach(['P01_SIGNAL_CLASSIFIER','BATTLECARD_GENERATOR','MONITOR_REVIEW_CRITIC'] as $i=>$role){
 $request=ModelRequestBuilder::prepare(ProductPromptPack::resolve($role)->value(),$context,$prior===null?[]:['stage_inputs'=>['prior_candidate'=>$prior]]);
 Assert::true($request->isOk(),'request prepared for '.$role);if(!$request->isOk()){Assert::same(null,$request->reasonCode(),'stage preparation reason');return;}$request=$request->value();
 $command=($role==='MONITOR_REVIEW_CRITIC'?$fixture['critic_governance']:$fixture['author_governance'])+['idempotency_key'=>'stage-model-'.$i,'correlation_id'=>Ids::new()];
 $result=$executor->execute($command,$request,$fixture['credential_id']);
 Assert::same('MODEL_CANDIDATE',$result['kind'],'governed offline stage '.$role);if($result['kind']!=='MODEL_CANDIDATE'){Assert::same(null,$result['reason_code'],'stage execution reason');return;}
 $prior=['role'=>$role,'context_digest'=>$context['digest'],'request_digest'=>$request->digest(),'candidate_digest'=>$result['candidate_digest'],'candidate'=>$result['candidate']];
}
Assert::same(3,$transport->calls,'three stages make exactly three fixture calls');
Assert::notSame($fixture['author_governance']['valkyrie_id'],$fixture['critic_governance']['valkyrie_id'],'critic has independently assigned identity');
Assert::same(true,$result['candidate']['requires_human_review'],'critic cannot approve release');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM released_briefings'),'three model stages have no release side effect');

// Independently produced genuine release, to test the routing projection/currentness boundary.
// This is a service fixture, not a claim that the preceding model candidates were human-approved.
$source=\Allfather\Loopia\Tests\Seed::p01ReleasedBriefing($db,$clock,$scope);
$db->run('UPDATE missions SET allowed_source_scope_ids=? WHERE mission_id=?',[json_encode($input['source_ids']),$source['mission_id']]);
$routingInput=$input;$routingInput['mission_id']=$source['mission_id'];
$ledger=new \Allfather\Loopia\Substrate\M10\DecisionLedger($db,$clock);
$distribution=new \Allfather\Loopia\Product\P04\Distribution($db,$clock,$ledger);
$distribution->configureRoleRedactionPolicy(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'target_org_role'=>'SALES','redaction_rules'=>['REDACT_INTERNAL_MARGIN']]);
$routingContext=$contextService->prepare($scope,$routingInput)->value();
$routingId=$distribution->composeRoleDistributionBriefing(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'target_org_role'=>'SALES','source_kind'=>'P01_BRIEFING','source_id'=>$source['released_briefing_id'],'briefing_summary_safe'=>'Internal planning context only.'])->value()['role_distribution_briefing_id'];
Assert::same('MODEL_ROUTING_SCOPE_OR_STATE_INVALID',$contextService->routingPackage($routingContext,$routingId)->reasonCode(),'routing critic cannot run over unsubmitted draft');
$readiness=new \Allfather\Loopia\Substrate\M05\Readiness($db,$clock);
$routingAdmission=new \Allfather\Loopia\Product\P04\DistributionAdmission($db,$clock,new EvidenceSpine($db,$clock),$readiness,new \Allfather\Loopia\Substrate\M04\Proposals($db,$clock),new \Allfather\Loopia\Substrate\M07\Council($db,$clock),new \Allfather\Loopia\Substrate\M08\Arbiter($db,$clock,$readiness),$ledger);
$submitted=$routingAdmission->submitRoutingProposal(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'role_distribution_briefing_id'=>$routingId,'governance'=>array_intersect_key($source,array_flip(['mission_id','producing_valkyrie_id','capability_contract_id']))]);
Assert::true($submitted->isOk(),'genuine routing submission');if(!$submitted->isOk())return;
Assert::same('MODEL_ROUTING_REVIEW_NOT_OPEN',$contextService->routingPackage($routingContext,$routingId)->reasonCode(),'routing must open its actual sensitivity review before critic dispatch');
$routingAdmission->openSensitivityReview(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'role_distribution_briefing_id'=>$routingId,'initiated_by_actor_type'=>'OPERATOR','initiated_by_actor_id'=>$scope['operator_id']]);
$package=$contextService->routingPackage($routingContext,$routingId);
Assert::true($package->isOk(),'routing package is server-produced and currently relied');if(!$package->isOk())return;$package=$package->value();
Assert::same(['REDACT_INTERNAL_MARGIN'],$package['envelope']['redaction_profile'],'frozen redaction policy enters inspected model input');
$draft=json_decode(file_get_contents(__DIR__.'/../fixtures/monitor_prompt_offline_cases.json'),true,128,JSON_THROW_ON_ERROR)['cases'][1]['candidate'];
$draft=json_decode(strtr(json_encode($draft),['ev-before'=>$input['observation_evidence_ids'][0],'ev-after'=>$input['observation_evidence_ids'][1],'ev-profile'=>$input['profile_evidence_ids'][0]]),true);
$brief=$db->one('SELECT * FROM battlecard_briefs WHERE battlecard_brief_id=?',[$source['battlecard_brief_id']]);
foreach(array_keys($draft['content']) as $key)$draft['content'][$key]=$brief[$key];
Assert::true($contextService->validateRoutingPackage($routingContext,$package,$draft)->isOk(),'routing prior content matches actual released P01');
$fake=$draft;$fake['content']['talk_track_safe']='different';
Assert::same('MODEL_ROUTING_PRIOR_DRAFT_MISMATCH',$contextService->validateRoutingPackage($routingContext,$package,$fake)->reasonCode(),'different draft cannot borrow release pedigree');
$fake=$package;$fake['envelope']['briefing_summary_safe']='substituted';$fake['digest']=\Allfather\Loopia\Core\Digest::canonicalDigest($fake['envelope']);
Assert::same('MODEL_ROUTING_PACKAGE_NOT_CURRENT',$contextService->validateRoutingPackage($routingContext,$fake,$draft)->reasonCode(),'self-hashed caller routing text is refused');
$prior=['role'=>'BATTLECARD_GENERATOR','context_digest'=>$routingContext['digest'],'request_digest'=>str_repeat('f',64),'candidate_digest'=>\Allfather\Loopia\Core\Digest::canonicalDigest($draft),'candidate'=>$draft];
$r=ModelRequestBuilder::prepare(ProductPromptPack::resolve('MONITOR_ROUTING_CRITIC')->value(),$routingContext,['stage_inputs'=>['prior_candidate'=>$prior,'routing_package'=>$package]]);
Assert::true($r->isOk(),'routing prompt binds distinct routing package and preceding draft');
if($r->isOk()){
 $assignment=(new \Allfather\Loopia\Substrate\M04\Proposals($db,$clock))->assignToMission($source['mission_id'],$fixture['critic_governance']['valkyrie_id'],$fixture['critic_governance']['capability_contract_id'],'ROUTING_CRITIC',[$fixture['model_descriptor']['governed_tool_id']],['ANALYZE'],$scope['operator_id'])->value()['assignment_id'];
 $command=[...$fixture['critic_governance'],'mission_id'=>$source['mission_id'],'assignment_id'=>$assignment,'idempotency_key'=>'routing-model','correlation_id'=>Ids::new()];
 $result=$executor->execute($command,$r->value(),$fixture['credential_id']);
 Assert::same('MODEL_CANDIDATE',$result['kind'],'routing critic passes through actual M06/M01 only with current routing proof');
}
$distribution->configureRoleRedactionPolicy(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'target_org_role'=>'SALES','redaction_rules'=>['NEW_RESTRICTION']]);
Assert::same('CONTEXT_AUTHORITY_OR_CONTENT_CHANGED',$contextService->validateRoutingPackage($routingContext,$package,$draft)->reasonCode(),'changed target policy invalidates prepared routing context');
Assert::same('PENDING_VERDICT',$db->scalar('SELECT briefing_status FROM role_distribution_briefings WHERE role_distribution_briefing_id=?',[$routingId]),'routing model output grants no verdict or distribution');
