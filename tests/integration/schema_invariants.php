<?php

declare(strict_types=1);

/**
 * Schema invariants, proved against real MariaDB.
 *
 * A migration without its constraint test is not conformed. These prove that the pins the
 * canonical schema relies on actually reject their violating value HERE — a CHECK that MariaDB
 * silently ignores would leave the application looking governed while being unguarded.
 */

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$now = (new Clock())->now();

/** Assert that `$work` is rejected by the database. */
$rejects = static function (callable $work, string $message) use (&$db): void {
    try {
        $work();
        Assert::fail($message . ' — the write was ACCEPTED');
    } catch (\PDOException) {
        Assert::pass();
    }
};

// --- structural totals --------------------------------------------------------------------------

// The baseline list is frozen from the documented 188-table projection at 0790475,
// not inferred from whichever tables happen to exist. Additions are separately classified so
// a renamed, missing or unexpected table cannot be silently treated as canonical.
$inventory = json_decode(
    (string) file_get_contents(__DIR__ . '/../fixtures/schema_table_inventory.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$baselineCanonical = $inventory['baseline_canonical_tables'];
$canonicalRepairs = $inventory['canonical_repair_tables'];
$baselineLoopia = $inventory['baseline_loopia_tables'];
$loopiaExtensions = $inventory['loopia_extension_tables'];
Assert::same(188, count($baselineCanonical), 'the original pinned canonical inventory remains explicit');
Assert::same(1, count($canonicalRepairs), 'one separately reviewed upstream repair table is projected');
Assert::same(8, count($baselineLoopia), 'the original host-adaptation inventory remains explicit');
Assert::same(6, count($loopiaExtensions), 'six bounded model/Monitor host-adaptation tables are declared');

// Tables added by the Monitor completion plan, declared per reviewed migration. Each count is
// stated here rather than read from the fixture, so a table cannot arrive by editing JSON alone.
$completion = $inventory['completion_plan_tables'];
Assert::same(
    ['210_monitor_execution_controls.sql' => 6, '220_monitor_evaluation_records.sql' => 4, '225_monitor_setup_receipts.sql' => 1, '230_monitor_attempt_recovery.sql' => 2],
    array_map('count', $completion),
    'completion-plan tables are declared per reviewed migration',
);
$declaredCompletionMigrations = array_map('basename', $inventory['provenance']['completion_plan_migrations']);
Assert::same(
    array_keys($completion),
    $declaredCompletionMigrations,
    'completion-plan migration provenance names exactly the migrations whose tables are classified',
);
$completionTables = array_merge(...array_values($completion));
$expected = array_merge($baselineCanonical, $canonicalRepairs, $baselineLoopia, $loopiaExtensions, $completionTables);
Assert::same(203 + count($completionTables), count(array_unique($expected)), 'all table classifications are disjoint and duplicate-free');
sort($expected, SORT_STRING);
$present = array_map(
    static fn (array $r): string => (string) $r['t'],
    $db->all(
        "SELECT table_name AS t FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name <> 'schema_migrations'"
    )
);
sort($present, SORT_STRING);
Assert::same(
    $expected,
    $present,
    sprintf('the exact declared table set exists: 189 canonical + %d Loopia adapters, with no missing or extra table', 14 + count($completionTables)),
);

$nonInnodb = (int) $db->scalar(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'"
);
Assert::same(0, $nonInnodb, 'every table is InnoDB (transactions, FKs, row locking)');

Assert::true(
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'"
    ) > 400,
    'foreign keys are declared, not application-only',
);
Assert::true(
    (int) $db->scalar('SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()') > 400,
    'CHECK constraints are declared',
);
Assert::true(
    (int) $db->scalar('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()') > 200,
    'append-only triggers are installed',
);

// The excluded P02 offering-twin family must not exist at all: an unreachable table is one thing,
// a table that exists and could be written is another.
foreach (['offering_twins', 'offering_versions', 'offering_claims', 'released_offering_twin_snapshots'] as $excluded) {
    Assert::same(
        0,
        (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$excluded]
        ),
        "the excluded offering-twin table {$excluded} does not exist (V1-GAP-097)",
    );
}

// --- seed the minimum authority chain -----------------------------------------------------------

$tenantId = Ids::new();
$db->insert('tenants', [
    'tenant_id' => $tenantId,
    'tenant_key' => 'invariant-test',
    'schema_version' => '1',
    'display_name' => 'Invariant Test',
    'tenant_type' => 'TEST',
    'status' => 'ACTIVE',
    'created_at' => $now,
]);

$workspaceId = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $workspaceId,
    'tenant_id' => $tenantId,
    'workspace_key' => 'ws-1',
    'schema_version' => '1',
    'display_name' => 'Workspace',
    'workspace_type' => 'TEST',
    'environment_class' => 'TEST',
    'external_side_effects_allowed' => 0,
    'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
    'status' => 'ACTIVE',
    'created_at' => $now,
]);

// --- THE action-class ceiling pin ---------------------------------------------------------------
// `external_side_effects_allowed = 0` is CHECK-pinned upstream. It is the single storage fact that
// makes "no external action" structural rather than a promise.

$rejects(
    static fn () => $db->insert('workspaces', [
        'workspace_id' => Ids::new(),
        'tenant_id' => $tenantId,
        'workspace_key' => 'ws-evil',
        'schema_version' => '1',
        'display_name' => 'External effects',
        'workspace_type' => 'TEST',
        'environment_class' => 'TEST',
        'external_side_effects_allowed' => 1,
        'active_v1_capability_ceiling' => 'EXECUTE_CONSEQUENTIAL_ACTION',
        'status' => 'ACTIVE',
        'created_at' => $now,
    ]),
    'external_side_effects_allowed = 1 is rejected by the CHECK pin',
);

$rejects(
    static fn () => $db->run('UPDATE workspaces SET external_side_effects_allowed = 1 WHERE workspace_id = ?', [$workspaceId]),
    'external_side_effects_allowed cannot be flipped to 1 by UPDATE either',
);

// --- closed vocabulary CHECKs --------------------------------------------------------------------

$rejects(
    static fn () => $db->insert('tenants', [
        'tenant_id' => Ids::new(),
        'tenant_key' => 'bad-type',
        'schema_version' => '1',
        'display_name' => 'Bad',
        'tenant_type' => 'NOT_IN_VOCABULARY',
        'status' => 'ACTIVE',
        'created_at' => $now,
    ]),
    'a value outside the closed vocabulary is rejected',
);

$competitorId = Ids::new();
$db->insert('competitors', [
    'competitor_id' => $competitorId,
    'schema_version' => '1',
    'tenant_id' => $tenantId,
    'workspace_id' => $workspaceId,
    'display_name' => 'Competitor A',
    'competitor_class' => 'DIRECT',
    'status' => 'ACTIVE',
    'created_at' => $now,
]);

$rejects(
    static fn () => $db->insert('competitors', [
        'competitor_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $tenantId,
        'workspace_id' => $workspaceId,
        'display_name' => 'Bad class',
        'competitor_class' => 'MADE_UP',
        'status' => 'ACTIVE',
        'created_at' => $now,
    ]),
    'competitor_class is confined to its closed vocabulary',
);

// --- foreign keys ---------------------------------------------------------------------------------

$rejects(
    static fn () => $db->insert('competitors', [
        'competitor_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => Ids::new(),   // no such tenant
        'workspace_id' => $workspaceId,
        'display_name' => 'Orphan',
        'competitor_class' => 'DIRECT',
        'status' => 'ACTIVE',
        'created_at' => $now,
    ]),
    'a competitor cannot reference a non-existent tenant',
);

// --- append-only ledgers ----------------------------------------------------------------------------

$transitionId = Ids::new();
$db->insert('competitor_status_transitions', [
    'transition_id' => $transitionId,
    'schema_version' => '1',
    'competitor_id' => $competitorId,
    'from_status' => null,
    'to_status' => 'ACTIVE',
    'initiated_by_actor_type' => 'SYSTEM',
    'initiated_by_actor_id' => Ids::new(),
    'reason_summary_safe' => 'registered',
    'transitioned_at' => $now,
]);

$rejects(
    static fn () => $db->run(
        'UPDATE competitor_status_transitions SET to_status = ? WHERE transition_id = ?',
        ['ARCHIVED', $transitionId]
    ),
    'an append-only transition ledger rejects UPDATE',
);
$rejects(
    static fn () => $db->run('DELETE FROM competitor_status_transitions WHERE transition_id = ?', [$transitionId]),
    'an append-only transition ledger rejects DELETE',
);

// History survives the attempts — the rollback discipline depends on this.
Assert::same(
    1,
    (int) $db->scalar('SELECT COUNT(*) FROM competitor_status_transitions WHERE transition_id = ?', [$transitionId]),
    'the history row is intact after both rejected mutations',
);

// --- transactions actually roll back -----------------------------------------------------------------

$before = (int) $db->scalar('SELECT COUNT(*) FROM competitors');
try {
    $db->transaction(static function (Database $tx) use ($tenantId, $workspaceId, $now): void {
        $tx->insert('competitors', [
            'competitor_id' => Ids::new(),
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'display_name' => 'Rolled back',
            'competitor_class' => 'DIRECT',
            'status' => 'ACTIVE',
            'created_at' => $now,
        ]);
        throw new \RuntimeException('fault injected after the write');
    });
    Assert::fail('the injected fault should have propagated');
} catch (\RuntimeException) {
    Assert::pass();
}
Assert::same(
    $before,
    (int) $db->scalar('SELECT COUNT(*) FROM competitors'),
    'a transaction that throws leaves NO partial write behind',
);

// Nested transactions roll back as one unit (savepoints), which is what the upstream post-M10
// release-atomicity hardening depends on.
$before = (int) $db->scalar('SELECT COUNT(*) FROM competitors');
try {
    $db->transaction(static function (Database $outer) use ($tenantId, $workspaceId, $now): void {
        $outer->insert('competitors', [
            'competitor_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId, 'display_name' => 'Outer', 'competitor_class' => 'DIRECT',
            'status' => 'ACTIVE', 'created_at' => $now,
        ]);
        $outer->transaction(static function (Database $inner) use ($tenantId, $workspaceId, $now): void {
            $inner->insert('competitors', [
                'competitor_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId, 'display_name' => 'Inner', 'competitor_class' => 'DIRECT',
                'status' => 'ACTIVE', 'created_at' => $now,
            ]);
        });
        throw new \RuntimeException('outer fault after a committed inner block');
    });
    Assert::fail('the outer fault should have propagated');
} catch (\RuntimeException) {
    Assert::pass();
}
Assert::same(
    $before,
    (int) $db->scalar('SELECT COUNT(*) FROM competitors'),
    'an inner savepoint does NOT survive the outer rollback',
);

// --- strict mode: silent truncation is an error ---------------------------------------------------

$rejects(
    static fn () => $db->insert('tenants', [
        'tenant_id' => Ids::new(),
        'tenant_key' => str_repeat('x', 500),   // exceeds VARCHAR(191)
        'schema_version' => '1',
        'display_name' => 'Too long',
        'tenant_type' => 'TEST',
        'status' => 'ACTIVE',
        'created_at' => $now,
    ]),
    'STRICT_ALL_TABLES turns over-length data into an error, not silent truncation',
);

// --- the interpretation-kind pin -----------------------------------------------------------------
// FACT_OBSERVED vs HYPOTHESIS is storage-pinned, so no code path can invent a third state that
// downstream readers would have to guess about.

$kinds = (string) $db->scalar(
    "SELECT check_clause FROM information_schema.check_constraints
     WHERE constraint_schema = DATABASE() AND check_clause LIKE '%FACT_OBSERVED%' LIMIT 1"
);
Assert::contains('FACT_OBSERVED', $kinds, 'interpretation_kind is CHECK-pinned');
Assert::contains('HYPOTHESIS', $kinds, 'interpretation_kind admits exactly the two canonical values');
