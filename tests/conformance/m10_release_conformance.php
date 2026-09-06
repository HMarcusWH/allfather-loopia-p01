<?php

declare(strict_types=1);

/**
 * CONF-M10-001 … CONF-M10-010 — the M10 release path, against canonical.
 *
 * Ported from `appendInternalReleaseDecisionRecord` in
 * `packages/allfather-control-substrate/src/m10/ledger.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * Every case asserts the EXACT canonical reason code. That matters more than it looks: the codes
 * distinguish six different reasons a release can be refused, and an operator reading a refused
 * release needs to know which one. Collapsing two of them into a shared "release refused" would
 * lose the difference between "this verdict was invalidated" and "this proposal already moved".
 *
 * The fixtures are REAL governed rows — a real proposal, a real evaluation run, a real verdict —
 * because that is what M10 reads. Nothing here hands the ledger a basis reference; the wrapper
 * takes a verdict identity and builds its own.
 */

use Allfather\Loopia\Core\Digest;
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
$ledger = new DecisionLedger($db, $clock);
$arbiter = new Arbiter($db, $clock, new Readiness($db, $clock));

/** A governed chain left SUBMITTED and reviewed, with the arbiter NOT yet run. */
$freshChain = static function (string $key) use ($db, $clock): array {
    $scope = Seed::tenantWorkspace($db, $clock, $key);
    $chain = Seed::governedReleaseChain(
        $db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'BATTLECARD_BRIEF', true
    );

    return ['scope' => $scope, 'chain' => $chain];
};

/**
 * Mint an arbiter verdict row for a proposal, with the fields under test.
 *
 * This writes the same two rows M08 writes — an evaluation run and its verdict — because that is
 * the only way to present M10 with a verdict whose value, routed stage, scope or digest is the
 * thing being varied. `arbiter_verdicts` is immutable at storage level, so a case cannot be built
 * by mutating a real verdict after the fact; each case needs its own row.
 */
$mintVerdict = static function (
    array $scope,
    array $chain,
    string $verdict,
    string $permittedNextStage,
    ?string $submissionDigest = null,
    ?array $scopeOverride = null,
) use ($db, $clock): string {
    $proposal = $db->one(
        'SELECT mission_id, submission_digest, proposal_type, requested_action_class,
                evidence_bundle_id
         FROM proposal_envelopes WHERE proposal_id = ?',
        [$chain['proposal_id']]
    );
    $digest = $submissionDigest ?? (string) $proposal['submission_digest'];
    $tenantId = $scopeOverride['tenant_id'] ?? $scope['tenant_id'];
    $workspaceId = $scopeOverride['workspace_id'] ?? $scope['workspace_id'];
    $now = $clock->now();

    $runId = Ids::new();
    $db->insert('arbiter_evaluation_runs', [
        'arbiter_evaluation_run_id' => $runId,
        'schema_version' => '1',
        'tenant_id' => $tenantId,
        'workspace_id' => $workspaceId,
        'mission_id' => (string) $proposal['mission_id'],
        'proposal_id' => $chain['proposal_id'],
        'proposal_submission_digest' => $digest,
        'requested_proposal_type' => (string) $proposal['proposal_type'],
        'requested_action_class' => (string) $proposal['requested_action_class'],
        'evidence_bundle_id' => $chain['evidence_bundle_id'],
        'evidence_bundle_hash' => null,
        'hmwh_result_id' => null,
        'arbiter_policy_set_id' => $chain['arbiter_policy_set_id'],
        'policy_version' => '1',
        'correlation_id' => null,
        'status' => 'VERDICT_ISSUED',
        'requested_at' => $now,
    ]);

    $verdictId = Ids::new();
    $db->insert('arbiter_verdicts', [
        'arbiter_verdict_id' => $verdictId,
        'schema_version' => '1',
        'arbiter_evaluation_run_id' => $runId,
        'tenant_id' => $tenantId,
        'workspace_id' => $workspaceId,
        'mission_id' => (string) $proposal['mission_id'],
        'proposal_id' => $chain['proposal_id'],
        'proposal_submission_digest' => $digest,
        'evidence_bundle_id' => $chain['evidence_bundle_id'],
        'evidence_bundle_hash' => null,
        'hmwh_result_id' => null,
        'arbiter_policy_set_id' => $chain['arbiter_policy_set_id'],
        'policy_version' => '1',
        'verdict' => $verdict,
        'permitted_next_stage' => $permittedNextStage,
        'admissibility_scope' => null,
        'passed_check_ids' => '[]',
        'failed_or_holding_check_ids' => '[]',
        'condition_ids' => '[]',
        'reason_codes' => '[]',
        'safe_rationale_summary' => 'Fixture verdict for the M10 release conformance suite.',
        'uncertainty_and_limitation_summary' => null,
        'severity' => null,
        'incident_evaluation_required' => 0,
        'not_human_approval_or_execution_authority' => 1,
        'basis_hash' => Digest::canonicalDigest(['fixture' => $verdictId]),
        'hash_algorithm' => 'sha256',
        'issued_at' => $now,
    ]);

    return $verdictId;
};

// ============================================================ CONF-M10-001

Assert::case('CONF-M10-001');
$one = $freshChain('conf001');
$oneVerdict = $arbiter->evaluateProposal($one['chain']['proposal_id'], $one['chain']['arbiter_policy_set_id']);
Assert::same(
    'ALLOW_INFORMATIONAL_RELEASE',
    (string) $oneVerdict->value()['verdict'],
    'CONF-M10-001: a governed chain reaches an ALLOW_INFORMATIONAL_RELEASE verdict',
);
$oneReleased = $ledger->appendInternalReleaseDecisionRecord(
    (string) $oneVerdict->value()['arbiter_verdict_id']
);
Assert::true($oneReleased->isOk(), 'CONF-M10-001: and that verdict releases');
Assert::same(
    'RELEASED',
    (string) $db->scalar(
        'SELECT status FROM proposal_envelopes WHERE proposal_id = ?', [$one['chain']['proposal_id']]
    ),
    'CONF-M10-001: with the proposal advanced to RELEASED by M10 itself',
);

// ============================================================ CONF-M10-002

Assert::case('CONF-M10-002');
$missing = $ledger->appendInternalReleaseDecisionRecord(Ids::new());
Assert::true($missing->isRefusal(), 'CONF-M10-002: a verdict that does not exist cannot release');
Assert::same(
    'LEDGER_INTERNAL_RELEASE_WITHOUT_VALID_VERDICT',
    $missing->reasonCode(),
    'CONF-M10-002: named by the canonical reason code',
);

// ============================================================ CONF-M10-003

Assert::case('CONF-M10-003');
$three = $freshChain('conf003');
$threeVerdict = $mintVerdict(
    $three['scope'], $three['chain'], 'REQUIRE_HUMAN_APPROVAL', 'M09_APPROVAL_REQUEST_CREATION'
);
$threeReleased = $ledger->appendInternalReleaseDecisionRecord($threeVerdict);
Assert::true($threeReleased->isRefusal(), 'CONF-M10-003: a non-ALLOW verdict cannot release');
Assert::same(
    'LEDGER_INTERNAL_RELEASE_WITHOUT_VALID_VERDICT',
    $threeReleased->reasonCode(),
    'CONF-M10-003: the same code — the verdict is not one that permits release',
);
Assert::same(
    'SUBMITTED',
    (string) $db->scalar(
        'SELECT status FROM proposal_envelopes WHERE proposal_id = ?', [$three['chain']['proposal_id']]
    ),
    'CONF-M10-003: and the proposal is untouched',
);

// ============================================================ CONF-M10-004

Assert::case('CONF-M10-004');
// An ALLOW_* row routed somewhere OTHER than M10 internal-release recording. Canonical validates
// BOTH the verdict value and its routed stage, exactly as the sibling M09 approval gate does: an
// imported or malformed ALLOW routed elsewhere is an ALLOW for a different path, and honouring it
// here would bypass the path that was actually chosen.
$four = $freshChain('conf004');
$fourVerdict = $mintVerdict(
    $four['scope'], $four['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M12_PROVISIONAL_DISPLAY'
);
$fourReleased = $ledger->appendInternalReleaseDecisionRecord($fourVerdict);
Assert::true($fourReleased->isRefusal(), 'CONF-M10-004: an ALLOW routed elsewhere cannot release');
Assert::same(
    'LEDGER_INTERNAL_RELEASE_WITHOUT_VALID_VERDICT',
    $fourReleased->reasonCode(),
    'CONF-M10-004: the routed stage is validated, not only the verdict value',
);

// ============================================================ CONF-M10-005

Assert::case('CONF-M10-005');
$five = $freshChain('conf005');
$fiveVerdict = $mintVerdict(
    $five['scope'], $five['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M10_INTERNAL_RELEASE_RECORDING'
);
$db->insert('arbiter_invalidations', [
    'arbiter_invalidation_id' => Ids::new(),
    'schema_version' => '1',
    'affected_object_type' => 'VERDICT',
    'affected_object_id' => $fiveVerdict,
    'reason_code' => 'UPSTREAM_EVIDENCE_REVOKED',
    'reason_summary' => 'The evidence the verdict rested on was revoked for use.',
    'trigger_record_refs' => '[]',
    'blocks_pending_progression' => 1,
    'severity' => 'S3',
    'incident_evaluation_required' => 0,
    'created_at' => $clock->now(),
]);
$fiveReleased = $ledger->appendInternalReleaseDecisionRecord($fiveVerdict);
Assert::true($fiveReleased->isRefusal(), 'CONF-M10-005: an invalidated verdict cannot release');
Assert::same(
    'LEDGER_RELEASE_VERDICT_INVALIDATED',
    $fiveReleased->reasonCode(),
    'CONF-M10-005: a distinct code — the verdict row still reads ALLOW, and is immutable',
);

// ============================================================ CONF-M10-006

Assert::case('CONF-M10-006');
$six = $freshChain('conf006');
$sixVerdict = $mintVerdict(
    $six['scope'], $six['chain'], 'ALLOW_WITH_CONDITIONS', 'M10_INTERNAL_RELEASE_RECORDING'
);
$sixRunId = (string) $db->scalar(
    'SELECT arbiter_evaluation_run_id FROM arbiter_verdicts WHERE arbiter_verdict_id = ?', [$sixVerdict]
);
$sixConditionId = Ids::new();
$db->insert('arbiter_conditions', [
    'arbiter_condition_id' => $sixConditionId,
    'schema_version' => '1',
    'arbiter_evaluation_run_id' => $sixRunId,
    'arbiter_verdict_id' => $sixVerdict,
    'condition_type' => 'UNCERTAINTY_DISCLOSURE',
    'condition_summary' => 'The uncertainty block must name the unobserved applicability.',
    'satisfaction_owner_module_id' => 'M04',
    'status' => 'OPEN',
    'satisfaction_reference_id' => null,
    'created_at' => $clock->now(),
]);
$sixReleased = $ledger->appendInternalReleaseDecisionRecord($sixVerdict);
Assert::true($sixReleased->isRefusal(), 'CONF-M10-006: an open condition blocks release');
Assert::same(
    'LEDGER_RELEASE_CONDITIONS_UNSATISFIED',
    $sixReleased->reasonCode(),
    'CONF-M10-006: named by the canonical reason code',
);
foreach (['UNSATISFIED', 'FAILED'] as $blocking) {
    $db->run(
        'UPDATE arbiter_conditions SET status = ? WHERE arbiter_condition_id = ?',
        [$blocking, $sixConditionId]
    );
    Assert::same(
        'LEDGER_RELEASE_CONDITIONS_UNSATISFIED',
        $ledger->appendInternalReleaseDecisionRecord($sixVerdict)->reasonCode(),
        "CONF-M10-006: a {$blocking} condition blocks it too",
    );
}

// ============================================================ CONF-M10-007

Assert::case('CONF-M10-007');
// The same verdict, once every condition is satisfied or superseded, DOES release. Without this
// case CONF-M10-006 would be satisfied by an implementation that simply never releases an
// ALLOW_WITH_CONDITIONS verdict at all.
$db->run(
    'UPDATE arbiter_conditions SET status = ?, satisfaction_reference_id = ? WHERE arbiter_condition_id = ?',
    ['SATISFIED_BY_REFERENCE', Ids::new(), $sixConditionId]
);
$sevenReleased = $ledger->appendInternalReleaseDecisionRecord($sixVerdict);
Assert::true(
    $sevenReleased->isOk(),
    'CONF-M10-007: ALLOW_WITH_CONDITIONS releases once its conditions are satisfied',
);
Assert::same(
    'RELEASED',
    (string) $db->scalar(
        'SELECT status FROM proposal_envelopes WHERE proposal_id = ?', [$six['chain']['proposal_id']]
    ),
    'CONF-M10-007: and the proposal advances',
);

// ============================================================ CONF-M10-008

Assert::case('CONF-M10-008');
// The proposal released in CONF-M10-007 is no longer SUBMITTED. A second verdict on it — even a
// perfectly valid one — cannot release it again.
$eightVerdict = $mintVerdict(
    $six['scope'], $six['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M10_INTERNAL_RELEASE_RECORDING'
);
$eightReleased = $ledger->appendInternalReleaseDecisionRecord($eightVerdict);
Assert::true($eightReleased->isRefusal(), 'CONF-M10-008: a proposal that is not SUBMITTED cannot release');
Assert::same(
    'LEDGER_PROPOSAL_NOT_RELEASABLE',
    $eightReleased->reasonCode(),
    'CONF-M10-008: named by the canonical reason code',
);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_records
         WHERE proposal_id = ? AND record_type = 'INTERNAL_INFORMATIONAL_RELEASE'",
        [$six['chain']['proposal_id']]
    ),
    'CONF-M10-008: and exactly one release record stands for that proposal',
);

// ============================================================ CONF-M10-009

Assert::case('CONF-M10-009');
// A verdict issued in ONE scope must not release a proposal in another. Left unchecked, the M10
// evidence for the release would also land in the wrong tenant's ledger.
$nine = $freshChain('conf009');
$otherScope = Seed::tenantWorkspace($db, $clock, 'conf009-other');
$nineVerdict = $mintVerdict(
    $nine['scope'], $nine['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M10_INTERNAL_RELEASE_RECORDING',
    null, $otherScope
);
$nineReleased = $ledger->appendInternalReleaseDecisionRecord($nineVerdict);
Assert::true($nineReleased->isRefusal(), 'CONF-M10-009: a cross-scope verdict cannot release');
Assert::same(
    'LEDGER_RELEASE_VERDICT_SCOPE_MISMATCH',
    $nineReleased->reasonCode(),
    'CONF-M10-009: named by the canonical reason code',
);

// ============================================================ CONF-M10-010

Assert::case('CONF-M10-010');
// The proposal must still be the one the verdict was issued against. A revised submission produces
// a new digest, and the old verdict no longer speaks for it.
$ten = $freshChain('conf010');
$tenVerdict = $mintVerdict(
    $ten['scope'], $ten['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M10_INTERNAL_RELEASE_RECORDING',
    str_repeat('a', 64)
);
$tenReleased = $ledger->appendInternalReleaseDecisionRecord($tenVerdict);
Assert::true($tenReleased->isRefusal(), 'CONF-M10-010: a stale submission digest cannot release');
Assert::same(
    'LEDGER_UPSTREAM_DIGEST_MISMATCH',
    $tenReleased->reasonCode(),
    'CONF-M10-010: named by the canonical reason code',
);
Assert::same(
    'SUBMITTED',
    (string) $db->scalar(
        'SELECT status FROM proposal_envelopes WHERE proposal_id = ?', [$ten['chain']['proposal_id']]
    ),
    'CONF-M10-010: and the proposal is untouched',
);

// --- every refusal above wrote NOTHING ----------------------------------------------------------
// A refusal that left a partial record behind would be worse than no check at all: the console
// would read a release record for a release that was refused.
Assert::same(
    2,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_records WHERE record_type = 'INTERNAL_INFORMATIONAL_RELEASE'"
    ),
    'exactly two release records exist across this suite — one per case that was permitted to release',
);
