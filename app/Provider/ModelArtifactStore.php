<?php
declare(strict_types=1);

namespace Allfather\Loopia\Provider;

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Storage\EvidenceStore;

/**
 * Durable private bytes for one validated model candidate.
 *
 * The provider provenance tables contain identities and digests. This class closes the gap between
 * "a candidate was returned" and "the exact candidate bytes can still be recovered after process
 * loss". The bytes are the canonical JSON bytes themselves, so candidate_digest and artifact_digest
 * are intentionally the same sha256.
 */
final class ModelArtifactStore
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly EvidenceStore $store,
    ) {}

    /** @param array<string,mixed> $candidate */
    public function persist(
        string $attemptId,
        string $toolRequestId,
        string $requestDigest,
        array $candidate,
        string $candidateDigest,
    ): Outcome {
        if (preg_match('/^[a-f0-9-]{36}$/D', $attemptId) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', $toolRequestId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $requestDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $candidateDigest) !== 1) {
            return Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_IDENTITY_INVALID');
        }

        $attempt = $this->db->one(
            'SELECT tenant_id,workspace_id,mission_id,tool_request_id,request_digest
             FROM provider_model_attempts
             WHERE model_attempt_id=? AND tool_request_id=?',
            [$attemptId, $toolRequestId],
        );
        if ($attempt === null || !hash_equals((string) $attempt['request_digest'], $requestDigest)) {
            return Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_ATTEMPT_MISMATCH');
        }

        $bytes = Digest::canonicalize($candidate);
        $actual = Digest::contentDigest($bytes);
        if (!hash_equals($candidateDigest, $actual)) {
            return Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_DIGEST_MISMATCH');
        }

        $stored = $this->store->put($bytes);
        if (!$stored->isOk()) {
            return $stored;
        }
        $artifactDigest = (string) $stored->value()['digest'];
        if (!hash_equals($candidateDigest, $artifactDigest)) {
            return Outcome::failure('MODEL_CANDIDATE_ARTIFACT_STORE_MISMATCH');
        }

        $row = [
            'model_attempt_id' => $attemptId,
            'tenant_id' => $attempt['tenant_id'],
            'workspace_id' => $attempt['workspace_id'],
            'mission_id' => $attempt['mission_id'],
            'tool_request_id' => $toolRequestId,
            'request_digest' => $requestDigest,
            'candidate_digest' => $candidateDigest,
            'artifact_digest' => $artifactDigest,
            'artifact_size' => strlen($bytes),
            'recorded_at' => $this->clock->now(),
        ];

        $existing = $this->db->one('SELECT * FROM provider_model_candidate_artifacts WHERE model_attempt_id=?', [$attemptId]);
        if ($existing !== null) {
            return self::sameBinding($existing, $row)
                ? Outcome::ok(self::safeBinding($existing) + ['deduplicated' => true])
                : Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_CONFLICT');
        }

        try {
            $this->db->insert('provider_model_candidate_artifacts', $row);
        } catch (\PDOException $error) {
            $winner = $this->db->one('SELECT * FROM provider_model_candidate_artifacts WHERE model_attempt_id=?', [$attemptId]);
            if ($winner !== null && self::sameBinding($winner, $row)) {
                return Outcome::ok(self::safeBinding($winner) + ['deduplicated' => true]);
            }

            return Database::classify($error, 'MODEL_CANDIDATE_ARTIFACT_CONFLICT');
        }

        return Outcome::ok(self::safeBinding($row) + ['deduplicated' => (bool) $stored->value()['deduplicated']]);
    }

    /**
     * Read and verify exact candidate bytes for an attempt in one scope.
     *
     * @return Outcome<array<string,mixed>>
     */
    public function read(
        string $tenantId,
        string $workspaceId,
        string $missionId,
        string $attemptId,
        ?string $expectedRequestDigest = null,
    ): Outcome {
        $row = $this->db->one(
            'SELECT ca.*,a.execution_mode,a.context_digest
             FROM provider_model_candidate_artifacts ca
             JOIN provider_model_attempts a ON a.model_attempt_id=ca.model_attempt_id
             WHERE ca.model_attempt_id=? AND ca.tenant_id=? AND ca.workspace_id=? AND ca.mission_id=?
               AND a.tenant_id=ca.tenant_id AND a.workspace_id=ca.workspace_id AND a.mission_id=ca.mission_id',
            [$attemptId, $tenantId, $workspaceId, $missionId],
        );
        if ($row === null) {
            return Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_NOT_FOUND');
        }
        if ($expectedRequestDigest !== null && !hash_equals((string) $row['request_digest'], $expectedRequestDigest)) {
            return Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_REQUEST_MISMATCH');
        }

        $stored = $this->store->get((string) $row['artifact_digest']);
        if (!$stored->isOk()) {
            return $stored;
        }
        $bytes = (string) $stored->value();
        if (strlen($bytes) !== (int) $row['artifact_size']) {
            return Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_SIZE_MISMATCH');
        }

        try {
            $candidate = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_INVALID');
        }
        if (!is_array($candidate)
            || !hash_equals((string) $row['candidate_digest'], Digest::canonicalDigest($candidate))
            || !hash_equals((string) $row['artifact_digest'], Digest::contentDigest($bytes))) {
            return Outcome::refuse('MODEL_CANDIDATE_ARTIFACT_INTEGRITY_FAILED');
        }

        return Outcome::ok(self::safeBinding($row) + [
            'candidate' => $candidate,
            'execution_mode' => $row['execution_mode'],
            'context_digest' => $row['context_digest'],
        ]);
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private static function sameBinding(array $a, array $b): bool
    {
        foreach (['model_attempt_id','tenant_id','workspace_id','mission_id','tool_request_id','request_digest','candidate_digest','artifact_digest'] as $key) {
            if ((string) ($a[$key] ?? '') !== (string) ($b[$key] ?? '')) {
                return false;
            }
        }

        return (int) ($a['artifact_size'] ?? -1) === (int) ($b['artifact_size'] ?? -2);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function safeBinding(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'model_attempt_id','tenant_id','workspace_id','mission_id','tool_request_id',
            'request_digest','candidate_digest','artifact_digest','artifact_size','recorded_at',
        ]));
    }
}
