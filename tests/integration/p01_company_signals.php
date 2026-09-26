<?php

declare(strict_types=1);

/**
 * P01 company signals: from a detected change to a relevance-reviewed brief.
 *
 * The claim under test is the one the whole product rests on: an OBSERVATION and an
 * INTERPRETATION are different things, and nothing in this path may quietly promote the second
 * into the first.
 *
 * A signal says a competitor's page changed. It does not say a customer cares. The review and the
 * verdict decide that, with a human in the loop — and the tests below prove there is no code path
 * that skips them.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Ingestion\FetchResponse;
use Allfather\Loopia\Ingestion\FixedHostResolver;
use Allfather\Loopia\Ingestion\FixtureTransport;
use Allfather\Loopia\Product\P01\Capture;
use Allfather\Loopia\Product\P01\ChangeDetection;
use Allfather\Loopia\Product\P01\CompanySignals;
use Allfather\Loopia\Product\P01\Registry as P01Registry;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M06\Gateway;
use Allfather\Loopia\Substrate\M07\Council;
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
$evidence = new EvidenceSpine($db, $clock);
$proposals = new Proposals($db, $clock);
$council = new Council($db, $clock);
$m03 = new M03Registry($db, $clock);
$signals = new CompanySignals($db, $clock, $proposals, $evidence, new Readiness($db, $clock), $council);
$gateway = new Gateway(
    $db, $clock, new AccessGuard($db, $clock), new Eligibility($db, $clock), new Invocation($db, $clock)
);
$capture = new Capture($db, $clock, $gateway, $evidence);
$cards = new SourceCards($db, $clock);
$p01 = new P01Registry($db, $clock);
$changes = new ChangeDetection($db, $clock, $evidence);

$role = Seed::role($db, $clock, 'tool_caller', ['tool.invoke']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$chain = Seed::governedToolChain($db, $clock, $scope);
// NO connector descriptor is seeded. A P01 capture rides its own governed read tool, not an M15
// connector dispatch, so the connector legs of the compiled policy are out of its scope entirely
// — and this chain running without one is part of what proves it.

// --- build the capture chain through the real modules -------------------------------------------

$card = $cards->project([
    'tenant_id' => $scope['tenant_id'],
    'source_name' => 'Competitor A pricing',
    'source_url_safe' => 'https://sources.example/pricing',
    'source_type' => 'website',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'PUBLIC_WEB_ALLOWED',
    'robots_policy_checked' => true,
    'terms_policy_checked' => true,
    'review_status' => 'approved',
    'caching_policy' => 'explicit_contract',
    'retention_class' => 'standard',
    'derived_summary_allowed' => true,
]);
$competitorId = $p01->registerCompetitor(
    $scope['tenant_id'], $scope['workspace_id'], 'Competitor A', 'DIRECT', 'OPERATOR', $scope['operator_id']
)->value()['competitor_id'];
$monitoredSourceId = $p01->registerMonitoredSource(
    $scope['tenant_id'], $scope['workspace_id'], $competitorId,
    $card['source_ref_id'], $card['source_license_card_id'], 'WEBSITE'
)->value()['monitored_source_id'];

$governance = [
    'mission_id' => $chain['mission_id'],
    'assignment_id' => $chain['assignment_id'],
    'valkyrie_id' => $chain['valkyrie_id'],
    'capability_contract_id' => $chain['capability_contract_id'],
    'governed_tool_id' => $chain['governed_tool_id'],
    'governed_tool_operation_id' => $chain['governed_tool_operation_id'],
    'calling_actor_type' => 'OPERATOR',
    'calling_actor_id' => $scope['operator_id'],
];
$resolver = new FixedHostResolver(['sources.example' => ['93.184.216.34']]);
$fetch = static fn (string $body): FixtureTransport => new FixtureTransport([
    'https://sources.example/pricing' => new FetchResponse(200, 'text/html', null, $body),
]);
$captureOnce = static function (string $body, string $key) use ($capture, $scope, $monitoredSourceId, $governance, $fetch, $resolver): array {
    return $capture->captureMonitoredSource([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'monitored_source_id' => $monitoredSourceId,
        'capture_url' => 'https://sources.example/pricing',
        'idempotency_key' => $key,
        'correlation_id' => Ids::new(),
        'governance' => $governance,
    ], $fetch($body), $resolver)->value();
};

$page1 = "Competitor A\nList price 449 SEK\nFree trial 14 days";
$page2 = "Competitor A\nList price 499 SEK\nFree trial 30 days\nNew enterprise tier";
$snap1 = $captureOnce($page1, 'sig-1');
$snap2 = $captureOnce($page2, 'sig-2');

$classified = $changes->detect([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'before_snapshot_id' => $snap1['source_snapshot_id'], 'after_snapshot_id' => $snap2['source_snapshot_id'],
    'before_text' => $page1, 'after_text' => $page2,
])->value();

// ============================================================ speculation is never a fact

// No-cache source retains no comparison proof, so changed content remains unclassified.
$db->run("UPDATE source_license_cards SET caching_policy='no_cache' WHERE source_license_card_id=?",[$card['source_license_card_id']]);
$snap3 = $captureOnce($page1, 'sig-3');
$unclassified = $changes->detect([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'before_snapshot_id' => $snap2['source_snapshot_id'], 'after_snapshot_id' => $snap3['source_snapshot_id'],
])->value();
Assert::same('UNKNOWN', $unclassified['change_significance'], 'an unmeasured change is UNKNOWN');

$asFact = $signals->createFromDiff([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'source_diff_id' => $unclassified['source_diff_id'],
    'signal_class' => 'PRICING_CHANGE_SIGNAL',
    'interpretation_kind' => 'FACT_OBSERVED',
    'confidence_band' => 'HIGH',
    'summary_safe' => 'Pricing appears to have changed.',
]);
Assert::true($asFact->isRefusal(), 'an unmeasured change cannot be recorded as an observed fact');
Assert::same(
    'FACT_REQUIRES_CLASSIFIED_CHANGE',
    $asFact->reasonCode(),
    'named specifically — speculation wearing a fact\'s label is the failure being prevented',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM company_signals WHERE source_diff_id = ?', [
        $unclassified['source_diff_id'],
    ]),
    'and no signal row exists for it',
);

// The SAME diff, honestly labelled, is accepted. The refusal is about the CLAIM, not the change.
$asHypothesis = $signals->createFromDiff([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'source_diff_id' => $unclassified['source_diff_id'],
    'signal_class' => 'PRICING_CHANGE_SIGNAL',
    'interpretation_kind' => 'HYPOTHESIS',
    'confidence_band' => 'LOW',
    'summary_safe' => 'Pricing may have changed; magnitude not measured.',
]);
Assert::true($asHypothesis->isOk(), 'the same change IS recordable as a hypothesis');

// ============================================================ a signal needs a change

// A NONE diff is no change at all. Recording a signal over one would assert an event that did not
// occur, so it is refused whatever the caller labels it.
$identical = $captureOnce($page1, 'sig-4');
$noChange = $changes->detect([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'before_snapshot_id' => $snap3['source_snapshot_id'], 'after_snapshot_id' => $identical['source_snapshot_id'],
])->value();
Assert::same('NONE', $noChange['change_significance'], 'an identical capture is NONE');
Assert::same(
    'SIGNAL_REQUIRES_DETECTED_CHANGE',
    $signals->createFromDiff([
        'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
        'monitored_source_id' => $monitoredSourceId,
        'source_diff_id' => $noChange['source_diff_id'],
        'signal_class' => 'PRICING_CHANGE_SIGNAL',
        'interpretation_kind' => 'HYPOTHESIS',
        'confidence_band' => 'LOW',
        'summary_safe' => 'Nothing changed.',
    ])->reasonCode(),
    'a signal over an unchanged source is refused even as a hypothesis',
);

// ============================================================ the governed brief

$signal = $signals->createFromDiff([
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'monitored_source_id' => $monitoredSourceId,
    'source_diff_id' => $classified['source_diff_id'],
    'signal_class' => 'PRICING_CHANGE_SIGNAL',
    'interpretation_kind' => 'FACT_OBSERVED',
    'confidence_band' => 'HIGH',
    'summary_safe' => 'List price line changed; a new tier line appeared.',
]);
Assert::true($signal->isOk(), 'a classified change supports a FACT_OBSERVED signal');
$signalId = $signal->value()['company_signal_id'];

$producer = $m03->registerValkyrie(
    $scope['tenant_id'], $scope['workspace_id'], 'Signal Producer',
    'COMPETITION_MONITOR_VALKYRIE', 'Produces competition signal briefs.', $scope['operator_id'], 'ACTIVE'
);
$producerContract = $m03->issueContract(
    $producer, $scope['tenant_id'], $scope['workspace_id'], 'p01.signal',
    'COMPETITION_MONITOR_VALKYRIE', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE']
)->value()['capability_contract_id'];

$briefGovernance = [
    'mission_id' => $chain['mission_id'],
    'producing_valkyrie_id' => $producer,
    'capability_contract_id' => $producerContract,
    'submitted_by_actor_type' => 'VALKYRIE',
    'submitted_by_actor_id' => $producer,
];

$brief = $signals->submitBrief([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $signalId,
    'governance' => $briefGovernance,
]);
Assert::true($brief->isOk(), 'the signal submits as a governed brief');
Assert::same('SATISFIED_FOR_EVALUATION', $brief->value()['readiness_status'], 'with admissible evidence');

// ALL THREE evidence objects are cited: both snapshots and the change reference. They answer
// different questions — what was seen before, what was seen after, and the derivation that says
// they differ. A bundle citing only the derivation would assert a difference with nothing to
// compare.
Assert::same(3, count($brief->value()['cited_evidence_ids']), 'citing three evidence objects');
$citedTypes = array_column($db->all(
    sprintf(
        'SELECT evidence_type FROM evidence_objects WHERE evidence_id IN (%s) ORDER BY evidence_type',
        implode(', ', array_fill(0, 3, '?'))
    ),
    $brief->value()['cited_evidence_ids']
), 'evidence_type');
Assert::equalsList(
    ['CHANGE_DETECTION_REFERENCE', 'HOST_RETRIEVAL_CAPTURE', 'HOST_RETRIEVAL_CAPTURE'],
    $citedTypes,
    'both captures AND the derived change reference',
);

// The interpretation kind travels into the proposal, so a reader downstream cannot lose it.
$proposal = $db->one(
    'SELECT status, uncertainty_summary, requested_action_class, declared_limitations
     FROM proposal_envelopes WHERE proposal_id = ?',
    [$brief->value()['proposal_id']]
);
Assert::same('SUBMITTED', (string) $proposal['status'], 'the proposal is frozen at SUBMITTED');
Assert::same('ANALYZE', (string) $proposal['requested_action_class'], 'as an ANALYZE act — nothing external');
Assert::contains(
    'FACT_OBSERVED',
    (string) $proposal['uncertainty_summary'],
    'and its uncertainty statement carries the interpretation kind forward',
);
Assert::contains(
    'customer relevance is the HMWH review and Arbiter verdict',
    (string) $proposal['declared_limitations'],
    'with the limitation stated on the brief itself: this path measures structure, not relevance',
);

// Double submission is refused. A signal already carrying a proposal is not draft, whatever its
// status column says.
Assert::same(
    'SIGNAL_NOT_DRAFT',
    $signals->submitBrief([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'company_signal_id' => $signalId,
        'governance' => $briefGovernance,
    ])->reasonCode(),
    'a signal cannot be submitted twice',
);

// ============================================================ the relevance review

$review = $signals->openRelevanceReview([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $signalId,
    'initiated_by_actor_type' => 'OPERATOR',
    'initiated_by_actor_id' => $scope['operator_id'],
]);
Assert::true($review->isOk(), 'the relevance review opens');
Assert::same(
    'COMPETITION_RELEVANCE_REVIEW',
    (string) $db->scalar('SELECT review_purpose FROM hmwh_review_sessions WHERE review_session_id = ?', [
        $review->value()['review_session_id'],
    ]),
    'under the competition-relevance purpose',
);
Assert::same(
    'UNDER_REVIEW',
    (string) $db->scalar('SELECT status FROM company_signals WHERE company_signal_id = ?', [$signalId]),
    'and the signal advances to UNDER_REVIEW',
);

// THE PRODUCER CANNOT REVIEW ITS OWN BRIEF. This is the Council's law, reused rather than
// re-implemented — and it is what makes "relevance-reviewed" mean something.
Assert::same(
    'HMWH_SELF_REVIEW_MISREPRESENTED_AS_INDEPENDENT',
    $council->assignParticipant(
        $review->value()['review_session_id'], $producer, 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
    )->reasonCode(),
    'the producing agent cannot be declared its own INDEPENDENT reviewer',
);

// ============================================================ the signal is not a verdict

// Everything above produced a REVIEWED BRIEF, and nothing more. No verdict, no release, no
// visibility — the chain stops exactly where a human judgement is owed.
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM arbiter_verdicts'),
    'no verdict was reached — the signal path stops before the arbiter',
);
Assert::same(
    0,
    (int) $db->scalar("SELECT COUNT(*) FROM decision_records WHERE record_type = 'INTERNAL_INFORMATIONAL_RELEASE'"),
    'and nothing was released',
);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM release_visibility_activations'),
    'and nothing was made visible to anyone',
);
