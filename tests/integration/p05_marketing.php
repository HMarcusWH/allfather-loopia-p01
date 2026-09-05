<?php

declare(strict_types=1);

/**
 * P05 marketing customization: composition, claims, variants, admission, release, surfacing.
 *
 * The claim under test: P05 turns released intelligence into internal DRAFTS, every comparative
 * claim cites governed evidence, and nothing here can publish.
 *
 * The last of those is the one worth being precise about. There is no PUBLISHED state guarded by a
 * check — the status vocabulary has no such member, and the tables carry no channel, destination
 * or schedule column. The test asserts that structurally rather than by trying to publish and
 * being refused, because being refused would imply the path exists.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P05\MarketingAdmission;
use Allfather\Loopia\Product\P05\MarketingAssets;
use Allfather\Loopia\Product\P05\Release as P05Release;
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
$entitlements = new Entitlements($db, $clock);

$assets = new MarketingAssets($db, $clock, $ledger);
$admission = new MarketingAdmission(
    $db, $clock, $evidence, $readiness, $proposals, $council, $arbiter, $ledger
);
$release = new P05Release(
    $db, $clock, $ledger, $proposals, new AccessGuard($db, $clock), $entitlements
);

$policySetId = (new PolicySets($db, $clock))->seed(
    PolicySets::P05_MARKETING_ASSET_ADMISSION, $scope['tenant_id'], $scope['workspace_id']
)->value()['arbiter_policy_set_id'];

$source = Seed::p01ReleasedBriefing($db, $clock, $scope);

$composeInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'asset_kind' => 'LANDING_PAGE_VARIANT',
    'source_kind' => 'P01_BRIEFING',
    'source_id' => $source['released_briefing_id'],
    'title_safe' => 'Price stability, in writing',
    'draft_content_safe' => 'We publish our list price and hold it for the contract term.',
];

// ============================================================ there is no publish state

// Asserted against the SCHEMA, not against a refusal. A guarded publish path would still be a
// publish path; what makes this hold is that the vocabulary has no such member.
$statusCheck = (string) $db->scalar(
    "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_asset_drafts'
       AND CHECK_CLAUSE LIKE '%asset_status%'"
);
foreach (['PUBLISHED', 'SENT', 'DISPATCHED', 'SCHEDULED'] as $forbidden) {
    Assert::false(
        str_contains($statusCheck, $forbidden),
        "the asset_status vocabulary has no {$forbidden} member — there is no publish state to reach",
    );
}
Assert::contains(
    'RELEASED_INTERNAL',
    $statusCheck,
    'the strongest state an asset reaches is RELEASED_INTERNAL',
);

$draftColumns = array_map(
    static fn (array $c): string => (string) $c['COLUMN_NAME'],
    $db->all(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?)',
        ['marketing_asset_drafts', 'marketing_asset_variants']
    )
);
foreach (['channel', 'recipient', 'destination', 'scheduled', 'send'] as $forbidden) {
    Assert::equalsList(
        [],
        array_values(array_filter($draftColumns, static fn (string $c): bool => str_contains($c, $forbidden))),
        "no `{$forbidden}` column exists on a draft or variant — a publish would have nowhere to write",
    );
}

// ============================================================ composition

Assert::same(
    'UNKNOWN_ASSET_KIND',
    $assets->compose([...$composeInput, 'asset_kind' => 'BILLBOARD'])->reasonCode(),
    'an asset kind outside the closed set is refused',
);
// Validated explicitly rather than falling through to the P03 branch, where the exactly-one-FK
// CHECK would have thrown instead of naming a reason.
Assert::same(
    'UNKNOWN_SOURCE_KIND',
    $assets->compose([...$composeInput, 'source_kind' => 'P09_SOMETHING'])->reasonCode(),
    'and so is an unrecognized source kind — named, not thrown',
);
Assert::same(
    'SOURCE_NOT_FOUND',
    $assets->compose([...$composeInput, 'source_id' => Ids::new()])->reasonCode(),
    'an unknown source resolves to nothing rather than defaulting to permitted',
);

$composed = $assets->compose($composeInput);
Assert::true($composed->isOk(), 'a live release composes a DRAFT marketing asset');
$draftId = $composed->value()['marketing_asset_draft_id'];

$row = $db->one(
    'SELECT asset_status, asset_digest, released_briefing_id, released_prospect_output_id, basis_evidence_id
     FROM marketing_asset_drafts WHERE marketing_asset_draft_id = ?',
    [$draftId]
);
Assert::same('DRAFT', (string) $row['asset_status'], 'as a DRAFT');
Assert::same(null, $row['basis_evidence_id'], 'with no basis yet — that is the submission\'s job');
Assert::notSame(null, $row['released_briefing_id'], 'citing its P01 source');
Assert::same(null, $row['released_prospect_output_id'], 'and only that one');
Assert::same(
    $composed->value()['asset_digest'],
    (string) $row['asset_digest'],
    'and carrying the digest of exactly what it says',
);

// ============================================================ the evidence-cited-claim invariant

$claimInput = [
    'tenant_id' => $scope['tenant_id'],
    'marketing_asset_draft_id' => $draftId,
    'claim_text_safe' => 'Unlike the competitor, our list price is held for the contract term.',
    'cited_evidence_id' => $source['evidence_id'],
];

// A fabricated evidence id cites nothing.
Assert::same(
    'CITED_EVIDENCE_NOT_FOUND',
    $assets->addClaimCitation([...$claimInput, 'cited_evidence_id' => Ids::new()])->reasonCode(),
    'a claim cannot cite evidence that does not exist',
);

// Evidence in ANOTHER WORKSPACE of the same tenant is equally unavailable. From this draft's
// position it does not exist, and the refusal says so rather than reporting a scope error.
$otherWorkspace = Seed::tenantWorkspace($db, $clock, 'second-workspace');
$foreignEvidence = Seed::p01ReleasedBriefing($db, $clock, $otherWorkspace)['evidence_id'];
Assert::same(
    'CITED_EVIDENCE_NOT_FOUND',
    $assets->addClaimCitation([...$claimInput, 'cited_evidence_id' => $foreignEvidence])->reasonCode(),
    'nor evidence belonging to another workspace',
);

$cited = $assets->addClaimCitation($claimInput);
Assert::true($cited->isOk(), 'a claim citing real in-scope governed evidence is accepted');

// The citation ledger is APPEND-ONLY. A citation that could be edited after a verdict would let
// the evidence behind a cleared claim be swapped for something else.
Assert::throws(
    static fn () => $db->run(
        'UPDATE marketing_claim_citations SET claim_text_safe = ? WHERE marketing_claim_citation_id = ?',
        ['edited', $cited->value()['marketing_claim_citation_id']]
    ),
    \PDOException::class,
    'and cannot be edited afterwards — the citation ledger is append-only',
);

// ============================================================ variants

$variant = $assets->addVariant([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'marketing_asset_draft_id' => $draftId,
    'variant_key' => 'headline-b',
    'variant_content_safe' => 'Your price, fixed for the term.',
]);
Assert::true($variant->isOk(), 'a copy variant is added to a DRAFT');

// From outside the draft's workspace, the same call is refused. A variant is content, and content
// added from elsewhere is content this workspace never wrote.
Assert::same(
    'DRAFT_SCOPE_MISMATCH',
    $assets->addVariant([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $otherWorkspace['workspace_id'],
        'marketing_asset_draft_id' => $draftId,
        'variant_key' => 'headline-c',
        'variant_content_safe' => 'Injected from another workspace.',
    ])->reasonCode(),
    'a variant cannot be added from another workspace',
);

// ============================================================ submission closes the draft

$governance = [
    'mission_id' => $source['mission_id'],
    'producing_valkyrie_id' => $source['producing_valkyrie_id'],
    'capability_contract_id' => $source['capability_contract_id'],
];
$submitInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'marketing_asset_draft_id' => $draftId,
    'governance' => $governance,
];

$submitted = $admission->submitDraftProposal($submitInput);
Assert::true($submitted->isOk(), 'a DRAFT submits as a governed marketing proposal');
$proposalId = $submitted->value()['proposal_id'];

$envelope = $db->one(
    'SELECT proposal_type, requested_action_class, declared_limitations
     FROM proposal_envelopes WHERE proposal_id = ?',
    [$proposalId]
);
Assert::same('MARKETING_ASSET_DRAFT', (string) $envelope['proposal_type'], 'declaring what it is');
// The ceiling: an internal release, never an external action class.
Assert::same(
    'RELEASE_INTELLIGENCE',
    (string) $envelope['requested_action_class'],
    'asking for INTERNAL release only',
);
Assert::contains(
    'no external publication',
    (string) $envelope['declared_limitations'],
    'and saying so on the envelope, where a reviewer sees it',
);

Assert::same(
    'IN_REVIEW',
    (string) $db->scalar(
        'SELECT asset_status FROM marketing_asset_drafts WHERE marketing_asset_draft_id = ?',
        [$draftId]
    ),
    'the draft moves to IN_REVIEW',
);

// AND IS NOW CLOSED FOR EDITS. This is what makes the review honest: nobody can add a comparative
// claim underneath a verdict about copy that did not have one.
Assert::same(
    'DRAFT_NOT_EDITABLE',
    $assets->addClaimCitation($claimInput)->reasonCode(),
    'a draft in review accepts no further claims',
);
Assert::same(
    'DRAFT_NOT_EDITABLE',
    $assets->addVariant([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'marketing_asset_draft_id' => $draftId,
        'variant_key' => 'headline-d',
        'variant_content_safe' => 'Added after submission.',
    ])->reasonCode(),
    'nor any further variants',
);
Assert::same(
    'DRAFT_NOT_DRAFT',
    $admission->submitDraftProposal($submitInput)->reasonCode(),
    'and cannot be submitted a second time',
);

// ============================================================ review and verdict

$review = $admission->openAssetReview([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'marketing_asset_draft_id' => $draftId,
    'initiated_by_actor_type' => 'OPERATOR',
    'initiated_by_actor_id' => $scope['operator_id'],
]);
Assert::true($review->isOk(), 'the marketing-asset review opens over the frozen proposal');
$sessionId = $review->value()['review_session_id'];
Assert::same(
    'MARKETING_ASSET_REVIEW',
    (string) $db->scalar(
        'SELECT review_purpose FROM hmwh_review_sessions WHERE review_session_id = ?',
        [$sessionId]
    ),
    'under its own review purpose',
);

$assignmentId = $council->assignParticipant(
    $sessionId, $source['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
)->value()['participant_assignment_id'];
$council->recordAssessment(
    $sessionId, $assignmentId, $source['critic_valkyrie_id'],
    'SUPPORT_PROCEED_TO_ARBITER', 'Copy is internal and cites its comparative claim.'
);
$council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');

$verdict = $admission->issueVerdict([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'marketing_asset_draft_id' => $draftId,
    'arbiter_policy_set_id' => $policySetId,
]);
Assert::true($verdict->isOk(), 'the draft reaches a verdict');
Assert::same('ADMITTED', $verdict->value()['outcome'], 'and is admitted');
Assert::same(
    'ALLOW_INFORMATIONAL_RELEASE',
    $verdict->value()['verdict'],
    'with an informational-release verdict',
);
Assert::same(
    'VERDICT_ISSUED',
    (string) $db->scalar(
        'SELECT asset_status FROM marketing_asset_drafts WHERE marketing_asset_draft_id = ?',
        [$draftId]
    ),
    'advancing only on the verdict the arbiter gave',
);

// ============================================================ release

$releaseInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'marketing_asset_draft_id' => $draftId,
];
$releasedOut = $release->releaseMarketingAsset($releaseInput);
Assert::true($releasedOut->isOk(), 'an admitted draft releases internally');
Assert::same('RELEASED', $releasedOut->value()['outcome'], 'as RELEASED');
Assert::same(
    'RELEASED_INTERNAL',
    (string) $db->scalar(
        'SELECT asset_status FROM marketing_asset_drafts WHERE marketing_asset_draft_id = ?',
        [$draftId]
    ),
    'and the draft reaches RELEASED_INTERNAL — the strongest state the vocabulary has',
);

Assert::same(
    $releasedOut->value()['decision_record_id'],
    (string) $db->scalar(
        'SELECT decision_record_id FROM released_marketing_assets WHERE released_marketing_asset_id = ?',
        [$releasedOut->value()['released_marketing_asset_id']]
    ),
    'the release reference cites the M10 decision record',
);
Assert::same(
    'ALREADY_RELEASED',
    $release->releaseMarketingAsset($releaseInput)->reasonCode(),
    'and a second release is refused',
);

// ============================================================ the two-gate surfacing

$listFor = static fn (?string $kind = null): array => $release->listVisibleMarketingAssets([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'actor_type' => 'OPERATOR',
    'actor_id' => $scope['operator_id'],
    'asset_kind' => $kind,
    'correlation_id' => Ids::new(),
]);

$noRole = $listFor();
Assert::same('DENIED', $noRole['decision'], 'a caller with no role is denied');
Assert::equalsList([], $noRole['outputs'], 'and sees NOTHING');

$role = Seed::role($db, $clock, 'marketing_reader', ['marketing_asset.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
Assert::equalsList(
    ['TIER_DOES_NOT_GRANT_PERMISSION'],
    $listFor()['reason_codes'],
    'an authorized caller in an unsubscribed tenant is denied on the TIER',
);

$tierId = $entitlements->defineTier('p05_marketing', 'P05 marketing', 5900, ['marketing_asset.read'])
    ->value()['subscription_tier_id'];
$entitlements->subscribe($scope['tenant_id'], $tierId);

$visible = $listFor();
Assert::same('VISIBLE', $visible['decision'], 'with both gates passed the asset renders');
Assert::same(1, count($visible['outputs']), 'exactly one');
Assert::same(
    'Price stability, in writing',
    (string) $visible['outputs'][0]['title_safe'],
    'carrying its safe title',
);

Assert::same(1, count($listFor('LANDING_PAGE_VARIANT')['outputs']), 'the kind filter narrows to a match');
Assert::equalsList([], $listFor('CAMPAIGN_ANGLE')['outputs'], 'and excludes a non-match');
// An unreadable filter narrows to nothing rather than being ignored — dropping it silently would
// return MORE than the caller asked for.
Assert::equalsList(
    ['UNKNOWN_ASSET_KIND'],
    $listFor('BILLBOARD')['reason_codes'],
    'and an unrecognized kind fails closed rather than being dropped',
);
