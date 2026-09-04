<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\FixedClock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Db\Migrator;
use Allfather\Loopia\Support\Env;

/**
 * Test harness: MariaDB access and a migrated, empty schema per suite.
 *
 * Connection settings come from the environment or `.env.testing`; when no database is reachable
 * the harness returns null and the calling suite reports SKIP. It never falls back to SQLite —
 * a result obtained against SQLite would not be a MariaDB parity claim, and recording one as if
 * it were is the specific dishonesty this repository is built to prevent.
 */
final class Harness
{
    public static bool $verbose = false;
    private static ?Database $db = null;
    private static ?string $unavailableReason = null;
    private static bool $migrated = false;

    /** Returns the migrated test database, or null when MariaDB is unreachable. */
    public static function db(): ?Database
    {
        if (self::$db !== null) {
            return self::$db;
        }
        if (self::$unavailableReason !== null) {
            return null;
        }

        $env = self::env();
        try {
            $db = Database::connect(
                $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
                $env->int('DB_PORT', 3306),
                $env->get('DB_NAME', 'allfather_test') ?? 'allfather_test',
                $env->get('DB_USER', 'allfather') ?? 'allfather',
                $env->get('DB_PASSWORD', 'allfather_dev') ?? 'allfather_dev',
                $env->get('DB_SOCKET'),
            );
        } catch (\PDOException $error) {
            self::$unavailableReason = 'MariaDB unreachable: ' . $error->getMessage();

            return null;
        }

        self::$db = $db;

        return $db;
    }

    public static function unavailableReason(): string
    {
        return self::$unavailableReason ?? 'MariaDB unreachable';
    }

    /**
     * A database with the full schema applied and every table truncated.
     * Migrations run once per process; truncation runs per suite so suites cannot leak into
     * each other through leftover rows.
     */
    public static function freshDb(): ?Database
    {
        $db = self::db();
        if ($db === null) {
            return null;
        }

        if (!self::$migrated) {
            $migrator = new Migrator($db, dirname(__DIR__, 2) . '/database/migrations');
            $migrator->up(self::$verbose ? static fn (string $m) => print("  migrate: {$m}\n") : null);
            self::$migrated = true;
        }

        self::truncateAll($db);

        return $db;
    }

    public static function truncateAll(Database $db): void
    {
        $pdo = $db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $db->all(
            'SELECT table_name AS t FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = ? AND table_name <> ?',
            ['BASE TABLE', 'schema_migrations']
        );
        foreach ($tables as $row) {
            $pdo->exec('TRUNCATE TABLE ' . Database::identifier((string) $row['t']));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** A clock pinned for reproducibility; conformance normalizes timestamps regardless. */
    public static function clock(string $at = '2026-01-01T00:00:00.000Z'): FixedClock
    {
        return new FixedClock($at, 1);
    }

    private static function env(): Env
    {
        $root = dirname(__DIR__, 2);
        foreach (['/.env.testing', '/.env'] as $candidate) {
            if (is_file($root . $candidate)) {
                return Env::load($root . $candidate);
            }
        }

        return Env::fromArray([
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
            'DB_NAME' => getenv('DB_NAME') ?: 'allfather_test',
            'DB_USER' => getenv('DB_USER') ?: 'allfather',
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'allfather_dev',
        ]);
    }
}
