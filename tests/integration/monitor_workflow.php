<?php
declare(strict_types=1);
require_once __DIR__.'/../support/MonitorModelFixture.php';

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Ingestion\{FetchRequest,FetchResponse,FetchTransport,FixedHostResolver};
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Provider\FixtureModelTransport;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
use Allfather\Loopia\Tests\{Assert,Harness,MonitorModelFixture,Seed};
use Allfather\Loopia\Workflow\{MissionConfiguration,MonitorFactory,RunJournal};

$db=Harness::freshDb(); if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }
// Synthetic source expressly permits derived output. This is source-use authority only;
// the internal company-profile contribution and runtime still prohibit customer-visible output.
$clock=Harness::clock(); $f=MonitorModelFixture::create($db,$clock,true); $scope=$f['scope'];
$value=static function(Outcome $result,string $label):mixed {
    Assert::true($result->isOk(),$label.($result->isOk()?'':': '.$result->reasonCode()));
    if (!$result->isOk()) { throw new RuntimeException($label.': '.json_encode($result)); }
    return $result->value();
};
$role=Seed::role($db,$clock,'monitor-operator-workflow',['mission.read','mission.create','mission.activate','mission.transition','evidence.read','tool.invoke','company_signal.review','distribution.route','distribution.configure_targets']);
Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$role['role_id']);
$mission=$f['context_selection']['mission_id'];
$db->run('UPDATE missions SET mission_type=? WHERE mission_id=?',['COMPETITION_MONITORING',$mission]);
$f['context_selection']['purpose']=$db->scalar('SELECT objective FROM missions WHERE mission_id=?',[$mission]);
$f['context_selection']['limits']['max_model_calls']=4;
$policies=new PolicySets($db,$clock);$ledger=new DecisionLedger($db,$clock);
$p01=$value($policies->seed(PolicySets::P01_COMPETITION_RELEVANCE,$scope['tenant_id'],$scope['workspace_id']),'P01 policy');
$p04=$value($policies->seed(PolicySets::P04_DISTRIBUTION_ADMISSION,$scope['tenant_id'],$scope['workspace_id']),'P04 policy');
(new ReleaseOutput($db,$clock,$ledger))->seedVisibilityProfiles($scope['tenant_id']);
$value((new Distribution($db,$clock,$ledger))->configureRoleRedactionPolicy($scope+['target_org_role'=>'SALES','redaction_rules'=>[]]),'target configured explicitly');
$definition=[
    'mission_id'=>$mission,'monitored_source_id'=>$f['monitored_source_id'],'capture_url'=>$f['capture_url'],
    'capture_governance'=>$f['capture_governance'],'model_governance'=>['author'=>$f['author_governance'],'critic'=>$f['critic_governance']],
    'release_governance'=>['mission_id'=>$mission,'producing_valkyrie_id'=>$f['author_governance']['valkyrie_id'],'capability_contract_id'=>$f['author_governance']['capability_contract_id']],
    'context_selection'=>$f['context_selection'],'credential_id'=>$f['credential_id'],'target_org_role'=>'SALES',
    'p01_policy_id'=>$p01['arbiter_policy_set_id'],'p04_policy_id'=>$p04['arbiter_policy_set_id'],
];
$configured=$value((new MissionConfiguration($db,$clock))->configure($scope,$definition,'FIXTURE'),'configured existing authority');
Assert::same(false,$configured['activation_granted'],'configuration grants no activation');
$source=new class([$f['before_body'],$f['after_body']]) implements FetchTransport {
    public int $calls=0;
    public function __construct(private array $pages) {}
    public function send(FetchRequest $request):FetchResponse { return new FetchResponse(200,'text/plain; charset=utf-8',null,$this->pages[$this->calls++]??'Unexpected extra capture'); }
};
$model=new FixtureModelTransport(MonitorModelFixture::response(...));
$service=MonitorFactory::forFixture($db,$clock,$f['evidence_store'],$f['credential_store'],$source,new FixedHostResolver(['sources.example'=>['93.184.216.34']]),$model);
$baseline=$value($service->startConfigured($scope,$mission,'baseline'),'baseline started');
$baseline=$value($service->advance($scope,$baseline['monitor_run_id']),'baseline capture');
Assert::same('BASELINE',$baseline['stage'],'first observation is baseline, not an invented change');
Assert::same(0,$model->calls,'baseline invokes no model');
$clock->advanceDays(1);
$run=$value($service->startConfigured($scope,$mission,'changed'),'changed run started');$id=$run['monitor_run_id'];
Assert::same($id,$value($service->startConfigured($scope,$mission,'changed'),'idempotent start')['monitor_run_id'],'same start key converges');
$run=$value($service->advance($scope,$id),'second capture');Assert::same('CAPTURED',$run['stage'],'second observation captured');
$run=$value($service->advance($scope,$id),'bound diff');Assert::same('DIFFED',$run['stage'],'captured bytes produce a real diff');
foreach (['ANALYZED','DRAFTED','REVIEW_REQUIRED'] as $expected) {
    $detail=$value($service->detail($scope,$id),'exact request preview');
    Assert::true(isset($detail['preview_request']['body'],$detail['preview_request']['request_digest']),'prompt and full request are inspectable');
    $before=$model->calls;
    Assert::same('MONITOR_REQUEST_INSPECTION_REQUIRED',$service->advance($scope,$id,'stale-request')->reasonCode(),'unseen request never dispatches');
    Assert::same($before,$model->calls,'digest mismatch makes no model call');
    $run=$value($service->advance($scope,$id,$detail['preview_request']['request_digest']),'confirmed model stage');
    Assert::same($expected,$run['stage'],'expected model stage');
}
Assert::same(3,$model->calls,'three calls before first human review');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM released_briefings'),'models cannot release');
$detail=$value($service->detail($scope,$id),'review packet');
Assert::same('MONITOR_REVIEW_CHANGED_RELOAD_REQUIRED',$service->review($scope,$id,['decision'=>'APPROVE','assessment'=>'Reviewed source and company context; this is a bounded hypothesis.','candidate_digest'=>'stale'])->reasonCode(),'stale human review rejected');
$run=$value($service->review($scope,$id,['decision'=>'APPROVE','assessment'=>'Synthetic test review: compared both pages, the owner profile and uncertainties; internal hypothesis only.','candidate_digest'=>$detail['review_digest']]),'human reviewed P01 release');
Assert::same('P01_RELEASED',$run['stage'],'human-approved P01 release');
Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM released_briefings'),'exactly one P01 release');
$artifact=$db->one('SELECT a.uncertainty_summary_safe,p.customer_output_allowed,p.sensitivity_ceiling,p.profile_json FROM release_artifacts a JOIN release_source_use_profiles p ON p.release_source_use_profile_id=a.source_use_profile_id WHERE a.release_artifact_id=?',[$run['cursor']['p01_release']['release_artifact_id']]);
Assert::contains('HYPOTHESIS',$artifact['uncertainty_summary_safe'],'released artifact preserves mandatory hypothesis ceiling');
Assert::contains('Planen är inte ett genomfört evenemang.',$artifact['uncertainty_summary_safe'],'specific model uncertainty reaches M16');
Assert::same(0,(int)$artifact['customer_output_allowed'],'owner context constrains whole artifact to internal use');
Assert::same('INTERNAL_DEMO',$artifact['sensitivity_ceiling'],'profile sensitivity survives release');
$contributions=json_decode($artifact['profile_json'],true,128,JSON_THROW_ON_ERROR)['contributions'];
Assert::same(2,count($contributions),'source observation and company profile have distinct M16 source contributions');
Assert::true(in_array($f['context_selection']['profile_evidence_ids'][0],$contributions[1]['evidence_ids'],true),'profile evidence is retained in its own source contribution');
$run=$value($service->advance($scope,$id),'routing composition');Assert::same('ROUTING_PREPARED',$run['stage'],'routing needs its own model review');
$detail=$value($service->detail($scope,$id),'routing prompt preview');
Assert::true(str_contains(json_encode($detail['preview_request']),'SALES'),'routing prompt includes exact configured audience');
$run=$value($service->advance($scope,$id,$detail['preview_request']['request_digest']),'routing critic');
Assert::same('ROUTING_REVIEW',$run['stage'],'routing critic requires distinct human decision');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM released_distribution_outputs'),'routing critic cannot distribute');
$detail=$value($service->detail($scope,$id),'routing review packet');
$db->run('UPDATE console_redaction_policy_projections SET redaction_rules_json=? WHERE tenant_id=? AND workspace_id=? AND target_org_role=?',[json_encode(['CHANGED_REQUIRES_NEW_REVIEW']),$scope['tenant_id'],$scope['workspace_id'],'SALES']);
$staleRouting=$service->review($scope,$id,['decision'=>'APPROVE','assessment'=>'Synthetic review tries an obsolete policy and must be refused.','candidate_digest'=>$detail['review_digest']]);
Assert::true(!$staleRouting->isOk(),'policy change after routing critic prevents release');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM released_distribution_outputs'),'policy change cannot silently reuse prior approval');
$db->run('UPDATE console_redaction_policy_projections SET redaction_rules_json=? WHERE tenant_id=? AND workspace_id=? AND target_org_role=?',['[]',$scope['tenant_id'],$scope['workspace_id'],'SALES']);
$run=$value($service->review($scope,$id,['decision'=>'APPROVE','assessment'=>'Synthetic test routing review: this exact internal summary is suitable for the configured SALES role.','candidate_digest'=>$detail['review_digest']]),'human reviewed P04 release');
Assert::same('COMPLETE',$run['stage'],'capture to internal routing completed');
Assert::same(4,$model->calls,'mission uses exactly four bounded fixture calls');
Assert::same(2,$source->calls,'exactly two source captures');
Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM released_distribution_outputs'),'one internal P04 release');
$replay=$value($service->detail($scope,$id),'read-only replay');
Assert::same('PASS',$replay['integrity'],'run history verifies');
Assert::same(false,$replay['reexecutes_model'],'replay is not re-execution');
Assert::same(4,$model->calls,'replay invokes no model');
Assert::same(2,$source->calls,'replay fetches no source');
Assert::same(10,count($replay['artifacts']),'all four exact requests/candidates and both human decisions retained');
$foreign=Seed::tenantWorkspace($db,$clock,'monitor-foreign');
Assert::same('MONITOR_ACCESS_DENIED',$service->detail($foreign,$id)->reasonCode(),'foreign operator cannot inspect private prompts');
$inactive=MonitorFactory::inactive($db,$clock,$f['evidence_store'],$f['secret_box']);
$new=$value($service->startConfigured($scope,$mission,'inactive-mode'),'separate run to verify runtime mode');
Assert::same('MONITOR_RUNTIME_MODE_MISMATCH',$inactive->advance($scope,$new['monitor_run_id'])->reasonCode(),'deployed inactive runtime cannot silently execute test fixtures');
