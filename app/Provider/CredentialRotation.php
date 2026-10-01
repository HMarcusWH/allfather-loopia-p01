<?php

declare(strict_types=1);

namespace Allfather\Loopia\Provider;

use Allfather\Loopia\Core\{Clock,Digest,Ids,OperatorScope,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * Tenant-wide, bounded master-key rewrap.
 *
 * Rewrap changes encryption-at-rest only: provider_credential_id, provider, label and lifecycle
 * status are preserved. credential_digest changes because it is an HMAC under the master key.
 * Immutable execution grants are never rewritten or re-admitted; any grant bound to the old digest
 * becomes stale and a separately reviewed successor is required.
 */
final class CredentialRotation
{
    public const MAX_BATCH = 25;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly KeyRing $keys,
    ) {
    }

    public function status(array $scope): Outcome
    {
        $scope = OperatorScope::normalize($scope) ?? [];
        if (!$this->mayManage($scope)) {
            return Outcome::refuse('PROVIDER_ROTATION_MANAGEMENT_DENIED');
        }

        $counts = [];
        foreach ($this->db->all(
            'SELECT key_version,status,COUNT(*) AS n
             FROM provider_credentials
             WHERE tenant_id=?
             GROUP BY key_version,status
             ORDER BY key_version,status',
            [$scope['tenant_id']],
        ) as $row) {
            $version = (int) $row['key_version'];
            $counts[(string) $version][(string) $row['status']] = (int) $row['n'];
        }

        $pendingIds = array_map(
            'strval',
            array_column(
                $this->db->all(
                    'SELECT provider_credential_id
                     FROM provider_credentials
                     WHERE tenant_id=? AND key_version<>?
                     ORDER BY provider_credential_id',
                    [$scope['tenant_id'], $this->keys->activeVersion()],
                ),
                'provider_credential_id',
            ),
        );
        $blockers = $this->blockingGrantIds($this->db, $scope['tenant_id'], $pendingIds);

        return Outcome::ok([
            'active_key_version' => $this->keys->activeVersion(),
            'available_key_versions' => $this->keys->versions(),
            'keyring_mode' => $this->keys->mode(),
            'credential_counts' => $counts,
            'pending_rewrap_count' => count($pendingIds),
            'blocked_by_execution_authority' => $blockers !== [],
            'blocking_execution_grant_ids' => $blockers,
        ]);
    }

    public function plan(array $scope, int $limit = self::MAX_BATCH): Outcome
    {
        $scope = OperatorScope::normalize($scope) ?? [];
        if (!$this->mayManage($scope)) {
            return Outcome::refuse('PROVIDER_ROTATION_MANAGEMENT_DENIED');
        }
        $limitCheck = self::validateLimit($limit);
        if (!$limitCheck->isOk()) {
            return $limitCheck;
        }

        $rows = $this->candidateRows($this->db, $scope['tenant_id'], $limit, false);
        $snapshot = $this->planSnapshot($scope['tenant_id'], $rows);
        $blockers = $this->blockingGrantIds(
            $this->db,
            $scope['tenant_id'],
            array_column($snapshot['rows'], 'provider_credential_id'),
        );

        return Outcome::ok($this->publicPlan($snapshot, $blockers));
    }

    public function apply(array $scope, string $planDigest, int $limit = self::MAX_BATCH): Outcome
    {
        $scope = OperatorScope::normalize($scope) ?? [];
        if (!$this->mayManage($scope)) {
            return Outcome::refuse('PROVIDER_ROTATION_MANAGEMENT_DENIED');
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $planDigest) !== 1) {
            return Outcome::refuse('PROVIDER_ROTATION_PLAN_DIGEST_INVALID');
        }
        $limitCheck = self::validateLimit($limit);
        if (!$limitCheck->isOk()) {
            return $limitCheck;
        }

        try {
            return $this->db->transaction(function (Database $tx) use (
                $scope,
                $planDigest,
                $limit,
            ): Outcome {
                // The SAME mutex used by CredentialStore::save and ExecutionGrantStore::admit.
                $tenant = $tx->one(
                    'SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE',
                    [$scope['tenant_id']],
                );
                if ($tenant === null) {
                    return Outcome::refuse('PROVIDER_ROTATION_TENANT_NOT_FOUND');
                }

                // Exact replay of an already committed batch is an idempotent success. Because the
                // batch transaction writes every selected row and event atomically, any event for
                // this digest means that complete inspected plan committed.
                $prior = $tx->all(
                    'SELECT rotation_batch_id,provider_credential_id,to_key_version
                     FROM provider_credential_rotation_events
                     WHERE tenant_id=? AND plan_digest=?
                     ORDER BY sequence_no,provider_credential_id',
                    [$scope['tenant_id'], $planDigest],
                );
                if ($prior !== []) {
                    $batches = array_values(array_unique(array_map(
                        'strval',
                        array_column($prior, 'rotation_batch_id'),
                    )));
                    if (count($batches) !== 1) {
                        return Outcome::failure('PROVIDER_ROTATION_EVENT_STATE_INVALID');
                    }

                    return Outcome::ok([
                        'rotation_batch_id' => $batches[0],
                        'plan_digest' => $planDigest,
                        'target_key_version' => (int) $prior[0]['to_key_version'],
                        'rotated_credential_ids' => array_values(array_map(
                            'strval',
                            array_column($prior, 'provider_credential_id'),
                        )),
                        'rotated_count' => count($prior),
                        'already_applied' => true,
                    ]);
                }

                $rows = $this->candidateRows($tx, $scope['tenant_id'], $limit, true);
                $snapshot = $this->planSnapshot($scope['tenant_id'], $rows);
                if (!hash_equals($snapshot['plan_digest'], $planDigest)) {
                    return Outcome::refuse(
                        'PROVIDER_ROTATION_PLAN_STALE',
                        ['current_plan_digest' => $snapshot['plan_digest']],
                    );
                }

                $credentialIds = array_column($snapshot['rows'], 'provider_credential_id');
                $blockers = $this->blockingGrantIds($tx, $scope['tenant_id'], $credentialIds);
                if ($blockers !== []) {
                    return Outcome::refuse(
                        'PROVIDER_ROTATION_EXECUTION_AUTHORITY_ACTIVE',
                        ['blocking_execution_grant_ids' => $blockers],
                    );
                }

                if ($rows === []) {
                    return Outcome::ok([
                        'rotation_batch_id' => null,
                        'plan_digest' => $planDigest,
                        'target_key_version' => $this->keys->activeVersion(),
                        'rotated_credential_ids' => [],
                        'rotated_count' => 0,
                        'already_applied' => false,
                    ]);
                }

                $batchId = Ids::new();
                $rotated = [];
                foreach ($rows as $row) {
                    $credentialId = (string) $row['provider_credential_id'];
                    $provider = (string) $row['provider'];
                    $fromVersion = (int) $row['key_version'];
                    $oldSecret = null;
                    $reopenedSecret = null;

                    try {
                        $opened = $this->keys->open(
                            $fromVersion,
                            (string) $row['ciphertext'],
                            (string) $row['nonce_hex'],
                            (string) $row['auth_tag_hex'],
                            $scope['tenant_id'],
                            $provider,
                        );
                        if (!$opened->isOk()) {
                            return Outcome::refuse(
                                $opened->reasonCode() === 'CREDENTIAL_KEY_VERSION_UNAVAILABLE'
                                    ? 'PROVIDER_ROTATION_OLD_KEY_UNAVAILABLE'
                                    : 'PROVIDER_ROTATION_OLD_CIPHERTEXT_INVALID',
                                ['provider_credential_id' => $credentialId, 'key_version' => $fromVersion],
                            );
                        }
                        $oldSecret = (string) $opened->value();

                        $oldFingerprint = $this->keys->fingerprint($fromVersion, $oldSecret);
                        if (
                            !$oldFingerprint->isOk()
                            || !hash_equals(
                                (string) $row['credential_digest'],
                                (string) $oldFingerprint->value(),
                            )
                        ) {
                            return Outcome::refuse(
                                'PROVIDER_ROTATION_OLD_DIGEST_MISMATCH',
                                ['provider_credential_id' => $credentialId],
                                'INTEGRITY',
                                'S3',
                            );
                        }

                        $sealed = $this->keys->seal($oldSecret, $scope['tenant_id'], $provider);
                        if ((int) $sealed['key_version'] !== $this->keys->activeVersion()) {
                            return Outcome::failure('PROVIDER_ROTATION_ACTIVE_VERSION_MISMATCH');
                        }

                        $reopened = $this->keys->open(
                            (int) $sealed['key_version'],
                            (string) $sealed['ciphertext'],
                            (string) $sealed['nonce_hex'],
                            (string) $sealed['auth_tag_hex'],
                            $scope['tenant_id'],
                            $provider,
                        );
                        if (!$reopened->isOk()) {
                            return Outcome::failure('PROVIDER_ROTATION_REWRAP_SELF_CHECK_FAILED');
                        }
                        $reopenedSecret = (string) $reopened->value();
                        if (!hash_equals($oldSecret, $reopenedSecret)) {
                            return Outcome::failure('PROVIDER_ROTATION_REWRAP_SELF_CHECK_FAILED');
                        }

                        $newFingerprint = $this->keys->fingerprint(
                            (int) $sealed['key_version'],
                            $reopenedSecret,
                        );
                        if (
                            !$newFingerprint->isOk()
                            || !hash_equals((string) $sealed['digest'], (string) $newFingerprint->value())
                        ) {
                            return Outcome::failure('PROVIDER_ROTATION_REWRAP_DIGEST_FAILED');
                        }

                        $updated = $tx->run(
                            'UPDATE provider_credentials
                             SET ciphertext=?,nonce_hex=?,auth_tag_hex=?,key_version=?,credential_digest=?
                             WHERE provider_credential_id=? AND tenant_id=? AND key_version=? AND credential_digest=?',
                            [
                                $sealed['ciphertext'],
                                $sealed['nonce_hex'],
                                $sealed['auth_tag_hex'],
                                $sealed['key_version'],
                                $sealed['digest'],
                                $credentialId,
                                $scope['tenant_id'],
                                $fromVersion,
                                $row['credential_digest'],
                            ],
                        );
                        if ($updated !== 1) {
                            return Outcome::refuse(
                                'PROVIDER_ROTATION_ROW_CHANGED',
                                ['provider_credential_id' => $credentialId],
                            );
                        }

                        $sequence = 1 + (int) $tx->scalar(
                            'SELECT COALESCE(MAX(sequence_no),0)
                             FROM provider_credential_rotation_events
                             WHERE provider_credential_id=?',
                            [$credentialId],
                        );
                        $tx->insert('provider_credential_rotation_events', [
                            'rotation_event_id' => Ids::new(),
                            'rotation_batch_id' => $batchId,
                            'sequence_no' => $sequence,
                            'schema_version' => '1',
                            'tenant_id' => $scope['tenant_id'],
                            'provider_credential_id' => $credentialId,
                            'rotation_kind' => 'MASTER_KEY_REWRAP',
                            'from_key_version' => $fromVersion,
                            'to_key_version' => (int) $sealed['key_version'],
                            'old_credential_digest' => (string) $row['credential_digest'],
                            'new_credential_digest' => (string) $sealed['digest'],
                            'old_sealed_payload_sha256' => self::sealedPayloadDigest($row),
                            'new_sealed_payload_sha256' => self::sealedPayloadDigest($sealed),
                            'plan_digest' => $planDigest,
                            'rotated_by_operator_id' => $scope['actor_id'],
                            'rotated_at' => $this->clock->now(),
                        ]);
                        $rotated[] = $credentialId;
                    } finally {
                        self::wipe($oldSecret);
                        self::wipe($reopenedSecret);
                    }
                }

                return Outcome::ok([
                    'rotation_batch_id' => $batchId,
                    'plan_digest' => $planDigest,
                    'target_key_version' => $this->keys->activeVersion(),
                    'rotated_credential_ids' => $rotated,
                    'rotated_count' => count($rotated),
                    'already_applied' => false,
                ]);
            });
        } catch (\Throwable) {
            // Never propagate DB/crypto exception text through the operator surface: it may contain
            // SQL details and is not needed to decide whether a rotation succeeded.
            return Outcome::failure('PROVIDER_ROTATION_FAILED');
        }
    }

    private static function validateLimit(int $limit): Outcome
    {
        return $limit >= 1 && $limit <= self::MAX_BATCH
            ? Outcome::ok($limit)
            : Outcome::refuse(
                'PROVIDER_ROTATION_BATCH_LIMIT_INVALID',
                ['min' => 1, 'max' => self::MAX_BATCH],
            );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function candidateRows(
        Database $db,
        string $tenantId,
        int $limit,
        bool $forUpdate,
    ): array {
        $sql =
            'SELECT provider_credential_id,provider,status,key_version,credential_digest,
                    ciphertext,nonce_hex,auth_tag_hex
             FROM provider_credentials
             WHERE tenant_id=? AND key_version<>?
             ORDER BY provider_credential_id
             LIMIT ' . $limit . ($forUpdate ? ' FOR UPDATE' : '');

        return $db->all($sql, [$tenantId, $this->keys->activeVersion()]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{tenant_id:string,target_key_version:int,rows:list<array<string,mixed>>,plan_digest:string}
     */
    private function planSnapshot(string $tenantId, array $rows): array
    {
        $snapshotRows = array_map(static fn (array $row): array => [
            'provider_credential_id' => (string) $row['provider_credential_id'],
            'provider' => (string) $row['provider'],
            'status' => (string) $row['status'],
            'from_key_version' => (int) $row['key_version'],
            'credential_digest' => (string) $row['credential_digest'],
            'sealed_payload_sha256' => self::sealedPayloadDigest($row),
        ], $rows);

        $body = [
            'schema_version' => '1',
            'kind' => 'PROVIDER_MASTER_KEY_REWRAP_PLAN',
            'tenant_id' => $tenantId,
            'target_key_version' => $this->keys->activeVersion(),
            'rows' => $snapshotRows,
        ];

        return $body + ['plan_digest' => Digest::canonicalDigest($body)];
    }

    /**
     * @param array{tenant_id:string,target_key_version:int,rows:list<array<string,mixed>>,plan_digest:string} $snapshot
     * @param list<string> $blockers
     */
    private function publicPlan(array $snapshot, array $blockers): array
    {
        $counts = [];
        foreach ($snapshot['rows'] as $row) {
            $version = (string) $row['from_key_version'];
            $counts[$version] = ($counts[$version] ?? 0) + 1;
        }
        ksort($counts, SORT_NUMERIC);

        return [
            'target_key_version' => $snapshot['target_key_version'],
            'candidate_count' => count($snapshot['rows']),
            'credential_ids' => array_column($snapshot['rows'], 'provider_credential_id'),
            'from_version_counts' => $counts,
            'plan_digest' => $snapshot['plan_digest'],
            'blocked_by_execution_authority' => $blockers !== [],
            'blocking_execution_grant_ids' => $blockers,
            'grants_execution' => false,
        ];
    }

    /**
     * Current or future-admitted grants whose immutable definition names one selected credential.
     *
     * @param list<string> $credentialIds
     * @return list<string>
     */
    private function blockingGrantIds(Database $db, string $tenantId, array $credentialIds): array
    {
        if ($credentialIds === []) {
            return [];
        }
        $selected = array_fill_keys($credentialIds, true);
        $now = $this->clock->now();
        $blocked = [];

        foreach ($db->all(
            'SELECT g.execution_grant_id,g.valid_until,g.definition_json,
                    (SELECT e.event_kind
                     FROM monitor_execution_grant_events e
                     WHERE e.execution_grant_id=g.execution_grant_id
                     ORDER BY e.sequence_no DESC LIMIT 1) AS event_kind
             FROM monitor_execution_grants g
             WHERE g.tenant_id=?
             ORDER BY g.execution_grant_id',
            [$tenantId],
        ) as $grant) {
            if (($grant['event_kind'] ?? null) !== 'ADMITTED' || $now >= (string) $grant['valid_until']) {
                continue;
            }
            try {
                $definition = json_decode(
                    (string) $grant['definition_json'],
                    true,
                    64,
                    JSON_THROW_ON_ERROR,
                );
                $credentialId = is_array($definition)
                    ? ($definition['credential_id'] ?? null)
                    : null;
            } catch (\Throwable) {
                // Execution-capable but malformed history is unknown in the dangerous direction.
                $blocked[] = (string) $grant['execution_grant_id'];
                continue;
            }

            if (is_string($credentialId) && isset($selected[$credentialId])) {
                $blocked[] = (string) $grant['execution_grant_id'];
            }
        }

        sort($blocked, SORT_STRING);

        return array_values(array_unique($blocked));
    }

    /** @param array<string,mixed> $row */
    private static function sealedPayloadDigest(array $row): string
    {
        return Digest::canonicalDigest([
            'ciphertext' => (string) $row['ciphertext'],
            'nonce_hex' => (string) $row['nonce_hex'],
            'auth_tag_hex' => (string) $row['auth_tag_hex'],
        ]);
    }

    private static function wipe(?string &$value): void
    {
        if ($value === null) {
            return;
        }
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
        } else {
            $value = '';
        }
        $value = null;
    }

    private function mayManage(array $scope): bool
    {
        foreach (['tenant_id', 'workspace_id', 'actor_id'] as $key) {
            if (!is_string($scope[$key] ?? null) || $scope[$key] === '') {
                return false;
            }
        }
        if (($scope['actor_type'] ?? '') !== 'OPERATOR') {
            return false;
        }

        // Successful inspection is read-only. Denials keep AccessGuard's normal evidence behavior.
        return (new AccessGuard($this->db, $this->clock))->evaluate(
            'OPERATOR',
            $scope['actor_id'],
            $scope['tenant_id'],
            $scope['workspace_id'],
            'tenant.administer',
            'PROVIDER_KEY_ROTATION',
        )['decision'] === 'ALLOW';
    }
}
