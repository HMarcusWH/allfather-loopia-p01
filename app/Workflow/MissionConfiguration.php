<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,OperatorScope,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\{Capture,CompanyContext};
use Allfather\Loopia\Provider\ModelRegistration;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M08\PolicySets;

/** Freezes a composition of already-authorized owners. Does not grant roles, contracts or activation. */
final class MissionConfiguration
{
    public function __construct(private readonly Database $db,private readonly Clock $clock) {}

    /**
     * Freeze one mission's definition. A mission has exactly one configuration for its lifetime; a
     * changed definition is a successor mission (see MonitorSetup), never a rewrite of this row.
     */
    public function configure(array $scope,array $definition,string $mode): Outcome
    {
        $scope=OperatorScope::normalize($scope);
        if ($scope===null) { return Outcome::refuse('MONITOR_CONFIGURATION_SCOPE_INVALID'); }
        foreach (['mission.create','mission.activate','distribution.configure_targets'] as $permission) {
            if ((new AccessGuard($this->db,$this->clock))->evaluate('OPERATOR',$scope['operator_id'],$scope['tenant_id'],$scope['workspace_id'],$permission,'MONITOR_CONFIGURATION')['decision']!=='ALLOW') {
                return Outcome::refuse('MONITOR_CONFIGURATION_DENIED');
            }
        }
        $checked=$this->validate($scope,$definition,$mode);
        if (!$checked->isOk()) { return $checked; }
        $digest=Digest::canonicalDigest(['definition'=>$definition,'mode'=>$mode]);
        $prior=$this->db->one('SELECT definition_digest FROM monitor_mission_configs WHERE mission_id=? AND tenant_id=? AND workspace_id=?',[$definition['mission_id'],$scope['tenant_id'],$scope['workspace_id']]);
        if ($prior!==null) { return hash_equals($prior['definition_digest'],$digest)?Outcome::ok(['mission_id'=>$definition['mission_id'],'definition_digest'=>$digest,'activation_granted'=>false,'reused'=>true]):Outcome::refuse('MONITOR_CONFIGURATION_FROZEN',['successor_required'=>true]); }
        try {
            $this->db->insert('monitor_mission_configs',[
                'mission_id'=>$definition['mission_id'],'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                'definition_json'=>json_encode($definition,JSON_THROW_ON_ERROR),'definition_digest'=>$digest,'execution_mode'=>$mode,
                'configured_by_operator_id'=>$scope['operator_id'],'created_at'=>$this->clock->now(),
            ]);
        } catch (\PDOException $error) { return Database::classify($error,'MONITOR_CONFIGURATION_ALREADY_EXISTS'); }
        return Outcome::ok(['mission_id'=>$definition['mission_id'],'definition_digest'=>$digest,'activation_granted'=>false]);
    }

    public function validate(array $scope,array $d,string $mode): Outcome
    {
        $scope=OperatorScope::normalize($scope);
        if ($scope===null) { return Outcome::refuse('MONITOR_CONFIGURATION_SCOPE_INVALID'); }
        try {
            $keys=['mission_id','monitored_source_id','capture_url','capture_governance','model_governance','release_governance','context_selection','credential_id','target_org_role','p01_policy_id','p04_policy_id'];
            if (array_diff(array_keys($d),$keys)!==[] || array_diff($keys,array_keys($d))!==[] || !in_array($mode,['FIXTURE','NATIVE'],true)) { return Outcome::refuse('MONITOR_CONFIGURATION_INVALID'); }
            foreach (['mission_id','monitored_source_id','credential_id','p01_policy_id','p04_policy_id'] as $key) {
                if (!is_string($d[$key]) || preg_match('/^[a-f0-9-]{36}$/D',$d[$key])!==1) { return Outcome::refuse('MONITOR_CONFIGURATION_INVALID'); }
            }
            if (!is_array($d['model_governance']) || array_diff(array_keys($d['model_governance']),['author','critic'])!==[]
                || !is_array($d['release_governance']) || array_diff(array_keys($d['release_governance']),['mission_id','producing_valkyrie_id','capability_contract_id'])!==[]) { return Outcome::refuse('MONITOR_CONFIGURATION_INVALID'); }
            $mission=$this->db->one('SELECT m.*,w.environment_class FROM missions m JOIN workspaces w ON w.workspace_id=m.workspace_id WHERE m.mission_id=? AND m.tenant_id=? AND m.workspace_id=? AND m.status=? AND w.status=?',[$d['mission_id'],$scope['tenant_id'],$scope['workspace_id'],'ACTIVE','ACTIVE']);
            if ($mission===null || $mission['mission_type']!=='COMPETITION_MONITORING') { return Outcome::refuse('MONITOR_MISSION_NOT_ACTIVE'); }
            if ($mode==='FIXTURE' && $mission['environment_class']!=='TEST') { return Outcome::refuse('MONITOR_FIXTURE_REQUIRES_TEST_SCOPE'); }
            if (!is_string($d['capture_url'])) { return Outcome::refuse('MONITOR_SOURCE_NOT_AVAILABLE'); }
            $sourceCurrent=Capture::currentPolicy($this->db,$this->clock,[
                'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                'monitored_source_id'=>$d['monitored_source_id'],'capture_url'=>$d['capture_url'],
            ]);
            if (!$sourceCurrent->isOk()) { return $sourceCurrent; }
            $sourceRefId=$sourceCurrent->value()['policy']['source_ref_id'];
            $context=$d['context_selection'];
            if (!is_array($context) || array_diff(array_keys($context),['mission_id','purpose','audience','limits','profile_evidence_ids','source_ids','selected_target_org_role'])!==[]
                || ($context['selected_target_org_role']??null)!==$d['target_org_role'] || !in_array($context['audience']??null,ContextEnvelope::AUDIENCES,true)) { return Outcome::refuse('MONITOR_CONTEXT_CONFIGURATION_INVALID'); }
            foreach (['max_input_bytes'=>65536,'max_output_tokens'=>2048,'max_model_calls'=>4,'max_observation_age_seconds'=>31536000] as $key=>$maximum) {
                if (!is_int($context['limits'][$key]??null) || $context['limits'][$key]<1 || $context['limits'][$key]>$maximum) { return Outcome::refuse('CONTEXT_LIMIT_INVALID'); }
            }
            if ($context['limits']['max_output_tokens']<16 || array_diff(array_keys($context['limits']),['max_input_bytes','max_output_tokens','max_model_calls','max_observation_age_seconds'])!==[]) { return Outcome::refuse('CONTEXT_LIMIT_INVALID'); }
            if (($context['mission_id']??null)!==$d['mission_id'] || ($context['purpose']??null)!==$mission['objective'] || empty($context['profile_evidence_ids']) || count($context['profile_evidence_ids'])>4 || !in_array($sourceRefId,$context['source_ids']??[],true)) { return Outcome::refuse('MONITOR_CONTEXT_CONFIGURATION_INVALID'); }
            $allowed=json_decode($mission['allowed_source_scope_ids'],true,32,JSON_THROW_ON_ERROR);
            if (array_diff($context['source_ids'],$allowed)!==[]) { return Outcome::refuse('CONTEXT_SOURCE_OUTSIDE_MISSION'); }
            foreach ($context['profile_evidence_ids'] as $id) {
                $profile=CompanyContext::read($this->db,(string)$id,$scope['tenant_id'],$scope['workspace_id']);
                if ($profile===null || !in_array($profile['source_ref_id'],$context['source_ids'],true)) { return Outcome::refuse('MONITOR_PROFILE_NOT_AVAILABLE'); }
            }
            // Frozen bindings must still name current in-scope authority at start/revalidation time.
            if ($this->db->one("SELECT 1 FROM provider_credentials WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",[$d['credential_id'],$scope['tenant_id']])===null) { return Outcome::refuse('MONITOR_CREDENTIAL_NOT_AVAILABLE'); }
            $policyOwner=new PolicySets($this->db,$this->clock);
            $p01=$policyOwner->current(PolicySets::P01_COMPETITION_RELEVANCE,$scope['tenant_id'],$scope['workspace_id'],$d['p01_policy_id']);
            $p04=$policyOwner->current(PolicySets::P04_DISTRIBUTION_ADMISSION,$scope['tenant_id'],$scope['workspace_id'],$d['p04_policy_id']);
            if (!$p01->isOk() || !$p04->isOk()) { return Outcome::refuse('MONITOR_POLICY_NOT_CURRENT'); }
            if (!in_array($d['target_org_role'],\Allfather\Loopia\Product\P04\Distribution::TARGET_ORG_ROLES,true) || $this->db->one("SELECT 1 FROM console_redaction_policy_projections WHERE tenant_id=? AND workspace_id=? AND target_org_role=? AND stale_status='CURRENT'",[$scope['tenant_id'],$scope['workspace_id'],$d['target_org_role']])===null) { return Outcome::refuse('DISTRIBUTION_TARGET_NOT_CONFIGURED'); }
            $commands=[$d['capture_governance'],$d['model_governance']['author']??[],$d['model_governance']['critic']??[]];
            $missionTools=json_decode((string)$mission['allowed_tool_ids'],true,32,JSON_THROW_ON_ERROR);
            $missionActions=json_decode((string)$mission['requested_action_classes'],true,32,JSON_THROW_ON_ERROR);
            foreach ($commands as $index=>$command) {
                if (!is_array($command) || array_diff(array_keys($command),['tenant_id','workspace_id','mission_id','assignment_id','valkyrie_id','capability_contract_id','governed_tool_id','governed_tool_operation_id','calling_actor_type','calling_actor_id','required_entitlement_permission','requested_action_class'])!==[]) { return Outcome::refuse('MONITOR_CONFIGURATION_INVALID'); }
                if (($command['mission_id']??null)!==$d['mission_id']) { return Outcome::refuse('MONITOR_ASSIGNMENT_MISMATCH'); }
                foreach (['tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],'calling_actor_id'=>$scope['operator_id']] as $field=>$expected) {
                    if (isset($command[$field]) && $command[$field]!==$expected) { return Outcome::refuse('MONITOR_ASSIGNMENT_MISMATCH'); }
                }
                if (isset($command['calling_actor_type']) && $command['calling_actor_type']!=='OPERATOR') { return Outcome::refuse('MONITOR_ASSIGNMENT_MISMATCH'); }
                $assignment=$this->db->one("SELECT a.*,v.tenant_id,v.workspace_id,v.status AS agent_status,c.tenant_id AS contract_tenant,c.workspace_id AS contract_workspace,c.status AS contract_status,c.allowed_action_classes
                    FROM mission_assignments a JOIN valkyries v ON v.valkyrie_id=a.valkyrie_id
                    JOIN capability_contracts c ON c.capability_contract_id=a.capability_contract_id
                    WHERE a.assignment_id=? AND a.mission_id=? AND a.valkyrie_id=? AND a.capability_contract_id=?",[$command['assignment_id']??'',$d['mission_id'],$command['valkyrie_id']??'',$command['capability_contract_id']??'']);
                if ($assignment===null || $assignment['tenant_id']!==$scope['tenant_id'] || $assignment['workspace_id']!==$scope['workspace_id'] || $assignment['status']!=='ACTIVE'
                    || $assignment['agent_status']!=='ACTIVE' || $assignment['contract_status']!=='ACTIVE'
                    || $assignment['contract_tenant']!==$scope['tenant_id'] || ($assignment['contract_workspace']!==null && $assignment['contract_workspace']!==$scope['workspace_id'])) {
                    return Outcome::refuse('MONITOR_ASSIGNMENT_MISMATCH');
                }
                $assignedTools=json_decode((string)$assignment['assigned_tool_ids'],true,32,JSON_THROW_ON_ERROR);
                $assignedActions=json_decode((string)$assignment['assigned_action_classes'],true,32,JSON_THROW_ON_ERROR);
                $contractActions=json_decode((string)$assignment['allowed_action_classes'],true,32,JSON_THROW_ON_ERROR);
                $action=$index===0?'OBSERVE':'ANALYZE';
                if (!in_array($command['governed_tool_id']??null,$assignedTools,true) || !in_array($command['governed_tool_id']??null,$missionTools,true)
                    || !in_array($action,$assignedActions,true) || !in_array($action,$missionActions,true) || !in_array($action,$contractActions,true)) {
                    return Outcome::refuse('MONITOR_ASSIGNMENT_MISMATCH');
                }
                $rights=$this->db->all("SELECT allowed_operations,tool_class,maximum_action_class,approval_required,frozen_artifact_required,evidence_capture_required
                    FROM tool_rights WHERE capability_contract_id=? AND governed_tool_id=? AND status='ACTIVE' ORDER BY tool_right_id",[$command['capability_contract_id'],$command['governed_tool_id']]);
                $operation=$this->db->scalar('SELECT operation_key FROM governed_tool_operations WHERE governed_tool_operation_id=? AND governed_tool_id=?',[$command['governed_tool_operation_id']??'',$command['governed_tool_id']??'']);
                $right=$rights[0]??null;
                $expectedOperation=$index===0?'source.read':ModelRegistration::OPERATION_KEY;
                $expectedClass=$index===0?'READ_ONLY_SOURCE':'INTERNAL_TRANSFORM';
                if (count($rights)!==1 || $right===null || !is_string($operation) || $operation!==$expectedOperation
                    || json_decode((string)$right['allowed_operations'],true,32,JSON_THROW_ON_ERROR)!==[$expectedOperation]
                    || $right['tool_class']!==$expectedClass || $right['maximum_action_class']!==$action
                    || (int)$right['approval_required']!==0 || (int)$right['frozen_artifact_required']!==0
                    || (int)$right['evidence_capture_required']!==1) {
                    return Outcome::refuse('MISSION_BROADENS_CONTRACT');
                }
                if ($index===0) {
                    $descriptor=$this->db->one("SELECT t.tool_key,o.operation_key FROM governed_tools t JOIN governed_tool_operations o ON o.governed_tool_id=t.governed_tool_id WHERE t.governed_tool_id=? AND o.governed_tool_operation_id=?",[$command['governed_tool_id']??'',$command['governed_tool_operation_id']??'']);
                    if ($descriptor===null || $descriptor['tool_key']!=='p01.source.read' || $descriptor['operation_key']!=='source.read'
                        || (isset($command['required_entitlement_permission']) && $command['required_entitlement_permission']!=='competition_monitoring.read')
                        || (isset($command['requested_action_class']) && $command['requested_action_class']!=='OBSERVE')) {
                        return Outcome::refuse('MONITOR_CAPTURE_DESCRIPTOR_CONFLICT');
                    }
                } else {
                    if (!(new ModelRegistration($this->db,$this->clock))->validateDispatchIds($command['governed_tool_id']??'',$command['governed_tool_operation_id']??'')->isOk()) { return Outcome::refuse('MODEL_DESCRIPTOR_CONFLICT'); }
                    if (($command['tenant_id']??null)!==$scope['tenant_id'] || ($command['workspace_id']??null)!==$scope['workspace_id']
                        || ($command['calling_actor_type']??null)!=='OPERATOR' || ($command['calling_actor_id']??null)!==$scope['operator_id']
                        || ($command['required_entitlement_permission']??null)!=='competition_monitoring.read' || ($command['requested_action_class']??null)!=='ANALYZE') {
                        return Outcome::refuse('MONITOR_ENTITLEMENT_BINDING_INVALID');
                    }
                }
            }
            $author=$d['model_governance']['author']; $critic=$d['model_governance']['critic'];
            if ($author['valkyrie_id']===$critic['valkyrie_id'] || ($d['release_governance']['mission_id']??null)!==$d['mission_id'] || ($d['release_governance']['producing_valkyrie_id']??null)!==$author['valkyrie_id'] || ($d['release_governance']['capability_contract_id']??null)!==$author['capability_contract_id']) { return Outcome::refuse('MONITOR_REVIEW_SEPARATION_REQUIRED'); }
            return Outcome::ok();
        } catch (\Throwable) { return Outcome::refuse('MONITOR_CONFIGURATION_INVALID'); }
    }
}
