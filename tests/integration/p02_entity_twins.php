<?php

declare(strict_types=1);

/**
 * P02 entity twins — a picture of an ORGANIZATION, and the four things it may never become.
 *
 * The privacy invariants are the reason this module has a screen at all, and each has a different
 * remedy so each has its own reason code:
 *
 *   PERSON OVERREACH      psychology, emotion, health, politics, religion, sexuality, ethnicity,
 *                         private life — not business facts about an organization.
 *   EMPLOYEE SCORING      rating an individual employee — the covert-telemetry line.
 *   BUYER INTENT AS FACT  fine as a hypothesis; not available as a certainty.
 *   INDIVIDUAL IDENTIFIER an email, handle or phone in a value — a role class is permitted, the
 *                         person holding it is not the subject of this system.
 *
 * The screen is CONTENT-BASED. Asking the writer to declare whether they were building a dossier
 * would put the boundary in the hands of the party crossing it.
 */

use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Product\P02\PrivacyPatterns;
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
$twins = new EntityTwins($db, $clock);

// ============================================================ the identifier detector

// Broad enough to catch real identifiers.
foreach ([
    'john.doe@rival.example' => 'a plain email address',
    'john.doe (at) rival.example' => 'a bracketed obfuscated email',
    'linkedin.com/in/johndoe' => 'a profile URL',
    '@johndoe' => 'a bare handle',
    '+46 70 123 45 67' => 'an international phone number',
    '070-123 45 67' => 'a local phone number',
] as $value => $why) {
    Assert::true(
        PrivacyPatterns::containsIndividualIdentifier($value),
        "detected: {$why}",
    );
}

// Narrow enough not to fire on ordinary business text. Each of these has previously been the shape
// of a false positive somewhere, which is why each is pinned.
foreach ([
    'Strategy data for the finance function' => '"at" inside ordinary words is not an obfuscated email',
    'Revenue grew to 1,250,000 SEK' => 'comma-grouped figures are not a phone number',
    '2026-06-07' => 'an ISO date is not a phone number',
    '2026-06-07 14:30:00' => 'nor is a timestamp',
    'Finance decision maker' => 'a ROLE CLASS is not an individual',
] as $value => $why) {
    Assert::false(
        PrivacyPatterns::containsIndividualIdentifier($value),
        "not detected: {$why}",
    );
}

// A date does not SHIELD a phone number in the same text: every run is inspected, not just the
// first, so a summary carrying both flags on the one that matters.
Assert::true(
    PrivacyPatterns::containsIndividualIdentifier('Observed 2026-06-07, contact +46 70 123 45 67'),
    'a date alongside a phone number does not shield it',
);

// ============================================================ the twin, and its ceiling

$twin = $twins->registerTwin(
    $scope['tenant_id'], $scope['workspace_id'], 'PROSPECT', 'Rival Industries AB',
    ['industry' => 'software', 'country' => 'SE'], $scope['operator_id']
);
Assert::true($twin->isOk(), 'an external organization twin registers');
Assert::false($twin->value()['is_first_party'], 'as external');
$twinId = $twin->value()['entity_twin_id'];

// A twin cannot be both the tenant and somebody else. The two flags are storage-pinned complements,
// so the leakage rules downstream always have an unambiguous subject.
Assert::throws(
    static fn () => $db->run(
        'UPDATE entity_twins SET is_first_party = 1 WHERE entity_twin_id = ?', [$twinId]
    ),
    \PDOException::class,
    'a twin cannot be both first-party and external',
);

$baseFields = [
    ['field_key' => 'industry', 'field_value_safe' => 'software', 'value_status' => 'PUBLIC_SOURCE'],
    ['field_key' => 'headcount_band', 'field_value_safe' => '50-249', 'value_status' => 'DERIVED_SUMMARY'],
    // A ROLE CLASS is permitted. An organization has a finance function; who runs it is not
    // recorded here.
    ['field_key' => 'buying_role_class', 'field_value_safe' => 'finance decision maker', 'value_status' => 'TENANT_PROVIDED'],
];
$baseInput = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_id' => $twinId,
    'claim_ceiling' => 'INTERNAL_REVIEWED',
    'fields' => $baseFields,
    'uncertainty' => ['summary_safe' => 'Headcount is derived from a public listing; not confirmed.'],
];

$version = $twins->appendVersion($baseInput);
Assert::true($version->isOk(), 'a version of admissible fields appends');
Assert::same(1, $version->value()['version_sequence'], 'as sequence 1');

// ============================================================ the four prohibitions

// Each prohibition gets its OWN code, because the remedies differ: a person-overreach field must be
// dropped, an employee-scoring field reconsidered entirely, a buyer-intent field only softened.
foreach ([
    ['field' => ['field_key' => 'ceo_personality_profile', 'field_value_safe' => 'analytical', 'value_status' => 'DERIVED_SUMMARY'],
     'code' => 'PERSON_OVERREACH_FIELD', 'why' => 'a personality profile is not a business fact'],
    ['field' => ['field_key' => 'founder_health_status', 'field_value_safe' => 'good', 'value_status' => 'PUBLIC_SOURCE'],
     'code' => 'PERSON_OVERREACH_FIELD', 'why' => 'nor is health'],
    ['field' => ['field_key' => 'board_political_leaning', 'field_value_safe' => 'centre', 'value_status' => 'PUBLIC_SOURCE'],
     'code' => 'PERSON_OVERREACH_FIELD', 'why' => 'nor politics'],
    ['field' => ['field_key' => 'sales_rep_productivity_score', 'field_value_safe' => '72', 'value_status' => 'DERIVED_SUMMARY'],
     'code' => 'EMPLOYEE_SCORING_FIELD', 'why' => 'scoring an individual employee is the covert-telemetry line'],
    ['field' => ['field_key' => 'account_manager_loyalty', 'field_value_safe' => 'high', 'value_status' => 'DERIVED_SUMMARY'],
     'code' => 'EMPLOYEE_SCORING_FIELD', 'why' => 'and so is rating their loyalty'],
    ['field' => ['field_key' => 'covert_activity_monitor', 'field_value_safe' => 'on', 'value_status' => 'TENANT_PROVIDED'],
     'code' => 'EMPLOYEE_SCORING_FIELD', 'why' => 'a field named for the practice asserts the practice'],
    ['field' => ['field_key' => 'buyer_intent', 'field_value_safe' => 'ready', 'value_status' => 'KNOWN_FACT'],
     'code' => 'BUYER_INTENT_OVERCLAIM', 'why' => 'buyer intent as a certainty is a claim about a mind'],
    ['field' => ['field_key' => 'primary_contact', 'field_value_safe' => 'anna@rival.example', 'value_status' => 'PUBLIC_SOURCE'],
     'code' => 'INDIVIDUAL_IDENTIFIER_IN_TWIN_FIELD', 'why' => 'an individual identifier in a value'],
] as $case) {
    $refused = $twins->appendVersion([
        ...$baseInput,
        'fields' => [...$baseFields, $case['field']],
    ]);
    Assert::same($case['code'], $refused->reasonCode(), $case['why']);
    Assert::same(
        $case['field']['field_key'],
        $refused->detail['field_key'],
        'and the refusal names WHICH field',
    );
}

// ONE prohibited field refuses the WHOLE version. Dropping it silently would leave the operator
// believing the twin holds something it does not, and the next writer would try again.
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM entity_twin_versions WHERE entity_twin_id = ?', [$twinId]),
    'every refused version wrote nothing at all',
);

// The SAME buyer-intent field as a HYPOTHESIS is admissible. The refusal is about the claim's
// strength, not the subject.
$asHypothesis = $twins->appendVersion([
    ...$baseInput,
    'fields' => [...$baseFields,
        ['field_key' => 'buyer_intent', 'field_value_safe' => 'possibly in market', 'value_status' => 'INFERRED_HYPOTHESIS'],
    ],
    'uncertainty' => ['summary_safe' => 'Buyer intent is inferred from a pricing change; not observed.'],
]);
Assert::true($asHypothesis->isOk(), 'the same field IS admissible as a hypothesis');

// ============================================================ uncertainty is not optional

Assert::same(
    'TWIN_UNCERTAINTY_SUMMARY_REQUIRED',
    $twins->appendVersion([...$baseInput, 'uncertainty' => ['summary_safe' => '  ']])->reasonCode(),
    'a version must state its uncertainty',
);

// A CONFLICTING field must be DECLARED. A twin recording contradictory evidence without saying so
// would present a resolved picture of an unresolved question.
$undeclared = $twins->appendVersion([
    ...$baseInput,
    'fields' => [...$baseFields,
        ['field_key' => 'headquarters', 'field_value_safe' => 'two sources disagree', 'value_status' => 'CONFLICTING'],
    ],
    'uncertainty' => ['summary_safe' => 'Nothing notable.'],
]);
Assert::same(
    'CONFLICTING_EVIDENCE_UNRESOLVED',
    $undeclared->reasonCode(),
    'a conflicting field must be declared in the uncertainty summary',
);

$declared = $twins->appendVersion([
    ...$baseInput,
    'fields' => [...$baseFields,
        ['field_key' => 'headquarters', 'field_value_safe' => 'two sources disagree', 'value_status' => 'CONFLICTING'],
        ['field_key' => 'funding_round', 'field_value_safe' => null, 'value_status' => 'UNKNOWN'],
    ],
    'uncertainty' => [
        'summary_safe' => 'Headquarters is contested between two public listings.',
        'has_conflict' => true,
        'conflict_summary_safe' => 'Registry and website disagree.',
    ],
]);
Assert::true($declared->isOk(), 'a declared conflict is admissible');
Assert::same(
    1,
    $declared->value()['unknown_field_count'],
    'and the unknown fields are COUNTED, so a reader sees how much of the picture is missing',
);

// ============================================================ versions are append-only

Assert::same(
    3,
    (int) $db->scalar('SELECT COUNT(*) FROM entity_twin_versions WHERE entity_twin_id = ?', [$twinId]),
    'three versions exist',
);
Assert::throws(
    static fn () => $db->run(
        'UPDATE entity_twin_state_summaries SET field_value_safe = ? WHERE entity_twin_version_id = ?',
        ['edited', $version->value()['entity_twin_version_id']]
    ),
    \PDOException::class,
    'and a recorded field cannot be edited — a twin changes by gaining a version',
);

// The version digest binds the SORTED field set, so field order at write time cannot change a
// version's identity, and a field changed after the fact no longer verifies.
Assert::same(
    64,
    strlen($version->value()['version_digest']),
    'each version carries a digest over its own field set',
);
Assert::notSame(
    $version->value()['version_digest'],
    $declared->value()['version_digest'],
    'and versions with different content have different digests',
);

// ============================================================ offering twins are not here

Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name LIKE 'offering_twin%'"
    ),
    'the offering-twin path is not ported — the P03 catalogue is the single products authority',
);
