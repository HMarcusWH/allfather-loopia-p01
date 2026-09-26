<?php
declare(strict_types=1);
require_once __DIR__.'/../support/MonitorModelFixture.php';

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Http\{Csrf,Kernel,Request,View};
use Allfather\Loopia\Http\Controllers\{MonitorController,SessionController,WorkspaceController};
use Allfather\Loopia\Ingestion\{FetchRequest,FetchResponse,FetchTransport,FixedHostResolver};
use Allfather\Loopia\Provider\FixtureModelTransport;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Tests\{Assert,Harness,MonitorModelFixture,Seed};
use Allfather\Loopia\Workflow\{MissionConfiguration,MonitorFactory,MonitorOperations,RunJournal};

// Real Kernel, authentication, service, database and fixture transports. No live source/provider.
$db=Harness::freshDb(); if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }
$clock=Harness::clock();$f=MonitorModelFixture::create($db,$clock);$scope=$f['scope'];
$value=static function(Outcome $out):mixed { if(!$out->isOk())throw new RuntimeException('HTTP fixture setup: '.$out->reasonCode());return $out->value(); };
$permissions=['mission.read','mission.create','mission.activate','mission.transition','evidence.read','tool.invoke','company_signal.review','distribution.route','distribution.configure_targets'];
$role=Seed::role($db,$clock,'http-monitor-authority',$permissions);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$role['role_id']);
$mission=$f['context_selection']['mission_id'];
$db->run('UPDATE missions SET mission_type=? WHERE mission_id=?',['COMPETITION_MONITORING',$mission]);
$f['context_selection']['purpose']=$db->scalar('SELECT objective FROM missions WHERE mission_id=?',[$mission]);
$policies=new PolicySets($db,$clock);
$p01=$value($policies->seed(PolicySets::P01_COMPETITION_RELEVANCE,$scope['tenant_id'],$scope['workspace_id']));
$p04=$value($policies->seed(PolicySets::P04_DISTRIBUTION_ADMISSION,$scope['tenant_id'],$scope['workspace_id']));
$definition=['mission_id'=>$mission,'monitored_source_id'=>$f['monitored_source_id'],'capture_url'=>$f['capture_url'],
    'capture_governance'=>$f['capture_governance'],'model_governance'=>['author'=>$f['author_governance'],'critic'=>$f['critic_governance']],
    'release_governance'=>['mission_id'=>$mission,'producing_valkyrie_id'=>$f['author_governance']['valkyrie_id'],'capability_contract_id'=>$f['author_governance']['capability_contract_id']],
    'context_selection'=>$f['context_selection'],'credential_id'=>$f['credential_id'],'target_org_role'=>'SALES',
    'p01_policy_id'=>$p01['arbiter_policy_set_id'],'p04_policy_id'=>$p04['arbiter_policy_set_id']];
$value((new MissionConfiguration($db,$clock))->configure($scope,$definition,'FIXTURE'));
$source=new class([$f['before_body'],$f['after_body']]) implements FetchTransport {
    public int $calls=0;public function __construct(private array $pages){}
    public function send(FetchRequest $request):FetchResponse{return new FetchResponse(200,'text/plain; charset=utf-8',null,$this->pages[$this->calls++]??'Unexpected fixture capture');}
};
$model=new FixtureModelTransport(MonitorModelFixture::response(...));
$service=MonitorFactory::forFixture($db,$clock,$f['evidence_store'],$f['credential_store'],$source,new FixedHostResolver(['sources.example'=>['93.184.216.34']]),$model);
$auth=new Authenticator($db,$clock);$view=new View(dirname(__DIR__,2).'/app/Http/templates');
$makeKernel=static function(MonitorOperations $operations)use($db,$clock,$auth,$view):Kernel {
    $k=new Kernel($db,$clock,$auth,$view);$session=new SessionController($auth,$view);$workspace=new WorkspaceController($db,$auth,$view);$monitor=new MonitorController($operations,$view);
    $k->route('GET','/login',$session->showLogin(...));$k->route('POST','/login',$session->login(...));$k->route('POST','/logout',$session->logout(...));
    $k->route('GET','/console/workspaces',$workspace->choose(...));$k->route('POST','/console/workspace',$workspace->select(...));
    $k->route('GET','/console/monitor',$monitor->show(...));$k->route('POST','/console/monitor',$monitor->submit(...));return $k;
};
$kernel=$makeKernel($service);
// Keep failure output sanitized: never print an authenticated HTML body or its CSRF value.
$contains=static fn(string $needle,string $body,string $label)=>Assert::true(str_contains($body,$needle),$label);
$request=static fn(string $method,string $path,array $body=[],array $cookies=[],array $query=[]):Request=>Request::fromGlobals(['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$path,'REMOTE_ADDR'=>'203.0.113.50'],$query,$body,$cookies);
$login=static function(array $user)use($kernel,$request):array {
    $page=$kernel->handle($request('GET','/login'));preg_match('/'.preg_quote(Csrf::COOKIE_NAME,'/').'=([^;]+)/',$page->cookies[0],$a);
    $response=$kernel->handle($request('POST','/login',['csrf_token'=>$a[1],'email'=>$user['email'],'password'=>$user['password']],[Csrf::COOKIE_NAME=>$a[1]]));
    Assert::same(303,$response->status,'real login succeeds');
    preg_match('/af_session=([^;]+)/',$response->headers['Set-Cookie'],$s);preg_match('/'.preg_quote(Csrf::COOKIE_NAME,'/').'=([^;]+)/',$response->cookies[0],$c);
    return ['af_session'=>$s[1],Csrf::COOKIE_NAME=>$c[1]];
};
$user=Seed::platformUser($db,$clock,$scope['tenant_id'],$scope['operator_id']);
$anonymous=$kernel->handle($request('GET','/console/monitor'));
Assert::same(303,$anonymous->status,'unauthenticated workbench redirects');Assert::same('/login',$anonymous->headers['Location'],'anonymous destination');
Assert::false(str_contains($anonymous->body,'P01_SIGNAL_CLASSIFIER'),'anonymous sees no prompt');
$cookie=$login($user);$csrf=$cookie[Csrf::COOKIE_NAME];
Assert::same(409,$kernel->handle($request('GET','/console/monitor',[],$cookie,['workspace_id'=>$scope['workspace_id']]))->status,'query workspace cannot establish session selection');
Assert::same(303,$kernel->handle($request('POST','/console/workspace',['csrf_token'=>$csrf,'workspace_id'=>$scope['workspace_id']],$cookie))->status,'workspace chosen through real route');
$base=['action'=>'start','mission_id'=>$mission,'idempotency_key'=>'http-baseline'];
Assert::same(403,$kernel->handle($request('POST','/console/monitor',$base,$cookie))->status,'missing CSRF denied before start');
Assert::same(403,$kernel->handle($request('POST','/console/monitor',$base+['csrf_token'=>'wrong'],$cookie))->status,'wrong CSRF denied');
Assert::same(403,$kernel->handle($request('POST','/console/monitor',$base,$cookie,['csrf_token'=>$csrf]))->status,'query CSRF never accepted');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM monitor_runs'),'CSRF refusals create no run');
$kernel->handle($request('GET','/console/monitor',[],$cookie,$base));
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM monitor_runs'),'GET action parameters cannot start');
$missing=$kernel->handle($request('POST','/console/monitor',['csrf_token'=>$csrf,'action'=>'start','idempotency_key'=>'query-only'],$cookie,['mission_id'=>$mission]));
Assert::same(409,$missing->status,'query mission cannot replace missing body');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM monitor_runs'),'query body substitution causes no run');
$foreign=Seed::tenantWorkspace($db,$clock,'http-monitor-foreign');
$created=$kernel->handle($request('POST','/console/monitor',$base+['csrf_token'=>$csrf,'tenant_id'=>$foreign['tenant_id'],'workspace_id'=>$foreign['workspace_id'],'operator_id'=>$foreign['operator_id']],$cookie,['mission_id'=>'foreign-mission','workspace_id'=>$foreign['workspace_id']]));
Assert::same(303,$created->status,'valid body creates configured run despite forged scope extras');
$run=$db->one('SELECT * FROM monitor_runs WHERE idempotency_key=?',['http-baseline']);$id=$run['monitor_run_id'];
Assert::same($scope['tenant_id'],$run['tenant_id'],'run tenant from authenticated session');Assert::same($scope['workspace_id'],$run['workspace_id'],'run workspace from authenticated selection');Assert::same($scope['operator_id'],$run['initiated_by_operator_id'],'run actor from session');
$post=static function(array $body,array $query=[])use($kernel,$request,&$csrf,&$cookie){return $kernel->handle($request('POST','/console/monitor',$body+['csrf_token'=>$csrf],$cookie,$query));};
Assert::same(303,$post(['action'=>'advance','run_id'=>$id])->status,'baseline advances through Kernel');
Assert::same('BASELINE',$db->scalar('SELECT stage FROM monitor_runs WHERE monitor_run_id=?',[$id]),'first capture remains baseline');
Assert::same(1,$source->calls,'one fixture capture');Assert::same(0,$model->calls,'baseline never calls model');
$clock->advanceDays(1);
Assert::same(303,$kernel->handle($request('GET','/console/monitor',[],$cookie))->status,'real session expires across source cadence day');
$cookie=$login($user);$csrf=$cookie[Csrf::COOKIE_NAME];
Assert::same(303,$kernel->handle($request('POST','/console/workspace',['csrf_token'=>$csrf,'workspace_id'=>$scope['workspace_id']],$cookie))->status,'fresh session reselects workspace after day boundary');
Assert::same(303,$post(['action'=>'start','mission_id'=>$mission,'idempotency_key'=>'http-changed'])->status,'second run starts');
$id=$db->scalar('SELECT monitor_run_id FROM monitor_runs WHERE idempotency_key=?',['http-changed']);
Assert::same(303,$post(['action'=>'advance','run_id'=>$id])->status,'changed capture');Assert::same(303,$post(['action'=>'advance','run_id'=>$id])->status,'deterministic diff');
$page=$kernel->handle($request('GET','/console/monitor',[],$cookie,['run'=>$id,'tenant_id'=>$foreign['tenant_id'],'workspace_id'=>$foreign['workspace_id']]));
Assert::same(200,$page->status,'authorized private inspection');$contains('P01_SIGNAL_CLASSIFIER',$page->body,'exact prompt role visible to authorized user');
$contains('Owner-provided, unverified',$page->body,'selected synthetic private profile in request');$contains('no-store',$page->headers['Cache-Control'],'private response not cached');
Assert::false(str_contains($page->headers['Content-Security-Policy'],'script-src'),'workbench keeps scriptless CSP');
preg_match('/name="request_digest" value="([a-f0-9]{64})"/',$page->body,$m);Assert::true(isset($m[1]),'rendered form binds actual prepared request');$digest=$m[1]??'';if($digest==='')throw new RuntimeException('Expected bound model preview unavailable; inspect sanitized test assertions');
Assert::same(409,$post(['action'=>'advance','run_id'=>$id],['request_digest'=>$digest])->status,'query request digest cannot confirm model request');
Assert::same(409,$post(['action'=>'advance','run_id'=>$id,'request_digest'=>str_repeat('0',64)])->status,'changed request digest refused');Assert::same(0,$model->calls,'request refusals dispatch no model');
Assert::same(303,$post(['action'=>'advance','run_id'=>$id,'request_digest'=>$digest])->status,'reviewed exact request advances');Assert::same(1,$model->calls,'one governed fixture model attempt');
$candidatePage=$kernel->handle($request('GET','/console/monitor',[],$cookie,['run'=>$id]));
$contains('observed_changes',$candidatePage->body,'actual stored candidate inspectable');
foreach ([[],['af_session'=>'invalid-token']] as $anonCookie) {
    $hidden=$kernel->handle($request('GET','/console/monitor',[],$anonCookie,['run'=>$id]));Assert::same(303,$hidden->status,'anonymous/invalid session cannot inspect existing run');
    foreach (['P01_SIGNAL_CLASSIFIER','observed_changes','Owner-provided, unverified',$digest] as $secret)Assert::false(str_contains($hidden->body,$secret),'no private prompt/candidate disclosure');
}
Seed::membership($db,$clock,$foreign['tenant_id'],$foreign['workspace_id'],$foreign['operator_id'],$role['role_id']);
$foreignCookie=$login(Seed::platformUser($db,$clock,$foreign['tenant_id'],$foreign['operator_id'],'foreign-monitor@example.test'));$foreignCsrf=$foreignCookie[Csrf::COOKIE_NAME];
Assert::same(303,$kernel->handle($request('POST','/console/workspace',['csrf_token'=>$foreignCsrf,'workspace_id'=>$foreign['workspace_id']],$foreignCookie))->status,'foreign user selects only own workspace');
$hidden=$kernel->handle($request('GET','/console/monitor',[],$foreignCookie,['run'=>$id,'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'operator_id'=>$scope['operator_id']]));
Assert::same(403,$hidden->status,'foreign session cannot override scope through query');Assert::false(str_contains($hidden->body,'observed_changes'),'foreign user sees no candidate');Assert::false(str_contains($hidden->body,'Owner-provided, unverified'),'foreign user sees no profile');
$denied=$kernel->handle($request('POST','/console/monitor',['csrf_token'=>$foreignCsrf,'action'=>'stop','run_id'=>$id,'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'operator_id'=>$scope['operator_id']],$foreignCookie));
Assert::false($denied->status===303,'foreign body scope cannot stop run');Assert::same('ANALYZED',$db->scalar('SELECT stage FROM monitor_runs WHERE monitor_run_id=?',[$id]),'foreign attempt leaves stage intact');
$queryOnly=$post(['action'=>'stop'],['run_id'=>$id]);Assert::same(409,$queryOnly->status,'query run id cannot stop');
$beforeCalls=$model->calls;$beforeCaptures=$source->calls;
Assert::same(303,$post(['action'=>'stop','run_id'=>$id])->status,'authorized real service stops');Assert::same('STOPPED',$db->scalar('SELECT stage FROM monitor_runs WHERE monitor_run_id=?',[$id]),'stopped journal state persists');
Assert::same(409,$post(['action'=>'advance','run_id'=>$id,'request_digest'=>$digest])->status,'stopped run cannot advance');Assert::same($beforeCalls,$model->calls,'stop/retry invokes no model');Assert::same($beforeCaptures,$source->calls,'stop/retry fetches no source');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM released_briefings'),'HTTP candidate/stop creates no release');
$inactive=$makeKernel(MonitorFactory::inactive($db,$clock,$f['evidence_store'],$f['secret_box']));
$new=$value($service->startConfigured($scope,$mission,'http-inactive'));
$refused=$inactive->handle($request('POST','/console/monitor',['csrf_token'=>$csrf,'action'=>'advance','run_id'=>$new['monitor_run_id']],$cookie));
Assert::same(409,$refused->status,'deployed inactive composition refuses fixture execution');$contains('MONITOR_RUNTIME_MODE_MISMATCH',$refused->body,'inactivity is explicit, not empty success');
Assert::same('NEW',$db->scalar('SELECT stage FROM monitor_runs WHERE monitor_run_id=?',[$new['monitor_run_id']]),'inactive attempt leaves run unexecuted');
Assert::same($beforeCalls,$model->calls,'inactive composition makes no fixture model call');Assert::same($beforeCaptures,$source->calls,'inactive composition makes no fixture source call');
// Native run creation itself grants no native execution. Use a fresh bounded definition identity.
$native=$value((new RunJournal($db,$clock))->create($scope,$definition,'http-native-inactive','NATIVE'));
$refused=$inactive->handle($request('POST','/console/monitor',['csrf_token'=>$csrf,'action'=>'advance','run_id'=>$native['monitor_run_id']],$cookie));
Assert::same(409,$refused->status,'native run cannot activate deployed factory');$contains('MONITOR_NATIVE_EXECUTION_INACTIVE',$refused->body,'native refusal explicit');
Assert::same('NEW',$db->scalar('SELECT stage FROM monitor_runs WHERE monitor_run_id=?',[$native['monitor_run_id']]),'native attempt has no execution transition');
$logout=$kernel->handle($request('POST','/logout',['csrf_token'=>$csrf],$cookie));Assert::same(303,$logout->status,'real logout');
Assert::same(303,$kernel->handle($request('GET','/console/monitor',[],$cookie,['run'=>$id]))->status,'revoked session cannot inspect retained artifacts');
