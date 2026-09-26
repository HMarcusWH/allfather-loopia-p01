<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;
use Allfather\Loopia\Core\{Clock,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/** Private operator service only. Management requires explicit tenant.administer; no HTTP route. */
final class CredentialStore
{
    public function __construct(private readonly Database $db,private readonly Clock $clock,private readonly SecretBox $box) {}

    public function save(array $scope,string $secret,string $label='Monitor OpenAI'): Outcome
    {
        if (!$this->mayManage($scope)) return Outcome::refuse('PROVIDER_CREDENTIAL_MANAGEMENT_DENIED');
        if ($secret===''||strlen($secret)>1024||preg_match('/[\x00-\x20\x7f]/',$secret)||$label===''||mb_strlen($label)>120) return Outcome::refuse('PROVIDER_CREDENTIAL_INVALID');
        $sealed=$this->box->seal($secret,$scope['tenant_id'],'OPENAI');
        try {
            return $this->db->transaction(function(Database $tx) use($scope,$label,$sealed):Outcome {
                // Serialize tenant credential changes; exactly one active provider key survives.
                $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE',[$scope['tenant_id']]);
                $id=Ids::new();$now=$this->clock->now();
                $tx->run("UPDATE provider_credentials SET status='ROTATED',rotated_at=? WHERE tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",[$now,$scope['tenant_id']]);
                $tx->insert('provider_credentials',['provider_credential_id'=>$id,'schema_version'=>'1','tenant_id'=>$scope['tenant_id'],'provider'=>'OPENAI','label'=>$label,'ciphertext'=>$sealed['ciphertext'],'nonce_hex'=>$sealed['nonce_hex'],'auth_tag_hex'=>$sealed['auth_tag_hex'],'key_version'=>$sealed['key_version'],'credential_digest'=>$sealed['digest'],'status'=>'ACTIVE','created_at'=>$now,'created_by_operator_id'=>$scope['actor_id']]);
                return Outcome::ok(['provider_credential_id'=>$id,'credential_digest'=>$sealed['digest'],'status'=>'ACTIVE']);
            });
        } catch(\Throwable) { return Outcome::failure('PROVIDER_CREDENTIAL_SAVE_FAILED'); }
    }

    public function revoke(array $scope,string $credentialId): Outcome
    {
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
