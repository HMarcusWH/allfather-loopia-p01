<?php

declare(strict_types=1);

/**
 * Usage metering — recording, and provably not charging.
 *
 * The whole value of this module is a distinction it must never blur: it writes down what was
 * consumed, and it does not bill anybody for it. Charging a customer is a consequential external
 * action, a class this projection refuses at four independent planes, and the fastest way for that
 * boundary to erode is a metering module that grows an invoice.
 *
 * So the tests below are mostly about the ABSENCE: no charge column that can say otherwise, no
 * revision of a recorded quantity, no payment path anywhere in the file.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\Metering;
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
$entitlements = new Entitlements($db, $clock);
$metering = new Metering($db, $clock, $entitlements);

// ============================================================ recording

$recorded = $metering->recordUsage([
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'quantity' => 1_500,
    'unit_rate_minor_units' => 2,
    'correlation_ref' => 'release:' . Ids::new(),
]);
Assert::true($recorded->isOk(), 'usage records');
Assert::same(3_000, $recorded->value()['billable_minor_units'], 'billable = quantity x unit rate, in minor units');

$row = $db->one(
    'SELECT * FROM usage_metering_records WHERE usage_metering_record_id = ?',
    [$recorded->value()['usage_metering_record_id']]
);
Assert::same('TOKEN_USAGE', (string) $row['metered_dimension'], 'the default dimension is TOKEN_USAGE');
Assert::same(1, (int) $row['no_external_charge_executed'], 'and the row asserts that NO charge was executed');

// THE RATE IS SNAPSHOTTED. A later price change must not rewrite what a past period cost.
Assert::same(
    3_000,
    (int) $row['billable_minor_units'],
    'the billable amount is stored, not derived — history is priced at the rates in force then',
);
$metering->recordUsage([
    'tenant_id' => $scope['tenant_id'],
    'quantity' => 1_000,
    'unit_rate_minor_units' => 9,
]);
Assert::same(
    3_000,
    (int) $db->scalar(
        'SELECT billable_minor_units FROM usage_metering_records WHERE usage_metering_record_id = ?',
        [$recorded->value()['usage_metering_record_id']]
    ),
    'and a later record at a different rate leaves the earlier one exactly as it was',
);

// ============================================================ it cannot become billing

// STORAGE, not code. A row claiming a charge was executed is unwritable, so no future edit to this
// module — or any other — can record one.
Assert::throws(
    static fn () => $db->insert('usage_metering_records', [
        'usage_metering_record_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $scope['tenant_id'],
        'metered_dimension' => 'TOKEN_USAGE',
        'quantity' => 1,
        'unit_rate_minor_units' => 1,
        'billable_minor_units' => 1,
        'no_external_charge_executed' => 0,
        'recorded_at' => $clock->now(),
    ]),
    \PDOException::class,
    'a row asserting a charge WAS executed cannot be written at all',
);

// Append-only: a recorded quantity cannot be quietly revised after the fact.
Assert::throws(
    static fn () => $db->run(
        'UPDATE usage_metering_records SET quantity = 1 WHERE usage_metering_record_id = ?',
        [$recorded->value()['usage_metering_record_id']]
    ),
    \PDOException::class,
    'and a recorded quantity cannot be revised — the ledger is append-only',
);

// The module contains no payment path. A tripwire, because the way this boundary actually breaks is
// somebody adding one convenient call — and it scans EXECUTABLE code only. The docblock says the
// words "invoice" and "payment" precisely to state what the module does not do, and a check that
// tripped on saying so would push the explanation out of the file.
$code = implode('', array_map(
    static fn (array|string $t): string => is_string($t)
        ? $t
        : (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]),
    token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/app/Product/P01/Metering.php'))
));
foreach (['curl', 'stripe', 'charge(', 'invoice', 'payment', 'socket', 'mail('] as $forbidden) {
    Assert::false(
        stripos($code, $forbidden) !== false,
        "the metering module's CODE contains no '{$forbidden}' — it records, it does not collect",
    );
}

// ============================================================ refusals

foreach ([
    ['INVALID_QUANTITY', ['quantity' => -1]],
    ['INVALID_UNIT_RATE', ['quantity' => 1, 'unit_rate_minor_units' => -5]],
    ['METERED_DIMENSION_UNKNOWN', ['quantity' => 1, 'metered_dimension' => 'SEAT_MONTHS']],
] as [$expected, $override]) {
    Assert::same(
        $expected,
        $metering->recordUsage(['tenant_id' => $scope['tenant_id'], ...$override])->reasonCode(),
        "{$expected}: refused by name rather than clamped or coerced",
    );
}
// A negative quantity is refused, NOT clamped to zero: a negative consumption is a caller bug or an
// attempt to reverse a record in a table that is append-only by design.
Assert::same(
    2,
    (int) $db->scalar('SELECT COUNT(*) FROM usage_metering_records WHERE tenant_id = ?', [$scope['tenant_id']]),
    'and none of the three refusals wrote a row',
);

// Every dimension the storage admits, the module admits — and nothing else.
$stored = [];
foreach ($db->all(
    "SELECT CHECK_CLAUSE c FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'usage_metering_records'
       AND CHECK_CLAUSE LIKE '%metered_dimension%'"
) as $constraint) {
    preg_match_all("/'([A-Z_]+)'/", (string) $constraint['c'], $m);
    $stored = array_merge($stored, $m[1]);
}
sort($stored);
$declared = Metering::METERED_DIMENSIONS;
sort($declared);
Assert::same($declared, $stored, 'the module and the storage agree on the dimension vocabulary exactly');

// ============================================================ the summary is not an invoice

$tier = $entitlements->defineTier('p01_monitor_metered', 'P01 Monitor', 249_900, []);
$entitlements->subscribe($scope['tenant_id'], $tier->value()['subscription_tier_id']);

$summary = $metering->summarizeTenantUsage($scope['tenant_id']);
Assert::same(2, $summary['record_count'], 'the summary counts the recorded rows');
Assert::same(2_500, $summary['total_quantity'], 'totals their quantity');
Assert::same(12_000, $summary['total_usage_billable_minor_units'], 'and their billable amounts');
Assert::same(249_900, $summary['monthly_fee_minor_units'], 'paired with the tier base fee');
Assert::same('p01_monitor_metered', $summary['tier_key'], 'naming the tier it came from');
Assert::same(
    true,
    $summary['no_external_charge_executed'],
    'and RESTATING that nothing was charged — a number that looks like an invoice total is exactly '
    . 'the thing somebody will eventually treat as one',
);

// Regenerable, not stored: there is no summary table for the ledger to disagree with.
Assert::same(
    0,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%usage_summar%'"
    ),
    'the summary is recomputed on read — no stored summary can drift from the rows it describes',
);
