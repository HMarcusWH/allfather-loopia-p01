<?php

declare(strict_types=1);

/**
 * P08 migration 245 on an EXISTING projection, upgraded in place.
 *
 * Migration 245 widens `source_license_cards.warn_after_ms`, `error_after_ms` and
 * `recheck_terms_after_ms` from INT to BIGINT. A fresh install applies it in lexical order, but a
 * deployed projection is not a fresh install: it already ledgered every merged migration —
 * including the historical, higher-numbered `900_deferred_foreign_keys` — and holds rows written
 * under the 32-bit columns. Lexical ordering alone proves nothing about that upgrade.
 *
 * So this test rewinds a current database to the pre-P08 merged state (the three columns exactly as
 * migration 020 created them, 245 not ledgered, 900 and everything else applied), writes cards
 * through the real P01 owner under that old shape, and then runs the ordinary Migrator. It proves:
 *
 *   1. every historical migration, 900 included, is already ledgered with its file digest;
 *   2. the upgrade neither replays nor drifts 900;
 *   3. 245 — and only 245 — applies, exactly once, ledgered with its file digest;
 *   4. a second migration pass is a no-op;
 *   5. rows written before the upgrade survive byte for byte;
 *   6. a 30-day window, refused by the old shape, is stored and read back exactly — and the 020
 *      `>= 0` CHECKs still hold on the widened columns;
 *   7. the upgraded schema is exactly the fresh-install schema: columns, CHECKs, triggers, indexes,
 *      table set (and that table set is the committed inventory), and the migration ledger.
 *
 * Migration 020 is not edited. Runs in both strict MariaDB lanes like every integration test.
 */

use Allfather\Loopia\Db\Migrator;
use Allfather\Loopia\Product\P01\SourceCards;
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
$windows = ['warn_after_ms', 'error_after_ms', 'recheck_terms_after_ms'];
$thirtyDays = 30 * 24 * 60 * 60 * 1000; // 2,592,000,000 — above INT's 2,147,483,647

// ---------------------------------------------------------------- what "exactly current" means
$schemaShape = static function () use ($db): array {
    $one = static fn (string $sql): array => $db->all($sql);
    return [
        'columns' => $one("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, ORDINAL_POSITION FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'source_license_cards' ORDER BY ORDINAL_POSITION"),
        'checks' => $one("SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
                          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'source_license_cards' ORDER BY CONSTRAINT_NAME"),
        'triggers' => $one("SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS
                            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'source_license_cards' ORDER BY TRIGGER_NAME"),
        'indexes' => $one("SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'source_license_cards' ORDER BY INDEX_NAME, SEQ_IN_INDEX"),
        'foreign_keys' => $one("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'source_license_cards' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, COLUMN_NAME"),
        'tables' => array_column($one("SELECT TABLE_NAME AS t FROM information_schema.TABLES
                                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"), 't'),
    ];
};
$ledger = static fn (): array => $db->all('SELECT migration_id, content_digest, statement_count FROM schema_migrations ORDER BY migration_id');
$fileDigests = array_column($migrator->available(), 'digest', 'id');
$fresh = $schemaShape();
$freshLedger = $ledger();
Assert::same(['bigint(20)', 'bigint(20)', 'bigint(20)'], array_values(array_map(
    static fn (string $c): string => (string) array_column($fresh['columns'], 'COLUMN_TYPE', 'COLUMN_NAME')[$c],
    $windows,
)), 'a fresh install has the widened BIGINT columns');
Assert::true(count($fresh['checks']) >= 3, 'and the 020 window CHECKs');

// ---------------------------------------------------------------- 1. rewind to the pre-P08 merged state
$db->pdo()->exec('ALTER TABLE source_license_cards MODIFY COLUMN warn_after_ms INT, MODIFY COLUMN error_after_ms INT, MODIFY COLUMN recheck_terms_after_ms INT');
$db->run('DELETE FROM schema_migrations WHERE migration_id = ?', [$id245]);
$rewound = array_column($schemaShape()['columns'], 'COLUMN_TYPE', 'COLUMN_NAME');
Assert::same(['int(11)', 'int(11)', 'int(11)'], [$rewound['warn_after_ms'], $rewound['error_after_ms'], $rewound['recheck_terms_after_ms']], 'the rewound columns are exactly what migration 020 created');
$applied = $migrator->applied();
$historical = array_values(array_diff(array_keys($fileDigests), [$id245]));
foreach ($historical as $id) {
    Assert::same($fileDigests[$id], $applied[$id]['content_digest'] ?? null, "historical {$id} is ledgered with its file digest");
}
Assert::false(isset($applied[$id245]), '245 is the only pending migration');
Assert::true(strcmp('900_deferred_foreign_keys', $id245) > 0 && isset($applied['900_deferred_foreign_keys']), 'and the higher-numbered historical 900 is already applied');
$before900 = $db->one("SELECT content_digest, statement_count, applied_at FROM schema_migrations WHERE migration_id = '900_deferred_foreign_keys'");

// The defect 245 repairs, reproduced on the old shape: an ordinary 30-day window cannot be stored.
$scope = Seed::tenantWorkspace($db, $clock, 'p08-upgrade');
$cards = new SourceCards($db, $clock);
$project = static function (string $name, array $values) use ($cards, $scope): array {
    return $cards->project([
        'tenant_id' => $scope['tenant_id'], 'source_name' => $name, 'source_url_safe' => 'https://sources.example/' . $name,
        'source_type' => 'manual', 'allowed_use_class' => 'derived_summary_allowed', 'license_class' => 'OPEN_CC0',
        'review_status' => 'approved', 'data_ownership' => 'TENANT_OWNED',
    ] + $values);
};
$refusedBefore = false;
try {
    $project('thirty-day-before-245', ['recheck_terms_after_ms' => $thirtyDays]);
} catch (\PDOException) {
    $refusedBefore = true;
}
Assert::true($refusedBefore, 'before 245, the strict 32-bit column refuses a 30-day window outright');
Assert::same(0, (int) $db->scalar("SELECT COUNT(*) FROM source_license_cards WHERE source_name = 'thirty-day-before-245'"), 'and nothing of that card was left behind');

// Rows a deployed projection already holds, written through the owner under the old shape.
$existing = [
    'one-day' => [86400000, 172800000, 604800000],
    'int-max' => [2147483647, 2147483647, 2147483647],
    'zero' => [0, 0, 0],
    'unset' => [null, null, null],
];
foreach ($existing as $name => [$warn, $error, $recheck]) {
    $project($name, ['warn_after_ms' => $warn, 'error_after_ms' => $error, 'recheck_terms_after_ms' => $recheck]);
}
$rows = static fn (): array => $db->all('SELECT * FROM source_license_cards ORDER BY source_license_card_id');
$beforeRows = $rows();
Assert::same(count($existing), count($beforeRows), 'the pre-upgrade cards are stored');

// ---------------------------------------------------------------- 2-3. the upgrade
$up = $migrator->up();
Assert::same([$id245], $up['applied'], '245, and nothing else, applies to the existing projection');
Assert::same([], $up['drifted'], 'with no migration drift');
Assert::true(in_array('900_deferred_foreign_keys', $up['skipped'], true), '900 is recognized as applied, not replayed');
Assert::same(count($historical), count($up['skipped']), 'every historical migration is skipped');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM schema_migrations WHERE migration_id = ?', [$id245]), '245 is ledgered exactly once');
Assert::same($fileDigests[$id245], (string) $db->scalar('SELECT content_digest FROM schema_migrations WHERE migration_id = ?', [$id245]), 'with the digest of the file that ran');
Assert::same($before900, $db->one("SELECT content_digest, statement_count, applied_at FROM schema_migrations WHERE migration_id = '900_deferred_foreign_keys'"), '900 keeps its original ledger row');

// ---------------------------------------------------------------- 4. a second pass is a no-op
$ledgerAfterUpgrade = $ledger();
$repeat = $migrator->up();
Assert::same([[], []], [$repeat['applied'], $repeat['drifted']], 'a second migration pass applies nothing and finds no drift');
Assert::same($ledgerAfterUpgrade, $ledger(), 'and leaves the ledger untouched');

// ---------------------------------------------------------------- 5. existing rows survive
Assert::same($beforeRows, $rows(), 'every pre-upgrade card survives byte for byte, INT maximum included');

// ---------------------------------------------------------------- 6. the repaired behaviour
$long = $project('thirty-day-after-245', ['warn_after_ms' => $thirtyDays, 'error_after_ms' => $thirtyDays + 1, 'recheck_terms_after_ms' => 3 * $thirtyDays]);
$stored = $db->one('SELECT warn_after_ms, error_after_ms, recheck_terms_after_ms FROM source_license_cards WHERE source_license_card_id = ?', [$long['source_license_card_id']]);
Assert::same([$thirtyDays, $thirtyDays + 1, 3 * $thirtyDays], array_map('intval', array_values($stored)), 'after 245, 30- and 90-day windows are stored and read back exactly');
foreach ($windows as $column) {
    $negative = false;
    try {
        $db->run("UPDATE source_license_cards SET {$column} = -1 WHERE source_license_card_id = ?", [$long['source_license_card_id']]);
    } catch (\PDOException) {
        $negative = true;
    }
    Assert::true($negative, "the 020 CHECK still refuses a negative {$column} on the widened column");
}

// ---------------------------------------------------------------- 7. exactly current
$upgraded = $schemaShape();
foreach (['columns', 'checks', 'triggers', 'indexes', 'foreign_keys', 'tables'] as $part) {
    Assert::same($fresh[$part], $upgraded[$part], "the upgraded {$part} are exactly the fresh-install {$part}");
}
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
