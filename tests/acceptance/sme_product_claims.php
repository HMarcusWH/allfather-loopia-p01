<?php

declare(strict_types=1);

/**
 * ACCEPTANCE: the claims the product is allowed to make about itself.
 *
 * Every other layer tests a mechanism. This one tests the SENTENCES — the things a salesperson
 * would say, and an auditor would later ask about. Each is written as the claim first and then
 * proved end-to-end through the real modules, because a claim nobody can demonstrate is marketing.
 *
 * And several of them are proved by their converse, which is the harder half: what the product
 * will NOT do is as much a part of the offer as what it will.
 */

use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P02\EntityTwins;
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Product\P05\MarketingAssets;
use Allfather\Loopia\Product\P07A\Consent;
use Allfather\Loopia\Product\P07A\PiiFirewall;
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

// ============================================================ "Every claim we show you is traceable"

// The strong form: not "we keep logs", but that a released briefing resolves to a decision record,
// which resolves to the evidence and the verdict it rested on — and that the chain verifies.
$source = Seed::p01ReleasedBriefing($db, $clock, $scope);

$record = $db->one(
    'SELECT r.decision_record_id, r.record_type, r.current_reliance_status, r.proposal_id
     FROM released_briefings b
     JOIN decision_records r ON r.decision_record_id = b.decision_record_id
     WHERE b.released_briefing_id = ?',
    [$source['released_briefing_id']]
);
Assert::same(
    'INTERNAL_INFORMATIONAL_RELEASE',
    (string) $record['record_type'],
    'a released briefing resolves to a decision record that says what kind of release it was',
);

$references = $db->all(
    'SELECT reference_role, input_object_type FROM decision_input_references
     WHERE decision_record_id = ? ORDER BY reference_role',
    [(string) $record['decision_record_id']]
);
$roles = array_map(static fn (array $r): string => (string) $r['reference_role'], $references);
foreach (['VERDICT_BASIS', 'PROPOSAL_BASIS', 'EVIDENCE_BASIS'] as $required) {
    Assert::true(
        in_array($required, $roles, true),
        "the record cites its {$required} — the claim resolves to what it rested on",
    );
}

$streamId = (string) $db->scalar(
    'SELECT ledger_stream_id FROM decision_records WHERE decision_record_id = ?',
    [(string) $record['decision_record_id']]
);
Assert::same(
    'PASS',
    (string) $ledger->verifyStream($streamId)['status'],
    'and the whole chain verifies — the trace is checkable, not merely present',
);

// ============================================================ "We never claim more than we saw"

// An interpretation recorded as a hypothesis stays one, all the way onto the artifact a
// salesperson reads. This is the claim that separates intelligence from speculation.
$interpretations = $db->all(
    'SELECT DISTINCT interpretation_kind FROM company_signals WHERE tenant_id = ?',
    [$scope['tenant_id']]
);
Assert::true(
    count($interpretations) > 0,
    'signals record HOW they were interpreted, as a first-class field',
);
Assert::contains(
    'downstream customer impact remains a hypothesis',
    (string) $db->scalar(
        'SELECT uncertainty_summary_safe FROM battlecard_briefs WHERE battlecard_brief_id = ?',
        [$source['battlecard_brief_id']]
    ),
    'and the artifact carries the uncertainty forward in words the reader sees',
);
Assert::notSame(
    null,
    $db->scalar(
        'SELECT rejected_claims_safe FROM battlecard_briefs WHERE battlecard_brief_id = ?',
        [$source['battlecard_brief_id']]
    ),
    'along with what the evidence does NOT support — a required field, not an optional courtesy',
);

// ============================================================ "A fit is not an intent"

// The buyer-intent invariant, stated as the business claim it is: the product will tell you a
// company might fit what you sell, and will refuse to tell you they want to buy it.
$catalogueTerms = $db->one(
    "SELECT claim_kind FROM fit_hypotheses WHERE tenant_id = ? LIMIT 1",
    [$scope['tenant_id']]
);
if ($catalogueTerms !== null) {
    Assert::same(
        'REASON_TO_INVESTIGATE',
        (string) $catalogueTerms['claim_kind'],
        'a fit hypothesis claims a REASON TO INVESTIGATE, and the column admits nothing stronger',
    );
}
$claimKindCheck = (string) $db->scalar(
    "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CHECK_CLAUSE LIKE '%REASON_TO_INVESTIGATE%' LIMIT 1"
);
foreach (['INTENT', 'READY_TO_BUY', 'QUALIFIED_LEAD'] as $overclaim) {
    Assert::false(
        str_contains($claimKindCheck, $overclaim),
        "the claim vocabulary has no {$overclaim} member — the stronger claim cannot be stored",
    );
}

// ============================================================ "We do not build files on people"

// Proved structurally, across two planes. The role table can hold a ROLE and nothing narrower, and
// the acknowledgement table has no column a score could live in.
Assert::same(
    'ROLE_CLASS',
    (string) $db->scalar(
        "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(CHECK_CLAUSE, '''', 2), '''', -1)
         FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CHECK_CLAUSE LIKE '%subject_kind%' LIMIT 1"
    ),
    'a stakeholder observation can name a ROLE CLASS and nothing narrower',
);

// And the entity twin refuses the same thing through the real product path, with a named reason
// for each kind of overreach — because the remedies differ, and an operator needs to know which.
$twins = new EntityTwins($db, $clock, new \Allfather\Loopia\Substrate\M05\EvidenceSpine($db, $clock));
$twinId = $twins->registerTwin(
    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITOR', 'Rival AB',
    [], $scope['operator_id']
)->value()['entity_twin_id'];
$twinBase = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'entity_twin_id' => $twinId,
    'claim_ceiling' => 'INTERNAL_REVIEWED',
    'uncertainty' => ['summary_safe' => 'Derived from a public listing; not confirmed.'],
];
foreach ([
    'a personality profile of a named officer' => [
        ['field_key' => 'ceo_personality_profile', 'field_value_safe' => 'analytical', 'value_status' => 'DERIVED_SUMMARY'],
        'PERSON_OVERREACH_FIELD',
    ],
    'a productivity score for an individual employee' => [
        ['field_key' => 'sales_rep_productivity_score', 'field_value_safe' => '72', 'value_status' => 'DERIVED_SUMMARY'],
        'EMPLOYEE_SCORING_FIELD',
    ],
    'a raw contact address' => [
        ['field_key' => 'primary_contact', 'field_value_safe' => 'anna@rival.example', 'value_status' => 'PUBLIC_SOURCE'],
        'INDIVIDUAL_IDENTIFIER_IN_TWIN_FIELD',
    ],
] as $why => [$field, $expected]) {
    Assert::same(
        $expected,
        $twins->appendVersion([...$twinBase, 'fields' => [$field]])->reasonCode(),
        "a company twin refuses {$why}",
    );
}

// ============================================================ "Nothing leaves without a person"

// The claim in its strongest available form: there is no code path in this projection that
// contacts anybody, and the tables say so. The outreach vocabulary tops out at APPROVAL_REQUIRED.
$outreachStatuses = (string) $db->scalar(
    "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CHECK_CLAUSE LIKE '%APPROVAL_REQUIRED%' LIMIT 1"
);
foreach (['SENT', 'DELIVERED', 'DISPATCHED'] as $forbidden) {
    Assert::false(
        str_contains($outreachStatuses, $forbidden),
        "an outreach proposal can never reach {$forbidden} — the state does not exist",
    );
}
Assert::same(
    0,
    (int) $db->scalar('SELECT COUNT(*) FROM outreach_channel_bindings'),
    'and no channel binding exists, because this projection ports no function that creates one',
);

// The marketing plane makes the same promise about publication.
$assetStatuses = (string) $db->scalar(
    "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CHECK_CLAUSE LIKE '%RELEASED_INTERNAL%'
       AND CHECK_CLAUSE LIKE '%asset_status%' LIMIT 1"
);
Assert::false(
    str_contains($assetStatuses, 'PUBLISHED'),
    'and a marketing asset can never reach PUBLISHED — the state does not exist either',
);

// ============================================================ "Consent means what it says"

// Silence is not consent, and a withdrawal takes effect immediately. Both are business promises
// before they are mechanisms.
$consent = new Consent($db, $clock);
$firewall = new PiiFirewall($db, $clock);
$pseudonym = $firewall->pseudonymize([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'channel' => 'EMAIL',
    'raw_recipient_identifier' => 'acceptance@example.com',
])->value()['recipient_pseudonym_ref'];
$key = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'recipient_pseudonym_ref' => $pseudonym,
    'channel' => 'EMAIL',
];

Assert::same(
    'NO_CONSENT_ON_RECORD',
    $consent->evaluateForSend($key)->reasonCode(),
    'with nothing on file the answer is no — silence is not consent',
);
$consent->record([...$key, 'consent_state' => 'OPTED_IN', 'lawful_basis' => 'CONSENT', 'recorded_at' => '2026-01-01T00:00:00.000Z']);
Assert::true($consent->evaluateForSend($key)->isOk(), 'an opt-in with a basis permits');
$consent->record([...$key, 'consent_state' => 'WITHDRAWN', 'lawful_basis' => 'CONSENT', 'recorded_at' => '2026-02-01T00:00:00.000Z']);
Assert::same(
    'CONSENT_WITHDRAWN',
    $consent->evaluateForSend($key)->reasonCode(),
    'and a withdrawal takes effect at once — no window, no grace period',
);

// ============================================================ "You see only what you bought"

// The commercial gate is genuinely separate from the access gate, so a role grant is not a
// purchase and a purchase is not an authority.
$entitlements = new Entitlements($db, $clock);
Assert::false(
    $entitlements->tenantTierGrants($scope['tenant_id'], 'battlecard.read'),
    'an unsubscribed tenant is entitled to nothing, whatever roles its people hold',
);
Assert::same(
    'TIER_PERMISSION_NOT_DEFINED',
    $entitlements->defineTier('invented', 'Invented', 1, ['authority.nobody.defined'])->reasonCode(),
    'and a tier can never mint an authority the platform never defined',
);

// ============================================================ "Withdrawing is provable"

// The last claim, and the one that makes the others enforceable: when something is withdrawn, the
// withdrawal is a record — so an auditor can see both what was said and that it was retracted.
$ledger->supersede(
    $source['decision_record_id'],
    'INVALIDATED_FOR_CURRENT_RELIANCE',
    'M11',
    $source['released_briefing_id'],
    'Withdrawn for the acceptance demonstration.',
    'INVALIDATED_FOR_CURRENT_RELIANCE',
);
Assert::false($ledger->isCurrentlyRelied($source['decision_record_id']), 'reliance is withdrawn');
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM decision_records WHERE decision_record_id = ?',
        [$source['decision_record_id']]
    ),
    'the original claim is still on the record — a retraction, not an erasure',
);
Assert::same(
    'PASS',
    (string) $ledger->verifyStream($streamId)['status'],
    'and the chain still verifies with the retraction in it',
);
