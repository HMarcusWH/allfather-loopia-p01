<?php

declare(strict_types=1);

/**
 * P08 — the projection-owned parts of the P03 assurance sweep, where canonical has nothing to match:
 *
 *   - profile seeding is serialized, so two concurrent seeds cannot mint rival ACTIVE profiles;
 *   - the operator acts that change state (seeding, sweeping) retain their M02 ALLOW;
 *   - one sweep per scope at a time;
 *   - an audit that throws is reported as ERRORED and the sweep carries on, ending INCOMPLETE;
 *   - a RETIRED catalogue is swept only while a live hypothesis still cites it;
 *   - a category without an ACTIVE profile is reported with why, and makes the sweep INCOMPLETE.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P03\Assurance as P03Assurance;
use Allfather\Loopia\Product\P03\Catalogue;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock('2026-07-02T00:00:00.000Z');
$p03 = new P03Assurance($db, $clock, new M11Assurance($db, $clock, new DecisionLedger($db, $clock)));
$cards = new SourceCards($db, $clock);
$catalogues = new Catalogue($db, $clock);
$DAY = 86_400_000;

$catalogue = static function (array $s) use ($cards, $catalogues, $clock): string {
    $clock->advanceMs(1000);
    $card = $cards->project([
        'tenant_id' => $s['tenant_id'], 'source_name' => 'Tenant product list', 'source_url_safe' => 'https://tenant.example/' . bin2hex(random_bytes(4)),
        'source_type' => 'manual', 'allowed_use_class' => 'derived_summary_allowed', 'license_class' => 'OPEN_CC0',
        'review_status' => 'approved', 'data_ownership' => 'TENANT_OWNED',
    ]);
    $registered = $catalogues->registerCatalogue($s['tenant_id'], $s['workspace_id'], 'cat-' . bin2hex(random_bytes(4)), 'Catalogue', $card['source_ref_id'], $card['source_license_card_id']);
    Assert::true($registered->isOk(), 'the fixture catalogue registers');

    return (string) $registered->value()['customer_product_catalogue_id'];
};
$liveHypothesisCiting = static function (array $s, string $catalogueId) use ($db): string {
    $competitorId = Ids::new();
    $db->insert('competitors', ['competitor_id' => $competitorId, 'schema_version' => '1', 'tenant_id' => $s['tenant_id'], 'workspace_id' => $s['workspace_id'],
        'display_name' => 'Rival', 'competitor_class' => 'DIRECT', 'status' => 'ACTIVE', 'created_at' => '2026-07-01T00:00:00.000Z']);
    $signalId = Ids::new();
    $db->insert('company_signals', ['company_signal_id' => $signalId, 'schema_version' => '1', 'tenant_id' => $s['tenant_id'], 'workspace_id' => $s['workspace_id'],
        'competitor_id' => $competitorId, 'signal_class' => 'PRICING_CHANGE_SIGNAL', 'interpretation_kind' => 'FACT_OBSERVED', 'confidence_band' => 'HIGH',
        'summary_safe' => 'Rival changed pricing', 'status' => 'RELEASED', 'created_at' => '2026-07-01T00:00:00.000Z']);
    $productId = Ids::new();
    $db->insert('customer_products', ['customer_product_id' => $productId, 'schema_version' => '1', 'tenant_id' => $s['tenant_id'],
        'customer_product_catalogue_id' => $catalogueId, 'product_key' => 'p-' . substr($productId, 0, 8), 'display_name' => 'Widget',
        'product_offer_kind' => 'PRODUCT', 'attributes_json' => '["pricing"]', 'product_status' => 'ACTIVE', 'created_at' => '2026-07-01T00:00:00.000Z']);
    $fitId = Ids::new();
    $db->insert('fit_hypotheses', ['fit_hypothesis_id' => $fitId, 'schema_version' => '1', 'tenant_id' => $s['tenant_id'], 'workspace_id' => $s['workspace_id'],
        'customer_product_id' => $productId, 'company_signal_id' => $signalId, 'fit_band' => 'PROBABLE', 'claim_kind' => 'REASON_TO_INVESTIGATE',
        'uncertainty_explicit' => 1, 'fit_assessment_digest' => str_repeat('e', 64), 'status' => 'ADMITTED', 'created_at' => '2026-07-01T00:00:00.000Z']);

    return $fitId;
};
$sweep = static fn (array $s, array $extra = []) => $p03->sweep(['tenant_id' => $s['tenant_id'], 'workspace_id' => $s['workspace_id'], 'signal_pairing_freshness_window_ms' => 7 * $DAY] + $extra);
$activeProfiles = static fn (array $s): array => array_map('intval', array_column($db->all(
    "SELECT profile_key, COUNT(*) AS n FROM audit_profiles WHERE tenant_id = ? AND workspace_id = ? AND status = 'ACTIVE' GROUP BY profile_key ORDER BY profile_key",
    [$s['tenant_id'], $s['workspace_id']]
), 'n', 'profile_key'));

// ============================================================ seeding is serialized

$scope = Seed::tenantWorkspace($db, $clock, 'p03seedrace');
$second = Harness::secondConnection();
Assert::true($second !== null, 'a second connection to the test database is available');
$p03Second = new P03Assurance($second, $clock, new M11Assurance($second, $clock, new DecisionLedger($second, $clock)));
$second->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
$firstIds = $db->transaction(function () use ($p03, $p03Second, $scope): array {
    // Connection one has seeded but not committed. Connection two must WAIT on the tenant lock, not
    // read "no profile yet" and insert rivals beside it.
    $ids = $p03->seedProfiles($scope['tenant_id'], $scope['workspace_id']);
    $blocked = false;
    try {
        $p03Second->seedProfiles($scope['tenant_id'], $scope['workspace_id']);
    } catch (\PDOException) {
        $blocked = true;
    }
    Assert::true($blocked, 'a concurrent seed of the same tenant waits on the lock instead of inserting rival profiles');

    return $ids;
});
Assert::same($firstIds, $p03Second->seedProfiles($scope['tenant_id'], $scope['workspace_id']), 'once the first seed commits, the second reuses its profiles');
Assert::same(['p03.buyer_intent_invariant' => 1, 'p03.catalogue_freshness' => 1, 'p03.signal_pairing_freshness' => 1], $activeProfiles($scope), 'exactly one ACTIVE profile per key');

// ============================================================ state-changing operator acts leave an access record

$operator = ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'operator_id' => $scope['operator_id']];
$admin = Seed::role($db, $clock, 'P03_SWEEP_ADMIN_' . substr(Ids::new(), 0, 6), ['tenant.administer', 'incident.read', 'incident.manage']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $admin['role_id']);
$allows = static fn (string $resource): int => (int) $db->scalar(
    "SELECT COUNT(*) FROM access_evaluation_records WHERE actor_id = ? AND resource_type = ? AND decision = 'ALLOW'",
    [$scope['operator_id'], $resource]
);
Assert::true($p03->operatorSeedProfiles($operator)->isOk(), 'an administrator can seed the scope\'s profiles');
Assert::same(1, $allows('P03_AUDIT_PROFILE'), 'and who did it is answerable from the retained ALLOW');
Assert::true($p03->operatorStatus($operator)->isOk(), 'reading status is allowed');
Assert::same(0, $allows('P03_AUDIT_STATUS'), 'and, being a read, retains nothing');

// ============================================================ one sweep per scope at a time

$lock = 'p03-sweep:' . substr(hash('sha256', $scope['tenant_id'] . '/' . $scope['workspace_id']), 0, 40);
Assert::same(1, (int) $second->scalar('SELECT GET_LOCK(?, 0)', [$lock]), 'another session holds this scope\'s sweep lock');
$runsBefore = (int) $db->scalar('SELECT COUNT(*) FROM audit_runs WHERE tenant_id = ?', [$scope['tenant_id']]);
Assert::same('SWEEP_IN_PROGRESS', $sweep($scope)->reasonCode(), 'a concurrent sweep of the same scope is refused');
Assert::same($runsBefore, (int) $db->scalar('SELECT COUNT(*) FROM audit_runs WHERE tenant_id = ?', [$scope['tenant_id']]), 'before it audits anything');
$other = Seed::tenantWorkspace($db, $clock, 'p03otherscope');
Assert::true($sweep($other)->isOk(), 'a different scope is not held up by it');
$second->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
Assert::true($sweep($scope)->isOk(), 'and once the lock is free the scope can be swept');

// ============================================================ an audit that throws does not abort the sweep

$broken = $catalogue($scope);
$healthy = $catalogue($scope);
$trigger = 'trg_p03_sweep_test_fault';
$db->pdo()->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON audit_runs FOR EACH ROW
    BEGIN IF NEW.target_object_refs = '[\"{$broken}\"]' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected test fault'; END IF; END");
try {
    $faulted = $sweep($scope);
} finally {
    $db->pdo()->exec("DROP TRIGGER IF EXISTS {$trigger}");
}
Assert::true($faulted->isOk(), 'the sweep still returns its summary');
$byTarget = array_column($faulted->value()['audits'], null, 'target');
Assert::same(['ERRORED', 'AUDIT_ERRORED'], [$byTarget[$broken]['outcome'], $byTarget[$broken]['reason_code']], 'the faulted audit is reported as ERRORED, with no error text');
Assert::same('COMPLETED', $byTarget[$healthy]['outcome'], 'the next target is still audited');
Assert::same('COMPLETED', $byTarget['workspace']['outcome'], 'and so is the buyer-intent scan after it');
Assert::same([false, 1], [$faulted->value()['complete'], $faulted->value()['errored_count']], 'and the sweep says it is INCOMPLETE');
Assert::false(str_contains(json_encode($faulted->value(), JSON_THROW_ON_ERROR), 'injected test fault'), 'the database error message never leaves the sweep');

// ============================================================ RETIRED catalogues

$retiredUnused = $catalogue($scope);
$retiredCited = $catalogue($scope);
$citingFit = $liveHypothesisCiting($scope, $retiredCited);
foreach ([$retiredUnused, $retiredCited] as $retired) {
    Assert::true($catalogues->changeCatalogueStatus($retired, 'RETIRED', 'test')->isOk(), 'the catalogue retires');
}
$targets = array_column($sweep($scope)->value()['audits'], 'target');
Assert::false(in_array($retiredUnused, $targets, true), 'a RETIRED catalogue nothing cites is not swept (it would re-open the same incident forever)');
Assert::true(in_array($retiredCited, $targets, true), 'a RETIRED catalogue a live hypothesis still cites is swept');
Assert::true(in_array($citingFit, $targets, true), 'together with the hypothesis');

// ============================================================ a category without an ACTIVE profile

$profileId = (string) $db->scalar("SELECT audit_profile_id FROM audit_profiles WHERE tenant_id = ? AND workspace_id = ? AND audit_category = 'SIGNAL_PAIRING_FRESHNESS'", [$scope['tenant_id'], $scope['workspace_id']]);
$db->run("UPDATE audit_profiles SET status = 'SUSPENDED' WHERE audit_profile_id = ?", [$profileId]);
$partial = $sweep($scope)->value();
Assert::same([['audit_category' => 'SIGNAL_PAIRING_FRESHNESS', 'profile_status' => 'SUSPENDED']], $partial['skipped_categories'], 'a suspended profile is reported as SUSPENDED, not "missing"');
Assert::same(false, $partial['complete'], 'and the sweep is INCOMPLETE');
Assert::same(['SIGNAL_PAIRING_FRESHNESS'], $partial['missing_profiles'], 'the legacy list still names it');
Assert::same(['MISSING', 'MISSING', 'MISSING'], array_column($sweep(Seed::tenantWorkspace($db, $clock, 'p03unseeded'))->value()['skipped_categories'], 'profile_status'), 'an unseeded scope reports every category MISSING');

// ============================================================ status follows the live target set

$live = Seed::tenantWorkspace($db, $clock, 'p03statuslive');
$p03->seedProfiles($live['tenant_id'], $live['workspace_id']);
$liveOperator = ['tenant_id' => $live['tenant_id'], 'workspace_id' => $live['workspace_id'], 'operator_id' => $live['operator_id']];
$reader = Seed::role($db, $clock, 'P03_STATUS_READER_' . substr(Ids::new(), 0, 6), ['incident.read']);
Seed::membership($db, $clock, $live['tenant_id'], $live['workspace_id'], $live['operator_id'], $reader['role_id']);
$status = static fn (): array => $p03->operatorStatus($liveOperator)->value()['categories'];
Assert::same(['NO_TARGETS', 'NO_TARGETS', 'NOT_AUDITED'], array_column($status(), 'posture'), 'a scope with nothing to audit says so, per category');

$suspendedCatalogue = $catalogue($live);
Assert::same(['INCOMPLETE', 1, 0, 1], (static fn (array $c): array => [$c['posture'], $c['live_targets'], $c['targets_audited'], $c['targets_never_audited']])($status()['CATALOGUE_FRESHNESS']), 'a live target never audited is INCOMPLETE, not PASSING');
Assert::true($catalogues->changeCatalogueStatus($suspendedCatalogue, 'SUSPENDED', 'test')->isOk(), 'the catalogue is suspended');
$staleFitId = $liveHypothesisCiting($live, $catalogue($live));
$db->run("UPDATE company_signals SET created_at = '2026-01-01T00:00:00.000Z' WHERE company_signal_id = (SELECT company_signal_id FROM fit_hypotheses WHERE fit_hypothesis_id = ?)", [$staleFitId]);
Assert::true($sweep($live)->isOk(), 'the scope is swept');
$before = $status();
Assert::same(['FAILING', [[$suspendedCatalogue]]], [$before['CATALOGUE_FRESHNESS']['posture'], array_column($before['CATALOGUE_FRESHNESS']['failing_targets'], 'target_object_refs')], 'the suspended catalogue fails');
Assert::same(['FAILING', [[$staleFitId]]], [$before['SIGNAL_PAIRING_FRESHNESS']['posture'], array_column($before['SIGNAL_PAIRING_FRESHNESS']['failing_targets'], 'target_object_refs')], 'and so does the stale pairing');

// Ordinary remediation: the stale hypothesis is rejected, the suspended catalogue retired (nothing
// cites it). The sweep stops auditing both, so status must stop reporting their old failures.
$db->run("UPDATE fit_hypotheses SET status = 'REJECTED' WHERE fit_hypothesis_id = ?", [$staleFitId]);
Assert::true($catalogues->changeCatalogueStatus($suspendedCatalogue, 'RETIRED', 'test')->isOk(), 'the suspended catalogue retires');
$clock->advanceMs(1000);
$resweep = $sweep($live)->value();
Assert::same([0, true], [$resweep['failed_count'], $resweep['complete']], 'the next sweep finds nothing failing');
$after = $status();
Assert::same(['PASSING', 1, []], [$after['CATALOGUE_FRESHNESS']['posture'], $after['CATALOGUE_FRESHNESS']['live_targets'], $after['CATALOGUE_FRESHNESS']['failing_targets']], 'status agrees: the retired catalogue no longer counts');
Assert::same(['NO_TARGETS', []], [$after['SIGNAL_PAIRING_FRESHNESS']['posture'], $after['SIGNAL_PAIRING_FRESHNESS']['failing_targets']], 'nor does the rejected hypothesis');

// Many sweeps of one target: status reports the latest result, chosen per target in SQL.
for ($i = 0; $i < 5; $i++) {
    $clock->advanceMs(1000);
    $sweep($live);
}
$latestRun = (string) $db->scalar('SELECT audit_run_id FROM audit_runs WHERE tenant_id = ? AND audit_category = ? ORDER BY requested_at DESC, audit_run_id DESC LIMIT 1', [$live['tenant_id'], 'CATALOGUE_FRESHNESS']);
Assert::same([$latestRun], array_column(array_values(array_filter([$status()['CATALOGUE_FRESHNESS']['latest_run']])), 'audit_run_id'), 'the newest run is the one reported');

// A service sweep given an instant passes it to every audit and records it as caller-supplied.
$asOfSweep = $sweep($live, ['as_of' => '2026-07-01T12:00:00.000Z'])->value();
Assert::same('2026-07-01T12:00:00.000Z', $asOfSweep['as_of'], 'the summary names the caller instant');
foreach ($asOfSweep['audits'] as $row) {
    if ($row['audit_category'] !== 'BUYER_INTENT_INVARIANT') {
        $summary = (string) $db->scalar('SELECT summary FROM audit_runs WHERE audit_run_id = ?', [$row['audit_run_id']]);
        Assert::true(str_contains($summary, 'Judged as of 2026-07-01T12:00:00.000Z (caller-supplied) under '), 'and every run records it as caller-supplied: ' . $summary);
    }
}
