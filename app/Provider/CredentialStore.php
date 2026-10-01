<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;

use Allfather\Loopia\Core\{Clock,Ids,OperatorScope,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/** Private operator service only. Management requires explicit tenant.administer; no HTTP route. */
final class CredentialStore
{
    private readonly KeyRing $keys;

    /**
     * SecretBox remains accepted for fixtures/backwards-compatible tests; production composition
     * uses KeyRing so every stored row resolves its exact key_version.
     */
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        SecretBox|KeyRing $keys,
    ) {
        $this->keys = $keys instanceof KeyRing ? $keys : KeyRing::single($keys);
    }

    /**
     * Seal and store a provider key. Saving ROTATES every other active OpenAI key of the tenant, so a
     * caller that passes $expectedRotation states exactly which credential identities it expects to
     * retire; any other set (a key added or retired concurrently, or a guess) refuses before a write.
     * Rotation never retargets a frozen mission configuration or grant: those stay bound to the old
     * credential's identity and digest and refuse until an explicit successor names the new one.
     *
     * @param null|list<string> $expectedRotation
     */
    public function save(
        array $scope,
        string $secret,
        string $label = 'Monitor OpenAI',
        ?array $expectedRotation = null,
    ): Outcome {
        $scope = OperatorScope::normalize($scope) ?? [];
        if (!$this->mayManage($scope)) {
            return Outcome::refuse('PROVIDER_CREDENTIAL_MANAGEMENT_DENIED');
        }
        if (
            $secret === ''
            || strlen($secret) > 1024
            || preg_match('/[\x00-\x20\x7f]/', $secret)
            || $label === ''
            || mb_strlen($label) > 120
        ) {
            return Outcome::refuse('PROVIDER_CREDENTIAL_INVALID');
        }

        $sealed = $this->keys->seal($secret, $scope['tenant_id'], 'OPENAI');
        try {
            return $this->db->transaction(function (Database $tx) use (
                $scope,
                $label,
                $sealed,
                $expectedRotation,
            ): Outcome {
                // Shared credential/rotation/admission mutex. P11 rotation and grant admission take
                // this same tenant row before inspecting current credential identity.
                $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$scope['tenant_id']]);
                $rotated = $this->activeIds($tx, $scope['tenant_id']);
                if ($rotated !== [] && $expectedRotation === null) {
                    return Outcome::refuse(
                        'PROVIDER_CREDENTIAL_ROTATION_NOT_ACKNOWLEDGED',
                        ['would_rotate' => $rotated],
                    );
                }
                if ($expectedRotation !== null) {
                    $expected = array_values(array_unique($expectedRotation));
                    sort($expected, SORT_STRING);
                    if ($expected !== $rotated) {
                        return Outcome::refuse(
                            'PROVIDER_CREDENTIAL_ROTATION_NOT_ACKNOWLEDGED',
                            ['would_rotate' => $rotated],
                        );
                    }
                }

                $id = Ids::new();
                $now = $this->clock->now();
                $tx->run(
                    "UPDATE provider_credentials
                     SET status='ROTATED',rotated_at=?
                     WHERE tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",
                    [$now, $scope['tenant_id']],
                );
                $tx->insert('provider_credentials', [
                    'provider_credential_id' => $id,
                    'schema_version' => '1',
                    'tenant_id' => $scope['tenant_id'],
                    'provider' => 'OPENAI',
                    'label' => $label,
                    'ciphertext' => $sealed['ciphertext'],
                    'nonce_hex' => $sealed['nonce_hex'],
                    'auth_tag_hex' => $sealed['auth_tag_hex'],
                    'key_version' => $sealed['key_version'],
                    'credential_digest' => $sealed['digest'],
                    'status' => 'ACTIVE',
                    'created_at' => $now,
                    'created_by_operator_id' => $scope['actor_id'],
                ]);

                return Outcome::ok([
                    'provider_credential_id' => $id,
                    'credential_digest' => $sealed['digest'],
                    'key_version' => $sealed['key_version'],
                    'status' => 'ACTIVE',
                    'rotated_credential_ids' => $rotated,
                    'retargets_frozen_missions' => false,
                ]);
            });
        } catch (\Throwable) {
            return Outcome::failure('PROVIDER_CREDENTIAL_SAVE_FAILED');
        }
    }

    /**
     * Credential identities/lifecycle only — never ciphertext, digest or secret — plus tenant-wide
     * mission/grant references. Master-key rotation is tenant-scoped, so reporting only the current
     * workspace would understate what an old credential still pins elsewhere in the tenant.
     */
    public function describe(array $scope): Outcome
    {
        $scope = OperatorScope::normalize($scope) ?? [];
        if (!$this->mayManage($scope)) {
            return Outcome::refuse('PROVIDER_CREDENTIAL_MANAGEMENT_DENIED');
        }

        $rows = $this->db->all(
            "SELECT provider_credential_id,label,status,key_version,created_at,rotated_at,revoked_at
             FROM provider_credentials
             WHERE tenant_id=? AND provider='OPENAI'
             ORDER BY created_at,provider_credential_id",
            [$scope['tenant_id']],
        );

        $bound = [];
        foreach ($this->db->all(
            'SELECT mission_id,workspace_id,definition_json
             FROM monitor_mission_configs
             WHERE tenant_id=?',
            [$scope['tenant_id']],
        ) as $config) {
            $id = json_decode($config['definition_json'], true, 64, JSON_THROW_ON_ERROR)['credential_id'] ?? null;
            if (is_string($id)) {
                $bound[$id]['missions'][] = [
                    'workspace_id' => (string) $config['workspace_id'],
                    'mission_id' => (string) $config['mission_id'],
                ];
            }
        }
        foreach ($this->db->all(
            'SELECT execution_grant_id,workspace_id,definition_json
             FROM monitor_execution_grants
             WHERE tenant_id=?',
            [$scope['tenant_id']],
        ) as $grant) {
            $id = json_decode($grant['definition_json'], true, 64, JSON_THROW_ON_ERROR)['credential_id'] ?? null;
            if (is_string($id)) {
                $bound[$id]['grants'][] = [
                    'workspace_id' => (string) $grant['workspace_id'],
                    'execution_grant_id' => (string) $grant['execution_grant_id'],
                ];
            }
        }

        return Outcome::ok([
            'credentials' => array_map(static function (array $row) use ($bound): array {
                $id = (string) $row['provider_credential_id'];

                $missions = $bound[$id]['missions'] ?? [];
                $grants = $bound[$id]['grants'] ?? [];
                usort($missions, static fn (array $a, array $b): int => [$a['workspace_id'], $a['mission_id']] <=> [$b['workspace_id'], $b['mission_id']]);
                usort($grants, static fn (array $a, array $b): int => [$a['workspace_id'], $a['execution_grant_id']] <=> [$b['workspace_id'], $b['execution_grant_id']]);
                $missionIds = array_values(array_map(
                    static fn (array $binding): string => $binding['mission_id'],
                    $missions,
                ));
                $grantIds = array_values(array_map(
                    static fn (array $binding): string => $binding['execution_grant_id'],
                    $grants,
                ));
                sort($missionIds, SORT_STRING);
                sort($grantIds, SORT_STRING);

                return $row + [
                    // Legacy flat-id fields stay available for existing operator/tests; P11 adds
                    // workspace-qualified tenant-wide bindings rather than silently changing shape.
                    'bound_mission_ids' => $missionIds,
                    'bound_execution_grant_ids' => $grantIds,
                    'bound_missions' => $missions,
                    'bound_execution_grants' => $grants,
                ];
            }, $rows),
            'save_would_rotate' => $this->activeIds($this->db, $scope['tenant_id']),
        ]);
    }

    /** @return list<string> */
    private function activeIds(Database $db, string $tenantId): array
    {
        $ids = array_map(
            'strval',
            array_column(
                $db->all(
                    "SELECT provider_credential_id
                     FROM provider_credentials
                     WHERE tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",
                    [$tenantId],
                ),
                'provider_credential_id',
            ),
        );
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function revoke(array $scope, string $credentialId): Outcome
    {
        $scope = OperatorScope::normalize($scope) ?? [];
        if (!$this->mayManage($scope)) {
            return Outcome::refuse('PROVIDER_CREDENTIAL_MANAGEMENT_DENIED');
        }

        try {
            return $this->db->transaction(function (Database $tx) use ($scope, $credentialId): Outcome {
                // Credential status affects grant currentness just as replacement/rewrap does.
                // Take the same tenant mutex so an admission cannot validate ACTIVE and append
                // ADMITTED while a revoke is crossing the boundary in another transaction.
                $tx->one(
                    'SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE',
                    [$scope['tenant_id']],
                );
                $n = $tx->run(
                    "UPDATE provider_credentials
                     SET status='REVOKED',revoked_at=?
                     WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",
                    [$this->clock->now(), $credentialId, $scope['tenant_id']],
                );

                return $n === 1
                    ? Outcome::ok(['status' => 'REVOKED'])
                    : Outcome::refuse('PROVIDER_CREDENTIAL_NOT_AVAILABLE');
            });
        } catch (\Throwable) {
            return Outcome::failure('PROVIDER_CREDENTIAL_REVOKE_FAILED');
        }
    }

    /** Internal dispatch leaf. Caller must already hold consumed M06/M01 admission. */
    public function withCredential(string $tenantId, string $credentialId, callable $use): Outcome
    {
        $row = $this->db->one(
            "SELECT provider,ciphertext,nonce_hex,auth_tag_hex,key_version,credential_digest
             FROM provider_credentials
             WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'",
            [$credentialId, $tenantId],
        );
        if ($row === null) {
            return Outcome::refuse('PROVIDER_CREDENTIAL_NOT_AVAILABLE');
        }

        $version = (int) $row['key_version'];
        $opened = $this->keys->open(
            $version,
            (string) $row['ciphertext'],
            (string) $row['nonce_hex'],
            (string) $row['auth_tag_hex'],
            $tenantId,
            (string) $row['provider'],
        );
        if (!$opened->isOk()) {
            return $opened->reasonCode() === 'CREDENTIAL_KEY_VERSION_UNAVAILABLE'
                ? Outcome::refuse('PROVIDER_CREDENTIAL_KEY_VERSION_UNAVAILABLE', ['key_version' => $version])
                : Outcome::refuse('PROVIDER_CREDENTIAL_NOT_AVAILABLE');
        }

        $secret = $opened->value();
        try {
            $fingerprint = $this->keys->fingerprint($version, $secret);
            if (
                !$fingerprint->isOk()
                || !hash_equals((string) $row['credential_digest'], (string) $fingerprint->value())
            ) {
                return Outcome::refuse(
                    'PROVIDER_CREDENTIAL_DIGEST_MISMATCH',
                    [],
                    'INTEGRITY',
                    'S3',
                );
            }

            return Outcome::ok($use($secret));
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            } else {
                $secret = '';
            }
        }
    }

    private function mayManage(array $scope): bool
    {
        foreach (['tenant_id', 'workspace_id', 'actor_id'] as $key) {
            if (empty($scope[$key])) {
                return false;
            }
        }
        if (($scope['actor_type'] ?? '') !== 'OPERATOR') {
            return false;
        }

        return (new AccessGuard($this->db, $this->clock))->evaluate(
            'OPERATOR',
            $scope['actor_id'],
            $scope['tenant_id'],
            $scope['workspace_id'],
            'tenant.administer',
            'PROVIDER_CREDENTIAL',
            null,
            null,
            true,
        )['decision'] === 'ALLOW';
    }
}
