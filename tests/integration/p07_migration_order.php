<?php

declare(strict_types=1);

/**
 * P07 migration 250 on an EXISTING projection, upgraded in place.
 *
 * Migration 250 is P07's only migration, and it changes tables a deployed projection already holds
 * rows in: `monitor_run_events` gains the v2 actor columns (and `actor_operator_id` becomes nullable
 * under a version CHECK), `monitor_runs` gains the service initiator (and its freeze trigger is
 * recreated), and the `jobs` vocabulary CHECKs that migration 110 left UNNAMED are found by clause,
 * dropped by the name the server holds and replaced by named ones. A fresh install applies 250 in
 * lexical order; a deployed projection does not. It has already ledgered every merged migration,
 * including the historical, higher-numbered `900_deferred_foreign_keys`, and holds v1 journal
 * chains whose digests are evidence. Lexical ordering alone proves nothing about that upgrade.
 *
 * So this test does not hand-revert 250. It BUILDS the true pre-P07 projection: every table in the
 * test database is dropped and the ordinary Migrator runs over a copy of database/migrations that
 * lacks only 250, so 900 and everything else are applied and ledgered with their own file digests.
 * On that schema it writes v1 runs and events exactly as the pre-P07 RunJournal wrote them (the
 * current writer cannot: it names columns 250 adds), and legacy jobs through the real JobMachine.
 * Then it runs the ordinary Migrator over the real directory and proves:
 *
 *   1. the pre-P07 build differs from a fresh install only where 250 says it does;
 *   2. 250, and only 250, applies — exactly once, ledgered with its file digest — and 900 (with
 *      every other historical row) is neither replayed nor drifted;
 *   3. every pre-existing run and v1 event survives byte for byte, and the CURRENT
 *      RunJournal::replay verifies each historical chain with integrity PASS and identical digests;
 *      a new operator act on a historical run is still v1 with the historical digest formula;
 *   4. new v2 SERVICE events can be written (a fresh scheduled run, and a v2 step appended to a
 *      historical v1 chain) and replay, and the actor-version CHECK and the recreated freeze trigger
 *      hold;
 *   5. the jobs CHECK replacement is exact: the three vocabulary CHECKs are gone, the other CHECKs
 *      are untouched, and over every type x state x subject combination the upgraded table accepts
 *      precisely what it accepted before PLUS the five MONITOR_STEP/occurrence combinations; legacy
 *      rows survive, legacy jobs still advance, and a MONITOR_STEP job runs its own graph;
 *   6. a second migration pass applies nothing, finds no drift and changes nothing;
 *   7. the upgraded schema — columns, CHECKs, triggers, indexes and foreign keys of every table,
 *      the table set (which is the committed inventory) and the ledger — equals a fresh install.
 *
 * The database is ALWAYS left at the full current schema, even when an assertion or the upgrade
 * fails, because every later suite shares it. No migration file is edited; only information_schema
 * views present on both MariaDB 10.6 and 10.11 are read. Runs in both strict MariaDB lanes like
 * every integration test.
 */

require_once __DIR__ . '/../support/ScheduleFixture.php';

use Allfather\Loopia\Core\{Digest,Ids,Outcome};
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Jobs\JobMachine;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Tests\{Assert,Harness,ScheduleFixture,Seed};
use Allfather\Loopia\Workflow\{MonitorPrincipal,RunJournal,ScheduleService};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$root = dirname(__DIR__, 2);
$migrationsDir = $root . '/database/migrations';
$migrator = new Migrator($db, $migrationsDir);
$id250 = '250_monitor_schedule_control';
$id900 = '900_deferred_foreign_keys';
$fileDigests = array_column($migrator->available(), 'digest', 'id');
$allIds = array_keys($fileDigests);
$historicalIds = array_values(array_diff($allIds, [$id250]));
$newTables = ['monitor_schedule_events', 'monitor_schedule_occurrence_outcomes', 'monitor_schedule_occurrences', 'monitor_schedules'];
$alteredTables = ['jobs', 'monitor_run_events', 'monitor_runs'];

// ---------------------------------------------------------------- tools

/** Drop every table (and with them every trigger) in the test database, ledger included. */
$dropEverything = static function () use ($db): void {
    $pdo = $db->pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        foreach ($db->all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW'") as $row) {
            $pdo->exec('DROP VIEW ' . Database::identifier((string) $row['t']));
        }
        foreach ($db->all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $row) {
            $pdo->exec('DROP TABLE ' . Database::identifier((string) $row['t']));
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
};

/** Every structural fact of every table, keyed by table, minus anything that varies by instance or time. */
$shape = static function () use ($db): array {
    $byTable = static function (array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $table = (string) $row['TABLE_NAME'];
            unset($row['TABLE_NAME']);
            $out[$table][] = $row;
        }
        ksort($out, SORT_STRING);

        return $out;
    };

    return [
        'tables' => $byTable($db->all("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
                                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")),
        'columns' => $byTable($db->all('SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE,
                                               CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS
                                        WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')),
        'checks' => $byTable($db->all('SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
                                       WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME')),
        'triggers' => $byTable($db->all('SELECT EVENT_OBJECT_TABLE AS TABLE_NAME, TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORDER,
                                                ACTION_STATEMENT, SQL_MODE FROM information_schema.TRIGGERS
                                         WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME')),
        'indexes' => $byTable($db->all('SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, SUB_PART, INDEX_TYPE
                                        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                                        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')),
        'foreign_keys' => $byTable($db->all('SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                                                    k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
                                             FROM information_schema.KEY_COLUMN_USAGE k
                                             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                                               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                                             WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
                                             ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION')),
    ];
};

/** @return array<string, list<string>> per part, the tables whose facts differ between two shapes */
$differences = static function (array $a, array $b): array {
    $out = [];
    foreach (array_keys($a) as $part) {
        $tables = array_unique(array_merge(array_keys($a[$part]), array_keys($b[$part])));
        sort($tables, SORT_STRING);
        $out[$part] = array_values(array_filter($tables, static fn (string $t): bool => ($a[$part][$t] ?? null) !== ($b[$part][$t] ?? null)));
    }

    return $out;
};

$ledger = static fn (): array => $db->all('SELECT migration_id, content_digest, statement_count, applied_at FROM schema_migrations ORDER BY migration_id');
$strip = static fn (array $rows): array => array_map(static fn (array $r): array => [$r['migration_id'], $r['content_digest'], (int) $r['statement_count']], $rows);

/** Run one statement; null when it succeeds, else the CHECK constraint that refused it. Any other error is not a CHECK refusal and propagates. */
$checkRefusal = static function (callable $statement): ?string {
    try {
        $statement();

        return null;
    } catch (\PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 4025 || preg_match('/CONSTRAINT `([^`]+)` failed/', $error->getMessage(), $m) !== 1) {
            throw $error;
        }

        return $m[1];
    }
};

/** Run one statement; true when a trigger SIGNALled it away (SQLSTATE 45000). */
$triggerRefuses = static function (callable $statement): bool {
    try {
        $statement();

        return false;
    } catch (\PDOException $error) {
        return ($error->errorInfo[0] ?? null) === '45000';
    }
};

/** A replay's verdict without ever reading a refused Outcome's value: PASS, or the refusal code. */
$integrity = static fn (Outcome $replay): string => $replay->isOk() ? (string) ($replay->value()['integrity'] ?? 'NO_VERDICT') : $replay->reasonCode();
/** @return list<array<string, mixed>> a replay's verified events, or none when it refused */
$replayed = static fn (Outcome $replay): array => $replay->isOk() ? $replay->value()['events'] : [];

// The jobs vocabulary, as 110 declared it and as 250 extends it, plus one value nobody declared.
$legacyTypes = ['P01_SOURCE_MONITOR', 'M15_INGESTION_CAPTURE', 'M11_AUDIT_RUN', 'M16_PROJECTION_REFRESH'];
$legacyStates = ['PENDING', 'PREFLIGHT', 'FETCH', 'CAPTURE', 'DIFF', 'ANALYZE', 'ADJUDICATE', 'RELEASE', 'VISIBLE', 'FAILED', 'ABANDONED'];
$legacySubjects = ['MONITORED_SOURCE', 'INGESTION_SOURCE_POLICY', 'AUDIT_PROFILE', 'RELEASE_ARTIFACT'];
$stepStates = ['PENDING', 'FAILED', 'WAITING_FOR_OPERATOR', 'SETTLED', 'ABANDONED'];
$jobTypes = [...$legacyTypes, 'MONITOR_STEP', 'UNDECLARED_JOB'];
$jobStates = [...$legacyStates, 'WAITING_FOR_OPERATOR', 'SETTLED', 'UNDECLARED_STATE'];
$subjectKinds = [...$legacySubjects, 'MONITOR_SCHEDULE_OCCURRENCE', 'UNDECLARED_SUBJECT'];

/**
 * Try every type x state x subject combination as a jobs row. Accepted rows stay (with a far-future
 * run_after, so no claim ever picks them up) and must survive whatever comes next.
 *
 * @return array{accepted: list<string>, refused_by: array<string, string>}
 */
$jobsMatrix = static function (string $tenantId, string $workspaceId, string $tag) use ($db, $checkRefusal, $jobTypes, $jobStates, $subjectKinds): array {
    $accepted = [];
    $refusedBy = [];
    $pdo = $db->pdo();
    $pdo->beginTransaction();
    try {
        foreach ($jobTypes as $type) {
            foreach ($jobStates as $state) {
                foreach ($subjectKinds as $subject) {
                    $combo = "{$type}|{$state}|{$subject}";
                    $refusal = $checkRefusal(static fn () => $db->insert('jobs', [
                        'job_id' => Ids::new(), 'schema_version' => '1', 'tenant_id' => $tenantId, 'workspace_id' => $workspaceId,
                        'job_type' => $type, 'state' => $state, 'subject_kind' => $subject, 'subject_id' => Ids::new(),
                        'idempotency_key' => "{$tag}:{$combo}", 'attempt_count' => 0, 'max_attempts' => 3,
                        'run_after' => '2099-01-01T00:00:00.000Z', 'correlation_id' => Ids::new(),
                        'created_at' => '2026-02-01T00:00:00.000Z', 'updated_at' => '2026-02-01T00:00:00.000Z',
                    ]));
                    if ($refusal === null) {
                        $accepted[] = $combo;
                    } else {
                        $refusedBy[$combo] = $refusal;
                    }
                }
            }
        }
        $pdo->commit();
    } catch (\Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return ['accepted' => $accepted, 'refused_by' => $refusedBy];
};

/** The combinations a vocabulary admits, computed from the declared sets rather than read from SQL. */
$oracle = static function (bool $after250) use ($jobTypes, $jobStates, $subjectKinds, $legacyTypes, $legacyStates, $legacySubjects, $stepStates): array {
    $out = [];
    foreach ($jobTypes as $type) {
        foreach ($jobStates as $state) {
            foreach ($subjectKinds as $subject) {
                $legacy = in_array($type, $legacyTypes, true) && in_array($state, $legacyStates, true) && in_array($subject, $legacySubjects, true);
                $step = $type === 'MONITOR_STEP' && $subject === 'MONITOR_SCHEDULE_OCCURRENCE' && in_array($state, $stepStates, true);
                if ($legacy || ($after250 && $step)) {
                    $out[] = "{$type}|{$state}|{$subject}";
                }
            }
        }
    }

    return $out;
};

// The pre-P07 RunJournal writer (git show 7e20aad^:app/Workflow/RunJournal.php — create() and
// advance()): the same columns, the same values, the same v1 digest. The current writer cannot run
// on the old schema; it names columns that only 250 adds.
$historicalDigest = static fn (string $runId, int $sequence, string $kind, string $actor, ?string $previous, array $payload, string $at): string => Digest::canonicalDigest([
    'run_id' => $runId, 'sequence' => $sequence, 'kind' => $kind,
    'actor' => $actor, 'previous_digest' => $previous, 'payload' => $payload, 'at' => $at,
]);

$preDir = sys_get_temp_dir() . '/allfather-pre-p07-migrations-' . bin2hex(random_bytes(6));
$failuresBefore = Assert::$failed;
$completed = false;

try {
    // ============================================================ 0. a fresh install, for reference
    $dropEverything();
    $install = $migrator->up();
    Assert::same([$allIds, [], []], [$install['applied'], $install['skipped'], $install['drifted']], 'a fresh install applies every migration, 250 included, in lexical order');
    $fresh = $shape();
    $freshLedger = $ledger();
    foreach ([...$newTables, ...$alteredTables] as $table) {
        Assert::true(isset($fresh['columns'][$table]), "the fresh install has {$table}");
    }

    // ============================================================ 1. the true pre-P07 projection
    $dropEverything();
    if (!mkdir($preDir, 0700) && !is_dir($preDir)) {
        throw new \RuntimeException('cannot create the pre-P07 migration directory');
    }
    foreach ($migrator->available() as $migration) {
        if ($migration['id'] !== $id250 && !copy($migration['path'], $preDir . '/' . basename($migration['path']))) {
            throw new \RuntimeException('cannot copy ' . $migration['id']);
        }
    }
    $pre = (new Migrator($db, $preDir))->up();
    Assert::same([$historicalIds, [], []], [$pre['applied'], $pre['skipped'], $pre['drifted']], 'the pre-P07 projection applies every merged migration except 250, ending with 900');
    Assert::same($id900, $pre['applied'][array_key_last($pre['applied'])], 'historical 900 is the last migration the pre-P07 projection applied');
    $preLedger = $ledger();
    Assert::same($historicalIds, array_column($preLedger, 'migration_id'), 'the pre-P07 ledger holds every historical migration and not 250');
    Assert::same(
        array_intersect_key($fileDigests, array_flip($historicalIds)),
        array_column($preLedger, 'content_digest', 'migration_id'),
        'every historical migration, 900 included, is ledgered with the digest of its committed file',
    );
    Assert::true(strcmp($id900, $id250) > 0, '900 sorts after 250, so the upgrade applies a LOWER-numbered migration after a higher one');

    $preShape = $shape();
    Assert::same([
        'tables' => $newTables,
        'columns' => ['monitor_run_events', 'monitor_runs', ...$newTables],
        'checks' => ['jobs', 'monitor_run_events', ...$newTables],
        'triggers' => ['monitor_runs', ...$newTables],
        'indexes' => ['monitor_run_events', 'monitor_runs', ...$newTables],
        'foreign_keys' => ['monitor_run_events', 'monitor_runs', ...$newTables],
    ], $differences($preShape, $fresh), 'the pre-P07 projection differs from a fresh install only in the tables 250 creates or alters, and only in what it changes there');
    Assert::same(
        ['monitor_event_id', 'monitor_run_id', 'sequence_no', 'event_kind', 'actor_operator_id', 'previous_digest', 'event_digest', 'payload_json', 'recorded_at'],
        array_column($preShape['columns']['monitor_run_events'], 'COLUMN_NAME'),
        'pre-P07 run events carry no version, service or delegator column',
    );
    Assert::same('NO', array_column($preShape['columns']['monitor_run_events'], 'IS_NULLABLE', 'COLUMN_NAME')['actor_operator_id'], 'and every pre-P07 event names a human operator');
    Assert::false(in_array('initiated_by_service_identity_id', array_column($preShape['columns']['monitor_runs'], 'COLUMN_NAME'), true), 'pre-P07 runs have no service initiator');
    $preJobChecks = array_column($preShape['checks']['jobs'], 'CHECK_CLAUSE', 'CONSTRAINT_NAME');
    foreach (['P01_SOURCE_MONITOR', 'PREFLIGHT', 'INGESTION_SOURCE_POLICY'] as $marker) {
        Assert::same(1, count(array_filter($preJobChecks, static fn (string $clause): bool => str_contains($clause, $marker))), "exactly one unnamed 110 CHECK on jobs mentions {$marker}, so 250's clause lookup is unambiguous");
    }
    Assert::same([], array_filter(array_keys($preJobChecks), static fn (string $name): bool => str_starts_with($name, 'ck_jobs_')), 'the pre-P07 jobs CHECKs are the unnamed ones 110 declared');

    // ============================================================ 2. what a deployed projection already holds
    $clock = Harness::clock('2026-02-01T08:00:00.000Z');
    $scope = Seed::tenantWorkspace($db, $clock, 'p07-upgrade');
    $secondOperator = Seed::operator($db, $clock, $scope['tenant_id'], 'Second operator');
    $mission = (new Proposals($db, $clock))->createMission($scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING', 'Watch approved source', 'Internal evidence review', ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'], 'OPERATOR', $scope['operator_id']);

    $historicalCreate = static function (array $scope, array $definition, string $key, string $mode) use ($db, $clock): string {
        $id = Ids::new();
        $now = $clock->now();
        $db->insert('monitor_runs', [
            'monitor_run_id' => $id, 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
            'mission_id' => $definition['mission_id'], 'initiated_by_operator_id' => $scope['operator_id'],
            'idempotency_key' => $key, 'definition_json' => json_encode($definition, JSON_THROW_ON_ERROR),
            'definition_digest' => Digest::canonicalDigest(['definition' => $definition, 'mode' => $mode]), 'execution_mode' => $mode,
            'stage' => 'NEW', 'cursor_json' => '{}', 'revision' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    };
    $historicalAdvance = static function (array $scope, string $runId, string $stage, array $cursor, string $kind) use ($db, $clock, $historicalDigest): void {
        $run = $db->one('SELECT * FROM monitor_runs WHERE monitor_run_id = ?', [$runId]);
        $db->transaction(static function () use ($db, $clock, $historicalDigest, $scope, $run, $stage, $cursor, $kind): void {
            $sequence = (int) $run['revision'] + 1;
            $at = $clock->now();
            $payload = ['from_stage' => $run['stage'], 'to_stage' => $stage, 'cursor' => $cursor];
            $digest = $historicalDigest($run['monitor_run_id'], $sequence, $kind, $scope['operator_id'], $run['event_digest'], $payload, $at);
            $changed = $db->run('UPDATE monitor_runs SET stage = ?, cursor_json = ?, revision = ?, event_digest = ?, updated_at = ? WHERE monitor_run_id = ? AND tenant_id = ? AND workspace_id = ? AND revision = ?', [
                $stage, json_encode($cursor, JSON_THROW_ON_ERROR), $sequence, $digest, $at,
                $run['monitor_run_id'], $scope['tenant_id'], $scope['workspace_id'], $run['revision'],
            ]);
            if ($changed !== 1) {
                throw new \RuntimeException('historical advance lost its revision');
            }
            $db->insert('monitor_run_events', [
                'monitor_event_id' => Ids::new(), 'monitor_run_id' => $run['monitor_run_id'], 'sequence_no' => $sequence,
                'event_kind' => $kind, 'actor_operator_id' => $scope['operator_id'], 'previous_digest' => $run['event_digest'],
                'event_digest' => $digest, 'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'recorded_at' => $at,
            ]);
        });
    };

    // Run A: captured and compared, a change waiting. Unicode, slashes and nesting in the cursor, so
    // "byte for byte" covers JSON escaping and the canonical digest, not just ASCII.
    $runA = $historicalCreate($scope, ['mission_id' => $mission, 'purpose' => 'Pre-P07 run A'], 'pre-p07-a', 'FIXTURE');
    $captureA = ['source_snapshot_id' => Ids::new(), 'payload_digest' => hash('sha256', 'a'), 'note' => 'Seminariet flyttas till “december” — Ångström / åäö'];
    $historicalAdvance($scope, $runA, 'CAPTURED', ['capture' => $captureA], 'SOURCE_CAPTURED');
    $historicalAdvance($scope, $runA, 'DIFFED', ['capture' => $captureA, 'diff' => ['change_significance' => 'MATERIAL', 'lines' => [1, 2, 3], 'empty' => []]], 'DIFF_CLASSIFIED');
    // Run B: a baseline recorded by a second operator.
    $runB = $historicalCreate(['operator_id' => $secondOperator] + $scope, ['mission_id' => $mission, 'purpose' => 'Pre-P07 run B'], 'pre-p07-b', 'FIXTURE');
    $historicalAdvance(['operator_id' => $secondOperator] + $scope, $runB, 'BASELINE', ['capture' => ['source_snapshot_id' => Ids::new(), 'before_snapshot_id' => null]], 'SOURCE_CAPTURED');
    // Run C: captured by a human before the upgrade; a v2 step is appended after it.
    $runC = $historicalCreate($scope, ['mission_id' => $mission, 'purpose' => 'Pre-P07 run C'], 'pre-p07-c', 'FIXTURE');
    $historicalAdvance($scope, $runC, 'CAPTURED', ['capture' => ['source_snapshot_id' => Ids::new()]], 'SOURCE_CAPTURED');
    // Run D: created, never advanced.
    $runD = $historicalCreate($scope, ['mission_id' => $mission, 'purpose' => 'Pre-P07 run D'], 'pre-p07-d', 'NATIVE');

    $runColumns = 'monitor_run_id, tenant_id, workspace_id, mission_id, initiated_by_operator_id, idempotency_key, definition_json, definition_digest, execution_mode, stage, cursor_json, revision, event_digest, created_at, updated_at';
    $eventColumns = 'monitor_event_id, monitor_run_id, sequence_no, event_kind, actor_operator_id, previous_digest, event_digest, payload_json, recorded_at';
    $runsBefore = $db->all("SELECT {$runColumns} FROM monitor_runs ORDER BY monitor_run_id");
    $eventsBefore = $db->all("SELECT {$eventColumns} FROM monitor_run_events ORDER BY monitor_run_id, sequence_no");
    Assert::same([4, 4], [count($runsBefore), count($eventsBefore)], 'four historical runs and four v1 events exist before the upgrade');
    $digestsBefore = static fn (string $runId): array => array_column(array_values(array_filter($eventsBefore, static fn (array $e): bool => $e['monitor_run_id'] === $runId)), 'event_digest');

    $journal = new RunJournal($db, $clock);
    foreach (['A' => $runA, 'B' => $runB, 'C' => $runC, 'D' => $runD] as $name => $runId) {
        Assert::same('PASS', $integrity($journal->replay($scope, $runId)), "historical run {$name} already verifies under the current reader on the pre-P07 shape");
    }

    // Legacy jobs: one enqueued, claimed and advanced through the real JobMachine; the vocabulary
    // matrix leaves one accepted row per legacy combination behind.
    $jobs = new JobMachine($db, $clock);
    $legacyJob = $jobs->enqueue($scope['tenant_id'], $scope['workspace_id'], 'P01_SOURCE_MONITOR', 'MONITORED_SOURCE', Ids::new(), 'pre-p07-legacy');
    Assert::true($legacyJob->isOk(), 'a legacy job is enqueued on the pre-P07 schema');
    $legacyJobId = (string) $legacyJob->value()['job_id'];
    Assert::same([$legacyJobId], array_column($jobs->claim('pre-p07-worker', 5, 300, ['PENDING']), 'job_id'), 'and claimed');
    Assert::true($jobs->advance($legacyJobId, 'pre-p07-worker', 'PREFLIGHT')->isOk(), 'and advanced to PREFLIGHT');
    Assert::false($jobs->enqueue($scope['tenant_id'], $scope['workspace_id'], 'MONITOR_STEP', 'MONITOR_SCHEDULE_OCCURRENCE', Ids::new(), 'pre-p07-step')->isOk(), 'a MONITOR_STEP job cannot exist before 250');

    $before250 = $jobsMatrix($scope['tenant_id'], $scope['workspace_id'], 'pre-250');
    Assert::same($oracle(false), $before250['accepted'], 'before 250 the jobs table accepts exactly the 110 vocabulary: every legacy type x state x subject, nothing else');
    $jobsBefore = $db->all('SELECT * FROM jobs ORDER BY job_id');
    $jobTransitionsBefore = $db->all('SELECT * FROM job_transitions ORDER BY job_transition_id');
    $before900 = $db->one('SELECT content_digest, statement_count, applied_at FROM schema_migrations WHERE migration_id = ?', [$id900]);

    // ============================================================ 3. the upgrade
    $up = $migrator->up();
    Assert::same([$id250], $up['applied'], '250, and nothing else, applies to the existing projection');
    Assert::same([], $up['drifted'], 'with no migration drift');
    Assert::same($historicalIds, $up['skipped'], 'every historical migration, 900 included, is recognized as applied rather than replayed');
    Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM schema_migrations WHERE migration_id = ?', [$id250]), '250 is ledgered exactly once');
    Assert::same(
        [$fileDigests[$id250], count(Migrator::splitStatements((string) file_get_contents($migrationsDir . '/' . $id250 . '.sql')))],
        (static fn (array $r): array => [$r['content_digest'], (int) $r['statement_count']])($db->one('SELECT content_digest, statement_count FROM schema_migrations WHERE migration_id = ?', [$id250])),
        'with the digest and statement count of the file that ran',
    );
    Assert::same($before900, $db->one('SELECT content_digest, statement_count, applied_at FROM schema_migrations WHERE migration_id = ?', [$id900]), '900 keeps its original ledger row: not replayed, not re-dated, not reinterpreted');
    Assert::same($preLedger, array_values(array_filter($ledger(), static fn (array $r): bool => $r['migration_id'] !== $id250)), 'and so does every other historical ledger row');

    // ============================================================ 4. history survives and replays
    Assert::same($runsBefore, $db->all("SELECT {$runColumns} FROM monitor_runs ORDER BY monitor_run_id"), 'every pre-existing run survives byte for byte');
    Assert::same($eventsBefore, $db->all("SELECT {$eventColumns} FROM monitor_run_events ORDER BY monitor_run_id, sequence_no"), 'every pre-existing v1 event survives byte for byte');
    Assert::same([[1, null, null]], array_values(array_unique(array_map(
        static fn (array $r): array => [(int) $r['event_version'], $r['actor_service_identity_id'], $r['delegating_operator_id']],
        $db->all('SELECT event_version, actor_service_identity_id, delegating_operator_id FROM monitor_run_events'),
    ), SORT_REGULAR)), 'pre-existing events read as v1 with no service or delegator');
    Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_runs WHERE initiated_by_service_identity_id IS NOT NULL'), 'and pre-existing runs name no service initiator');
    foreach (['A' => $runA, 'B' => $runB, 'C' => $runC, 'D' => $runD] as $name => $runId) {
        $replay = $journal->replay($scope, $runId);
        Assert::same('PASS', $integrity($replay), "historical run {$name} replays through the current RunJournal with integrity PASS");
        Assert::same($digestsBefore($runId), array_column($replayed($replay), 'event_digest'), "and its event digests are the ones the pre-P07 writer recorded for run {$name}");
    }

    // A human acting on a historical run after the upgrade still writes v1, with the old formula.
    $runARow = $journal->find($scope, $runA);
    Assert::true($journal->advance($scope, $runARow, 'STOPPED', $runARow['cursor'] + ['reason_code' => 'MONITOR_OPERATOR_STOP'], 'OPERATOR_STOP')->isOk(), 'an operator still advances a historical run after the upgrade');
    $stopEvent = $db->one('SELECT * FROM monitor_run_events WHERE monitor_run_id = ? AND sequence_no = 3', [$runA]);
    Assert::same([1, $scope['operator_id'], null, null], [(int) $stopEvent['event_version'], $stopEvent['actor_operator_id'], $stopEvent['actor_service_identity_id'], $stopEvent['delegating_operator_id']], 'the new operator event is v1');
    Assert::same(
        $historicalDigest($runA, 3, 'OPERATOR_STOP', $scope['operator_id'], $runARow['event_digest'], json_decode($stopEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR), $stopEvent['recorded_at']),
        $stopEvent['event_digest'],
        'and its digest is exactly the pre-P07 formula',
    );
    Assert::same('PASS', $integrity($journal->replay($scope, $runA)), 'the extended historical chain replays');

    // ============================================================ 5. v2 SERVICE events after the upgrade
    $serviceId = ScheduleFixture::serviceIdentity($db, $clock, $scope['tenant_id'], $scope['workspace_id'], 'SYSTEM_SCHEDULER', false);
    $occurrenceId = Ids::new();
    $serviceScope = MonitorPrincipal::service($serviceId, $scope['operator_id'], $occurrenceId)->scope($scope['tenant_id'], $scope['workspace_id']);
    $created = $journal->create($serviceScope, ['mission_id' => $mission, 'purpose' => 'Scheduled observation after 250'], ScheduleService::runKey($occurrenceId), 'FIXTURE');
    Assert::true($created->isOk(), 'a service-initiated run is created on the upgraded schema');
    $serviceRun = $created->value();
    Assert::same([$scope['operator_id'], $serviceId], [$serviceRun['initiated_by_operator_id'], $serviceRun['initiated_by_service_identity_id']], 'naming the delegator and the service separately');
    $captured = $journal->advance($serviceScope, $serviceRun, 'CAPTURED', ['capture' => ['source_snapshot_id' => Ids::new()]], 'SOURCE_CAPTURED');
    Assert::true($captured->isOk(), 'the service writes a capture event');
    Assert::true($journal->advance($serviceScope, $captured->value(), 'DIFFED', $captured->value()['cursor'] + ['diff' => ['change_significance' => 'MATERIAL']], 'DIFF_CLASSIFIED')->isOk(), 'and a compare event');
    $serviceEvents = $db->all('SELECT * FROM monitor_run_events WHERE monitor_run_id = ? ORDER BY sequence_no', [$serviceRun['monitor_run_id']]);
    Assert::same([[2, null, $serviceId, $scope['operator_id']], [2, null, $serviceId, $scope['operator_id']]], array_map(
        static fn (array $e): array => [(int) $e['event_version'], $e['actor_operator_id'], $e['actor_service_identity_id'], $e['delegating_operator_id']],
        $serviceEvents,
    ), 'both events are v2: the service acted under the operator\'s delegation, never recorded as the human');
    $first = $serviceEvents[0];
    $firstPayload = json_decode($first['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    Assert::false(in_array($first['event_digest'], [
        $historicalDigest($first['monitor_run_id'], 1, $first['event_kind'], $scope['operator_id'], null, $firstPayload, $first['recorded_at']),
        $historicalDigest($first['monitor_run_id'], 1, $first['event_kind'], $serviceId, null, $firstPayload, $first['recorded_at']),
    ], true), 'a v2 digest is not the v1 digest of either actor, so a service act cannot be re-read as a human one');
    Assert::same('PASS', $integrity($journal->replay($scope, $serviceRun['monitor_run_id'])), 'a v2 chain replays with integrity');

    // A v2 step appended to a chain the pre-P07 writer started: the v1 digest links into v2.
    $runCRow = $journal->find($scope, $runC);
    Assert::true($journal->advance($serviceScope, $runCRow, 'DIFFED', $runCRow['cursor'] + ['diff' => ['change_significance' => 'MATERIAL']], 'DIFF_CLASSIFIED')->isOk(), 'a v2 event is appended to a historical v1 chain');
    $mixed = $journal->replay($scope, $runC);
    Assert::same('PASS', $integrity($mixed), 'the mixed historical-v1 / new-v2 chain replays');
    Assert::same([1, 2], array_map('intval', array_column($replayed($mixed), 'event_version')), 'with the historical event still v1 and the new one v2');
    Assert::same($digestsBefore($runC)[0], $replayed($mixed)[0]['event_digest'] ?? null, 'and the historical digest unchanged at its head');

    // The actor-version CHECK refuses every inconsistent actor.
    $badEvent = static fn (int $sequence, array $actor) => static fn () => $db->insert('monitor_run_events', [
        'monitor_event_id' => Ids::new(), 'monitor_run_id' => $serviceRun['monitor_run_id'], 'sequence_no' => $sequence, 'event_kind' => 'FORGED',
        ...$actor, 'previous_digest' => null, 'event_digest' => str_repeat('0', 64), 'payload_json' => '{}', 'recorded_at' => $clock->now(),
    ]);
    foreach ([
        'v1 without its operator' => [90, ['event_version' => 1, 'actor_operator_id' => null, 'actor_service_identity_id' => null, 'delegating_operator_id' => null]],
        'v1 naming a service' => [91, ['event_version' => 1, 'actor_operator_id' => $scope['operator_id'], 'actor_service_identity_id' => $serviceId, 'delegating_operator_id' => null]],
        'v2 naming the human as actor' => [92, ['event_version' => 2, 'actor_operator_id' => $scope['operator_id'], 'actor_service_identity_id' => $serviceId, 'delegating_operator_id' => $scope['operator_id']]],
        'v2 without a delegator' => [93, ['event_version' => 2, 'actor_operator_id' => null, 'actor_service_identity_id' => $serviceId, 'delegating_operator_id' => null]],
        'an undeclared version' => [94, ['event_version' => 3, 'actor_operator_id' => null, 'actor_service_identity_id' => $serviceId, 'delegating_operator_id' => $scope['operator_id']]],
    ] as $label => [$sequence, $actor]) {
        Assert::same('ck_monitor_run_events_actor_version', $checkRefusal($badEvent($sequence, $actor)), "the actor-version CHECK refuses {$label}");
    }

    // The recreated freeze trigger covers the new initiator column, and still the old ones.
    Assert::true($triggerRefuses(static fn () => $db->run('UPDATE monitor_runs SET initiated_by_service_identity_id = ? WHERE monitor_run_id = ?', [$serviceId, $runD])), 'a historical run cannot be re-attributed to a service');
    Assert::true($triggerRefuses(static fn () => $db->run('UPDATE monitor_runs SET initiated_by_service_identity_id = NULL WHERE monitor_run_id = ?', [$serviceRun['monitor_run_id']])), 'a service run cannot shed its service');
    Assert::true($triggerRefuses(static fn () => $db->run('UPDATE monitor_runs SET initiated_by_operator_id = ? WHERE monitor_run_id = ?', [$secondOperator, $runD])), 'and the operator initiator stays frozen');
    Assert::true($triggerRefuses(static fn () => $db->run("UPDATE monitor_run_events SET event_kind = 'REWRITTEN' WHERE monitor_run_id = ?", [$runA])), 'historical events stay append-only');

    // ============================================================ 6. the jobs CHECK replacement
    $postJobChecks = array_column($db->all("SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
                                            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'jobs' ORDER BY CONSTRAINT_NAME"), 'CHECK_CLAUSE', 'CONSTRAINT_NAME');
    $vocabulary = static fn (string $clause): bool => str_contains($clause, 'P01_SOURCE_MONITOR') || str_contains($clause, 'PREFLIGHT') || str_contains($clause, 'INGESTION_SOURCE_POLICY');
    $kept = array_filter($preJobChecks, static fn (string $clause): bool => !$vocabulary($clause));
    Assert::same(3, count($preJobChecks) - count($kept), 'three vocabulary CHECKs existed before 250');
    Assert::same($kept, array_diff_key($postJobChecks, array_flip(array_filter(array_keys($postJobChecks), static fn (string $n): bool => str_starts_with($n, 'ck_jobs_')))), 'after 250 every non-vocabulary CHECK is untouched and every unnamed vocabulary CHECK is gone');
    Assert::same(
        ['ck_jobs_job_type', 'ck_jobs_monitor_step_states', 'ck_jobs_monitor_step_subject', 'ck_jobs_state', 'ck_jobs_subject_kind'],
        array_values(array_filter(array_keys($postJobChecks), static fn (string $n): bool => str_starts_with($n, 'ck_jobs_'))),
        'replaced by exactly the five named CHECKs',
    );
    Assert::same(['ck_jobs_job_type'], array_keys(array_filter($postJobChecks, static fn (string $clause): bool => str_contains($clause, 'P01_SOURCE_MONITOR'))), 'no second job-type vocabulary survives beside the new one');
    Assert::same($jobsBefore, $db->all('SELECT * FROM jobs ORDER BY job_id'), 'every pre-existing job row, in every legacy state, survives byte for byte');
    Assert::same($jobTransitionsBefore, $db->all('SELECT * FROM job_transitions ORDER BY job_transition_id'), 'and so does the transition ledger');

    $after250 = $jobsMatrix($scope['tenant_id'], $scope['workspace_id'], 'post-250');
    Assert::same($oracle(true), $after250['accepted'], 'after 250 the jobs table accepts exactly the 110 vocabulary plus MONITOR_STEP over an occurrence in its five states');
    Assert::same([], array_values(array_diff($before250['accepted'], $after250['accepted'])), 'nothing the 110 vocabulary accepted is refused after 250');
    Assert::same(
        array_map(static fn (string $s): string => "MONITOR_STEP|{$s}|MONITOR_SCHEDULE_OCCURRENCE", ['PENDING', 'FAILED', 'ABANDONED', 'WAITING_FOR_OPERATOR', 'SETTLED']),
        array_values(array_diff($after250['accepted'], $before250['accepted'])),
        'and the only newly accepted rows are MONITOR_STEP over an occurrence in PENDING, FAILED, ABANDONED, WAITING_FOR_OPERATOR or SETTLED',
    );
    Assert::same([], array_values(array_filter($after250['refused_by'], static fn (string $name): bool => !str_starts_with($name, 'ck_jobs_'))), 'every type/state/subject refusal after 250 is made by a named 250 CHECK');
    foreach ([
        'a legacy job waiting for an operator' => ['P01_SOURCE_MONITOR', 'WAITING_FOR_OPERATOR', 'MONITORED_SOURCE', 'ck_jobs_monitor_step_states'],
        'a legacy job settled' => ['M11_AUDIT_RUN', 'SETTLED', 'AUDIT_PROFILE', 'ck_jobs_monitor_step_states'],
        'a MONITOR_STEP job in a source-monitoring state' => ['MONITOR_STEP', 'FETCH', 'MONITOR_SCHEDULE_OCCURRENCE', 'ck_jobs_monitor_step_states'],
        'a MONITOR_STEP job about a source' => ['MONITOR_STEP', 'PENDING', 'MONITORED_SOURCE', 'ck_jobs_monitor_step_subject'],
        'a legacy job about an occurrence' => ['P01_SOURCE_MONITOR', 'PENDING', 'MONITOR_SCHEDULE_OCCURRENCE', 'ck_jobs_monitor_step_subject'],
        'an undeclared job type' => ['UNDECLARED_JOB', 'PENDING', 'MONITORED_SOURCE', 'ck_jobs_job_type'],
        'an undeclared state' => ['P01_SOURCE_MONITOR', 'UNDECLARED_STATE', 'MONITORED_SOURCE', 'ck_jobs_state'],
        'an undeclared subject' => ['P01_SOURCE_MONITOR', 'PENDING', 'UNDECLARED_SUBJECT', 'ck_jobs_subject_kind'],
    ] as $label => [$type, $state, $subject, $constraint]) {
        Assert::same($constraint, $after250['refused_by']["{$type}|{$state}|{$subject}"] ?? null, "{$label} is refused by {$constraint}");
    }
    Assert::same('ck_jobs_monitor_step_states', $checkRefusal(static fn () => $db->run("UPDATE jobs SET state = 'WAITING_FOR_OPERATOR' WHERE job_id = ?", [$legacyJobId])), 'a pre-existing legacy job cannot be moved into a MONITOR_STEP state');
    Assert::true($checkRefusal(static fn () => $db->run('UPDATE jobs SET attempt_count = -1 WHERE job_id = ?', [$legacyJobId])) !== null, 'the untouched 110 attempt_count CHECK still holds');

    Assert::same([$legacyJobId], array_column($jobs->claim('post-250-worker', 5, 300, ['PREFLIGHT']), 'job_id'), 'the pre-existing legacy job is claimed after the upgrade');
    Assert::true($jobs->advance($legacyJobId, 'post-250-worker', 'FETCH')->isOk(), 'and advances along the legacy graph');
    $step = $jobs->enqueue($scope['tenant_id'], $scope['workspace_id'], 'MONITOR_STEP', 'MONITOR_SCHEDULE_OCCURRENCE', $occurrenceId, 'post-250-step');
    Assert::true($step->isOk(), 'a MONITOR_STEP job is enqueued after the upgrade');
    $stepId = (string) $step->value()['job_id'];
    Assert::same([], $jobs->claim('post-250-worker', 5, 300, ['PENDING']), 'a legacy PENDING claim never picks up the MONITOR_STEP job');
    Assert::same([$stepId], array_column($jobs->claim('post-250-worker', 5, 300, [], ['MONITOR_STEP' => ['PENDING']]), 'job_id'), 'a typed claim does');
    Assert::true($jobs->advance($stepId, 'post-250-worker', 'WAITING_FOR_OPERATOR')->isOk(), 'and it rests at WAITING_FOR_OPERATOR');

    // ============================================================ 7. a second pass is a no-op
    $ledgerAfterUpgrade = $ledger();
    $shapeAfterUpgrade = $shape();
    $repeat = $migrator->up();
    Assert::same([[], []], [$repeat['applied'], $repeat['drifted']], 'a second migration pass applies nothing and finds no drift');
    Assert::same($allIds, $repeat['skipped'], 'every migration, 250 included, is now recognized as applied');
    Assert::same($ledgerAfterUpgrade, $ledger(), 'the ledger is untouched by the second pass');
    Assert::same(array_fill_keys(array_keys($fresh), []), $differences($shapeAfterUpgrade, $shape()), 'and so is the schema');

    // ============================================================ 8. exactly a fresh install
    $upgraded = $shape();
    Assert::same(array_fill_keys(array_keys($fresh), []), $differences($fresh, $upgraded), 'the upgraded columns, CHECKs, triggers, indexes and foreign keys of every table are exactly the fresh install\'s');
    foreach ([...$alteredTables, ...$newTables] as $table) {
        foreach (array_keys($fresh) as $part) {
            if (isset($fresh[$part][$table])) {
                Assert::same($fresh[$part][$table], $upgraded[$part][$table] ?? null, "upgraded {$table} {$part} equal the fresh install");
            }
        }
    }
    Assert::same($strip($freshLedger), $strip($ledger()), 'the migration ledger is exactly the fresh-install ledger');
    $inventory = json_decode((string) file_get_contents($root . '/tests/fixtures/schema_table_inventory.json'), true, 512, JSON_THROW_ON_ERROR);
    $expected = array_merge(
        $inventory['baseline_canonical_tables'], $inventory['canonical_repair_tables'], $inventory['baseline_loopia_tables'],
        $inventory['loopia_extension_tables'], ...array_values($inventory['completion_plan_tables']), ...[['schema_migrations']],
    );
    sort($expected, SORT_STRING);
    Assert::same($expected, array_keys($upgraded['tables']), 'the upgraded table set is exactly the committed schema inventory');
    $inventory250 = $inventory['completion_plan_tables'][$id250 . '.sql'] ?? [];
    sort($inventory250, SORT_STRING);
    Assert::same($newTables, $inventory250, 'and the tables 250 created are exactly the inventory\'s entry for 250');

    $completed = true;
} finally {
    foreach (glob($preDir . '/*.sql') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($preDir);
    // Later suites share this database. Unless every assertion above held, the schema may be
    // pre-P07, half-upgraded or otherwise not current: rebuild it from nothing with the real
    // migrations. On success it already IS the fresh-install schema (asserted), so nothing changes.
    if (!$completed || Assert::$failed !== $failuresBefore) {
        $dropEverything();
        $migrator->up();
    }
}
