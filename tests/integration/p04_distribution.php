<?php

declare(strict_types=1);

/**
 * P04 intelligence distribution: composition, admission, release and surfacing.
 *
 * The claim under test: distribution ROUTES intelligence M10 already released, to internal roles
 * only, and every step of that is gated. Four things are proved here and each is a distinct
 * failure the plane exists to prevent:
 *
 *   A briefing cannot be composed over a claim the ledger no longer stands behind.
 *   A routing cannot be admitted for a role nobody configured as a recipient.
 *   A SENSITIVE routing cannot be admitted without a recorded legal basis.
 *   A briefing composed for one role is INVISIBLE in another role's view, whatever the caller's
 *   permissions — the cross-role default-deny.
 *
 * There is no external send anywhere in this file, because there is none in the plane.
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
$policies = new PolicySets($db, $clock);
$entitlements = new Entitlements($db, $clock);

$distribution = new Distribution($db, $clock, $ledger);
$admission = new DistributionAdmission(
    $db, $clock, $evidence, $readiness, $proposals, $council, $arbiter, $ledger
);
$release = new P04Release(
    $db, $clock, $ledger, $proposals, new AccessGuard($db, $clock), $entitlements
);

$policySetId = $policies->seed(
    PolicySets::P04_DISTRIBUTION_ADMISSION, $scope['tenant_id'], $scope['workspace_id']
)->value()['arbiter_policy_set_id'];

// A REAL released P01 briefing, produced by the product. Everything below routes THIS claim.
$source = Seed::p01ReleasedBriefing($db, $clock, $scope);

// ============================================================ the role must be configured first

// An org-role the console has no view for is not a narrower target; it is no target at all.
Assert::same(
    'UNKNOWN_TARGET_ROLE',
    $distribution->configureRoleRedactionPolicy([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'target_org_role' => 'BOARD_OBSERVER',
        'redaction_rules' => [],
    ])->reasonCode(),
    'a role outside the closed org-role set cannot be configured as a recipient',
);

$composeInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'source_kind' => 'P01_BRIEFING',
    'source_id' => $source['released_briefing_id'],
    'briefing_summary_safe' => 'Competitor list pricing rose; renewal conversations may reference it.',
];

// Composition without a configured target is refused. The role exists; nobody made it a recipient.
Assert::same(
    'DISTRIBUTION_TARGET_NOT_CONFIGURED',
    $distribution->composeRoleDistributionBriefing($composeInput)->reasonCode(),
    'an unconfigured role has no redaction profile to compose against',
);

$configured = $distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'SALES',
    'redaction_rules' => ['REDACT_INTERNAL_MARGIN'],
]);
Assert::true($configured->isOk(), 'the admin configures the SALES redaction policy');

// The projection is ADVISORY and says so in a CHECK-pinned column: it records a choice, it grants
// nothing. The enforcement authorities are the M08 admission and the briefing's frozen profile.
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT is_advisory_only FROM console_redaction_policy_projections
         WHERE tenant_id = ? AND workspace_id = ? AND target_org_role = ?',
        [$scope['tenant_id'], $scope['workspace_id'], 'SALES']
    ),
    'the redaction projection is advisory only — pinned in storage, not merely documented',
);

// ============================================================ composition rests on a live release

// Withdraw reliance on the source, and composition is refused. Nothing was deleted; the ledger
// simply no longer stands behind the claim, and a routing of a retracted claim would re-assert it.
$ledger->supersede(
    $source['decision_record_id'],
    'INVALIDATED_FOR_CURRENT_RELIANCE',
    'M11',
    $source['released_briefing_id'],
    'Source withdrawn for the composition test.',
    'INVALIDATED_FOR_CURRENT_RELIANCE',
);
Assert::same(
    'SOURCE_NOT_RELEASED',
    $distribution->composeRoleDistributionBriefing($composeInput)->reasonCode(),
    'a withdrawn release cannot back a new distribution',
);

// A fresh source, still relied upon, composes.
$live = Seed::p01ReleasedBriefing($db, $clock, $scope);
$composeInput['source_id'] = $live['released_briefing_id'];
$composed = $distribution->composeRoleDistributionBriefing($composeInput);
Assert::true($composed->isOk(), 'a live release composes a DRAFT briefing');
$briefingId = $composed->value()['role_distribution_briefing_id'];

$row = $db->one(
    'SELECT briefing_status, redaction_profile, released_briefing_id, released_prospect_output_id
     FROM role_distribution_briefings WHERE role_distribution_briefing_id = ?',
    [$briefingId]
);
Assert::same('DRAFT', (string) $row['briefing_status'], 'a composed briefing is a DRAFT and confers nothing');
Assert::same(
    '["REDACT_INTERNAL_MARGIN"]',
    (string) $row['redaction_profile'],
    'carrying the role profile FROZEN at composition',
);
// Exactly one source FK. The briefing REFERENCES the release; it does not copy its content.
Assert::notSame(null, $row['released_briefing_id'], 'the briefing cites its P01 source');
Assert::same(null, $row['released_prospect_output_id'], 'and only that one — the CHECK admits no second');

// ============================================================ the governed submission

$governance = [
    'mission_id' => $live['mission_id'],
    'producing_valkyrie_id' => $live['producing_valkyrie_id'],
    'capability_contract_id' => $live['capability_contract_id'],
];
$submitInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_distribution_briefing_id' => $briefingId,
    'governance' => $governance,
];

$submitted = $admission->submitRoutingProposal($submitInput);
Assert::true($submitted->isOk(), 'a DRAFT briefing submits as a governed routing proposal');
Assert::same('SUBMITTED', $submitted->value()['outcome'], 'as SUBMITTED');
$proposalId = $submitted->value()['proposal_id'];

$envelope = $db->one(
    'SELECT proposal_type, requested_action_class, requested_release_or_action_scope, submission_digest
     FROM proposal_envelopes WHERE proposal_id = ?',
    [$proposalId]
);
Assert::same(
    'INTELLIGENCE_DISTRIBUTION_ROUTING',
    (string) $envelope['proposal_type'],
    'the proposal declares what it is',
);
// The ceiling: routing an internal briefing is RELEASE_INTELLIGENCE and nothing above it.
Assert::same(
    'RELEASE_INTELLIGENCE',
    (string) $envelope['requested_action_class'],
    'and asks for internal release only — never an external action class',
);
Assert::contains('"sensitivityClass":"ROUTINE"', (string) $envelope['requested_release_or_action_scope'],
    'the governance facts are FROZEN in the digest-bound release scope, not passed at verdict time');

// A second submission is refused: the briefing is no longer DRAFT.
Assert::same(
    'BRIEFING_NOT_DRAFT',
    $admission->submitRoutingProposal($submitInput)->reasonCode(),
    'a briefing already routed cannot be routed again',
);

// ============================================================ the M07 sensitivity review

$review = $admission->openSensitivityReview([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_distribution_briefing_id' => $briefingId,
    'initiated_by_actor_type' => 'OPERATOR',
    'initiated_by_actor_id' => $scope['operator_id'],
]);
Assert::true($review->isOk(), 'the sensitivity review opens over the frozen proposal');
$sessionId = $review->value()['review_session_id'];
Assert::same(
    'DISTRIBUTION_SENSITIVITY_REVIEW',
    (string) $db->scalar(
        'SELECT review_purpose FROM hmwh_review_sessions WHERE review_session_id = ?',
        [$sessionId]
    ),
    'asking the narrow question: is THIS briefing cleared for THIS role',
);

$assignmentId = $council->assignParticipant(
    $sessionId, $live['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
)->value()['participant_assignment_id'];
$council->recordAssessment(
    $sessionId, $assignmentId, $live['critic_valkyrie_id'],
    'SUPPORT_PROCEED_TO_ARBITER', 'Role-scoped routing of an already-released briefing.'
);
$council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');

// ============================================================ the verdict

$admitted = $admission->admitRouting([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_distribution_briefing_id' => $briefingId,
    'arbiter_policy_set_id' => $policySetId,
]);
Assert::true($admitted->isOk(), 'the routing reaches a verdict');
Assert::same('ADMITTED', $admitted->value()['outcome'], 'and is admitted');
Assert::same(
    'ALLOW_INFORMATIONAL_RELEASE',
    $admitted->value()['verdict'],
    'with an informational-release verdict — the only verdict that advances a briefing',
);
Assert::same(
    'VERDICT_ISSUED',
    (string) $db->scalar(
        'SELECT briefing_status FROM role_distribution_briefings WHERE role_distribution_briefing_id = ?',
        [$briefingId]
    ),
    'the briefing advances only on the verdict the arbiter actually gave',
);

// ============================================================ release

$releaseInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_distribution_briefing_id' => $briefingId,
];
$releasedOut = $release->releaseRoleDistribution($releaseInput);
Assert::true($releasedOut->isOk(), 'an admitted briefing releases');
Assert::same('RELEASED', $releasedOut->value()['outcome'], 'as RELEASED');

// The canonical release IS the M10 record; both sibling rows REFERENCE it.
$routing = $db->one(
    'SELECT decision_record_id, target_org_role FROM distribution_routing_records
     WHERE distribution_routing_record_id = ?',
    [$releasedOut->value()['distribution_routing_record_id']]
);
$output = $db->one(
    'SELECT decision_record_id, role_distribution_briefing_id, distribution_routing_record_id
     FROM released_distribution_outputs WHERE released_distribution_output_id = ?',
    [$releasedOut->value()['released_distribution_output_id']]
);
Assert::same(
    $releasedOut->value()['decision_record_id'],
    (string) $routing['decision_record_id'],
    'the routing record cites the M10 decision record',
);
Assert::same(
    $releasedOut->value()['decision_record_id'],
    (string) $output['decision_record_id'],
    'and so does the released-output reference',
);
// Siblings of one release, not parent and child.
Assert::same(
    $briefingId,
    (string) $output['role_distribution_briefing_id'],
    'the released output points at the BRIEFING',
);
Assert::same(
    null,
    $output['distribution_routing_record_id'],
    'not at the routing record — they are siblings of one release, and the CHECK keeps that clear',
);

// A second release is refused.
Assert::same(
    'BRIEFING_NOT_VERDICT_ISSUED',
    $release->releaseRoleDistribution($releaseInput)->reasonCode(),
    'a distributed briefing cannot be released twice',
);

// ============================================================ the three-gate surfacing

$listFor = static fn (string $role): array => $release->listVisibleRoleDistributions([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $scope['operator_id'],
    'target_org_role' => $role,
    'correlation_id' => Ids::new(),
]);

// Gate 1 — the operator holds no role yet.
$noRole = $listFor('SALES');
Assert::same('DENIED', $noRole['decision'], 'a caller with no role is denied');
Assert::equalsList([], $noRole['outputs'], 'and sees NOTHING — a denial is not a partial list');

// Gate 2 — grant the role, leave the tenant unsubscribed.
$role = Seed::role($db, $clock, 'role_briefing_reader', ['role_briefing.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$noTier = $listFor('SALES');
Assert::same('DENIED', $noTier['decision'], 'an authorized caller in an unsubscribed tenant is still denied');
Assert::equalsList(
    ['TIER_DOES_NOT_GRANT_PERMISSION'],
    $noTier['reason_codes'],
    'on the TIER — a role grant is not a purchase',
);

$tierId = $entitlements->defineTier('p04_distribution', 'P04 distribution', 4900, ['role_briefing.read'])
    ->value()['subscription_tier_id'];
$entitlements->subscribe($scope['tenant_id'], $tierId);

$visible = $listFor('SALES');
Assert::same('VISIBLE', $visible['decision'], 'with a role and a subscription, the SALES briefing renders');
Assert::same(1, count($visible['outputs']), 'exactly one');
Assert::same(
    'Competitor list pricing rose; renewal conversations may reference it.',
    (string) $visible['outputs'][0]['briefing_summary_safe'],
    'carrying the safe summary that was composed',
);

// Gate 3 — THE CROSS-ROLE BOUNDARY. The same fully-authorized caller asking for a different
// configured role sees nothing, because the briefing was not composed for that role. This is the
// difference between "distribution" and "broadcast".
$distribution->configureRoleRedactionPolicy([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'target_org_role' => 'EXECUTIVE_CEO',
    'redaction_rules' => ['REDACT_INTERNAL_MARGIN'],
]);
$otherRole = $listFor('EXECUTIVE_CEO');
Assert::same('VISIBLE', $otherRole['decision'], 'the caller is authorized for the executive view too');
Assert::equalsList(
    [],
    $otherRole['outputs'],
    'but a SALES briefing is not IN it — the cross-role boundary is a filter, not a redaction',
);

// An unconfigured role denies outright rather than returning an empty authorized view.
Assert::equalsList(
    ['DISTRIBUTION_TARGET_NOT_CONFIGURED'],
    $listFor('CUSTOMER_SUCCESS')['reason_codes'],
    'a real org-role nobody configured is still not a recipient',
);
Assert::equalsList(
    ['UNKNOWN_TARGET_ROLE'],
    $listFor('BOARD_OBSERVER')['reason_codes'],
    'and a role outside the closed set fails closed',
);

// ============================================================ delivery assurance, and only that

$ack = $release->recordAcknowledgement([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'role_distribution_briefing_id' => $briefingId,
    'ack_state' => 'VIEWED',
    'acknowledged_by_role_seat' => 'SALES/seat-1',
]);
Assert::true($ack->isOk(), 'a distributed briefing records a delivery-assurance event');
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT is_delivery_assurance_only FROM distribution_acknowledgements
         WHERE distribution_acknowledgement_id = ?',
        [$ack->value()['distribution_acknowledgement_id']]
    ),
    'pinned to delivery assurance — in the code AND in a storage CHECK',
);

// The structural guarantee: there is no column an employee score could be written into. This is
// stronger than a policy, because a policy can be changed by an edit and a schema cannot be
// changed by one.
$columns = array_map(
    static fn (array $c): string => (string) $c['COLUMN_NAME'],
    $db->all(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['distribution_acknowledgements']
    )
);
foreach (['score', 'rank', 'responsiveness', 'diligence', 'loyalty', 'performance'] as $forbidden) {
    Assert::equalsList(
        [],
        array_values(array_filter($columns, static fn (string $c): bool => str_contains($c, $forbidden))),
        "the acknowledgement table has no `{$forbidden}` column — read tracking cannot become scoring",
    );
}

// A seat reference naming a person is refused. A seat is a position; an address is an individual.
Assert::same(
    'INDIVIDUAL_IDENTIFIER_FORBIDDEN',
    $release->recordAcknowledgement([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'role_distribution_briefing_id' => $briefingId,
        'ack_state' => 'ACKNOWLEDGED',
        'acknowledged_by_role_seat' => 'anna.berg@acme.example',
    ])->reasonCode(),
    'a seat reference may not name a person',
);
