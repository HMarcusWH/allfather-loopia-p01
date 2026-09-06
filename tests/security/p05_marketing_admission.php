<?php

declare(strict_types=1);

/**
 * P05 marketing admission and content compliance, proved by REVERSION.
 *
 * Five admission rules, two release-boundary re-checks, three audits. Each is isolated — one bent
 * input at a time — so a passing suite cannot be one rule covering for another.
 *
 * The two claims worth stating plainly, because they are what the whole plane is for:
 *
 *   A comparative claim with nothing behind it never reaches a verdict. Not "is flagged", not "is
 *   reviewed more carefully" — it BLOCKS, because the claim was never available to make.
 *
 *   Copy edited after it was cleared never reaches a release. The digest is recomputed at the
 *   boundary and compared to the one frozen at composition, so the check needs no opinion about
 *   what the new words say. They are simply not the words that were admitted.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P05\Assurance as P05Assurance;
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
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
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
$m11 = new M11Assurance($db, $clock, $ledger);

$assets = new MarketingAssets($db, $clock, $ledger);
$admission = new MarketingAdmission(
    $db, $clock, $evidence, $readiness, $proposals, $council, $arbiter, $ledger
);
$release = new P05Release(
    $db, $clock, $ledger, new AccessGuard($db, $clock), new Entitlements($db, $clock)
);
$assurance = new P05Assurance($db, $clock, $m11);

$policySetId = (new PolicySets($db, $clock))->seed(
    PolicySets::P05_MARKETING_ASSET_ADMISSION, $scope['tenant_id'], $scope['workspace_id']
)->value()['arbiter_policy_set_id'];

$neutralCopy = 'We publish our list price and hold it for the contract term.';
$comparativeCopy = 'Unlike the competitor, we hold our list price for the whole contract term.';

/**
 * Drive one draft from a fresh released source to a verdict.
 *
 * Each case gets its OWN source chain, so a mutation made for one rule cannot leak into another.
 * `$mutate` runs AFTER the submission is frozen and BEFORE the verdict — the window the rules
 * govern.
 */
$runToVerdict = static function (
    array $overrides = [],
    ?callable $mutate = null,
) use ($db, $clock, $scope, $assets, $admission, $council, $evidence, $policySetId): array {
    $src = Seed::p01ReleasedBriefing($db, $clock, $scope);
    $draftId = $assets->compose([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'asset_kind' => 'LANDING_PAGE_VARIANT',
        'source_kind' => 'P01_BRIEFING',
        'source_id' => $src['released_briefing_id'],
        'title_safe' => $overrides['title_safe'] ?? 'Price stability, in writing',
        'draft_content_safe' => $overrides['draft_content_safe'],
    ])->value()['marketing_asset_draft_id'];

    // A claim citation, when the case calls for one. It cites a claim basis minted for THIS draft,
    // which is the lineage the citation audit recognizes.
    if (($overrides['cite_claim'] ?? false) === true) {
        $basis = $assets->captureClaimBasis([
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'marketing_asset_draft_id' => $draftId,
            'claim_tag' => 'price-hold',
            'basis_summary_safe' => 'Competitor list price rose; ours is contractually held.',
            'mission_id' => $src['mission_id'],
        ], $evidence);
        $assets->addClaimCitation([
            'tenant_id' => $scope['tenant_id'],
            'marketing_asset_draft_id' => $draftId,
            'claim_text_safe' => 'Unlike the competitor, our list price is held for the contract term.',
            'cited_evidence_id' => $basis->value()['evidence_id'],
        ]);
    }

    // A citation whose own claim text is NOT comparative. Added through the real path, before
    // submission, because the gate closes the draft the moment it is submitted.
    if (isset($overrides['extra_claim'])) {
        $extraBasis = $assets->captureClaimBasis([
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'marketing_asset_draft_id' => $draftId,
            'claim_tag' => 'unrelated',
            'basis_summary_safe' => 'Our uptime figure for the last quarter.',
            'mission_id' => $src['mission_id'],
        ], $evidence);
        $assets->addClaimCitation([
            'tenant_id' => $scope['tenant_id'],
            'marketing_asset_draft_id' => $draftId,
            'claim_text_safe' => (string) $overrides['extra_claim'],
            'cited_evidence_id' => $extraBasis->value()['evidence_id'],
        ]);
    }

    $submitted = $admission->submitDraftProposal([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'marketing_asset_draft_id' => $draftId,
        'governance' => [
            'mission_id' => $src['mission_id'],
            'producing_valkyrie_id' => $src['producing_valkyrie_id'],
            'capability_contract_id' => $src['capability_contract_id'],
        ],
    ]);

    $sessionId = $admission->openAssetReview([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'marketing_asset_draft_id' => $draftId,
        'initiated_by_actor_type' => 'OPERATOR',
        'initiated_by_actor_id' => $scope['operator_id'],
    ])->value()['review_session_id'];
    $assignmentId = $council->assignParticipant(
        $sessionId, $src['critic_valkyrie_id'], 'EVIDENCE_CRITIC', 'INDEPENDENT_DECLARED_REVIEWER'
    )->value()['participant_assignment_id'];
    $council->recordAssessment(
        $sessionId, $assignmentId, $src['critic_valkyrie_id'], 'SUPPORT_PROCEED_TO_ARBITER', 'Reviewed.'
    );
    $council->freezeResult($sessionId, 'PROCEED_TO_ARBITER', 'Council result.');

    if ($mutate !== null) {
        $mutate($draftId, $src);
    }

    $verdict = $admission->issueVerdict([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'marketing_asset_draft_id' => $draftId,
        'arbiter_policy_set_id' => $policySetId,
    ]);

    return [
        'draft_id' => $draftId,
        'proposal_id' => $submitted->isOk() ? $submitted->value()['proposal_id'] : null,
        'source' => $src,
        'verdict' => $verdict,
    ];
};

// ============================================================ the clean baseline

$clean = $runToVerdict(['draft_content_safe' => $neutralCopy]);
Assert::same('ADMITTED', $clean['verdict']->value()['outcome'], 'neutral copy is admitted');
Assert::same(
    'ALLOW_INFORMATIONAL_RELEASE',
    $clean['verdict']->value()['verdict'],
    'with an informational-release verdict',
);

// ============================================================ rule 1: the uncited comparative claim

$uncited = $runToVerdict(['draft_content_safe' => $comparativeCopy]);
$uncitedValue = $uncited['verdict']->value();
Assert::same('REFUSED', $uncitedValue['outcome'], 'copy comparing against a competitor with no citation is refused');
Assert::true(
    in_array('P05_UNCITED_COMPETITOR_CLAIM', $uncitedValue['reason_codes'], true),
    'named as an uncited competitor claim',
);
Assert::same(
    'BLOCK',
    $uncitedValue['verdict'],
    'and BLOCKS — rewording would not supply the evidence it lacks',
);
Assert::true($uncitedValue['draft_blocked'], 'closing the draft');

// The SAME copy WITH a citation is admitted. That is what makes this a gate rather than a ban on
// comparative marketing.
$citedRun = $runToVerdict(['draft_content_safe' => $comparativeCopy, 'cite_claim' => true]);
Assert::same(
    'ADMITTED',
    $citedRun['verdict']->value()['outcome'],
    'the same claim citing governed evidence is admitted',
);

// A citation whose own claim text is NOT comparative does not satisfy a comparative claim. Citing
// evidence for something else is not citing this.
$mismatchedCitation = $runToVerdict([
    'draft_content_safe' => $comparativeCopy,
    'extra_claim' => 'Our uptime was 99.98% last quarter.',
]);
Assert::true(
    in_array('P05_UNCITED_COMPETITOR_CLAIM', $mismatchedCitation['verdict']->value()['reason_codes'], true),
    'a citation for a different claim does not satisfy a comparative one',
);

// ============================================================ rule 2: forbidden patterns

$forbidden = [
    'a dark pattern' => 'Use a fake scarcity timer on the pricing page.',
    'manufactured urgency' => 'Headline: only 3 minutes left to claim this rate.',
    'trait targeting' => 'Target pregnant visitors with the premium bundle.',
    'trait justification' => 'Show the discount based on religion of the visitor.',
];
foreach ($forbidden as $shape => $copy) {
    $bad = $runToVerdict(['draft_content_safe' => $copy]);
    $value = $bad['verdict']->value();
    Assert::same('REFUSED', $value['outcome'], "copy carrying {$shape} is refused");
    Assert::true(
        in_array('P05_FORBIDDEN_MARKETING_PATTERN', $value['reason_codes'], true),
        'named as a forbidden marketing pattern',
    );
    Assert::same('BLOCK', $value['verdict'], 'and BLOCKS — no citation makes this copy acceptable');
    Assert::same(
        1,
        (int) $db->scalar(
            'SELECT incident_evaluation_required FROM arbiter_verdicts WHERE arbiter_verdict_id = ?',
            [$value['arbiter_verdict_id']]
        ),
        'opening an incident: copy like this reaching the arbiter is a question about how it was produced',
    );
}

// ============================================================ rule 3: the source must still hold

$withdrawn = $runToVerdict(
    ['draft_content_safe' => $neutralCopy],
    static function (string $draftId, array $src) use ($ledger): void {
        $ledger->supersede(
            $src['decision_record_id'],
            'INVALIDATED_FOR_CURRENT_RELIANCE',
            'M11',
            $src['released_briefing_id'],
            'Source withdrawn between submission and verdict.',
            'INVALIDATED_FOR_CURRENT_RELIANCE',
        );
    }
);
$withdrawnValue = $withdrawn['verdict']->value();
Assert::same('REFUSED', $withdrawnValue['outcome'], 'a draft whose source was withdrawn is refused');
Assert::true(
    in_array('SOURCE_NOT_RELEASED', $withdrawnValue['reason_codes'], true),
    'naming the withdrawn release',
);

// ============================================================ rule 4: only an IN_REVIEW draft

$notInReview = $runToVerdict(
    ['draft_content_safe' => $neutralCopy],
    static function (string $draftId) use ($db): void {
        $db->run(
            "UPDATE marketing_asset_drafts SET asset_status = 'DRAFT' WHERE marketing_asset_draft_id = ?",
            [$draftId]
        );
    }
);
// The module's own guard catches this before the arbiter is ever consulted — a verdict is issued
// about an asset that was submitted for one, and this is not that.
Assert::same(
    'DRAFT_NOT_IN_REVIEW',
    $notInReview['verdict']->reasonCode(),
    'a draft moved back out of review never reaches an evaluation',
);

// ============================================================ rule 5: the evidence basis

$noBasis = $runToVerdict(
    ['draft_content_safe' => $neutralCopy],
    static function (string $draftId) use ($db): void {
        $db->run(
            'UPDATE marketing_asset_drafts SET basis_evidence_id = NULL WHERE marketing_asset_draft_id = ?',
            [$draftId]
        );
    }
);
$noBasisValue = $noBasis['verdict']->value();
Assert::same('REFUSED', $noBasisValue['outcome'], 'a draft whose basis was cleared is refused');
Assert::true(
    in_array('P05_EVIDENCE_BASIS_MISSING', $noBasisValue['reason_codes'], true),
    'naming the missing basis',
);
Assert::same(
    'REQUIRE_MORE_EVIDENCE',
    $noBasisValue['verdict'],
    'asking for evidence rather than blocking',
);
// And it is the ONE non-allow verdict that leaves the draft standing. The other four close it,
// because revising marketing copy produces different copy — but "bring the missing basis" is a
// repair that leaves the copy itself exactly as the reviewer read it.
Assert::false($noBasisValue['draft_blocked'], 'the draft is NOT closed — this is the repairable case');
Assert::same(
    'IN_REVIEW',
    (string) $db->scalar(
        'SELECT asset_status FROM marketing_asset_drafts WHERE marketing_asset_draft_id = ?',
        [$noBasis['draft_id']]
    ),
    'it stays in review, awaiting the basis the verdict named',
);

// ============================================================ the release-boundary re-checks

$edited = $runToVerdict(['draft_content_safe' => $neutralCopy]);
Assert::same('ADMITTED', $edited['verdict']->value()['outcome'], 'the draft is admitted cleanly');
$db->run(
    'UPDATE marketing_asset_drafts SET draft_content_safe = ? WHERE marketing_asset_draft_id = ?',
    ['We are cheaper than every alternative on the market.', $edited['draft_id']]
);
Assert::same(
    'ASSET_DIGEST_CHANGED_AT_RELEASE',
    $release->releaseMarketingAsset([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'marketing_asset_draft_id' => $edited['draft_id'],
    ])->reasonCode(),
    'copy edited after its verdict does not reach a release',
);
Assert::same(
    0,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM released_marketing_assets WHERE marketing_asset_draft_id = ?',
        [$edited['draft_id']]
    ),
    'and NOTHING was released',
);

// Even editing the TITLE alone changes the digest. The check is about identity, not about whether
// the edit looks harmful.
$titleEdited = $runToVerdict(['draft_content_safe' => $neutralCopy]);
$db->run(
    'UPDATE marketing_asset_drafts SET title_safe = ? WHERE marketing_asset_draft_id = ?',
    ['A different headline', $titleEdited['draft_id']]
);
Assert::same(
    'ASSET_DIGEST_CHANGED_AT_RELEASE',
    $release->releaseMarketingAsset([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'marketing_asset_draft_id' => $titleEdited['draft_id'],
    ])->reasonCode(),
    'a changed title is a changed asset — the check is identity, not harm',
);

// And the source re-check at the boundary.
$retracted = $runToVerdict(['draft_content_safe' => $neutralCopy]);
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
    $release->releaseMarketingAsset([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'marketing_asset_draft_id' => $retracted['draft_id'],
    ])->reasonCode(),
    'a source withdrawn after the verdict stops the release',
);

// ============================================================ the audits

$citationProfile = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p05.citation', 'MARKETING_EVIDENCE_CITATION',
    'P05 evidence-cited claim'
);
$draftOnlyProfile = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p05.draftonly', 'MARKETING_DRAFT_ONLY_INVARIANT',
    'P05 draft-only invariant'
);
$contentProfile = $m11->createAuditProfile(
    $scope['tenant_id'], $scope['workspace_id'], 'p05.content', 'MARKETING_CONTENT_COMPLIANCE',
    'P05 content compliance'
);

// A profile of the wrong category cannot back a finding it never asked for.
Assert::same(
    'AUDIT_PROFILE_CATEGORY_NOT_MARKETING_EVIDENCE_CITATION',
    $assurance->runEvidenceCitedClaimAudit(['audit_profile_id' => $contentProfile])->reasonCode(),
    'an audit profile of the wrong category cannot back a citation finding',
);

// The CONTENT audit catches forbidden copy that persisted, drafts and variants alike. The blocked
// drafts written above are still in the table — a BLOCKED draft is still copy somebody could read.
$content = $assurance->runForbiddenPatternAudit(['audit_profile_id' => $contentProfile]);
Assert::same(
    'OVERCLAIM_OR_UI_MISREPRESENTATION',
    $content->value()['finding_type'],
    'the content audit finds the forbidden copy still sitting in the table',
);
Assert::equalsList(
    ['P05_FORBIDDEN_MARKETING_PATTERN_PERSISTED'],
    $content->value()['reason_codes'],
    'named precisely',
);
Assert::same(
    4,
    $content->value()['violation_count'],
    'all four — each forbidden shape is found, not just the first',
);
Assert::notSame(null, $content->value()['incident_case_id'], 'and an incident is opened');

// A VARIANT is audited too. It never passes the admission gate on its own, which makes it the
// likelier place for non-compliant copy to sit unnoticed.
$variantSrc = Seed::p01ReleasedBriefing($db, $clock, $scope);
$variantDraft = $assets->compose([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'asset_kind' => 'COPY_VARIANT',
    'source_kind' => 'P01_BRIEFING',
    'source_id' => $variantSrc['released_briefing_id'],
    'title_safe' => 'Variant carrier',
    'draft_content_safe' => $neutralCopy,
])->value()['marketing_asset_draft_id'];
$badVariant = $assets->addVariant([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'marketing_asset_draft_id' => $variantDraft,
    'variant_key' => 'urgency',
    'variant_content_safe' => 'Only 2 minutes left to claim this rate.',
])->value()['marketing_asset_variant_id'];
Assert::true(
    in_array(
        $badVariant,
        $assurance->runForbiddenPatternAudit(['audit_profile_id' => $contentProfile])->value()['violating_refs'],
        true
    ),
    'a variant carrying manufactured urgency is a finding of its own',
);

// The DRAFT-ONLY audit is clean, because nothing in this plane can produce an external effect.
$draftOnly = $assurance->runDraftOnlyInvariantAudit(['audit_profile_id' => $draftOnlyProfile]);
Assert::same(
    'CONTROL_PASS',
    $draftOnly->value()['finding_type'],
    'nothing external has happened — there is no path in this plane that could',
);

// Write the trace an external act WOULD leave, and the audit finds it. This is the check for a
// failure nothing in the codebase explains, so it is tested by writing what nothing writes.
$db->insert('marketing_asset_draft_status_transitions', [
    'transition_id' => Ids::new(),
    'schema_version' => '1',
    'marketing_asset_draft_id' => $variantDraft,
    'from_status' => 'DRAFT',
    'to_status' => 'DRAFT',
    'reason_code' => 'CRM_WRITEBACK_COMPLETED',
    'transitioned_at' => $clock->now(),
]);
$drift = $assurance->runDraftOnlyInvariantAudit(['audit_profile_id' => $draftOnlyProfile]);
Assert::same(
    'RELEASE_BLOCKING_CONDITION',
    $drift->value()['finding_type'],
    'a transition naming a CRM writeback is caught even under an innocuous status',
);
Assert::equalsList(
    ['P05_ILLEGAL_EXTERNAL_STATUS_TRANSITION'],
    $drift->value()['reason_codes'],
    'named as an illegal external transition',
);
Assert::same(
    'S4',
    (string) $db->scalar(
        'SELECT severity FROM audit_findings WHERE audit_finding_id = ?',
        [$drift->value()['audit_finding_id']]
    ),
    'at S4 — an external effect in a plane with no external path is not a content problem',
);

// The CITATION audit: a comparative draft whose citation has no lineage to it.
$strayCitationDraft = $runToVerdict(['draft_content_safe' => $comparativeCopy, 'cite_claim' => true]);
$strayBasis = $assets->captureClaimBasis([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'marketing_asset_draft_id' => $variantDraft,
    'claim_tag' => 'stray',
    'basis_summary_safe' => 'A basis belonging to a different draft.',
], $evidence);
$db->insert('marketing_claim_citations', [
    'marketing_claim_citation_id' => Ids::new(),
    'schema_version' => '1',
    'tenant_id' => $scope['tenant_id'],
    'marketing_asset_draft_id' => $strayCitationDraft['draft_id'],
    'claim_text_safe' => 'Compared to the competitor, our renewal terms are fixed.',
    'cited_evidence_id' => $strayBasis->value()['evidence_id'],
    'created_at' => $clock->now(),
]);
$citation = $assurance->runEvidenceCitedClaimAudit(['audit_profile_id' => $citationProfile]);
Assert::true(
    in_array('P05_CITED_EVIDENCE_UNRELATED', $citation->value()['reason_codes'], true),
    'evidence about a DIFFERENT draft is real, in scope, fresh — and still not a citation of this claim',
);
Assert::notSame(null, $citation->value()['incident_case_id'], 'and an incident is opened');

// ============================================================ scope

$otherScope = Seed::tenantWorkspace($db, $clock, 'rival');
$foreign = $runToVerdict(['draft_content_safe' => $neutralCopy]);
$foreignInput = [
    'tenant_id' => $otherScope['tenant_id'],
    'workspace_id' => $otherScope['workspace_id'],
    'marketing_asset_draft_id' => $foreign['draft_id'],
];
Assert::same(
    'DRAFT_SCOPE_MISMATCH',
    $admission->submitDraftProposal($foreignInput + ['governance' => [
        'mission_id' => Ids::new(),
        'producing_valkyrie_id' => Ids::new(),
        'capability_contract_id' => Ids::new(),
    ]])->reasonCode(),
    'a foreign scope cannot submit another tenant\'s draft',
);
Assert::same(
    'DRAFT_SCOPE_MISMATCH',
    $admission->issueVerdict($foreignInput + ['arbiter_policy_set_id' => $policySetId])->reasonCode(),
    'nor evaluate it',
);
Assert::same(
    'DRAFT_SCOPE_MISMATCH',
    $release->releaseMarketingAsset($foreignInput)->reasonCode(),
    'nor release it',
);
