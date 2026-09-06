<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * Frozen outreach artifacts and single-use authorizations.
 *
 * NOTHING HERE DISPATCHES. What this models is the chain of gates a send would have to pass —
 * exact-payload integrity, single use, expiry, live consent, live source — built and proved while
 * there is no channel behind it. That ordering is the whole value: the gates are testable now,
 * against an adversary who is only the test suite.
 *
 * THE DIGEST CHAIN IS THE LOAD-BEARING IDEA. Four values must be equal for anything to proceed:
 *
 *     the digest the PROPOSAL froze at submission
 *   = the digest the APPROVAL bound to
 *   = the digest of the FROZEN artifact
 *   = the digest OBSERVED at consume
 *
 * Each link is checked at the moment it is crossed. Break any one and the chain refuses, so the
 * content a person approved is provably the content that would go out — and "provably" here means
 * a hash comparison, not a process claim.
 *
 * THE FACTS ARE BOUND TOO, NOT JUST THE PAYLOAD. An approval is for a specific recipient, channel,
 * payload kind and source. Binding only the digest would let one approval be reused to freeze the
 * same words for a DIFFERENT consented recipient — the payload would match and every content gate
 * would pass, while the person who received it had never been reviewed at all.
 *
 * SINGLE USE IS ATOMIC AND THERE IS NO RETRY. The consume flips ISSUED to CONSUMED in one guarded
 * UPDATE; only the caller that changes exactly one row proceeds. A failed outcome still consumes,
 * because a retry that reused an authorization would be a second contact nobody approved. Sending
 * again means a new approval, a new artifact, a new authorization.
 */
final class OutreachArtifacts
{
    /**
     * The default artifact and authorization lifetime: five minutes.
     *
     * Deliberately short. An authorization is permission to act NOW, granted by a person who was
     * looking at the artifact when they granted it; a long-lived one would outlive the situation
     * they judged.
     */
    public const DEFAULT_TTL_SECONDS = 300;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly Consent $consent,
    ) {
    }

    /**
     * Freeze the exact payload against a recorded human approval.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, recipient_pseudonym_ref: string,
     *     channel: string, payload_kind: string, exact_payload: mixed,
     *     exact_payload_reference_safe: string, source_decision_record_id: string,
     *     approval_request_id: string, approval_decision_id: string, ttl_seconds?: ?int
     * } $input
     */
    public function freeze(array $input): Outcome
    {
        $workspace = $this->db->one(
            'SELECT tenant_id FROM workspaces WHERE workspace_id = ?',
            [$input['workspace_id']]
        );
        if ($workspace === null) {
            return Outcome::refuse('WORKSPACE_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $workspace['tenant_id'] !== $input['tenant_id']) {
            return Outcome::refuse('WORKSPACE_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        // Re-checked here for callers that reach freeze directly rather than through the submit
        // gate. The firewall must hold at every entrance, not only the front one.
        if (PiiFirewall::containsRawPii($input['exact_payload_reference_safe'])
            || PiiFirewall::containsRawPiiDeep($input['exact_payload'])
        ) {
            return Outcome::refuse('PAYLOAD_CONTAINS_RAW_PII', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        // Consent RE-CROSS-CHECKED at freeze. It was checked at submission and re-derived at the
        // verdict; a withdrawal between the verdict and here would otherwise be honoured nowhere.
        $consent = $this->consent->evaluateForSend([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'recipient_pseudonym_ref' => $input['recipient_pseudonym_ref'],
            'channel' => $input['channel'],
        ]);
        if ($consent->isRefusal()) {
            return Outcome::refuse('CONSENT_NOT_PERMITTED', [
                'detail' => $consent->reasonCode(),
            ], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        $approval = $this->db->one(
            'SELECT d.decision, r.tenant_id, r.workspace_id,
                    r.exact_normalized_payload_digest AS approved_digest,
                    r.exact_payload_reference_safe AS approved_reference,
                    oep.recipient_pseudonym_ref, oep.channel, oep.payload_kind,
                    oep.exact_payload_reference_safe AS proposal_reference,
                    oep.source_decision_record_id
             FROM approval_decisions d
             JOIN approval_requests r ON r.approval_request_id = d.approval_request_id
             LEFT JOIN outreach_execution_proposals oep ON oep.proposal_id = r.proposal_id
             WHERE d.approval_decision_id = ? AND d.approval_request_id = ?',
            [$input['approval_decision_id'], $input['approval_request_id']]
        );
        if ($approval === null) {
            return Outcome::refuse('APPROVAL_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $approval['tenant_id'] !== $input['tenant_id']
            || (string) $approval['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('APPROVAL_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $approval['decision'] !== 'APPROVE_EXACT_ARTIFACT') {
            return Outcome::refuse('APPROVAL_NOT_APPROVED', [
                'decision' => (string) $approval['decision'],
            ], 'POLICY');
        }

        // LINK THREE OF THE DIGEST CHAIN: what is being frozen must equal what was approved.
        $payloadDigest = Digest::canonicalDigest($input['exact_payload']);
        if ($payloadDigest !== (string) $approval['approved_digest']) {
            return Outcome::refuse('APPROVAL_PAYLOAD_DIGEST_MISMATCH', [], 'INTEGRITY', 'S4', 'BLOCK_RELEASE');
        }
        if ($input['exact_payload_reference_safe'] !== (string) $approval['approved_reference']) {
            return Outcome::refuse('APPROVAL_PAYLOAD_REFERENCE_MISMATCH', [], 'INTEGRITY', 'S4');
        }

        $source = $this->db->one(
            'SELECT decision_record_id FROM decision_records
             WHERE decision_record_id = ? AND tenant_id = ? AND workspace_id = ?',
            [$input['source_decision_record_id'], $input['tenant_id'], $input['workspace_id']]
        );
        if ($source === null) {
            return Outcome::refuse('SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!$this->ledger->isCurrentlyRelied($input['source_decision_record_id'])) {
            return Outcome::refuse('SOURCE_NOT_RELEASED', [
                'current_reliance' => $this->ledger->currentReliance($input['source_decision_record_id']),
            ], 'PROVENANCE', 'S4');
        }

        // THE FACTS, not just the payload. Checked AFTER the digest and source gates so a malformed
        // payload still reports its own reason first.
        if ($approval['recipient_pseudonym_ref'] === null
            || $approval['channel'] === null
            || $approval['payload_kind'] === null
            || $approval['proposal_reference'] === null
            || $approval['source_decision_record_id'] === null
        ) {
            return Outcome::refuse('OUTREACH_PROPOSAL_BINDING_MISSING', [], 'PROVENANCE', 'S4');
        }
        if ((string) $approval['recipient_pseudonym_ref'] !== $input['recipient_pseudonym_ref']
            || (string) $approval['channel'] !== $input['channel']
            || (string) $approval['payload_kind'] !== $input['payload_kind']
            || (string) $approval['proposal_reference'] !== $input['exact_payload_reference_safe']
            || (string) $approval['source_decision_record_id'] !== $input['source_decision_record_id']
        ) {
            // The same words, for somebody else. Every content gate would have passed.
            return Outcome::refuse('OUTREACH_FACTS_MISMATCH', [], 'INTEGRITY', 'S4', 'BLOCK_RELEASE');
        }

        $frozenAt = $this->clock->now();
        $expiresAt = self::plusSeconds($frozenAt, $input['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS);
        $artifactId = Ids::new();
        $this->db->insert('frozen_outreach_artifacts', [
            'frozen_outreach_artifact_id' => $artifactId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'recipient_pseudonym_ref' => $input['recipient_pseudonym_ref'],
            'channel' => $input['channel'],
            'payload_kind' => $input['payload_kind'],
            'source_decision_record_id' => $input['source_decision_record_id'],
            // The EFFECTIVE consent entry as of now, not the one the caller cited.
            'consent_registry_entry_id' => $consent->value()['consent_registry_entry_id'],
            'approval_request_id' => $input['approval_request_id'],
            'approval_decision_id' => $input['approval_decision_id'],
            'exact_payload_reference_safe' => $input['exact_payload_reference_safe'],
            'payload_digest' => $payloadDigest,
            'hash_algorithm' => 'sha256',
            'artifact_status' => 'FROZEN',
            'frozen_at' => $frozenAt,
            'expires_at' => $expiresAt,
        ]);

        return Outcome::ok([
            'frozen_outreach_artifact_id' => $artifactId,
            'payload_digest' => $payloadDigest,
            'consent_registry_entry_id' => $consent->value()['consent_registry_entry_id'],
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Issue a single-use, short-TTL authorization for a frozen artifact.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, frozen_outreach_artifact_id: string,
     *     ttl_seconds?: ?int
     * } $input
     */
    public function issueAuthorization(array $input): Outcome
    {
        $artifact = $this->db->one(
            'SELECT tenant_id, workspace_id, approval_decision_id, artifact_status, expires_at
             FROM frozen_outreach_artifacts WHERE frozen_outreach_artifact_id = ?',
            [$input['frozen_outreach_artifact_id']]
        );
        if ($artifact === null) {
            return Outcome::refuse('ARTIFACT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $artifact['tenant_id'] !== $input['tenant_id']
            || (string) $artifact['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('ARTIFACT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $artifact['artifact_status'] !== 'FROZEN') {
            return Outcome::refuse('ARTIFACT_NOT_FROZEN', [
                'artifact_status' => (string) $artifact['artifact_status'],
            ], 'POLICY');
        }

        $now = $this->clock->now();
        if ($artifact['expires_at'] !== null && $now > (string) $artifact['expires_at']) {
            // The expiry is RECORDED on the way past, so a later reader sees that it lapsed rather
            // than that it was never used.
            $this->db->run(
                "UPDATE frozen_outreach_artifacts SET artifact_status = 'EXPIRED'
                 WHERE frozen_outreach_artifact_id = ? AND artifact_status = 'FROZEN'",
                [$input['frozen_outreach_artifact_id']]
            );

            return Outcome::refuse('ARTIFACT_EXPIRED', [], 'POLICY');
        }

        // The authorization may never outlive the artifact it authorizes. Requesting a longer TTL
        // silently clamps to the artifact's own expiry rather than extending it.
        $requested = self::plusSeconds($now, $input['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS);
        $validUntil = $artifact['expires_at'] !== null && (string) $artifact['expires_at'] < $requested
            ? (string) $artifact['expires_at']
            : $requested;

        $authorizationId = Ids::new();

        return $this->db->transaction(function (Database $tx) use (
            $input, $artifact, $authorizationId, $now, $validUntil
        ): Outcome {
            $tx->insert('outreach_execution_authorizations', [
                'outreach_execution_authorization_id' => $authorizationId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'frozen_outreach_artifact_id' => $input['frozen_outreach_artifact_id'],
                // The SAME approval decision the artifact carries. CHECK-pinned to single use.
                'approval_decision_id' => (string) $artifact['approval_decision_id'],
                'single_use_required' => 1,
                'authorization_status' => 'ISSUED',
                'issued_at' => $now,
                'expires_at' => $validUntil,
            ]);
            $tx->run(
                "UPDATE frozen_outreach_artifacts SET artifact_status = 'AUTHORIZED'
                 WHERE frozen_outreach_artifact_id = ?",
                [$input['frozen_outreach_artifact_id']]
            );

            return Outcome::ok([
                'outreach_execution_authorization_id' => $authorizationId,
                'valid_until' => $validUntil,
            ]);
        });
    }

    /**
     * Consume an authorization ONCE.
     *
     * @param array{outreach_execution_authorization_id: string, observed_payload_digest: string} $input
     */
    public function consume(array $input): Outcome
    {
        $auth = $this->db->one(
            'SELECT a.authorization_status, a.frozen_outreach_artifact_id, a.expires_at,
                    f.payload_digest
             FROM outreach_execution_authorizations a
             JOIN frozen_outreach_artifacts f
               ON f.frozen_outreach_artifact_id = a.frozen_outreach_artifact_id
             WHERE a.outreach_execution_authorization_id = ?',
            [$input['outreach_execution_authorization_id']]
        );
        if ($auth === null) {
            return Outcome::refuse('AUTHORIZATION_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $auth['authorization_status'] !== 'ISSUED') {
            return Outcome::refuse('AUTHORIZATION_EXPIRED_OR_CONSUMED', [
                'authorization_status' => (string) $auth['authorization_status'],
            ], 'POLICY');
        }

        $now = $this->clock->now();
        if ($now > (string) $auth['expires_at']) {
            $this->db->run(
                "UPDATE outreach_execution_authorizations SET authorization_status = 'EXPIRED'
                 WHERE outreach_execution_authorization_id = ? AND authorization_status = 'ISSUED'",
                [$input['outreach_execution_authorization_id']]
            );

            return Outcome::refuse('AUTHORIZATION_EXPIRED_OR_CONSUMED', [], 'POLICY');
        }

        // LINK FOUR OF THE DIGEST CHAIN. A mutation BLOCKS BOTH the authorization and the artifact,
        // rather than merely refusing this attempt: an artifact whose payload changed after freezing
        // is not one to retry with, and leaving it authorized would invite exactly that.
        if ($input['observed_payload_digest'] !== (string) $auth['payload_digest']) {
            return $this->db->transaction(function (Database $tx) use ($input, $auth): Outcome {
                $tx->run(
                    "UPDATE outreach_execution_authorizations SET authorization_status = 'REVOKED'
                     WHERE outreach_execution_authorization_id = ?",
                    [$input['outreach_execution_authorization_id']]
                );
                $tx->run(
                    "UPDATE frozen_outreach_artifacts SET artifact_status = 'REVOKED'
                     WHERE frozen_outreach_artifact_id = ?",
                    [(string) $auth['frozen_outreach_artifact_id']]
                );

                return Outcome::refuse('FROZEN_PAYLOAD_MUTATED', [], 'INTEGRITY', 'S4', 'BLOCK_RELEASE');
            });
        }

        return $this->db->transaction(function (Database $tx) use ($input, $auth, $now): Outcome {
            // ATOMIC. The status predicate is IN the UPDATE, so of two concurrent callers exactly
            // one changes a row and proceeds. A read-then-write would let both through.
            $changed = $tx->run(
                "UPDATE outreach_execution_authorizations
                 SET authorization_status = 'CONSUMED', consumed_at = ?
                 WHERE outreach_execution_authorization_id = ? AND authorization_status = 'ISSUED'",
                [$now, $input['outreach_execution_authorization_id']]
            );
            if ($changed !== 1) {
                return Outcome::refuse('AUTHORIZATION_EXPIRED_OR_CONSUMED', [], 'POLICY');
            }
            $tx->run(
                "UPDATE frozen_outreach_artifacts SET artifact_status = 'CONSUMED'
                 WHERE frozen_outreach_artifact_id = ?",
                [(string) $auth['frozen_outreach_artifact_id']]
            );

            return Outcome::ok([
                'outreach_execution_authorization_id' => $input['outreach_execution_authorization_id'],
                'frozen_outreach_artifact_id' => (string) $auth['frozen_outreach_artifact_id'],
            ]);
        });
    }

    /**
     * The stand-down lever: revoke an OUTSTANDING authorization.
     *
     * A CONSUMED authorization is NOT revocable. What it authorized already happened, and that is
     * historical fact — recorded truthfully, never un-recorded. A correction is a new approved
     * outreach, not a rewrite of this one.
     *
     * @param array{tenant_id: string, workspace_id: string, outreach_execution_authorization_id: string} $input
     */
    public function revokeAuthorization(array $input): Outcome
    {
        $auth = $this->db->one(
            'SELECT authorization_status, frozen_outreach_artifact_id, tenant_id, workspace_id
             FROM outreach_execution_authorizations WHERE outreach_execution_authorization_id = ?',
            [$input['outreach_execution_authorization_id']]
        );
        if ($auth === null) {
            return Outcome::refuse('AUTHORIZATION_NOT_FOUND', [], 'PROVENANCE');
        }
        // Scope BEFORE any mutation. A stand-down lever must never reach another workspace's
        // outreach — which would make it an attack rather than a safety control.
        if ((string) $auth['tenant_id'] !== $input['tenant_id']
            || (string) $auth['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('AUTHORIZATION_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }

        return $this->db->transaction(function (Database $tx) use ($input, $auth): Outcome {
            $revoked = $tx->run(
                "UPDATE outreach_execution_authorizations SET authorization_status = 'REVOKED'
                 WHERE outreach_execution_authorization_id = ? AND authorization_status = 'ISSUED'",
                [$input['outreach_execution_authorization_id']]
            );
            if ($revoked !== 1) {
                return Outcome::refuse('AUTHORIZATION_NOT_REVOCABLE', [
                    'authorization_status' => (string) $auth['authorization_status'],
                ], 'POLICY');
            }
            $tx->run(
                "UPDATE frozen_outreach_artifacts SET artifact_status = 'REVOKED'
                 WHERE frozen_outreach_artifact_id = ? AND artifact_status IN ('FROZEN', 'AUTHORIZED')",
                [(string) $auth['frozen_outreach_artifact_id']]
            );

            return Outcome::ok([
                'outreach_execution_authorization_id' => $input['outreach_execution_authorization_id'],
                'frozen_outreach_artifact_id' => (string) $auth['frozen_outreach_artifact_id'],
            ]);
        });
    }

    /** An ISO instant advanced by whole seconds, in the ledger's millisecond format. */
    private static function plusSeconds(string $iso, int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s', strtotime($iso) + $seconds) . '.000Z';
    }
}
