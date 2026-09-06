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

    /**
     * The canonical writer for a P07-A synthetic dispatch record.
     *
     * Upstream: `input.recordedByServiceIdentityId ?? "service:p07-crm-delivery"`. The port used to
     * pass no writer at all, which meant M10's `?? $recordId` fallback wrote the record's own id
     * into the provenance column — a record asserting it wrote itself.
     */
    public const SERVICE_IDENTITY = 'service:p07-crm-delivery';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
        private readonly Consent $consent,
        private readonly OutreachArtifacts $artifacts,
    ) {
    }

    /**
     * Consume an authorization and record the outcome — as ONE transaction.
     *
     * THE UNIT IS THE POINT. Upstream opens `BEGIN IMMEDIATE` before the consent re-check and does
     * not commit until the delivery-outcome row is in. The port used to run four separate
     * transactional units, which left two states upstream forbids the normal dispatch command to
     * produce:
     *
     *   consume commits, M10 fails   -> a CONSUMED authorization, no outcome record, no delivery
     *                                   row. The authorization is spent and nothing says what it
     *                                   was spent on.
     *   consume + M10 commit, insert fails -> a CONSUMED authorization and an M10 record claiming a
     *                                   delivery outcome that has no delivery row.
     *
     * The port's own P07 assurance detects the first as `CONSUMED_AUTHORIZATION_WITHOUT_OUTCOME`.
     * That audit is still here and still useful — it catches the states no transaction can prevent,
     * such as a crash between two committed units in some future path — but detecting an invalid
     * state after the fact is not the same as being unable to reach it. Both are needed.
     *
     * WHAT STILL COMMITS ON A REFUSAL. Two refusals leave durable effects, and upstream commits
     * both deliberately:
     *
     *   - consent NOT PERMITTED: nothing was written, and the commit is a no-op that keeps the
     *     control flow identical to upstream's;
     *   - consume EXPIRED or payload-mutation REVOKED: a real status change, made because the
     *     authorization really did expire or the artifact really was mutated. Rolling those back
     *     to tidy up a refused dispatch would restore an authorization the system had correctly
     *     retired, and the next attempt would meet an ISSUED row that should not exist.
     *
     * Only a failure AFTER a successful consume rolls back, and then it rolls back everything: the
     * consume, the artifact status, the M10 record, its references, its integrity link and the
     * stream head.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, outreach_execution_authorization_id: string,
     *     observed_payload_digest: string, simulated_outcome: string, correlation_id?: ?string,
     *     recorded_by_service_identity_id?: ?string
     * } $input
     */
    public function dispatch(array $input): Outcome
    {
        // A programmer contract, not a governed refusal. Upstream types the outcome as
        // `"DELIVERED" | "FAILED" | "UNKNOWN"`, so an unrecognised value does not compile and
        // `DispatchOutreachSyntheticReason` carries no code for it. The port used to return an
        // invented `UNKNOWN_SIMULATED_OUTCOME` refusal, which put a compile error onto the governed
        // conformance surface. There is no request path into this method — P07-A has no real
        // channel, and the outcome is supplied by a fixture, exactly as upstream says.
        if (!isset(self::RECORD_BY_OUTCOME[$input['simulated_outcome']])) {
            throw new \InvalidArgumentException(
                'simulated_outcome must be DELIVERED, FAILED or UNKNOWN; got: '
                . $input['simulated_outcome']
            );
        }

        // The same five columns off the same four tables upstream reads, and no more. The port used
        // to additionally inner-join `arbiter_verdicts` and `proposal_envelopes` for a basis hash
        // and a proposal id that upstream does not put in this record — and inner joins that exist
        // only to feed removed fields also decide the refusal: a missing proposal row would have
        // reported AUTHORIZATION_NOT_FOUND about an authorization that was present.
        //
        // The workspace join is upstream's, and it is where the environment comes from. Reading it
        // here rather than in a second lookup also restores upstream's failure behaviour: an
        // authorization whose workspace has gone is AUTHORIZATION_NOT_FOUND, not a separate
        // WORKSPACE_NOT_FOUND code that upstream's reason union does not contain.
        $auth = $this->db->one(
            'SELECT a.tenant_id, a.workspace_id, a.frozen_outreach_artifact_id,
                    a.approval_decision_id, f.recipient_pseudonym_ref, f.channel,
                    f.source_decision_record_id, r.arbiter_verdict_id, w.environment_class
             FROM outreach_execution_authorizations a
             JOIN frozen_outreach_artifacts f
               ON f.frozen_outreach_artifact_id = a.frozen_outreach_artifact_id
             JOIN approval_requests r ON r.approval_request_id = f.approval_request_id
             JOIN workspaces w ON w.workspace_id = a.workspace_id
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

        try {
            return $this->db->transaction(
                fn (): Outcome => $this->dispatchUnit($input, $auth),
                // THE ONE UPSTREAM EXCEPTION, and it is upstream's own. `outreach-delivery.ts` is
                // the only file in the selected graph that COMMITs and then refuses, and it does so
                // twice: a consent refusal (which wrote nothing) and a consume refusal (whose
                // EXPIRED / mutation-REVOKE transitions are "legitimate persisted effects — a real
                // status change, not a half-finished dispatch", in its words). A post-consume
                // failure is NOT covered by this: it throws DispatchRollback and rolls the unit
                // back, which is the whole point of the repair.
                commitOnRefusal: true
            );
        } catch (DispatchRollback $rollback) {
            // The unit is already rolled back. The consume did not happen, the M10 record does not
            // exist, and the head did not move.
            return Outcome::refuse($rollback->reasonCode, $rollback->detail, 'POLICY');
        }
    }

    /**
     * The dispatch unit itself, inside the caller's transaction.
     *
     * Returning from here COMMITS. Throwing `DispatchRollback` rolls the unit back and becomes a
     * governed refusal; any other throw rolls back and propagates, which is upstream's
     * `catch { db.exec("ROLLBACK"); throw error; }`.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $auth
     */
    private function dispatchUnit(array $input, array $auth): Outcome
    {
        // CONSENT IS CHECKED ONE LAST TIME, and before the consume. A withdrawal recorded in the
        // seconds since the authorization was issued is honoured HERE — which is the last moment it
        // could be, and therefore the moment that matters.
        $consent = $this->consent->evaluateForSend([
            'tenant_id' => (string) $auth['tenant_id'],
            'workspace_id' => (string) $auth['workspace_id'],
            'recipient_pseudonym_ref' => (string) $auth['recipient_pseudonym_ref'],
            'channel' => (string) $auth['channel'],
        ]);
        if ($consent->isRefusal()) {
            // Returned, not thrown: nothing has been written and upstream COMMITs here.
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
            // Returned, not thrown. An EXPIRED or REVOKED transition the consume just made is a
            // legitimate persisted effect and upstream COMMITs it.
            return Outcome::refuse($consumed->reasonCode(), $consumed->detail, 'POLICY', 'S4');
        }

        // ============ this is where a live send would be. There is nothing here. ============
        // No channel adapter is constructed, no transport is opened, no request is made. The
        // outcome below was supplied by the caller, and the record says so.

        $mapping = self::RECORD_BY_OUTCOME[$input['simulated_outcome']];
        $isSuccess = $input['simulated_outcome'] === 'DELIVERED';

        // The environment is the WORKSPACE's, read from the join above rather than assumed. This
        // call used to omit it and take M10's `'TEST'` default, so a DEMO workspace's
        // synthetic-delivery records were appended to the TEST ledger.
        $streamId = $this->ledger->ensureActiveStream(
            (string) $auth['tenant_id'],
            (string) $auth['workspace_id'],
            (string) $auth['environment_class'],
        );

        // THE RECORD IS UPSTREAM'S, FIELD FOR FIELD. Every one of these participates in
        // `record_payload_digest`, so a paraphrased summary, a missing reason code, an
        // upper-cased object type or an extra version digest is not a cosmetic difference — it
        // produces a different digest for the same governed event, and cross-runtime parity is
        // gone. See tests/conformance/p07a_delivery_conformance.php, whose vectors come from the
        // canonical TypeScript encoder rather than from this file.
        $recorded = $this->ledger->append(
            $streamId,
            $mapping['record_type'],
            'ACTION_MATERIAL',
            $mapping['status'],
            sprintf(
                'P07-A synthetic outreach delivery outcome: %s (no external effect).',
                $input['simulated_outcome'],
            ),
            [
                [
                    'owner_module_id' => 'M08',
                    'input_object_type' => 'arbiter_verdict',
                    'input_object_id' => (string) $auth['arbiter_verdict_id'],
                    'reference_role' => 'VERDICT_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M09',
                    'input_object_type' => 'approval_decision',
                    'input_object_id' => (string) $auth['approval_decision_id'],
                    'reference_role' => 'APPROVAL_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M09',
                    'input_object_type' => 'outreach_execution_authorization',
                    'input_object_id' => $input['outreach_execution_authorization_id'],
                    'reference_role' => 'AUTHORIZATION_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    'owner_module_id' => 'M09',
                    'input_object_type' => 'frozen_outreach_artifact',
                    'input_object_id' => (string) $auth['frozen_outreach_artifact_id'],
                    'reference_role' => 'ARTIFACT_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
                [
                    // OUTCOME_BASIS is the RELEASED decision record the outreach was built from —
                    // the M10 record that authorised there being something to say at all. The port
                    // used to cite an invented `SYNTHETIC_DISPATCH_OUTCOME` object owned by M09 and
                    // identified by the authorization id, which pointed the mandatory outcome basis
                    // at the authorization it had already cited as AUTHORIZATION_BASIS. The record
                    // hashed cleanly and cited the wrong source object.
                    'owner_module_id' => 'M10',
                    'input_object_type' => 'decision_record',
                    'input_object_id' => (string) $auth['source_decision_record_id'],
                    'reference_role' => 'OUTCOME_BASIS',
                    'verification_status_at_record_time' => 'VERIFIED_REFERENCE',
                ],
            ],
            $input['recorded_by_service_identity_id'] ?? self::SERVICE_IDENTITY,
            [
                'reason_codes' => ['P07_DELIVERY_' . $input['simulated_outcome']],
                'correlation_id' => $input['correlation_id'] ?? null,
                // A failed or unknown delivery is recorded truthfully AND flagged for operator
                // triage. There is no auto-retry: the authorization is already spent.
                'severity' => $isSuccess ? null : 'S3',
                'incident_evaluation_required' => !$isSuccess,
            ],
        );
        if ($recorded->isRefusal()) {
            // THROWN, not returned. A returned refusal would COMMIT the consume that just
            // succeeded, stranding the authorization with no outcome — which is the exact state
            // upstream rolls back to prevent.
            throw new DispatchRollback('M10_RECORD_FAILED', ['detail' => $recorded->reasonCode()]);
        }
        $decisionRecordId = (string) $recorded->value()['decision_record_id'];

        $outcomeRecordId = Ids::new();
        // Inside the unit. A failure here throws out of the transaction, taking the consume, the
        // artifact status and the whole M10 append with it.
        $this->db->insert('delivery_outcome_records', [
            'delivery_outcome_record_id' => $outcomeRecordId,
            'schema_version' => '1',
            'tenant_id' => (string) $auth['tenant_id'],
            'workspace_id' => (string) $auth['workspace_id'],
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
