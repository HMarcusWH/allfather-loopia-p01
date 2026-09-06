<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P07-A outreach admission — the governed decision about whether an outreach WOULD be permissible.
 *
 * NOTHING HERE SENDS ANYTHING, and that is a structural fact rather than a policy. This projection
 * contains no channel adapter, no transport, and no code path that could acquire one. The
 * requested action class is `ANALYZE`: what is being admitted is a JUDGEMENT about an outreach, not
 * an outreach.
 *
 * THE PRODUCER-AUTHORITY GATE IS THE ONE MOST EASILY FORGOTTEN. Every downstream plane — M07, M08,
 * M09 — reviews the outreach FACTS: is there consent, is the content clean, is the source live.
 * None of them asks whether this agent was ever entitled to propose an outreach at all. Five
 * conditions are checked here and nowhere else: the Valkyrie is ACTIVE and in this tenant, its
 * contract is ACTIVE and current and belongs to it, the contract carries a CRM-outreach output
 * binding, the mission permits that output class, and the mission assignment grants both ANALYZE
 * and that output class. Without them a caller could route any outreach through a producer that
 * was never authorized to make one, and every later gate would happily review its facts.
 *
 * THE PII FIREWALL IS CHECKED THREE WAYS. The scannable content, the safe payload reference, AND
 * the exact structured payload — because the payload is what would actually reach a person, and a
 * clean scan string must not let a raw identifier ride in alongside it.
 *
 * CONSENT IS CHECKED HERE AND RE-DERIVED AT THE VERDICT. Not because this check is weak, but
 * because a withdrawal is an act a person can perform at any moment, including between the two.
 */
final class OutreachAdmission
{
    /**
     * The verdicts that TERMINATE an outreach proposal.
     *
     * `HOLD` is absent: a content-policy or cooldown failure is a reason to wait or reword, and the
     * proposal stays where a revision can reach it.
     *
     * @var list<string>
     */
    public const TERMINATING_VERDICTS = ['BLOCK', 'QUARANTINE'];

    public const OUTPUT_CLASS = 'CRM_OUTREACH_EXECUTION_PROPOSAL';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly EvidenceSpine $evidence,
        private readonly Readiness $readiness,
        private readonly Proposals $proposals,
        private readonly Council $council,
        private readonly Arbiter $arbiter,
        private readonly DecisionLedger $ledger,
        private readonly Consent $consent,
    ) {
    }

    /**
     * Submit a CRM_OUTREACH_EXECUTION proposal.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, recipient_pseudonym_ref: string,
     *     channel: string, payload_kind: string, exact_payload: mixed,
     *     exact_payload_reference_safe: string, content_to_scan_safe: string,
     *     source_decision_record_id: string,
     *     governance: array{
     *         mission_id: string, producing_valkyrie_id: string, capability_contract_id: string
     *     }
     * } $input
     */
    public function submitProposal(array $input): Outcome
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

        // THE FIREWALL, THREE WAYS. The exact payload is scanned DEEP — every string at every
        // depth, keys included — because it is the content that would reach a person, and a clean
        // parallel scan string proves nothing about it.
        if (PiiFirewall::containsRawPii($input['content_to_scan_safe'])
            || PiiFirewall::containsRawPii($input['exact_payload_reference_safe'])
            || PiiFirewall::containsRawPiiDeep($input['exact_payload'])
        ) {
            return Outcome::refuse('CONTENT_CONTAINS_RAW_PII', [], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        // Scoped by tenant AND workspace, not by id alone. The M08 source re-check reads whatever
        // id this gate validated, so a foreign record admitted here would be trusted there.
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

        $consentKey = [
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'recipient_pseudonym_ref' => $input['recipient_pseudonym_ref'],
            'channel' => $input['channel'],
        ];
        $consent = $this->consent->evaluateForSend($consentKey);
        if ($consent->isRefusal()) {
            return Outcome::refuse('CONSENT_NOT_PERMITTED', [
                'detail' => $consent->reasonCode(),
            ], 'POLICY', 'S5', 'BLOCK_RELEASE');
        }

        $authority = $this->producerAuthorityDefect($input);
        if ($authority !== null) {
            return Outcome::refuse($authority['reason'], ['detail' => $authority['detail']], 'CONTRACT', 'S4');
        }

        $governance = $input['governance'];
        $now = $this->clock->now();
        $outreachId = Ids::new();

        return $this->db->transaction(function (Database $tx) use (
            $input, $governance, $consent, $outreachId, $now
        ): Outcome {
            $tx->insert('outreach_execution_proposals', [
                'outreach_execution_proposal_id' => $outreachId,
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                // A PSEUDONYM. There is no column here for a person.
                'recipient_pseudonym_ref' => $input['recipient_pseudonym_ref'],
                'channel' => $input['channel'],
                'payload_kind' => $input['payload_kind'],
                'exact_payload_reference_safe' => $input['exact_payload_reference_safe'],
                'content_to_scan_safe' => $input['content_to_scan_safe'],
                'source_decision_record_id' => $input['source_decision_record_id'],
                // WHICH consent entry this rests on, frozen. The verdict re-derives the effective
                // consent independently; this records what the producer believed at submission.
                'consent_registry_entry_id' => $consent->value()['consent_registry_entry_id'],
                'proposal_status' => 'DRAFT',
                'created_at' => $now,
            ]);

            $basisSourceRefId = $this->evidence->registerSource(
                $input['tenant_id'],
                $input['workspace_id'],
                'PROJECT_CANONICAL_OBJECT',
                'outreach-basis:' . $outreachId,
                'crm_outreach_execution',
                'INTERNAL_DEMO',
                'ACTIVE_FOR_CAPTURE',
            );
            $captured = $this->evidence->capture([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'source_ref_id' => $basisSourceRefId,
                'evidence_type' => 'CONSENT_STATE_REFERENCE',
                'captured_content_reference_safe' => sprintf(
                    'Consent state at proposal: %s (%s); cites M10 release %s.',
                    $consent->value()['consent_state'],
                    $consent->value()['lawful_basis'],
                    $input['source_decision_record_id'],
                ),
                'content_hash' => Digest::canonicalDigest([
                    'outreachExecutionProposalId' => $outreachId,
                    'consentRegistryEntryId' => $consent->value()['consent_registry_entry_id'],
                    'sourceDecisionRecordId' => $input['source_decision_record_id'],
                ]),
                'capture_method' => 'DERIVED_CONSENT_STATE_REFERENCE',
                'permitted_use_scope' => 'crm_outreach_execution',
                'sensitivity_class' => 'INTERNAL_DEMO',
            ]);
            if ($captured->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $captured->reasonCode(),
                ], 'EVIDENCE');
            }
            $evidenceId = $captured->value()['evidence_id'];

            $bundleId = $this->evidence->createBundleDraft(
                $input['tenant_id'],
                $input['workspace_id'],
                $governance['mission_id'],
                null,
                'outreach consent-state basis evidence',
            );
            $added = $this->evidence->addBundleItem($bundleId, $evidenceId);
            if ($added->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $added->reasonCode(),
                ], 'EVIDENCE');
            }
            $submittedBundle = $this->evidence->submitBundle($bundleId);
            if ($submittedBundle->isRefusal()) {
                return Outcome::refuse('BUNDLE_SUBMISSION_FAILED', [
                    'detail' => $submittedBundle->reasonCode(),
                ], 'EVIDENCE');
            }

            $draft = $this->proposals->createDraft([
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'mission_id' => $governance['mission_id'],
                'producing_valkyrie_id' => $governance['producing_valkyrie_id'],
                'capability_contract_id' => $governance['capability_contract_id'],
                'proposal_type' => 'CRM_OUTREACH_EXECUTION',
                // ANALYZE. What is proposed is a judgement about an outreach, and the M04 ceiling
                // would refuse either external class outright if this asked for one.
                'requested_action_class' => 'ANALYZE',
                'requested_next_stage' => 'HMWH_REVIEW',
                'requested_review_path' => 'HMWH_THEN_ARBITER',
                'title' => 'CRM outreach execution — ' . $input['channel'],
                'safe_purpose_summary' =>
                    'HMWH outreach-compliance review of a consented, pseudonymized outreach. Synthetic; no external effect.',
                'proposed_content_or_action_summary' =>
                    'Prepare a consented, human-approved outreach payload. This projection has no channel and performs no send.',
                'uncertainty_summary' =>
                    'References the canonical released source and the recipient\'s recorded consent; derives no new fact.',
                'declared_limitations' => [
                    'No external effect: this projection implements no channel adapter and no live send.',
                    'Consented, pseudonymized recipient only; no raw PII in any artifact, log or evidence object.',
                ],
                'evidence_refs' => [$evidenceId],
                'evidence_bundle_id' => $bundleId,
                'requested_release_or_action_scope' => json_encode([
                    'recipientPseudonymRef' => $input['recipient_pseudonym_ref'],
                    'channel' => $input['channel'],
                    'payloadKind' => $input['payload_kind'],
                    // The payload DIGEST, not the payload. What the approval will bind to is an
                    // exact artifact, and the digest is how the envelope names it without carrying
                    // its content into the governance plane.
                    'payloadDigest' => Digest::canonicalDigest($input['exact_payload']),
                    'sourceDecisionRecordId' => $input['source_decision_record_id'],
                ], JSON_THROW_ON_ERROR),
            ]);
            if ($draft->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $draft->reasonCode(),
                ], 'POLICY');
            }
            $proposalId = $draft->value()['proposal_id'];

            $readiness = $this->readiness->forProposal($proposalId);
            if ($readiness['requirement_status'] !== 'SATISFIED_FOR_EVALUATION') {
                return Outcome::refuse('EVIDENCE_NOT_READY', [
                    'readiness_status' => $readiness['requirement_status'],
                    'reason_codes' => $readiness['reason_codes'],
                ], 'EVIDENCE');
            }

            $submitted = $this->proposals->submit($proposalId);
            if ($submitted->isRefusal()) {
                return Outcome::refuse('PROPOSAL_SUBMISSION_FAILED', [
                    'detail' => $submitted->reasonCode(),
                ], 'POLICY');
            }
            $tx->run(
                "UPDATE outreach_execution_proposals
                 SET proposal_id = ?, proposal_status = 'PENDING_VERDICT'
                 WHERE outreach_execution_proposal_id = ?",
                [$proposalId, $outreachId]
            );
            $this->appendTransition($tx, $outreachId, 'DRAFT', 'PENDING_VERDICT', 'OUTREACH_PROPOSAL_SUBMITTED');

            return Outcome::ok([
                'outcome' => 'SUBMITTED',
                'outreach_execution_proposal_id' => $outreachId,
                'proposal_id' => $proposalId,
                'evidence_bundle_id' => $bundleId,
                'submission_digest' => $submitted->value()['submission_digest'],
            ]);
        });
    }

    /**
     * Open the M07 outreach-compliance review.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, outreach_execution_proposal_id: string,
     *     review_mode?: string, initiated_by_actor_type: string, initiated_by_actor_id: string
     * } $input
     */
    public function openComplianceReview(array $input): Outcome
    {
        $row = $this->db->one(
            'SELECT tenant_id, workspace_id, proposal_status, proposal_id
             FROM outreach_execution_proposals WHERE outreach_execution_proposal_id = ?',
            [$input['outreach_execution_proposal_id']]
        );
        if ($row === null) {
            return Outcome::refuse('OUTREACH_PROPOSAL_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $row['tenant_id'] !== $input['tenant_id']
            || (string) $row['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('OUTREACH_PROPOSAL_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $row['proposal_status'] !== 'PENDING_VERDICT' || $row['proposal_id'] === null) {
            return Outcome::refuse('OUTREACH_PROPOSAL_NOT_PENDING_VERDICT', [
                'proposal_status' => (string) $row['proposal_status'],
            ], 'POLICY');
        }

        $proposalId = (string) $row['proposal_id'];
        $proposal = $this->db->one(
            'SELECT mission_id, evidence_bundle_id FROM proposal_envelopes WHERE proposal_id = ?',
            [$proposalId]
        );
        if ($proposal === null) {
            return Outcome::refuse('PROPOSAL_NOT_FOUND', [], 'PROVENANCE');
        }

        $opened = $this->council->openSession(
            $input['tenant_id'],
            $input['workspace_id'],
            (string) $proposal['mission_id'],
            $proposalId,
            (string) ($input['review_mode'] ?? 'MULTI_REVIEWER_DEMO'),
            'OUTREACH_COMPLIANCE_REVIEW',
            $input['initiated_by_actor_type'],
            $input['initiated_by_actor_id'],
            $proposal['evidence_bundle_id'] === null ? null : (string) $proposal['evidence_bundle_id'],
        );
        if ($opened->isRefusal()) {
            return Outcome::refuse('REVIEW_NOT_OPENED', [
                'detail' => $opened->reasonCode(),
            ], 'REVIEW');
        }

        return Outcome::ok([
            'review_session_id' => $opened->value()['review_session_id'],
            'proposal_id' => $proposalId,
        ]);
    }

    /**
     * Evaluate the outreach and act on the verdict.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, outreach_execution_proposal_id: string,
     *     arbiter_policy_set_id: string, correlation_id?: ?string
     * } $input
     */
    public function admit(array $input): Outcome
    {
        $row = $this->db->one(
            'SELECT o.tenant_id, o.workspace_id, o.proposal_status, o.proposal_id, pe.proposal_type
             FROM outreach_execution_proposals o
             LEFT JOIN proposal_envelopes pe ON pe.proposal_id = o.proposal_id
             WHERE o.outreach_execution_proposal_id = ?',
            [$input['outreach_execution_proposal_id']]
        );
        if ($row === null) {
            return Outcome::refuse('OUTREACH_PROPOSAL_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $row['tenant_id'] !== $input['tenant_id']
            || (string) $row['workspace_id'] !== $input['workspace_id']
        ) {
            return Outcome::refuse('OUTREACH_PROPOSAL_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $row['proposal_status'] !== 'PENDING_VERDICT' || $row['proposal_id'] === null) {
            return Outcome::refuse('OUTREACH_PROPOSAL_NOT_PENDING_VERDICT', [
                'proposal_status' => (string) $row['proposal_status'],
            ], 'POLICY');
        }
        if ((string) $row['proposal_type'] !== 'CRM_OUTREACH_EXECUTION') {
            return Outcome::refuse('NOT_A_CRM_OUTREACH_PROPOSAL', [
                'proposal_type' => $row['proposal_type'],
            ], 'POLICY');
        }

        // THE POLICY SET IS PINNED BY KEY, not merely required to be applicable. Any ACTIVE set
        // scoped to CRM_OUTREACH_EXECUTION would otherwise do — including one that omits the
        // consent rule entirely. Such a set would pass every check it contained and route straight
        // to the approval verdict, and the outreach would reach APPROVAL_REQUIRED having never
        // been asked about consent at all.
        $policy = $this->db->one(
            'SELECT policy_key, status FROM arbiter_policy_sets WHERE arbiter_policy_set_id = ?',
            [$input['arbiter_policy_set_id']]
        );
        if ($policy === null
            || (string) $policy['policy_key'] !== 'P07_OUTREACH_EXECUTION_ADMISSION'
            || (string) $policy['status'] !== 'ACTIVE'
        ) {
            return Outcome::refuse('POLICY_NOT_P07_ADMISSION', [
                'policy_key' => $policy === null ? null : (string) $policy['policy_key'],
            ], 'POLICY', 'S4');
        }

        $proposalId = (string) $row['proposal_id'];

        return $this->db->transaction(function (Database $tx) use ($input, $proposalId): Outcome {
            $decision = $this->arbiter->evaluateProposal(
                $proposalId,
                $input['arbiter_policy_set_id'],
                $input['correlation_id'] ?? null,
            );
            if ($decision->isRefusal()) {
                return Outcome::refuse('EVALUATION_REJECTED', [
                    'detail' => $decision->reasonCode(),
                ], 'POLICY');
            }

            $result = $decision->value();
            $verdict = (string) $result['verdict'];
            $outreachId = $input['outreach_execution_proposal_id'];

            // The BEST outcome available here is "a human must decide". There is no verdict in this
            // vocabulary that authorizes a send, because there is nothing to send with.
            if ($verdict === 'REQUIRE_HUMAN_APPROVAL') {
                $tx->run(
                    "UPDATE outreach_execution_proposals
                     SET proposal_status = 'APPROVAL_REQUIRED', arbiter_verdict_id = ?
                     WHERE outreach_execution_proposal_id = ?",
                    [$result['arbiter_verdict_id'], $outreachId]
                );
                $this->appendTransition(
                    $tx, $outreachId, 'PENDING_VERDICT', 'APPROVAL_REQUIRED', 'ROUTED_TO_HUMAN_APPROVAL'
                );

                return Outcome::ok([
                    'outcome' => 'APPROVAL_REQUIRED',
                    'verdict' => $verdict,
                    'arbiter_verdict_id' => $result['arbiter_verdict_id'],
                    'permitted_next_stage' => $result['permitted_next_stage'],
                ]);
            }

            $terminates = in_array($verdict, self::TERMINATING_VERDICTS, true);
            if ($terminates) {
                $tx->run(
                    "UPDATE outreach_execution_proposals SET proposal_status = 'BLOCKED'
                     WHERE outreach_execution_proposal_id = ?",
                    [$outreachId]
                );
                $this->appendTransition($tx, $outreachId, 'PENDING_VERDICT', 'BLOCKED', $verdict);
            }

            return Outcome::ok([
                'outcome' => 'REFUSED',
                'verdict' => $verdict,
                'arbiter_verdict_id' => $result['arbiter_verdict_id'],
                'reason_codes' => $result['reason_codes'],
                'outreach_blocked' => $terminates,
            ]);
        });
    }

    /**
     * The five producer-authority conditions, each named.
     *
     * @param array<string, mixed> $input
     * @return array{reason: string, detail: string}|null
     */
    private function producerAuthorityDefect(array $input): ?array
    {
        $governance = $input['governance'];
        $now = $this->clock->now();

        $producer = $this->db->one(
            'SELECT tenant_id, status FROM valkyries WHERE valkyrie_id = ?',
            [$governance['producing_valkyrie_id']]
        );
        if ($producer === null
            || (string) $producer['tenant_id'] !== $input['tenant_id']
            || (string) $producer['status'] !== 'ACTIVE'
        ) {
            return ['reason' => 'PRODUCER_NOT_AUTHORIZED', 'detail' => 'valkyrie'];
        }

        $contract = $this->db->one(
            'SELECT valkyrie_id, tenant_id, workspace_id, status, valid_from, valid_until
             FROM capability_contracts WHERE capability_contract_id = ?',
            [$governance['capability_contract_id']]
        );
        if ($contract === null
            || (string) $contract['tenant_id'] !== $input['tenant_id']
            || (string) $contract['status'] !== 'ACTIVE'
            || (string) $contract['valkyrie_id'] !== $governance['producing_valkyrie_id']
            || ($contract['workspace_id'] !== null && (string) $contract['workspace_id'] !== $input['workspace_id'])
            || (string) $contract['valid_from'] > $now
            || ($contract['valid_until'] !== null && (string) $contract['valid_until'] <= $now)
        ) {
            return ['reason' => 'PRODUCER_NOT_AUTHORIZED', 'detail' => 'contract'];
        }

        $binding = $this->db->one(
            "SELECT 1 AS ok FROM output_class_bindings
             WHERE capability_contract_id = ? AND output_class = ? AND status = 'ACTIVE'",
            [$governance['capability_contract_id'], self::OUTPUT_CLASS]
        );
        if ($binding === null) {
            return ['reason' => 'PRODUCER_NOT_AUTHORIZED', 'detail' => 'output_class'];
        }

        $mission = $this->db->one(
            'SELECT tenant_id, workspace_id, status, permitted_output_classes
             FROM missions WHERE mission_id = ?',
            [$governance['mission_id']]
        );
        if ($mission === null) {
            return ['reason' => 'MISSION_NOT_FOUND', 'detail' => 'mission'];
        }
        if ((string) $mission['tenant_id'] !== $input['tenant_id']
            || (string) $mission['workspace_id'] !== $input['workspace_id']
        ) {
            return ['reason' => 'MISSION_SCOPE_MISMATCH', 'detail' => 'mission'];
        }
        if ((string) $mission['status'] !== 'ACTIVE') {
            return ['reason' => 'MISSION_NOT_ACTIVE', 'detail' => 'mission'];
        }
        $missionOutputs = json_decode((string) $mission['permitted_output_classes'], true, 512, JSON_THROW_ON_ERROR);
        if (!in_array(self::OUTPUT_CLASS, $missionOutputs, true)) {
            return ['reason' => 'MISSION_OUTPUT_NOT_AUTHORIZED', 'detail' => 'mission'];
        }

        // The ASSIGNMENT is the narrowest gate: a contract says what an agent may ever do, and an
        // assignment says what it may do on THIS mission. Both the action class and the output
        // class must be named, because either alone would let one half be assumed.
        $assignment = $this->db->one(
            "SELECT assigned_action_classes, assigned_output_classes FROM mission_assignments
             WHERE tenant_id = ? AND workspace_id = ? AND mission_id = ? AND valkyrie_id = ?
               AND capability_contract_id = ? AND status = 'ACTIVE'
               AND (valid_until IS NULL OR valid_until > ?)
             LIMIT 1",
            [
                $input['tenant_id'],
                $input['workspace_id'],
                $governance['mission_id'],
                $governance['producing_valkyrie_id'],
                $governance['capability_contract_id'],
                $now,
            ]
        );
        if ($assignment === null) {
            return ['reason' => 'PRODUCER_NOT_AUTHORIZED', 'detail' => 'mission_assignment'];
        }
        $actions = json_decode((string) $assignment['assigned_action_classes'], true, 512, JSON_THROW_ON_ERROR);
        $outputs = json_decode((string) $assignment['assigned_output_classes'], true, 512, JSON_THROW_ON_ERROR);
        if (!in_array('ANALYZE', $actions, true) || !in_array(self::OUTPUT_CLASS, $outputs, true)) {
            return ['reason' => 'PRODUCER_NOT_AUTHORIZED', 'detail' => 'mission_assignment'];
        }

        return null;
    }

    private function appendTransition(
        Database $tx,
        string $outreachId,
        ?string $fromStatus,
        string $toStatus,
        string $reasonCode,
    ): void {
        $tx->insert('outreach_execution_proposal_status_transitions', [
            'transition_id' => Ids::new(),
            'schema_version' => '1',
            'outreach_execution_proposal_id' => $outreachId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reasonCode,
            'transitioned_at' => $this->clock->now(),
        ]);
    }
}
