<?php

declare(strict_types=1);

/**
 * P04 privacy and admission, proved by REVERSION.
 *
 * Every claim below is established the only way a security claim can be: by putting the system in
 * the state the guard exists to refuse, and showing it refuses. A test that only exercised the
 * happy path would prove the feature works, not that the fence holds.
 *
 * The five admission rules are each isolated — one bent input at a time — so a passing suite
 * cannot be one rule covering for another. Then both RELEASE-BOUNDARY re-checks are proved by
 * mutating state AFTER a clean verdict, which is the exact window they exist for: a verdict is
 * about the artifact as it was, and a release is about the artifact as it is.
 *
 * NOTHING IN THIS FILE SENDS ANYTHING. There is no external channel in the plane to test.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Product\P04\DistributionAdmission;
use Allfather\Loopia\Product\P04\Release as P04Release;
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
$proposals = new Proposals($db, $clock);
$evidence = new EvidenceSpine($db, $clock);
$readiness = new Readiness($db, $clock);
$arbiter = new Arbiter($db, $clock, $readiness);
$council = new Council($db, $clock);

$distribution = new Distribution($db, $clock, $ledger);
$admission = new DistributionAdmission(
    $db, $clock, $evidence, $readiness, $proposals, $council, $arbiter, $ledger
);
$release = new P04Release(
    $db, $clock, $ledger, $proposals, new AccessGuard($db, $clock), new Entitlements($db, $clock)
);

$policySetId = (new PolicySets($db, $clock))->seed(
    PolicySets::P04_DISTRIBUTION_ADMISSION, $scope['tenant_id'], $scope['workspace_id']
)->value()['arbiter_policy_set_id'];

$distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'redaction_rules' => ['REDACT_INTERNAL_MARGIN'],
]);

/**
 * Drive one briefing from a fresh released source all the way to a verdict.
 *
 * Each case gets its OWN source chain, so a mutation made for one rule cannot leak into another
 * and make a later assertion pass for a reason it does not name.
 *
 * `$mutate` runs AFTER the submission is frozen and BEFORE the verdict — the window every one of
 * these rules governs.
 */
$runToVerdict = static function (
    array $overrides = [],
    ?callable $mutate = null,
) use ($db, $clock, $scope, $distribution, $admission, $council, $policySetId): array {
    $src = Seed::p01ReleasedBriefing($db, $clock, $scope);
    $composed = $distribution->composeRoleDistributionBriefing([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'target_org_role' => 'SALES',
        'source_kind' => 'P01_BRIEFING',
        'source_id' => $src['released_briefing_id'],
        'briefing_summary_safe' => $overrides['briefing_summary_safe']
            ?? 'Competitor list pricing rose; renewal conversations may reference it.',
    ]);
    $briefingId = $composed->value()['role_distribution_briefing_id'];

    $submitted = $admission->submitRoutingProposal([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'role_distribution_briefing_id' => $briefingId,
        'sensitivity_class' => $overrides['sensitivity_class'] ?? 'ROUTINE',
        'legal_basis_ref' => $overrides['legal_basis_ref'] ?? null,
        'governance' => [
            'mission_id' => $src['mission_id'],
            'producing_valkyrie_id' => $src['producing_valkyrie_id'],
            'capability_contract_id' => $src['capability_contract_id'],
        ],
    ]);

    $review = $admission->openSensitivityReview([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'role_distribution_briefing_id' => $briefingId,
        'initiated_by_actor_type' => 'OPERATOR',
        'initiated_by_actor_id' => $scope['operator_id'],
    ]);
    $sessionId = $review->value()['review_session_id'];
    $assignmentId = $council->assignParticipant(
        $sessionId, $src['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
    )->value()['participant_assignment_id'];
    $council->recordAssessment(
        $sessionId, $assignmentId, $src['critic_valkyrie_id'],
        'SUPPORT_PROCEED_TO_ARBITER', 'Reviewed.'
    );
    $council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');

    if ($mutate !== null) {
        $mutate($briefingId, $src);
    }

    $verdict = $admission->admitRouting([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'role_distribution_briefing_id' => $briefingId,
        'arbiter_policy_set_id' => $policySetId,
    ]);

    return [
        'briefing_id' => $briefingId,
        'proposal_id' => $submitted->isOk() ? $submitted->value()['proposal_id'] : null,
        'source' => $src,
        'verdict' => $verdict,
    ];
};

// ============================================================ the clean baseline

// Everything below bends ONE thing away from this. Without the baseline, a refusal proves only
// that something is wrong, not that the named rule is what caught it.
$clean = $runToVerdict();
Assert::same('ADMITTED', $clean['verdict']->value()['outcome'], 'the unmutated routing is admitted');
Assert::same(
    'ALLOW_INFORMATIONAL_RELEASE',
    $clean['verdict']->value()['verdict'],
    'with an informational-release verdict',
);

// ============================================================ rule 1: the privacy invariant

// A summary carrying a person is not a role-class briefing. Each identifier SHAPE is proved
// separately, because the detector is several patterns and one working does not make the rest work.
$identifiers = [
    'an email address' => 'Renewal contact is anna.berg@rival.example for this account.',
    'an obfuscated email' => 'Renewal contact is anna.berg (at) rival.example for this account.',
    'a social handle' => 'See linkedin.com/in/anna-berg for the renewal contact.',
    'a phone-like run' => 'Renewal contact reachable on +46 70 123 45 67.',
];
foreach ($identifiers as $shape => $summary) {
    $carrying = $runToVerdict(['briefing_summary_safe' => $summary]);
    $value = $carrying['verdict']->value();
    Assert::same('REFUSED', $value['outcome'], "a briefing carrying {$shape} is refused");
    Assert::same('BLOCK', $value['verdict'], 'and BLOCKS — this is a defect upstream, not a draft to revise');
    Assert::true(
        in_array('INDIVIDUAL_IDENTIFIER_FORBIDDEN', $value['reason_codes'], true),
        'named as the privacy violation it is',
    );
    Assert::true($value['briefing_blocked'], 'terminating the briefing');
    Assert::same(
        'BLOCKED',
        (string) $db->scalar(
            'SELECT briefing_status FROM role_distribution_briefings WHERE role_distribution_briefing_id = ?',
            [$carrying['briefing_id']]
        ),
        'and the briefing is closed, not left waiting for a revision nobody asked for',
    );
    // The incident flag is the difference between "refused" and "somebody should look at this".
    Assert::same(
        1,
        (int) $db->scalar(
            'SELECT incident_evaluation_required FROM arbiter_verdicts WHERE arbiter_verdict_id = ?',
            [$value['arbiter_verdict_id']]
        ),
        'opening an incident: an identifier reaching the arbiter means a guard upstream leaked',
    );
}

// An ordinary business summary carrying a DATE is not an identifier. The detector must be usable,
// and a rule that fired on every timestamp would be turned off within a week.
$dated = $runToVerdict([
    'briefing_summary_safe' => 'Pricing page changed on 2026-06-07 14:30:00; list price rose 11%.',
]);
Assert::same(
    'ADMITTED',
    $dated['verdict']->value()['outcome'],
    'a date is not a phone number — the detector does not fire on ordinary business text',
);

// ============================================================ rule 2: the unauthorized role

// The role WAS configured at composition. Removing the configuration between the submission and
// the verdict is the real failure mode: a recipient that was authorized and no longer is.
$deconfigured = $runToVerdict([], static function (string $briefingId) use ($db, $scope): void {
    $db->run(
        'DELETE FROM console_redaction_policy_projections
         WHERE tenant_id = ? AND workspace_id = ? AND target_org_role = ?',
        [$scope['tenant_id'], $scope['workspace_id'], 'SALES']
    );
});
$deconfiguredValue = $deconfigured['verdict']->value();
Assert::same('REFUSED', $deconfiguredValue['outcome'], 'a routing to a de-configured role is refused');
Assert::true(
    in_array('UNAUTHORIZED_ROLE', $deconfiguredValue['reason_codes'], true),
    'named as an unauthorized role — an unconfigured recipient is not a recipient with an empty policy',
);
Assert::same('BLOCK', $deconfiguredValue['verdict'], 'and BLOCKS');

// Restore it for the cases below.
$distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'redaction_rules' => ['REDACT_INTERNAL_MARGIN'],
]);

// ============================================================ rules 3 and 4: the SENSITIVE path

$noBasis = $runToVerdict(['sensitivity_class' => 'SENSITIVE']);
$noBasisValue = $noBasis['verdict']->value();
Assert::same('REFUSED', $noBasisValue['outcome'], 'a SENSITIVE routing with no legal basis is refused');
Assert::true(
    in_array('NO_LEGAL_BASIS_FOR_SENSITIVE', $noBasisValue['reason_codes'], true),
    'naming the missing basis',
);
Assert::same('BLOCK', $noBasisValue['verdict'], 'and BLOCKS — a basis is not something to supply later');

// The same routing WITH a recorded basis is admitted. This is what makes the rule a gate rather
// than a prohibition on the sensitive path altogether.
$withBasis = $runToVerdict([
    'sensitivity_class' => 'SENSITIVE',
    'legal_basis_ref' => 'legal-basis://dpia-2026-04',
]);
Assert::same(
    'ADMITTED',
    $withBasis['verdict']->value()['outcome'],
    'a SENSITIVE routing citing a recorded basis is admitted',
);

// An EMPTY redaction profile on a sensitive routing HOLDs. Different from the basis rule on
// purpose: configuring redaction is a repair an operator can make, and the verdict says so by
// leaving the briefing where it was.
$distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'redaction_rules' => [],
]);
$unredacted = $runToVerdict([
    'sensitivity_class' => 'SENSITIVE',
    'legal_basis_ref' => 'legal-basis://dpia-2026-04',
]);
$unredactedValue = $unredacted['verdict']->value();
Assert::same('REFUSED', $unredactedValue['outcome'], 'a SENSITIVE routing with an empty profile is refused');
Assert::true(
    in_array('REDACTION_REQUIRED', $unredactedValue['reason_codes'], true),
    'naming the missing redaction',
);
Assert::same('HOLD', $unredactedValue['verdict'], 'and HOLDS rather than blocking — this is repairable');
Assert::false($unredactedValue['briefing_blocked'], 'so the briefing is not terminated');
Assert::same(
    'PENDING_VERDICT',
    (string) $db->scalar(
        'SELECT briefing_status FROM role_distribution_briefings WHERE role_distribution_briefing_id = ?',
        [$unredacted['briefing_id']]
    ),
    'it stays exactly where it was, awaiting the repair the verdict named',
);

// A ROUTINE routing with the same empty profile is fine: the rule governs sensitive routings.
$routineUnredacted = $runToVerdict();
Assert::same(
    'ADMITTED',
    $routineUnredacted['verdict']->value()['outcome'],
    'a ROUTINE routing needs no redaction profile — the rule is about sensitivity, not paperwork',
);

$distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'redaction_rules' => ['REDACT_INTERNAL_MARGIN'],
]);

// ============================================================ rule 5: the source must still hold

// Withdrawn between the submission and the verdict. The composition check already passed; that is
// the point — each check governs its own moment, and none substitutes for another.
$withdrawn = $runToVerdict([], static function (string $briefingId, array $src) use ($ledger): void {
    $ledger->supersede(
        $src['decision_record_id'],
        'INVALIDATED_FOR_CURRENT_RELIANCE',
        'M11',
        $src['released_briefing_id'],
        'Source withdrawn between submission and verdict.',
        'INVALIDATED_FOR_CURRENT_RELIANCE',
    );
});
$withdrawnValue = $withdrawn['verdict']->value();
Assert::same('REFUSED', $withdrawnValue['outcome'], 'a routing whose source was withdrawn is refused');
Assert::true(
    in_array('SOURCE_NOT_RELEASED', $withdrawnValue['reason_codes'], true),
    'naming the withdrawn release',
);

// ============================================================ the release-boundary re-checks

// A CLEAN verdict, then the artifact is edited. This is the window the boundary checks exist for:
// the verdict was about the briefing as it was, and the release is about the briefing as it is.
$edited = $runToVerdict();
Assert::same('ADMITTED', $edited['verdict']->value()['outcome'], 'the routing is admitted cleanly');
$db->run(
    'UPDATE role_distribution_briefings SET briefing_summary_safe = ?
     WHERE role_distribution_briefing_id = ?',
    ['Renewal contact is anna.berg@rival.example.', $edited['briefing_id']]
);
Assert::same(
    'INDIVIDUAL_IDENTIFIER_AT_RELEASE',
    $release->releaseRoleDistribution([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'role_distribution_briefing_id' => $edited['briefing_id'],
    ])->reasonCode(),
    'a summary edited after its verdict is re-scanned at the release boundary',
);
Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM released_distribution_outputs WHERE role_distribution_briefing_id = ?',
        [$edited['briefing_id']]
    ),
    'and NOTHING was released',
);

// The same for the source: admitted cleanly, then the ledger withdraws the claim.
$retracted = $runToVerdict();
Assert::same('ADMITTED', $retracted['verdict']->value()['outcome'], 'the routing is admitted cleanly');
$ledger->supersede(
    $retracted['source']['decision_record_id'],
    'INVALIDATED_FOR_CURRENT_RELIANCE',
    'M11',
    $retracted['source']['released_briefing_id'],
    'Source withdrawn between verdict and release.',
    'INVALIDATED_FOR_CURRENT_RELIANCE',
);
Assert::same(
    'SOURCE_RELEASE_INVALIDATED_AT_RELEASE',
    $release->releaseRoleDistribution([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'role_distribution_briefing_id' => $retracted['briefing_id'],
    ])->reasonCode(),
    'a source withdrawn after the verdict stops the release',
);
Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM released_distribution_outputs WHERE role_distribution_briefing_id = ?',
        [$retracted['briefing_id']]
    ),
    'and again NOTHING was released',
);

// ============================================================ scope

// A foreign workspace cannot reach another's briefing, at any step. Proved on all three entry
// points, because a single guarded door in a wall with three doors is not a wall.
$otherScope = Seed::tenantWorkspace($db, $clock, 'rival');
$foreign = $runToVerdict();
$foreignInput = [
    'tenant_id' => $otherScope['tenant_id'],
    'workspace_id' => $otherScope['workspace_id'],
    'role_distribution_briefing_id' => $foreign['briefing_id'],
];
Assert::same(
    'BRIEFING_SCOPE_MISMATCH',
    $admission->submitRoutingProposal($foreignInput + ['governance' => [
        'mission_id' => Ids::new(),
        'producing_valkyrie_id' => Ids::new(),
        'capability_contract_id' => Ids::new(),
    ]])->reasonCode(),
    'a foreign scope cannot submit another tenant\'s briefing',
);
Assert::same(
    'BRIEFING_SCOPE_MISMATCH',
    $admission->admitRouting($foreignInput + ['arbiter_policy_set_id' => $policySetId])->reasonCode(),
    'nor evaluate it',
);
Assert::same(
    'BRIEFING_SCOPE_MISMATCH',
    $release->releaseRoleDistribution($foreignInput)->reasonCode(),
    'nor release it',
);
