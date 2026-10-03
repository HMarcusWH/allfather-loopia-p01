<?php

declare(strict_types=1);

/**
 * P08 — the P03 post-outcome audits as an OPERATOR uses them, against real MariaDB.
 *
 * Canonical parity is `tests/conformance/p03_assurance.php`. This suite proves what parity cannot:
 * the projection-only surfaces around the port.
 *
 *   - profiles are seeded idempotently, by registration or by an operator, and never reactivated;
 *   - windows longer than 24.8 days are storable at all (migration 245) and are honoured;
 *   - malformed knobs refuse BEFORE any row is written (the canonical NaN fail-open is not ported);
 *   - a sweep is bounded, refuses rather than auditing a prefix, never sweeps a sibling workspace's
 *     catalogue, and reports a missing profile rather than inventing one;
 *   - only an operator holding the right permission can seed, read or sweep, and the run records
 *     that operator — never an anonymous service;
 *   - audits append findings/incidents and never touch the rows they audit.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P03\Assurance as P03Assurance;
use Allfather\Loopia\Product\P03\Catalogue;
use Allfather\Loopia\Product\P03\Registration as P03Registration;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
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

$clock = Harness::clock('2026-07-02T00:00:00.000Z');
$m11 = new M11Assurance($db, $clock, new DecisionLedger($db, $clock));
$p03 = new P03Assurance($db, $clock, $m11);
$cards = new SourceCards($db, $clock);
$catalogues = new Catalogue($db, $clock);
$DAY = 86_400_000;
$scope = Seed::tenantWorkspace($db, $clock, 'p03assure');
$auditRows = static fn (): int => (int) $db->scalar('SELECT COUNT(*) FROM audit_runs')
    + (int) $db->scalar('SELECT COUNT(*) FROM audit_findings')
    + (int) $db->scalar('SELECT COUNT(*) FROM incident_cases');

/** A real tenant-owned catalogue through the P01 card and P03 catalogue owners. */
$catalogue = static function (array $s, array $card = [], ?string $workspaceId = 'scope') use ($cards, $catalogues): array {
    $projected = $cards->project([
        'tenant_id' => $s['tenant_id'],
        'source_name' => 'Tenant product list',
        'source_url_safe' => 'https://tenant.example/products/' . bin2hex(random_bytes(4)),
        'source_type' => 'manual',
        'allowed_use_class' => 'derived_summary_allowed',
        'license_class' => 'OPEN_CC0',
        'review_status' => 'approved',
        'data_ownership' => 'TENANT_OWNED',
        ...$card,
    ]);
    Assert::same('PROJECTED', $projected['outcome'], 'the catalogue card projects: ' . ($projected['reason_code'] ?? ''));
    $registered = $catalogues->registerCatalogue(
        $s['tenant_id'],
        $workspaceId === 'scope' ? $s['workspace_id'] : $workspaceId,
        'cat-' . bin2hex(random_bytes(4)),
        'Catalogue',
        $projected['source_ref_id'],
        $projected['source_license_card_id'],
    );
    Assert::true($registered->isOk(), 'the catalogue registers: ' . ($registered->isOk() ? '' : $registered->reasonCode()));

    return [
        'catalogue_id' => $registered->value()['customer_product_catalogue_id'],
        'source_ref_id' => $projected['source_ref_id'],
        'card_id' => $projected['source_license_card_id'],
    ];
};

$signal = static function (array $s, string $status, string $createdAt, string $class = 'PRICING_CHANGE_SIGNAL', string $kind = 'FACT_OBSERVED') use ($db): string {
    $competitorId = Ids::new();
    $db->insert('competitors', [
        'competitor_id' => $competitorId, 'schema_version' => '1', 'tenant_id' => $s['tenant_id'], 'workspace_id' => $s['workspace_id'],
        'display_name' => 'Rival', 'competitor_class' => 'DIRECT', 'status' => 'ACTIVE', 'created_at' => $createdAt,
    ]);
    $signalId = Ids::new();
    $db->insert('company_signals', [
        'company_signal_id' => $signalId, 'schema_version' => '1', 'tenant_id' => $s['tenant_id'], 'workspace_id' => $s['workspace_id'],
        'competitor_id' => $competitorId, 'signal_class' => $class, 'interpretation_kind' => $kind,
        'confidence_band' => 'HIGH', 'summary_safe' => 'Rival changed pricing', 'status' => $status, 'created_at' => $createdAt,
    ]);

    return $signalId;
};

$hypothesis = static function (array $s, string $catalogueId, string $signalId, string $status = 'ADMITTED') use ($db): string {
    $productId = Ids::new();
    $db->insert('customer_products', [
        'customer_product_id' => $productId, 'schema_version' => '1', 'tenant_id' => $s['tenant_id'],
        'customer_product_catalogue_id' => $catalogueId, 'product_key' => 'p-' . substr($productId, 0, 8),
        'display_name' => 'Widget', 'product_offer_kind' => 'PRODUCT', 'attributes_json' => '["pricing"]',
        'product_status' => 'ACTIVE', 'created_at' => '2026-06-01T00:00:00.000Z',
    ]);
    $fitId = Ids::new();
    $db->insert('fit_hypotheses', [
        'fit_hypothesis_id' => $fitId, 'schema_version' => '1', 'tenant_id' => $s['tenant_id'], 'workspace_id' => $s['workspace_id'],
        'customer_product_id' => $productId, 'company_signal_id' => $signalId, 'fit_band' => 'PROBABLE',
        'claim_kind' => 'REASON_TO_INVESTIGATE', 'uncertainty_explicit' => 1, 'fit_assessment_digest' => str_repeat('e', 64),
        'status' => $status, 'created_at' => '2026-06-01T00:00:00.000Z',
    ]);

    return $fitId;
};

// ============================================================ profiles

$first = $p03->seedProfiles($scope['tenant_id'], $scope['workspace_id']);
Assert::same(P03Assurance::CATEGORIES, array_keys($first), 'all three P03 categories are seeded');
foreach ($first as $category => $profileId) {
    Assert::same(
        ['audit_category' => $category, 'status' => 'ACTIVE', 'does_not_grant_authority' => 1],
        array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $db->one(
            'SELECT audit_category, status, does_not_grant_authority FROM audit_profiles WHERE audit_profile_id = ?',
            [$profileId]
        )),
        "{$category}: an ACTIVE profile that grants no authority",
    );
}
Assert::same($first, $p03->seedProfiles($scope['tenant_id'], $scope['workspace_id']), 'seeding again reuses, never mints rivals');
$db->run("UPDATE audit_profiles SET status = 'SUSPENDED' WHERE audit_profile_id = ?", [$first['BUYER_INTENT_INVARIANT']]);
$p03->seedProfiles($scope['tenant_id'], $scope['workspace_id']);
Assert::same(
    'SUSPENDED',
    (string) $db->scalar('SELECT status FROM audit_profiles WHERE audit_profile_id = ?', [$first['BUYER_INTENT_INVARIANT']]),
    'reseeding never reactivates a profile an operator suspended',
);
Assert::same(
    'AUDIT_PROFILE_INACTIVE',
    $p03->runBuyerIntentInvariantAudit(['audit_profile_id' => $first['BUYER_INTENT_INVARIANT']])->reasonCode(),
    'and the suspended profile refuses to back an audit',
);
$db->run("UPDATE audit_profiles SET status = 'ACTIVE' WHERE audit_profile_id = ?", [$first['BUYER_INTENT_INVARIANT']]);

// Registration composes the same seeding when — and only when — it is given the assurance owner.
$registrationScope = Seed::tenantWorkspace($db, $clock, 'p03reg');
$registrationArgs = [$db, $clock, new M03Registry($db, $clock), new Eligibility($db, $clock), new ToolRegistry($db, $clock), new PolicySets($db, $clock), new Entitlements($db, $clock)];
$withAssurance = (new P03Registration(...[...$registrationArgs, $p03]))->registerLeadQualification([
    'tenant_id' => $registrationScope['tenant_id'],
    'workspace_id' => $registrationScope['workspace_id'],
    'operator_id' => $registrationScope['operator_id'],
]);
Assert::true($withAssurance->isOk(), 'P03 registers with assurance composed');
Assert::same(P03Assurance::CATEGORIES, array_keys($withAssurance->value()['audit_profile_ids']), 'and returns the three seeded profiles');
$bare = Seed::tenantWorkspace($db, $clock, 'p03bare');
$withoutAssurance = (new P03Registration(...$registrationArgs))->registerLeadQualification([
    'tenant_id' => $bare['tenant_id'], 'workspace_id' => $bare['workspace_id'], 'operator_id' => $bare['operator_id'],
]);
Assert::same([], $withoutAssurance->value()['audit_profile_ids'], 'without it, registration seeds no profile — the existing composition is unchanged');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM audit_profiles WHERE tenant_id = ?', [$bare['tenant_id']]), 'and writes none');

// ============================================================ long windows are storable (migration 245)

$ninety = 90 * $DAY;
$long = $catalogue($scope, ['error_after_ms' => $ninety, 'recheck_terms_after_ms' => $ninety, 'warn_after_ms' => 60 * $DAY]);
Assert::same(
    [$ninety, $ninety, 60 * $DAY],
    array_map('intval', array_values($db->one(
        'SELECT error_after_ms, recheck_terms_after_ms, warn_after_ms FROM source_license_cards WHERE source_license_card_id = ?',
        [$long['card_id']]
    ))),
    'a 90-day window round-trips exactly — it overflowed the 32-bit INT translation before migration 245',
);
Assert::same(
    'bigint',
    strtolower((string) $db->scalar(
        "SELECT data_type FROM information_schema.columns WHERE table_schema = DATABASE()
           AND table_name = 'source_license_cards' AND column_name = 'recheck_terms_after_ms'"
    )),
    'the recheck window column is 64-bit, matching canonical SQLite INTEGER',
);
$db->insert('evidence_objects', [
    'evidence_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
    'source_ref_id' => $long['source_ref_id'], 'evidence_type' => 'UPLOADED_SOURCE_CAPTURE',
    'captured_content_reference_safe' => 'upload://catalogue', 'content_hash' => str_repeat('a', 64),
    'capture_method' => 'UPLOAD', 'captured_at' => '2026-05-01T00:00:00.000Z',
    'permitted_use_scope' => 'customer_owned_tenant_scoped/internal_fit_matching', 'evidence_status' => 'CAPTURED', 'sensitivity_class' => 'INTERNAL_DEMO',
]);
$db->insert('source_terms_reviews', [
    'source_terms_review_id' => Ids::new(), 'schema_version' => '1', 'source_license_card_id' => $long['card_id'],
    'robots_policy_checked' => 1, 'terms_policy_checked' => 1, 'reviewed_by' => 'operator', 'reviewed_at' => '2026-05-01T00:00:00.000Z',
]);
$within = $p03->runCatalogueFreshnessAudit([
    'audit_profile_id' => $first['CATALOGUE_FRESHNESS'],
    'customer_product_catalogue_id' => $long['catalogue_id'],
    'as_of' => '2026-07-01T00:00:00.000Z',
])->value();
Assert::same([false, []], [$within['failed'], $within['reason_codes']], 'a 61-day-old capture and review are inside 90-day windows');
$beyond = $p03->runCatalogueFreshnessAudit([
    'audit_profile_id' => $first['CATALOGUE_FRESHNESS'],
    'customer_product_catalogue_id' => $long['catalogue_id'],
    'as_of' => '2026-08-01T00:00:00.001Z',
])->value();
Assert::same(
    ['CATALOGUE_CAPTURE_STALE', 'CATALOGUE_TERMS_RECHECK_OVERDUE'],
    $beyond['reason_codes'],
    'and the same rows are stale one millisecond past 92 days',
);

// ============================================================ malformed knobs refuse before writing

$before = $auditRows();
foreach ([
    ['AS_OF_NOT_CANONICAL', fn () => $p03->runBuyerIntentInvariantAudit(['audit_profile_id' => $first['BUYER_INTENT_INVARIANT'], 'as_of' => 'not-a-date'])],
    ['AS_OF_NOT_CANONICAL', fn () => $p03->runBuyerIntentInvariantAudit(['audit_profile_id' => $first['BUYER_INTENT_INVARIANT'], 'as_of' => 'tomorrow'])],
    ['AS_OF_NOT_CANONICAL', fn () => $p03->runBuyerIntentInvariantAudit(['audit_profile_id' => $first['BUYER_INTENT_INVARIANT'], 'as_of' => '2026-07-01'])],
    ['FRESHNESS_WINDOW_INVALID', fn () => $p03->runCatalogueFreshnessAudit(['audit_profile_id' => $first['CATALOGUE_FRESHNESS'], 'customer_product_catalogue_id' => $long['catalogue_id'], 'freshness_window_ms' => -1])],
    ['FRESHNESS_WINDOW_INVALID', fn () => $p03->runSignalPairingFreshnessAudit(['audit_profile_id' => $first['SIGNAL_PAIRING_FRESHNESS'], 'fit_hypothesis_id' => Ids::new()])],
    ['REQUESTER_TYPE_INVALID', fn () => $p03->runBuyerIntentInvariantAudit(['audit_profile_id' => $first['BUYER_INTENT_INVARIANT'], 'requested_by_actor_type' => 'MODEL'])],
    ['REQUESTER_ID_INVALID', fn () => $p03->runBuyerIntentInvariantAudit(['audit_profile_id' => $first['BUYER_INTENT_INVARIANT'], 'requested_by_actor_id' => "x' OR 1=1"])],
] as [$code, $call]) {
    Assert::same($code, $call()->reasonCode(), "refused: {$code}");
}
Assert::same($before, $auditRows(), 'a malformed knob writes no run, finding or incident — the canonical NaN fail-open is not reproduced');

// ============================================================ the sweep

$sweepScope = Seed::tenantWorkspace($db, $clock, 'p03sweep');
$sibling = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $sibling, 'tenant_id' => $sweepScope['tenant_id'], 'workspace_key' => 'sib-' . substr($sibling, 0, 8),
    'schema_version' => '1', 'display_name' => 'Sibling', 'workspace_type' => 'TEST', 'environment_class' => 'TEST',
    'external_side_effects_allowed' => 0, 'active_v1_capability_ceiling' => 'READ_ONLY', 'status' => 'ACTIVE',
    'created_at' => '2026-06-01T00:00:00.000Z',
]);

// No profiles yet: the sweep reports that, and invents none.
$unprovisioned = $p03->sweep([
    'tenant_id' => $sweepScope['tenant_id'], 'workspace_id' => $sweepScope['workspace_id'],
    'signal_pairing_freshness_window_ms' => 7 * $DAY, 'as_of' => '2026-07-01T00:00:00.000Z',
]);
Assert::same(P03Assurance::CATEGORIES, $unprovisioned->value()['missing_profiles'], 'a sweep with no profiles reports all three missing');
Assert::same([], $unprovisioned->value()['audits'], 'and runs nothing');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM audit_profiles WHERE tenant_id = ?', [$sweepScope['tenant_id']]), 'and seeds nothing');

$sweepProfiles = $p03->seedProfiles($sweepScope['tenant_id'], $sweepScope['workspace_id']);
$fresh = $catalogue($sweepScope);
$tenantLevel = $catalogue($sweepScope, [], null);
$stale = $catalogue($sweepScope);
$catalogues->changeCatalogueStatus($stale['catalogue_id'], 'SUSPENDED', 'test');
$siblingOwned = $catalogue(['tenant_id' => $sweepScope['tenant_id'], 'workspace_id' => $sibling]);
$freshSignal = $signal($sweepScope, 'RELEASED', '2026-06-30T00:00:00.000Z');
$staleSignal = $signal($sweepScope, 'RELEASED', '2026-05-01T00:00:00.000Z');
$okFit = $hypothesis($sweepScope, $fresh['catalogue_id'], $freshSignal);
$staleFit = $hypothesis($sweepScope, $fresh['catalogue_id'], $staleSignal);
$rejectedFit = $hypothesis($sweepScope, $fresh['catalogue_id'], $staleSignal, 'REJECTED');

$snapshot = static fn (): string => hash('sha256', json_encode([
    $db->all('SELECT * FROM customer_product_catalogues WHERE tenant_id = ? ORDER BY customer_product_catalogue_id', [$sweepScope['tenant_id']]),
    $db->all('SELECT * FROM fit_hypotheses WHERE tenant_id = ? ORDER BY fit_hypothesis_id', [$sweepScope['tenant_id']]),
    $db->all('SELECT * FROM company_signals WHERE tenant_id = ? ORDER BY company_signal_id', [$sweepScope['tenant_id']]),
    $db->all('SELECT * FROM source_license_cards WHERE tenant_id = ? ORDER BY source_license_card_id', [$sweepScope['tenant_id']]),
]));
$auditedBefore = $snapshot();

$bounded = $p03->sweep([
    'tenant_id' => $sweepScope['tenant_id'], 'workspace_id' => $sweepScope['workspace_id'],
    'signal_pairing_freshness_window_ms' => 7 * $DAY, 'as_of' => '2026-07-01T00:00:00.000Z', 'max_targets' => 2,
]);
Assert::same('SWEEP_TARGETS_EXCEED_BOUND', $bounded->reasonCode(), 'three catalogues over a bound of two refuse the whole sweep');
$boundedTotal = $p03->sweep([
    'tenant_id' => $sweepScope['tenant_id'], 'workspace_id' => $sweepScope['workspace_id'],
    'signal_pairing_freshness_window_ms' => 7 * $DAY, 'as_of' => '2026-07-01T00:00:00.000Z', 'max_targets' => 4,
]);
Assert::same('SWEEP_TARGETS_EXCEED_BOUND', $boundedTotal->reasonCode(), 'three catalogues and two live hypotheses (each family within 4, five in total) still exceed a bound of four: the bound is on the total');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM audit_runs WHERE tenant_id = ?', [$sweepScope['tenant_id']]), 'before auditing any prefix of it');

$swept = $p03->sweep([
    'tenant_id' => $sweepScope['tenant_id'], 'workspace_id' => $sweepScope['workspace_id'],
    'signal_pairing_freshness_window_ms' => 7 * $DAY, 'as_of' => '2026-07-01T00:00:00.000Z',
])->value();
Assert::same([], $swept['missing_profiles'], 'every profile is present');
$byCategory = [];
foreach ($swept['audits'] as $row) {
    $byCategory[$row['audit_category']][$row['target']] = $row;
}
Assert::same(
    [$fresh['catalogue_id'], $stale['catalogue_id'], $tenantLevel['catalogue_id']],
    array_values(array_intersect([$fresh['catalogue_id'], $stale['catalogue_id'], $tenantLevel['catalogue_id']], array_keys($byCategory['CATALOGUE_FRESHNESS']))),
    'this workspace\'s catalogues and the tenant-level one are swept',
);
Assert::same(3, count($byCategory['CATALOGUE_FRESHNESS']), 'and nothing else');
Assert::false(isset($byCategory['CATALOGUE_FRESHNESS'][$siblingOwned['catalogue_id']]), "a sibling workspace's catalogue is not swept from here");
Assert::true($byCategory['CATALOGUE_FRESHNESS'][$stale['catalogue_id']]['failed'], 'the suspended catalogue fails');
Assert::notSame(null, $byCategory['CATALOGUE_FRESHNESS'][$stale['catalogue_id']]['incident_case_id'], 'and opens an incident');
Assert::false($byCategory['CATALOGUE_FRESHNESS'][$fresh['catalogue_id']]['failed'], 'the active one passes');
Assert::same([$okFit, $staleFit], array_values(array_intersect([$okFit, $staleFit], array_keys($byCategory['SIGNAL_PAIRING_FRESHNESS']))), 'live hypotheses are swept');
Assert::false(isset($byCategory['SIGNAL_PAIRING_FRESHNESS'][$rejectedFit]), 'a REJECTED hypothesis backs nothing and is not swept');
Assert::true($byCategory['SIGNAL_PAIRING_FRESHNESS'][$staleFit]['failed'], 'the stale pairing fails');
Assert::same(1, count($byCategory['BUYER_INTENT_INVARIANT']), 'the buyer-intent scan runs once for the scope');
Assert::same(2, $swept['failed_count'], 'two failures in total');
Assert::same(0, $swept['refused_count'], 'and no refusal');
Assert::same([true, [], 0], [$swept['complete'], $swept['skipped_categories'], $swept['errored_count']], 'and the sweep says it is complete');
Assert::same($auditedBefore, $snapshot(), 'the sweep changed none of the rows it audited');
Assert::same(
    6,
    (int) $db->scalar("SELECT COUNT(*) FROM audit_runs WHERE tenant_id = ? AND requested_by_actor_type = 'SERVICE'", [$sweepScope['tenant_id']]),
    'a direct sweep records itself as SERVICE',
);

// The M11 rows the audits wrote are themselves append-only.
Assert::throws(
    fn () => $db->run("UPDATE audit_findings SET finding_type = 'CONTROL_PASS' WHERE audit_run_id IN (SELECT audit_run_id FROM audit_runs WHERE tenant_id = ?)", [$sweepScope['tenant_id']]),
    \PDOException::class,
    'a P03 finding cannot be rewritten into a pass',
);

// ============================================================ operator entrypoints

$operator = ['tenant_id' => $sweepScope['tenant_id'], 'workspace_id' => $sweepScope['workspace_id'], 'operator_id' => $sweepScope['operator_id']];
$runsBefore = (int) $db->scalar('SELECT COUNT(*) FROM audit_runs WHERE tenant_id = ?', [$sweepScope['tenant_id']]);
Assert::same('OPERATOR_NOT_AUTHORIZED', $p03->operatorSweep($operator, ['signal_pairing_freshness_window_ms' => 7 * $DAY])->reasonCode(), 'an operator with no role cannot sweep');
Assert::same('OPERATOR_NOT_AUTHORIZED', $p03->operatorStatus($operator)->reasonCode(), 'or read audit status');
Assert::same('OPERATOR_NOT_AUTHORIZED', $p03->operatorSeedProfiles($operator)->reasonCode(), 'or seed profiles');
Assert::same($runsBefore, (int) $db->scalar('SELECT COUNT(*) FROM audit_runs WHERE tenant_id = ?', [$sweepScope['tenant_id']]), 'and a refused operator writes no audit run');

$leadOnly = Seed::role($db, $clock, 'P03_TEST_LEAD_' . substr(Ids::new(), 0, 6), ['workspace.read', 'prospect.read']);
Seed::membership($db, $clock, $sweepScope['tenant_id'], $sweepScope['workspace_id'], $sweepScope['operator_id'], $leadOnly['role_id']);
Assert::same('OPERATOR_NOT_AUTHORIZED', $p03->operatorSweep($operator, ['signal_pairing_freshness_window_ms' => 7 * $DAY])->reasonCode(), 'reading prospects is not authority to open incidents');

$reviewer = Seed::role($db, $clock, 'P03_TEST_INCIDENT_' . substr(Ids::new(), 0, 6), ['incident.read', 'incident.manage']);
Seed::membership($db, $clock, $sweepScope['tenant_id'], $sweepScope['workspace_id'], $sweepScope['operator_id'], $reviewer['role_id']);
// The operator path evaluates at the clock's now against the cards' own windows: no M11 row can
// record a caller's instant or window, so neither is accepted from an operator.
Assert::same('SWEEP_AS_OF_NOT_ACCEPTED', $p03->operatorSweep($operator, ['signal_pairing_freshness_window_ms' => 7 * $DAY, 'as_of' => '2020-01-01T00:00:00.000Z'])->reasonCode(), 'an operator cannot backdate a sweep');
Assert::same('SWEEP_CATALOGUE_WINDOW_NOT_ACCEPTED', $p03->operatorSweep($operator, ['signal_pairing_freshness_window_ms' => 7 * $DAY, 'freshness_window_ms' => 3650 * $DAY])->reasonCode(), 'nor loosen the cards\' own catalogue windows');
// A passing catalogue created AFTER the failing one is audited after it, so the newest single run is a
// PASS while the scope still has a failure: status must not let the one hide the other.
$clock->advanceMs(60_000);
$lateFresh = $catalogue($sweepScope);
$allowsBefore = (int) $db->scalar("SELECT COUNT(*) FROM access_evaluation_records WHERE actor_id = ? AND resource_type = 'P03_AUDIT_SWEEP' AND decision = 'ALLOW'", [$sweepScope['operator_id']]);
$operatorSwept = $p03->operatorSweep($operator, ['signal_pairing_freshness_window_ms' => 7 * $DAY]);
Assert::true($operatorSwept->isOk(), 'an incident reviewer can sweep');
Assert::true($operatorSwept->value()['as_of'] >= '2026-07-02T00:00:00.000Z' && $operatorSwept->value()['as_of'] <= $clock->now(), 'at the clock\'s now');
Assert::same(null, $operatorSwept->value()['freshness_window_ms'], 'with the cards\' own windows');
Assert::same($allowsBefore + 1, (int) $db->scalar("SELECT COUNT(*) FROM access_evaluation_records WHERE actor_id = ? AND resource_type = 'P03_AUDIT_SWEEP' AND decision = 'ALLOW'", [$sweepScope['operator_id']]), 'and the ALLOW that let the operator sweep is retained as an access record');
$operatorRuns = $db->all(
    'SELECT requested_by_actor_type, requested_by_actor_id FROM audit_runs WHERE tenant_id = ? ORDER BY requested_at DESC LIMIT 6',
    [$sweepScope['tenant_id']]
);
foreach ($operatorRuns as $run) {
    Assert::same(['OPERATOR', $sweepScope['operator_id']], [(string) $run['requested_by_actor_type'], (string) $run['requested_by_actor_id']], 'the run names the operator who asked');
}
$status = $p03->operatorStatus($operator)->value();
$catalogueStatus = $status['categories']['CATALOGUE_FRESHNESS'];
Assert::same('ACTIVE', $catalogueStatus['profile_status'], 'status reports the profile');
$byOperatorTarget = [];
foreach ($operatorSwept->value()['audits'] as $row) {
    $byOperatorTarget[$row['audit_category']][$row['target']] = $row;
}
Assert::false($byOperatorTarget['CATALOGUE_FRESHNESS'][$lateFresh['catalogue_id']]['failed'], 'the late catalogue passes');
Assert::same(
    ['FAILING', 4, [[$stale['catalogue_id']]]],
    [$catalogueStatus['posture'], $catalogueStatus['targets_audited'], array_column($catalogueStatus['failing_targets'], 'target_object_refs')],
    'status reports catalogue freshness FAILING with exactly the suspended catalogue — a later passing target cannot hide it',
);
Assert::same(['CATALOGUE_NOT_ACTIVE'], $catalogueStatus['failing_targets'][0]['reason_codes'], 'with its reason');
$pairing = $status['categories']['SIGNAL_PAIRING_FRESHNESS'];
Assert::same(['FAILING', 2, [[$staleFit]]], [$pairing['posture'], $pairing['targets_audited'], array_column($pairing['failing_targets'], 'target_object_refs')], 'signal pairing is FAILING for the stale pairing only');
Assert::same(['PASSING', 1, []], [$status['categories']['BUYER_INTENT_INVARIANT']['posture'], $status['categories']['BUYER_INTENT_INVARIANT']['targets_audited'], $status['categories']['BUYER_INTENT_INVARIANT']['failing_targets']], 'the scope-wide buyer-intent scan is one target');
Assert::same('COMPLETED', $pairing['latest_run']['status'], 'and the newest run is still shown, for what it is');
Assert::same('OPERATOR_NOT_AUTHORIZED', $p03->operatorSeedProfiles($operator)->reasonCode(), 'managing incidents is not authority to configure profiles');
$foreignScope = Seed::tenantWorkspace($db, $clock, 'p03foreignop');
Assert::same(
    'OPERATOR_NOT_AUTHORIZED',
    $p03->operatorSweep(['tenant_id' => $foreignScope['tenant_id'], 'workspace_id' => $foreignScope['workspace_id'], 'operator_id' => $sweepScope['operator_id']], ['signal_pairing_freshness_window_ms' => 7 * $DAY])->reasonCode(),
    "an operator's role in one tenant is no authority in another",
);
Assert::same('OPERATOR_SCOPE_INVALID', $p03->operatorStatus(['tenant_id' => 'x', 'workspace_id' => 'y', 'operator_id' => 'z'])->reasonCode(), 'malformed scope identifiers refuse');

// ============================================================ the CLI

$envFile = Harness::childEnvFile();
try {
    $cli = static function (array $args) use ($envFile): array {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/ops/assurance/p03.php'], $args, ['--env=' . $envFile]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), json_decode((string) $stdout, true), (string) $stderr];
    };
    $ids = ['--tenant-id=' . $sweepScope['tenant_id'], '--workspace-id=' . $sweepScope['workspace_id'], '--operator-id=' . $sweepScope['operator_id']];
    [$code, $out] = $cli(['status', ...$ids]);
    Assert::same([0, true], [$code, $out['ok'] ?? null], 'the CLI reports status to an authorized operator');
    [$code, $out] = $cli(['seed-profiles', ...$ids]);
    Assert::same([1, 'OPERATOR_NOT_AUTHORIZED'], [$code, $out['reason_code'] ?? null], 'and refuses profile configuration it does not hold');
    [$code, , $err] = $cli(['sweep', ...$ids]);
    Assert::same(2, $code, 'a sweep without a signal window is a usage error');
    Assert::contains('--signal-window-days is required', $err, 'saying which flag is missing');
    [$code, , $err] = $cli(['sweep', ...$ids, '--signal-window-days=0']);
    Assert::same(2, $code, 'a zero-day window is refused at the boundary');
    [$code] = $cli(['sweep', ...$ids, '--signal-window-days=7', '--as-of=2026-07-01T00:00:00.000Z']);
    Assert::same(2, $code, 'the CLI has no --as-of: an operator sweep runs at the clock\'s now');
    [$code] = $cli(['sweep', ...$ids, '--signal-window-days=7', '--catalogue-window-days=3650']);
    Assert::same(2, $code, 'and no --catalogue-window-days: the cards\' own windows govern');
    [$code, $out] = $cli(['sweep', ...$ids, '--signal-window-days=7']);
    Assert::same([0, true, true], [$code, $out['ok'] ?? null, $out['result']['complete'] ?? null], 'an authorized CLI sweep completes');
    Assert::true(($out['result']['failed_count'] ?? 0) >= 2, 'and reports its failures');
    $db->run("UPDATE audit_profiles SET status = 'SUSPENDED' WHERE audit_profile_id = ?", [$sweepProfiles['BUYER_INTENT_INVARIANT']]);
    [$code, $out] = $cli(['sweep', ...$ids, '--signal-window-days=7']);
    Assert::same([3, false], [$code, $out['result']['complete'] ?? null], 'a sweep that skipped a category exits 3, not 0');
    Assert::same([['audit_category' => 'BUYER_INTENT_INVARIANT', 'profile_status' => 'SUSPENDED']], $out['result']['skipped_categories'] ?? null, 'naming the category and why it was skipped');
    [$code] = $cli(['publish', ...$ids]);
    Assert::same(2, $code, 'there is no other command');
} finally {
    @unlink($envFile);
}
