<?php

declare(strict_types=1);

/**
 * "At most one ACTIVE stream per scope" — proved against concurrency, not against timing.
 *
 * Canonical states the invariant as a partial unique index:
 *
 *   CREATE UNIQUE INDEX ux_decision_ledger_streams_active_scope
 *     ON decision_ledger_streams (tenant_id, workspace_id, environment_class)
 *     WHERE status = 'ACTIVE';
 *
 * MariaDB has no partial index, and migration 010 followed this repository's standing translation:
 * document the canonical rule, install a non-unique lookup index, enforce the predicate in the
 * application. For most partial indexes that is adequate. It was NOT adequate here, and the reason
 * is worth being precise about rather than glossing: application enforcement of this predicate is
 * a read followed by a write, and two callers can both pass the read before either does the write.
 * The invariant held while nothing raced — which is a statement about load, not about the ledger.
 *
 * Migration 140 makes it a real constraint: a generated column that is NULL for every row the
 * predicate excludes, inside an ordinary UNIQUE index. NULLs are distinct in a unique index, so
 * non-ACTIVE rows never collide, while ACTIVE rows collide exactly when the scope repeats.
 *
 * Everything below runs on TWO connections. A "race" driven through one PDO handle races nothing:
 * the statements serialize on the wire and the second call reads what the first committed.
 */

use Allfather\Loopia\Core\Ids;
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
$ledgerA = new DecisionLedger($db, $clock);
$scope = Seed::tenantWorkspace($db, $clock, 'stream-race');
$tenantId = (string) $scope['tenant_id'];
$workspaceId = (string) $scope['workspace_id'];

$second = Harness::secondConnection();
if ($second === null) {
    Assert::skip('a second connection is required to prove anything about concurrency');

    return;
}
$ledgerB = new DecisionLedger($second, $clock);

// --- the constraint is REAL, and it is the database that says so ------------------------------

Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'decision_ledger_streams'
           AND INDEX_NAME = 'ux_decision_ledger_streams_active_scope' AND NON_UNIQUE = 0
           AND COLUMN_NAME = 'active_scope_guard'"
    ),
    'the active-scope guard is a UNIQUE index in the server, not a comment in a migration',
);

$firstStream = $ledgerA->ensureActiveStream($tenantId, $workspaceId, 'TEST');

// Forging a second ACTIVE row for the same scope, bypassing the module entirely. This is the state
// application-only enforcement could not prevent under load, and the storage must refuse it.
try {
    $db->insert('decision_ledger_streams', [
        'decision_ledger_stream_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $tenantId,
        'workspace_id' => $workspaceId,
        'environment_class' => 'TEST',
        'stream_key' => 'ledger:test-forged',
        'genesis_or_initial_anchor_hash' => str_repeat('0', 64),
        'hash_algorithm' => 'sha256',
        'current_head_record_id' => null,
        'current_head_chain_hash' => str_repeat('0', 64),
        'head_sequence_number' => 0,
        'status' => 'ACTIVE',
        'created_at' => $clock->now(),
        'activated_at' => $clock->now(),
    ]);
    Assert::fail('a second ACTIVE stream for the same scope should be structurally impossible');
} catch (\PDOException) {
    Assert::pass();
}

// A second ACTIVE row in a DIFFERENT environment is not a violation, and must not be refused.
$demoStream = $ledgerA->ensureActiveStream($tenantId, $workspaceId, 'DEMO');
Assert::notSame($firstStream, $demoStream, 'the guard binds per environment, not per scope');

// Nor is a non-ACTIVE row. The predicate excludes them, so many may coexist — the NULLs in the
// generated column are what make that true.
foreach (['SUSPENDED', 'SEALED', 'SUPERSEDED'] as $index => $status) {
    $db->insert('decision_ledger_streams', [
        'decision_ledger_stream_id' => Ids::new(),
        'schema_version' => '1',
        'tenant_id' => $tenantId,
        'workspace_id' => $workspaceId,
        'environment_class' => 'TEST',
        'stream_key' => 'ledger:test-retired-' . $index,
        'genesis_or_initial_anchor_hash' => str_repeat('0', 64),
        'hash_algorithm' => 'sha256',
        'current_head_record_id' => null,
        'current_head_chain_hash' => str_repeat('0', 64),
        'head_sequence_number' => 0,
        'status' => $status,
        'created_at' => $clock->now(),
        'activated_at' => null,
    ]);
}
Assert::same(
    3,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_ledger_streams
         WHERE tenant_id = ? AND workspace_id = ? AND environment_class = 'TEST' AND status <> 'ACTIVE'",
        [$tenantId, $workspaceId]
    ),
    'and any number of retired streams may coexist beside the ACTIVE one',
);

// Reactivating a retired stream into an occupied scope is refused too — the predicate binds on
// UPDATE, not only on INSERT, which is the half a hand-rolled check usually forgets.
$retiredId = (string) $db->scalar(
    "SELECT decision_ledger_stream_id FROM decision_ledger_streams
     WHERE tenant_id = ? AND workspace_id = ? AND environment_class = 'TEST' AND status = 'SUPERSEDED'",
    [$tenantId, $workspaceId]
);
try {
    $db->run(
        "UPDATE decision_ledger_streams SET status = 'ACTIVE' WHERE decision_ledger_stream_id = ?",
        [$retiredId]
    );
    Assert::fail('reactivating a retired stream into an occupied scope should be refused');
} catch (\PDOException) {
    Assert::pass();
}

// --- THE RACE, reproduced deterministically ---------------------------------------------------
//
// Timing cannot be relied on to hit the window, so the window is held open. Connection A opens a
// transaction and reads the scope, which pins its REPEATABLE READ snapshot at "no stream here".
// Connection B then creates the stream and commits. A now proceeds through the whole of
// ensureActiveStream while its snapshot still shows nothing: its find returns null, its pre-check
// returns null, and its INSERT meets the unique index — which reads committed data, not A's
// snapshot — and is rejected.
//
// That is exactly the interleaving a real race produces, and it is the case that would otherwise
// surface in production as an uncaught duplicate-key error on a governed ledger.

$raceScope = Seed::tenantWorkspace($db, $clock, 'stream-race-window');
$raceTenant = (string) $raceScope['tenant_id'];
$raceWorkspace = (string) $raceScope['workspace_id'];

$db->pdo()->exec('START TRANSACTION');
Assert::same(
    null,
    $ledgerA->findActiveStream($raceTenant, $raceWorkspace, 'TEST'),
    'A opens a transaction and sees no stream — its snapshot is now pinned to that',
);

$winner = $ledgerB->ensureActiveStream($raceTenant, $raceWorkspace, 'TEST');
Assert::true($winner !== '', 'B creates the stream and commits while A is mid-flight');

$loser = $ledgerA->ensureActiveStream($raceTenant, $raceWorkspace, 'TEST');
$db->pdo()->exec('COMMIT');

Assert::same($winner, $loser, 'the loser CONVERGES on the winner rather than raising');
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_ledger_streams
         WHERE tenant_id = ? AND workspace_id = ? AND environment_class = 'TEST'",
        [$raceTenant, $raceWorkspace]
    ),
    'exactly ONE stream row exists — no second ledger history was created',
);
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(DISTINCT genesis_or_initial_anchor_hash) FROM decision_ledger_streams
         WHERE tenant_id = ? AND workspace_id = ?",
        [$raceTenant, $raceWorkspace]
    ),
    'and one genesis head — there is no competing anchor for the scope',
);

// The same window, two DIFFERENT environments: both callers must succeed, with distinct streams.
// A race is only a race within one environment; convergence must not become collapse.
$splitScope = Seed::tenantWorkspace($db, $clock, 'stream-race-split');
$splitTenant = (string) $splitScope['tenant_id'];
$splitWorkspace = (string) $splitScope['workspace_id'];

$db->pdo()->exec('START TRANSACTION');
Assert::same(
    null,
    $ledgerA->findActiveStream($splitTenant, $splitWorkspace, 'TEST'),
    'A pins its snapshot again, this time asking for TEST',
);
$demoWinner = $ledgerB->ensureActiveStream($splitTenant, $splitWorkspace, 'DEMO');
$testFromA = $ledgerA->ensureActiveStream($splitTenant, $splitWorkspace, 'TEST');
$db->pdo()->exec('COMMIT');

Assert::notSame(
    $demoWinner,
    $testFromA,
    'a concurrent DEMO creation does not satisfy a TEST caller — two environments, two streams',
);
Assert::same(
    2,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM decision_ledger_streams
         WHERE tenant_id = ? AND workspace_id = ? AND status = 'ACTIVE'",
        [$splitTenant, $splitWorkspace]
    ),
    'both survive as ACTIVE — one per environment',
);
Assert::equalsList(
    ['DEMO', 'TEST'],
    array_column(
        $db->all(
            "SELECT environment_class FROM decision_ledger_streams
             WHERE tenant_id = ? AND workspace_id = ? AND status = 'ACTIVE'
             ORDER BY environment_class",
            [$splitTenant, $splitWorkspace]
        ),
        'environment_class'
    ),
    'and they are the two environments that were actually asked for',
);
