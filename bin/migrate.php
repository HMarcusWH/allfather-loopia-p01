<?php

declare(strict_types=1);

/**
 * Apply pending migrations.
 *
 *     php bin/migrate.php            apply everything pending
 *     php bin/migrate.php --status   report without changing anything
 *
 * Connection settings come from the environment, so no credential is ever written into the
 * repository: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, and optionally DB_SOCKET (which
 * Loopia shared hosting generally prefers over a TCP host).
 *
 * DRIFT IS REPORTED, NOT REPAIRED. A migration whose content changed after it was applied means the
 * database and the repository disagree about history, and guessing which is right is how a schema
 * silently diverges. The command says so and exits non-zero; a human decides.
 */

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Db\Migrator;

$statusOnly = in_array('--status', $argv, true);

$db = Database::connect(
    getenv('DB_HOST') ?: '127.0.0.1',
    (int) (getenv('DB_PORT') ?: '3306'),
    getenv('DB_NAME') ?: 'allfather',
    getenv('DB_USER') ?: 'allfather',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_SOCKET') ?: null,
);

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');

if ($statusOnly) {
    $migrator->ensureLedger();
    $applied = [];
    foreach ($db->all('SELECT migration_id FROM schema_migrations ORDER BY migration_id') as $row) {
        $applied[(string) $row['migration_id']] = true;
    }
    foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $file) {
        $id = basename($file, '.sql');
        printf("%-40s %s\n", $id, isset($applied[$id]) ? 'applied' : 'PENDING');
    }
    exit(0);
}

$result = $migrator->up(static fn (string $message) => print("  {$message}\n"));

printf(
    "applied=%d skipped=%d drifted=%d\n",
    count($result['applied']),
    count($result['skipped']),
    count($result['drifted']),
);

if ($result['drifted'] !== []) {
    fwrite(STDERR, "DRIFT: these migrations changed after being applied:\n");
    foreach ($result['drifted'] as $id) {
        fwrite(STDERR, "  {$id}\n");
    }
    fwrite(STDERR, "The database and the repository disagree about history. Resolve by hand.\n");
    exit(1);
}
