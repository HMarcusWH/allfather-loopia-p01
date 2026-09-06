<?php

declare(strict_types=1);

namespace Allfather\Loopia\Db;

/**
 * Ordered, ledgered MariaDB migrations.
 *
 * Loopia shared hosting gives no shell and no migration daemon, so migrations run from a bounded
 * CLI/URL entry point (`ops/migrate.php`). Properties that matter for a governed deployment:
 *
 *  - **Ordered.** Files are applied in filename order; the order is the dependency order recorded
 *    in `docs/SCHEMA_REACHABILITY.md`.
 *  - **Ledgered.** Every applied migration is recorded with its content digest, so a migration
 *    edited after being applied is detected rather than silently re-interpreted.
 *  - **Idempotent to re-run.** Applying twice is a no-op, so a re-triggered deploy is safe.
 *  - **Not silently partial.** DDL in MariaDB is not transactional, so a failed migration is
 *    reported with the exact statement index that failed; the runbook's recovery step is manual
 *    and deliberate rather than an automatic half-rollback that would leave a worse state.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsDir,
    ) {
    }

    public function ensureLedger(): void
    {
        $this->db->run(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                migration_id   VARCHAR(191) NOT NULL,
                content_digest CHAR(64)     NOT NULL,
                applied_at     CHAR(24)     NOT NULL,
                statement_count INT UNSIGNED NOT NULL,
                PRIMARY KEY (migration_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );
    }

    /** @return list<array{id: string, path: string, digest: string}> */
    public function available(): array
    {
        $files = glob(rtrim($this->migrationsDir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $path) {
            $sql = (string) file_get_contents($path);
            $out[] = [
                'id' => basename($path, '.sql'),
                'path' => $path,
                'digest' => hash('sha256', $sql),
            ];
        }

        return $out;
    }

    /** @return array<string, array{content_digest: string, applied_at: string}> */
    public function applied(): array
    {
        $rows = $this->db->all('SELECT migration_id, content_digest, applied_at FROM schema_migrations');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['migration_id']] = [
                'content_digest' => (string) $row['content_digest'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $out;
    }

    /**
     * Apply every pending migration.
     *
     * @return array{applied: list<string>, skipped: list<string>, drifted: list<string>}
     */
    public function up(?callable $log = null): array
    {
        $this->ensureLedger();
        $applied = $this->applied();
        $result = ['applied' => [], 'skipped' => [], 'drifted' => []];

        foreach ($this->available() as $migration) {
            $id = $migration['id'];
            if (isset($applied[$id])) {
                if ($applied[$id]['content_digest'] !== $migration['digest']) {
                    // A migration that changed after being applied means the database and the
                    // repository disagree about what the schema IS. Reporting it is the whole
                    // point of storing the digest; re-running it would compound the divergence.
                    $result['drifted'][] = $id;
                } else {
                    $result['skipped'][] = $id;
                }
                continue;
            }

            $statements = self::splitStatements((string) file_get_contents($migration['path']));
            $index = 0;
            try {
                foreach ($statements as $index => $statement) {
                    $this->db->pdo()->exec($statement);
                }
            } catch (\PDOException $error) {
                throw new \RuntimeException(sprintf(
                    "migration %s failed at statement %d: %s\n--- statement ---\n%s",
                    $id,
                    $index + 1,
                    $error->getMessage(),
                    substr($statements[$index] ?? '', 0, 2000),
                ), 0, $error);
            }

            $this->db->insert('schema_migrations', [
                'migration_id' => $id,
                'content_digest' => $migration['digest'],
                'applied_at' => (new \Allfather\Loopia\Core\Clock())->now(),
                'statement_count' => count($statements),
            ]);
            $result['applied'][] = $id;
            if ($log !== null) {
                $log(sprintf('applied %s (%d statements)', $id, count($statements)));
            }
        }

        return $result;
    }

    /**
     * Split a migration file into executable statements.
     *
     * Trigger bodies contain `;` inside `BEGIN … END`, so a naive split on `;` would truncate
     * them into invalid fragments. `DELIMITER $$` blocks are honored the way the mysql client
     * honors them, since that is the form the trigger migrations are written in.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $delimiter = ';';
        $buffer = '';
        $lines = preg_split('/\R/', $sql) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(\S+)$/i', $trimmed, $m) === 1) {
                $delimiter = $m[1];
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with($trimmed, $delimiter)) {
                $statement = trim(substr(trim($buffer), 0, -strlen($delimiter)));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }
}
