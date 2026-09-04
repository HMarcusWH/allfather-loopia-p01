<?php

declare(strict_types=1);

namespace Allfather\Loopia\Db;

use Allfather\Loopia\Core\Outcome;

/**
 * The MariaDB connection and transaction boundary.
 *
 * Replaces upstream's `node:sqlite` `DatabaseSync` (`src/db/open.ts`). Two upstream properties
 * must survive the swap:
 *
 *  1. **Every statement is prepared.** There is no method on this class that takes an
 *     interpolated SQL string with caller data in it. `PDO::ATTR_EMULATE_PREPARES` is OFF so the
 *     server, not the driver, does the parameter binding.
 *  2. **Writes are transactional and roll back as a unit.** Upstream wraps multi-row governed
 *     writes in `BEGIN IMMEDIATE` … `COMMIT` / `ROLLBACK` so a released snapshot can never persist
 *     without the binding that points at it. `transaction()` reproduces that, including the
 *     rollback-on-throw path, and nests safely via savepoints.
 *
 * Errors are raised as exceptions (`ERRMODE_EXCEPTION`) rather than silently returning false: a
 * swallowed write error on a governed ledger is indistinguishable from a successful append.
 */
final class Database
{
    private int $transactionDepth = 0;

    private function __construct(private readonly \PDO $pdo)
    {
    }

    public static function connect(
        string $host,
        int $port,
        string $name,
        string $user,
        string $password,
        ?string $socket = null,
    ): self {
        $dsn = $socket !== null && $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $name)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            // Server-side prepares. With emulation on, PDO interpolates values into the SQL string
            // itself, which reintroduces exactly the class of bug prepared statements prevent.
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // STRICT_ALL_TABLES turns silent truncation and out-of-range coercion into errors. Without
        // it MariaDB would quietly truncate an over-long reason code, which is data loss on the
        // conformance surface. The other modes forbid the implicit zero-date and division-by-zero
        // coercions that would otherwise produce a value no CHECK constraint anticipated.
        $pdo->exec(
            "SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'"
        );
        // All governed timestamps are canonical UTC strings; a session offset must never
        // reinterpret one.
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $pdo->exec('SET SESSION transaction isolation level REPEATABLE READ');

        return new self($pdo);
    }

    public static function fromPdo(\PDO $pdo): self
    {
        return new self($pdo);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * Fetch at most one row.
     *
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Execute a write. Returns the affected row count, which callers use to detect a lost
     * optimistic update rather than assuming success.
     *
     * @param array<string|int, mixed> $params
     */
    public function run(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $columns
     */
    public function insert(string $table, array $columns): void
    {
        $names = array_keys($columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::identifier($table),
            implode(', ', array_map(self::identifier(...), $names)),
            implode(', ', array_fill(0, count($names), '?')),
        );
        $this->run($sql, array_values($columns));
    }

    /**
     * Run `$work` inside a transaction, committing on return and rolling back on any throw.
     *
     * Nesting uses savepoints so a governed command composed of other governed commands still
     * rolls back as one unit — the upstream atomicity hardening (P02/P04/P05 post-M10 writes)
     * depends on the inner write never committing independently of the outer one.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp' . $this->transactionDepth);
        }
        $depth = $this->transactionDepth;
        $this->transactionDepth++;

        try {
            $result = $work($this);
        } catch (\Throwable $error) {
            $this->transactionDepth = $depth;
            if ($depth === 0) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sp' . $depth);
            }
            throw $error;
        }

        $this->transactionDepth = $depth;
        if ($depth === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT sp' . $depth);
        }

        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /**
     * Quote an identifier. Only ever called with literals from this codebase, never with request
     * data — the allowlist assertion makes that structural rather than a convention.
     */
    public static function identifier(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("unsafe SQL identifier: {$name}");
        }

        return '`' . $name . '`';
    }

    public function serverVersion(): string
    {
        return (string) $this->scalar('SELECT VERSION()');
    }

    /**
     * Translate a driver exception into a governed outcome where the failure is a *constraint*
     * the application deliberately relies on (a unique idempotency key, an append-only trigger,
     * a CHECK). Anything else stays a system failure — a broken index is not a policy decision.
     */
    public static function classify(\PDOException $error, string $duplicateReasonCode): Outcome
    {
        $sqlState = $error->getCode();
        $driverCode = (int) ($error->errorInfo[1] ?? 0);

        if ($sqlState === '23000' && ($driverCode === 1062 || $driverCode === 1586)) {
            return Outcome::refuse($duplicateReasonCode, ['constraint' => 'UNIQUE']);
        }
        if ($sqlState === '45000') {
            // Raised by the append-only triggers via SIGNAL; the message carries the pin's name.
            return Outcome::refuse('APPEND_ONLY_VIOLATION', ['detail' => $error->errorInfo[2] ?? '']);
        }
        if ($sqlState === '23000' && $driverCode === 1452) {
            return Outcome::refuse('FOREIGN_KEY_VIOLATION', ['detail' => $error->errorInfo[2] ?? '']);
        }
        if ($driverCode === 4025 || $driverCode === 3819) {
            return Outcome::refuse('CHECK_CONSTRAINT_VIOLATION', ['detail' => $error->errorInfo[2] ?? '']);
        }

        return Outcome::failure('DATABASE_ERROR', [
            'sqlstate' => $sqlState,
            'driver_code' => $driverCode,
        ], 'DEPENDENCY', 'S3');
    }
}
