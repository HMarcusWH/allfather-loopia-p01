<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;
use Allfather\Loopia\Core\{Clock,Ids,OperatorScope,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/** Private operator service only. Management requires explicit tenant.administer; no HTTP route. */
final class CredentialStore
{
    public function __construct(private readonly Database $db,private readonly Clock $clock,private readonly SecretBox $box) {}

    /**
     * Seal and store a provider key. Saving ROTATES every other active OpenAI key of the tenant, so a
     * caller that passes `$expectedRotation` states exactly which credential identities it expects to
     * retire; any other set (a key added or retired concurrently, or a guess) refuses before a write.
     * Rotation never retargets a frozen mission configuration or grant: those stay bound to the old
     * credential's identity and digest and refuse until an explicit successor names the new one.
     *
     * @param null|list<string> $expectedRotation
     */
    public function save(array $scope,string $secret,string $label='Monitor OpenAI',?array $expectedRotation=null): Outcome
    {
        $scope=OperatorScope::normalize($scope)??[];
        if (!$this->mayManage($scope)) return Outcome::refuse('PROVIDER_CREDENTIAL_MANAGEMENT_DENIED');
        if ($secret===''||strlen($secret)>1024||preg_match('/[\x00-\x20\x7f]/',$secret)||$label===''||mb_strlen($label)>120) return Outcome::refuse('PROVIDER_CREDENTIAL_INVALID');
        $sealed=$this->box->seal($secret,$scope['tenant_id'],'OPENAI');
        try {
            return $this->db->transaction(function(Database $tx) use($scope,$label,$sealed,$expectedRotation):Outcome {
                // Serialize tenant credential changes; exactly one active provider key survives.
                $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE',[$scope['tenant_id']]);
                $rotated=$this->activeIds($tx,$scope['tenant_id']);
                if ($expectedRotation!==null) {
                    $expected=array_values(array_unique($expectedRotation));sort($expected,SORT_STRING);
                    if ($expected!==$rotated) return Outcome::refuse('PROVIDER_CREDENTIAL_ROTATION_NOT_ACKNOWLEDGED',['would_rotate'=>$rotated]);
                }
                $id=Ids::new();$now=$this->clock->now();
                $tx->run("UPDATE provider_credentials SET status='ROTATED',rotated_at=? WHERE tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",[$now,$scope['tenant_id']]);
                $tx->insert('provider_credentials',['provider_credential_id'=>$id,'schema_version'=>'1','tenant_id'=>$scope['tenant_id'],'provider'=>'OPENAI','label'=>$label,'ciphertext'=>$sealed['ciphertext'],'nonce_hex'=>$sealed['nonce_hex'],'auth_tag_hex'=>$sealed['auth_tag_hex'],'key_version'=>$sealed['key_version'],'credential_digest'=>$sealed['digest'],'status'=>'ACTIVE','created_at'=>$now,'created_by_operator_id'=>$scope['actor_id']]);
                return Outcome::ok(['provider_credential_id'=>$id,'credential_digest'=>$sealed['digest'],'status'=>'ACTIVE','rotated_credential_ids'=>$rotated,'retargets_frozen_missions'=>false]);
            });
        } catch(\Throwable) { return Outcome::failure('PROVIDER_CREDENTIAL_SAVE_FAILED'); }
    }

    /**
     * Credential identities and lifecycle only — never ciphertext, digest or secret — plus what a save
     * would rotate and which frozen configurations and grants still name each key.
     */
    public function describe(array $scope): Outcome
    {
        $scope=OperatorScope::normalize($scope)??[];
        if (!$this->mayManage($scope)) return Outcome::refuse('PROVIDER_CREDENTIAL_MANAGEMENT_DENIED');
        $rows=$this->db->all("SELECT provider_credential_id,label,status,created_at,rotated_at,revoked_at FROM provider_credentials WHERE tenant_id=? AND provider='OPENAI' ORDER BY created_at,provider_credential_id",[$scope['tenant_id']]);
        $bound=[];
        foreach($this->db->all('SELECT mission_id,definition_json FROM monitor_mission_configs WHERE tenant_id=? AND workspace_id=?',[$scope['tenant_id'],$scope['workspace_id']]) as $config){
            $id=json_decode($config['definition_json'],true,64,JSON_THROW_ON_ERROR)['credential_id']??null;
            if(is_string($id))$bound[$id]['mission_ids'][]=$config['mission_id'];
        }
        foreach($this->db->all('SELECT execution_grant_id,definition_json FROM monitor_execution_grants WHERE tenant_id=? AND workspace_id=?',[$scope['tenant_id'],$scope['workspace_id']]) as $grant){
            $id=json_decode($grant['definition_json'],true,64,JSON_THROW_ON_ERROR)['credential_id']??null;
            if(is_string($id))$bound[$id]['execution_grant_ids'][]=$grant['execution_grant_id'];
        }
        return Outcome::ok(['credentials'=>array_map(static fn(array $r):array=>$r+['bound_mission_ids'=>$bound[$r['provider_credential_id']]['mission_ids']??[],'bound_execution_grant_ids'=>$bound[$r['provider_credential_id']]['execution_grant_ids']??[]],$rows),'save_would_rotate'=>$this->activeIds($this->db,$scope['tenant_id'])]);
    }

    /** @return list<string> */
    private function activeIds(Database $db,string $tenantId): array
    {
        $ids=array_map('strval',array_column($db->all("SELECT provider_credential_id FROM provider_credentials WHERE tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",[$tenantId]),'provider_credential_id'));
        sort($ids,SORT_STRING);
        return $ids;
    }

    public function revoke(array $scope,string $credentialId): Outcome
    {
        $scope=OperatorScope::normalize($scope)??[];
        if (!$this->mayManage($scope)) return Outcome::refuse('PROVIDER_CREDENTIAL_MANAGEMENT_DENIED');
        $n=$this->db->run("UPDATE provider_credentials SET status='REVOKED',revoked_at=? WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",[$this->clock->now(),$credentialId,$scope['tenant_id']]);
        return $n===1?Outcome::ok(['status'=>'REVOKED']):Outcome::refuse('PROVIDER_CREDENTIAL_NOT_AVAILABLE');
    }

    /** Internal dispatch leaf. Caller must already hold consumed M06/M01 admission. */
    public function withCredential(string $tenantId,string $credentialId,callable $use): Outcome
    {
        $row=$this->db->one("SELECT ciphertext,nonce_hex,auth_tag_hex FROM provider_credentials WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",[$credentialId,$tenantId]);
        if ($row===null) return Outcome::refuse('PROVIDER_CREDENTIAL_NOT_AVAILABLE');
        $opened=$this->box->open($row['ciphertext'],$row['nonce_hex'],$row['auth_tag_hex'],$tenantId,'OPENAI');
        if (!$opened->isOk()) return Outcome::refuse('PROVIDER_CREDENTIAL_NOT_AVAILABLE');
        $secret=$opened->value();
        try { return Outcome::ok($use($secret)); }
        finally { if(function_exists('sodium_memzero')) sodium_memzero($secret); else $secret=''; }
    }

    private function mayManage(array $scope): bool
    {
        foreach(['tenant_id','workspace_id','actor_id'] as $key) if(empty($scope[$key]))return false;
        if(($scope['actor_type']??'')!=='OPERATOR')return false;
        return (new AccessGuard($this->db,$this->clock))->evaluate('OPERATOR',$scope['actor_id'],$scope['tenant_id'],$scope['workspace_id'],'tenant.administer','PROVIDER_CREDENTIAL',null,null,true)['decision']==='ALLOW';
    }
}
