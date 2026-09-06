<?php

declare(strict_types=1);

/**
 * P07-A synthetic dispatch: cross-runtime parity with the canonical TypeScript.
 *
 * WHERE THE EXPECTED VALUES COME FROM. `tests/fixtures/p07a_canonical_vectors.json` is produced by
 * `ops/conformance/p07a-canonical-vectors.ts`, which opens a real canonical SQLite database, drives
 * a real outreach through the real canonical governed path, and calls the REAL
 * `dispatchOutreachSynthetic` — the function this module projects. Every value in the fixture was
 * read back out of the canonical ledger, and the pinned digest came out of canonical's own
 * `computeRecordPayloadDigest`. No PHP participated in producing any of it.
 *
 * WHY THAT MATTERS MORE THAN IT SOUNDS. The defect this file exists to prevent is the one the
 * previous port had: an M10 record that was internally consistent, hashed cleanly, verified end to
 * end — and described a different event from the one canonical describes. It cited an invented
 * `SYNTHETIC_DISPATCH_OUTCOME` object as its mandatory outcome basis, upper-cased its object types,
 * carried version digests canonical does not set and a proposal id canonical does not set, said its
 * summary in different words, and carried no reason code at all. Every one of those is inside
 * `record_payload_digest`. A ledger that hashes cleanly over the wrong content is exactly as broken
 * as one that does not hash, and considerably more convincing.
 *
 * A PHP-COMPUTED "EXPECTED" WOULD PROVE NOTHING. If both sides of a digest comparison are computed
 * here, the test asserts that this file agrees with itself. The pinned digest is therefore the
 * canonical runtime's output, and the assertion is that the PHP encoder reproduces it from the
 * shape the PHP RUNTIME actually recorded.
 *
 * THE IDS ARE SUBSTITUTED, and only the ids. Object identifiers are per-run in both runtimes, so
 * the fixture pins a fixed id per reference ROLE and a fixed sequence number. Everything the digest
 * commits to other than those — record type, materiality, outcome status, summary wording, reason
 * codes, severity, incident flag, reliance, mission/proposal absence, and each reference's owner
 * module, object TYPE, version-or-digest and verification status — is this port's own output.
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


// The vectors speak for ONE canonical head. If canonical has moved, they are evidence about a
// commit this port is no longer pinned to, and silently trusting them would be the exact failure
// this repository's authority discipline exists to prevent.
$vectors = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/fixtures/p07a_canonical_vectors.json'),
    true, 512, JSON_THROW_ON_ERROR
);
$lock = json_decode(
    (string) file_get_contents(dirname(__DIR__, 2) . '/upstream.lock.json'),
    true, 512, JSON_THROW_ON_ERROR
);
Assert::same(
    $lock['projection_pinned_head'],
    $vectors['canonical_head'],
    'the canonical vectors were generated at the head this projection is pinned to',
);

$dispatchInput = static fn (string $authId, string $outcome): array => [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'outreach_execution_authorization_id' => $authId,
    'observed_payload_digest' => $payloadDigest,
    'simulated_outcome' => $outcome,
];

foreach ($vectors['transcripts'] as $vector) {
    $outcome = (string) $vector['simulated_outcome'];
    Assert::case("P07-A dispatch parity: {$outcome}");

    $fixture = $authorizeOne();
    $result = $delivery->dispatch($dispatchInput($fixture['authorization_id'], $outcome));

    // ---------------------------------------------------------------- the dispatch's own outcome
    Assert::true($result->isOk(), "{$outcome}: the port dispatches where canonical DISPATCHED");
    Assert::same(
        (string) $vector['delivery_outcome'],
        (string) $result->value()['delivery_outcome'],
        "{$outcome}: the delivery outcome matches canonical's",
    );
    Assert::same(
        (string) $vector['authorization_status_after'],
        (string) $db->scalar(
            'SELECT authorization_status FROM outreach_execution_authorizations
             WHERE outreach_execution_authorization_id = ?',
            [$fixture['authorization_id']]
        ),
        "{$outcome}: the authorization ends in canonical's state",
    );
    Assert::same(
        (string) $vector['artifact_status_after'],
        (string) $db->scalar(
            'SELECT artifact_status FROM frozen_outreach_artifacts WHERE frozen_outreach_artifact_id = ?',
            [$fixture['artifact_id']]
        ),
        "{$outcome}: and so does the artifact",
    );
    Assert::same(
        (string) $vector['delivery_outcome'],
        (string) $db->scalar(
            'SELECT delivery_outcome FROM delivery_outcome_records WHERE decision_record_id = ?',
            [$result->value()['decision_record_id']]
        ),
        "{$outcome}: the delivery row records what canonical records",
    );

    // ---------------------------------------------------------------- the M10 record, field by field
    $record = (array) $db->one(
        'SELECT record_type, materiality_class, primary_outcome_status, mission_id, proposal_id,
                proposal_submission_digest, correlation_id, safe_decision_summary, reason_codes,
                uncertainty_and_limitation_summary, severity, incident_evaluation_required,
                supersedes_or_corrects_decision_record_id, current_reliance_status,
                record_sequence_number, record_payload_digest, recorded_by_service_identity_id
         FROM decision_records WHERE decision_record_id = ?',
        [$result->value()['decision_record_id']]
    );
    $expectedRecord = $vector['record'];
    foreach ([
        'record_type', 'materiality_class', 'primary_outcome_status', 'mission_id', 'proposal_id',
        'proposal_submission_digest', 'correlation_id', 'safe_decision_summary',
        'uncertainty_and_limitation_summary', 'severity', 'current_reliance_status',
    ] as $field) {
        Assert::same(
            $expectedRecord[$field],
            $record[$field] === null ? null : (string) $record[$field],
            "{$outcome}: {$field} is canonical's",
        );
    }
    Assert::equalsList(
        $expectedRecord['reason_codes'],
        json_decode((string) $record['reason_codes'], true, 512, JSON_THROW_ON_ERROR),
        "{$outcome}: the reason codes are canonical's exactly",
    );
    Assert::same(
        (int) $expectedRecord['incident_evaluation_required'],
        (int) $record['incident_evaluation_required'],
        "{$outcome}: the incident flag is canonical's",
    );
    Assert::same(
        (string) $vector['recorded_by_service_identity_id'],
        (string) $record['recorded_by_service_identity_id'],
        "{$outcome}: written by the service canonical names",
    );

    // ---------------------------------------------------------------- the reference SET
    $references = [];
    foreach ($db->all(
        'SELECT owner_module_id, input_object_type, input_object_id, input_object_version_or_digest,
                reference_role, verification_status_at_record_time
         FROM decision_input_references WHERE decision_record_id = ?',
        [$result->value()['decision_record_id']]
    ) as $row) {
        $references[(string) $row['reference_role']] = $row;
    }
    $expectedRefs = $vector['references_by_role'];
    Assert::equalsList(
        array_values(array_map('strval', array_keys($expectedRefs))),
        (static function (array $roles): array { sort($roles); return $roles; })(array_keys($references)),
        "{$outcome}: exactly canonical's reference roles, no more and no fewer",
    );
    foreach ($expectedRefs as $role => $expectedRef) {
        Assert::same(
            (string) $expectedRef['owner_module_id'],
            (string) $references[$role]['owner_module_id'],
            "{$outcome}: {$role} is owned by the module canonical says owns it",
        );
        // CASING IS SEMANTIC. `arbiter_verdict` and `ARBITER_VERDICT` are different strings inside
        // the digest, so an upper-cased object type is a different record about the same event.
        Assert::same(
            (string) $expectedRef['input_object_type'],
            (string) $references[$role]['input_object_type'],
            "{$outcome}: {$role} names canonical's object type, character for character",
        );
        Assert::same(
            $expectedRef['input_object_version_or_digest'],
            $references[$role]['input_object_version_or_digest'],
            "{$outcome}: {$role} carries canonical's version-or-digest — a spare one is a digest change",
        );
        Assert::same(
            (string) $expectedRef['verification_status_at_record_time'],
            (string) $references[$role]['verification_status_at_record_time'],
            "{$outcome}: {$role} is verified as canonical verifies it",
        );
    }

    // OUTCOME_BASIS points at the RELEASED source record, not at the authorization. This is the one
    // the digest could never have caught, because the invented reference hashed perfectly well.
    Assert::same(
        $fixture['source_decision_record_id'],
        (string) $references['OUTCOME_BASIS']['input_object_id'],
        "{$outcome}: OUTCOME_BASIS cites the M10 source record the outreach was built from",
    );
    Assert::notSame(
        $fixture['authorization_id'],
        (string) $references['OUTCOME_BASIS']['input_object_id'],
        "{$outcome}: and NOT the authorization, which AUTHORIZATION_BASIS already cites",
    );
    Assert::same(
        $fixture['arbiter_verdict_id'],
        (string) $references['VERDICT_BASIS']['input_object_id'],
        "{$outcome}: VERDICT_BASIS cites the M08 verdict",
    );
    Assert::same(
        $fixture['approval_decision_id'],
        (string) $references['APPROVAL_BASIS']['input_object_id'],
        "{$outcome}: APPROVAL_BASIS cites the M09 approval decision",
    );

    // ---------------------------------------------------------------- THE DIGEST
    // Same shape, canonical's fixed ids and sequence number, this port's encoder. The expected value
    // is canonical's output and nothing here can adjust it.
    $fixedIds = $vector['fixed_reference_ids'];
    $normalized = [];
    foreach ($references as $role => $row) {
        $normalized[] = [
            'owner_module_id' => (string) $row['owner_module_id'],
            'input_object_type' => (string) $row['input_object_type'],
            'input_object_id' => (string) $fixedIds[$role],
            'input_object_version_or_digest' => $row['input_object_version_or_digest'],
            'reference_role' => (string) $role,
            'verification_status_at_record_time' => (string) $row['verification_status_at_record_time'],
        ];
    }
    $phpDigest = DecisionLedger::computeRecordPayloadDigest(
        [
            'record_type' => (string) $record['record_type'],
            'materiality_class' => (string) $record['materiality_class'],
            'primary_outcome_status' => (string) $record['primary_outcome_status'],
            'mission_id' => $record['mission_id'],
            'proposal_id' => $record['proposal_id'],
            'proposal_submission_digest' => $record['proposal_submission_digest'],
            'correlation_id' => $record['correlation_id'],
            'safe_decision_summary' => (string) $record['safe_decision_summary'],
            'reason_codes' => json_decode((string) $record['reason_codes'], true, 512, JSON_THROW_ON_ERROR),
            'uncertainty_and_limitation_summary' => $record['uncertainty_and_limitation_summary'],
            'severity' => $record['severity'],
            'incident_evaluation_required' => (bool) $record['incident_evaluation_required'],
            'supersedes_or_corrects_decision_record_id' => $record['supersedes_or_corrects_decision_record_id'],
            'current_reliance_status' => (string) $record['current_reliance_status'],
            'record_sequence_number' => (int) $vector['fixed_record_sequence_number'],
        ],
        $normalized,
    );
    Assert::same(
        (string) $vector['pinned_record_payload_digest'],
        $phpDigest,
        "{$outcome}: THE DIGEST MATCHES CANONICAL'S — the two runtimes agree what this record is",
    );

    // And the port's own stored digest is this same encoder over its own real ids, so the stored
    // record is not a separately-computed thing that merely resembles the vector.
    Assert::same(
        (string) $record['record_payload_digest'],
        DecisionLedger::computeRecordPayloadDigest(
            [
                'record_type' => (string) $record['record_type'],
                'materiality_class' => (string) $record['materiality_class'],
                'primary_outcome_status' => (string) $record['primary_outcome_status'],
                'mission_id' => $record['mission_id'],
                'proposal_id' => $record['proposal_id'],
                'proposal_submission_digest' => $record['proposal_submission_digest'],
                'correlation_id' => $record['correlation_id'],
                'safe_decision_summary' => (string) $record['safe_decision_summary'],
                'reason_codes' => json_decode((string) $record['reason_codes'], true, 512, JSON_THROW_ON_ERROR),
                'uncertainty_and_limitation_summary' => $record['uncertainty_and_limitation_summary'],
                'severity' => $record['severity'],
                'incident_evaluation_required' => (bool) $record['incident_evaluation_required'],
                'supersedes_or_corrects_decision_record_id' => $record['supersedes_or_corrects_decision_record_id'],
                'current_reliance_status' => (string) $record['current_reliance_status'],
                'record_sequence_number' => (int) $record['record_sequence_number'],
            ],
            array_map(static fn (array $row): array => [
                'owner_module_id' => (string) $row['owner_module_id'],
                'input_object_type' => (string) $row['input_object_type'],
                'input_object_id' => (string) $row['input_object_id'],
                'input_object_version_or_digest' => $row['input_object_version_or_digest'],
                'reference_role' => (string) $row['reference_role'],
                'verification_status_at_record_time' => (string) $row['verification_status_at_record_time'],
            ], array_values($references)),
        ),
        "{$outcome}: and the stored digest is that same encoder over this run's real ids",
    );
}

// ==================================================================================== the vocabulary
// EVERY input-object type in the port, checked against canonical's — not just P07-A's five.
//
// The P07-A record cited `ARBITER_VERDICT` where canonical writes `arbiter_verdict`, and invented
// `SYNTHETIC_DISPATCH_OUTCOME`, which exists nowhere upstream. Both are inside
// `record_payload_digest`, so both produced a different digest for the same governed event while
// verifying perfectly. Fixing the two P07-A references does not stop the third one, so the
// vocabulary is pinned for the whole port.
//
// The canonical list below is every `inputObjectType:` literal in
// packages/allfather-control-substrate/src at the pinned head, test files excluded. A port value
// outside it is either a casing slip or an invention, and there is no third possibility.

Assert::case('M10 input-object types are canonical vocabulary, port-wide');

$canonicalObjectTypes = [
    'action_execution_acknowledgement',
    'approval_decision',
    'arbiter_verdict',
    'authority_source',
    'decision_record',
    'evidence_bundle',
    'execution_authorization',
    'frozen_action_artifact',
    'frozen_outreach_artifact',
    'hmwh_result',
    'outreach_execution_authorization',
    'proposal_envelope',
    'replay_result',
    'tool_authorization_decision',
    'tool_dispatch_record',
    'tool_execution_outcome',
];

$appRoot = dirname(__DIR__, 2) . '/app';
$portObjectTypes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (preg_match_all(
        "/'input_object_type'\s*=>\s*'([^']+)'/",
        (string) file_get_contents((string) $file),
        $matches
    ) > 0) {
        foreach ($matches[1] as $type) {
            $portObjectTypes[$type] = str_replace(dirname(__DIR__, 2) . '/', '', (string) $file);
        }
    }
}
Assert::true($portObjectTypes !== [], 'the scan found object types at all — an empty result proves nothing');

foreach ($portObjectTypes as $type => $where) {
    Assert::true(
        in_array($type, $canonicalObjectTypes, true),
        "{$type} (in {$where}) is a canonical object type, not an invention or a casing slip",
    );
    // Stated separately so a canonical value that gains an upper-case spelling upstream would not
    // quietly make the port's upper-case one legitimate.
    Assert::same(
        strtolower($type),
        $type,
        "{$type} is lower-case, as every canonical object type is — casing is inside the digest",
    );
}

// The one that started this, by name: it must not exist anywhere in the port.
Assert::false(
    array_key_exists('SYNTHETIC_DISPATCH_OUTCOME', $portObjectTypes),
    'the invented SYNTHETIC_DISPATCH_OUTCOME object type is gone from the port entirely',
);
