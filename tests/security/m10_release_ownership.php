<?php

declare(strict_types=1);

/**
 * M10 owns release — proved by reversion, not by assertion.
 *
 * The claim under test is INV-012: `appendInternalReleaseDecisionRecord` is the one place a
 * proposal reaches RELEASED, and the one place an `INTERNAL_INFORMATIONAL_RELEASE` record can be
 * minted. That claim was false in this port until recently in three separate ways, and each of the
 * three is now pinned here:
 *
 *  - the release path validated NOTHING (it took a caller-assembled basis and appended it);
 *  - M04 performed the RELEASED mutation, in a different transaction from the decision;
 *  - the capability guarding the record type had a public static factory, so any importing module
 *    could mint one — the comment claimed a strength the code did not have.
 *
 * A-H prove each refusal writes nothing and moves no ledger head. I-K prove the ownership is
 * structural rather than conventional. L proves the unit is atomic. M proves the retry path cannot
 * mint a second release. N scans the repository, because a rule that holds in the files this suite
 * happens to import is not a rule.
 */

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$repoRoot = dirname(__DIR__, 2);

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$ledger = new DecisionLedger($db, $clock);
$arbiter = new Arbiter($db, $clock, new Readiness($db, $clock));

/** Everything a refusal must leave exactly as it found it. */
$ledgerState = static fn (): array => [
    'release_records' => (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_records WHERE record_type = 'INTERNAL_INFORMATIONAL_RELEASE'"
    ),
    'all_records' => (int) $db->scalar('SELECT COUNT(*) FROM decision_records'),
    'references' => (int) $db->scalar('SELECT COUNT(*) FROM decision_input_references'),
    'heads' => (int) ($db->scalar('SELECT COALESCE(SUM(head_sequence_number), 0) FROM decision_ledger_streams') ?? 0),
    'released_proposals' => (int) $db->scalar(
        "SELECT COUNT(*) FROM proposal_envelopes WHERE status = 'RELEASED'"
    ),
    'transitions' => (int) $db->scalar(
        "SELECT COUNT(*) FROM proposal_status_transitions WHERE to_status = 'RELEASED'"
    ),
];

$freshChain = static function (string $key) use ($db, $clock): array {
    $scope = Seed::tenantWorkspace($db, $clock, $key);

    return [
        'scope' => $scope,
        'chain' => Seed::governedReleaseChain(
            $db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'BATTLECARD_BRIEF', true
        ),
    ];
};

/** A real M08 verdict row with the fields under test. See the conformance suite for the rationale. */
$mintVerdict = static function (
    array $scope,
    array $chain,
    string $verdict,
    string $stage,
    ?string $digest = null,
    ?array $scopeOverride = null,
) use ($db, $clock): string {
    $proposal = $db->one(
        'SELECT mission_id, submission_digest, proposal_type, requested_action_class
         FROM proposal_envelopes WHERE proposal_id = ?',
        [$chain['proposal_id']]
    );
    $submissionDigest = $digest ?? (string) $proposal['submission_digest'];
    $tenantId = $scopeOverride['tenant_id'] ?? $scope['tenant_id'];
    $workspaceId = $scopeOverride['workspace_id'] ?? $scope['workspace_id'];
    $now = $clock->now();
    $runId = Ids::new();
    $verdictId = Ids::new();

    $db->insert('arbiter_evaluation_runs', [
        'arbiter_evaluation_run_id' => $runId, 'schema_version' => '1',
        'tenant_id' => $tenantId, 'workspace_id' => $workspaceId,
        'mission_id' => (string) $proposal['mission_id'], 'proposal_id' => $chain['proposal_id'],
        'proposal_submission_digest' => $submissionDigest,
        'requested_proposal_type' => (string) $proposal['proposal_type'],
        'requested_action_class' => (string) $proposal['requested_action_class'],
        'evidence_bundle_id' => $chain['evidence_bundle_id'], 'evidence_bundle_hash' => null,
        'hmwh_result_id' => null, 'arbiter_policy_set_id' => $chain['arbiter_policy_set_id'],
        'policy_version' => '1', 'correlation_id' => null, 'status' => 'VERDICT_ISSUED',
        'requested_at' => $now,
    ]);
    $db->insert('arbiter_verdicts', [
        'arbiter_verdict_id' => $verdictId, 'schema_version' => '1',
        'arbiter_evaluation_run_id' => $runId, 'tenant_id' => $tenantId,
        'workspace_id' => $workspaceId, 'mission_id' => (string) $proposal['mission_id'],
        'proposal_id' => $chain['proposal_id'], 'proposal_submission_digest' => $submissionDigest,
        'evidence_bundle_id' => $chain['evidence_bundle_id'], 'evidence_bundle_hash' => null,
        'hmwh_result_id' => null, 'arbiter_policy_set_id' => $chain['arbiter_policy_set_id'],
        'policy_version' => '1', 'verdict' => $verdict, 'permitted_next_stage' => $stage,
        'admissibility_scope' => null, 'passed_check_ids' => '[]',
        'failed_or_holding_check_ids' => '[]', 'condition_ids' => '[]', 'reason_codes' => '[]',
        'safe_rationale_summary' => 'Fixture verdict for the M10 ownership mutation suite.',
        'uncertainty_and_limitation_summary' => null, 'severity' => null,
        'incident_evaluation_required' => 0, 'not_human_approval_or_execution_authority' => 1,
        'basis_hash' => Digest::canonicalDigest(['fixture' => $verdictId]),
        'hash_algorithm' => 'sha256', 'issued_at' => $now,
    ]);

    return $verdictId;
};

/** Every refusal class, with the state it must not disturb. */
$refusalCases = [
    'A' => ['a verdict that does not exist', static fn (): string => Ids::new()],
    'B' => ['a non-ALLOW verdict', static function () use ($freshChain, $mintVerdict): string {
        $c = $freshChain('mut-b');

        return $mintVerdict($c['scope'], $c['chain'], 'HOLD', 'NO_PROGRESSION');
    }],
    'C' => ['an ALLOW routed away from M10', static function () use ($freshChain, $mintVerdict): string {
        $c = $freshChain('mut-c');

        return $mintVerdict($c['scope'], $c['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M12_PROVISIONAL_DISPLAY');
    }],
    'D' => ['a verdict M08 invalidated', static function () use ($db, $clock, $freshChain, $mintVerdict): string {
        $c = $freshChain('mut-d');
        $id = $mintVerdict($c['scope'], $c['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M10_INTERNAL_RELEASE_RECORDING');
        $db->insert('arbiter_invalidations', [
            'arbiter_invalidation_id' => Ids::new(), 'schema_version' => '1',
            'affected_object_type' => 'VERDICT', 'affected_object_id' => $id,
            'reason_code' => 'UPSTREAM_EVIDENCE_REVOKED', 'reason_summary' => 'Revoked for use.',
            'trigger_record_refs' => '[]', 'blocks_pending_progression' => 1, 'severity' => 'S3',
            'incident_evaluation_required' => 0, 'created_at' => $clock->now(),
        ]);

        return $id;
    }],
    'E' => ['a condition still open', static function () use ($db, $clock, $freshChain, $mintVerdict): string {
        $c = $freshChain('mut-e');
        $id = $mintVerdict($c['scope'], $c['chain'], 'ALLOW_WITH_CONDITIONS', 'M10_INTERNAL_RELEASE_RECORDING');
        $db->insert('arbiter_conditions', [
            'arbiter_condition_id' => Ids::new(), 'schema_version' => '1',
            'arbiter_evaluation_run_id' => (string) $db->scalar(
                'SELECT arbiter_evaluation_run_id FROM arbiter_verdicts WHERE arbiter_verdict_id = ?', [$id]
            ),
            'arbiter_verdict_id' => $id, 'condition_type' => 'UNCERTAINTY_DISCLOSURE',
            'condition_summary' => 'Unobserved applicability must be disclosed.',
            'satisfaction_owner_module_id' => 'M04', 'status' => 'OPEN',
            'satisfaction_reference_id' => null, 'created_at' => $clock->now(),
        ]);

        return $id;
    }],
    'F' => ['a proposal that is not SUBMITTED', static function () use ($db, $freshChain, $mintVerdict): string {
        $c = $freshChain('mut-f');
        // WITHDRAWN, not RELEASED: this case is about the SUBMITTED precondition, and reaching it
        // through a real release would also change the record counts the assertion below pins.
        $db->run(
            "UPDATE proposal_envelopes SET status = 'WITHDRAWN' WHERE proposal_id = ?",
            [$c['chain']['proposal_id']]
        );

        return $mintVerdict($c['scope'], $c['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M10_INTERNAL_RELEASE_RECORDING');
    }],
    'G' => ['a verdict from another scope', static function () use ($db, $clock, $freshChain, $mintVerdict): string {
        $c = $freshChain('mut-g');
        $other = Seed::tenantWorkspace($db, $clock, 'mut-g-other');

        return $mintVerdict(
            $c['scope'], $c['chain'], 'ALLOW_INFORMATIONAL_RELEASE',
            'M10_INTERNAL_RELEASE_RECORDING', null, $other
        );
    }],
    'H' => ['a submission digest that has moved on', static function () use ($freshChain, $mintVerdict): string {
        $c = $freshChain('mut-h');

        return $mintVerdict(
            $c['scope'], $c['chain'], 'ALLOW_INFORMATIONAL_RELEASE',
            'M10_INTERNAL_RELEASE_RECORDING', str_repeat('b', 64)
        );
    }],
];

foreach ($refusalCases as $letter => [$description, $build]) {
    Assert::case($letter);
    $verdictId = $build();
    $before = $ledgerState();
    $refused = $ledger->appendInternalReleaseDecisionRecord($verdictId);
    Assert::true($refused->isRefusal(), "{$description} is refused");
    Assert::same(
        $before,
        $ledgerState(),
        'and the refusal writes NOTHING — no record, no reference, no head advance, no status change',
    );
}

// ================================================================ I

Assert::case('I');
// The record type is unreachable from outside M10. Not "guarded by a token that is hard to obtain"
// — there is no token. The previous design had a `ReleasePathCapability` with a public static
// factory, so the guard was satisfiable by anything that could type its name.
$anyWorkspace = (array) $db->one(
    'SELECT tenant_id, workspace_id, environment_class FROM workspaces ORDER BY workspace_id LIMIT 1'
);
$stream = $ledger->ensureActiveStream(
    (string) $anyWorkspace['tenant_id'],
    (string) $anyWorkspace['workspace_id'],
    (string) $anyWorkspace['environment_class'],
);
$forged = $ledger->append(
    $stream, 'INTERNAL_INFORMATIONAL_RELEASE', 'GOVERNANCE_MATERIAL', 'RELEASED_INTERNAL', 'forged',
    [['owner_module_id' => 'M08', 'input_object_type' => 'arbiter_verdict',
      'input_object_id' => Ids::new(), 'reference_role' => 'VERDICT_BASIS',
      'verification_status_at_record_time' => 'VERIFIED_REFERENCE'],
     ['owner_module_id' => 'M04', 'input_object_type' => 'proposal_envelope',
      'input_object_id' => Ids::new(), 'reference_role' => 'PROPOSAL_BASIS',
      'verification_status_at_record_time' => 'VERIFIED_REFERENCE']],
    'service:forger'
);
Assert::same(
    'LEDGER_RELEASE_RECORD_REQUIRES_RELEASE_PATH',
    $forged->reasonCode(),
    'the public append refuses the release record type',
);
Assert::equalsList(
    ['ledgerStreamId', 'recordType', 'materialityClass', 'primaryOutcomeStatus',
     'safeDecisionSummary', 'inputReferences', 'recordedByServiceIdentityId', 'fields'],
    array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(DecisionLedger::class, 'append'))->getParameters()
    ),
    // `recordedByServiceIdentityId` is a REQUIRED provenance parameter, not an override: it names
    // who wrote the record and unlocks nothing. The forged call below supplies one and is still
    // refused by record type.
    'and takes no capability, token or override parameter that could unlock it',
);
Assert::false(
    file_exists($repoRoot . '/app/Substrate/M10/ReleasePathCapability.php'),
    'the forgeable capability class is gone, not merely unused',
);
Assert::false(
    (new ReflectionMethod(DecisionLedger::class, 'appendRecord'))->isPublic(),
    'and the unrestricted append is private to M10',
);

// ================================================================ J

Assert::case('J');
// M04 has no method that reaches RELEASED. Reflection over the public surface, not a reading of
// the code: a private helper reachable from a public method would still be M04 releasing.
$m04Methods = array_map(
    static fn (ReflectionMethod $m): string => $m->getName(),
    (new ReflectionClass(Proposals::class))->getMethods(ReflectionMethod::IS_PUBLIC)
);
Assert::false(in_array('markReleased', $m04Methods, true), 'M04 exposes no markReleased');
Assert::same(
    [],
    array_values(array_filter($m04Methods, static fn (string $n): bool => stripos($n, 'release') !== false)),
    'and no other M04 method names release at all',
);
$m04Source = (string) file_get_contents($repoRoot . '/app/Substrate/M04/Proposals.php');
Assert::same(
    0,
    preg_match('/UPDATE\s+proposal_envelopes[^;]*RELEASED/is', $m04Source),
    'and the module contains no statement that writes the RELEASED status',
);

// ================================================================ K

Assert::case('K');
// The release path accepts a release IDENTITY and nothing else. A caller that can name its own
// basis can name a fabricated one, and the record the console treats as PROOF of release would
// then prove only that a caller asserted something.
$releaseSignature = new ReflectionMethod(DecisionLedger::class, 'appendInternalReleaseDecisionRecord');
Assert::equalsList(
    ['arbiterVerdictId', 'options'],
    array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        $releaseSignature->getParameters()
    ),
    'the release path takes a verdict identity and options — never a basis',
);

$kChain = $freshChain('mut-k');
$kVerdict = $arbiter->evaluateProposal(
    $kChain['chain']['proposal_id'], $kChain['chain']['arbiter_policy_set_id']
);
$kVerdictId = (string) $kVerdict->value()['arbiter_verdict_id'];
$kReleased = $ledger->appendInternalReleaseDecisionRecord($kVerdictId);
$kRecordId = (string) $kReleased->value()['decision_record_id'];
$kRefs = $db->all(
    'SELECT reference_role, input_object_id, verification_status_at_record_time
     FROM decision_input_references WHERE decision_record_id = ? ORDER BY reference_role',
    [$kRecordId]
);
$kByRole = array_column($kRefs, 'input_object_id', 'reference_role');
Assert::same(
    $kVerdictId,
    (string) $kByRole['VERDICT_BASIS'],
    'and the basis it builds names the verdict M10 actually read',
);
Assert::same(
    (string) $kChain['chain']['proposal_id'],
    (string) $kByRole['PROPOSAL_BASIS'],
    'and the proposal that verdict names — not one a caller chose',
);
Assert::same(
    [],
    array_values(array_filter(
        $kRefs,
        static fn (array $r): bool => (string) $r['verification_status_at_record_time'] !== 'VERIFIED_REFERENCE'
    )),
    'every reference is VERIFIED_REFERENCE because M10 verified it, not because a caller said so',
);

// ================================================================ L

Assert::case('L');
// Force a failure AFTER the record is appended and AT the proposal mutation. The whole M10 unit
// must roll back: a durable release record against a still-SUBMITTED proposal is the split state
// the single transaction exists to prevent, and a retry over it would mint a SECOND release.
//
// The failure is injected the way an operator with database privileges would — a trigger — and
// the trigger is dropped again immediately, with the drop verified, so this suite cannot leave the
// schema different for the suites that follow it.
$lChain = $freshChain('mut-l');
$lVerdictId = (string) $arbiter
    ->evaluateProposal($lChain['chain']['proposal_id'], $lChain['chain']['arbiter_policy_set_id'])
    ->value()['arbiter_verdict_id'];
$lBefore = $ledgerState();

$db->pdo()->exec(
    "CREATE TRIGGER trg_test_block_release_flip BEFORE UPDATE ON proposal_envelopes
     FOR EACH ROW BEGIN
       IF NEW.status = 'RELEASED' THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure at the release flip';
       END IF;
     END"
);
try {
    $ledger->appendInternalReleaseDecisionRecord($lVerdictId);
    Assert::fail('the injected failure should propagate rather than being swallowed');
} catch (\PDOException) {
    Assert::pass();
} finally {
    $db->pdo()->exec('DROP TRIGGER IF EXISTS trg_test_block_release_flip');
}
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_test_block_release_flip'"
    ),
    'the injected trigger is gone — this suite leaves the schema as it found it',
);
Assert::same(
    $lBefore,
    $ledgerState(),
    'and the whole M10 unit rolled back: no record, no reference, no head advance, no transition',
);

// The same verdict still releases cleanly once the injected failure is removed. Without this, L
// would be satisfied by an implementation that merely broke.
$lRetry = $ledger->appendInternalReleaseDecisionRecord($lVerdictId);
Assert::true($lRetry->isOk(), 'and the release succeeds on a clean retry — nothing was consumed');

// The OTHER failure at the same point: the guarded flip matching zero rows. The status is read
// before the transaction opens, so a concurrent releaser can move the proposal in between; the
// UPDATE is guarded on the still-SUBMITTED row precisely so the loser of that race advances no
// second time. That path is unreachable from a single-threaded test by timing, so the racer is
// reproduced deterministically: a BEFORE INSERT trigger on `decision_records` moves the proposal
// out of SUBMITTED inside M10's own transaction, after the pre-check and before the flip.
//
// Without this case the `changes !== 1` branch has no test at all, and an implementation that
// RETURNED a refusal there instead of rolling back — committing the release record it had just
// appended — would pass every other assertion in this file.
$l2Chain = $freshChain('mut-l2');
$l2VerdictId = (string) $arbiter
    ->evaluateProposal($l2Chain['chain']['proposal_id'], $l2Chain['chain']['arbiter_policy_set_id'])
    ->value()['arbiter_verdict_id'];
$l2Before = $ledgerState();

$db->pdo()->exec(
    "CREATE TRIGGER trg_test_race_the_release BEFORE INSERT ON decision_records
     FOR EACH ROW BEGIN
       IF NEW.record_type = 'INTERNAL_INFORMATIONAL_RELEASE' AND NEW.proposal_id IS NOT NULL THEN
         UPDATE proposal_envelopes SET status = 'SUPERSEDED'
           WHERE proposal_id = NEW.proposal_id AND status = 'SUBMITTED';
       END IF;
     END"
);
try {
    $raced = $ledger->appendInternalReleaseDecisionRecord($l2VerdictId);
} finally {
    $db->pdo()->exec('DROP TRIGGER IF EXISTS trg_test_race_the_release');
}
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema = DATABASE() AND trigger_name = 'trg_test_race_the_release'"
    ),
    'the racing trigger is gone — this suite leaves the schema as it found it',
);
Assert::true($raced->isRefusal(), 'a guarded flip that matches no row refuses');
Assert::same(
    'LEDGER_PROPOSAL_NOT_RELEASABLE',
    $raced->reasonCode(),
    'named by the canonical reason code',
);
Assert::same(
    $l2Before,
    $ledgerState(),
    'and the ALREADY-APPENDED record rolls back with it — a returned refusal here would commit it',
);
Assert::same(
    'SUBMITTED',
    (string) $db->scalar(
        'SELECT status FROM proposal_envelopes WHERE proposal_id = ?', [$l2Chain['chain']['proposal_id']]
    ),
    "and the racer's own write rolled back too, because it shared the unit",
);

// ================================================================ M

Assert::case('M');
// The product projection commits in its OWN transaction after M10's, which leaves a window: M10
// can commit and the product half can then fail. The retry must be able to finish, and must not
// mint a second release for one decision.
$mChain = $freshChain('mut-m');
$mVerdictId = (string) $arbiter
    ->evaluateProposal($mChain['chain']['proposal_id'], $mChain['chain']['arbiter_policy_set_id'])
    ->value()['arbiter_verdict_id'];
$mFirst = $ledger->releaseOrResume($mVerdictId);
Assert::same('RELEASED', $mFirst->value()['outcome'], 'the first attempt releases');

$mAfterFirst = $ledgerState();
$mSecond = $ledger->releaseOrResume($mVerdictId);
Assert::same(
    'RESUMED',
    $mSecond->value()['outcome'],
    'the retry RESUMES rather than failing merely because the proposal is already RELEASED',
);
Assert::same(
    $mFirst->value()['decision_record_id'],
    $mSecond->value()['decision_record_id'],
    'and it returns the ORIGINAL decision record — the decision was already made',
);
Assert::same(
    $mAfterFirst,
    $ledgerState(),
    'minting nothing: no second record, no reference, no head advance, no second transition',
);

// A resume is not a way around the gates. A DIFFERENT verdict on the same, already-released
// proposal has no release record of its own, so it is refused rather than inheriting one.
$mOtherVerdict = $mintVerdict(
    $mChain['scope'], $mChain['chain'], 'ALLOW_INFORMATIONAL_RELEASE', 'M10_INTERNAL_RELEASE_RECORDING'
);
$mOther = $ledger->releaseOrResume($mOtherVerdict);
Assert::true($mOther->isRefusal(), 'a different verdict cannot resume a release it did not cause');
Assert::same('LEDGER_PROPOSAL_NOT_RELEASABLE', $mOther->reasonCode(), 'refused by name');

// ================================================================ N

Assert::case('N');
// A rule that holds in the files this suite happens to import is not a rule. Scan every runtime
// PHP file for a statement that writes the RELEASED status onto a proposal envelope.
$runtimeFiles = [];
foreach (['app', 'worker', 'ops', 'public_html'] as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot . '/' . $dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $runtimeFiles[] = $file->getPathname();
        }
    }
}
Assert::true(count($runtimeFiles) > 100, 'the scan actually walks the runtime tree');

$pattern = '/UPDATE\s+proposal_envelopes\b[^;\'"]*?SET[^;]*?RELEASED/is';
// Positive control: the scan must be capable of finding the thing it claims not to find. A regex
// that matches nothing would pass this test on an empty repository.
Assert::same(
    1,
    preg_match($pattern, "UPDATE proposal_envelopes SET status = 'RELEASED' WHERE proposal_id = ?"),
    'the scan pattern DOES match a proposal release mutation',
);

$offenders = [];
foreach ($runtimeFiles as $path) {
    if (preg_match($pattern, (string) file_get_contents($path)) === 1) {
        $offenders[] = str_replace($repoRoot . '/', '', $path);
    }
}
Assert::equalsList(
    ['app/Substrate/M10/DecisionLedger.php'],
    $offenders,
    'exactly ONE runtime file mutates a proposal to RELEASED, and it is the M10 ledger',
);

// The same for the vocabulary the removed M04 method used, so a reintroduction under the old name
// fails here rather than passing review.
$callers = [];
foreach ($runtimeFiles as $path) {
    if (str_contains((string) file_get_contents($path), 'markReleased(')) {
        $callers[] = str_replace($repoRoot . '/', '', $path);
    }
}
Assert::same([], $callers, 'and no runtime file calls markReleased — the method no longer exists');
