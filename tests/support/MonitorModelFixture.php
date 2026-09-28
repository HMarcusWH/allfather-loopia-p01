<?php
declare(strict_types=1);
namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\{SourceCards,Registry as P01Registry};
use Allfather\Loopia\Provider\{CredentialStore,ModelRegistration,ModelResponse,PreparedModelRequest,SecretBox};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M03\{Eligibility,Registry as AgentRegistry};
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;

/** Explicit setup for offline composition tests. Does not capture, invoke, approve or release. */
final class MonitorModelFixture
{
    public static function create(Database $db,Clock $clock,bool $sourceCustomerOutputAllowed=false): array
    {
        $base=Seed::tenantWorkspace($db,$clock,'monitor-composition');
        $scope=$base+['actor_type'=>'OPERATOR','actor_id'=>$base['operator_id']];
        (new \Allfather\Loopia\Product\P04\Distribution($db,$clock,new \Allfather\Loopia\Substrate\M10\DecisionLedger($db,$clock)))->configureRoleRedactionPolicy(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'target_org_role'=>'SALES','redaction_rules'=>[]]);
        $role=Seed::role($db,$clock,'fixture-monitor-'.substr(Ids::new(),0,8),['tool.invoke','evidence.read','tenant.administer','runtime_host_binding.administer']);
        Seed::membership($db,$clock,$scope['tenant_id'],$scope['workspace_id'],$scope['operator_id'],$role['role_id']);
        $capture=Seed::governedToolChain($db,$clock,$scope);
        // This fixture predates P04 setup, so normalize its generic Seed chain to the exact Monitor
        // authority envelope before any MissionConfiguration is frozen.
        $db->run('UPDATE missions SET requested_action_classes=? WHERE mission_id=?',[
            json_encode(['OBSERVE','ANALYZE','RELEASE_INTELLIGENCE'],JSON_THROW_ON_ERROR),$capture['mission_id']
        ]);
        $db->run('UPDATE capability_contracts SET purpose_summary=?,allowed_action_classes=? WHERE capability_contract_id=?',[
            'p01.competition_monitoring',json_encode(['OBSERVE','ANALYZE','RELEASE_INTELLIGENCE'],JSON_THROW_ON_ERROR),$capture['capability_contract_id']
        ]);
        $model=self::value((new ModelRegistration($db,$clock))->ensure($scope));
        $m03=new Eligibility($db,$clock);$m04=new Proposals($db,$clock);
        $m03->grantToolRight($capture['capability_contract_id'],$model['governed_tool_id'],[ModelRegistration::OPERATION_KEY],'INTERNAL_TRANSFORM','ANALYZE');
        $authorAssignment=self::value($m04->assignToMission($capture['mission_id'],$capture['valkyrie_id'],$capture['capability_contract_id'],'MODEL_AUTHOR',[$model['governed_tool_id']],['ANALYZE'],$scope['operator_id']));
        $agents=new AgentRegistry($db,$clock);
        $critic=$agents->registerValkyrie($scope['tenant_id'],$scope['workspace_id'],'Separate fixture critic','REVIEW_CRITIC_VALKYRIE','Advisory review only',$scope['operator_id'],'ACTIVE');
        $criticContract=self::value($agents->issueContract($critic,$scope['tenant_id'],$scope['workspace_id'],'monitor.review_critic','REVIEW_CRITIC_VALKYRIE',['ANALYZE']))['capability_contract_id'];
        $m03->grantToolRight($criticContract,$model['governed_tool_id'],[ModelRegistration::OPERATION_KEY],'INTERNAL_TRANSFORM','ANALYZE');
        $criticAssignment=self::value($m04->assignToMission($capture['mission_id'],$critic,$criticContract,'MODEL_CRITIC',[$model['governed_tool_id']],['ANALYZE'],$scope['operator_id']));
        $url='https://sources.example/pricing';
        $card=(new SourceCards($db,$clock))->project(['tenant_id'=>$scope['tenant_id'],'source_name'=>'Synthetic monitor fixture','source_url_safe'=>$url,'source_type'=>'website','allowed_use_class'=>'derived_summary_allowed','license_class'=>'PUBLIC_WEB_ALLOWED','customer_output_allowed'=>$sourceCustomerOutputAllowed,'derived_summary_allowed'=>true,'robots_policy_checked'=>true,'terms_policy_checked'=>true,'review_status'=>'approved','caching_policy'=>'standard_cache','retention_class'=>'standard']);
        $registry=new P01Registry($db,$clock);
        $competitor=self::value($registry->registerCompetitor($scope['tenant_id'],$scope['workspace_id'],'Synthetic monitor competitor','DIRECT','OPERATOR',$scope['operator_id']))['competitor_id'];
        $monitored=self::value($registry->registerMonitoredSource($scope['tenant_id'],$scope['workspace_id'],$competitor,$card['source_ref_id'],$card['source_license_card_id'],'WEBSITE'))['monitored_source_id'];
        $store=new EvidenceStore(sys_get_temp_dir().'/allfather-monitor-fixture-'.Ids::new());$evidence=new EvidenceSpine($db,$clock);
        $profileSource=$evidence->registerSource($scope['tenant_id'],$scope['workspace_id'],'UPLOADED_ARTIFACT','internal://fixture/owner-profile','INTERNAL_ANALYSIS','INTERNAL_DEMO','ACTIVE_FOR_CAPTURE');
        $profileHash=self::value($store->put('Owner-provided, unverified: we provide event studio services.'))['digest'];
        $profile=self::value($evidence->capture(['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'source_ref_id'=>$profileSource,'evidence_type'=>'UPLOADED_SOURCE_CAPTURE','captured_content_reference_safe'=>'evidence://'.$profileHash,'content_hash'=>$profileHash,'capture_method'=>'OWNER_UPLOAD','permitted_use_scope'=>'INTERNAL_ANALYSIS','sensitivity_class'=>'INTERNAL_DEMO']))['evidence_id'];
        $sourceIds=[$profileSource,$card['source_ref_id']];
        $db->run('UPDATE missions SET allowed_source_scope_ids=? WHERE mission_id=?',[json_encode($sourceIds),$capture['mission_id']]);
        $box=SecretBox::fromMasterKey(base64_encode(random_bytes(32)));$credentials=new CredentialStore($db,$clock,$box);
        $credentialId=self::value($credentials->save($scope,'synthetic-offline-fixture-key'))['provider_credential_id'];
        $common=['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'mission_id'=>$capture['mission_id'],'calling_actor_type'=>'OPERATOR','calling_actor_id'=>$scope['operator_id'],'required_entitlement_permission'=>'competition_monitoring.read'];
        $captureGovernance=array_intersect_key($capture,array_flip(['mission_id','assignment_id','valkyrie_id','capability_contract_id','governed_tool_id','governed_tool_operation_id']))+['calling_actor_type'=>'OPERATOR','calling_actor_id'=>$scope['operator_id']];
        $modelBase=$common+array_intersect_key($model,array_flip(['governed_tool_id','governed_tool_operation_id']))+['requested_action_class'=>'ANALYZE'];
        return ['scope'=>$scope,'capture_chain'=>$capture,'model_descriptor'=>$model,'capture_governance'=>$captureGovernance,'author_governance'=>$modelBase+['assignment_id'=>$authorAssignment['assignment_id'],'valkyrie_id'=>$capture['valkyrie_id'],'capability_contract_id'=>$capture['capability_contract_id']],'critic_governance'=>$modelBase+['assignment_id'=>$criticAssignment['assignment_id'],'valkyrie_id'=>$critic,'capability_contract_id'=>$criticContract],'monitored_source_id'=>$monitored,'competitor_id'=>$competitor,'card'=>$card,'capture_url'=>$url,'before_body'=>'The planned seminar is November.','after_body'=>'The planned seminar is December.','evidence_store'=>$store,'secret_box'=>$box,'credential_store'=>$credentials,'credential_id'=>$credentialId,'context_selection'=>['mission_id'=>$capture['mission_id'],'audience'=>'SALES','selected_target_org_role'=>'SALES','limits'=>['max_input_bytes'=>65536,'max_output_tokens'=>2048,'max_model_calls'=>4,'max_observation_age_seconds'=>172800],'profile_evidence_ids'=>[$profile],'source_ids'=>$sourceIds]];
    }

    /** Hand-authored output fixture adapted only to exact selected evidence identities. Not model quality proof. */
    public static function candidate(PreparedModelRequest $request): array
    {
        $p=$request->provenance();$cases=json_decode(file_get_contents(__DIR__.'/../fixtures/monitor_prompt_offline_cases.json'),true,128,JSON_THROW_ON_ERROR)['cases'];
        $sample=null;foreach($cases as $case)if($case['role']===$p['role']&&$case['expected_refusal']===null){$sample=$case['candidate'];break;}
        if($sample===null)throw new \RuntimeException('No hand-authored role fixture');
        if($p['role']==='BATTLECARD_GENERATOR')$sample['recommended_internal_roles']=[$p['context']['envelope']['routing_target']['target_org_role']];
        $e=$p['context']['envelope']['evidence'];$observed=array_values(array_filter($e,static fn($r)=>$r['basis']==='CAPTURED_SOURCE_OBSERVATION'));$profile=array_values(array_filter($e,static fn($r)=>$r['basis']==='TENANT_PROVIDED'));
        return json_decode(strtr(json_encode($sample,JSON_THROW_ON_ERROR),['ev-before'=>$observed[0]['evidence_id'],'ev-after'=>$observed[1]['evidence_id'],'ev-profile'=>$profile[0]['evidence_id']]),true,128,JSON_THROW_ON_ERROR);
    }
    public static function response(PreparedModelRequest $request): ModelResponse
    {
        return new ModelResponse(200,json_encode(['status'=>'completed','output'=>[['type'=>'message','content'=>[['type'=>'output_text','text'=>json_encode(self::candidate($request),JSON_THROW_ON_ERROR)]]]],'usage'=>['input_tokens'=>90,'output_tokens'=>40]],JSON_THROW_ON_ERROR),'req_fixture',null,1);
    }
    private static function value(\Allfather\Loopia\Core\Outcome $outcome): mixed
    {
        if(!$outcome->isOk())throw new \RuntimeException('Monitor fixture setup refused: '.$outcome->reasonCode());
        return $outcome->value();
    }
}
