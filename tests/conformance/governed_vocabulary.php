<?php

declare(strict_types=1);

/**
 * CONFORMANCE: the closed vocabularies, compared against canonical MEMBER BY MEMBER.
 *
 * `UPSTREAM_AUTHORITY.md` draws one line through everything conformance does: identifiers and
 * timestamps are normalized, and "reason codes, policy decisions, interpretation kind, release
 * eligibility, visibility, scope denial, current reliance and audit outcome are **never**
 * normalized — they are the claim". This file is that rule applied to the vocabularies those
 * claims are drawn from.
 *
 * WHY A WHOLE FILE FOR LISTS OF STRINGS. Upstream holds these as TypeScript union types, and its
 * compiler then proves exhaustively that every consumer handles every member — a member added
 * upstream cannot reach production unhandled. PHP has no equivalent check anywhere, so every union
 * crossing the port boundary loses the only thing that was keeping it honest. Vocabulary drift is
 * therefore the failure mode this port is most exposed to, and it is silent: a missing member is
 * not a crash, it is a state nothing refuses.
 *
 * The comparison is against the canonical source ITSELF where this repository can reach it — the
 * expected lists below are transcribed from `release-output/vocabulary.ts`, `action-class.ts` and
 * `ingestion/policy.ts`, and each says which. Where a vocabulary is also pinned in storage, the
 * CHECK constraint is compared too: a constant and a constraint that disagree is a gate that
 * refuses in one layer and admits in the other.
 */

use Allfather\Loopia\Core\ActionClass;
use Allfather\Loopia\Ingestion\SourcePolicy;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Tests\Seed;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

/** The members a storage CHECK on one column admits, read back from the server. */
$checkMembers = static function (string $table, string $column) use ($db): array {
    $clauses = $db->all(
        'SELECT cc.CHECK_CLAUSE AS clause
         FROM information_schema.CHECK_CONSTRAINTS cc
         WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND cc.TABLE_NAME = ?
           AND cc.CHECK_CLAUSE LIKE ?',
        [$table, '%`' . $column . '`%']
    );
    $members = [];
    $quoted = preg_quote($column, '/');
    foreach ($clauses as $row) {
        $clause = (string) $row['clause'];
        // TWO SHAPES, because MariaDB rewrites a single-member IN as an equality — and a
        // vocabulary of exactly one member is the strictest kind there is, so reading only the
        // IN form would silently skip the pins that matter most.
        if (preg_match('/`' . $quoted . '`\s+in\s+\((.*?)\)/is', $clause, $m) === 1) {
            preg_match_all("/'([^']+)'/", $m[1], $literals);
            foreach ($literals[1] as $literal) {
                $members[] = $literal;
            }
        } elseif (preg_match("/`" . $quoted . "`\s*=\s*'([^']+)'/i", $clause, $m) === 1) {
            $members[] = $m[1];
        }
        // Anything else on this column — a NOT NULL pairing, a cross-column rule — is not the
        // vocabulary and is deliberately not read as one.
    }
    $members = array_values(array_unique($members));
    sort($members);

    return $members;
};

$sorted = static function (array $values): array {
    $copy = array_values($values);
    sort($copy);

    return $copy;
};

// ============================================================ the action-class ladder

// Canonical: `src/action-class.ts` — CANONICAL_ACTION_CLASSES, inner to wider. The ORDER is the
// law, not just the membership: every ceiling comparison in four planes is a rank comparison over
// this list, so a reordering would silently re-rank every one of them.
Assert::same(
    [
        'OBSERVE',
        'ANALYZE',
        'RELEASE_INTELLIGENCE',
        'PREPARE_EXTERNAL_ACTION',
        'EXECUTE_CONSEQUENTIAL_ACTION',
    ],
    ActionClass::CANONICAL,
    'the action-class ladder matches canonical, in canonical ORDER',
);
Assert::same(
    ['OBSERVE', 'ANALYZE'],
    ActionClass::DISPATCHABLE_IN_PROJECTION,
    'and only the two read classes are dispatchable here — C releases, it does not dispatch',
);
Assert::true(
    ActionClass::rank('RELEASE_INTELLIGENCE') < ActionClass::rank('PREPARE_EXTERNAL_ACTION'),
    'the projection ceiling sits BELOW both external classes',
);
Assert::same(null, ActionClass::rank('SEND'), 'and a class outside the ladder has no rank at all');

// ============================================================ M16 release-output vocabulary

// Canonical: `src/release-output/vocabulary.ts`. Seven unions, transcribed member for member.
$canonicalM16 = [
    'AudienceClass' => [
        'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE',
        'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM', 'BLOCKED_QUARANTINED',
    ],
    'AudienceOrderInnerToWider' => [
        'OPERATOR_ONLY', 'TENANT_INTERNAL', 'CUSTOMER_VISIBLE',
        'EXPORTABLE', 'API_VISIBLE', 'CHANNEL_EMAIL_SMS_CRM',
    ],
    'ReleaseArtifactKind' => [
        'COMPANY_SIGNAL_BRIEFING', 'BATTLECARD_BRIEFING',
        'ENTITY_TWIN_SNAPSHOT_VIEW', 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW',
    ],
    'ReleaseArtifactStatus' => [
        'DRAFT', 'READY_FOR_RELEASE', 'RELEASED_INTERNAL', 'RELEASED_CUSTOMER_VISIBLE',
        'HELD_SOURCE_USE', 'HELD_FRESHNESS', 'HELD_CONFLICT', 'SUPERSEDED', 'ROLLED_BACK', 'QUARANTINED',
    ],
    'ClaimCeiling' => [
        'INTERNAL_DRAFT', 'INTERNAL_REVIEWED', 'INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS',
        'SUPERSEDED', 'RETRACTED',
    ],
];
$portedM16 = [
    'AudienceClass' => ReleaseOutput::AUDIENCE_CLASS,
    'AudienceOrderInnerToWider' => ReleaseOutput::AUDIENCE_ORDER,
    'ReleaseArtifactKind' => ReleaseOutput::ARTIFACT_KIND,
    'ReleaseArtifactStatus' => ReleaseOutput::ARTIFACT_STATUS,
    'ClaimCeiling' => ReleaseOutput::CLAIM_CEILING,
];
foreach ($canonicalM16 as $union => $members) {
    Assert::same($members, $portedM16[$union], "M16 {$union} matches canonical member for member, in order");
}

// The pins that make the wider audiences DESIGNED_INERT rather than merely unused, and they are
// storage-level: an application constant can be edited by anyone who can edit the code.
Assert::same(
    ['OPERATOR_ONLY', 'TENANT_INTERNAL'],
    ReleaseOutput::ACTIVATABLE_AUDIENCES,
    'only the two internal audiences may ever be activated',
);
Assert::same(
    $sorted($canonicalM16['AudienceOrderInnerToWider']),
    $checkMembers('customer_output_projections', 'audience_class'),
    'the projection table CHECK admits exactly the LADDER — the pseudo-audience appears in no CHECK',
);
Assert::same(
    $sorted($canonicalM16['ReleaseArtifactStatus']),
    $checkMembers('release_artifacts', 'status'),
    'and the artifact status CHECK is the canonical status union, RELEASED_CUSTOMER_VISIBLE included',
);
// Enum-listed but unreachable: the designed-inert pattern. The member exists so the vocabulary is
// canonical; a SECOND constraint is what makes it unreachable.
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'release_artifacts'
           AND CHECK_CLAUSE LIKE '%RELEASED_CUSTOMER_VISIBLE%' AND CHECK_CLAUSE NOT LIKE '%DRAFT%'"
    ),
    'RELEASED_CUSTOMER_VISIBLE is enum-listed AND separately pinned unreachable — the two are different claims',
);

// ============================================================ the ingestion policy vocabulary

// Canonical: `src/ingestion/policy.ts` — PolicyFailClosedReasonCode, fourteen members. The P01
// adapter's coverage of these is proved in `p01_source_policy_adapter.php`; what is proved here is
// that the vocabulary itself has not drifted from canonical.
Assert::same(
    $sorted([
        'SOURCE_CARD_NOT_APPROVED', 'SOURCE_CARD_REVIEW_EXPIRED', 'SOURCE_USE_PROHIBITED',
        'SOURCE_LICENSE_PROHIBITED', 'SOURCE_RETENTION_PROHIBITED', 'SOURCE_REFERENCE_NOT_ACTIVE',
        'SOURCE_SENSITIVITY_PROHIBITED', 'TERMS_RECHECK_OVERDUE', 'ROBOTS_NOT_CHECKED',
        'SOURCE_URL_MISSING_OR_INVALID', 'SOURCE_TYPE_UNSUPPORTED', 'CONNECTOR_NOT_ACTIVATED',
        'CONNECTOR_CLASS_NOT_PROMOTED', 'SOURCE_SCOPE_MISMATCH', 'SOURCE_CONTRACT_REQUIRED_BUT_MISSING',
    ]),
    $sorted(SourcePolicy::FAIL_CLOSED_REASON_CODES),
    'the compiler\'s fail-closed vocabulary is canonical\'s fourteen members, no more and no fewer',
);
Assert::same(
    'ing_policy_compiler_v3',
    SourcePolicy::COMPILER_VERSION,
    'and the compiler version is canonical\'s — a replay compares digests across versions',
);

// ============================================================ the claims that are storage-pinned

// Each of these is a SENTENCE the product is allowed to say, and each is a closed column
// vocabulary rather than a rule in code. A vocabulary is the strongest form of these claims: a
// state that has no name cannot be reached by any path, including one written later.
foreach ([
    ['fit_hypotheses', 'claim_kind', ['FIT_HYPOTHESIS', 'REASON_TO_INVESTIGATE'],
     'a fit is a reason to investigate — there is no member meaning "wants to buy"'],
    ['stakeholder_role_observations', 'subject_kind', ['ROLE_CLASS'],
     'a stakeholder observation names a ROLE CLASS and has no member naming a person'],
    ['company_signals', 'interpretation_kind', ['FACT_OBSERVED', 'HYPOTHESIS'],
     'a signal is an observation or a hypothesis, and the two are never the same word'],
] as [$table, $column, $expected, $because]) {
    Assert::same($sorted($expected), $checkMembers($table, $column), "{$table}.{$column}: {$because}");
}

// The marketing status vocabulary is the converse claim: what is proved is an ABSENCE. No
// `PUBLISHED` member exists, so "publish" is not a state this system can be argued into.
$marketingStatuses = $checkMembers('marketing_asset_drafts', 'asset_status');
Assert::true($marketingStatuses !== [], 'the marketing draft status vocabulary is CHECK-pinned');
foreach (['PUBLISHED', 'SENT', 'DELIVERED', 'EXPORTED', 'LIVE'] as $absent) {
    Assert::false(
        in_array($absent, $marketingStatuses, true),
        "no marketing draft can reach {$absent} — the vocabulary has no such member",
    );
}

// ============================================================ the entitlement vocabulary

// A tier grants READ authority and nothing else. A permission naming an external effect in this
// list would be a PURCHASABLE capability that the four refusal planes then have to catch — and a
// capability you can buy is one somebody will eventually argue was sold to them.
$tierPermissions = (new ReflectionClass(\Allfather\Loopia\Product\P01\Registration::class))
    ->getConstant('TIER_PERMISSIONS');
Assert::true($tierPermissions !== [] && $tierPermissions !== false, 'the P01 tier names its permissions');
foreach ((array) $tierPermissions as $permission) {
    Assert::true(
        str_ends_with((string) $permission, '.read') || str_ends_with((string) $permission, '.review'),
        "the P01 tier grants only read or review authority: {$permission}",
    );
}

// And the platform vocabulary a tier may draw from excludes the external-effect permissions
// outright — the commercial plane cannot sell an authority the governance plane refuses to define.
$excluded = (new ReflectionClass(\Allfather\Loopia\Substrate\M02\Permissions::class))
    ->getConstant('EXCLUDED');
Assert::true($excluded !== false && $excluded !== [], 'the excluded-permission set is declared');
foreach ((array) $tierPermissions as $permission) {
    Assert::false(
        in_array($permission, (array) $excluded, true),
        "no tier permission is an excluded one: {$permission}",
    );
}

// ============================================================ the M16 adoption boundary

// M10 owns RELEASE for every module — one authority, one place a release can be revoked from. M16
// is an adopted governed OUTPUT projection, and the adopting families are P01 and P02. An earlier
// revision of this port asserted "exactly one ported family adopts M16", which was a statement
// about the port's own omission rather than about canonical law, and the Sources plane was then
// written to match it. Both are corrected here.

// THE ARTIFACT VOCABULARY IS EXACTLY FOUR KINDS. Not three, not five.
$kinds = ReleaseOutput::ARTIFACT_KIND;
sort($kinds);
Assert::equalsList(
    ['BATTLECARD_BRIEFING', 'COMPANY_SIGNAL_BRIEFING', 'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW', 'ENTITY_TWIN_SNAPSHOT_VIEW'],
    $kinds,
    'M16 admits exactly the four canonical artifact kinds',
);
$storageKinds = $checkMembers('release_artifacts', 'artifact_kind');
sort($storageKinds);
Assert::equalsList(
    $kinds,
    $storageKinds,
    'and the storage CHECK admits exactly the same four — constant and constraint agree',
);

// WHICH PRODUCT FAMILY REACHES M16, read from the source rather than from a diagram.
$m16Consumers = [];
foreach (glob(dirname(__DIR__, 2) . '/app/Product/*/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);
    // A mention in a docblock is not an adoption. Only a constructor-injected dependency or a
    // direct call is, which is what distinguishes "explains the boundary" from "crosses it".
    if (preg_match('/(P02Adapter|ReleaseOutput)\s+\$/', $source) === 1) {
        $m16Consumers[] = basename(dirname($file));
    }
}
$m16Consumers = array_values(array_unique($m16Consumers));
sort($m16Consumers);
Assert::equalsList(
    ['P01', 'P02'],
    $m16Consumers,
    'exactly two product families adopt M16 — P01 and P02, as canonical does',
);

// P03, P04 and P05 use their OWN canonical release paths and must not be forced through M16.
foreach (['P03', 'P04', 'P05'] as $unadopted) {
    Assert::false(
        in_array($unadopted, $m16Consumers, true),
        "{$unadopted} does not route through M16 — it has its own canonical release path",
    );
}

// Each module's release projection references an M10 decision record. That is the shape: ONE
// release authority, several projections.
foreach ([
    'released_briefings' => 'P01',
    'released_entity_twin_snapshots' => 'P02 entity',
    'released_entity_relationship_snapshots' => 'P02 relationship',
    'released_prospect_outputs' => 'P03',
    'released_distribution_outputs' => 'P04',
    'released_marketing_assets' => 'P05',
] as $table => $owner) {
    Assert::same(
        1,
        (int) $db->scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'decision_record_id'",
            [$table]
        ),
        "{$owner}: its release projection references an M10 decision record",
    );
}

// The two P02 artifact kinds are consumer-referenced by their own snapshot columns, so an artifact
// can never claim to be about a snapshot family it does not belong to.
foreach ([
    'ENTITY_TWIN_SNAPSHOT_VIEW' => 'released_entity_twin_snapshot_id',
    'ENTITY_RELATIONSHIP_SNAPSHOT_VIEW' => 'released_relationship_snapshot_id',
] as $kind => $column) {
    Assert::same(
        1,
        (int) $db->scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'release_artifacts' AND COLUMN_NAME = ?",
            [$column]
        ),
        "{$kind} has its own consumer reference column",
    );
}

// The per-family access rule is what keeps two adopting families from inheriting each other's
// permission. The table must exist AND be keyed by artifact kind — an authority row that did not
// name its family is the under-keyed authority this boundary depends on not having.
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'output_access_rules'
           AND COLUMN_NAME = 'artifact_kind'"
    ),
    'output_access_rules is keyed by artifact kind, so one family cannot govern another',
);
$ruleKinds = $checkMembers('output_access_rules', 'artifact_kind');
sort($ruleKinds);
Assert::equalsList(
    $kinds,
    $ruleKinds,
    'and it admits the same four kinds — no family can be registered that M16 cannot produce',
);

// ============================================================ the P02 release vocabularies

// One constant, three readers. `TwinStateChange` validates the REQUEST, `EntityTwins` validates the
// RELEASE, and `TwinRelease` validates the read LENS — against the same list, because a scope one
// module accepts and another rejects is a hole in a default-deny that no single test would see.
foreach ([
    ['entity_twin_state_change_proposals', 'requested_visibility_scope', EntityTwins::VISIBILITY_SCOPES],
    ['released_entity_twin_snapshots', 'visibility_scope', EntityTwins::VISIBILITY_SCOPES],
    ['entity_twin_state_change_proposals', 'requested_release_status', EntityTwins::RELEASE_STATUSES],
] as [$table, $column, $constant]) {
    $members = $checkMembers($table, $column);
    sort($members);
    $expected = $constant;
    sort($expected);
    Assert::equalsList(
        $expected,
        $members,
        "{$table}.{$column}: the PHP constant and the storage CHECK admit exactly the same members",
    );
}

// The release STATUS column on the snapshot admits two members the request vocabulary does not:
// SUPERSEDED and RETRACTED are OUTCOMES of later events, never something a caller may ask for. A
// constant that grew to match this column would let a caller request its own retraction.
$snapshotStatuses = $checkMembers('released_entity_twin_snapshots', 'release_status');
foreach (['SUPERSEDED', 'RETRACTED', 'BLOCKED'] as $outcomeOnly) {
    Assert::true(
        in_array($outcomeOnly, $snapshotStatuses, true),
        "released_entity_twin_snapshots.release_status admits {$outcomeOnly} as an outcome",
    );
    Assert::false(
        in_array($outcomeOnly, EntityTwins::RELEASE_STATUSES, true),
        "and {$outcomeOnly} is NOT requestable — an outcome is not a request",
    );
}

// The state-change status vocabulary has no released member. A change is BOUND to a released
// snapshot through a column; it never becomes a status, so no code path can mint a release by
// setting one.
$changeStatuses = $checkMembers('entity_twin_state_change_proposals', 'change_status');
sort($changeStatuses);
Assert::equalsList(
    ['BLOCKED', 'DRAFT', 'PENDING_VERDICT', 'VERDICT_ISSUED'],
    $changeStatuses,
    'the state-change statuses stop at VERDICT_ISSUED — RELEASED is M10\'s word, not a status here',
);

// ============================================================ one source-use law, two entry points

// M16 now compiles a source-use profile two ways: from registered CARDS (P01's path) and from
// explicit CONTRIBUTIONS (P02's, because a twin's bases are a mix only the caller can classify).
// Two implementations of one law is how the law drifts, so the agreement is pinned here rather
// than left to the fact that both currently look right.
$m16 = new ReleaseOutput($db, $clock ?? Harness::clock(), new DecisionLedger($db, Harness::clock()));
$scopeForFold = Seed::tenantWorkspace($db, Harness::clock(), 'fold');

// THE TWO PATHS ARE NOT THE SAME LAW, and this says where they differ before asserting where they
// agree. The card path applies one gate the contribution path structurally cannot: the SOURCE's
// capture status. A P02 contribution is not tied to a capture-eligible source — a twin's first-party
// basis was never fetched from anywhere — so there is no status for it to check. What must agree is
// the FOLD: given the same facts about licence, sensitivity, retention and card state, both must
// reach the same verdict.
$permissiveCard = (new SourceCards($db, Harness::clock()))->project([
    'tenant_id' => $scopeForFold['tenant_id'],
    'source_name' => 'Permissive source',
    'source_url_safe' => 'https://example.test/open',
    'source_type' => 'website',
    'allowed_use_class' => 'derived_summary_allowed',
    'license_class' => 'PUBLIC_WEB_ALLOWED',
    'review_status' => 'approved',
    // A public-web source is admissible only once robots and terms have actually been reviewed.
    'robots_policy_checked' => true,
    'terms_policy_checked' => true,
]);
Assert::same(
    'ACTIVE_FOR_CAPTURE',
    (string) $db->scalar(
        'SELECT status FROM source_references WHERE source_ref_id = ?',
        [$permissiveCard['source_ref_id']]
    ),
    'the fixture source is capture-active, so the card path\'s extra gate is not what is being compared',
);
$fromCard = $m16->compileSourceUseProfile(
    $scopeForFold['tenant_id'], $scopeForFold['workspace_id'], [$permissiveCard['source_ref_id']]
)->value();
$fromContribution = $m16->compileSourceUseProfileFromContributions(
    $scopeForFold['tenant_id'],
    $scopeForFold['workspace_id'],
    [[
        'source_license_card_id' => $permissiveCard['source_license_card_id'],
        'source_ref_id' => $permissiveCard['source_ref_id'],
        'license_ceiling' => 'PUBLIC_WEB_ALLOWED',
        'customer_output_allowed' => false,
        'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
        'retention_class' => 'standard',
        'data_ownership' => 'PUBLIC',
        'card_state' => ['approved' => true, 'review_expires_at' => null],
        'evidence_usable' => true,
    ]]
)->value();
Assert::same(
    $fromCard['status'],
    $fromContribution['status'],
    'both source-use entry points agree that an approved permissive source compiles ALLOW',
);
// THE TWO CEILINGS ARE EQUAL, because both paths now call one fold.
//
// They were not. The card-driven path used to reach OPERATOR_ONLY where canonical and the
// contribution fold reach TENANT_INTERNAL, and that gap was recorded as a deliberate "stricter,
// never looser" divergence. Stricter is not the same as conformant: a ceiling states what the
// SOURCES permit, and answering that question differently from canonical is a changed answer
// whichever direction it errs in. There was no host reason for the difference — MariaDB does not
// constrain a fold — so the divergence is gone rather than documented.
Assert::same(
    $fromCard['audience_ceiling'],
    $fromContribution['audience_ceiling'],
    'both source-use entry points reach the SAME audience ceiling on the same facts',
);
Assert::same(
    'TENANT_INTERNAL',
    $fromCard['audience_ceiling'],
    'and it is the canonical one for a licence that does not permit customer output',
);

// THE FIRST-PARTY RULE IS WHAT THE CONTRIBUTION PATH ADDS, and it must not leak into the card path:
// a cardless contribution that is NOT tenant-owned holds, and one that IS tenant-owned does not.
$cardless = $m16->compileSourceUseProfileFromContributions(
    $scopeForFold['tenant_id'],
    $scopeForFold['workspace_id'],
    [[
        'source_license_card_id' => null,
        'source_ref_id' => 'twin:external',
        'license_ceiling' => 'PUBLIC_WEB_RESTRICTED',
        'customer_output_allowed' => false,
        'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
        'retention_class' => 'standard',
        'data_ownership' => 'PUBLIC',
        'card_state' => null,
        'evidence_usable' => true,
    ]]
)->value();
Assert::equalsList(
    ['SOURCE_CARD_MISSING_FOR_EXTERNAL_SOURCE'],
    $cardless['hold_reasons'],
    'a cardless EXTERNAL contribution holds, naming the missing card',
);
Assert::same('OPERATOR_ONLY', $cardless['audience_ceiling'], 'and is capped at the operator hold-view');

$firstParty = $m16->compileSourceUseProfileFromContributions(
    $scopeForFold['tenant_id'],
    $scopeForFold['workspace_id'],
    [[
        'source_license_card_id' => null,
        'source_ref_id' => 'twin:first-party',
        'license_ceiling' => 'OPEN_CC0',
        'customer_output_allowed' => true,
        'sensitivity_class' => 'PUBLIC_OR_NON_SENSITIVE',
        'retention_class' => 'standard',
        'data_ownership' => 'TENANT_OWNED',
        'card_state' => null,
        'evidence_usable' => true,
    ]]
)->value();
Assert::equalsList(
    [],
    $firstParty['hold_reasons'],
    "a cardless TENANT_OWNED contribution does NOT hold — the tenant's own material needs no licence",
);
Assert::same(
    'CHANNEL_EMAIL_SMS_CRM',
    $firstParty['audience_ceiling'],
    'and reaches the full source-use ceiling — the tenant may show its own material anywhere',
);
// Which is a statement about the SOURCES, not about this deployment. What actually gets turned on
// is pinned separately, and stays at two internal audiences no licence can widen.
Assert::equalsList(
    ['OPERATOR_ONLY', 'TENANT_INTERNAL'],
    ReleaseOutput::ACTIVATABLE_AUDIENCES,
    'source permission is not deployment authority: activation stays pinned regardless',
);

// The hold-reason vocabulary is ordered, because the profile digest is order-sensitive.
Assert::equalsList(
    [
        'SOURCE_LICENSE_PROHIBITED', 'SOURCE_CARD_NOT_APPROVED', 'SOURCE_CARD_REVIEW_EXPIRED',
        'SOURCE_CARD_MISSING_FOR_EXTERNAL_SOURCE', 'EVIDENCE_QUARANTINED_OR_REVOKED',
        'SENSITIVITY_PROHIBITED', 'RETENTION_PROHIBITED', 'UNKNOWN_LICENSE_VALUE',
    ],
    ReleaseOutput::SOURCE_USE_HOLD_REASONS,
    'the hold reasons keep canonical declaration order — the digest depends on it',
);
