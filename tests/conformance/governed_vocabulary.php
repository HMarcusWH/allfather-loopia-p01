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
use Allfather\Loopia\Product\P02\EntityTwins;
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
        'CONNECTOR_CLASS_NOT_PROMOTED', 'SOURCE_SCOPE_MISMATCH',
    ]),
    $sorted(SourcePolicy::FAIL_CLOSED_REASON_CODES),
    'the compiler\'s fail-closed vocabulary is canonical\'s fourteen members, no more and no fewer',
);
Assert::same(
    'ing_policy_compiler_v2',
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

// ============================================================ the release topology is M10's, not M16's

// The Sources plane used to draw M10 -> M16 -> all five product modules. It is not the adoption
// boundary at the pinned canonical head, and the difference matters: M10 owns release for EVERY
// module — one authority, one place a release can be revoked from — while M16 is a PROJECTION that
// exactly two product families adopt. Asserted here so the map cannot drift back.

// M16's artifact-kind vocabulary names P01 and P02 kinds only. A P03, P04 or P05 kind appearing
// here would be the first sign of somebody routing a module through it.
foreach (ReleaseOutput::ARTIFACT_KIND as $kind) {
    Assert::true(
        str_contains($kind, 'BRIEFING') || str_contains($kind, 'SNAPSHOT_VIEW'),
        "M16 artifact kind {$kind} belongs to the adopted P01/P02 families",
    );
}
foreach (['PROSPECT', 'DISTRIBUTION', 'MARKETING', 'QUEUE'] as $unadopted) {
    Assert::false(
        in_array(true, array_map(
            static fn (string $k): bool => str_contains($k, $unadopted),
            ReleaseOutput::ARTIFACT_KIND
        ), true),
        "and no {$unadopted} kind — P03/P04/P05 are not M16-adopted",
    );
}

// Each unadopted module has its OWN release projection, and every one of them references an M10
// decision record. That is the shape: one release authority, several projections.
foreach ([
    'released_briefings' => 'P01 — also the M16-adopted path',
    'released_entity_twin_snapshots' => 'P02',
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

// And ONE product module constructs M16 in this port. A second one appearing is the drift this
// assertion exists to catch — it would mean an adoption boundary moved without an owner act.
$m16Consumers = [];
foreach (glob(dirname(__DIR__, 2) . '/app/Product/*/*.php') ?: [] as $file) {
    if (str_contains((string) file_get_contents($file), 'M16\\ReleaseOutput')) {
        $m16Consumers[] = basename(dirname($file));
    }
}
Assert::same(
    ['P01'],
    array_values(array_unique($m16Consumers)),
    'exactly one product family reaches M16 in this port — P01, the adopted path that is ported',
);
// P02 is the pointed case: its release lifecycle IS ported, and upstream composes an additive M16
// snapshot-view artifact inside the same post-M10 transaction. This port does not, and the
// assertion above is what keeps that an honest exclusion rather than a claim — the SNAPSHOT_VIEW
// kinds exist in M16's vocabulary and nothing here composes one.
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM release_artifacts'),
    'and no artifact exists on a fresh database, P02 snapshot views included',
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
