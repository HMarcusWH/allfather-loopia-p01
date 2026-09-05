<?php

declare(strict_types=1);

/**
 * P07-A consent and the PII firewall, proved by REVERSION.
 *
 * These two modules are the safety foundation any outreach would have to cross. They are built and
 * proved here while there is NO channel to send on — which is the right order, because a consent
 * law written after a send path exists is a law written under pressure.
 *
 * What is proved:
 *
 *   Consent fails closed in five directions, each with its own reason code.
 *   A withdrawal supersedes an opt-in by RECENCY, and the registry refuses to be edited.
 *   The firewall's output is opaque, deterministic, and carries no PII shape of its own.
 *   Erasure downgrades and never deletes — and the storage refuses a delete independently.
 */

use Allfather\Loopia\Product\P07A\Consent;
use Allfather\Loopia\Product\P07A\PiiFirewall;
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
$other = Seed::tenantWorkspace($db, $clock, 'rival');

$consent = new Consent($db, $clock);
$firewall = new PiiFirewall($db, $clock);

// ============================================================ the firewall entrance

$pseudonymized = $firewall->pseudonymize([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'channel' => 'EMAIL',
    'raw_recipient_identifier' => 'Anna.Berg@Example.com',
]);
Assert::true($pseudonymized->isOk(), 'a raw identifier is pseudonymized');
$pseudonym = $pseudonymized->value()['recipient_pseudonym_ref'];
Assert::true($pseudonymized->value()['created'], 'creating the mapping on first sight');

// THE RAW IDENTIFIER IS NOWHERE. Not in the mapping, not in any column of it. This is the whole
// claim of the firewall, and it is checked against the stored row rather than against the API.
$stored = $db->one(
    'SELECT * FROM recipient_pseudonym_mappings WHERE recipient_pseudonym_mapping_id = ?',
    [$pseudonymized->value()['recipient_pseudonym_mapping_id']]
);
foreach ($stored as $column => $value) {
    Assert::false(
        is_string($value) && stripos($value, 'anna.berg') !== false,
        "the raw identifier does not appear in `{$column}` — it was used and discarded",
    );
}
Assert::same(
    'SALTED_SHA256',
    (string) $stored['pseudonymization_method'],
    'the mapping records HOW the pseudonym was derived',
);
Assert::same('ACTIVE_LINKABLE', (string) $stored['retention_class'], 'and starts linkable');

// THE PSEUDONYM CARRIES NO PII SHAPE OF ITS OWN. base64url rather than hex, so the firewall's
// output does not trip the very detector that audits the firewall held. A hex digest would.
Assert::false(
    PiiFirewall::containsRawPii($pseudonym),
    'the pseudonym itself reads as no kind of identifier — base64url, not a long digit run',
);

// Deterministic across calls, and case-insensitive for email: the same person resolves to the same
// pseudonym, or consent lookups would not line up with the consent that was given.
Assert::same(
    $pseudonym,
    PiiFirewall::computePseudonym($scope['tenant_id'], $scope['workspace_id'], 'EMAIL', 'anna.berg@example.com'),
    'the same recipient resolves to the same pseudonym, whatever the casing',
);
Assert::false(
    $firewall->pseudonymize([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'channel' => 'EMAIL',
        'raw_recipient_identifier' => 'anna.berg@example.com',
    ])->value()['created'],
    'and a second call returns the existing mapping rather than duplicating it',
);

// Different people, different scopes, different channels and different salts all separate.
Assert::notSame(
    $pseudonym,
    PiiFirewall::computePseudonym($scope['tenant_id'], $scope['workspace_id'], 'EMAIL', 'bo.lind@example.com'),
    'a different recipient gets a different pseudonym',
);
Assert::notSame(
    $pseudonym,
    PiiFirewall::computePseudonym($other['tenant_id'], $other['workspace_id'], 'EMAIL', 'anna.berg@example.com'),
    'the same person in another tenant does not collide — pseudonyms are scope-bound',
);
Assert::notSame(
    $pseudonym,
    PiiFirewall::computePseudonym($scope['tenant_id'], $scope['workspace_id'], 'SMS', 'anna.berg@example.com'),
    'nor across channels',
);
// Salt-injectable, which is what makes the demo salt safe to document: a deployment with real
// recipients supplies a sealed secret and gets an entirely different pseudonym space.
Assert::notSame(
    $pseudonym,
    PiiFirewall::computePseudonym(
        $scope['tenant_id'], $scope['workspace_id'], 'EMAIL', 'anna.berg@example.com', 'a-sealed-tenant-secret'
    ),
    'and a different salt yields a different pseudonym space entirely',
);

// A "safe" locator that smuggles PII defeats the firewall, so it is refused.
Assert::same(
    'UNSAFE_LOCATOR_CONTAINS_PII',
    $firewall->pseudonymize([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'channel' => 'EMAIL',
        'raw_recipient_identifier' => 'bo.lind@example.com',
        'sealed_identity_locator_safe' => 'vault://bo.lind@example.com',
    ])->reasonCode(),
    'a sealed locator embedding a raw identifier is refused',
);
Assert::same(
    'EMPTY_IDENTIFIER',
    $firewall->pseudonymize([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'channel' => 'EMAIL',
        'raw_recipient_identifier' => '   ',
    ])->reasonCode(),
    'and an empty identifier is refused rather than pseudonymized into a constant',
);
Assert::same(
    'WORKSPACE_SCOPE_MISMATCH',
    $firewall->pseudonymize([
        'tenant_id' => $other['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'channel' => 'EMAIL',
        'raw_recipient_identifier' => 'anna.berg@example.com',
    ])->reasonCode(),
    'a workspace belonging to another tenant is refused',
);

// ============================================================ the deep payload scan

// The per-recipient payload is what would reach a person, so it is scanned WHOLE — including keys.
Assert::true(
    PiiFirewall::containsRawPiiDeep(['body' => 'Call us on +46 70 123 45 67.']),
    'a phone number nested in a payload value is found',
);
Assert::true(
    PiiFirewall::containsRawPiiDeep(['sections' => [['note' => 'Reply to anna@example.com']]]),
    'and at any depth',
);
// A raw email used as a JSON KEY is still payload content. A scan that only walked values would
// miss it entirely, which is why keys are scanned too.
Assert::true(
    PiiFirewall::containsRawPiiDeep(['anna.berg@example.com' => 'preferred']),
    'a raw identifier used as a KEY is payload content, and is found',
);
Assert::false(
    PiiFirewall::containsRawPiiDeep(['body' => 'Our pricing page changed on 2026-06-07.', 'n' => 3]),
    'and ordinary business content with a date is not flagged',
);

// A UUID IS NOT A PHONE NUMBER, and every governed object reference is one. A UUID's digit-and-
// hyphen fragments read exactly like phone runs — about a QUARTER of random ids produce one — so
// without the exclusion any safe-reference text quoting a record id would trip this detector a
// quarter of the time. That is not a small annoyance: it opens spurious high-severity incidents,
// and it teaches operators that the PII audit cries wolf.
$uuidTexts = 0;
foreach ([
    'c9c3faaf-d20e-4ec7-9573-282b74929769',
    '8930b375-6380-4f21-98a7-fc1a099383a3',
    '94f48019-8640-4630-80a9-19a56384c4c5',
    '3d8dec54-7444-4465-9290-ebbd6da72b2b',
    '09bc88ce-9596-4628-9331-8deb3adb8e72',
] as $uuid) {
    Assert::false(
        PiiFirewall::containsRawPii('Consent state at proposal: OPTED_IN; cites M10 release ' . $uuid . '.'),
        "a record reference quoting {$uuid} is not an individual identifier",
    );
    $uuidTexts++;
}
Assert::same(5, $uuidTexts, 'each of the five phone-run-shaped UUIDs is proved on its own');

// And the exclusion is NARROW: a real number sitting next to a UUID still flags, so removing the
// token cannot be used to smuggle one past.
Assert::true(
    PiiFirewall::containsRawPii('release c9c3faaf-d20e-4ec7-9573-282b74929769, call +46 70 123 45 67'),
    'a real phone number adjacent to a UUID still flags — the exclusion removes the token, not the scan',
);

// ============================================================ consent fails closed

$consentKey = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'recipient_pseudonym_ref' => $pseudonym,
    'channel' => 'EMAIL',
];

// SILENCE IS NOT CONSENT. With no record at all, the answer is a block — not an unknown to resolve.
Assert::same(
    'NO_CONSENT_ON_RECORD',
    $consent->evaluateForSend($consentKey)->reasonCode(),
    'with no record on file, sending is blocked — silence is not consent',
);

// Each refusing state, proved on its own with its own code.
$states = [
    'OPTED_OUT' => ['OPTED_OUT', 'CONSENT', 'CONSENT_OPTED_OUT'],
    'WITHDRAWN' => ['WITHDRAWN', 'CONSENT', 'CONSENT_WITHDRAWN'],
    'NO_BASIS state' => ['NO_BASIS', 'CONSENT', 'NO_LAWFUL_BASIS'],
    'NONE basis' => ['OPTED_IN', 'NONE', 'NO_LAWFUL_BASIS'],
];
$at = 1;
foreach ($states as $label => [$state, $basis, $expected]) {
    $ref = $pseudonym . ':' . $at;
    $consent->record([
        'tenant_id' => $scope['tenant_id'],
        'workspace_id' => $scope['workspace_id'],
        'recipient_pseudonym_ref' => $ref,
        'channel' => 'EMAIL',
        'consent_state' => $state,
        'lawful_basis' => $basis,
        'recorded_at' => sprintf('2026-01-%02dT00:00:00.000Z', $at),
    ]);
    Assert::same(
        $expected,
        $consent->evaluateForSend([...$consentKey, 'recipient_pseudonym_ref' => $ref])->reasonCode(),
        "a {$label} recipient is blocked, and the refusal names which act it was",
    );
    $at++;
}

// An OPTED_IN recipient with a real basis is permitted. Without this the suite would only prove
// the gate refuses everything, which is not the same as proving it refuses the right things.
$consent->record([
    ...$consentKey,
    'consent_state' => 'OPTED_IN',
    'lawful_basis' => 'CONSENT',
    'basis_reference_safe' => 'consent-form://2026-01-02',
    'recorded_at' => '2026-01-02T00:00:00.000Z',
]);
$permitted = $consent->evaluateForSend($consentKey);
Assert::true($permitted->isOk(), 'an opted-in recipient with a recorded basis is permitted');
Assert::same('PERMITTED', $permitted->value()['decision'], 'explicitly');
Assert::same('CONSENT', $permitted->value()['lawful_basis'], 'carrying the basis it rests on');

// ============================================================ withdrawal supersedes by recency

$consent->record([
    ...$consentKey,
    'consent_state' => 'WITHDRAWN',
    'lawful_basis' => 'CONSENT',
    'recorded_at' => '2026-03-01T00:00:00.000Z',
]);
Assert::same(
    'CONSENT_WITHDRAWN',
    $consent->evaluateForSend($consentKey)->reasonCode(),
    'a later withdrawal supersedes the earlier opt-in — immediately, and by recency',
);

// THE OPT-IN ROW STILL EXISTS. The withdrawal did not edit it; it was appended after it. That is
// what makes "what did they consent to in January?" a question with an answer.
Assert::same(
    2,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM consent_registry
         WHERE recipient_pseudonym_ref = ? AND channel = ?',
        [$pseudonym, 'EMAIL']
    ),
    'both rows survive — a withdrawal is an append, never an edit',
);

// And the storage refuses an edit independently of the module. A consent registry that could be
// rewritten would make every past consent unprovable.
Assert::throws(
    static fn () => $db->run(
        "UPDATE consent_registry SET consent_state = 'OPTED_IN' WHERE recipient_pseudonym_ref = ?",
        [$pseudonym]
    ),
    \PDOException::class,
    'the consent registry refuses UPDATE at the storage layer',
);
Assert::throws(
    static fn () => $db->run(
        'DELETE FROM consent_registry WHERE recipient_pseudonym_ref = ?',
        [$pseudonym]
    ),
    \PDOException::class,
    'and DELETE too',
);

// ============================================================ GDPR erasure downgrades

$erased = $firewall->eraseUnderGdpr($consentKey);
Assert::true($erased->isOk(), 'a recipient is erased under GDPR');
$after = $firewall->mapping($consentKey);
Assert::same(
    'ERASED_PSEUDONYMIZED',
    (string) $after['retention_class'],
    'by DOWNGRADING the retention class',
);
Assert::same(null, $after['sealed_identity_locator_safe'], 'and clearing the sealed locator');
Assert::notSame(null, $after['retention_downgraded_at'], 'recording when');

// THE ROW SURVIVES, and that is the point: the pseudonym in past audit records must still resolve
// to something. Deleting the mapping would erase the audit trail rather than the person.
Assert::notSame(null, $after, 'the mapping row survives — erasure is not deletion');
Assert::throws(
    static fn () => $db->run(
        'DELETE FROM recipient_pseudonym_mappings WHERE recipient_pseudonym_ref = ?',
        [$pseudonym]
    ),
    \PDOException::class,
    'and the storage refuses a delete independently of the module',
);
Assert::same(
    'ALREADY_ERASED',
    $firewall->eraseUnderGdpr($consentKey)->reasonCode(),
    'a second erasure is refused rather than silently repeated',
);
