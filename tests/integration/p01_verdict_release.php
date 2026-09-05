<?php

declare(strict_types=1);

/**
 * P01 verdict and release: the last two steps of the vertical.
 *
 * The claim under test: an artifact a salesperson reads exists ONLY where a governed decision
 * admitted it, and a release rests on authority that is still valid AT THE MOMENT OF RELEASE.
 *
 * Both halves matter. The first stops an unadmitted claim reaching a customer through the side
 * door. The second stops a release resting on a verdict that was right when issued and is not
 * any more.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Battlecards;
use Allfather\Loopia\Product\P01\Release as P01Release;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\Readiness;
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
$proposals = new Proposals($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$arbiter = new Arbiter($db, $clock, new Readiness($db, $clock));
$battlecards = new Battlecards($db, $clock, $arbiter);
$release = new P01Release($db, $clock, $ledger, $proposals);

$content = [
    'why_it_matters_safe' => 'The competitor raised list pricing on its public page.',
    'customer_impact_hypothesis_safe' => 'Existing customers MAY face a renewal increase; not observed.',
    'talk_track_safe' => 'Ask whether the customer has seen a renewal notice.',
    'recommended_response_safe' => 'Reconfirm our own price stability in writing.',
    'rejected_claims_safe' => 'Not supported: that existing contracts were repriced.',
];

// ============================================================ incomplete content is not an artifact

// The completeness check runs BEFORE any verdict, so an incomplete battlecard never even consumes
// an arbiter evaluation. Each required field is proved on its own.
$governedForContent = Seed::governedReleaseChain($db, $clock, $scope);
foreach (array_keys($content) as $field) {
    $incomplete = $battlecards->issueVerdict([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'company_signal_id' => Ids::new(),
        'arbiter_policy_set_id' => $governedForContent['arbiter_policy_set_id'],
        'content' => [...$content, $field => '   '],
    ]);
    Assert::same(
        'BATTLECARD_CONTENT_INCOMPLETE',
        $incomplete->reasonCode(),
        "{$field} is required — an incomplete battlecard is not a governed artifact",
    );
    Assert::same($field, $incomplete->detail['field'], 'and the refusal names WHICH field');
}

// `rejected_claims_safe` is as required as the rest. What the evidence does NOT support is part of
// the artifact — a battlecard that only said what was supported would invite a reader to fill the
// silence themselves.
Assert::same(
    'BATTLECARD_CONTENT_INCOMPLETE',
    $battlecards->issueVerdict([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'company_signal_id' => Ids::new(),
        'arbiter_policy_set_id' => $governedForContent['arbiter_policy_set_id'],
        'content' => [...$content, 'rejected_claims_safe' => ''],
    ])->reasonCode(),
    'a battlecard with no rejected-claims section is incomplete',
);

// ============================================================ only an ALLOW materializes an artifact

/**
 * Build a full signal chain sitting UNDER_REVIEW with a battlecard-ready proposal.
 *
 * It runs through the real modules; the only shortcut is that the signal row is written directly,
 * because this file is testing the verdict and release steps and the steps before them have their
 * own file.
 */
$readySignal = static function (array $scope, array $signalOverrides = []) use ($db, $clock, $proposals): array {
    // stopBeforeArbiter: the verdict is what THIS module issues, so the chain must arrive
    // reviewed but unevaluated. A pre-run verdict would leave the proposal already released.
    $governed = Seed::governedReleaseChain(
        $db, $clock, $scope, $signalOverrides, 'PROCEED_TO_ARBITER', 'COMPANY_SIGNAL_BRIEF', true
    );
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
        'summary_safe' => 'List price line changed.',
        'proposal_id' => $governed['proposal_id'],
        'status' => 'UNDER_REVIEW',
        'created_at' => $clock->now(),
    ]);

    return [...$governed, 'company_signal_id' => $signalId];
};

$admitted = $readySignal($scope);
$verdict = $battlecards->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $admitted['company_signal_id'],
    'arbiter_policy_set_id' => $admitted['arbiter_policy_set_id'],
    'content' => $content,
]);
Assert::true($verdict->isOk(), 'a relevance-reviewed signal reaches a verdict');
Assert::same('ADMITTED', $verdict->value()['outcome'], 'and is admitted');
Assert::same('ALLOW_INFORMATIONAL_RELEASE', $verdict->value()['verdict'], 'with an informational-release verdict');
$battlecardId = $verdict->value()['battlecard_brief_id'];

// The artifact carries its LINEAGE: the licence flags it was built under, and an uncertainty
// statement DERIVED from the signal rather than authored.
$artifact = $db->one(
    'SELECT status, uncertainty_summary_safe, source_license_visibility_flags, rejected_claims_safe
     FROM battlecard_briefs WHERE battlecard_brief_id = ?',
    [$battlecardId]
);
Assert::same('VERDICT_ISSUED', (string) $artifact['status'], 'the battlecard rests at VERDICT_ISSUED');
Assert::contains(
    'downstream customer impact remains a hypothesis',
    (string) $artifact['uncertainty_summary_safe'],
    'and even a FACT_OBSERVED signal yields an artifact whose CUSTOMER IMPACT stays a hypothesis',
);
Assert::same(
    'Not supported: that existing contracts were repriced.',
    (string) $artifact['rejected_claims_safe'],
    'with the rejected claims recorded ON the artifact',
);

// One verdict, one artifact. A second issuance is refused, so a decision cannot be re-materialized
// into a second battlecard a reader could choose between.
Assert::same(
    'BATTLECARD_ALREADY_EXISTS',
    $battlecards->issueVerdict([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'company_signal_id' => $admitted['company_signal_id'],
        'arbiter_policy_set_id' => $admitted['arbiter_policy_set_id'],
        'content' => $content,
    ])->reasonCode(),
    'a verdict is never re-materialized into a second artifact',
);

// A signal that never went to review has not had the question asked that the verdict answers.
$notReviewed = $readySignal($scope);
$db->run("UPDATE company_signals SET status = 'DRAFT' WHERE company_signal_id = ?", [
    $notReviewed['company_signal_id'],
]);
Assert::same(
    'SIGNAL_NOT_UNDER_REVIEW',
    $battlecards->issueVerdict([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'company_signal_id' => $notReviewed['company_signal_id'],
        'arbiter_policy_set_id' => $notReviewed['arbiter_policy_set_id'],
        'content' => $content,
    ])->reasonCode(),
    'a signal that never went to review cannot receive a verdict',
);

// A NON-ALLOWING verdict produces NO artifact at all. This is the asymmetry the module exists for.
$blockedScope = Seed::tenantWorkspace($db, $clock, 'blocked');
// `review_status => 'expired'` is a STALE_INPUT the arbiter's P01 relevance rules refuse. The
// point is not which condition — it is that a non-allowing verdict produces no artifact.
$blocked = $readySignal($blockedScope, ['review_status' => 'expired']);
$blockedVerdict = $battlecards->issueVerdict([
    'tenant_id' => $blockedScope['tenant_id'],
    'workspace_id' => $blockedScope['workspace_id'],
    'company_signal_id' => $blocked['company_signal_id'],
    'arbiter_policy_set_id' => $blocked['arbiter_policy_set_id'],
    'content' => $content,
]);
Assert::same('REFUSED', $blockedVerdict->value()['outcome'], 'a signal on a stale source is refused');
Assert::notSame(
    'ALLOW_INFORMATIONAL_RELEASE',
    $blockedVerdict->value()['verdict'],
    'with a non-allowing verdict',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM battlecard_briefs WHERE company_signal_id = ?', [
        $blocked['company_signal_id'],
    ]),
    'and NO artifact was materialized — the asymmetry this module exists for',
);

// ============================================================ release rests on current authority

// The card that governs the admitted signal permits customer output; without it the release is
// refused, because a released briefing is the artifact a salesperson repeats to a customer.
$cardId = (string) $db->scalar(
    'SELECT ms.source_license_card_id FROM company_signals cs
     JOIN source_diffs sd ON sd.source_diff_id = cs.source_diff_id
     JOIN monitored_sources ms ON ms.monitored_source_id = sd.monitored_source_id
     WHERE cs.company_signal_id = ?',
    [$admitted['company_signal_id']]
);

$releaseInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'battlecard_brief_id' => $battlecardId,
];

// Each release-boundary condition is proved on its own, by changing the card AFTER the verdict —
// which is exactly the state the second check exists for.
$cardStates = [
    'SOURCE_CARD_NOT_APPROVED_AT_RELEASE' => ["review_status = 'blocked'"],
    'SOURCE_LICENSE_PROHIBITED_AT_RELEASE' => ["license_class = 'PROHIBITED'"],
    'SOURCE_CARD_FORBIDS_CUSTOMER_OUTPUT_AT_RELEASE' => ['customer_output_allowed = 0'],
    'SOURCE_CARD_REVIEW_EXPIRED_AT_RELEASE' => ["review_expires_at = '2020-01-01T00:00:00.000Z'"],
];
foreach ($cardStates as $expected => $mutations) {
    $db->run("UPDATE source_license_cards SET review_status = 'approved', license_class = 'PUBLIC_WEB_ALLOWED',
              customer_output_allowed = 1, review_expires_at = NULL WHERE source_license_card_id = ?", [$cardId]);
    $db->run(
        sprintf('UPDATE source_license_cards SET %s WHERE source_license_card_id = ?', implode(', ', $mutations)),
        [$cardId]
    );
    Assert::same(
        $expected,
        $release->releaseBriefing($releaseInput)->reasonCode(),
        "a card {$expected} after the verdict stops the release",
    );
    Assert::same(
        0,
        (int) $db->scalar('SELECT COUNT(*) FROM released_briefings'),
        'and nothing was released',
    );
}

// Restore the card, and the release proceeds.
$db->run("UPDATE source_license_cards SET review_status = 'approved', license_class = 'PUBLIC_WEB_ALLOWED',
          customer_output_allowed = 1, review_expires_at = NULL WHERE source_license_card_id = ?", [$cardId]);

$released = $release->releaseBriefing($releaseInput);
Assert::true($released->isOk(), 'a battlecard on still-valid authority releases');
Assert::same('RELEASED', $released->value()['outcome'], 'as RELEASED');

// The canonical release IS the M10 decision record; the P01 row REFERENCES it. One place a release
// can be revoked from, rather than two that could disagree.
$briefing = $db->one(
    'SELECT decision_record_id, briefing_kind FROM released_briefings WHERE released_briefing_id = ?',
    [$released->value()['released_briefing_id']]
);
Assert::same(
    $released->value()['decision_record_id'],
    (string) $briefing['decision_record_id'],
    'the P01 row references the canonical M10 record rather than duplicating the decision',
);
Assert::same(
    'INTERNAL_INFORMATIONAL_RELEASE',
    (string) $db->scalar('SELECT record_type FROM decision_records WHERE decision_record_id = ?', [
        $released->value()['decision_record_id'],
    ]),
    'which is an INTERNAL informational release — never a customer-visible one',
);

// All three status advances committed together.
Assert::same(
    'RELEASED',
    (string) $db->scalar('SELECT status FROM battlecard_briefs WHERE battlecard_brief_id = ?', [$battlecardId]),
    'the battlecard advances to RELEASED',
);
Assert::same(
    'RELEASED',
    (string) $db->scalar('SELECT status FROM company_signals WHERE company_signal_id = ?', [
        $admitted['company_signal_id'],
    ]),
    'so does the signal',
);
Assert::same(
    'RELEASED',
    (string) $db->scalar('SELECT status FROM proposal_envelopes WHERE proposal_id = ?', [$admitted['proposal_id']]),
    'and the proposal envelope, advanced through M04 against the M10 record',
);

// Double release is refused on both the status guard and the existence check.
Assert::same(
    'BATTLECARD_NOT_RELEASABLE',
    $release->releaseBriefing($releaseInput)->reasonCode(),
    'a released battlecard cannot be released again',
);

// ============================================================ released is not visible

// A release is INTERNAL. Nothing in this path activated visibility for any audience, and the
// M16 activation plane is where that would have to happen.
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM release_visibility_activations'),
    'an internal release activated no visibility',
);
Assert::same(
    0,
    (int) $db->scalar("SELECT COUNT(*) FROM release_artifacts WHERE status = 'RELEASED_CUSTOMER_VISIBLE'"),
    'and no artifact reached a customer-visible status',
);
