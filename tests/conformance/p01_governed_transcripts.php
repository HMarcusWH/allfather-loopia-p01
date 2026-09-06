<?php

declare(strict_types=1);

/**
 * CONFORMANCE: governed OUTCOMES, compared as normalized transcripts.
 *
 * What conformance means here, and what it deliberately does not:
 *
 *   IT COMPARES DECISIONS, NOT IMPLEMENTATIONS. Each case states input facts and an authority
 *   state, runs the real modules, and compares a normalized transcript — the decision, the reason
 *   codes, and the governed consequence. Identifiers and timestamps are stripped, because a port
 *   that produced different UUIDs would be conformant and one that produced a different REASON
 *   would not.
 *
 *   IT IS NOT A SNAPSHOT TEST. A snapshot records whatever the code currently does and calls that
 *   correct. Each expectation below is written down as the required outcome, and a change that
 *   alters it is meant to fail — that is the entire point of pinning reason codes rather than
 *   asserting "some refusal happened".
 *
 * The seven cases are the ones the suite README requires, each a distinct governed question.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Battlecards;
use Allfather\Loopia\Product\P01\Release as P01Release;
use Allfather\Loopia\Substrate\M05\Readiness;
use Allfather\Loopia\Substrate\M08\Arbiter;
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
$ledger = new DecisionLedger($db, $clock);
$arbiter = new Arbiter($db, $clock, new Readiness($db, $clock));
$battlecards = new Battlecards($db, $clock, $arbiter);
$release = new P01Release($db, $clock, $ledger, new ReleaseOutput($db, $clock, $ledger));

$content = [
    'why_it_matters_safe' => 'The competitor raised list pricing on its public page.',
    'customer_impact_hypothesis_safe' => 'Existing customers MAY face a renewal increase; not observed.',
    'talk_track_safe' => 'Ask whether the customer has seen a renewal notice.',
    'recommended_response_safe' => 'Reconfirm our own price stability in writing.',
    'rejected_claims_safe' => 'Not supported: that existing contracts were repriced.',
];

/**
 * Build a signal chain sitting UNDER_REVIEW, with one link of the authority state bent.
 *
 * The overrides are the "input facts and authority state" half of a fixture; everything else runs
 * through the real modules, so a case cannot pass over a state the product could not reach.
 */
$chainWith = static function (array $signalOverrides = [], string $interpretation = 'FACT_OBSERVED') use (
    $db, $clock, $scope
): array {
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
        'interpretation_kind' => $interpretation,
        'confidence_band' => 'HIGH',
        'summary_safe' => 'List price line changed.',
        'proposal_id' => $governed['proposal_id'],
        'status' => 'UNDER_REVIEW',
        'created_at' => $clock->now(),
    ]);

    return [...$governed, 'company_signal_id' => $signalId];
};

/**
 * The normalized transcript of a governed verdict.
 *
 * Ids and timestamps are dropped; the DECISION and its REASONS are kept. Reason codes are sorted so
 * a transcript does not depend on the order rules happened to fire in — the set of reasons is the
 * conformance surface, not their sequence.
 *
 * @return array{outcome: string, verdict: ?string, reason_codes: list<string>, artifact_materialized: bool}
 */
$transcriptOf = static function (array $chain, $verdict) use ($db): array {
    // The reason codes come from the RECORDED VERDICT wherever there is one, not from the return
    // value. That is the conformance surface: what the arbiter wrote down is what an auditor will
    // read, and a port whose return shape differed but whose record matched is conformant.
    $verdictId = $verdict->isOk() ? ($verdict->value()['arbiter_verdict_id'] ?? null) : null;
    if ($verdictId !== null) {
        $reasonCodes = json_decode(
            (string) $db->scalar(
                'SELECT reason_codes FROM arbiter_verdicts WHERE arbiter_verdict_id = ?',
                [$verdictId]
            ),
            true, 512, JSON_THROW_ON_ERROR
        );
    } else {
        $reasonCodes = $verdict->isOk() ? ($verdict->value()['reason_codes'] ?? []) : [$verdict->reasonCode()];
    }
    $reasonCodes = array_values(array_unique($reasonCodes));
    sort($reasonCodes);

    return [
        'outcome' => $verdict->isOk() ? (string) $verdict->value()['outcome'] : 'REJECTED',
        'verdict' => $verdict->isOk() ? ($verdict->value()['verdict'] ?? null) : null,
        'reason_codes' => $reasonCodes,
        // The governed CONSEQUENCE, not just the words: did an artifact come into existence?
        'artifact_materialized' => (int) $db->scalar(
            'SELECT COUNT(*) FROM battlecard_briefs WHERE company_signal_id = ?',
            [$chain['company_signal_id']]
        ) > 0,
    ];
};

/** Assert a transcript field-by-field, so a failure names WHICH part of the outcome diverged. */
$expectTranscript = static function (string $fixtureId, array $expected, array $actual): void {
    Assert::same($expected['outcome'], $actual['outcome'], "{$fixtureId}: outcome");
    Assert::same($expected['verdict'], $actual['verdict'], "{$fixtureId}: verdict");
    foreach ($expected['reason_codes'] as $code) {
        Assert::true(
            in_array($code, $actual['reason_codes'], true),
            "{$fixtureId}: the transcript carries {$code}",
        );
    }
    Assert::same(
        $expected['artifact_materialized'],
        $actual['artifact_materialized'],
        "{$fixtureId}: artifact materialization",
    );
};

// ============================================================ CONF-001 unapproved source

// An unapproved source card is not weaker authority for a claim; it is no authority for one.
$c1 = $chainWith(['review_status' => 'expired']);
$expectTranscript('CONF-001 unapproved source', [
    'outcome' => 'REFUSED',
    'verdict' => 'HOLD',
    'reason_codes' => ['STALE_INPUT', 'ARBITER_P01_RELEVANCE_DENIED'],
    'artifact_materialized' => false,
], $transcriptOf($c1, $battlecards->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $c1['company_signal_id'],
    'arbiter_policy_set_id' => $c1['arbiter_policy_set_id'],
    'content' => $content,
])));

// ============================================================ CONF-002 prohibited licence

$c2 = $chainWith(['license_class' => 'PROHIBITED']);
$expectTranscript('CONF-002 prohibited licence', [
    'outcome' => 'REFUSED',
    'verdict' => 'HOLD',
    'reason_codes' => ['SOURCE_AUTHORITY_TOO_LOW', 'ARBITER_P01_RELEVANCE_DENIED'],
    'artifact_materialized' => false,
], $transcriptOf($c2, $battlecards->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $c2['company_signal_id'],
    'arbiter_policy_set_id' => $c2['arbiter_policy_set_id'],
    'content' => $content,
])));

// ============================================================ CONF-003 missing evidence

// An UNCLASSIFIED diff is not evidence of anything in particular, so it cannot back a release.
$c3 = $chainWith(['change_significance' => 'UNKNOWN']);
// REQUIRE_MORE_EVIDENCE, not HOLD — and the distinction is the point. "Bring more evidence" names
// what would change the answer; a bare hold does not.
$expectTranscript('CONF-003 missing evidence', [
    'outcome' => 'REFUSED',
    'verdict' => 'REQUIRE_MORE_EVIDENCE',
    'reason_codes' => ['INSUFFICIENT_EVIDENCE', 'ARBITER_P01_RELEVANCE_DENIED'],
    'artifact_materialized' => false,
], $transcriptOf($c3, $battlecards->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $c3['company_signal_id'],
    'arbiter_policy_set_id' => $c3['arbiter_policy_set_id'],
    'content' => $content,
])));

// ============================================================ CONF-004 a HOLD releases nothing

// The load-bearing asymmetry: a non-allowing verdict does not produce a lesser artifact. It
// produces NONE. Proved above by `artifact_materialized => false` on each; stated here as its own
// claim, against the release path rather than the verdict path.
$c4 = $chainWith(['review_status' => 'expired']);
$battlecards->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $c4['company_signal_id'],
    'arbiter_policy_set_id' => $c4['arbiter_policy_set_id'],
    'content' => $content,
]);
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM released_briefings'),
    'CONF-004: a held verdict leaves NOTHING released, anywhere in the tenant',
);

// ============================================================ CONF-005 hypothesis stays a hypothesis

// The uncertainty statement is DERIVED from the interpretation kind, not authored alongside it, so
// a hypothesis cannot be described as an observation by whoever writes the summary.
$c5 = $chainWith([], 'HYPOTHESIS');
$hypothesisVerdict = $battlecards->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $c5['company_signal_id'],
    'arbiter_policy_set_id' => $c5['arbiter_policy_set_id'],
    'content' => $content,
]);
Assert::same('ADMITTED', $hypothesisVerdict->value()['outcome'], 'CONF-005: a hypothesis can still be admitted');
Assert::contains(
    'HYPOTHESIS',
    (string) $db->scalar(
        'SELECT uncertainty_summary_safe FROM battlecard_briefs WHERE battlecard_brief_id = ?',
        [$hypothesisVerdict->value()['battlecard_brief_id']]
    ),
    'CONF-005: and the artifact SAYS it is a hypothesis — derived, not authored',
);
Assert::same(
    'HYPOTHESIS',
    (string) $db->scalar(
        'SELECT interpretation_kind FROM company_signals WHERE company_signal_id = ?',
        [$c5['company_signal_id']]
    ),
    'CONF-005: the interpretation kind survives the whole chain unchanged',
);

// ============================================================ CONF-006 clean chain releases

$c6 = $chainWith();
$clean = $battlecards->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'company_signal_id' => $c6['company_signal_id'],
    'arbiter_policy_set_id' => $c6['arbiter_policy_set_id'],
    'content' => $content,
]);
$expectTranscript('CONF-006 clean chain', [
    'outcome' => 'ADMITTED',
    'verdict' => 'ALLOW_INFORMATIONAL_RELEASE',
    'reason_codes' => ['ARBITER_ADMISSIBLE_FOR_INTERNAL_RELEASE'],
    'artifact_materialized' => true,
], $transcriptOf($c6, $clean));

$released = $release->releaseBriefing([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'battlecard_brief_id' => $clean->value()['battlecard_brief_id'],
]);
Assert::same('RELEASED', $released->value()['outcome'], 'CONF-006: and it releases');
// The canonical release IS the M10 record. This is the conformance claim that matters most: a port
// that released without a ledger record would look identical from the product surface and be
// entirely non-conformant.
Assert::true(
    $ledger->isCurrentlyRelied($released->value()['decision_record_id']),
    'CONF-006: backed by a decision record the ledger relies on',
);

// ============================================================ CONF-007 cross-tenant

$foreign = Seed::tenantWorkspace($db, $clock, 'rival');
Assert::same(
    'BATTLECARD_SCOPE_MISMATCH',
    $release->releaseBriefing([
        'tenant_id' => $foreign['tenant_id'],
        'workspace_id' => $foreign['workspace_id'],
        'battlecard_brief_id' => $clean->value()['battlecard_brief_id'],
    ])->reasonCode(),
    'CONF-007: another tenant cannot release this tenant\'s artifact',
);
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM released_briefings'),
    'CONF-007: and the attempt released nothing',
);
