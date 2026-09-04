<?php

declare(strict_types=1);

/**
 * M10 decision ledger, proved against real MariaDB.
 *
 * The ledger is what makes every downstream claim checkable. These tests exercise the four
 * properties the rest of the system rests on: the canonical-basis gate, the release-path
 * capability, the hash chain, and supersession-without-deletion.
 */

use Allfather\Loopia\Core\Ids;
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

// --- THE release-path capability ---------------------------------------------------------------------

$proposalId = Ids::new();
$verdictId = Ids::new();
$fullBasis = [
    ['owner_module_id' => 'M08', 'input_object_type' => 'ARBITER_VERDICT', 'input_object_id' => $verdictId,
     'reference_role' => 'VERDICT_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
    ['owner_module_id' => 'M04', 'input_object_type' => 'PROPOSAL', 'input_object_id' => $proposalId,
     'reference_role' => 'PROPOSAL_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
];

$forged = $ledger->append(
    $streamId, 'INTERNAL_INFORMATIONAL_RELEASE', 'GOVERNANCE_MATERIAL', 'RELEASED_INTERNAL',
    'forged release', $fullBasis
);
Assert::true($forged->isRefusal(), 'a release record CANNOT be minted by a direct caller');
Assert::same(
    'LEDGER_RELEASE_RECORD_REQUIRES_RELEASE_PATH',
    $forged->reasonCode(),
    'the record the console treats as PROOF of release is unforgeable',
);

// The validated release path succeeds with the same basis.
$released = $ledger->appendInternalRelease($streamId, 'released briefing', $fullBasis);
Assert::true($released->isOk(), 'the validated release path mints the release record');
$releaseRecordId = $released->value()['decision_record_id'];
Assert::same(1, $released->value()['record_sequence_number'], 'it is record 1 in the stream');

// --- a required role present but UNVERIFIED does not satisfy the gate -------------------------------------

$unverifiedBasis = [
    ['owner_module_id' => 'M08', 'input_object_type' => 'ARBITER_VERDICT', 'input_object_id' => Ids::new(),
     'reference_role' => 'VERDICT_BASIS', 'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
    ['owner_module_id' => 'M04', 'input_object_type' => 'PROPOSAL', 'input_object_id' => Ids::new(),
     // Present, but explicitly NOT verified.
     'reference_role' => 'PROPOSAL_BASIS', 'verification_status_at_record_time' => 'PRESENT_UNVERIFIED'],
];
$unverified = $ledger->appendInternalRelease($streamId, 'unverified basis', $unverifiedBasis);
Assert::true($unverified->isRefusal(), 'a mandatory role present but UNVERIFIED does not satisfy the gate');
Assert::same('LEDGER_MANDATORY_INPUT_REFERENCE_MISSING', $unverified->reasonCode(), 'refused by name');
Assert::equalsList(
    ['PROPOSAL_BASIS'],
    $unverified->detail['missing'],
    'and the refusal names exactly which role was unsatisfied',
);

foreach (['REQUIRED_BUT_MISSING', 'RESTRICTED_REFERENCE'] as $status) {
    $basis = $unverifiedBasis;
    $basis[1]['verification_status_at_record_time'] = $status;
    $refused = $ledger->appendInternalRelease($streamId, 'basis ' . $status, $basis);
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

$victim = (string) $db->scalar(
    'SELECT decision_record_id FROM decision_records WHERE ledger_stream_id = ? AND record_sequence_number = 3',
    [$streamId]
);

// `decision_records` deliberately carries only a no-DELETE trigger, not a no-UPDATE one:
// supersession must be able to change `current_reliance_status` in place. That makes the payload
// digest the thing standing between a database-level field edit and an undetected forgery, which
// is exactly what this asserts.
$db->run('UPDATE decision_records SET safe_decision_summary = ? WHERE decision_record_id = ?', ['TAMPERED', $victim]);

$afterTamper = $ledger->verifyStream($streamId);
Assert::same('FAIL', $afterTamper['status'], 'a tampered field breaks verification');
$reasons = array_column($afterTamper['failures'], 'reason_code');
Assert::true(in_array('LEDGER_PAYLOAD_DIGEST_MISMATCH', $reasons, true), 'and is reported as a payload digest mismatch');

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

// Restore both the reference and the triggers: a test that leaves the schema mutated would make
// every later suite in this process run against a weaker database than production has.
$db->run(
    'UPDATE decision_input_references SET input_object_id = ? WHERE decision_record_id = ?',
    [$originalRefId, $victim]
);
Assert::same('PASS', $ledger->verifyStream($streamId)['status'], 'restoring the reference restores verification');

$db->pdo()->exec(
    "CREATE TRIGGER trg_decision_input_references_no_update BEFORE UPDATE ON decision_input_references FOR EACH ROW
     BEGIN
       SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'decision_input_references is append-only';
     END"
);

// --- supersession: withdraw reliance, delete NOTHING ------------------------------------------------------------

$freshScope = Seed::tenantWorkspace($db, $clock, 'rollback');
$freshStream = $ledger->createStream($freshScope['tenant_id'], $freshScope['workspace_id'], 'TEST')
    ->value()['decision_ledger_stream_id'];

$live = $ledger->appendInternalRelease($freshStream, 'live release', $fullBasis)->value();
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
    'live release',
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
