<?php

declare(strict_types=1);

/**
 * P08 migrations 245 and 246 on an EXISTING projection, upgraded in place.
 *
 *   245  widens `source_license_cards.warn_after_ms`, `error_after_ms`, `recheck_terms_after_ms`
 *        from INT to BIGINT (a 30-day window did not fit in 32 bits);
 *   246  widens `audit_runs.target_object_refs` and `incident_evaluation_requests.related_object_refs`
 *        from TEXT to LONGTEXT (a buyer-intent finding over ~1,680 violators did not fit in 64 KiB).
 *
 * A fresh install applies both in lexical order, but a deployed projection is not a fresh install:
 * it already ledgered every merged migration — including the historical, higher-numbered
 * `900_deferred_foreign_keys` — and holds rows written under the narrow columns. Lexical ordering
 * alone proves nothing about that upgrade.
 *
 * So this test rewinds a current database to the pre-P08 merged state (the five columns exactly as
 * migrations 020 and 010 created them, 245 and 246 not ledgered, 900 and everything else applied),
 * writes rows through the real owners under that old shape, and then runs the ordinary Migrator. It
 * proves:
 *
 *   1. every historical migration, 900 included, is already ledgered with its file digest;
 *   2. the upgrade neither replays nor drifts 900;
 *   3. 245 and 246 — and only they — apply, exactly once each, ledgered with their file digests;
 *   4. a second migration pass is a no-op;
 *   5. rows written before the upgrade survive byte for byte;
 *   6. what the old shape refused is now stored and read back exactly: a 30-day window, and ref
 *      lists larger than 64 KiB — and the 020 `>= 0` CHECKs still hold on the widened columns;
 *   7. the upgraded schema is exactly the fresh-install schema for every touched table (columns,
 *      CHECKs, triggers, indexes, foreign keys), the table set is the committed inventory, and the
 *      migration ledger is the fresh-install ledger.
 *
 * Migrations 010 and 020 are not edited. Runs in both strict MariaDB lanes like every integration test.
 */

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Migrator;
use Allfather\Loopia\Product\P01\SourceCards;
use Allfather\Loopia\Product\P03\Assurance as P03Assurance;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();
$root = dirname(__DIR__, 2);
$migrator = new Migrator($db, $root . '/database/migrations');
$id245 = '245_source_card_window_width';
$id246 = '246_assurance_refs_width';
$windows = ['warn_after_ms', 'error_after_ms', 'recheck_terms_after_ms'];
$thirtyDays = 30 * 24 * 60 * 60 * 1000; // 2,592,000,000 — above INT's 2,147,483,647
$touched = ['source_license_cards', 'audit_runs', 'incident_evaluation_requests'];

// ---------------------------------------------------------------- what "exactly current" means
$schemaShape = static function () use ($db, $touched): array {
    $shape = [];
    foreach ($touched as $table) {
        $q = static fn (string $sql): array => $db->all($sql, [$table]);
        $shape[$table] = [
            'columns' => $q('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, ORDINAL_POSITION, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'),
            'checks' => $q('SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
                            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME'),
            'triggers' => $q('SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS
                              WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? ORDER BY TRIGGER_NAME'),
            'indexes' => $q('SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX'),
            'foreign_keys' => $q('SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, COLUMN_NAME'),
        ];
    }
    $shape['tables'] = array_column($db->all("SELECT TABLE_NAME AS t FROM information_schema.TABLES
                                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"), 't');

    return $shape;
};
$columnType = static fn (array $shape, string $table, string $column): string => (string) array_column($shape[$table]['columns'], 'COLUMN_TYPE', 'COLUMN_NAME')[$column];
$ledger = static fn (): array => $db->all('SELECT migration_id, content_digest, statement_count FROM schema_migrations ORDER BY migration_id');
$fileDigests = array_column($migrator->available(), 'digest', 'id');
$fresh = $schemaShape();
$freshLedger = $ledger();
Assert::same(['bigint(20)', 'bigint(20)', 'bigint(20)'], array_map(static fn (string $c): string => $columnType($fresh, 'source_license_cards', $c), $windows), 'a fresh install has the widened BIGINT window columns');
Assert::same(['longtext', 'longtext'], [$columnType($fresh, 'audit_runs', 'target_object_refs'), $columnType($fresh, 'incident_evaluation_requests', 'related_object_refs')], 'and the widened LONGTEXT ref columns');
Assert::true(count($fresh['source_license_cards']['checks']) >= 3, 'and the 020 window CHECKs');

// ---------------------------------------------------------------- 1. rewind to the pre-P08 merged state
$db->pdo()->exec('ALTER TABLE source_license_cards MODIFY COLUMN warn_after_ms INT, MODIFY COLUMN error_after_ms INT, MODIFY COLUMN recheck_terms_after_ms INT');
$db->pdo()->exec("ALTER TABLE audit_runs MODIFY COLUMN target_object_refs TEXT NOT NULL DEFAULT '[]'");
$db->pdo()->exec("ALTER TABLE incident_evaluation_requests MODIFY COLUMN related_object_refs TEXT NOT NULL DEFAULT '[]'");
$db->run('DELETE FROM schema_migrations WHERE migration_id IN (?, ?)', [$id245, $id246]);
$rewound = $schemaShape();
Assert::same(['int(11)', 'int(11)', 'int(11)'], array_map(static fn (string $c): string => $columnType($rewound, 'source_license_cards', $c), $windows), 'the rewound window columns are exactly what migration 020 created');
Assert::same(['text', 'text'], [$columnType($rewound, 'audit_runs', 'target_object_refs'), $columnType($rewound, 'incident_evaluation_requests', 'related_object_refs')], 'and the rewound ref columns exactly what migration 010 created');
$applied = $migrator->applied();
$historical = array_values(array_diff(array_keys($fileDigests), [$id245, $id246]));
foreach ($historical as $id) {
    Assert::same($fileDigests[$id], $applied[$id]['content_digest'] ?? null, "historical {$id} is ledgered with its file digest");
}
Assert::false(isset($applied[$id245]) || isset($applied[$id246]), '245 and 246 are the only pending migrations');
Assert::true(strcmp('900_deferred_foreign_keys', $id246) > 0 && isset($applied['900_deferred_foreign_keys']), 'and the higher-numbered historical 900 is already applied');
$before900 = $db->one("SELECT content_digest, statement_count, applied_at FROM schema_migrations WHERE migration_id = '900_deferred_foreign_keys'");

// The defects the repairs address, reproduced on the old shape.
$scope = Seed::tenantWorkspace($db, $clock, 'p08-upgrade');
$cards = new SourceCards($db, $clock);
$project = static function (string $name, array $values) use ($cards, $scope): array {
    return $cards->project([
        'tenant_id' => $scope['tenant_id'], 'source_name' => $name, 'source_url_safe' => 'https://sources.example/' . $name,
        'source_type' => 'manual', 'allowed_use_class' => 'derived_summary_allowed', 'license_class' => 'OPEN_CC0',
        'review_status' => 'approved', 'data_ownership' => 'TENANT_OWNED',
    ] + $values);
};
$refused = static function (callable $write): bool {
    try {
        $write();
    } catch (\PDOException) {
        return true;
    }
    return false;
};
Assert::true($refused(static fn () => $project('thirty-day-before-245', ['recheck_terms_after_ms' => $thirtyDays])), 'before 245, the strict 32-bit column refuses a 30-day window outright');
Assert::same(0, (int) $db->scalar("SELECT COUNT(*) FROM source_license_cards WHERE source_name = 'thirty-day-before-245'"), 'and nothing of that card was left behind');

$m11 = new M11Assurance($db, $clock, new DecisionLedger($db, $clock));
$profiles = (new P03Assurance($db, $clock, $m11))->seedProfiles($scope['tenant_id'], $scope['workspace_id']);
$bigRefs = json_encode(array_map(static fn (): string => Ids::new(), range(1, 1800)), JSON_THROW_ON_ERROR);
Assert::true(strlen($bigRefs) > 65535, 'the large ref list is over the 64 KiB TEXT limit');
$auditRun = static fn (string $refs): array => [
    'audit_run_id' => Ids::new(), 'schema_version' => '1', 'audit_profile_id' => $profiles['BUYER_INTENT_INVARIANT'],
    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'audit_category' => 'BUYER_INTENT_INVARIANT',
    'target_object_refs' => $refs, 'requested_by_actor_type' => 'SYSTEM', 'status' => 'COMPLETED', 'requested_at' => $clock->now(),
];
Assert::true($refused(static fn () => $db->insert('audit_runs', $auditRun($bigRefs))), 'before 246, a run naming more than ~1,680 targets cannot be recorded');
Assert::true($refused(static fn () => $m11->openIncident($scope['tenant_id'], $scope['workspace_id'], 'M08', ['BUYER_INTENT_OVERCLAIM_PERSISTED'], 'S2', 'DISCLOSURE_OR_OVERCLAIM', 'oversized', json_decode($bigRefs, true))), 'nor can an incident relating that many objects be opened');

// Rows a deployed projection already holds, written through the owners under the old shape.
$existing = [
    'one-day' => [86400000, 172800000, 604800000],
    'int-max' => [2147483647, 2147483647, 2147483647],
    'zero' => [0, 0, 0],
    'unset' => [null, null, null],
];
foreach ($existing as $name => [$warn, $error, $recheck]) {
    $project($name, ['warn_after_ms' => $warn, 'error_after_ms' => $error, 'recheck_terms_after_ms' => $recheck]);
}
$smallRefs = json_encode(array_map(static fn (): string => Ids::new(), range(1, 1600)), JSON_THROW_ON_ERROR);
Assert::true(strlen($smallRefs) <= 65535, 'a ref list just under the old limit');
$db->insert('audit_runs', $auditRun($smallRefs));
$opened = $m11->openIncident($scope['tenant_id'], $scope['workspace_id'], 'M08', ['BUYER_INTENT_OVERCLAIM_PERSISTED'], 'S2', 'DISCLOSURE_OR_OVERCLAIM', 'existing', json_decode($smallRefs, true));
Assert::true($opened->isOk(), 'an incident under the old limit opens under the old shape');
$rows = static fn (): array => [
    'cards' => $db->all('SELECT * FROM source_license_cards ORDER BY source_license_card_id'),
    'runs' => $db->all('SELECT * FROM audit_runs ORDER BY audit_run_id'),
    'requests' => $db->all('SELECT * FROM incident_evaluation_requests ORDER BY incident_evaluation_request_id'),
];
$beforeRows = $rows();
Assert::same([count($existing), 1, 1], [count($beforeRows['cards']), count($beforeRows['runs']), count($beforeRows['requests'])], 'the pre-upgrade rows are stored');

// ---------------------------------------------------------------- 2-3. the upgrade
$up = $migrator->up();
Assert::same([$id245, $id246], $up['applied'], '245 and 246, and nothing else, apply to the existing projection, in order');
Assert::same([], $up['drifted'], 'with no migration drift');
Assert::true(in_array('900_deferred_foreign_keys', $up['skipped'], true), '900 is recognized as applied, not replayed');
Assert::same(count($historical), count($up['skipped']), 'every historical migration is skipped');
foreach ([$id245, $id246] as $id) {
    Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM schema_migrations WHERE migration_id = ?', [$id]), "{$id} is ledgered exactly once");
    Assert::same($fileDigests[$id], (string) $db->scalar('SELECT content_digest FROM schema_migrations WHERE migration_id = ?', [$id]), "{$id} is ledgered with the digest of the file that ran");
}
Assert::same($before900, $db->one("SELECT content_digest, statement_count, applied_at FROM schema_migrations WHERE migration_id = '900_deferred_foreign_keys'"), '900 keeps its original ledger row');

// ---------------------------------------------------------------- 4. a second pass is a no-op
$ledgerAfterUpgrade = $ledger();
$repeat = $migrator->up();
Assert::same([[], []], [$repeat['applied'], $repeat['drifted']], 'a second migration pass applies nothing and finds no drift');
Assert::same($ledgerAfterUpgrade, $ledger(), 'and leaves the ledger untouched');

// ---------------------------------------------------------------- 5. existing rows survive
Assert::same($beforeRows, $rows(), 'every pre-upgrade card, run and incident request survives byte for byte, INT maximum included');

// ---------------------------------------------------------------- 6. the repaired behaviour
$long = $project('thirty-day-after-245', ['warn_after_ms' => $thirtyDays, 'error_after_ms' => $thirtyDays + 1, 'recheck_terms_after_ms' => 3 * $thirtyDays]);
$stored = $db->one('SELECT warn_after_ms, error_after_ms, recheck_terms_after_ms FROM source_license_cards WHERE source_license_card_id = ?', [$long['source_license_card_id']]);
Assert::same([$thirtyDays, $thirtyDays + 1, 3 * $thirtyDays], array_map('intval', array_values($stored)), 'after 245, 30- and 90-day windows are stored and read back exactly');
foreach ($windows as $column) {
    Assert::true($refused(static fn () => $db->run("UPDATE source_license_cards SET {$column} = -1 WHERE source_license_card_id = ?", [$long['source_license_card_id']])), "the 020 CHECK still refuses a negative {$column} on the widened column");
}
$bigRun = $auditRun($bigRefs);
$db->insert('audit_runs', $bigRun);
Assert::same($bigRefs, (string) $db->scalar('SELECT target_object_refs FROM audit_runs WHERE audit_run_id = ?', [$bigRun['audit_run_id']]), 'after 246, a run naming 1,800 targets is recorded and read back exactly');
$bigIncident = $m11->openIncident($scope['tenant_id'], $scope['workspace_id'], 'M08', ['BUYER_INTENT_OVERCLAIM_PERSISTED'], 'S2', 'DISCLOSURE_OR_OVERCLAIM', 'oversized', json_decode($bigRefs, true));
Assert::true($bigIncident->isOk(), 'and an incident relating 1,800 objects opens');
Assert::same($bigRefs, (string) $db->scalar(
    'SELECT r.related_object_refs FROM incident_cases c JOIN incident_evaluation_requests r ON r.incident_evaluation_request_id = c.incident_evaluation_request_id WHERE c.incident_case_id = ?',
    [$bigIncident->value()['incident_case_id']]
), 'with every related ref kept');

// ---------------------------------------------------------------- 7. exactly current
$upgraded = $schemaShape();
foreach ($touched as $table) {
    foreach (['columns', 'checks', 'triggers', 'indexes', 'foreign_keys'] as $part) {
        Assert::same($fresh[$table][$part], $upgraded[$table][$part], "the upgraded {$table} {$part} are exactly the fresh-install {$part}");
    }
}
Assert::same($fresh['tables'], $upgraded['tables'], 'the upgraded table set is the fresh-install table set');
$strip = static fn (array $rows): array => array_map(static fn (array $r): array => [$r['migration_id'], $r['content_digest'], (int) $r['statement_count']], $rows);
Assert::same($strip($freshLedger), $strip($ledger()), 'and the migration ledger is exactly the fresh-install ledger');
$inventory = json_decode((string) file_get_contents($root . '/tests/fixtures/schema_table_inventory.json'), true, 512, JSON_THROW_ON_ERROR);
$expected = array_merge(
    $inventory['baseline_canonical_tables'], $inventory['canonical_repair_tables'], $inventory['baseline_loopia_tables'],
    $inventory['loopia_extension_tables'], ...array_values($inventory['completion_plan_tables']), ...[['schema_migrations']],
);
sort($expected, SORT_STRING);
$actual = $upgraded['tables'];
sort($actual, SORT_STRING);
Assert::same($expected, $actual, 'the upgraded table set is exactly the committed schema inventory');
