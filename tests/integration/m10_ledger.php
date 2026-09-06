<?php

declare(strict_types=1);

/**
 * M10 decision ledger, proved against real MariaDB.
 *
 * The ledger is what makes every downstream claim checkable. These tests exercise the four
 * properties the rest of the system rests on: the canonical-basis gate, M10's sole ownership of
 * release, the hash chain, and supersession-without-deletion.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M08\Arbiter;
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

// --- stream lifecycle ---------------------------------------------------------------------------

$created = $ledger->createStream($scope['tenant_id'], $scope['workspace_id'], 'TEST');
Assert::true($created->isOk(), 'a ledger stream is created');
$streamId = $created->value()['decision_ledger_stream_id'];
Assert::same(64, strlen($created->value()['genesis_hash']), 'the genesis anchor is a sha256');

$duplicate = $ledger->createStream($scope['tenant_id'], $scope['workspace_id'], 'TEST');
Assert::true($duplicate->isRefusal(), 'a second ACTIVE stream for the same scope is refused');
Assert::same(
    'LEDGER_ACTIVE_STREAM_ALREADY_EXISTS',
    $duplicate->reasonCode(),
    'competing heads would be competing histories',
);

// --- THE canonical-basis gate (M10-DEC-006) --------------------------------------------------------

$noBasis = $ledger->append($streamId, 'PROPOSAL_HELD', 'GOVERNANCE_MATERIAL', 'HELD', 'held', []);
Assert::true($noBasis->isRefusal(), 'a record with NO input reference is refused');
Assert::same('LEDGER_MANDATORY_INPUT_REFERENCE_MISSING', $noBasis->reasonCode(), 'refused by name');

// --- M10 owns release: the record type is not reachable from outside ---------------------------
// The record the console and the release-readiness projection treat as PROOF of release must not be
// mintable by a caller that skipped the validation. This used to be guarded by a capability object
// with a public factory, which any importing module could call — a lock whose key was on the door.
// It is now guarded by reachability: `append()` refuses the type, and the code that can pass it is
// a private method of this class.

$fabricatedBasis = [
    ['owner_module_id' => 'M08', 'input_object_type' => 'arbiter_verdict', 'input_object_id' => Ids::new(),
     'reference_role' => 'VERDICT_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
    ['owner_module_id' => 'M04', 'input_object_type' => 'proposal_envelope', 'input_object_id' => Ids::new(),
     'reference_role' => 'PROPOSAL_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
];

$forged = $ledger->append(
    $streamId, 'INTERNAL_INFORMATIONAL_RELEASE', 'GOVERNANCE_MATERIAL', 'RELEASED_INTERNAL',
    'forged release', $fabricatedBasis
);
Assert::true($forged->isRefusal(), 'a release record CANNOT be minted through the public append');
Assert::same(
    'LEDGER_RELEASE_RECORD_REQUIRES_RELEASE_PATH',
    $forged->reasonCode(),
    'the record the console treats as PROOF of release is refused by record type',
);

// And there is no SECOND argument that unlocks it. A guard that can be satisfied by supplying
// something is only as strong as the difficulty of supplying it; this one cannot be satisfied at
// all, because `append()` takes no authorization parameter of any kind.
$appendSignature = new ReflectionMethod(DecisionLedger::class, 'append');
Assert::equalsList(
    ['ledgerStreamId', 'recordType', 'materialityClass', 'primaryOutcomeStatus',
     'safeDecisionSummary', 'inputReferences', 'fields'],
    array_map(static fn (ReflectionParameter $p): string => $p->getName(), $appendSignature->getParameters()),
    'the public append takes no capability, token or override parameter',
);
Assert::same(
    false,
    file_exists(dirname(__DIR__, 2) . '/app/Substrate/M10/ReleasePathCapability.php'),
    'and the forgeable capability class it used to accept no longer exists',
);
Assert::same(
    false,
    (new ReflectionMethod(DecisionLedger::class, 'appendRecord'))->isPublic(),
    'the unrestricted append is private to M10',
);

// --- the validated release path, on REAL governed records ------------------------------------------
// The basis is not supplied by the caller and is not fabricated here. The chain below is built by
// the real modules; M10 is handed one thing — the verdict identity — and reads the rest itself.

$arbiter = new Arbiter($db, $clock, new Readiness($db, $clock));
$chain = Seed::governedReleaseChain(
    $db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'BATTLECARD_BRIEF', true
);
$chainVerdict = $arbiter->evaluateProposal($chain['proposal_id'], $chain['arbiter_policy_set_id']);
Assert::true($chainVerdict->isOk(), 'the seeded chain produces a real arbiter verdict');
Assert::same(
    'ALLOW_INFORMATIONAL_RELEASE',
    (string) $chainVerdict->value()['verdict'],
    'and it is an ALLOW routed to internal release',
);
$verdictId = (string) $chainVerdict->value()['arbiter_verdict_id'];
$proposalId = (string) $chain['proposal_id'];

Assert::same(
    'SUBMITTED',
    (string) $db->scalar('SELECT status FROM proposal_envelopes WHERE proposal_id = ?', [$proposalId]),
    'the proposal is SUBMITTED before the release',
);

$released = $ledger->appendInternalReleaseDecisionRecord($verdictId);
Assert::true($released->isOk(), 'the validated release path mints the release record');
$releaseRecordId = $released->value()['decision_record_id'];
Assert::same(1, $released->value()['record_sequence_number'], 'it is record 1 in the stream');

// The flip is M10's, in M10's transaction. No product module and no M04 method performed it.
Assert::same(
    'RELEASED',
    (string) $db->scalar('SELECT status FROM proposal_envelopes WHERE proposal_id = ?', [$proposalId]),
    'and M10 itself advanced the proposal to RELEASED',
);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM proposal_status_transitions
         WHERE proposal_id = ? AND to_status = 'RELEASED' AND initiated_by_actor_type = 'M10'
           AND reason_code = 'M10_DECISION_RECORD_APPENDED' AND initiated_by_actor_id = ?",
        [$proposalId, $releaseRecordId]
    ),
    'with exactly one M10 transition naming the decision record that caused it',
);

// The references M10 built for itself, from the records it read.
$builtReferences = $db->all(
    'SELECT owner_module_id, input_object_type, input_object_id, reference_role,
            verification_status_at_record_time
     FROM decision_input_references WHERE decision_record_id = ? ORDER BY reference_role',
    [$releaseRecordId]
);
Assert::equalsList(
    ['EVIDENCE_BASIS', 'PROPOSAL_BASIS', 'REVIEW_BASIS', 'VERDICT_BASIS'],
    array_column($builtReferences, 'reference_role'),
    'M10 builds the verdict, proposal, evidence and review basis itself, from the verdict row',
);
$basisByRole = array_column($builtReferences, 'input_object_id', 'reference_role');
Assert::same(
    $verdictId,
    (string) $basisByRole['VERDICT_BASIS'],
    'and the verdict basis is the verdict it was actually given',
);
Assert::same(
    $proposalId,
    (string) $basisByRole['PROPOSAL_BASIS'],
    'and the proposal basis is the one that verdict names, not one the caller chose',
);
Assert::same(
    [],
    array_values(array_filter(
        $builtReferences,
        static fn (array $r): bool => (string) $r['verification_status_at_record_time'] !== 'VERIFIED_REFERENCE'
    )),
    'every reference it built is a VERIFIED_REFERENCE, because it verified them',
);

// A second call on the same verdict does not mint a second release: the proposal is no longer
// SUBMITTED, and the guarded flip refuses rather than releasing twice.
$twice = $ledger->appendInternalReleaseDecisionRecord($verdictId);
Assert::true($twice->isRefusal(), 'the same verdict cannot release the same proposal twice');
Assert::same('LEDGER_PROPOSAL_NOT_RELEASABLE', $twice->reasonCode(), 'refused by name');
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_records
         WHERE proposal_id = ? AND record_type = 'INTERNAL_INFORMATIONAL_RELEASE'",
        [$proposalId]
    ),
    'and exactly one release record exists for the proposal',
);

// --- a required role present but UNVERIFIED does not satisfy the gate -------------------------------------
// The release path no longer takes caller basis at all, so this gate is exercised on the other
// record type that carries mandatory roles. The property is the same one: a role that is PRESENT
// but not VERIFIED does not satisfy a mandatory basis role.

$demoBasis = [
    ['owner_module_id' => 'M08', 'input_object_type' => 'arbiter_verdict', 'input_object_id' => Ids::new(),
     'reference_role' => 'VERDICT_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
    ['owner_module_id' => 'M09', 'input_object_type' => 'approval_decision', 'input_object_id' => Ids::new(),
     'reference_role' => 'APPROVAL_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
    ['owner_module_id' => 'M12', 'input_object_type' => 'frozen_artifact', 'input_object_id' => Ids::new(),
     'reference_role' => 'ARTIFACT_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
    ['owner_module_id' => 'M09', 'input_object_type' => 'action_authorization', 'input_object_id' => Ids::new(),
     'reference_role' => 'AUTHORIZATION_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
    ['owner_module_id' => 'M06', 'input_object_type' => 'tool_outcome', 'input_object_id' => Ids::new(),
     // Present, but explicitly NOT verified.
     'reference_role' => 'OUTCOME_BASIS', 'verification_status_at_record_time' => 'PRESENT_UNVERIFIED'],
];
$unverified = $ledger->append(
    $streamId, 'HARMLESS_DEMO_ACTION_COMPLETED', 'GOVERNANCE_MATERIAL', 'COMPLETED',
    'unverified basis', $demoBasis
);
Assert::true($unverified->isRefusal(), 'a mandatory role present but UNVERIFIED does not satisfy the gate');
Assert::same('LEDGER_MANDATORY_INPUT_REFERENCE_MISSING', $unverified->reasonCode(), 'refused by name');
Assert::equalsList(
    ['OUTCOME_BASIS'],
    $unverified->detail['missing'],
    'and the refusal names exactly which role was unsatisfied',
);

foreach (['REQUIRED_BUT_MISSING', 'RESTRICTED_REFERENCE'] as $status) {
    $basis = $demoBasis;
    $basis[4]['verification_status_at_record_time'] = $status;
    $refused = $ledger->append(
        $streamId, 'HARMLESS_DEMO_ACTION_COMPLETED', 'GOVERNANCE_MATERIAL', 'COMPLETED',
        'basis ' . $status, $basis
    );
    Assert::true($refused->isRefusal(), "a {$status} reference does not satisfy a mandatory role");
}

// --- the chain ------------------------------------------------------------------------------------------------

for ($i = 0; $i < 3; $i++) {
    $appended = $ledger->append(
        $streamId, 'PROPOSAL_HELD', 'GOVERNANCE_MATERIAL', 'HELD', "held {$i}",
        [['owner_module_id' => 'M04', 'input_object_type' => 'PROPOSAL', 'input_object_id' => Ids::new(),
          'reference_role' => 'PROPOSAL_BASIS']],
        ['reason_codes' => ['ARBITER_HOLD']]
    );
    Assert::true($appended->isOk(), "record " . ($i + 2) . " appends");
    Assert::same($i + 2, $appended->value()['record_sequence_number'], 'the sequence advances by one');
}

$verified = $ledger->verifyStream($streamId);
Assert::same('PASS', $verified['status'], 'the chain verifies from genesis');
Assert::same(4, $verified['records_verified'], 'all four records verify');
Assert::same([], $verified['failures'], 'with no failures');

// --- tamper detection -----------------------------------------------------------------------------------------
// The payload digest commits to the record fields AND its reference set, so both kinds of tamper
// surface. This is why the digest is not merely over the record's own columns.
//
// `decision_records` and `decision_input_references` are fully immutable at storage level
// (INV-013), so the honest path cannot produce a tamper at all — which is the point. The tamper is
// therefore applied the way an attacker WITH database privileges would: by removing the trigger.
// Each trigger's exact definition is captured first and restored afterwards, so this suite cannot
// leave the schema weaker than production's for the suites that follow it.

/** @return array<string, string> trigger name => body */
$captureTriggers = static function (array $tables) use ($db): array {
    $placeholders = implode(', ', array_fill(0, count($tables), '?'));
    $rows = $db->all(
        "SELECT trigger_name, action_timing, event_manipulation, event_object_table, action_statement
         FROM information_schema.triggers
         WHERE trigger_schema = DATABASE() AND event_object_table IN ({$placeholders})",
        $tables
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['trigger_name']] = sprintf(
            'CREATE TRIGGER %s %s %s ON %s FOR EACH ROW %s',
            $row['trigger_name'],
            $row['action_timing'],
            $row['event_manipulation'],
            $row['event_object_table'],
            $row['action_statement'],
        );
    }

    return $out;
};

$savedTriggers = $captureTriggers(['decision_records', 'decision_input_references']);
Assert::true(
    isset($savedTriggers['trg_decision_records_no_update']),
    'decision_records is immutable at storage level (INV-013), not merely by convention',
);
Assert::true(
    isset($savedTriggers['trg_decision_records_no_delete']),
    'and cannot be deleted either',
);

$victim = (string) $db->scalar(
    'SELECT decision_record_id FROM decision_records WHERE ledger_stream_id = ? AND record_sequence_number = 3',
    [$streamId]
);

$db->pdo()->exec('DROP TRIGGER IF EXISTS trg_decision_records_no_update');
$db->run('UPDATE decision_records SET safe_decision_summary = ? WHERE decision_record_id = ?', ['TAMPERED', $victim]);

$afterTamper = $ledger->verifyStream($streamId);
Assert::same('FAIL', $afterTamper['status'], 'a tampered field breaks verification');
Assert::true(
    in_array('LEDGER_PAYLOAD_DIGEST_MISMATCH', array_column($afterTamper['failures'], 'reason_code'), true),
    'and is reported as a payload digest mismatch',
);

$db->run('UPDATE decision_records SET safe_decision_summary = ? WHERE decision_record_id = ?', ['held 1', $victim]);
Assert::same('PASS', $ledger->verifyStream($streamId)['status'], 'restoring the field restores verification');

// Tampering with a REFERENCE is caught too — the record commits to its exact upstream basis.
$db->pdo()->exec('DROP TRIGGER IF EXISTS trg_decision_input_references_no_update');
$originalRefId = (string) $db->scalar(
    'SELECT input_object_id FROM decision_input_references WHERE decision_record_id = ? LIMIT 1',
    [$victim]
);
$db->run(
    'UPDATE decision_input_references SET input_object_id = ? WHERE decision_record_id = ?',
    [Ids::new(), $victim]
);
$afterRefTamper = $ledger->verifyStream($streamId);
Assert::same('FAIL', $afterRefTamper['status'], 'swapping a reference breaks verification');
Assert::true(
    in_array('LEDGER_PAYLOAD_DIGEST_MISMATCH', array_column($afterRefTamper['failures'], 'reason_code'), true),
    'because the digest commits to the reference set, not only to the record columns',
);
$db->run(
    'UPDATE decision_input_references SET input_object_id = ? WHERE decision_record_id = ?',
    [$originalRefId, $victim]
);
Assert::same('PASS', $ledger->verifyStream($streamId)['status'], 'restoring the reference restores verification');

// Restore every trigger EXACTLY as it was captured, then prove the restoration actually took —
// an unverified restore is how a suite silently weakens the database for every suite after it.
foreach ($savedTriggers as $name => $definition) {
    $db->pdo()->exec('DROP TRIGGER IF EXISTS ' . $name);
    $db->pdo()->exec($definition);
}
$restored = $captureTriggers(['decision_records', 'decision_input_references']);
Assert::same(
    count($savedTriggers),
    count($restored),
    'every dropped trigger is restored — this suite leaves the schema exactly as it found it',
);
try {
    $db->run('UPDATE decision_records SET safe_decision_summary = ? WHERE decision_record_id = ?', ['x', $victim]);
    Assert::fail('the restored immutability trigger should reject UPDATE');
} catch (\PDOException) {
    Assert::pass();
}

// --- supersession: withdraw reliance, delete NOTHING ------------------------------------------------------------

$freshScope = Seed::tenantWorkspace($db, $clock, 'rollback');
$freshStream = $ledger->createStream($freshScope['tenant_id'], $freshScope['workspace_id'], 'TEST')
    ->value()['decision_ledger_stream_id'];

$freshChain = Seed::governedReleaseChain(
    $db, $clock, $freshScope, [], 'PROCEED_TO_ARBITER', 'BATTLECARD_BRIEF', true
);
$freshVerdict = $arbiter->evaluateProposal($freshChain['proposal_id'], $freshChain['arbiter_policy_set_id']);
$live = $ledger->appendInternalReleaseDecisionRecord(
    (string) $freshVerdict->value()['arbiter_verdict_id']
)->value();
Assert::same($freshStream, $live['ledger_stream_id'], 'the release lands in the scope\'s ACTIVE stream');
Assert::same(true, $ledger->isCurrentlyRelied($live['decision_record_id']), 'a fresh release is currently relied on');

$superseded = $ledger->supersede(
    $live['decision_record_id'],
    'SUPERSEDED_BY_NEW_DECISION',
    'M11',
    Ids::new(),
    'owner stand-down: reliance withdrawn',
);
Assert::true($superseded->isOk(), 'the release is superseded');
Assert::same('SUPERSEDED', $ledger->currentReliance($live['decision_record_id']), 'reliance is withdrawn');
Assert::same(false, $ledger->isCurrentlyRelied($live['decision_record_id']), 'and it is no longer currently relied on');

// The CORRECTION record itself stays ACTIVE. If the new status were stored on the successor,
// consumers would ignore the very record carrying the correction.
Assert::same(
    'ACTIVE_AS_RECORDED',
    $ledger->currentReliance($superseded->value()['successor_decision_record_id']),
    'the correction record itself remains ACTIVE',
);

// The record still EXISTS and is UNEDITED. That is the whole point: reliance is derived from the
// supersession row, so the original's payload digest still verifies as history.
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM decision_records WHERE decision_record_id = ?', [$live['decision_record_id']]),
    'the superseded record is PRESERVED, not deleted',
);
Assert::same(
    'Internal informational release recorded after M08 ALLOW verdict.',
    (string) $db->scalar('SELECT safe_decision_summary FROM decision_records WHERE decision_record_id = ?', [$live['decision_record_id']]),
    'and its content is byte-intact',
);
Assert::same(
    'ACTIVE_AS_RECORDED',
    (string) $db->scalar('SELECT current_reliance_status FROM decision_records WHERE decision_record_id = ?', [$live['decision_record_id']]),
    'the original row was never EDITED — reliance is derived, not mutated',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM decision_supersession_records WHERE original_decision_record_id = ?', [$live['decision_record_id']]),
    'the supersession itself is recorded',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT original_remains_historical FROM decision_supersession_records WHERE original_decision_record_id = ?', [$live['decision_record_id']]),
    'and original_remains_historical is CHECK-pinned to 1',
);

// The whole stream — original, correction and all — still verifies.
Assert::same('PASS', $ledger->verifyStream($freshStream)['status'], 'the stream verifies AFTER the supersession');

// A later supersession wins, ordered by the successor's ledger sequence rather than by timestamp.
$again = $ledger->supersede(
    $live['decision_record_id'],
    'INVALIDATED_FOR_CURRENT_RELIANCE',
    'M11',
    Ids::new(),
    'second correction',
    'INVALIDATED_FOR_CURRENT_RELIANCE',
);
Assert::true($again->isOk(), 'a record can be corrected more than once');
Assert::same(
    'INVALIDATED_FOR_CURRENT_RELIANCE',
    $ledger->currentReliance($live['decision_record_id']),
    'the LATEST supersession wins, by ledger sequence rather than by timestamp',
);

// --- the ledger is append-only at storage level ----------------------------------------------------------------------

try {
    $db->run('DELETE FROM decision_records WHERE decision_record_id = ?', [$live['decision_record_id']]);
    Assert::fail('decision_records should reject DELETE');
} catch (\PDOException) {
    Assert::pass();
}
