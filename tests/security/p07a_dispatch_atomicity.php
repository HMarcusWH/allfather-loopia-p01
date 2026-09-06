<?php

declare(strict_types=1);

/**
 * The P07-A dispatch is ONE transaction, proved by breaking it in the middle.
 *
 * WHY THIS FILE EXISTS. Upstream's `dispatchOutreachSynthetic` opens `BEGIN IMMEDIATE` before the
 * consent re-check and does not commit until the delivery-outcome row is in, and it says why:
 *
 *   > the no-effect path either records the truthful outcome or leaves the authorization
 *   > unconsumed — never a stranded CONSUMED authorization with no delivery outcome.
 *
 * The port used to run four separate transactional units. Every happy-path assertion passed,
 * because on the happy path a split unit and an atomic one are indistinguishable. The difference
 * only appears when something fails in the middle, which is why nothing found it.
 *
 * SO THE FAILURES ARE INJECTED, DETERMINISTICALLY. Not raced, not hoped for. A trigger raises
 * SIGNAL at the exact statement under test, which is the same idiom `m10_release_ownership` uses
 * to reproduce a racing releaser. Each trigger's presence is asserted gone afterwards, so this
 * suite cannot leave the schema weaker for the suites that follow it.
 *
 * WHAT IS NOT TESTED HERE. That the audit still catches a stranded authorization — that is
 * `p07a_assurance`'s job and it still passes. Prevention and detection are different claims and
 * this repository needs both: a transaction cannot cover a crash between two units in some future
 * path, and an audit cannot un-spend an authorization.
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

$approverRole = Seed::role($db, $clock, 'crm_approver', ['approval.read', 'approval.decide_exact_artifact', 'outreach.approve']);
$approverId = Seed::operator($db, $clock, $scope['tenant_id'], 'CRM Approver');
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $approverId, $approverRole['role_id']);

$payload = ['subject' => 'Your renewal terms', 'body' => 'We hold list price for the term.'];
$payloadRef = 'payload://renewal-terms-v1';
$payloadDigest = Digest::canonicalDigest($payload);

/**
 * Drive one outreach from a fresh producer all the way to an ISSUED single-use authorization.
 *
 * Every case below needs its own, because an authorization is single use by construction: two
 * cases sharing one would be testing the second gate rather than the one they name.
 *
 * @return array{authorization_id: string, artifact_id: string, source_decision_record_id: string,
 *               arbiter_verdict_id: string, approval_decision_id: string}
 */
$authorizeOne = static function () use (
    $db, $clock, $scope, $admission, $approval, $artifacts, $council, $consent, $firewall,
    $policySetId, $payload, $payloadRef, $payloadDigest, $approverId
): array {
    $producer = Seed::p07OutreachProducer($db, $clock, $scope);
    $pseudonym = $firewall->pseudonymize([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'channel' => 'EMAIL',
        'raw_recipient_identifier' => 'anna.' . substr(Ids::new(), 0, 8) . '@example.com',
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

    $outreachId = $admission->submitProposal([
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
    ])->value()['outreach_execution_proposal_id'];

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

    $verdictId = $admission->admit([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'outreach_execution_proposal_id' => $outreachId,
        'arbiter_policy_set_id' => $policySetId,
    ])->value()['arbiter_verdict_id'];

    $requestId = $approval->requestApproval([
        'arbiter_verdict_id' => $verdictId,
        'created_by_actor_type' => 'OPERATOR',
        'created_by_actor_id' => $scope['operator_id'],
    ])->value()['approval_request_id'];
    $decisionId = $approval->recordDecision([
        'approval_request_id' => $requestId,
        'approver_operator_id' => $approverId,
        'decision' => 'APPROVE_EXACT_ARTIFACT',
        'exact_payload_digest_confirmed' => $payloadDigest,
        'decision_reason_summary' => 'Approved.',
    ])->value()['approval_decision_id'];

    $artifactId = $artifacts->freeze([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'recipient_pseudonym_ref' => $pseudonym,
        'channel' => 'EMAIL',
        'payload_kind' => 'EMAIL_SUBJECT_BODY',
        'exact_payload' => $payload,
        'exact_payload_reference_safe' => $payloadRef,
        'source_decision_record_id' => $producer['source_decision_record_id'],
        'approval_request_id' => $requestId,
        'approval_decision_id' => $decisionId,
    ])->value()['frozen_outreach_artifact_id'];
    $authId = $artifacts->issueAuthorization([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'frozen_outreach_artifact_id' => $artifactId,
    ])->value()['outreach_execution_authorization_id'];

    return [
        'authorization_id' => $authId,
        'artifact_id' => $artifactId,
        'source_decision_record_id' => $producer['source_decision_record_id'],
        'arbiter_verdict_id' => $verdictId,
        'approval_decision_id' => $decisionId,
    ];
};

/** The M10 stream this workspace's dispatch records land on, and its head as it stands now. */
$streamId = $ledger->ensureActiveStream(
    $scope['tenant_id'],
    $scope['workspace_id'],
    (string) $db->scalar('SELECT environment_class FROM workspaces WHERE workspace_id = ?', [$scope['workspace_id']]),
);
/** @return array{seq: int, hash: string, record_id: ?string} */
$head = static function () use ($db, $streamId): array {
    $row = (array) $db->one(
        'SELECT head_sequence_number, current_head_chain_hash, current_head_record_id
         FROM decision_ledger_streams WHERE decision_ledger_stream_id = ?',
        [$streamId]
    );

    return [
        'seq' => (int) $row['head_sequence_number'],
        'hash' => (string) $row['current_head_chain_hash'],
        'record_id' => $row['current_head_record_id'] === null ? null : (string) $row['current_head_record_id'],
    ];
};
$countAll = static fn (string $sql, array $params = []): int => (int) $db->scalar($sql, $params);

$dispatchInput = static fn (string $authId, string $outcome = 'DELIVERED'): array => [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'outreach_execution_authorization_id' => $authId,
    'observed_payload_digest' => $payloadDigest,
    'simulated_outcome' => $outcome,
];

// ==================================================================================== TEST A
// The M10 append fails AFTER the authorization has successfully passed consume.
//
// This is the window the split unit left open: the consume had already committed, so the failure
// arrived at an authorization that was permanently spent with nothing recorded against it. There
// is no retry to recover with — that is the SF-011 law working exactly as designed — so the
// authorization was simply gone.

Assert::case('A. an M10 failure after a successful consume strands nothing');

$a = $authorizeOne();
$headBeforeA = $head();
$recordsBeforeA = $countAll('SELECT COUNT(*) FROM decision_records');
$refsBeforeA = $countAll('SELECT COUNT(*) FROM decision_input_references');
$linksBeforeA = $countAll('SELECT COUNT(*) FROM ledger_integrity_links');

// The failure lands on the decision-record INSERT, which is the first write of the M10 append and
// therefore the earliest point at which "the consume succeeded and the record did not" is true.
$db->pdo()->exec(
    "CREATE TRIGGER trg_test_fail_m10_append BEFORE INSERT ON decision_records
     FOR EACH ROW BEGIN
       IF NEW.record_type = 'HARMLESS_DEMO_ACTION_COMPLETED' THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected M10 failure after consume';
       END IF;
     END"
);
try {
    $delivery->dispatch($dispatchInput($a['authorization_id']));
    Assert::fail('the injected M10 failure should propagate rather than being swallowed');
} catch (\PDOException) {
    Assert::pass();
} finally {
    $db->pdo()->exec('DROP TRIGGER IF EXISTS trg_test_fail_m10_append');
}

Assert::same(
    'ISSUED',
    (string) $db->scalar(
        'SELECT authorization_status FROM outreach_execution_authorizations
         WHERE outreach_execution_authorization_id = ?',
        [$a['authorization_id']]
    ),
    'A1: the authorization is NOT stranded as CONSUMED — the consume rolled back with the unit',
);
Assert::same(
    'AUTHORIZED',
    (string) $db->scalar(
        'SELECT artifact_status FROM frozen_outreach_artifacts WHERE frozen_outreach_artifact_id = ?',
        [$a['artifact_id']]
    ),
    'A2: and the artifact is back to the state it was in before the dispatch',
);
Assert::same(
    0,
    $countAll(
        'SELECT COUNT(*) FROM delivery_outcome_records WHERE outreach_execution_authorization_id = ?',
        [$a['authorization_id']]
    ),
    'A3: no delivery outcome exists',
);
Assert::same(
    $recordsBeforeA,
    $countAll('SELECT COUNT(*) FROM decision_records'),
    'A4: and no decision record from the attempted dispatch survives',
);
Assert::same(
    $refsBeforeA,
    $countAll('SELECT COUNT(*) FROM decision_input_references'),
    'A5: nor any dangling input reference',
);
Assert::same(
    $linksBeforeA,
    $countAll('SELECT COUNT(*) FROM ledger_integrity_links'),
    'A6: nor an integrity link with no record on the other end',
);
Assert::equalsList(
    [$headBeforeA['seq'], $headBeforeA['hash']],
    [$head()['seq'], $head()['hash']],
    'A7: the ledger head did not move — a failed dispatch is not history',
);

// AND THE AUTHORIZATION IS STILL USABLE. This is the half that makes the rollback correct rather
// than merely tidy: a rollback that left the authorization unusable would have spent it anyway,
// just without saying so.
$aRetry = $delivery->dispatch($dispatchInput($a['authorization_id']));
Assert::true($aRetry->isOk(), 'A8: and the untouched authorization still dispatches afterwards');

// ==================================================================================== TEST B
// The delivery-outcome INSERT fails AFTER the M10 append has already executed inside the unit.
//
// This window is subtler than A and worse to read: the ledger would carry a durable, chain-linked
// record asserting a delivery outcome that no delivery row corroborates, and `verifyStream` would
// return PASS over it, because the chain is intact. The record would be internally consistent and
// externally false.

Assert::case('B. a delivery-row failure after the M10 append rolls the record back too');

$b = $authorizeOne();
$headBeforeB = $head();
$recordsBeforeB = $countAll('SELECT COUNT(*) FROM decision_records');
$refsBeforeB = $countAll('SELECT COUNT(*) FROM decision_input_references');
$linksBeforeB = $countAll('SELECT COUNT(*) FROM ledger_integrity_links');

$db->pdo()->exec(
    "CREATE TRIGGER trg_test_fail_delivery_row BEFORE INSERT ON delivery_outcome_records
     FOR EACH ROW BEGIN
       SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected delivery-row failure after M10 append';
     END"
);
try {
    $delivery->dispatch($dispatchInput($b['authorization_id']));
    Assert::fail('the injected delivery-row failure should propagate rather than being swallowed');
} catch (\PDOException) {
    Assert::pass();
} finally {
    $db->pdo()->exec('DROP TRIGGER IF EXISTS trg_test_fail_delivery_row');
}

Assert::same(
    'ISSUED',
    (string) $db->scalar(
        'SELECT authorization_status FROM outreach_execution_authorizations
         WHERE outreach_execution_authorization_id = ?',
        [$b['authorization_id']]
    ),
    'B1: the consume rolled back',
);
Assert::same(
    'AUTHORIZED',
    (string) $db->scalar(
        'SELECT artifact_status FROM frozen_outreach_artifacts WHERE frozen_outreach_artifact_id = ?',
        [$b['artifact_id']]
    ),
    'B2: the artifact state rolled back',
);
Assert::same(
    $recordsBeforeB,
    $countAll('SELECT COUNT(*) FROM decision_records'),
    'B3: the M10 decision record rolled back — no orphan asserting an outcome nothing corroborates',
);
Assert::same(
    $refsBeforeB,
    $countAll('SELECT COUNT(*) FROM decision_input_references'),
    'B4: its input references rolled back',
);
Assert::same(
    $linksBeforeB,
    $countAll('SELECT COUNT(*) FROM ledger_integrity_links'),
    'B5: its integrity link rolled back',
);
Assert::equalsList(
    [$headBeforeB['seq'], $headBeforeB['hash'], $headBeforeB['record_id']],
    [$head()['seq'], $head()['hash'], $head()['record_id']],
    'B6: and the stream head rolled back, record id included',
);
Assert::same(
    0,
    $countAll(
        'SELECT COUNT(*) FROM delivery_outcome_records WHERE outreach_execution_authorization_id = ?',
        [$b['authorization_id']]
    ),
    'B7: no delivery record exists',
);
Assert::same(
    'PASS',
    $ledger->verifyStream($streamId)['status'],
    'B8: and the stream still verifies from genesis — the rollback left no gap in the chain',
);

// ==================================================================================== TEST C
// A legitimate consume refusal must SURVIVE the outer transaction.
//
// This is the trap the atomicity repair sets for itself. Having established that a post-consume
// failure rolls everything back, the tempting simplification is to roll back on any refusal — and
// that would silently un-expire an authorization that really had expired, and un-revoke an
// artifact whose payload really had been mutated. Upstream COMMITs both, and the comment in
// `outreach-delivery.ts` says why: they are "legitimate persisted effects (a real status change,
// not a half-finished dispatch)".

Assert::case('C. a legitimate consume refusal is committed, not erased by the dispatch rollback');

$cExpired = $authorizeOne();
$db->run(
    'UPDATE outreach_execution_authorizations SET expires_at = ?
     WHERE outreach_execution_authorization_id = ?',
    ['2025-01-01T00:00:00.000Z', $cExpired['authorization_id']]
);
$cExpiredOutcome = $delivery->dispatch($dispatchInput($cExpired['authorization_id']));
Assert::true($cExpiredOutcome->isRefusal(), 'C1: an expired authorization refuses the dispatch');
Assert::same(
    'AUTHORIZATION_EXPIRED_OR_CONSUMED',
    $cExpiredOutcome->reasonCode(),
    'C2: by upstream\'s reason code',
);
Assert::same(
    'EXPIRED',
    (string) $db->scalar(
        'SELECT authorization_status FROM outreach_execution_authorizations
         WHERE outreach_execution_authorization_id = ?',
        [$cExpired['authorization_id']]
    ),
    'C3: and the EXPIRED transition PERSISTED — the dispatch rollback did not restore it to ISSUED',
);

$cMutated = $authorizeOne();
$cMutatedOutcome = $delivery->dispatch([
    ...$dispatchInput($cMutated['authorization_id']),
    'observed_payload_digest' => Digest::canonicalDigest(['subject' => 'Something else', 'body' => 'Not approved.']),
]);
Assert::same(
    'FROZEN_PAYLOAD_MUTATED',
    $cMutatedOutcome->reasonCode(),
    'C4: a mutated payload refuses the dispatch',
);
Assert::same(
    'REVOKED',
    (string) $db->scalar(
        'SELECT authorization_status FROM outreach_execution_authorizations
         WHERE outreach_execution_authorization_id = ?',
        [$cMutated['authorization_id']]
    ),
    'C5: and the REVOKE of the authorization PERSISTED',
);
Assert::same(
    'REVOKED',
    (string) $db->scalar(
        'SELECT artifact_status FROM frozen_outreach_artifacts WHERE frozen_outreach_artifact_id = ?',
        [$cMutated['artifact_id']]
    ),
    'C6: as did the REVOKE of the artifact — a mutated artifact is not one to retry with',
);
Assert::same(
    0,
    $countAll('SELECT COUNT(*) FROM delivery_outcome_records WHERE outreach_execution_authorization_id IN (?, ?)', [
        $cExpired['authorization_id'], $cMutated['authorization_id'],
    ]),
    'C7: and neither refusal recorded a delivery outcome',
);

// ==================================================================================== TEST D
// FAILED and UNKNOWN are recorded truthfully, spend the authorization, and route for triage.

Assert::case('D. FAILED and UNKNOWN are truthful, terminal, and flagged');

foreach ([
    'FAILED' => ['HARMLESS_DEMO_ACTION_FAILED', 'EXECUTION_FAILED'],
    'UNKNOWN' => ['HARMLESS_DEMO_ACTION_OUTCOME_UNKNOWN', 'OUTCOME_UNKNOWN'],
] as $outcome => [$expectedType, $expectedStatus]) {
    $d = $authorizeOne();
    $result = $delivery->dispatch($dispatchInput($d['authorization_id'], $outcome));
    Assert::true($result->isOk(), "D: a {$outcome} outcome is recorded rather than refused");

    $record = (array) $db->one(
        'SELECT record_type, primary_outcome_status, reason_codes, severity, incident_evaluation_required,
                materiality_class, proposal_id, mission_id
         FROM decision_records WHERE decision_record_id = ?',
        [$result->value()['decision_record_id']]
    );
    Assert::same($expectedType, (string) $record['record_type'], "D: {$outcome} uses upstream's record type");
    Assert::same(
        $expectedStatus,
        (string) $record['primary_outcome_status'],
        "D: {$outcome} uses upstream's primary outcome status",
    );
    Assert::equalsList(
        ["P07_DELIVERY_{$outcome}"],
        json_decode((string) $record['reason_codes'], true, 512, JSON_THROW_ON_ERROR),
        "D: {$outcome} carries upstream's exact reason code and no other",
    );
    Assert::same('S3', (string) $record['severity'], "D: {$outcome} carries S3, as upstream does");
    Assert::same(
        1,
        (int) $record['incident_evaluation_required'],
        "D: {$outcome} routes for incident evaluation — a delivery nobody confirmed is an operator's problem",
    );
    Assert::same('ACTION_MATERIAL', (string) $record['materiality_class'], "D: {$outcome} is ACTION_MATERIAL");
    // Upstream sets neither on this record. Both are digest inputs, so setting one changes the
    // record's identity for the same governed event.
    Assert::same(null, $record['proposal_id'], "D: {$outcome} sets no proposal id, because upstream sets none");
    Assert::same(null, $record['mission_id'], "D: {$outcome} sets no mission id, because upstream sets none");

    // TERMINAL. The consume happened before the outcome was known, so a failure has already spent
    // its authorization. There is nothing to retry with, and that is the design.
    Assert::same(
        'CONSUMED',
        (string) $db->scalar(
            'SELECT authorization_status FROM outreach_execution_authorizations
             WHERE outreach_execution_authorization_id = ?',
            [$d['authorization_id']]
        ),
        "D: a {$outcome} outcome STILL consumes the authorization",
    );
    Assert::same(
        'AUTHORIZATION_EXPIRED_OR_CONSUMED',
        $delivery->dispatch($dispatchInput($d['authorization_id'], $outcome))->reasonCode(),
        "D: so a {$outcome} cannot be retried — there is no automatic retry because there is nothing left",
    );
    Assert::same(
        $outcome,
        (string) $db->scalar(
            'SELECT delivery_outcome FROM delivery_outcome_records WHERE decision_record_id = ?',
            [$result->value()['decision_record_id']]
        ),
        "D: and the delivery row records {$outcome} truthfully, not rounded up",
    );
}

// A DELIVERED record carries neither, which is what makes S3 mean something.
$dOk = $authorizeOne();
$dOkResult = $delivery->dispatch($dispatchInput($dOk['authorization_id']));
$dOkRecord = (array) $db->one(
    'SELECT severity, incident_evaluation_required FROM decision_records WHERE decision_record_id = ?',
    [$dOkResult->value()['decision_record_id']]
);
Assert::same(null, $dOkRecord['severity'], 'D: a DELIVERED outcome carries NO severity');
Assert::same(
    0,
    (int) $dOkRecord['incident_evaluation_required'],
    'D: and is not routed for incident evaluation — otherwise the flag would say nothing',
);

// ==================================================================================== TEST E
// Provenance. A decision record names the service that wrote it, and never itself.

Assert::case('E. every M10 record names a real writer');

Assert::same(
    'service:p07-crm-delivery',
    (string) $db->scalar(
        'SELECT recorded_by_service_identity_id FROM decision_records WHERE decision_record_id = ?',
        [$dOkResult->value()['decision_record_id']]
    ),
    'E1: a P07-A dispatch record is written by service:p07-crm-delivery, as upstream defaults it',
);
Assert::same(
    'service:m10-decision-ledger',
    (string) $db->scalar(
        "SELECT recorded_by_service_identity_id FROM decision_records
         WHERE record_type = 'INTERNAL_INFORMATIONAL_RELEASE' ORDER BY recorded_at ASC LIMIT 1",
    ),
    'E2: an internal release is written by M10, which is who minted it',
);

// THE FALLBACK IS GONE, not merely unused. `?? $recordId` produced a record whose provenance column
// held its own primary key — NOT NULL satisfied, meaning destroyed.
Assert::same(
    0,
    $countAll('SELECT COUNT(*) FROM decision_records WHERE recorded_by_service_identity_id = decision_record_id'),
    'E3: no record in the whole database claims to have been written by itself',
);
Assert::same(
    0,
    $countAll("SELECT COUNT(*) FROM decision_records WHERE TRIM(recorded_by_service_identity_id) = ''"),
    'E4: nor by nobody',
);

// STRUCTURAL, not incidental. The writer is a REQUIRED parameter, so a caller cannot omit it: the
// omission is an ArgumentCountError, which is the closest PHP gets to upstream's non-optional
// `recordedByServiceIdentityId: string`. Asserting only the rows above would pass just as well
// against a restored default nobody happened to hit.
$appendWriter = (new ReflectionMethod(DecisionLedger::class, 'append'))->getParameters()[6];
Assert::same(
    'recordedByServiceIdentityId',
    $appendWriter->getName(),
    'E5: the writer is a positional parameter of append()',
);
Assert::false(
    $appendWriter->isOptional(),
    'E6: and it is REQUIRED — there is no default for an omission to fall into',
);
Assert::throws(
    static fn () => $ledger->append(
        $streamId, 'PROPOSAL_HELD', 'GOVERNANCE_MATERIAL', 'HELD', 'blank writer',
        [['owner_module_id' => 'M04', 'input_object_type' => 'proposal_envelope',
          'input_object_id' => Ids::new(), 'reference_role' => 'PROPOSAL_BASIS',
          'verification_status_at_record_time' => 'VERIFIED_REFERENCE']],
        '   ',
    ),
    \InvalidArgumentException::class,
    'E7: and a blank writer is refused too — NOT NULL is satisfied by a string that names nobody',
);

// EVERY runtime caller, not the two this file happens to exercise. A grep is the only assertion
// that covers the ones nobody thought to test.
$repoRoot = dirname(__DIR__, 2);
$appendCallers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/app')) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace($repoRoot . '/', '', (string) $file);
    if ($path === 'app/Substrate/M10/DecisionLedger.php') {
        continue; // M10's own wrappers are asserted by E1/E2 above.
    }
    if (preg_match('/->append\(/', (string) file_get_contents((string) $file)) === 1) {
        $appendCallers[] = $path;
    }
}
Assert::equalsList(
    ['app/Product/P07A/SyntheticDelivery.php'],
    $appendCallers,
    'E8: exactly one runtime file outside M10 calls append(), and E1 pins the identity it passes',
);

// No trigger this suite installed may outlive it.
Assert::same(
    0,
    $countAll(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'trg_test_%'"
    ),
    'E9: and this suite left no injected trigger behind',
);
