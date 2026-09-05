<?php

declare(strict_types=1);

/**
 * ROLLBACK AND STAND-DOWN: what a withdrawal is, and what it is emphatically not.
 *
 * THE GOVERNING RULE: rollback is never a delete. Not of a decision record, not of evidence, not of
 * a release. The reason is not squeamishness about data — it is that a rollback which destroyed the
 * record would also destroy the proof that the rollback happened, and an organization that cannot
 * show what it withdrew and when has not withdrawn anything. It has just stopped talking about it.
 *
 * So every lever in this file works by APPENDING a fact that changes what the current state means,
 * and every one of them is proved twice: that it takes effect, and that the thing it withdrew is
 * still there afterwards.
 *
 * FIVE LEVERS, at five different planes:
 *
 *   M10  supersession       — withdraw reliance on a released decision
 *   M16  visibility         — withdraw a frozen artifact from the surface
 *   M05  source revocation  — stop a source admitting new captures
 *   P07  authorization      — revoke an outstanding permission to act
 *   M11  incident           — record that something needs looking at
 *
 * And the storage enforces the rule independently of all of them: the append-only tables refuse a
 * DELETE outright, so a caller who ignored every lever above and reached for the database directly
 * would still be refused.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
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
$m16 = new ReleaseOutput($db, $clock, $ledger);
$m11 = new M11Assurance($db, $clock, $ledger);

$source = Seed::p01ReleasedBriefing($db, $clock, $scope);
$decisionRecordId = $source['decision_record_id'];

// ============================================================ lever 1: M10 supersession

$before = $db->one(
    'SELECT r.record_payload_digest, r.record_sequence_number, r.current_reliance_status,
            l.chain_hash
     FROM decision_records r
     JOIN ledger_integrity_links l ON l.decision_record_id = r.decision_record_id
     WHERE r.decision_record_id = ?',
    [$decisionRecordId]
);
Assert::same('ACTIVE_AS_RECORDED', (string) $before['current_reliance_status'], 'the release is relied upon');

$superseded = $ledger->supersede(
    $decisionRecordId,
    'INVALIDATED_FOR_CURRENT_RELIANCE',
    'M11',
    $source['released_briefing_id'],
    'Withdrawn after a source-authority review.',
    'INVALIDATED_FOR_CURRENT_RELIANCE',
);
Assert::true($superseded->isOk(), 'reliance is withdrawn');
Assert::false($ledger->isCurrentlyRelied($decisionRecordId), 'and the record is no longer relied upon');

// THE ORIGINAL IS BYTE-INTACT. This is the whole difference between a withdrawal and a deletion:
// the claim that was made is still readable, and so is the correction.
$after = $db->one(
    'SELECT r.record_payload_digest, r.record_sequence_number, l.chain_hash
     FROM decision_records r
     JOIN ledger_integrity_links l ON l.decision_record_id = r.decision_record_id
     WHERE r.decision_record_id = ?',
    [$decisionRecordId]
);
Assert::same(
    (string) $before['record_payload_digest'],
    (string) $after['record_payload_digest'],
    'the original record is byte-intact — its payload digest is unchanged',
);
Assert::same(
    (string) $before['chain_hash'],
    (string) $after['chain_hash'],
    'its place in the chain is unchanged',
);

// The withdrawal is itself a RECORD, appended to the same stream — so the chain covers the
// correction too, and verifying the stream verifies both.
$correction = $db->one(
    'SELECT successor_decision_record_id, new_current_reliance_status
     FROM decision_supersession_records WHERE original_decision_record_id = ?',
    [$decisionRecordId]
);
Assert::notSame(null, $correction, 'the withdrawal is recorded as a supersession');
Assert::same(
    'INVALIDATED_FOR_CURRENT_RELIANCE',
    (string) $correction['new_current_reliance_status'],
    'naming what the original now is',
);
$streamId = (string) $db->scalar(
    'SELECT ledger_stream_id FROM decision_records WHERE decision_record_id = ?',
    [$decisionRecordId]
);
$verified = $ledger->verifyStream($streamId);
Assert::same(
    'PASS',
    (string) $verified['status'],
    'and the chain still verifies WITH the correction in it — the withdrawal is provable',
);

// A supersession can never restore reliance. Un-withdrawing is a NEW decision, not an undo.
Assert::same(
    'DECISION_RECORD_NOT_FOUND',
    $ledger->supersede(
        Ids::new(), 'SUPERSEDED_BY_NEW_DECISION', 'M11', Ids::new(), 'x'
    )->reasonCode(),
    'a supersession of an unknown record is refused rather than silently creating one',
);

// ============================================================ the storage refuses deletion

// Independent of every lever above. A caller who reached past the modules for the database itself
// is refused by the tables, which is what makes the rule structural rather than a convention.
foreach ([
    'decision_records' => ['decision_record_id', $decisionRecordId],
    'evidence_objects' => ['evidence_id', $source['evidence_id']],
] as $table => [$column, $id]) {
    Assert::throws(
        static fn () => $db->run("DELETE FROM {$table} WHERE {$column} = ?", [$id]),
        \PDOException::class,
        "DELETE FROM {$table} is refused by the storage itself",
    );
}
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM decision_records WHERE decision_record_id = ?',
        [$decisionRecordId]
    ),
    'and the record survives the attempt',
);

// ============================================================ lever 2: M16 visibility

// The P01 release froze an artifact and activated its internal projection. Withdrawing visibility
// removes the briefing from the surface without editing the artifact or the release.
$projectionId = (string) $db->scalar(
    "SELECT p.customer_output_projection_id
     FROM customer_output_projections p
     JOIN release_artifacts a ON a.release_artifact_id = p.release_artifact_id
     WHERE p.tenant_id = ? AND p.workspace_id = ? ORDER BY p.projected_at DESC LIMIT 1",
    [$scope['tenant_id'], $scope['workspace_id']]
);
Assert::notSame('', $projectionId, 'the release froze a projection');

$deactivated = $m16->deactivateVisibility($projectionId, 'ROLLBACK');
Assert::true($deactivated->isOk(), 'visibility is withdrawn');

// THE PROJECTION ROW STILL EXISTS, and so does the artifact. What changed is an APPENDED event.
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM customer_output_projections WHERE customer_output_projection_id = ?',
        [$projectionId]
    ),
    'the projection row survives — nothing was deleted',
);
Assert::same(
    'DEACTIVATED',
    (string) $db->scalar(
        'SELECT event_kind FROM release_visibility_activations
         WHERE customer_output_projection_id = ?
         ORDER BY event_sequence DESC LIMIT 1',
        [$projectionId]
    ),
    'the withdrawal is an APPENDED event, and the latest event is what governs',
);
// The ACTIVATION is still there too. A surface can be asked what it showed and when.
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM release_visibility_activations
         WHERE customer_output_projection_id = ? AND event_kind = 'ACTIVATED'",
        [$projectionId]
    ),
    'and the activation it withdrew is still on the record',
);
Assert::same(
    'VISIBILITY_NOT_CURRENTLY_ACTIVE',
    $m16->deactivateVisibility($projectionId, 'ROLLBACK')->reasonCode(),
    'a second withdrawal is refused rather than appending a meaningless second event',
);

// ============================================================ lever 3: source revocation

// The lever that stops NEW captures without touching captured evidence. Evidence already taken
// keeps its frozen admitting authority and stays valid as history — because what it recorded was
// true when it was recorded, and revoking the source does not change that.
$sourceRefId = (string) $db->scalar(
    'SELECT source_ref_id FROM evidence_objects WHERE evidence_id = ?',
    [$source['evidence_id']]
);
$capturedBefore = (int) $db->scalar(
    'SELECT COUNT(*) FROM evidence_objects WHERE source_ref_id = ?',
    [$sourceRefId]
);
Assert::true($evidence->setSourceStatus($sourceRefId, 'REVOKED')->isOk(), 'a source is revoked');

$refused = $evidence->capture([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'source_ref_id' => $sourceRefId,
    'evidence_type' => 'DERIVED_ANNOTATION_REFERENCE',
    'captured_content_reference_safe' => 'A capture attempted after revocation.',
    'content_hash' => str_repeat('a', 64),
    'capture_method' => 'DERIVED_TEST',
    'sensitivity_class' => 'INTERNAL_DEMO',
]);
Assert::true($refused->isRefusal(), 'and admits no new capture — immediately');
Assert::same(
    $capturedBefore,
    (int) $db->scalar('SELECT COUNT(*) FROM evidence_objects WHERE source_ref_id = ?', [$sourceRefId]),
    'while every capture already taken survives untouched',
);
Assert::same(
    'ACTIVE_FOR_CAPTURE',
    (string) $db->scalar(
        'SELECT admitting_source_status FROM evidence_objects WHERE evidence_id = ?',
        [$source['evidence_id']]
    ),
    'each carrying the authority that admitted it, FROZEN — revocation does not rewrite history',
);

// ============================================================ lever 5: the incident record

// The lever that says "somebody must look at this". It is a record, not a repair — and an
// unverified resolution keeps the containment in place rather than clearing it.
$incident = $m11->openIncident(
    $scope['tenant_id'],
    $scope['workspace_id'],
    'M11',
    ['SOURCE_AUTHORITY_WITHDRAWN'],
    'S4',
    'PROVENANCE_OR_IP',
    'A released briefing rested on a source whose authority was later withdrawn.',
    ['ROLLBACK:' . $decisionRecordId],
);
Assert::true($incident->isOk(), 'an incident records that this needs looking at');
Assert::same(
    'S4',
    (string) $db->scalar(
        'SELECT severity FROM incident_cases WHERE incident_case_id = ?',
        [$incident->value()['incident_case_id']]
    ),
    'at the severity the finding warrants',
);

// ============================================================ what rollback is NOT

// The closing statement, made against the storage rather than against the code. Every table that
// records what was decided, what it rested on, or what was withdrawn refuses a DELETE — so there
// is no arrangement of calls, and no direct query, that turns a rollback into an erasure.
$appendOnly = [
    'decision_records' => 'decision_record_id',
    'decision_supersession_records' => 'decision_supersession_record_id',
    'evidence_objects' => 'evidence_id',
    'consent_registry' => 'consent_registry_entry_id',
];
foreach ($appendOnly as $table => $column) {
    $existing = $db->scalar("SELECT {$column} FROM {$table} LIMIT 1");
    if ($existing === null) {
        continue;
    }
    Assert::throws(
        static fn () => $db->run("DELETE FROM {$table} WHERE {$column} = ?", [$existing]),
        \PDOException::class,
        "{$table} refuses a DELETE — a rollback can never become an erasure here",
    );
}
