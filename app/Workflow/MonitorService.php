<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\ChangeDetection;
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\Provider\{ModelExecutor,ModelRequestBuilder};
use Allfather\Loopia\Substrate\M02\AccessGuard;

/** Manual, bounded composition. No stage creates activation, a permission, or a scheduler job. */
final class MonitorService implements MonitorOperations
{
    private const ROLES=['DIFFED'=>'P01_SIGNAL_CLASSIFIER','ANALYZED'=>'BATTLECARD_GENERATOR','DRAFTED'=>'MONITOR_REVIEW_CRITIC','ROUTING_PREPARED'=>'MONITOR_ROUTING_CRITIC'];
    private const OUTPUTS=['DIFFED'=>['classifier','ANALYZED'],'ANALYZED'=>['draft','DRAFTED'],'DRAFTED'=>['critic','REVIEW_REQUIRED'],'ROUTING_PREPARED'=>['routing_critic','ROUTING_REVIEW']];
    public function __construct(
        private readonly Database $db,private readonly Clock $clock,private readonly RunJournal $journal,
        private readonly MissionConfiguration $configuration,private readonly SourceCaptureStage $capture,
        private readonly ChangeDetection $diffs,private readonly ModelExecutor $models,
        private readonly RunArtifacts $artifacts,private readonly ReleaseStages $releases,
        private readonly ContextEnvelope $context,
        private readonly bool $fixtureRuntime=false,private readonly bool $nativeEnabled=false,
    ) {}

    public function list(array $scope): Outcome
    {
        $access=$this->authorize($scope,['mission.read']); if (!$access->isOk()) { return $access; }
        return Outcome::ok([
            'missions'=>$this->db->all('SELECT m.mission_id,m.title,m.status,c.execution_mode FROM monitor_mission_configs c JOIN missions m ON m.mission_id=c.mission_id WHERE c.tenant_id=? AND c.workspace_id=? ORDER BY c.created_at DESC,c.mission_id',[$scope['tenant_id'],$scope['workspace_id']]),
            'runs'=>$this->db->all('SELECT monitor_run_id,mission_id,execution_mode,stage,revision,created_at,updated_at FROM monitor_runs WHERE tenant_id=? AND workspace_id=? ORDER BY created_at DESC,monitor_run_id LIMIT 100',[$scope['tenant_id'],$scope['workspace_id']]),
        ]);
    }

    public function detail(array $scope,string $runId): Outcome
    {
        $access=$this->authorize($scope,['mission.read','evidence.read']); if (!$access->isOk()) { return $access; }
        $replay=$this->journal->replay($scope,$runId); if (!$replay->isOk()) { return $replay; }
        $result=$replay->value(); $run=$result['run']; $result['artifacts']=[];
        foreach (['classifier_request','classifier','draft_request','draft','critic_request','critic','routing_critic_request','routing_critic','human_review','routing_review'] as $name) {
            if (!isset($run['cursor'][$name.'_artifact'])) { continue; }
            $artifact=$this->artifacts->read($run,$run['cursor'][$name.'_artifact'],$name);
            if (!$artifact->isOk()) { return $artifact; }
            $result['artifacts'][]=['kind'=>$name,'digest'=>$run['cursor'][$name.'_artifact'],'payload'=>$artifact->value()];
        }
        $result['preview_request']=null;
        if (isset(self::ROLES[$run['stage']])) {
            $preview=$this->prepareModel($scope,$run);
            if ($preview->isOk()) { $result['preview_request']=$preview->value()->inspect(); }
            else { $result['preview_reason_code']=$preview->reasonCode(); }
        }
        $result['review_digest']=in_array($run['stage'],['REVIEW_REQUIRED','ROUTING_REVIEW'],true)?self::reviewDigest($run):null;
        $result['native_execution_enabled']=$this->nativeEnabled;
        $result['fixture_runtime']=$this->fixtureRuntime;
        // Current reliance is read from M10; a historical COMPLETE journal is not a current release grant.
        $result['current_reliance']=[];
        $ledger=new \Allfather\Loopia\Substrate\M10\DecisionLedger($this->db,$this->clock);
        foreach (['p01_release','routing_release'] as $key) {
            if (isset($run['cursor'][$key]['decision_record_id'])) { $result['current_reliance'][$key]=$ledger->currentReliance($run['cursor'][$key]['decision_record_id']); }
        }
        return Outcome::ok($result);
    }

    public function startConfigured(array $scope,string $missionId,string $idempotencyKey): Outcome
    {
        $access=$this->authorize($scope,['mission.read','mission.transition','evidence.read']); if (!$access->isOk()) { return $access; }
        $row=$this->db->one('SELECT * FROM monitor_mission_configs WHERE mission_id=? AND tenant_id=? AND workspace_id=?',[$missionId,$scope['tenant_id'],$scope['workspace_id']]);
        if ($row===null) { return Outcome::refuse('MONITOR_MISSION_NOT_CONFIGURED'); }
        $definition=json_decode($row['definition_json'],true,64,JSON_THROW_ON_ERROR);
        if (!hash_equals($row['definition_digest'],Digest::canonicalDigest(['definition'=>$definition,'mode'=>$row['execution_mode']]))) { return Outcome::refuse('MONITOR_CONFIGURATION_INTEGRITY_FAILED'); }
        $valid=$this->configuration->validate($scope,$definition,$row['execution_mode']); if (!$valid->isOk()) { return $valid; }
        return $this->journal->create($scope,$definition,$idempotencyKey,$row['execution_mode']);
    }

    public function advance(array $scope,string $runId,?string $expectedRequestDigest=null): Outcome
    {
        $access=$this->authorize($scope,['mission.transition','evidence.read','tool.invoke']); if (!$access->isOk()) { return $access; }
        return $this->journal->exclusive($scope,$runId,function(array $run) use($scope,$expectedRequestDigest):Outcome {
            $valid=$this->validateRun($scope,$run); if (!$valid->isOk()) { return $valid; }
            if ($run['execution_mode']==='NATIVE' && !$this->nativeEnabled) { return Outcome::refuse('MONITOR_NATIVE_EXECUTION_INACTIVE'); }
            if (($run['execution_mode']==='FIXTURE')!==$this->fixtureRuntime) { return Outcome::refuse('MONITOR_RUNTIME_MODE_MISMATCH'); }
            try {
                if ($run['stage']==='NEW') {
                    $captured=$this->capture->execute($scope,$run);
                    if (!$captured->isOk()) { return $this->block($scope,$run,$captured->reasonCode()); }
                    return $this->journal->advance($scope,$run,$captured->value()['before_snapshot_id']===null?'BASELINE':'CAPTURED',['capture'=>$captured->value()],'SOURCE_CAPTURED');
                }
                if ($run['stage']==='CAPTURED') {
                    $context=$this->contextFor($scope,$run); if (!$context->isOk()) { return $this->block($scope,$run,$context->reasonCode()); }
                    $diff=$this->diffs->detect(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'monitored_source_id'=>$run['definition']['monitored_source_id'],'before_snapshot_id'=>$run['cursor']['capture']['before_snapshot_id'],'after_snapshot_id'=>$run['cursor']['capture']['after_snapshot_id']]);
                    if (!$diff->isOk()) { return $this->block($scope,$run,$diff->reasonCode()); }
                    return $this->journal->advance($scope,$run,$diff->value()['change_significance']==='NONE'?'NO_CHANGE':'DIFFED',$run['cursor']+['diff'=>$diff->value()],'DIFF_CLASSIFIED');
                }
                if (isset(self::ROLES[$run['stage']])) { return $this->executeModel($scope,$run,$expectedRequestDigest); }
                if ($run['stage']==='P01_RELEASED') {
                    $access=$this->authorize($scope,['distribution.route']); if (!$access->isOk()) { return $access; }
                    $draft=$this->candidate($run,'draft'); if (!$draft->isOk()) { return $draft; }
                    return $this->db->transaction(function() use($scope,$run,$draft):Outcome {
                        $routing=$this->releases->prepareRouting($scope,$run,$draft->value()['candidate']);
                        if (!$routing->isOk()) { return $routing; }
                        return $this->journal->advance($scope,$run,'ROUTING_PREPARED',$run['cursor']+['routing'=>$routing->value()],'ROUTING_PROPOSED');
                    });
                }
                return Outcome::refuse('MONITOR_STAGE_REQUIRES_REVIEW_OR_IS_TERMINAL');
            } catch (\Throwable) {
                // An unknown transport outcome never becomes an automatic retry. Existing M01/M06
                // receipts remain authoritative; support can reconcile the run by its correlation ID.
                return $this->block($scope,$run,'MONITOR_STAGE_FAILED_RECONCILIATION_REQUIRED','FAILED');
            }
        });
    }

    public function review(array $scope,string $runId,array $review): Outcome
    {
        $access=$this->authorize($scope,['company_signal.review','evidence.read','mission.transition']); if (!$access->isOk()) { return $access; }
        return $this->journal->exclusive($scope,$runId,function(array $run) use($scope,$review):Outcome {
            $valid=$this->validateRun($scope,$run); if (!$valid->isOk()) { return $valid; }
            if (($run['execution_mode']==='FIXTURE')!==$this->fixtureRuntime) { return Outcome::refuse('MONITOR_RUNTIME_MODE_MISMATCH'); }
            if ($run['execution_mode']==='NATIVE' && !$this->nativeEnabled) { return Outcome::refuse('MONITOR_NATIVE_EXECUTION_INACTIVE'); }
            if (!in_array($run['stage'],['REVIEW_REQUIRED','ROUTING_REVIEW'],true)) { return Outcome::refuse('MONITOR_NOT_AWAITING_REVIEW'); }
            if (!is_string($review['candidate_digest']??null) || !hash_equals(self::reviewDigest($run),$review['candidate_digest'])) { return Outcome::refuse('MONITOR_REVIEW_CHANGED_RELOAD_REQUIRED'); }
            if (!in_array($review['decision']??null,['APPROVE','HOLD','REJECT'],true) || !is_string($review['assessment']??null) || mb_strlen(trim($review['assessment']))<20 || strlen($review['assessment'])>4000) { return Outcome::refuse('MONITOR_REVIEW_ASSESSMENT_REQUIRED'); }
            $kind=$run['stage']==='REVIEW_REQUIRED'?'human_review':'routing_review';
            $stored=$this->artifacts->save($run,$kind,['operator_id'=>$scope['operator_id'],'reviewed_at'=>$this->clock->now(),'decision'=>$review['decision'],'assessment'=>trim($review['assessment']),'candidate_digest'=>$review['candidate_digest'],'execution_mode'=>$run['execution_mode']]);
            if (!$stored->isOk()) { return $stored; }
            if ($review['decision']!=='APPROVE') { return $this->journal->advance($scope,$run,$review['decision']==='HOLD'?'BLOCKED':'STOPPED',$run['cursor']+[$kind.'_artifact'=>$stored->value()['digest'],'reason_code'=>'MONITOR_HUMAN_'.$review['decision']],'HUMAN_'.$review['decision']); }
            $context=$this->contextFor($scope,$run); if (!$context->isOk()) { return $context; }
            $classifier=$this->candidate($run,'classifier'); $draft=$this->candidate($run,'draft'); $critic=$this->candidate($run,$run['stage']==='ROUTING_REVIEW'?'routing_critic':'critic');
            foreach ([$classifier,$draft,$critic] as $candidate) {
                if (!$candidate->isOk()) { return $candidate; }
                if ($candidate->value()['context_digest']!==$context->value()['digest']) { return Outcome::refuse('MONITOR_REVIEW_CONTEXT_CHANGED'); }
            }
            if ($run['stage']==='ROUTING_REVIEW') { $access=$this->authorize($scope,['distribution.route']); if (!$access->isOk()) { return $access; } }
            return $this->db->transaction(function() use($scope,$run,$classifier,$draft,$critic,$stored,$kind,$context):Outcome {
                if ($run['stage']==='ROUTING_REVIEW') {
                    $this->db->one('SELECT redaction_rules_json FROM console_redaction_policy_projections WHERE tenant_id=? AND workspace_id=? AND target_org_role=? FOR UPDATE',[$scope['tenant_id'],$scope['workspace_id'],$run['definition']['target_org_role']]);
                    $request=$this->artifacts->read($run,$run['cursor']['routing_critic_request_artifact'],'routing_critic_request');
                    if (!$request->isOk()) { return $request; }
                    $input=json_decode($request->value()['body']['input'],true,128,JSON_THROW_ON_ERROR);
                    $current=$this->context->validateRoutingPackage($context->value(),$input['routing_package']??[],$draft->value()['candidate']);
                    if (!$current->isOk()) { return $current; }
                }
                $released=$run['stage']==='REVIEW_REQUIRED'
                    ?$this->releases->releaseP01($scope,$run,$classifier->value()['candidate'],$draft->value()['candidate'],$critic->value()['candidate'])
                    :$this->releases->releaseRouting($scope,$run,$critic->value()['candidate']);
                if (!$released->isOk()) { return $released; }
                $stage=$run['stage']==='REVIEW_REQUIRED'?'P01_RELEASED':'COMPLETE';
                $key=$run['stage']==='REVIEW_REQUIRED'?'p01_release':'routing_release';
                return $this->journal->advance($scope,$run,$stage,$run['cursor']+[$kind.'_artifact'=>$stored->value()['digest'],$key=>$released->value()],'HUMAN_APPROVED_INTERNAL_RELEASE');
            });
        });
    }

    public function stop(array $scope,string $runId): Outcome
    {
        $access=$this->authorize($scope,['mission.transition']); if (!$access->isOk()) { return $access; }
        return $this->journal->exclusive($scope,$runId,function(array $run) use($scope):Outcome {
            $integrity=$this->journal->replay($scope,$run['monitor_run_id']); if (!$integrity->isOk()) { return $integrity; }
            return $this->journal->advance($scope,$run,'STOPPED',$run['cursor']+['reason_code'=>'MONITOR_OPERATOR_STOP'],'OPERATOR_STOP');
        });
    }

    private function executeModel(array $scope,array $run,?string $expected): Outcome
    {
        $prepared=$this->prepareModel($scope,$run); if (!$prepared->isOk()) { return $prepared; }
        $request=$prepared->value();
        if ($expected===null || !hash_equals($request->digest(),$expected)) { return Outcome::refuse('MONITOR_REQUEST_INSPECTION_REQUIRED'); }
        [$name,$next]=self::OUTPUTS[$run['stage']];
        $requestArtifact=$this->artifacts->save($run,$name.'_request',$request->inspect()); if (!$requestArtifact->isOk()) { return $requestArtifact; }
        $command=$run['definition']['model_governance'][str_contains($name,'critic')?'critic':'author'];
        $command=array_merge($command,['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'calling_actor_type'=>'OPERATOR','calling_actor_id'=>$scope['operator_id'],'requested_action_class'=>'ANALYZE','idempotency_key'=>'monitor.model.'.$run['monitor_run_id'].'.'.$name,'correlation_id'=>$run['monitor_run_id'],'request_purpose_safe'=>'Bounded Monitor '.$name.' candidate']);
        $result=$this->models->execute($command,$request,$run['definition']['credential_id']);
        $cursor=$run['cursor']+[$name.'_request_artifact'=>$requestArtifact->value()['digest']];
        if (($result['kind']??'')!=='MODEL_CANDIDATE') {
            return $this->journal->advance($scope,$run,'BLOCKED',$cursor+['reason_code'=>$result['kind']==='MODEL_REPLAY'?'MONITOR_MODEL_REQUIRES_RECONCILIATION':($result['reason_code']??'MONITOR_MODEL_REFUSED')],'MODEL_REFUSED');
        }
        $payload=['role'=>self::ROLES[$run['stage']],'context_digest'=>$request->provenance()['context_digest'],'request_digest'=>$request->digest(),'candidate_digest'=>$result['candidate_digest'],'candidate'=>$result['candidate'],'provider_call_id'=>$result['provider_call_id'],'execution_mode'=>$result['execution_mode']];
        $stored=$this->artifacts->save($run,$name,$payload); if (!$stored->isOk()) { return $stored; }
        $cursor[$name.'_artifact']=$stored->value()['digest'];
        if ($name==='classifier' && $result['candidate']['status']==='NO_MATERIAL_CHANGE') { $next='NO_CHANGE'; }
        elseif (!str_contains($name,'critic') && $result['candidate']['status']==='HELD') { $next='BLOCKED'; $cursor['reason_code']='MONITOR_MODEL_HELD'; }
        return $this->journal->advance($scope,$run,$next,$cursor,'MODEL_CANDIDATE_RECORDED');
    }

    private function prepareModel(array $scope,array $run): Outcome
    {
        $context=$this->contextFor($scope,$run); if (!$context->isOk()) { return $context; }
        $role=self::ROLES[$run['stage']]; $prompt=ProductPromptPack::resolve($role); if (!$prompt->isOk()) { return $prompt; }
        $options=['max_output_tokens'=>$run['definition']['context_selection']['limits']['max_output_tokens']];
        if ($role!=='P01_SIGNAL_CLASSIFIER') {
            $prior=$this->candidate($run,$role==='BATTLECARD_GENERATOR'?'classifier':'draft'); if (!$prior->isOk()) { return $prior; }
            $options['stage_inputs']=['prior_candidate'=>array_intersect_key($prior->value(),array_flip(['role','context_digest','request_digest','candidate_digest','candidate']))];
            if ($role==='MONITOR_ROUTING_CRITIC') {
                $routing=$this->context->routingPackage($context->value(),$run['cursor']['routing']['role_distribution_briefing_id']);
                if (!$routing->isOk()) { return $routing; }
                $options['stage_inputs']['routing_package']=$routing->value();
            }
        }
        return ModelRequestBuilder::prepare($prompt->value(),$context->value(),$options);
    }
    private function contextFor(array $scope,array $run): Outcome
    {
        return $this->capture->contextFor($scope,$run['definition'],[$run['cursor']['capture']['before_snapshot_id'],$run['cursor']['capture']['after_snapshot_id']]);
    }
    private function candidate(array $run,string $name): Outcome
    {
        if (!isset($run['cursor'][$name.'_artifact'])) { return Outcome::refuse('MONITOR_CANDIDATE_MISSING'); }
        $artifact=$this->artifacts->read($run,$run['cursor'][$name.'_artifact'],$name); if (!$artifact->isOk()) { return $artifact; }
        $p=$artifact->value();
        if (!isset($p['candidate'],$p['candidate_digest'],$p['provider_call_id'],$p['request_digest'],$p['context_digest'])
            || !hash_equals(Digest::canonicalDigest($p['candidate']),$p['candidate_digest'])) { return Outcome::refuse('MONITOR_CANDIDATE_INTEGRITY_FAILED'); }
        $receipt=$this->db->one('SELECT r.candidate_digest,a.request_digest,a.context_digest FROM provider_model_results r JOIN provider_model_attempts a ON a.model_attempt_id=r.model_attempt_id WHERE r.provider_call_id=? AND r.outcome=? AND a.tenant_id=? AND a.workspace_id=? AND a.mission_id=?',[$p['provider_call_id'],'CANDIDATE',$run['tenant_id'],$run['workspace_id'],$run['mission_id']]);
        if ($receipt===null || $receipt['candidate_digest']!==$p['candidate_digest'] || $receipt['request_digest']!==$p['request_digest'] || $receipt['context_digest']!==$p['context_digest']) { return Outcome::refuse('MONITOR_CANDIDATE_PROVENANCE_INVALID'); }
        return $artifact;
    }
    private function validateRun(array $scope,array $run): Outcome
    {
        $replay=$this->journal->replay($scope,$run['monitor_run_id']); if (!$replay->isOk()) { return $replay; }
        return $this->configuration->validate($scope,$run['definition'],$run['execution_mode']);
    }
    private function block(array $scope,array $run,string $reason,string $stage='BLOCKED'): Outcome
    {
        return $this->journal->advance($scope,$run,$stage,$run['cursor']+['reason_code'=>$reason],'STAGE_REFUSED');
    }
    private function authorize(array $scope,array $permissions): Outcome
    {
        foreach (['tenant_id','workspace_id','operator_id'] as $key) { if (empty($scope[$key]) || !is_string($scope[$key])) { return Outcome::refuse('MONITOR_SCOPE_REQUIRED'); } }
        $access=new AccessGuard($this->db,$this->clock);
        foreach ($permissions as $permission) { if ($access->evaluate('OPERATOR',$scope['operator_id'],$scope['tenant_id'],$scope['workspace_id'],$permission,'MONITOR_RUN')['decision']!=='ALLOW') { return Outcome::refuse('MONITOR_ACCESS_DENIED'); } }
        return Outcome::ok();
    }
    private static function reviewDigest(array $run): string
    {
        return Digest::canonicalDigest(['run_id'=>$run['monitor_run_id'],'revision'=>$run['revision'],'stage'=>$run['stage'],'definition_digest'=>$run['definition_digest'],'cursor'=>$run['cursor']]);
    }
}
