<?php

declare(strict_types=1);

/**
 * P01 surfacing: what a released briefing looks like to a caller, and to whom.
 *
 * VISIBILITY IS A DEFAULT-DENY COMPOSITION, and this file fails each gate on its own:
 *
 *   1. M02 must grant the read permission — an ACCESS question.
 *   2. The tenant's tier must include it — a COMMERCIAL question.
 *   3. The release must still be LIVE — activated, and still relied upon.
 *
 * The two questions in gates 1 and 2 are deliberately separate. Collapsing them would let a billing
 * change silently become an authority change, or a role grant silently become a purchase.
 *
 * The surface renders from the FROZEN M16 artifact rather than a live join, which is what makes
 * the rollback lever real: withdrawing reliance or deactivating visibility removes the briefing
 * without editing anything.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Battlecards;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\Registration;
use Allfather\Loopia\Product\P01\Release as P01Release;
use Allfather\Loopia\Product\P01\Surfacing;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
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
$access = new AccessGuard($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$m16 = new ReleaseOutput($db, $clock, $ledger);
$entitlements = new Entitlements($db, $clock);
$surfacing = new Surfacing($db, $access, $ledger, $entitlements);

// ============================================================ registration provisions the capability

$registration = new Registration(
    $db, $clock, new M03Registry($db, $clock), new Eligibility($db, $clock),
    new ToolRegistry($db, $clock), new PolicySets($db, $clock), $m16, $entitlements
);
$registered = $registration->registerCompetitionMonitor([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'operator_id' => $scope['operator_id'],
]);
Assert::true($registered->isOk(), 'the competition-monitoring capability registers');

// The visibility profiles are PROVISIONED by registration, and their shape is the whole point: the
// internal audiences are permitted, and every wider one is present and NOT permitted. Absent would
// have been ambiguous; explicitly-not-permitted is a recorded fact an auditor can read.
$profiles = $db->all(
    'SELECT audience_class, activation_permitted FROM output_visibility_profiles
     WHERE tenant_id = ? ORDER BY audience_class',
    [$scope['tenant_id']]
);
Assert::true(count($profiles) >= 6, 'every audience class has a profile, not just the permitted ones');
foreach ($profiles as $profile) {
    $expected = in_array((string) $profile['audience_class'], ['OPERATOR_ONLY', 'TENANT_INTERNAL'], true) ? 1 : 0;
    Assert::same(
        $expected,
        (int) $profile['activation_permitted'],
        sprintf('%s activation_permitted is %d', (string) $profile['audience_class'], $expected),
    );
}

// Registration provisions; it cannot GRANT. The storage CHECK makes a permitting wider profile
// unwritable, so no registration path — however written — could widen this.
Assert::throws(
    static fn () => $db->run(
        "UPDATE output_visibility_profiles SET activation_permitted = 1
         WHERE tenant_id = ? AND audience_class = 'CUSTOMER_VISIBLE'",
        [$scope['tenant_id']]
    ),
    \PDOException::class,
    'and a permitting wider profile cannot be written even directly',
);

// The contract's ceiling stops below external effect, enforced by M03 at issuance.
Assert::equalsList(
    ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'],
    json_decode((string) $db->scalar(
        'SELECT allowed_action_classes FROM capability_contracts WHERE capability_contract_id = ?',
        [$registered->value()['capability_contract_id']]
    ), true),
    'the contract stops at RELEASE_INTELLIGENCE — no external-effect class is grantable',
);
Assert::same(
    6,
    (int) $db->scalar('SELECT COUNT(*) FROM forbidden_action_clauses WHERE capability_contract_id = ?', [
        $registered->value()['capability_contract_id'],
    ]),
    'and the bright lines are recorded ON the contract as forbidden-action clauses',
);

// ============================================================ release, then surface

$governed = Seed::governedReleaseChain($db, $clock, $scope, [], 'PROCEED_TO_ARBITER', 'COMPANY_SIGNAL_BRIEF', true);
$signalId = Ids::new();
$db->insert('company_signals', [
    'company_signal_id' => $signalId,
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'competitor_id' => $governed['competitor_id'],
    'source_diff_id' => $governed['source_diff_id'],
    'signal_class' => 'PRICING_CHANGE_SIGNAL',
    'interpretation_kind' => 'FACT_OBSERVED',
    'confidence_band' => 'HIGH',
    'summary_safe' => 'List price rose from 449 to 499 SEK.',
    'proposal_id' => $governed['proposal_id'],
    'status' => 'UNDER_REVIEW',
    'created_at' => $clock->now(),
]);

$battlecardId = (new Battlecards($db, $clock, new Arbiter($db, $clock, new Readiness($db, $clock))))->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $signalId,
    'arbiter_policy_set_id' => $governed['arbiter_policy_set_id'],
    'content' => [
        'why_it_matters_safe' => 'The competitor raised list pricing on its public page.',
        'customer_impact_hypothesis_safe' => 'Existing customers MAY face a renewal increase; not observed.',
        'talk_track_safe' => 'Ask whether the customer has seen a renewal notice.',
        'recommended_response_safe' => 'Reconfirm our own price stability in writing.',
        'rejected_claims_safe' => 'Not supported: that existing contracts were repriced.',
    ],
])->value()['battlecard_brief_id'];

$released = (new P01Release($db, $clock, $ledger, $m16))->releaseBriefing([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'battlecard_brief_id' => $battlecardId,
]);
Assert::true($released->isOk(), 'the briefing releases and freezes an artifact');
$decisionRecordId = $released->value()['decision_record_id'];
$projectionId = $released->value()['customer_output_projection_id'];

$listFor = static fn (string $actorId): array => $surfacing->listVisibleReleasedBriefings([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $actorId,
    'correlation_id' => Ids::new(),
]);

// ============================================================ gate 1: M02 access

// The operator has no role yet. Denied, and the list is EMPTY — never a partial leak.
$noRole = $listFor($scope['operator_id']);
Assert::same('DENIED', $noRole['decision'], 'a caller with no role is denied');
Assert::equalsList([], $noRole['briefings'], 'and sees NOTHING — a denial is not a partial list');
Assert::same('battlecard.read', $noRole['required_permission'], 'the required permission is stated');

// ============================================================ gate 2: the commercial tier

// Grant the ROLE but leave the tenant unsubscribed. Denied on the tier — proving the two gates are
// genuinely separate rather than one check wearing two names.
$role = Seed::role($db, $clock, 'battlecard_reader', ['battlecard.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$noTier = $listFor($scope['operator_id']);
Assert::same('DENIED', $noTier['decision'], 'an authorized caller in an unsubscribed tenant is still denied');
Assert::equalsList(
    ['TIER_DOES_NOT_GRANT_PERMISSION'],
    $noTier['reason_codes'],
    'on the TIER, not on access — a role grant is not a purchase',
);
Assert::equalsList([], $noTier['briefings'], 'and still sees nothing');

// Subscribe the tenant. NOW both gates pass.
$entitlements->subscribe($scope['tenant_id'], $registered->value()['subscription_tier_id']);
$visible = $listFor($scope['operator_id']);
Assert::same('VISIBLE', $visible['decision'], 'with both a role and a subscription, the briefing is visible');
Assert::same(1, count($visible['briefings']), 'and one briefing renders');
Assert::same(
    'List price rose from 449 to 499 SEK.',
    $visible['briefings'][0]['signal_summary_safe'],
    'rendered from the FROZEN projection — what the viewer sees is what was frozen at release',
);
Assert::same('HIGH', $visible['briefings'][0]['confidence_band'], 'carrying its confidence band');

// One live subscription per tenant: a second would make "what has this tenant paid for?" a question
// with two answers, and the gate would take whichever it read first.
Assert::same(
    'TENANT_ALREADY_SUBSCRIBED',
    $entitlements->subscribe($scope['tenant_id'], $registered->value()['subscription_tier_id'])->reasonCode(),
    'a tenant holds at most one live subscription',
);

// A tier cannot mint an authority M02 never heard of.
Assert::same(
    'TIER_PERMISSION_NOT_DEFINED',
    $entitlements->defineTier('invented', 'Invented', 1, ['authority.nobody.defined'])->reasonCode(),
    'a tier cannot grant a permission the platform does not define',
);

// ============================================================ gate 3: the release must still be live

// DEACTIVATE the visibility. The briefing disappears — nothing was edited, and the row still exists.
$m16->deactivateVisibility($projectionId, 'ROLLBACK');
$deactivated = $listFor($scope['operator_id']);
Assert::same('VISIBLE', $deactivated['decision'], 'the caller is still authorized');
Assert::equalsList([], $deactivated['briefings'], 'but a deactivated projection renders nothing');
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM released_briefings WHERE released_briefing_id = ?', [
        $released->value()['released_briefing_id'],
    ]),
    'and the briefing row still EXISTS — withdrawal is not deletion',
);

// Re-activate, and it returns. The lever works in both directions.
$m16->activateInternalVisibility(
    $released->value()['release_artifact_id'], $scope['tenant_id'], $scope['workspace_id']
);
Assert::same(1, count($listFor($scope['operator_id'])['briefings']), 're-activating brings it back');

// WITHDRAW RELIANCE on the release decision. The briefing stops rendering: the product no longer
// asserts it, even though every other gate still passes.
$ledger->supersede($decisionRecordId, 'SUPERSEDED_BY_NEW_DECISION', 'M11', Ids::new(), 'stand-down');
$superseded = $listFor($scope['operator_id']);
Assert::same('VISIBLE', $superseded['decision'], 'the caller is authorized and the projection is active');
Assert::equalsList(
    [],
    $superseded['briefings'],
    'but a release no longer relied upon renders nothing — the product stops asserting it',
);

// ============================================================ nothing wider is reachable

Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM customer_output_projections
         WHERE audience_class <> 'OPERATOR_ONLY' AND audience_class <> 'TENANT_INTERNAL'
           AND status = 'PROJECTED'",
    ),
    'no wider audience obtained a PROJECTED row anywhere in this flow',
);
Assert::same(
    'SURFACED_KIND_NOT_RECOGNIZED',
    $surfacing->listVisibleReleasedBriefings([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'actor_type' => 'OPERATOR',
        'actor_id' => $scope['operator_id'],
        'briefing_kind' => 'SOMETHING_NOBODY_DEFINED',
    ])['reason_codes'][0],
    'an unrecognized briefing kind fails closed rather than defaulting to a permission somebody holds',
);

// Setup converges per workspace while shared registry definitions remain exact and scoped safely.
$again=$registration->registerCompetitionMonitor($scope);
Assert::same($registered->value(),$again->value(),'exact workspace setup replays the same immutable registration');
$secondScope=Seed::tenantWorkspace($db,$clock,'registration-second');
$second=$registration->registerCompetitionMonitor($secondScope);
Assert::true($second->isOk(),'a second tenant/workspace registers without colliding with the global tool');
Assert::same($registered->value()['governed_tool_id'],$second->value()['governed_tool_id'],'compatible read tool is shared');
Assert::notSame($registered->value()['capability_contract_id'],$second->value()['capability_contract_id'],'capability contract stays workspace-scoped');
Assert::same($registered->value()['subscription_tier_id'],$second->value()['subscription_tier_id'],'exact tier definition is reused');
Assert::throws(static fn ()=>$db->run("UPDATE p01_registration_receipts SET input_digest='forged'"),\PDOException::class,'registration receipt is append-only');
$conflict=$registration->registerCompetitionMonitor([...$scope,'runtime_host_tool_ref'=>'changed']);
Assert::same('P01_REGISTRATION_INPUT_CONFLICT',$conflict->reasonCode(),'changed setup input never silently replays');
$runtime=Seed::governedToolChain($db,$clock,$scope);
$db->run('UPDATE runtime_host_bindings SET tenant_id=?,workspace_id=? WHERE binding_id=?',[$scope['tenant_id'],$scope['workspace_id'],$runtime['binding_id']]);
Assert::same('P01_RUNTIME_BINDING_NOT_SHAREABLE',$registration->registerCompetitionMonitor([...$scope,'runtime_binding_id'=>$runtime['binding_id']])->reasonCode(),'a workspace binding cannot become a globally shared tool mapping');
$before=(int)$db->scalar('SELECT COUNT(*) FROM valkyries');
$thirdScope=Seed::tenantWorkspace($db,$clock,'registration-failure');
Assert::throws(static fn ()=>$registration->registerCompetitionMonitor([...$thirdScope,'operator_id'=>\Allfather\Loopia\Core\Ids::new()]),\PDOException::class,'late setup exception is surfaced');
Assert::same($before,(int)$db->scalar('SELECT COUNT(*) FROM valkyries'),'late exception rolls back partial workspace registry rows');
Assert::same(0,(int)$db->scalar('SELECT COUNT(*) FROM p01_registration_receipts WHERE workspace_id=?',[$thirdScope['workspace_id']]),'failed setup writes no receipt');
Assert::true($registration->registerCompetitionMonitor($thirdScope)->isOk(),'corrected setup remains retryable');
// Changed original rights cannot be resurrected by replaying the setup receipt.
foreach ([['status','REVOKED'],['allowed_operations','[]']] as [$column,$value]) {
    $savedRight=$db->one('SELECT * FROM tool_rights WHERE tool_right_id=?',[$second->value()['tool_right_id']]);
    $db->run('UPDATE tool_rights SET '.$column.'=? WHERE tool_right_id=?',[$value,$second->value()['tool_right_id']]);
    Assert::same('P01_REGISTRATION_STATE_CHANGED',$registration->registerCompetitionMonitor($secondScope)->reasonCode(),'changed original right refuses setup replay');
    Assert::same($value,$db->scalar('SELECT '.$column.' FROM tool_rights WHERE tool_right_id=?',[$second->value()['tool_right_id']]),'registration did not repair or reactivate right');
    $db->run('UPDATE tool_rights SET '.$column.'=? WHERE tool_right_id=?',[$savedRight[$column],$second->value()['tool_right_id']]);
}
$db->run("UPDATE governed_tools SET maximum_action_class='ANALYZE' WHERE governed_tool_id=?",[$registered->value()['governed_tool_id']]);
Assert::same('P01_SHARED_TOOL_CONFLICT',$registration->registerCompetitionMonitor($scope)->reasonCode(),'changed global descriptor refuses reuse');
$db->run("UPDATE governed_tools SET maximum_action_class='OBSERVE' WHERE governed_tool_id=?",[$registered->value()['governed_tool_id']]);
$db->run("DELETE FROM subscription_tier_permissions WHERE subscription_tier_id=? AND permission_key='briefing.read'",[$registered->value()['subscription_tier_id']]);
Assert::same('P01_SHARED_TIER_CONFLICT',$registration->registerCompetitionMonitor($scope)->reasonCode(),'changed tier permissions refuse reuse');
