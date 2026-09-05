<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * The SYNTHETIC dispatch, and the truthful record of what it did.
 *
 * WHAT THIS FUNCTION DOES: consumes a single-use authorization, then appends a decision record and
 * a delivery-outcome row saying what happened.
 *
 * WHAT IT DOES NOT DO: contact anybody. There is no channel, no adapter, no transport and no
 * network call in this file or anywhere it reaches. The outcome is supplied by the caller because
 * there is nothing to observe one from — and the function is named for that rather than pretending
 * otherwise.
 *
 * SO WHY BUILD IT AT ALL? Because every gate a real send would pass is here and testable: single
 * use, expiry, frozen-payload integrity, live consent. The gates get exercised while the only
 * adversary is a test suite, which is the moment to find out they are wrong.
 *
 * THE ORDER IS THE LAW. Consent is re-checked, then the authorization is CONSUMED, and only then
 * is an outcome known. That means a FAILED or UNKNOWN outcome has already spent its authorization,
 * so there is no automatic retry — not because retry is disabled, but because there is nothing
 * left to retry with. Sending again requires a new approval, a new artifact and a new
 * authorization, each with a person in the middle.
 *
 * THE RECORD IS TRUTHFUL. A failure is recorded as a failure and an unknown as an unknown. The
 * ledger's job is to say what happened, and a ledger that rounded unknowns up to successes would
 * be worse than no ledger.
 */
final class SyntheticDelivery
{
    /**
     * How each outcome is recorded.
     *
     * All three are HARMLESS_DEMO_ACTION types, because that is categorically what a synthetic
     * dispatch is. The M09 ceiling is not being stretched to cover outreach; the outreach is being
     * recorded as the harmless thing it actually was.
     *
     * @var array<string, array{record_type: string, status: string}>
     */
    public const RECORD_BY_OUTCOME = [
        'DELIVERED' => ['record_type' => 'HARMLESS_DEMO_ACTION_COMPLETED', 'status' => 'EXECUTED_SUCCESS'],
        'FAILED' => ['record_type' => 'HARMLESS_DEMO_ACTION_FAILED', 'status' => 'EXECUTION_FAILED'],
        'UNKNOWN' => ['record_type' => 'HARMLESS_DEMO_ACTION_OUTCOME_UNKNOWN', 'status' => 'OUTCOME_UNKNOWN'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly Consent $consent,
        private readonly OutreachArtifacts $artifacts,
    ) {
    }

    /**
     * Consume an authorization and record the outcome.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, outreach_execution_authorization_id: string,
     *     observed_payload_digest: string, simulated_outcome: string, correlation_id?: ?string
     * } $input
     */
    public function dispatch(array $input): Outcome
    {
        if (!isset(self::RECORD_BY_OUTCOME[$input['simulated_outcome']])) {
            return Outcome::refuse('UNKNOWN_SIMULATED_OUTCOME', [
                'simulated_outcome' => $input['simulated_outcome'],
            ], 'POLICY');
        }

        $auth = $this->db->one(
            'SELECT a.tenant_id, a.workspace_id, a.frozen_outreach_artifact_id, a.approval_decision_id,
                    f.recipient_pseudonym_ref, f.channel, f.approval_request_id,
                    f.source_decision_record_id, r.arbiter_verdict_id, r.proposal_id,
                    v.basis_hash, pe.evidence_bundle_id
             FROM outreach_execution_authorizations a
             JOIN frozen_outreach_artifacts f
               ON f.frozen_outreach_artifact_id = a.frozen_outreach_artifact_id
             JOIN approval_requests r ON r.approval_request_id = f.approval_request_id
             JOIN arbiter_verdicts v ON v.arbiter_verdict_id = r.arbiter_verdict_id
             JOIN proposal_envelopes pe ON pe.proposal_id = r.proposal_id
             WHERE a.outreach_execution_authorization_id = ?',
            [$input['outreach_execution_authorization_id']]
        );
        if ($auth === null) {
            return Outcome::refuse('AUTHORIZATION_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $auth['tenant_id'] !== $input['tenant_id']
            || (string) $auth['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('AUTHORIZATION_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }

        // CONSENT IS CHECKED ONE LAST TIME, and before the consume. A withdrawal recorded in the
        // seconds since the authorization was issued is honoured HERE — which is the last moment it
        // could be, and therefore the moment that matters.
        $consent = $this->consent->evaluateForSend([
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'recipient_pseudonym_ref' => (string) $auth['recipient_pseudonym_ref'],
            'channel' => (string) $auth['channel'],
        ]);
        if ($consent->isRefusal()) {
            return Outcome::refuse('CONSENT_NOT_PERMITTED', [
                'detail' => $consent->reasonCode(),
            ], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        // The consume carries the single-use, expiry and integrity gates. It happens BEFORE any
        // outcome is known, which is what makes retry structurally impossible rather than merely
        // discouraged.
        $consumed = $this->artifacts->consume([
            'outreach_execution_authorization_id' => $input['outreach_execution_authorization_id'],
            'observed_payload_digest' => $input['observed_payload_digest'],
        ]);
        if ($consumed->isRefusal()) {
            return Outcome::refuse($consumed->reasonCode(), $consumed->detail, 'POLICY', 'S4');
        }

        // ============ this is where a live send would be. There is nothing here. ============
        // No channel adapter is constructed, no transport is opened, no request is made. The
        // outcome below was supplied by the caller, and the record says so.

        $mapping = self::RECORD_BY_OUTCOME[$input['simulated_outcome']];
        $streamId = $this->ledger->ensureActiveStream($input['tenant_id'], $input['workspace_id']);
        $recorded = $this->ledger->append(
            $streamId,
            $mapping['record_type'],
            'ACTION_MATERIAL',
            $mapping['status'],
            sprintf(
                'Synthetic outreach dispatch recorded as %s. No external effect occurred.',
                $input['simulated_outcome'],
            ),
            [
                [
                    'owner_module_id' => 'M08', 'input_object_type' => 'ARBITER_VERDICT',
                    'input_object_id' => (string) $auth['arbiter_verdict_id'],
                    'input_object_version_or_digest' => (string) $auth['basis_hash'],
                    'reference_role' => 'VERDICT_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M09', 'input_object_type' => 'APPROVAL_DECISION',
                    'input_object_id' => (string) $auth['approval_decision_id'],
                    'reference_role' => 'APPROVAL_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M09', 'input_object_type' => 'FROZEN_OUTREACH_ARTIFACT',
                    'input_object_id' => (string) $auth['frozen_outreach_artifact_id'],
                    'input_object_version_or_digest' => $input['observed_payload_digest'],
                    'reference_role' => 'ARTIFACT_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M09', 'input_object_type' => 'OUTREACH_EXECUTION_AUTHORIZATION',
                    'input_object_id' => $input['outreach_execution_authorization_id'],
                    'reference_role' => 'AUTHORIZATION_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    // M09 owns the execution plane that issued the authorization, and the outcome
                    // is an observation about consuming it. The module vocabulary is substrate-only,
                    // so a product-layer owner id would not be a module the ledger can name.
                    'owner_module_id' => 'M09', 'input_object_type' => 'SYNTHETIC_DISPATCH_OUTCOME',
                    'input_object_id' => $input['outreach_execution_authorization_id'],
                    'input_object_version_or_digest' => $input['simulated_outcome'],
                    'reference_role' => 'OUTCOME_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
            ],
            [
                'proposal_id' => (string) $auth['proposal_id'],
                'correlation_id' => $input['correlation_id'] ?? null,
            ],
        );
        if ($recorded->isRefusal()) {
            return Outcome::refuse('M10_RECORD_FAILED', [
                'detail' => $recorded->reasonCode(),
            ], 'POLICY');
        }
        $decisionRecordId = (string) $recorded->value()['decision_record_id'];

        $outcomeRecordId = Ids::new();
        $this->db->insert('delivery_outcome_records', [
            'delivery_outcome_record_id' => $outcomeRecordId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'outreach_execution_authorization_id' => $input['outreach_execution_authorization_id'],
            'frozen_outreach_artifact_id' => (string) $auth['frozen_outreach_artifact_id'],
            'decision_record_id' => $decisionRecordId,
            // TRUTHFUL. A failure is a failure; an unknown stays unknown.
            'delivery_outcome' => $input['simulated_outcome'],
            'outcome_detail_safe' => 'Synthetic dispatch; this projection performs no external send.',
            'recorded_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'outcome' => 'RECORDED',
            'delivery_outcome' => $input['simulated_outcome'],
            'delivery_outcome_record_id' => $outcomeRecordId,
            'decision_record_id' => $decisionRecordId,
            'frozen_outreach_artifact_id' => (string) $auth['frozen_outreach_artifact_id'],
        ]);
    }
}
