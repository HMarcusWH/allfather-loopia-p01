<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Provider\ModelRegistration;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M05\SourceAdmission;

/** Freezes a composition of already-authorized owners. Does not grant roles, contracts or activation. */
final class MissionConfiguration
{
    public function __construct(private readonly Database $db,private readonly Clock $clock) {}

    public function configure(array $scope,array $definition,string $mode): Outcome
    {
        foreach (['mission.create','mission.activate','distribution.configure_targets'] as $permission) {
            if ((new AccessGuard($this->db,$this->clock))->evaluate('OPERATOR',$scope['operator_id'],$scope['tenant_id'],$scope['workspace_id'],$permission,'MONITOR_CONFIGURATION')['decision']!=='ALLOW') {
                return Outcome::refuse('MONITOR_CONFIGURATION_DENIED');
            }
        }
        $checked=$this->validate($scope,$definition,$mode);
        if (!$checked->isOk()) { return $checked; }
        $digest=Digest::canonicalDigest(['definition'=>$definition,'mode'=>$mode]);
        $prior=$this->db->one('SELECT definition_digest FROM monitor_mission_configs WHERE mission_id=? AND tenant_id=? AND workspace_id=?',[$definition['mission_id'],$scope['tenant_id'],$scope['workspace_id']]);
        if ($prior!==null) { return hash_equals($prior['definition_digest'],$digest)?Outcome::ok(['mission_id'=>$definition['mission_id'],'activation_granted'=>false]):Outcome::refuse('MONITOR_CONFIGURATION_FROZEN'); }
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
            $source=$this->db->one('SELECT ms.source_ref_id FROM monitored_sources ms JOIN competitors c ON c.competitor_id=ms.competitor_id WHERE ms.monitored_source_id=? AND ms.tenant_id=? AND ms.workspace_id=? AND ms.status=? AND c.status IN (?,?)',[$d['monitored_source_id'],$scope['tenant_id'],$scope['workspace_id'],'ACTIVE','ACTIVE','PAUSED']);
            if ($source===null || !is_string($d['capture_url']) || !str_starts_with($d['capture_url'],'https://')) { return Outcome::refuse('MONITOR_SOURCE_NOT_AVAILABLE'); }
            $context=$d['context_selection'];
            if (!is_array($context) || array_diff(array_keys($context),['mission_id','purpose','audience','limits','profile_evidence_ids','source_ids','selected_target_org_role'])!==[]
                || ($context['selected_target_org_role']??null)!==$d['target_org_role']) { return Outcome::refuse('MONITOR_CONTEXT_CONFIGURATION_INVALID'); }
            foreach (['max_input_bytes'=>65536,'max_output_tokens'=>2048,'max_model_calls'=>4,'max_observation_age_seconds'=>31536000] as $key=>$maximum) {
                if (!is_int($context['limits'][$key]??null) || $context['limits'][$key]<1 || $context['limits'][$key]>$maximum) { return Outcome::refuse('CONTEXT_LIMIT_INVALID'); }
            }
            if ($context['limits']['max_output_tokens']<16 || array_diff(array_keys($context['limits']),['max_input_bytes','max_output_tokens','max_model_calls','max_observation_age_seconds'])!==[]) { return Outcome::refuse('CONTEXT_LIMIT_INVALID'); }
            if (($context['mission_id']??null)!==$d['mission_id'] || ($context['purpose']??null)!==$mission['objective'] || empty($context['profile_evidence_ids']) || count($context['profile_evidence_ids'])>4 || !in_array($source['source_ref_id'],$context['source_ids']??[],true)) { return Outcome::refuse('MONITOR_CONTEXT_CONFIGURATION_INVALID'); }
            $allowed=json_decode($mission['allowed_source_scope_ids'],true,32,JSON_THROW_ON_ERROR);
            if (array_diff($context['source_ids'],$allowed)!==[]) { return Outcome::refuse('CONTEXT_SOURCE_OUTSIDE_MISSION'); }
            foreach ($context['profile_evidence_ids'] as $id) {
                $profile=$this->db->one('SELECT * FROM evidence_objects WHERE evidence_id=? AND tenant_id=? AND workspace_id=?',[$id,$scope['tenant_id'],$scope['workspace_id']]);
                if ($profile===null || $profile['evidence_type']!=='UPLOADED_SOURCE_CAPTURE' || !in_array($profile['evidence_status'],['CAPTURED','ASSESSED'],true) || !in_array($profile['source_ref_id'],$context['source_ids'],true)) { return Outcome::refuse('MONITOR_PROFILE_NOT_AVAILABLE'); }
                $ref=$this->db->one('SELECT * FROM source_references WHERE source_ref_id=?',[$profile['source_ref_id']]);
                if ($ref===null || !SourceAdmission::evaluate($ref,$scope['tenant_id'],$scope['workspace_id'],$profile['permitted_use_scope'],$profile['sensitivity_class'])['admitted']) { return Outcome::refuse('MONITOR_PROFILE_NOT_ADMISSIBLE'); }
            }
            if (!in_array($d['target_org_role'],\Allfather\Loopia\Product\P04\Distribution::TARGET_ORG_ROLES,true) || $this->db->one('SELECT 1 FROM console_redaction_policy_projections WHERE tenant_id=? AND workspace_id=? AND target_org_role=?',[$scope['tenant_id'],$scope['workspace_id'],$d['target_org_role']])===null) { return Outcome::refuse('DISTRIBUTION_TARGET_NOT_CONFIGURED'); }
            $commands=[$d['capture_governance'],$d['model_governance']['author']??[], $d['model_governance']['critic']??[]];
            foreach ($commands as $index=>$command) {
                if (!is_array($command) || array_diff(array_keys($command),['tenant_id','workspace_id','mission_id','assignment_id','valkyrie_id','capability_contract_id','governed_tool_id','governed_tool_operation_id','calling_actor_type','calling_actor_id','required_entitlement_permission','requested_action_class'])!==[]) { return Outcome::refuse('MONITOR_CONFIGURATION_INVALID'); }
                if (($command['mission_id']??null)!==$d['mission_id']) { return Outcome::refuse('MONITOR_ASSIGNMENT_MISMATCH'); }
                $assignment=$this->db->one('SELECT a.*,v.tenant_id,v.workspace_id,v.status AS agent_status FROM mission_assignments a JOIN valkyries v ON v.valkyrie_id=a.valkyrie_id WHERE a.assignment_id=? AND a.mission_id=? AND a.valkyrie_id=? AND a.capability_contract_id=?',[$command['assignment_id']??'',$d['mission_id'],$command['valkyrie_id']??'',$command['capability_contract_id']??'']);
                if ($assignment===null || $assignment['tenant_id']!==$scope['tenant_id'] || $assignment['workspace_id']!==$scope['workspace_id'] || $assignment['status']!=='ACTIVE' || $assignment['agent_status']!=='ACTIVE') { return Outcome::refuse('MONITOR_ASSIGNMENT_MISMATCH'); }
                if ($index>0 && !(new ModelRegistration($this->db,$this->clock))->validateDispatchIds($command['governed_tool_id']??'',$command['governed_tool_operation_id']??'')->isOk()) { return Outcome::refuse('MODEL_DESCRIPTOR_CONFLICT'); }
            }
            $author=$d['model_governance']['author']; $critic=$d['model_governance']['critic'];
            if ($author['valkyrie_id']===$critic['valkyrie_id'] || ($d['release_governance']['mission_id']??null)!==$d['mission_id'] || ($d['release_governance']['producing_valkyrie_id']??null)!==$author['valkyrie_id'] || ($d['release_governance']['capability_contract_id']??null)!==$author['capability_contract_id']) { return Outcome::refuse('MONITOR_REVIEW_SEPARATION_REQUIRED'); }
            return Outcome::ok();
        } catch (\Throwable) { return Outcome::refuse('MONITOR_CONFIGURATION_INVALID'); }
    }
}
