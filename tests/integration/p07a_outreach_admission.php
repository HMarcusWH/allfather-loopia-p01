<?php

declare(strict_types=1);

/**
 * P07-A outreach admission: the governed decision about whether an outreach would be permissible.
 *
 * THE CLAIM UNDER TEST IS AS MUCH ABOUT WHAT IS ABSENT AS WHAT IS PRESENT. The chain runs from a
 * consented, pseudonymized recipient through M04, M05, M07 and M08 to a verdict — and the BEST
 * verdict available is "a human must decide". There is no state, verdict or code path in this
 * projection that sends anything, and the test asserts that structurally rather than by attempting
 * a send and being refused, because a refusal would imply the path exists.
 *
 * The producer-authority gate gets its own section. Every plane downstream reviews the outreach
 * FACTS; none of them asks whether this agent was entitled to propose an outreach at all.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P07A\Consent;
use Allfather\Loopia\Product\P07A\OutreachAdmission;
use Allfather\Loopia\Product\P07A\PiiFirewall;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);

$ledger = new DecisionLedger($db, $clock);
$evidence = new EvidenceSpine($db, $clock);
$readiness = new Readiness($db, $clock);
$arbiter = new Arbiter($db, $clock, $readiness);
$council = new Council($db, $clock);
$proposals = new Proposals($db, $clock);
$consent = new Consent($db, $clock);
$firewall = new PiiFirewall($db, $clock);

$admission = new OutreachAdmission(
    $db, $clock, $evidence, $readiness, $proposals, $council, $arbiter, $ledger, $consent
);

$policySetId = (new PolicySets($db, $clock))->seed(
    PolicySets::P07_OUTREACH_EXECUTION_ADMISSION, $scope['tenant_id'], $scope['workspace_id']
)->value()['arbiter_policy_set_id'];

$producer = Seed::p07OutreachProducer($db, $clock, $scope);

$pseudonym = $firewall->pseudonymize([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'channel' => 'EMAIL',
    'raw_recipient_identifier' => 'anna.berg@example.com',
])->value()['recipient_pseudonym_ref'];

$consent->record([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'recipient_pseudonym_ref' => $pseudonym,
    'channel' => 'EMAIL',
    'consent_state' => 'OPTED_IN',
    'lawful_basis' => 'CONSENT',
    'basis_reference_safe' => 'consent-form://2026-01-02',
    'recorded_at' => '2026-01-02T00:00:00.000Z',
]);

$governance = [
    'mission_id' => $producer['mission_id'],
    'producing_valkyrie_id' => $producer['producing_valkyrie_id'],
    'capability_contract_id' => $producer['capability_contract_id'],
];
$submitInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'recipient_pseudonym_ref' => $pseudonym,
    'channel' => 'EMAIL',
    'payload_kind' => 'EMAIL_SUBJECT_BODY',
    'exact_payload' => ['subject' => 'Your renewal terms', 'body' => 'We hold list price for the term.'],
    'exact_payload_reference_safe' => 'payload://renewal-terms-v1',
    'content_to_scan_safe' => 'Your renewal terms. We hold list price for the term.',
    'source_decision_record_id' => $producer['source_decision_record_id'],
    'governance' => $governance,
];

// ============================================================ there is no send path

// Asserted against the SCHEMA. The outreach proposal's status vocabulary tops out at
// APPROVAL_REQUIRED; there is no SENT, DISPATCHED or DELIVERED member to reach.
$statusCheck = (string) $db->scalar(
    "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'outreach_execution_proposals'
       AND CHECK_CLAUSE LIKE '%proposal_status%'"
);
foreach (['SENT', 'DISPATCHED', 'DELIVERED', 'EXECUTED'] as $forbidden) {
    Assert::false(
        str_contains($statusCheck, $forbidden),
        "the outreach status vocabulary has no {$forbidden} member",
    );
}

// The channel binding table exists and is STRUCTURALLY inert: the pins are CHECK constraints, not
// application logic, so no code — ported or otherwise — can write a permitting row.
$bindingChecks = $db->all(
    "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND (CHECK_CLAUSE LIKE '%external_effect_permitted%' OR CHECK_CLAUSE LIKE '%NO_OP_TEST_EVENT%')"
);
$clauses = implode(' | ', array_map(static fn (array $r): string => (string) $r['CHECK_CLAUSE'], $bindingChecks));
Assert::contains(
    'external_effect_permitted',
    $clauses,
    'external effect is CHECK-pinned off — a pin, not a policy',
);
Assert::contains('NO_OP_TEST_EVENT', $clauses, 'and the only admissible adapter is the no-op');

// And the projection ships no producer for that table at all, which is stronger than the pins:
// there is no code path that could write even a permitted-off binding row.
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM outreach_channel_bindings'),
    'no channel binding exists, and this projection ports no function that creates one',
);

// ============================================================ the producer-authority gate

// Each of the five conditions on its own. Every downstream plane reviews the outreach FACTS; this
// is the only gate that asks whether the agent was entitled to propose an outreach at all.
$foreignScope = Seed::tenantWorkspace($db, $clock, 'rival');
$foreignProducer = Seed::p07OutreachProducer($db, $clock, $foreignScope);

$authorityCases = [
    'an unknown Valkyrie' => ['producing_valkyrie_id' => Ids::new()],
    "another tenant's Valkyrie" => ['producing_valkyrie_id' => $foreignProducer['producing_valkyrie_id']],
    'an unknown contract' => ['capability_contract_id' => Ids::new()],
    "another producer's contract" => ['capability_contract_id' => $foreignProducer['capability_contract_id']],
];
foreach ($authorityCases as $label => $override) {
    Assert::same(
        'PRODUCER_NOT_AUTHORIZED',
        $admission->submitProposal([
            ...$submitInput,
            'governance' => [...$governance, ...$override],
        ])->reasonCode(),
        "{$label} cannot produce an outreach proposal",
    );
}

// A contract with no CRM-outreach OUTPUT binding. The agent may analyse; producing an outreach
// proposal is a separate grant, and this proves the two are genuinely separate.
//
// A SECOND VALKYRIE, not a second contract on the producer. An earlier revision issued the unbound
// contract to the producing Valkyrie, which left it holding TWO ACTIVE contracts — the state
// `ux_capability_contracts_one_active_per_valkyrie` exists to forbid, because an agent's authority
// then depends on which row is read first. The fixture was building the invalid state it was
// testing around; the invariant is now enforced in the database and this seat is its own agent.
$m03 = new \Allfather\Loopia\Substrate\M03\Registry($db, $clock);
$unboundValkyrieId = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Unbound CRM Producer',
    'CRM_EXECUTION_VALKYRIE', 'Holds an analysis contract with no outreach output binding.',
    $scope['operator_id'], 'ACTIVE'
);
$unboundContract = $m03->issueContract(
    $unboundValkyrieId, $scope['tenant_id'], $scope['workspace_id'],
    'p07.unbound', 'CRM_EXECUTION_VALKYRIE', ['OBSERVE', 'ANALYZE']
)->value()['capability_contract_id'];
$unbound = $admission->submitProposal([
    ...$submitInput,
    'governance' => [
        ...$governance,
        'producing_valkyrie_id' => $unboundValkyrieId,
        'capability_contract_id' => $unboundContract,
    ],
]);
Assert::same('PRODUCER_NOT_AUTHORIZED', $unbound->reasonCode(), 'a contract with no output binding cannot');
Assert::same(
    'output_class',
    $unbound->detail['detail'],
    'and the refusal names WHICH of the five conditions failed',
);

// ============================================================ the PII firewall, three ways

$piiCases = [
    'the scannable content' => ['content_to_scan_safe' => 'Reply to anna.berg@example.com for terms.'],
    'the payload reference' => ['exact_payload_reference_safe' => 'payload://anna.berg@example.com'],
    // The EXACT payload is what would reach a person. A clean parallel scan string proves nothing
    // about it, which is why it is scanned deep and separately.
    'the exact payload body' => ['exact_payload' => ['subject' => 'Terms', 'body' => 'Call +46 70 123 45 67.']],
    'an exact payload KEY' => ['exact_payload' => ['anna.berg@example.com' => 'preferred']],
];
foreach ($piiCases as $where => $override) {
    Assert::same(
        'CONTENT_CONTAINS_RAW_PII',
        $admission->submitProposal([...$submitInput, ...$override])->reasonCode(),
        "raw PII in {$where} is refused before any proposal exists",
    );
}

// ============================================================ consent gates the submission

$noConsentPseudonym = $firewall->pseudonymize([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'channel' => 'EMAIL',
    'raw_recipient_identifier' => 'bo.lind@example.com',
])->value()['recipient_pseudonym_ref'];
$noConsent = $admission->submitProposal([
    ...$submitInput,
    'recipient_pseudonym_ref' => $noConsentPseudonym,
]);
Assert::same(
    'CONSENT_NOT_PERMITTED',
    $noConsent->reasonCode(),
    'a recipient with no consent on record cannot be proposed to',
);
Assert::same(
    'NO_CONSENT_ON_RECORD',
    $noConsent->detail['detail'],
    'carrying the specific consent reason rather than a generic refusal',
);

// The source must be in scope AND still relied upon.
Assert::same(
    'SOURCE_NOT_FOUND',
    $admission->submitProposal([
        ...$submitInput,
        'source_decision_record_id' => $foreignProducer['source_decision_record_id'],
    ])->reasonCode(),
    "another workspace's decision record is not a source this outreach can cite",
);

// ============================================================ the governed chain

$submitted = $admission->submitProposal($submitInput);
Assert::true($submitted->isOk(), 'a consented, pseudonymized, authorized outreach submits');
$outreachId = $submitted->value()['outreach_execution_proposal_id'];
$proposalId = $submitted->value()['proposal_id'];

$envelope = $db->one(
    'SELECT proposal_type, requested_action_class, declared_limitations, requested_release_or_action_scope
     FROM proposal_envelopes WHERE proposal_id = ?',
    [$proposalId]
);
Assert::same('CRM_OUTREACH_EXECUTION', (string) $envelope['proposal_type'], 'declaring what it is');
// ANALYZE. What is proposed is a JUDGEMENT about an outreach, and the M04 ceiling would refuse
// either external class outright if this asked for one.
Assert::same(
    'ANALYZE',
    (string) $envelope['requested_action_class'],
    'under ANALYZE — a judgement about an outreach, not an outreach',
);
Assert::contains(
    'no channel adapter',
    (string) $envelope['declared_limitations'],
    'and the envelope says so where a reviewer reads it',
);
// The envelope carries the payload DIGEST, never the payload. The governance plane names the
// artifact without carrying its content.
Assert::contains('"payloadDigest"', (string) $envelope['requested_release_or_action_scope'], 'binding the payload by digest');
Assert::false(
    str_contains((string) $envelope['requested_release_or_action_scope'], 'renewal'),
    'and NOT the payload body itself',
);

// The consent-state evidence is a DERIVED reference, never canonical fact.
$basis = $db->one(
    "SELECT e.evidence_type, e.capture_method FROM evidence_objects e
     JOIN evidence_bundle_items i ON i.evidence_id = e.evidence_id
     WHERE i.evidence_bundle_id = ?",
    [$submitted->value()['evidence_bundle_id']]
);
Assert::same(
    'CONSENT_STATE_REFERENCE',
    (string) $basis['evidence_type'],
    'the basis records the consent state as a reference',
);
Assert::same('DERIVED_CONSENT_STATE_REFERENCE', (string) $basis['capture_method'], 'derived, not observed');

// ============================================================ review and verdict

$review = $admission->openComplianceReview([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'outreach_execution_proposal_id' => $outreachId,
    'initiated_by_actor_type' => 'OPERATOR',
    'initiated_by_actor_id' => $scope['operator_id'],
]);
Assert::true($review->isOk(), 'the outreach-compliance review opens');
$sessionId = $review->value()['review_session_id'];
Assert::same(
    'OUTREACH_COMPLIANCE_REVIEW',
    (string) $db->scalar(
        'SELECT review_purpose FROM hmwh_review_sessions WHERE review_session_id = ?',
        [$sessionId]
    ),
    'under its own review purpose',
);

$assignmentId = $council->assignParticipant(
    $sessionId, $producer['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
)->value()['participant_assignment_id'];
$council->recordAssessment(
    $sessionId, $assignmentId, $producer['critic_valkyrie_id'],
    'SUPPORT_PROCEED_TO_ARBITER', 'Consented, pseudonymized, no prohibited content.'
);
$council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');

// A policy set that is ACTIVE and applicable but is NOT the P07 admission set is refused. Such a
// set would pass every check it contained and route straight to the approval verdict, and the
// outreach would reach APPROVAL_REQUIRED having never been asked about consent at all.
$wrongPolicy = (new PolicySets($db, $clock))->seed(
    PolicySets::P04_DISTRIBUTION_ADMISSION, $scope['tenant_id'], $scope['workspace_id']
)->value()['arbiter_policy_set_id'];
Assert::same(
    'POLICY_NOT_P07_ADMISSION',
    $admission->admit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'outreach_execution_proposal_id' => $outreachId,
        'arbiter_policy_set_id' => $wrongPolicy,
    ])->reasonCode(),
    'the policy set is pinned by KEY — a merely-applicable set cannot stand in for it',
);

$admitted = $admission->admit([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'outreach_execution_proposal_id' => $outreachId,
    'arbiter_policy_set_id' => $policySetId,
]);
Assert::true($admitted->isOk(), 'a clean outreach reaches a verdict');

// THE BEST AVAILABLE OUTCOME IS "A HUMAN MUST DECIDE". Not "sent", not "authorized to send".
Assert::same(
    'APPROVAL_REQUIRED',
    $admitted->value()['outcome'],
    'and the best outcome available is that a human must decide',
);
Assert::same(
    'REQUIRE_HUMAN_APPROVAL',
    $admitted->value()['verdict'],
    'there is no verdict in this vocabulary that authorizes a send',
);
Assert::same(
    'M09_APPROVAL_REQUEST_CREATION',
    $admitted->value()['permitted_next_stage'],
    'routing to the approval seat, which is where this projection stops',
);
Assert::same(
    'APPROVAL_REQUIRED',
    (string) $db->scalar(
        'SELECT proposal_status FROM outreach_execution_proposals WHERE outreach_execution_proposal_id = ?',
        [$outreachId]
    ),
    'and the outreach rests there',
);
