<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * The M09 approval seat, in the ONE place this projection admits one: P07-A outreach.
 *
 * M09 IS OTHERWISE EXCLUDED HERE. There is no controlled-demo-action approval path, and Class E
 * dispatch is refused outright at `CLASS_E_NOT_AVAILABLE_IN_PROJECTION`. This narrow admission
 * exists because an outreach decision is exactly the kind that should require a person, and
 * building the seat is what makes "a human must decide" a real state rather than a label.
 *
 * WHAT THE APPROVAL BINDS TO IS DERIVED, NEVER PASSED. The payload reference and digest come from
 * the IMMUTABLE submitted proposal — the reference from the outreach row, the digest from the
 * envelope's release scope, which froze at submission. A caller cannot open an approval for one
 * payload and have the frozen artifact bind to another, because neither value is theirs to supply.
 *
 * SEPARATION OF DUTIES IS ENFORCED, NOT INTENDED. The request demands `outreach.approve`, which
 * only `ROLE_CRM_APPROVER` holds — the CRM executor that drafts an outreach does not. Both the
 * generic approval permission and the specific outreach one are checked, so a general action
 * approver cannot approve a CRM outreach either.
 *
 * THE M09 CEILING IS UNTOUCHED. `action_category` stays `HARMLESS_DEMO_ACTION` and the adapter
 * stays `NO_OP_TEST_EVENT`, both CHECK-pinned. Approving an outreach here authorizes the creation
 * of a frozen artifact, and nothing else — this projection has no channel to carry one.
 */
final class OutreachApproval
{
    public const REQUIRED_APPROVER_PERMISSION = 'outreach.approve';
    public const GENERIC_APPROVER_PERMISSION = 'approval.decide_exact_artifact';

    /** @var array<string, string> */
    private const STATUS_FOR_DECISION = [
        'APPROVE_EXACT_ARTIFACT' => 'APPROVED',
        'DENY' => 'DENIED',
        'REQUEST_REVISION' => 'REVISION_REQUESTED',
        'REVOKE_BEFORE_EXECUTION' => 'REVOKED',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AccessGuard $access,
    ) {
    }

    /**
     * Open the approval request for an admitted outreach.
     *
     * @param array{
     *     arbiter_verdict_id: string, created_by_actor_type: string, created_by_actor_id: string,
     *     requested_effect_summary?: ?string
     * } $input
     */
    public function requestApproval(array $input): Outcome
    {
        $verdict = $this->db->one(
            'SELECT v.verdict, v.permitted_next_stage, v.basis_hash, v.proposal_id,
                    v.proposal_submission_digest, v.tenant_id, v.workspace_id, v.mission_id,
                    p.proposal_type, p.requested_release_or_action_scope AS action_scope,
                    oep.exact_payload_reference_safe, oep.proposal_status AS outreach_status,
                    oep.arbiter_verdict_id AS outreach_verdict_id
             FROM arbiter_verdicts v
             JOIN proposal_envelopes p ON p.proposal_id = v.proposal_id
             LEFT JOIN outreach_execution_proposals oep ON oep.proposal_id = v.proposal_id
             WHERE v.arbiter_verdict_id = ?',
            [$input['arbiter_verdict_id']]
        );
        if ($verdict === null) {
            return Outcome::refuse('VERDICT_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $verdict['verdict'] !== 'REQUIRE_HUMAN_APPROVAL'
            || (string) $verdict['permitted_next_stage'] !== 'M09_APPROVAL_REQUEST_CREATION'
        ) {
            return Outcome::refuse('VERDICT_NOT_APPROVAL_ROUTE', [
                'verdict' => (string) $verdict['verdict'],
            ], 'POLICY');
        }
        if ((string) $verdict['proposal_type'] !== 'CRM_OUTREACH_EXECUTION') {
            return Outcome::refuse('VERDICT_NOT_OUTREACH', [], 'POLICY');
        }
        // The outreach row must AGREE that this verdict admitted it. Without the second clause a
        // verdict could be reused to open an approval for an outreach it never evaluated.
        if ((string) $verdict['outreach_status'] !== 'APPROVAL_REQUIRED'
            || (string) $verdict['outreach_verdict_id'] !== $input['arbiter_verdict_id']
        ) {
            return Outcome::refuse('OUTREACH_PROPOSAL_NOT_ADMITTED', [], 'POLICY');
        }
        // A verdict M08 has explicitly invalidated must not advance to a human. Asking somebody to
        // approve a decision the arbiter has withdrawn is asking them to approve nothing.
        $invalidation = $this->db->one(
            "SELECT 1 AS hit FROM arbiter_invalidations
             WHERE affected_object_type = 'VERDICT' AND affected_object_id = ?
               AND blocks_pending_progression = 1 LIMIT 1",
            [$input['arbiter_verdict_id']]
        );
        if ($invalidation !== null) {
            return Outcome::refuse('VERDICT_INVALIDATED', [], 'POLICY', 'S4');
        }

        // DERIVED, never passed. The reference comes from the immutable outreach row and the digest
        // from the envelope scope that froze at submission.
        $reference = $verdict['exact_payload_reference_safe'];
        $digest = null;
        if ($verdict['action_scope'] !== null) {
            $scope = json_decode((string) $verdict['action_scope'], true);
            if (is_array($scope) && is_string($scope['payloadDigest'] ?? null)) {
                $digest = $scope['payloadDigest'];
            }
        }
        if ($reference === null || $digest === null) {
            return Outcome::refuse('PROPOSAL_PAYLOAD_BINDING_MISSING', [], 'PROVENANCE', 'S4');
        }

        $requestId = Ids::new();
        $this->db->insert('approval_requests', [
            'approval_request_id' => $requestId,
            'schema_version' => '1',
            'tenant_id' => (string) $verdict['tenant_id'],
            'workspace_id' => (string) $verdict['workspace_id'],
            'mission_id' => (string) $verdict['mission_id'],
            'proposal_id' => (string) $verdict['proposal_id'],
            'proposal_submission_digest' => (string) $verdict['proposal_submission_digest'],
            'arbiter_verdict_id' => $input['arbiter_verdict_id'],
            'arbiter_verdict_basis_hash' => (string) $verdict['basis_hash'],
            // BOTH CHECK-pinned. The M09 ceiling is not weakened to admit outreach: what is being
            // approved is categorically a harmless demo action against a no-op adapter.
            'action_category' => 'HARMLESS_DEMO_ACTION',
            'requested_adapter_type' => 'NO_OP_TEST_EVENT',
            'governed_tool_id' => 'governed:p07-synthetic',
            'governed_tool_operation_id' => 'synthetic-noop',
            'requested_effect_summary' => $input['requested_effect_summary']
                ?? 'Freeze an approved outreach artifact. This projection performs no send.',
            'requested_destination_or_scope_summary' => 'demo-workspace-only',
            'exact_payload_reference_safe' => (string) $reference,
            'exact_normalized_payload_digest' => (string) $digest,
            'hash_algorithm' => 'sha256',
            'status' => 'AWAITING_APPROVAL',
            'created_by_actor_type' => $input['created_by_actor_type'],
            'created_by_actor_id' => $input['created_by_actor_id'],
            // The SEPARATE seat. A generic action approver does not hold this.
            'required_approver_permission' => self::REQUIRED_APPROVER_PERMISSION,
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'approval_request_id' => $requestId,
            'exact_normalized_payload_digest' => (string) $digest,
        ]);
    }

    /**
     * Record a human decision.
     *
     * @param array{
     *     approval_request_id: string, approver_operator_id: string, decision: string,
     *     exact_payload_digest_confirmed: string, decision_reason_summary: string,
     *     expires_at?: ?string
     * } $input
     */
    public function recordDecision(array $input): Outcome
    {
        $request = $this->db->one(
            'SELECT tenant_id, workspace_id, status, exact_normalized_payload_digest,
                    arbiter_verdict_id, required_approver_permission
             FROM approval_requests WHERE approval_request_id = ?',
            [$input['approval_request_id']]
        );
        if ($request === null) {
            return Outcome::refuse('APPROVAL_REQUEST_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $request['status'] !== 'AWAITING_APPROVAL') {
            return Outcome::refuse('APPROVAL_REQUEST_NOT_AWAITING_APPROVAL', [
                'status' => (string) $request['status'],
            ], 'POLICY');
        }

        // The GENERIC approval authority, decided server-side by M02 like any other access question.
        $access = $this->access->evaluate(
            'OPERATOR',
            $input['approver_operator_id'],
            (string) $request['tenant_id'],
            (string) $request['workspace_id'],
            self::GENERIC_APPROVER_PERMISSION,
            'approval_request',
            $input['approval_request_id'],
        );
        if ($access['decision'] !== 'ALLOW') {
            return Outcome::refuse('APPROVER_NOT_AUTHORIZED', [
                'reason_codes' => $access['reason_codes'],
            ], 'SCOPE', 'S4');
        }
        // AND the domain-specific seat, when the request demands one. This is what makes separation
        // of duties real at the decision rather than merely intended by the role seeding: a general
        // action approver holds the first permission and not this one.
        if ($request['required_approver_permission'] !== null) {
            $specific = $this->access->evaluate(
                'OPERATOR',
                $input['approver_operator_id'],
                (string) $request['tenant_id'],
                (string) $request['workspace_id'],
                (string) $request['required_approver_permission'],
                'approval_request',
                $input['approval_request_id'],
            );
            if ($specific['decision'] !== 'ALLOW') {
                return Outcome::refuse('APPROVER_NOT_AUTHORIZED', [
                    'required_permission' => (string) $request['required_approver_permission'],
                ], 'SCOPE', 'S4');
            }
        }

        // The approver confirms the EXACT digest they were shown. Any drift between what was
        // displayed and what is on file invalidates the decision — an approval is of a specific
        // artifact, and "approximately that artifact" is not a thing a person can consent to.
        if ($input['exact_payload_digest_confirmed'] !== (string) $request['exact_normalized_payload_digest']) {
            return Outcome::refuse('APPROVAL_REQUEST_NOT_EXACT', [], 'INTEGRITY', 'S4', 'BLOCK_RELEASE');
        }

        $decisionId = Ids::new();
        $now = $this->clock->now();
        $decisionDigest = Digest::canonicalDigest([
            'approvalRequestId' => $input['approval_request_id'],
            'decision' => $input['decision'],
            'exactPayloadDigestAtDecision' => (string) $request['exact_normalized_payload_digest'],
            'approverOperatorId' => $input['approver_operator_id'],
            'arbiterVerdictId' => (string) $request['arbiter_verdict_id'],
        ]);

        return $this->db->transaction(function (Database $tx) use (
            $input, $request, $decisionId, $decisionDigest, $access, $now
        ): Outcome {
            $tx->insert('approval_decisions', [
                'approval_decision_id' => $decisionId,
                'schema_version' => '1',
                'approval_request_id' => $input['approval_request_id'],
                'approver_operator_id' => $input['approver_operator_id'],
                // WHICH access evaluation authorized this person. The decision cites the proof of
                // authority rather than asserting it.
                'access_evaluation_id' => $access['access_evaluation_id'],
                'decision' => $input['decision'],
                'exact_payload_digest_at_decision' => (string) $request['exact_normalized_payload_digest'],
                'arbiter_verdict_id' => (string) $request['arbiter_verdict_id'],
                'decision_reason_summary' => $input['decision_reason_summary'],
                'decision_digest' => $decisionDigest,
                'hash_algorithm' => 'sha256',
                'effective_at' => $now,
                'expires_at' => $input['expires_at'] ?? null,
            ]);
            $tx->run(
                'UPDATE approval_requests SET status = ? WHERE approval_request_id = ?',
                [self::STATUS_FOR_DECISION[$input['decision']] ?? 'DENIED', $input['approval_request_id']]
            );

            return Outcome::ok([
                'approval_decision_id' => $decisionId,
                'decision' => $input['decision'],
            ]);
        });
    }
}
