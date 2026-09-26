<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;
use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M01\AdapterConformance;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M06\Registry;

/** Registers descriptors only. No credential, model activation, mission, role or tool right is granted. */
final class ModelRegistration
{
    public const TOOL_KEY='monitor.model.v1';
    public const OPERATION_KEY='monitor.model.infer';
    public const HOST_KEY='loopia-monitor-model-v1';
    public function __construct(private readonly Database $db,private readonly Clock $clock) {}
    /** Read-only narrowing check; does not grant or provision authority. */
    public function validateDispatchIds(string $toolId,string $operationId): Outcome
    {
        $row=$this->existing();
        if($row===null||$row['governed_tool_id']!==$toolId||$row['governed_tool_operation_id']!==$operationId)return Outcome::refuse('MODEL_DESCRIPTOR_CONFLICT');
        return $this->validateExisting($row);
    }
    public function ensure(array $scope): Outcome
    {
        foreach(['tenant_id','workspace_id','actor_type','actor_id'] as $k)if(empty($scope[$k]))return Outcome::refuse('MODEL_REGISTRATION_SCOPE_REQUIRED');
        if((new AccessGuard($this->db,$this->clock))->evaluate($scope['actor_type'],$scope['actor_id'],$scope['tenant_id'],$scope['workspace_id'],'runtime_host_binding.administer','MODEL_DESCRIPTOR',null,null,true)['decision']!=='ALLOW')return Outcome::refuse('MODEL_REGISTRATION_DENIED');
        $existing=$this->existing();
        if($existing!==null)return $this->validateExisting($existing);
        try {
            return $this->db->transaction(function(Database $tx):Outcome {
                $host=Ids::new();$adapter=Ids::new();$binding=Ids::new();$now=$this->clock->now();
                $tx->insert('runtime_host_instances',['runtime_host_instance_id'=>$host,'schema_version'=>'1','host_family'=>'LOOPIA_BOUNDED_MODEL','host_name'=>self::HOST_KEY,'host_instance_key'=>self::HOST_KEY,'host_version'=>'1.0.0','repository_reference_safe'=>'internal://monitor/model','licence_status_ref'=>'internal','integration_map_document_ref'=>'docs/BOUNDED_MODEL_BOUNDARY.md','environment_class'=>'TEST','declared_security_assumptions'=>json_encode(['NO_EXTERNAL_ACTION','MODEL_TRANSPORT_INACTIVE_BY_DEFAULT']),'status'=>'REGISTERED','created_at'=>$now]);
                $identity=['runtime_host_instance_id'=>$host,'adapter_family'=>'LOOPIA_BOUNDED_MODEL','adapter_name'=>self::HOST_KEY,'adapter_version'=>'1.0.0',...self::payloads()];
                $tx->insert('runtime_adapter_instances',['runtime_adapter_instance_id'=>$adapter,'schema_version'=>'1',...$identity,'identity_digest'=>AdapterConformance::identityDigest($identity),'status'=>'READY','created_at'=>$now]);
                $tx->insert('runtime_host_bindings',['binding_id'=>$binding,'schema_version'=>'1','runtime_host_instance_id'=>$host,'runtime_adapter_instance_id'=>$adapter,'binding_type'=>'TOOL_SURFACE','runtime_host_reference'=>self::HOST_KEY.':transform','runtime_host_version'=>'1.0.0','mapping_purpose'=>'Bounded monitor interpretation; no release authority.','permitted_operation_classes'=>'["ANALYZE"]','safety_classification'=>'SAFE_INTERNAL_TRANSFORM','status'=>'ACTIVE','tenant_id'=>null,'workspace_id'=>null,'activation_evidence_refs'=>'[]','activated_at'=>$now,'created_at'=>$now]);
                $registry=new Registry($tx,$this->clock);
                $tool=$registry->registerTool(['tool_key'=>self::TOOL_KEY,'display_name'=>'Bounded Monitor model','purpose_summary'=>'Interpret scoped approved inputs; produce an untrusted candidate only.','tool_class'=>'INTERNAL_TRANSFORM','risk_level'=>'T1','maximum_action_class'=>'ANALYZE','runtime_mapping_ref_id'=>$binding,'evidence_capture_required_by_default'=>false]);
                $registry->registerOperation(['governed_tool_id'=>$tool,'operation_key'=>self::OPERATION_KEY,'operation_summary'=>'One bounded structured model interpretation.','operation_class'=>'TRANSFORM','classified_action_class'=>'ANALYZE','requires_evidence_capture'=>false]);
                $tx->insert('runtime_host_tool_refs',['tool_ref_id'=>Ids::new(),'schema_version'=>'1','binding_id'=>$binding,'governed_tool_id'=>$tool,'runtime_host_tool_ref'=>self::HOST_KEY.':'.self::OPERATION_KEY,'tool_surface_type'=>'TRANSFORM','declared_operation_class'=>'ANALYZE','mapping_status'=>'ACTIVE','version'=>'1.0.0','created_at'=>$now]);
                return $this->validateExisting($this->existing()??[]);
            });
        } catch(\Throwable) {
            $raced=$this->existing();
            return $raced===null?Outcome::failure('MODEL_REGISTRATION_FAILED'):$this->validateExisting($raced);
        }
    }
    private static function payloads(): array
    {
        return ['descriptor_identity'=>Digest::canonicalize(['adapterId'=>self::HOST_KEY,'adapterVersion'=>'1.0.0']),'capability_manifest_identity'=>Digest::canonicalize(['capabilities'=>['BOUNDED_MODEL_TRANSFORM']]),'conformance_profile_identity'=>Digest::canonicalize(['profile'=>'NO_EXTERNAL_ACTION','version'=>'1'])];
    }
    private function existing(): ?array
    {
        return $this->db->one('SELECT t.*,o.governed_tool_operation_id,o.operation_key,o.operation_class,o.classified_action_class,o.status AS operation_status,o.requires_mission,o.requires_valkyrie_contract,o.requires_evidence_capture,o.requires_approval,o.requires_frozen_artifact,b.safety_classification,b.tenant_id AS binding_tenant,b.workspace_id AS binding_workspace,b.status AS binding_status,b.permitted_operation_classes,b.binding_type,a.*,a.status AS adapter_status,h.status AS host_status,h.host_instance_key,r.runtime_host_tool_ref,r.declared_operation_class,r.mapping_status,r.binding_id AS ref_binding_id FROM governed_tools t LEFT JOIN governed_tool_operations o ON o.governed_tool_id=t.governed_tool_id AND o.operation_key=? LEFT JOIN runtime_host_bindings b ON b.binding_id=t.runtime_mapping_ref_id LEFT JOIN runtime_adapter_instances a ON a.runtime_adapter_instance_id=b.runtime_adapter_instance_id LEFT JOIN runtime_host_instances h ON h.runtime_host_instance_id=b.runtime_host_instance_id LEFT JOIN runtime_host_tool_refs r ON r.governed_tool_id=t.governed_tool_id AND r.binding_id=b.binding_id WHERE t.tool_key=?',[self::OPERATION_KEY,self::TOOL_KEY]);
    }
    private function validateExisting(array $r): Outcome
    {
        $expected=['tool_key'=>self::TOOL_KEY,'tool_class'=>'INTERNAL_TRANSFORM','maximum_action_class'=>'ANALYZE','descriptor_version'=>'1.0.0','operation_key'=>self::OPERATION_KEY,'operation_class'=>'TRANSFORM','classified_action_class'=>'ANALYZE','operation_status'=>'ACTIVE','safety_classification'=>'SAFE_INTERNAL_TRANSFORM','binding_status'=>'ACTIVE','binding_type'=>'TOOL_SURFACE','adapter_family'=>'LOOPIA_BOUNDED_MODEL','adapter_name'=>self::HOST_KEY,'adapter_version'=>'1.0.0','adapter_status'=>'READY','host_status'=>'REGISTERED','host_instance_key'=>self::HOST_KEY,'runtime_host_tool_ref'=>self::HOST_KEY.':'.self::OPERATION_KEY,'declared_operation_class'=>'ANALYZE','mapping_status'=>'ACTIVE',...self::payloads()];
        foreach($expected as $k=>$v)if(($r[$k]??null)!==$v)return Outcome::refuse('MODEL_DESCRIPTOR_CONFLICT');
        // SELECT a.* shadows t.status; tool lifecycle is therefore separately checked.
        if($this->db->scalar('SELECT status FROM governed_tools WHERE governed_tool_id=?',[$r['governed_tool_id']])!=='ACTIVE'||$r['binding_tenant']!==null||$r['binding_workspace']!==null||$r['runtime_mapping_ref_id']!==$r['ref_binding_id']||json_decode($r['permitted_operation_classes'],true)!==['ANALYZE']||!AdapterConformance::verify($r))return Outcome::refuse('MODEL_DESCRIPTOR_CONFLICT');
        foreach(['approval_required','frozen_artifact_required','evidence_capture_required_by_default','requires_evidence_capture','requires_approval','requires_frozen_artifact'] as $k)if((int)$r[$k]!==0)return Outcome::refuse('MODEL_DESCRIPTOR_CONFLICT');
        if((int)$r['requires_mission']!==1||(int)$r['requires_valkyrie_contract']!==1)return Outcome::refuse('MODEL_DESCRIPTOR_CONFLICT');
        return Outcome::ok(['governed_tool_id'=>$r['governed_tool_id'],'governed_tool_operation_id'=>$r['governed_tool_operation_id'],'runtime_host_tool_ref'=>$r['runtime_host_tool_ref'],'operation_key'=>self::OPERATION_KEY,'model_activation_granted'=>false]);
    }
}
