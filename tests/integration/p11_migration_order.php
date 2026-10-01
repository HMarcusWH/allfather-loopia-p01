<?php

declare(strict_types=1);

/**
 * P11 migration ordering on an EXISTING projection.
 *
 * Historical migration 900 may already be recorded before P11 introduces lower-numbered 240.
 * Fresh-install lexical ordering alone does not prove that upgrade. This test removes only P11's
 * table/ledger row from an otherwise current database, leaves 900 applied, then proves Migrator
 * applies 240 without reinterpretation or drift and leaves 900 untouched.
 */

use Allfather\Loopia\Db\Migrator;
use Allfather\Loopia\Tests\{Assert,Harness};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}

$before900 = $db->one(
    "SELECT content_digest,statement_count FROM schema_migrations WHERE migration_id='900_deferred_foreign_keys'",
);
Assert::true($before900 !== null, 'historical migration 900 is already applied in the upgrade simulation');
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id='240_provider_rotation_events'",
    ),
    'fresh current schema initially includes P11 migration 240',
);

$db->pdo()->exec('DROP TRIGGER IF EXISTS trg_provider_credential_rotation_events_no_update');
$db->pdo()->exec('DROP TRIGGER IF EXISTS trg_provider_credential_rotation_events_no_delete');
$db->pdo()->exec('DROP TABLE provider_credential_rotation_events');
$db->run(
    "DELETE FROM schema_migrations WHERE migration_id='240_provider_rotation_events'",
);

Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id='900_deferred_foreign_keys'",
    ),
    '900 remains applied while lower-numbered 240 is pending',
);

$migrator = new Migrator($db, dirname(__DIR__, 2) . '/database/migrations');
$up = $migrator->up();
Assert::true(
    in_array('240_provider_rotation_events', $up['applied'], true),
    'an existing database with 900 already applied still applies newly introduced 240',
);
Assert::true(
    in_array('900_deferred_foreign_keys', $up['skipped'], true),
    '900 is recognized as historical/applied rather than replayed after 240',
);
Assert::same([], $up['drifted'], 'the lower-number upgrade introduces no migration drift');
Assert::same(
    1,
    (int) $db->scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name='provider_credential_rotation_events'",
    ),
    'P11 table exists after the upgrade',
);

$after900 = $db->one(
    "SELECT content_digest,statement_count FROM schema_migrations WHERE migration_id='900_deferred_foreign_keys'",
);
Assert::same($before900, $after900, 'P11 does not reinterpret the already-applied 900 ledger row');

$repeat = $migrator->up();
Assert::same([], $repeat['applied'], 'a second upgrade application is a no-op');
Assert::same([], $repeat['drifted'], 'and remains undrifted');
Assert::true(
    in_array('240_provider_rotation_events', $repeat['skipped'], true),
    'P11 migration is ledgered after the upgrade',
);
