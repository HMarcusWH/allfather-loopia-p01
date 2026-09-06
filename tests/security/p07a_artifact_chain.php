<?php

declare(strict_types=1);

/**
 * The P07-A approval, artifact and dispatch chain, proved by REVERSION.
 *
 * THE CENTRAL CLAIM IS THE DIGEST CHAIN. Four values must be equal for anything to proceed:
 *
 *     proposal digest = approval digest = frozen digest = observed digest
 *
 * Each link is broken in turn, and each break is refused at the moment it is crossed. What that
 * buys is a hash-level proof — not a process claim — that the content a person approved is the
 * content that would have gone out.
 *
 * THE FACTS ARE BOUND TOO. An approval reused to freeze the SAME words for a DIFFERENT recipient
 * is the subtle attack: every content gate passes, and the person who receives it was never
 * reviewed. That is tested on its own.
 *
 * And separation of duties is proved by having the drafter try to approve their own outreach.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P07A\Consent;
use Allfather\Loopia\Product\P07A\OutreachAdmission;
use Allfather\Loopia\Product\P07A\OutreachApproval;
use Allfather\Loopia\Product\P07A\OutreachArtifacts;
use Allfather\Loopia\Product\P07A\PiiFirewall;
use Allfather\Loopia\Product\P07A\SyntheticDelivery;
use Allfather\Loopia\Substrate\M02\AccessGuard;
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
$access = new AccessGuard($db, $clock);
$consent = new Consent($db, $clock);
$firewall = new PiiFirewall($db, $clock);

$admission = new OutreachAdmission(
    $db, $clock, $evidence, $readiness, $proposals, $council, $arbiter, $ledger, $consent
);
$approval = new OutreachApproval($db, $clock, $access);
$artifacts = new OutreachArtifacts($db, $clock, $ledger, $consent);
$delivery = new SyntheticDelivery($db, $clock, $ledger, $consent, $artifacts);

$policySetId = (new PolicySets($db, $clock))->seed(
    PolicySets::P07_OUTREACH_EXECUTION_ADMISSION, $scope['tenant_id'], $scope['workspace_id']
)->value()['arbiter_policy_set_id'];

// The two seats: a CRM approver who may decide, and a drafter who may not.
$approverRole = Seed::role($db, $clock, 'crm_approver', ['approval.read', 'approval.decide_exact_artifact', 'outreach.approve']);
$drafterRole = Seed::role($db, $clock, 'crm_drafter', ['approval.read', 'approval.decide_exact_artifact', 'outreach.draft']);
$approverId = Seed::operator($db, $clock, $scope['tenant_id'], 'CRM Approver');
$drafterId = Seed::operator($db, $clock, $scope['tenant_id'], 'CRM Drafter');
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $approverId, $approverRole['role_id']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $drafterId, $drafterRole['role_id']);

$payload = ['subject' => 'Your renewal terms', 'body' => 'We hold list price for the term.'];
$payloadRef = 'payload://renewal-terms-v1';

/** Drive one outreach from a fresh producer all the way to an APPROVAL_REQUIRED verdict. */
$runToApprovalRequired = static function (array $overrides = []) use (
    $db, $clock, $scope, $admission, $council, $consent, $firewall, $policySetId, $payload, $payloadRef
): array {
    $producer = Seed::p07OutreachProducer($db, $clock, $scope);
    $identifier = $overrides['identifier'] ?? ('anna.' . substr(Ids::new(), 0, 8) . '@example.com');
    $pseudonym = $firewall->pseudonymize([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'channel' => 'EMAIL',
        'raw_recipient_identifier' => $identifier,
    ])->value()['recipient_pseudonym_ref'];
    $consent->record([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'recipient_pseudonym_ref' => $pseudonym,
        'channel' => 'EMAIL',
        'consent_state' => 'OPTED_IN',
        'lawful_basis' => 'CONSENT',
        'recorded_at' => '2026-01-02T00:00:00.000Z',
    ]);

    $submitted = $admission->submitProposal([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'recipient_pseudonym_ref' => $pseudonym,
        'channel' => 'EMAIL',
        'payload_kind' => 'EMAIL_SUBJECT_BODY',
        'exact_payload' => $payload,
        'exact_payload_reference_safe' => $payloadRef,
        'content_to_scan_safe' => 'Your renewal terms. We hold list price for the term.',
        'source_decision_record_id' => $producer['source_decision_record_id'],
        'governance' => [
            'mission_id' => $producer['mission_id'],
            'producing_valkyrie_id' => $producer['producing_valkyrie_id'],
            'capability_contract_id' => $producer['capability_contract_id'],
        ],
    ]);
    $outreachId = $submitted->value()['outreach_execution_proposal_id'];

    $sessionId = $admission->openComplianceReview([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'outreach_execution_proposal_id' => $outreachId,
        'initiated_by_actor_type' => 'OPERATOR',
        'initiated_by_actor_id' => $scope['operator_id'],
    ])->value()['review_session_id'];
    $assignmentId = $council->assignParticipant(
        $sessionId, $producer['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
    )->value()['participant_assignment_id'];
    $council->recordAssessment(
        $sessionId, $assignmentId, $producer['critic_valkyrie_id'], 'SUPPORT_PROCEED_TO_ARBITER', 'Reviewed.'
    );
    $council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');

    $admitted = $admission->admit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'outreach_execution_proposal_id' => $outreachId,
        'arbiter_policy_set_id' => $policySetId,
    ]);

    return [
        'producer' => $producer,
        'pseudonym' => $pseudonym,
        'outreach_execution_proposal_id' => $outreachId,
        'arbiter_verdict_id' => $admitted->value()['arbiter_verdict_id'],
        'source_decision_record_id' => $producer['source_decision_record_id'],
    ];
};

$base = $runToApprovalRequired();

// ============================================================ the approval binds derived values

$requested = $approval->requestApproval([
    'arbiter_verdict_id' => $base['arbiter_verdict_id'],
    'created_by_actor_type' => 'OPERATOR',
    'created_by_actor_id' => $scope['operator_id'],
]);
Assert::true($requested->isOk(), 'an admitted outreach opens an approval request');
$requestId = $requested->value()['approval_request_id'];

// The digest is DERIVED from the immutable proposal, not supplied. A caller cannot open an
// approval for one payload and have the frozen artifact bind to another.
Assert::same(
    Digest::canonicalDigest($payload),
    $requested->value()['exact_normalized_payload_digest'],
    'the approval binds the digest the PROPOSAL froze — derived, never passed',
);

// The M09 ceiling is untouched: this is categorically a harmless demo action on a no-op adapter.
$requestRow = $db->one(
    'SELECT action_category, requested_adapter_type, required_approver_permission, status
     FROM approval_requests WHERE approval_request_id = ?',
    [$requestId]
);
Assert::same('HARMLESS_DEMO_ACTION', (string) $requestRow['action_category'], 'the M09 ceiling is untouched');
Assert::same('NO_OP_TEST_EVENT', (string) $requestRow['requested_adapter_type'], 'against a no-op adapter');
Assert::same(
    'outreach.approve',
    (string) $requestRow['required_approver_permission'],
    'demanding the SEPARATE CRM approver seat',
);

// ============================================================ separation of duties

// The drafter holds the GENERIC approval permission but not the outreach seat. If only the generic
// one were checked they would pass — so this is the assertion that proves both are checked.
$decisionInput = [
    'approval_request_id' => $requestId,
    'decision' => 'APPROVE_EXACT_ARTIFACT',
    'exact_payload_digest_confirmed' => Digest::canonicalDigest($payload),
    'decision_reason_summary' => 'Reviewed and approved.',
];
Assert::same(
    'APPROVER_NOT_AUTHORIZED',
    $approval->recordDecision([...$decisionInput, 'approver_operator_id' => $drafterId])->reasonCode(),
    'the drafter holds the generic approval permission and STILL cannot approve an outreach',
);

// A digest the approver did not confirm invalidates the decision. An approval is OF a specific
// artifact; "approximately that artifact" is not something a person can consent to.
Assert::same(
    'APPROVAL_REQUEST_NOT_EXACT',
    $approval->recordDecision([
        ...$decisionInput,
        'approver_operator_id' => $approverId,
        'exact_payload_digest_confirmed' => Digest::canonicalDigest(['subject' => 'Different', 'body' => 'Words.']),
    ])->reasonCode(),
    'a digest the approver did not confirm invalidates the decision',
);

$decided = $approval->recordDecision([...$decisionInput, 'approver_operator_id' => $approverId]);
Assert::true($decided->isOk(), 'the CRM approver records the decision');
$decisionId = $decided->value()['approval_decision_id'];
Assert::notSame(
    null,
    $db->scalar('SELECT access_evaluation_id FROM approval_decisions WHERE approval_decision_id = ?', [$decisionId]),
    'and the decision cites WHICH access evaluation authorized them',
);

// ============================================================ the digest chain at freeze

$freezeInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'recipient_pseudonym_ref' => $base['pseudonym'],
    'channel' => 'EMAIL',
    'payload_kind' => 'EMAIL_SUBJECT_BODY',
    'exact_payload' => $payload,
    'exact_payload_reference_safe' => $payloadRef,
    'source_decision_record_id' => $base['source_decision_record_id'],
    'approval_request_id' => $requestId,
    'approval_decision_id' => $decisionId,
];

// LINK THREE, broken: a payload that is not the one approved.
Assert::same(
    'APPROVAL_PAYLOAD_DIGEST_MISMATCH',
    $artifacts->freeze([
        ...$freezeInput,
        'exact_payload' => ['subject' => 'Your renewal terms', 'body' => 'We may raise the price.'],
    ])->reasonCode(),
    'a payload that is not the approved one cannot be frozen',
);
Assert::same(
    'APPROVAL_PAYLOAD_REFERENCE_MISMATCH',
    $artifacts->freeze([...$freezeInput, 'exact_payload_reference_safe' => 'payload://something-else'])->reasonCode(),
    'nor a different payload reference',
);

// THE FACTS. The same approved words, for a DIFFERENT consented recipient. Every content gate
// passes; the recipient was never reviewed. This is the subtle one.
$otherRecipient = $runToApprovalRequired();
Assert::same(
    'OUTREACH_FACTS_MISMATCH',
    $artifacts->freeze([...$freezeInput, 'recipient_pseudonym_ref' => $otherRecipient['pseudonym']])->reasonCode(),
    'the approved words for a DIFFERENT recipient are refused — the approval binds the facts too',
);

// A withdrawal between the approval and the freeze is honoured.
$consent->record([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'recipient_pseudonym_ref' => $base['pseudonym'],
    'channel' => 'EMAIL',
    'consent_state' => 'WITHDRAWN',
    'lawful_basis' => 'CONSENT',
    'recorded_at' => '2026-06-01T00:00:00.000Z',
]);
$withdrawnFreeze = $artifacts->freeze($freezeInput);
Assert::same(
    'CONSENT_NOT_PERMITTED',
    $withdrawnFreeze->reasonCode(),
    'a withdrawal recorded after the approval is honoured at the freeze',
);
Assert::same('CONSENT_WITHDRAWN', $withdrawnFreeze->detail['detail'], 'naming the act');

// Restore consent and freeze for real.
$consent->record([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'recipient_pseudonym_ref' => $base['pseudonym'],
    'channel' => 'EMAIL',
    'consent_state' => 'OPTED_IN',
    'lawful_basis' => 'CONSENT',
    'recorded_at' => '2026-06-02T00:00:00.000Z',
]);
$frozen = $artifacts->freeze($freezeInput);
Assert::true($frozen->isOk(), 'the approved payload freezes');
$artifactId = $frozen->value()['frozen_outreach_artifact_id'];
Assert::same(
    Digest::canonicalDigest($payload),
    $frozen->value()['payload_digest'],
    'carrying the digest the approval bound',
);

// ============================================================ single use, and no retry

$issued = $artifacts->issueAuthorization([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'frozen_outreach_artifact_id' => $artifactId,
]);
Assert::true($issued->isOk(), 'a single-use authorization is issued');
$authId = $issued->value()['outreach_execution_authorization_id'];
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT single_use_required FROM outreach_execution_authorizations
         WHERE outreach_execution_authorization_id = ?',
        [$authId]
    ),
    'pinned to single use — in the code AND in a storage CHECK',
);
Assert::same(
    'ARTIFACT_NOT_FROZEN',
    $artifacts->issueAuthorization([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'frozen_outreach_artifact_id' => $artifactId,
    ])->reasonCode(),
    'and a second authorization for the same artifact is refused',
);

// LINK FOUR, broken: a mutated payload at consume blocks BOTH the authorization and the artifact.
$mutationArtifact = $runToApprovalRequired();
$mutationRequest = $approval->requestApproval([
    'arbiter_verdict_id' => $mutationArtifact['arbiter_verdict_id'],
    'created_by_actor_type' => 'OPERATOR',
    'created_by_actor_id' => $scope['operator_id'],
])->value()['approval_request_id'];
$mutationDecision = $approval->recordDecision([
    'approval_request_id' => $mutationRequest,
    'approver_operator_id' => $approverId,
    'decision' => 'APPROVE_EXACT_ARTIFACT',
    'exact_payload_digest_confirmed' => Digest::canonicalDigest($payload),
    'decision_reason_summary' => 'Approved.',
])->value()['approval_decision_id'];
$mutationFrozen = $artifacts->freeze([
    ...$freezeInput,
    'recipient_pseudonym_ref' => $mutationArtifact['pseudonym'],
    'source_decision_record_id' => $mutationArtifact['source_decision_record_id'],
    'approval_request_id' => $mutationRequest,
    'approval_decision_id' => $mutationDecision,
])->value()['frozen_outreach_artifact_id'];
$mutationAuth = $artifacts->issueAuthorization([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'frozen_outreach_artifact_id' => $mutationFrozen,
])->value()['outreach_execution_authorization_id'];

Assert::same(
    'FROZEN_PAYLOAD_MUTATED',
    $artifacts->consume([
        'outreach_execution_authorization_id' => $mutationAuth,
        'observed_payload_digest' => Digest::canonicalDigest(['subject' => 'Tampered', 'body' => 'Different.']),
    ])->reasonCode(),
    'a payload that changed after freezing is refused at consume',
);
// BOTH are blocked, not just this attempt: an artifact whose payload changed is not one to retry.
Assert::same(
    'REVOKED',
    (string) $db->scalar(
        'SELECT authorization_status FROM outreach_execution_authorizations
         WHERE outreach_execution_authorization_id = ?',
        [$mutationAuth]
    ),
    'and the authorization is REVOKED, not merely left unused',
);
Assert::same(
    'REVOKED',
    (string) $db->scalar(
        'SELECT artifact_status FROM frozen_outreach_artifacts WHERE frozen_outreach_artifact_id = ?',
        [$mutationFrozen]
    ),
    'along with the artifact — a mutated artifact is not one to try again with',
);

// ============================================================ the stand-down lever

$standDown = $runToApprovalRequired();
$sdRequest = $approval->requestApproval([
    'arbiter_verdict_id' => $standDown['arbiter_verdict_id'],
    'created_by_actor_type' => 'OPERATOR',
    'created_by_actor_id' => $scope['operator_id'],
])->value()['approval_request_id'];
$sdDecision = $approval->recordDecision([
    'approval_request_id' => $sdRequest,
    'approver_operator_id' => $approverId,
    'decision' => 'APPROVE_EXACT_ARTIFACT',
    'exact_payload_digest_confirmed' => Digest::canonicalDigest($payload),
    'decision_reason_summary' => 'Approved.',
])->value()['approval_decision_id'];
$sdFrozen = $artifacts->freeze([
    ...$freezeInput,
    'recipient_pseudonym_ref' => $standDown['pseudonym'],
    'source_decision_record_id' => $standDown['source_decision_record_id'],
    'approval_request_id' => $sdRequest,
    'approval_decision_id' => $sdDecision,
])->value()['frozen_outreach_artifact_id'];
$sdAuth = $artifacts->issueAuthorization([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'frozen_outreach_artifact_id' => $sdFrozen,
])->value()['outreach_execution_authorization_id'];

// The lever cannot reach another workspace's outreach — that would make it an attack.
$foreignScope = Seed::tenantWorkspace($db, $clock, 'rival');
Assert::same(
    'AUTHORIZATION_SCOPE_MISMATCH',
    $artifacts->revokeAuthorization([
        'tenant_id' => $foreignScope['tenant_id'],
        'workspace_id' => $foreignScope['workspace_id'],
        'outreach_execution_authorization_id' => $sdAuth,
    ])->reasonCode(),
    'the stand-down lever cannot reach another workspace',
);

Assert::true(
    $artifacts->revokeAuthorization([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'outreach_execution_authorization_id' => $sdAuth,
    ])->isOk(),
    'an outstanding authorization is revocable',
);
Assert::same(
    'AUTHORIZATION_EXPIRED_OR_CONSUMED',
    $artifacts->consume([
        'outreach_execution_authorization_id' => $sdAuth,
        'observed_payload_digest' => Digest::canonicalDigest($payload),
    ])->reasonCode(),
    'and a revoked authorization can never be consumed',
);

// ============================================================ the synthetic dispatch

$dispatched = $delivery->dispatch([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'outreach_execution_authorization_id' => $authId,
    'observed_payload_digest' => Digest::canonicalDigest($payload),
    'simulated_outcome' => 'DELIVERED',
]);
Assert::true($dispatched->isOk(), 'the synthetic dispatch records an outcome');
Assert::same('DELIVERED', $dispatched->value()['delivery_outcome'], 'as supplied');

// The M10 record says what this categorically was: a harmless demo action, not a send.
Assert::same(
    'HARMLESS_DEMO_ACTION_COMPLETED',
    (string) $db->scalar(
        'SELECT record_type FROM decision_records WHERE decision_record_id = ?',
        [$dispatched->value()['decision_record_id']]
    ),
    'recorded as a HARMLESS DEMO ACTION — the M09 ceiling is not stretched to cover outreach',
);
Assert::contains(
    'No external effect occurred',
    (string) $db->scalar(
        'SELECT safe_decision_summary FROM decision_records WHERE decision_record_id = ?',
        [$dispatched->value()['decision_record_id']]
    ),
    'and the summary says so plainly',
);

// THE AUTHORIZATION IS SPENT. There is no retry, because there is nothing left to retry with.
Assert::same(
    'CONSUMED',
    (string) $db->scalar(
        'SELECT authorization_status FROM outreach_execution_authorizations
         WHERE outreach_execution_authorization_id = ?',
        [$authId]
    ),
    'the authorization is consumed',
);
Assert::same(
    'AUTHORIZATION_EXPIRED_OR_CONSUMED',
    $delivery->dispatch([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'outreach_execution_authorization_id' => $authId,
        'observed_payload_digest' => Digest::canonicalDigest($payload),
        'simulated_outcome' => 'DELIVERED',
    ])->reasonCode(),
    'and a second dispatch on the same authorization is refused',
);

// ============================================================ a failure still spends it

$failing = $runToApprovalRequired();
$fRequest = $approval->requestApproval([
    'arbiter_verdict_id' => $failing['arbiter_verdict_id'],
    'created_by_actor_type' => 'OPERATOR',
    'created_by_actor_id' => $scope['operator_id'],
])->value()['approval_request_id'];
$fDecision = $approval->recordDecision([
    'approval_request_id' => $fRequest,
    'approver_operator_id' => $approverId,
    'decision' => 'APPROVE_EXACT_ARTIFACT',
    'exact_payload_digest_confirmed' => Digest::canonicalDigest($payload),
    'decision_reason_summary' => 'Approved.',
])->value()['approval_decision_id'];
$fFrozen = $artifacts->freeze([
    ...$freezeInput,
    'recipient_pseudonym_ref' => $failing['pseudonym'],
    'source_decision_record_id' => $failing['source_decision_record_id'],
    'approval_request_id' => $fRequest,
    'approval_decision_id' => $fDecision,
])->value()['frozen_outreach_artifact_id'];
$fAuth = $artifacts->issueAuthorization([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'frozen_outreach_artifact_id' => $fFrozen,
])->value()['outreach_execution_authorization_id'];

$failed = $delivery->dispatch([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'outreach_execution_authorization_id' => $fAuth,
    'observed_payload_digest' => Digest::canonicalDigest($payload),
    'simulated_outcome' => 'FAILED',
]);
Assert::true($failed->isOk(), 'a FAILED outcome is recorded');
// TRUTHFULLY. A ledger that rounded failures up to successes would be worse than no ledger.
Assert::same(
    'HARMLESS_DEMO_ACTION_FAILED',
    (string) $db->scalar(
        'SELECT record_type FROM decision_records WHERE decision_record_id = ?',
        [$failed->value()['decision_record_id']]
    ),
    'as a FAILURE — recorded truthfully, not rounded up',
);
Assert::same(
    'FAILED',
    (string) $db->scalar(
        'SELECT delivery_outcome FROM delivery_outcome_records WHERE delivery_outcome_record_id = ?',
        [$failed->value()['delivery_outcome_record_id']]
    ),
    'and the delivery record agrees',
);
// AND IT STILL SPENT THE AUTHORIZATION. The consume happens before the outcome is known, so a
// failure cannot be retried — which is what stops a transient error becoming a second contact.
Assert::same(
    'CONSUMED',
    (string) $db->scalar(
        'SELECT authorization_status FROM outreach_execution_authorizations
         WHERE outreach_execution_authorization_id = ?',
        [$fAuth]
    ),
    'a FAILED outcome still consumes the authorization — there is nothing left to retry with',
);

// ============================================================ the five audits

$m11 = new \Allfather\Loopia\Substrate\M11\Assurance($db, $clock, $ledger);
$assurance = new \Allfather\Loopia\Product\P07A\Assurance($db, $clock, $m11);

$profiles = [];
foreach ([
    'DELIVERY_RECONCILIATION' => 'p07.reconciliation',
    'CONSENT_STATE_INTEGRITY' => 'p07.consent',
    'CONTENT_COMPLIANCE' => 'p07.content',
    'EXTERNAL_EFFECT_GUARD' => 'p07.external',
] as $category => $key) {
    $profiles[$category] = $m11->createAuditProfile(
        $scope['tenant_id'], $scope['workspace_id'], $key, $category, $key
    );
}

// THE EXTERNAL-EFFECT GUARD IS THE ONE THAT MATTERS MOST. It reads five facts about stored state,
// and on a projection that performs no external effect all five hold.
$guard = $assurance->runExternalEffectGuardAudit(['audit_profile_id' => $profiles['EXTERNAL_EFFECT_GUARD']]);
Assert::same(
    'CONTROL_PASS',
    $guard->value()['finding_type'],
    'every external-effect pin holds and every recorded outcome is synthetic',
);
Assert::same(null, $guard->value()['incident_case_id'], 'so no incident is opened');

// FOUR OF THE GUARD'S FIVE ARMS CANNOT FIRE, and that is the strongest possible result. The
// conditions they check are CHECK-pinned in storage, so the state they look for cannot be written
// at all — not by this code, and not by anything else with a connection to this database.
Assert::throws(
    static fn () => $db->run(
        'UPDATE workspaces SET external_side_effects_allowed = 1 WHERE workspace_id = ?',
        [$scope['workspace_id']]
    ),
    \PDOException::class,
    'the workspace external-effect pin cannot be relaxed — the storage refuses it',
);
Assert::throws(
    static fn () => $db->run(
        'UPDATE capability_contracts SET human_approval_or_execution_authority = 1
         WHERE capability_contract_id = ?',
        [$base['producer']['capability_contract_id']]
    ),
    \PDOException::class,
    'nor can a contract acquire execution authority',
);
Assert::throws(
    static fn () => $db->insert('outreach_channel_bindings', [
        'outreach_channel_binding_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'channel' => 'EMAIL',
        'adapter_type' => 'REAL_EMAIL_PROVIDER',
        'external_effect_permitted' => 1,
        'binding_status' => 'ENABLED',
        'created_at' => $clock->now(),
    ]),
    \PDOException::class,
    'and a real channel adapter cannot be bound at all',
);

// The FIFTH arm is the reachable one, and it is the interesting one: an outcome carrying a record
// type a synthetic dispatch could not have produced. A real send would leave exactly that trace,
// so the audit catches the effect by its footprint rather than by its cause.
// A DOUBLE outcome is structurally impossible too: the authorization column is UNIQUE, so one
// authorization can carry at most one outcome whatever any code tries.
Assert::throws(
    static fn () => $db->insert('delivery_outcome_records', [
        'delivery_outcome_record_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'outreach_execution_authorization_id' => $fAuth,
        'frozen_outreach_artifact_id' => $fFrozen,
        'decision_record_id' => $failed->value()['decision_record_id'],
        'delivery_outcome' => 'DELIVERED',
        'outcome_detail_safe' => 'A second outcome on one authorization.',
        'recorded_at' => $clock->now(),
    ]),
    \PDOException::class,
    'one authorization can carry at most one outcome — a UNIQUE index, not a check in code',
);

// The planted trace therefore goes on the REVOKED authorization, which carries none.
$foreignRecordId = (string) $db->scalar(
    "SELECT decision_record_id FROM decision_records
     WHERE tenant_id = ? AND record_type = 'INTERNAL_INFORMATIONAL_RELEASE' LIMIT 1",
    [$scope['tenant_id']]
);
$db->insert('delivery_outcome_records', [
    'delivery_outcome_record_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'outreach_execution_authorization_id' => $mutationAuth,
    'frozen_outreach_artifact_id' => $mutationFrozen,
    'decision_record_id' => $foreignRecordId,
    'delivery_outcome' => 'DELIVERED',
    'outcome_detail_safe' => 'A trace no synthetic dispatch could leave.',
    'recorded_at' => $clock->now(),
]);
$breached = $assurance->runExternalEffectGuardAudit(['audit_profile_id' => $profiles['EXTERNAL_EFFECT_GUARD']]);
Assert::same(
    'SCOPE_VIOLATION',
    $breached->value()['finding_type'],
    'an outcome a synthetic dispatch could not have produced is caught',
);
Assert::true(
    in_array('NON_SYNTHETIC_DELIVERY_OUTCOME', $breached->value()['reason_codes'], true),
    'named as a non-synthetic outcome',
);
Assert::same(
    'S5',
    (string) $db->scalar(
        'SELECT severity FROM audit_findings WHERE audit_finding_id = ?',
        [$breached->value()['audit_finding_id']]
    ),
    'at S5 — a finding here means the projection did something it has no code to do',
);
Assert::notSame(null, $breached->value()['incident_case_id'], 'and an incident is opened');

// The same planted row is also an outcome whose authorization was never CONSUMED — a contact
// nobody authorized — and the reconciliation audit names that separately.
$unauthorized = $assurance->runDeliveryReconciliationAudit(['audit_profile_id' => $profiles['DELIVERY_RECONCILIATION']]);
Assert::true(
    in_array('OUTCOME_WITHOUT_CONSUMED_AUTHORIZATION', $unauthorized->value()['reason_codes'], true),
    'an outcome against an unconsumed authorization is a contact nobody authorized',
);

// The other direction is the dangerous one: a spent authorization with NO outcome — a contact that
// left no trace, which looks exactly like one that never happened. It is reached the way it would
// actually happen: a consume that never reaches its record, as a crash in between would leave.
// Deleting an existing outcome is not available — the table is append-only, and the storage says so.
Assert::throws(
    static fn () => $db->run(
        'DELETE FROM delivery_outcome_records WHERE delivery_outcome_record_id = ?',
        [$dispatched->value()['delivery_outcome_record_id']]
    ),
    \PDOException::class,
    'a recorded outcome cannot be deleted — the ledger of what happened is append-only',
);

$stranded = $runToApprovalRequired();
$stRequest = $approval->requestApproval([
    'arbiter_verdict_id' => $stranded['arbiter_verdict_id'],
    'created_by_actor_type' => 'OPERATOR',
    'created_by_actor_id' => $scope['operator_id'],
])->value()['approval_request_id'];
$stDecision = $approval->recordDecision([
    'approval_request_id' => $stRequest,
    'approver_operator_id' => $approverId,
    'decision' => 'APPROVE_EXACT_ARTIFACT',
    'exact_payload_digest_confirmed' => Digest::canonicalDigest($payload),
    'decision_reason_summary' => 'Approved.',
])->value()['approval_decision_id'];
$stFrozen = $artifacts->freeze([
    ...$freezeInput,
    'recipient_pseudonym_ref' => $stranded['pseudonym'],
    'source_decision_record_id' => $stranded['source_decision_record_id'],
    'approval_request_id' => $stRequest,
    'approval_decision_id' => $stDecision,
])->value()['frozen_outreach_artifact_id'];
$stAuth = $artifacts->issueAuthorization([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'frozen_outreach_artifact_id' => $stFrozen,
])->value()['outreach_execution_authorization_id'];
// Consumed directly, bypassing the dispatch that would have recorded the outcome.
$artifacts->consume([
    'outreach_execution_authorization_id' => $stAuth,
    'observed_payload_digest' => Digest::canonicalDigest($payload),
]);

$orphaned = $assurance->runDeliveryReconciliationAudit(['audit_profile_id' => $profiles['DELIVERY_RECONCILIATION']]);
Assert::same(
    'INTEGRITY_FAILURE',
    $orphaned->value()['finding_type'],
    'a consumed authorization with no recorded outcome is caught',
);
Assert::true(
    in_array('CONSUMED_AUTHORIZATION_WITHOUT_OUTCOME', $orphaned->value()['reason_codes'], true),
    'by the direction that would otherwise be invisible',
);

// The consent and PII audits pass over the clean state this test produced.
Assert::same(
    'CONTROL_PASS',
    $assurance->runConsentStateIntegrityAudit(['audit_profile_id' => $profiles['CONSENT_STATE_INTEGRITY']])
        ->value()['finding_type'],
    'every frozen artifact rests on an affirmative consent',
);
Assert::same(
    'CONTROL_PASS',
    $assurance->runPiiFirewallAudit(['audit_profile_id' => $profiles['CONTENT_COMPLIANCE']])
        ->value()['finding_type'],
    'and no stored outreach object carries a raw identifier',
);

// Write a raw identifier past the firewall, and the audit finds it.
$db->run(
    'UPDATE frozen_outreach_artifacts SET exact_payload_reference_safe = ?
     WHERE frozen_outreach_artifact_id = ?',
    ['payload://anna.berg@example.com', $artifactId]
);
$leaked = $assurance->runPiiFirewallAudit(['audit_profile_id' => $profiles['CONTENT_COMPLIANCE']]);
Assert::same(
    'PROVENANCE_OR_IP_CONCERN',
    $leaked->value()['finding_type'],
    'an identifier written past the firewall is caught after the fact',
);
Assert::true(
    in_array('UN_PSEUDONYMIZED_PII_IN_FROZEN_ARTIFACT', $leaked->value()['reason_codes'], true),
    'naming which of the three places it surfaced in',
);
