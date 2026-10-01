<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\{Clock,Digest,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Http\ConsoleAssetManifest;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Workflow\RunJournal;

/**
 * Portable proof beside one P10 native database dump.
 *
 * No credential, .env value, provider response or raw tenant profile is embedded in this manifest.
 * Private content-addressed bytes are copied beside it and named only by sha256.
 */
final class BackupManifest
{
    public const VERSION = 1;
    public const FILE = 'manifest.json';
    public const DUMP = 'database.sql';
    public const REQUEST_LOG_PREFIX = 'request-log.prefix';

    private const SECRET_PATTERN = '/APP_MASTER_KEY|DB_PASSWORD|SCHEDULER_TOKEN|BEGIN (?:OPENSSH|PRIVATE) KEY|sk-[A-Za-z0-9]/';

    private const TOOLING_FILES = [
        'ops/backup/export.sh',
        'ops/backup/restore-rehearsal.sh',
        'ops/backup/maintenance.php',
        'ops/backup/verify-restore.php',
        'ops/monitor/stand-down.php',
        'ops/rollback/verify-stand-down.php',
        'ops/loopia-probe/probe.php',
    ];

    private const SEMANTIC_TABLES = [
        'decision_ledger_streams',
        'decision_records',
        'monitor_runs',
        'monitor_run_events',
        'released_briefings',
        'role_distribution_briefings',
        'released_distribution_outputs',
        'monitor_execution_grants',
        'monitor_execution_grant_events',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly string $root,
        private readonly ?string $releaseRef = null,
    ) {}

    public function build(string $dumpPath, string $packageDir): Outcome
    {
        $maintenance = (new MaintenanceGate($this->root, $this->clock))->status();
        if (($maintenance['active'] ?? false) !== true || ($maintenance['state'] ?? '') !== 'QUIESCED'
            || ($maintenance['reason'] ?? null) !== 'BACKUP') {
            return Outcome::refuse('BACKUP_REQUIRES_QUIESCED_MAINTENANCE');
        }

        $operation = (new MaintenanceGate($this->root, $this->clock))->operationStatus();
        if (($operation['active'] ?? false) !== true || ($operation['state'] ?? '') !== 'ACTIVE'
            || ($operation['kind'] ?? null) !== 'BACKUP_EXPORT'
            || !hash_equals((string) ($maintenance['maintenance_id'] ?? ''), (string) ($operation['maintenance_id'] ?? ''))
            || !hash_equals((string) ($maintenance['operator_id'] ?? ''), (string) ($operation['operator_id'] ?? ''))) {
            return Outcome::refuse('BACKUP_REQUIRES_ACTIVE_EXPORT_OPERATION');
        }

        $admittedGrants = $this->admittedGrantCount();
        if ($admittedGrants > 0) {
            return Outcome::refuse('BACKUP_EXECUTION_GRANTS_STILL_ADMITTED', ['count' => $admittedGrants]);
        }
        $integrity = $this->governedHistoryIntegrity();
        if (!$integrity->isOk()) {
            return Outcome::refuse('BACKUP_GOVERNED_HISTORY_INVALID', [
                'reason_code' => $integrity->reasonCode(),
            ]);
        }
        $objects = $this->databaseObjectSupport();
        if (!$objects->isOk()) {
            return $objects;
        }

        $dumpReal = realpath($dumpPath);
        if ($dumpReal === false || !is_file($dumpReal) || is_link($dumpPath)) {
            return Outcome::refuse('BACKUP_DUMP_NOT_READABLE');
        }
        $dumpSize = filesize($dumpReal);
        $dumpDigest = hash_file('sha256', $dumpReal);
        if ($dumpSize === false || $dumpSize < 1 || !is_string($dumpDigest)) {
            return Outcome::refuse('BACKUP_DUMP_EMPTY_OR_UNREADABLE');
        }
        if (file_exists($packageDir)) {
            if (!is_dir($packageDir) || is_link($packageDir) || (scandir($packageDir) ?: []) !== ['.', '..']) {
                return Outcome::refuse('BACKUP_PACKAGE_DIR_NOT_EMPTY');
            }
        } elseif (!@mkdir($packageDir, 0700, true) && !is_dir($packageDir)) {
            return Outcome::failure('BACKUP_PACKAGE_DIR_CREATE_FAILED');
        }
        $packageReal = realpath($packageDir);
        if ($packageReal === false) {
            return Outcome::failure('BACKUP_PACKAGE_DIR_CREATE_FAILED');
        }
        $public = realpath($this->root . '/public_html');
        $evidence = realpath($this->root . '/storage/evidence');
        if (($public !== false && str_starts_with($packageReal, $public . DIRECTORY_SEPARATOR))
            || ($evidence !== false && ($packageReal === $evidence || str_starts_with($packageReal, $evidence . DIRECTORY_SEPARATOR)))
            || ($public !== false && str_starts_with($dumpReal, $public . DIRECTORY_SEPARATOR))) {
            return Outcome::refuse('BACKUP_PRIVATE_PATH_REQUIRED');
        }

        if (!@copy($dumpReal, $packageReal . DIRECTORY_SEPARATOR . self::DUMP)) {
            return Outcome::failure('BACKUP_DUMP_COPY_FAILED');
        }
        @chmod($packageReal . DIRECTORY_SEPARATOR . self::DUMP, 0600);

        $artifacts = $this->copyReferencedArtifacts($packageReal);
        if (!$artifacts->isOk()) {
            return $artifacts;
        }
        $requestLog = $this->copyRequestLogPrefix($packageReal);
        if (!$requestLog->isOk()) {
            return $requestLog;
        }

        $schema = $this->schemaState();
        $dataState = $this->dataState($schema['tables']);
        $rendered = $this->retainedOutputState();
        if (!$rendered->isOk()) {
            return $rendered;
        }
        $semantic = $this->semanticState($rendered->value());
        $console = $this->consoleState((string) $maintenance['environment']);
        if (!$console->isOk()) {
            return $console;
        }
        $toolingDigest = self::toolingFingerprint($this->root);
        if ($toolingDigest === null) {
            return Outcome::refuse('BACKUP_TOOLING_FINGERPRINT_UNAVAILABLE');
        }

        $manifest = [
            'schema_version' => self::VERSION,
            'kind' => 'ALLFATHER_FULL_BACKUP',
            'created_at' => $this->clock->now(),
            'maintenance' => [
                'maintenance_id' => $maintenance['maintenance_id'],
                'operator_id' => $maintenance['operator_id'],
                'environment' => $maintenance['environment'],
                'reason' => $maintenance['reason'],
                'entered_at' => $maintenance['entered_at'],
            ],
            'operation' => [
                'operation_id' => $operation['operation_id'],
                'maintenance_id' => $operation['maintenance_id'],
                'operator_id' => $operation['operator_id'],
                'kind' => $operation['kind'],
                'started_at' => $operation['started_at'],
            ],
            'source' => [
                'release_ref' => $this->releaseRef,
                'database_identity_sha256' => self::databaseIdentity($this->db),
                'database_principal_sha256' => self::databasePrincipalIdentity($this->db),
                'server_version' => $this->db->serverVersion(),
                'source_fingerprint' => SourceFingerprint::compute($this->root),
                'backup_tooling_digest' => $toolingDigest,
                'schema_digest' => $schema['schema_digest'],
                'trigger_digest' => $schema['trigger_digest'],
                'migrations_digest' => $schema['migrations_digest'],
                'migration_state' => $schema['migrations'],
                'trigger_definitions' => $schema['triggers'],
                'table_count' => count($schema['tables']),
                'table_names_digest' => Digest::canonicalDigest($schema['tables']),
                'console_manifest_sha256' => $console->value()['manifest_sha256'],
            ],
            'dump' => [
                'path' => self::DUMP,
                'sha256' => $dumpDigest,
                'size' => (int) $dumpSize,
            ],
            'artifacts' => $artifacts->value(),
            'request_log_prefix' => $requestLog->value(),
            'data_state' => $dataState,
            'semantic_state' => $semantic,
            'restore_policy' => [
                'requires_empty_target' => true,
                'target_must_differ_from_source' => true,
                'native_execution_requires_separate_re_admission' => true,
                'code_rollback_is_not_restore' => true,
            ],
        ];

        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if (preg_match(self::SECRET_PATTERN, $encoded) === 1) {
            return Outcome::failure('BACKUP_MANIFEST_SECRET_PATTERN_DETECTED');
        }
        $manifestPath = $packageReal . DIRECTORY_SEPARATOR . self::FILE;
        if (@file_put_contents($manifestPath, $encoded, LOCK_EX) !== strlen($encoded)) {
            return Outcome::failure('BACKUP_MANIFEST_WRITE_FAILED');
        }
        @chmod($manifestPath, 0600);

        // A builder must not be more permissive than the verifier the operator runs next. In
        // particular staging/production require a concrete release fingerprint and console
        // manifest; do not return "built" and leave that contradiction for a later command.
        $verified = self::verifyPackage($packageReal);
        if (!$verified->isOk()) {
            return $verified;
        }

        return Outcome::ok([
            'manifest' => $manifest,
            'manifest_sha256' => hash('sha256', $encoded),
            'artifact_count' => count($manifest['artifacts']),
            'package_dir' => $packageReal,
        ]);
    }

    public static function load(string $packageDir): Outcome
    {
        $root = realpath($packageDir);
        if ($root === false || !is_dir($root) || is_link($packageDir)) {
            return Outcome::refuse('BACKUP_PACKAGE_NOT_AVAILABLE');
        }
        $path = $root . DIRECTORY_SEPARATOR . self::FILE;
        if (!is_file($path) || is_link($path) || filesize($path) > 4_000_000) {
            return Outcome::refuse('BACKUP_MANIFEST_NOT_AVAILABLE');
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || preg_match(self::SECRET_PATTERN, $raw) === 1) {
            return Outcome::refuse('BACKUP_MANIFEST_SECRET_PATTERN_DETECTED');
        }
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return Outcome::refuse('BACKUP_MANIFEST_INVALID');
        }
        if (!is_array($manifest)
            || !self::exactKeys($manifest, ['schema_version','kind','created_at','maintenance','operation','source','dump','artifacts','request_log_prefix','data_state','semantic_state','restore_policy'])
            || ($manifest['schema_version'] ?? null) !== self::VERSION
            || ($manifest['kind'] ?? null) !== 'ALLFATHER_FULL_BACKUP'
            || !is_array($manifest['maintenance'] ?? null)
            || !self::exactKeys($manifest['maintenance'], ['maintenance_id','operator_id','environment','reason','entered_at'])
            || !is_array($manifest['operation'] ?? null)
            || !self::exactKeys($manifest['operation'], ['operation_id','maintenance_id','operator_id','kind','started_at'])
            || !is_array($manifest['source'] ?? null)
            || !self::exactKeys($manifest['source'], ['release_ref','database_identity_sha256','database_principal_sha256','server_version','source_fingerprint','backup_tooling_digest','schema_digest','trigger_digest','migrations_digest','migration_state','trigger_definitions','table_count','table_names_digest','console_manifest_sha256'])
            || !is_array($manifest['dump'] ?? null)
            || !self::exactKeys($manifest['dump'], ['path','sha256','size'])
            || !is_array($manifest['artifacts'] ?? null)
            || !array_is_list($manifest['artifacts'])
            || !is_array($manifest['data_state'] ?? null)
            || !self::exactKeys($manifest['data_state'], ['tables','digest'])
            || !is_array($manifest['semantic_state'] ?? null)
            || !self::exactKeys($manifest['semantic_state'], ['counts','current_reliance_digest','monitor_state_digest','retained_output_count','retained_output_digest','digest'])
            || !is_array($manifest['restore_policy'] ?? null)
            || !self::exactKeys($manifest['restore_policy'], ['requires_empty_target','target_must_differ_from_source','native_execution_requires_separate_re_admission','code_rollback_is_not_restore'])) {
            return Outcome::refuse('BACKUP_MANIFEST_INVALID');
        }

        foreach (['database_identity_sha256','database_principal_sha256','backup_tooling_digest','schema_digest','trigger_digest','migrations_digest','table_names_digest'] as $key) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['source'][$key] ?? '')) !== 1) {
                return Outcome::refuse('BACKUP_MANIFEST_INVALID');
            }
        }
        if (!is_array($manifest['source']['migration_state'] ?? null)
            || !array_is_list($manifest['source']['migration_state'])
            || !is_array($manifest['source']['trigger_definitions'] ?? null)
            || !array_is_list($manifest['source']['trigger_definitions'])
            || !hash_equals(
                (string) $manifest['source']['migrations_digest'],
                Digest::canonicalDigest($manifest['source']['migration_state']),
            )
            || !hash_equals(
                (string) $manifest['source']['trigger_digest'],
                Digest::canonicalDigest($manifest['source']['trigger_definitions']),
            )) {
            return Outcome::refuse('BACKUP_MANIFEST_INVALID');
        }
        foreach ($manifest['source']['migration_state'] as $migration) {
            if (!is_array($migration)
                || !self::exactKeys($migration, ['migration_id','content_digest','statement_count'])
                || !is_string($migration['migration_id'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($migration['content_digest'] ?? '')) !== 1
                || !is_int($migration['statement_count'] ?? null)
                || $migration['statement_count'] < 0) {
                return Outcome::refuse('BACKUP_MANIFEST_INVALID');
            }
        }
        foreach ($manifest['source']['trigger_definitions'] as $trigger) {
            if (!is_array($trigger)
                || !self::exactKeys($trigger, ['trigger_name','event_manipulation','event_object_table','action_timing','action_statement'])
                || !is_string($trigger['trigger_name'] ?? null)
                || !is_string($trigger['event_manipulation'] ?? null)
                || !is_string($trigger['event_object_table'] ?? null)
                || !is_string($trigger['action_timing'] ?? null)
                || !is_string($trigger['action_statement'] ?? null)) {
                return Outcome::refuse('BACKUP_MANIFEST_INVALID');
            }
        }
        if (!is_int($manifest['source']['table_count'] ?? null) || $manifest['source']['table_count'] < 1
            || !is_string($manifest['source']['server_version'] ?? null)
            || !is_string($manifest['source']['source_fingerprint'] ?? null)
            || (!in_array($manifest['maintenance']['environment'] ?? null, ['development','testing'], true)
                && preg_match('/^[a-f0-9]{40}$/D', (string) ($manifest['source']['release_ref'] ?? '')) !== 1)
            || (($manifest['source']['release_ref'] ?? null) !== null
                && preg_match('/^[a-f0-9]{40}$/D', (string) $manifest['source']['release_ref']) !== 1)
            || (!in_array($manifest['maintenance']['environment'] ?? null, ['development','testing'], true)
                && preg_match('/^[a-f0-9]{64}$/D', (string) $manifest['source']['source_fingerprint']) !== 1)
            || (!in_array($manifest['maintenance']['environment'] ?? null, ['development','testing'], true)
                && preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['source']['console_manifest_sha256'] ?? '')) !== 1)
            || (($manifest['source']['console_manifest_sha256'] ?? null) !== null
                && preg_match('/^[a-f0-9]{64}$/D', (string) $manifest['source']['console_manifest_sha256']) !== 1)
            || !in_array($manifest['maintenance']['environment'] ?? null, ['development','testing','staging','production'], true)
            || ($manifest['maintenance']['reason'] ?? null) !== 'BACKUP'
            || preg_match('/^[a-f0-9-]{36}$/D', (string) ($manifest['maintenance']['maintenance_id'] ?? '')) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', (string) ($manifest['maintenance']['operator_id'] ?? '')) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', (string) ($manifest['maintenance']['entered_at'] ?? '')) !== 1
            || preg_match('/^[a-f0-9-]{36}$/D', (string) ($manifest['operation']['operation_id'] ?? '')) !== 1
            || ($manifest['operation']['kind'] ?? null) !== 'BACKUP_EXPORT'
            || !hash_equals(
                (string) ($manifest['maintenance']['maintenance_id'] ?? ''),
                (string) ($manifest['operation']['maintenance_id'] ?? ''),
            )
            || !hash_equals(
                (string) ($manifest['maintenance']['operator_id'] ?? ''),
                (string) ($manifest['operation']['operator_id'] ?? ''),
            )
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', (string) ($manifest['operation']['started_at'] ?? '')) !== 1
            || (string) $manifest['operation']['started_at'] < (string) $manifest['maintenance']['entered_at']
            || (string) $manifest['created_at'] < (string) $manifest['operation']['started_at']) {
            return Outcome::refuse('BACKUP_MANIFEST_INVALID');
        }
        foreach (['requires_empty_target','target_must_differ_from_source','native_execution_requires_separate_re_admission','code_rollback_is_not_restore'] as $key) {
            if (($manifest['restore_policy'][$key] ?? null) !== true) {
                return Outcome::refuse('BACKUP_MANIFEST_INVALID');
            }
        }
        $dataState = $manifest['data_state'];
        if (!is_array($dataState['tables'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($dataState['digest'] ?? '')) !== 1
            || !hash_equals((string) $dataState['digest'], Digest::canonicalDigest($dataState['tables']))) {
            return Outcome::refuse('BACKUP_MANIFEST_INVALID');
        }
        foreach ($dataState['tables'] as $table => $state) {
            if (!is_string($table) || preg_match('/^[a-z_][a-z0-9_]*$/D', $table) !== 1
                || !is_array($state)
                || !self::exactKeys($state, ['count','digest'])
                || !is_int($state['count'] ?? null) || $state['count'] < 0
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($state['digest'] ?? '')) !== 1) {
                return Outcome::refuse('BACKUP_MANIFEST_INVALID');
            }
        }
        $manifestTableNames = array_keys($dataState['tables']);
        sort($manifestTableNames, SORT_STRING);
        if ($manifest['source']['table_count'] !== count($manifestTableNames)
            || !hash_equals($manifest['source']['table_names_digest'], Digest::canonicalDigest($manifestTableNames))
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', (string) ($manifest['created_at'] ?? '')) !== 1
            || trim((string) $manifest['source']['server_version']) === ''
            || strlen((string) $manifest['source']['server_version']) > 255) {
            return Outcome::refuse('BACKUP_MANIFEST_INVALID');
        }

        $semantic = $manifest['semantic_state'];
        $semanticCountKeys = array_keys(is_array($semantic['counts'] ?? null) ? $semantic['counts'] : []);
        sort($semanticCountKeys, SORT_STRING);
        $expectedSemanticCountKeys = self::SEMANTIC_TABLES;
        sort($expectedSemanticCountKeys, SORT_STRING);
        if (!is_array($semantic['counts'] ?? null)
            || $semanticCountKeys !== $expectedSemanticCountKeys
            || array_filter($semantic['counts'], static fn (mixed $v): bool => !is_int($v) || $v < 0) !== []
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($semantic['current_reliance_digest'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($semantic['monitor_state_digest'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($semantic['digest'] ?? '')) !== 1
            || !is_int($semantic['retained_output_count'] ?? null)
            || $semantic['retained_output_count'] < 0
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($semantic['retained_output_digest'] ?? '')) !== 1
            || !hash_equals((string) $semantic['digest'], Digest::canonicalDigest([
                'counts' => $semantic['counts'],
                'current_reliance_digest' => $semantic['current_reliance_digest'],
                'monitor_state_digest' => $semantic['monitor_state_digest'],
                'retained_output_count' => $semantic['retained_output_count'],
                'retained_output_digest' => $semantic['retained_output_digest'],
            ]))) {
            return Outcome::refuse('BACKUP_MANIFEST_INVALID');
        }

        return Outcome::ok(['root' => $root, 'manifest' => $manifest]);
    }

    public static function verifyPackage(string $packageDir): Outcome
    {
        $loaded = self::load($packageDir);
        if (!$loaded->isOk()) {
            return $loaded;
        }
        $root = $loaded->value()['root'];
        $manifest = $loaded->value()['manifest'];

        $dump = $manifest['dump'];
        if (($dump['path'] ?? null) !== self::DUMP
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($dump['sha256'] ?? '')) !== 1
            || !is_int($dump['size'] ?? null) || $dump['size'] < 1) {
            return Outcome::refuse('BACKUP_DUMP_MANIFEST_INVALID');
        }
        $dumpPath = $root . DIRECTORY_SEPARATOR . self::DUMP;
        if (!is_file($dumpPath) || is_link($dumpPath)
            || filesize($dumpPath) !== $dump['size']
            || !hash_equals($dump['sha256'], (string) hash_file('sha256', $dumpPath))) {
            return Outcome::refuse('BACKUP_DUMP_INTEGRITY_FAILED');
        }
        $seen = [];
        foreach ($manifest['artifacts'] as $artifact) {
            if (!is_array($artifact)
                || !self::exactKeys($artifact, ['digest','size'])
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($artifact['digest'] ?? '')) !== 1
                || !is_int($artifact['size'] ?? null) || $artifact['size'] < 0
                || isset($seen[$artifact['digest']])) {
                return Outcome::refuse('BACKUP_ARTIFACT_MANIFEST_INVALID');
            }
            $seen[$artifact['digest']] = true;
            $path = self::artifactPath($root, $artifact['digest']);
            if (!is_file($path) || is_link($path) || filesize($path) !== $artifact['size']
                || !hash_equals($artifact['digest'], (string) hash_file('sha256', $path))) {
                return Outcome::refuse('BACKUP_ARTIFACT_INTEGRITY_FAILED', ['digest' => $artifact['digest']]);
            }
        }

        $prefix = $manifest['request_log_prefix'] ?? null;
        if ($prefix !== null) {
            if (!is_array($prefix) || !self::exactKeys($prefix, ['path','size','sha256'])
                || ($prefix['path'] ?? null) !== self::REQUEST_LOG_PREFIX
                || !is_int($prefix['size'] ?? null) || $prefix['size'] < 0
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($prefix['sha256'] ?? '')) !== 1) {
                return Outcome::refuse('BACKUP_REQUEST_LOG_PREFIX_INVALID');
            }
            $path = $root . DIRECTORY_SEPARATOR . self::REQUEST_LOG_PREFIX;
            if (!is_file($path) || is_link($path) || filesize($path) !== $prefix['size']
                || !hash_equals($prefix['sha256'], (string) hash_file('sha256', $path))) {
                return Outcome::refuse('BACKUP_REQUEST_LOG_PREFIX_INTEGRITY_FAILED');
            }
        }

        $expectedFiles = [self::FILE, self::DUMP];
        if (($manifest['request_log_prefix'] ?? null) !== null) {
            $expectedFiles[] = self::REQUEST_LOG_PREFIX;
        }
        foreach ($manifest['artifacts'] as $artifact) {
            $expectedFiles[] = self::artifactRelativePath($artifact['digest']);
        }
        sort($expectedFiles, SORT_STRING);

        $expectedDirs = [];
        foreach ($expectedFiles as $file) {
            $dir = dirname($file);
            while ($dir !== '.' && $dir !== '') {
                $expectedDirs[$dir] = true;
                $next = dirname($dir);
                if ($next === $dir) break;
                $dir = $next;
            }
        }
        $expectedDirs = array_keys($expectedDirs);
        sort($expectedDirs, SORT_STRING);

        $actualFiles = [];
        $actualDirs = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($walk as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            if ($entry->isLink()) {
                return Outcome::refuse('BACKUP_PACKAGE_SYMLINK_REFUSED', ['path' => $relative]);
            }
            if ($entry->isFile()) {
                $actualFiles[] = $relative;
            } elseif ($entry->isDir()) {
                $actualDirs[] = rtrim($relative, '/');
            } else {
                return Outcome::refuse('BACKUP_PACKAGE_SPECIAL_FILE_REFUSED', ['path' => $relative]);
            }
        }
        sort($actualFiles, SORT_STRING);
        sort($actualDirs, SORT_STRING);
        if ($actualFiles !== $expectedFiles || $actualDirs !== $expectedDirs) {
            return Outcome::refuse('BACKUP_PACKAGE_UNDECLARED_CONTENT');
        }

        return Outcome::ok([
            'manifest' => $manifest,
            'package_root' => $root,
            'artifact_count' => count($manifest['artifacts']),
        ]);
    }

    public function verifyRestoredDatabase(array $manifest, string $storageRoot): Outcome
    {
        if (!is_array($manifest['source'] ?? null)
            || !is_string($manifest['source']['database_identity_sha256'] ?? null)) {
            return Outcome::refuse('RESTORE_MANIFEST_SOURCE_INVALID');
        }
        $runtime = $this->verifyRuntimeIdentity($manifest);
        if (!$runtime->isOk()) {
            return $runtime;
        }
        $targetIdentity = self::databaseIdentity($this->db);
        if (hash_equals($manifest['source']['database_identity_sha256'], $targetIdentity)) {
            return Outcome::refuse('RESTORE_TARGET_EQUALS_SOURCE');
        }
        $sourceLine = self::serverLine((string) ($manifest['source']['server_version'] ?? ''));
        $targetLine = self::serverLine($this->db->serverVersion());
        if ($sourceLine === null || $targetLine === null || !hash_equals($sourceLine, $targetLine)) {
            return Outcome::refuse('RESTORE_MARIADB_LINE_MISMATCH', [
                'source_line' => $sourceLine,
                'target_line' => $targetLine,
            ]);
        }
        if (($manifest['source']['trigger_definitions'] ?? []) !== []
            && !hash_equals(
                (string) ($manifest['source']['database_principal_sha256'] ?? ''),
                self::databasePrincipalIdentity($this->db),
            )) {
            return Outcome::refuse('RESTORE_TRIGGER_DEFINER_PRINCIPAL_MISMATCH');
        }

        $objects = $this->databaseObjectSupport();
        if (!$objects->isOk()) {
            return $objects;
        }

        $schema = $this->schemaState();
        foreach (['schema_digest','trigger_digest','migrations_digest'] as $key) {
            if (!is_string($manifest['source'][$key] ?? null)
                || !hash_equals($manifest['source'][$key], $schema[$key])) {
                return Outcome::refuse('RESTORE_SCHEMA_MISMATCH', ['component' => $key]);
            }
        }
        if ((int) ($manifest['source']['table_count'] ?? -1) !== count($schema['tables'])
            || !hash_equals((string) ($manifest['source']['table_names_digest'] ?? ''), Digest::canonicalDigest($schema['tables']))) {
            return Outcome::refuse('RESTORE_TABLE_INVENTORY_MISMATCH');
        }

        $integrity = $this->governedHistoryIntegrity();
        if (!$integrity->isOk()) {
            return $integrity;
        }

        $currentData = $this->dataState($schema['tables']);
        if (!hash_equals((string) ($manifest['data_state']['digest'] ?? ''), $currentData['digest'])) {
            $mismatched = [];
            foreach ($currentData['tables'] as $table => $state) {
                $expected = $manifest['data_state']['tables'][$table] ?? null;
                if (!is_array($expected)
                    || (int) ($expected['count'] ?? -1) !== $state['count']
                    || !hash_equals((string) ($expected['digest'] ?? ''), $state['digest'])) {
                    $mismatched[] = $table;
                    if (count($mismatched) >= 20) break;
                }
            }
            return Outcome::refuse('RESTORE_DATA_STATE_MISMATCH', ['tables' => $mismatched]);
        }

        if ($this->admittedGrantCount() > 0) {
            return Outcome::refuse('RESTORE_EXECUTION_GRANTS_ADMITTED');
        }

        $storageReal = realpath($storageRoot);
        if ($storageReal === false || !is_dir($storageReal) || is_link($storageRoot)) {
            return Outcome::refuse('RESTORE_STORAGE_ROOT_INVALID');
        }
        $evidenceRoot = $storageReal . DIRECTORY_SEPARATOR . 'evidence';
        if (!is_dir($evidenceRoot) || is_link($evidenceRoot)) {
            return Outcome::refuse('RESTORE_EVIDENCE_ROOT_INVALID');
        }
        $store = new EvidenceStore($evidenceRoot);
        foreach ($manifest['artifacts'] ?? [] as $artifact) {
            $read = $store->get((string) ($artifact['digest'] ?? ''));
            if (!$read->isOk() || strlen((string) $read->value()) !== (int) ($artifact['size'] ?? -1)) {
                return Outcome::refuse('RESTORE_ARTIFACT_MISSING_OR_CORRUPT', ['digest' => $artifact['digest'] ?? null]);
            }
        }

        $prefix = $manifest['request_log_prefix'] ?? null;
        if ($prefix !== null) {
            $requestLog = $storageReal . DIRECTORY_SEPARATOR . 'request.log';
            if (!is_file($requestLog) || is_link($requestLog)
                || filesize($requestLog) !== (int) ($prefix['size'] ?? -1)
                || !hash_equals((string) ($prefix['sha256'] ?? ''), (string) hash_file('sha256', $requestLog))) {
                return Outcome::refuse('RESTORE_REQUEST_LOG_PREFIX_MISMATCH');
            }
        }

        $rendered = $this->retainedOutputState();
        if (!$rendered->isOk()) {
            return Outcome::refuse('RESTORE_RETAINED_OUTPUT_UNRENDERABLE', [
                'reason_code' => $rendered->reasonCode(),
            ]);
        }
        $semantic = $this->semanticState($rendered->value());
        if (!hash_equals((string) ($manifest['semantic_state']['digest'] ?? ''), (string) $semantic['digest'])) {
            return Outcome::refuse('RESTORE_SEMANTIC_STATE_MISMATCH');
        }

        return Outcome::ok([
            'database_identity_sha256' => $targetIdentity,
            'tables' => count($schema['tables']),
            'artifacts' => count($manifest['artifacts'] ?? []),
            'm10_streams' => $semantic['counts']['decision_ledger_streams'],
            'monitor_runs' => $semantic['counts']['monitor_runs'],
            'retained_outputs_rendered' => $semantic['retained_output_count'],
            'runtime_started' => false,
            'native_execution_requires_separate_re_admission' => true,
            'restore_verified' => true,
        ]);
    }

    /** Verify the code/assets performing the rehearsal are the release the backup names. */
    public function verifyRuntimeIdentity(array $manifest): Outcome
    {
        $source = $manifest['source'] ?? null;
        if (!is_array($source)) {
            return Outcome::refuse('RESTORE_MANIFEST_SOURCE_INVALID');
        }
        $releaseRef = $source['release_ref'] ?? null;
        if ($releaseRef !== null
            && (!is_string($this->releaseRef) || !hash_equals($releaseRef, $this->releaseRef))) {
            return Outcome::refuse('RESTORE_RELEASE_REF_MISMATCH');
        }

        $tooling = self::toolingFingerprint($this->root);
        if ($tooling === null
            || !hash_equals((string) ($source['backup_tooling_digest'] ?? ''), $tooling)) {
            return Outcome::refuse('RESTORE_BACKUP_TOOLING_MISMATCH');
        }

        $sourceFingerprint = (string) ($source['source_fingerprint'] ?? '');
        if ($sourceFingerprint !== SourceFingerprint::NOT_AVAILABLE) {
            $current = SourceFingerprint::compute($this->root);
            if ($current === SourceFingerprint::NOT_AVAILABLE || !hash_equals($sourceFingerprint, $current)) {
                return Outcome::refuse('RESTORE_SOURCE_FINGERPRINT_MISMATCH');
            }
        }

        $expectedConsole = $source['console_manifest_sha256'] ?? null;
        if ($expectedConsole !== null) {
            $stage = $this->root . '/public_html/assets/operator-console';
            $path = $stage . '/manifest.json';
            try {
                (new ConsoleAssetManifest($stage))->load();
            } catch (\Throwable) {
                return Outcome::refuse('RESTORE_CONSOLE_MANIFEST_MISMATCH');
            }
            $actual = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (!is_string($actual) || !hash_equals((string) $expectedConsole, $actual)) {
                return Outcome::refuse('RESTORE_CONSOLE_MANIFEST_MISMATCH');
            }
        }

        return Outcome::ok([
            'release_ref' => $releaseRef,
            'source_fingerprint' => $sourceFingerprint,
            'console_manifest_sha256' => $expectedConsole,
        ]);
    }

    private function consoleState(string $environment): Outcome
    {
        $stage = $this->root . '/public_html/assets/operator-console';
        $manifestPath = $stage . '/manifest.json';
        if (!is_dir($stage)) {
            return in_array($environment, ['development','testing'], true)
                ? Outcome::ok(['manifest_sha256' => null])
                : Outcome::refuse('BACKUP_CONSOLE_STAGE_REQUIRED');
        }
        if (is_link($stage) || !is_file($manifestPath) || is_link($manifestPath)) {
            return Outcome::refuse('BACKUP_CONSOLE_MANIFEST_INVALID');
        }
        try {
            (new ConsoleAssetManifest($stage))->load();
        } catch (\Throwable) {
            return Outcome::refuse('BACKUP_CONSOLE_MANIFEST_INVALID');
        }
        $digest = hash_file('sha256', $manifestPath);
        if (!is_string($digest)) {
            return Outcome::refuse('BACKUP_CONSOLE_MANIFEST_INVALID');
        }

        return Outcome::ok(['manifest_sha256' => $digest]);
    }

    private static function toolingFingerprint(string $root): ?string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $inventory = [];
        foreach (self::TOOLING_FILES as $relative) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path) || is_link($path)) {
                return null;
            }
            $digest = hash_file('sha256', $path);
            if (!is_string($digest)) {
                return null;
            }
            $inventory[$relative] = $digest;
        }
        ksort($inventory, SORT_STRING);

        return Digest::canonicalDigest($inventory);
    }

    public static function databaseIdentity(Database $db): string
    {
        $row = $db->one('SELECT DATABASE() AS db_name, @@hostname AS host_name, @@port AS server_port');

        return hash(
            'sha256',
            (string) ($row['host_name'] ?? '') . "\0"
            . (string) ($row['server_port'] ?? '') . "\0"
            . (string) ($row['db_name'] ?? ''),
        );
    }


    public static function databasePrincipalIdentity(Database $db): string
    {
        return hash('sha256', (string) $db->scalar('SELECT CURRENT_USER()'));
    }

    public static function serverLine(string $version): ?string
    {
        return preg_match('/^(\d+\.\d+)\./D', $version, $match) === 1 ? $match[1] : null;
    }

    /** P10 currently restores tables+triggers only. Unexpected object families fail closed. */
    private function databaseObjectSupport(): Outcome
    {
        $nonTransactional = $this->db->all(
            "SELECT table_name,engine FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
               AND (engine IS NULL OR UPPER(engine) <> 'INNODB')
             ORDER BY table_name"
        );
        if ($nonTransactional !== []) {
            return Outcome::refuse('BACKUP_NON_TRANSACTIONAL_TABLES', [
                'tables' => array_map(
                    static fn (array $row): array => [
                        'table_name' => (string) $row['table_name'],
                        'engine' => $row['engine'] === null ? null : (string) $row['engine'],
                    ],
                    array_slice($nonTransactional, 0, 20),
                ),
            ]);
        }

        $row = $this->db->one(
            "SELECT
                (SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema=DATABASE() AND table_type <> 'BASE TABLE') AS non_base_table_count,
                (SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE()) AS view_count,
                (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE()) AS routine_count,
                (SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE()) AS event_count"
        );
        $unsupported = [
            'non_base_tables' => (int) ($row['non_base_table_count'] ?? 0),
            'views' => (int) ($row['view_count'] ?? 0),
            'routines' => (int) ($row['routine_count'] ?? 0),
            'events' => (int) ($row['event_count'] ?? 0),
        ];
        if (array_sum($unsupported) > 0) {
            return Outcome::refuse('BACKUP_UNSUPPORTED_DATABASE_OBJECTS', $unsupported);
        }

        $currentUser = (string) $this->db->scalar('SELECT CURRENT_USER()');
        foreach ($this->db->all(
            'SELECT trigger_name,definer FROM information_schema.triggers
             WHERE trigger_schema=DATABASE() ORDER BY trigger_name'
        ) as $trigger) {
            if (!hash_equals($currentUser, (string) ($trigger['definer'] ?? ''))) {
                return Outcome::refuse('BACKUP_TRIGGER_DEFINER_MISMATCH', [
                    'trigger_name' => $trigger['trigger_name'] ?? null,
                ]);
            }
        }

        return Outcome::ok(['unsupported' => $unsupported]);
    }

    /** @return array{schema_digest:string,trigger_digest:string,migrations_digest:string,tables:list<string>,triggers:list<array<string,mixed>>,migrations:list<array<string,mixed>>} */
    private function schemaState(): array
    {
        $tables = array_map(
            static fn (array $row): string => (string) $row['table_name'],
            $this->db->all(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
                 ORDER BY table_name"
            ),
        );
        // The manifest verifier sorts restored table names with PHP SORT_STRING before comparing the
        // names digest. MariaDB ORDER BY follows the server/database collation and can order
        // punctuation (notably underscores) differently, so normalize the source side the same way.
        // Without this, build() can write a perfectly complete package that its own verifier rejects
        // as BACKUP_MANIFEST_INVALID on a real MariaDB lane.
        sort($tables, SORT_STRING);
        $creates = [];
        foreach ($tables as $table) {
            $row = $this->db->one('SHOW CREATE TABLE ' . Database::identifier($table));
            $creates[$table] = (string) (array_values($row ?? [])[1] ?? '');
        }
        $triggers = array_map(
            static fn (array $row): array => [
                'trigger_name' => (string) $row['trigger_name'],
                'event_manipulation' => (string) $row['event_manipulation'],
                'event_object_table' => (string) $row['event_object_table'],
                'action_timing' => (string) $row['action_timing'],
                'action_statement' => (string) $row['action_statement'],
            ],
            $this->db->all(
                "SELECT trigger_name,event_manipulation,event_object_table,action_timing,action_statement
                 FROM information_schema.triggers WHERE trigger_schema=DATABASE()
                 ORDER BY trigger_name"
            ),
        );
        $migrations = array_map(
            static fn (array $row): array => [
                'migration_id' => (string) $row['migration_id'],
                'content_digest' => (string) $row['content_digest'],
                'statement_count' => (int) $row['statement_count'],
            ],
            $this->db->all(
                'SELECT migration_id,content_digest,statement_count FROM schema_migrations ORDER BY migration_id'
            ),
        );

        return [
            'schema_digest' => Digest::canonicalDigest($creates),
            'trigger_digest' => Digest::canonicalDigest($triggers),
            'migrations_digest' => Digest::canonicalDigest($migrations),
            'tables' => $tables,
            'triggers' => $triggers,
            'migrations' => $migrations,
        ];
    }

    /**
     * Digest every database row without putting row contents in the manifest. Quiescence makes the
     * dump and this post-dump read the same logical state; restore must reproduce it table-for-table.
     *
     * @param list<string> $tables
     * @return array{tables:array<string,array{count:int,digest:string}>,digest:string}
     */
    private function dataState(array $tables): array
    {
        $state = [];
        foreach ($tables as $table) {
            $rows = $this->db->all('SELECT * FROM ' . Database::identifier($table));
            $rowDigests = array_map(self::databaseRowDigest(...), $rows);
            sort($rowDigests, SORT_STRING);
            $state[$table] = [
                'count' => count($rowDigests),
                // Order-independent at the table level. That matters for tables whose logical
                // history is append-only but whose physical scan order is not a contract.
                'digest' => Digest::canonicalDigest($rowDigests),
            ];
        }
        ksort($state, SORT_STRING);

        return ['tables' => $state, 'digest' => Digest::canonicalDigest($state)];
    }

    /** @param array<string,mixed> $row */
    private static function databaseRowDigest(array $row): string
    {
        ksort($row, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($row as $column => $value) {
            hash_update($hash, $column . "\0");
            if ($value === null) {
                hash_update($hash, "null\0");
                continue;
            }
            // The schema digest already pins each column's SQL type. Row-state comparison should
            // normalize PDO transport representation rather than treating the same scalar returned
            // as an int on one mysqlnd build and a string on another as different database content.
            // Raw bytes remain raw bytes, so binary values do not acquire a UTF-8 dependency.
            $bytes = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            hash_update($hash, "value\0" . strlen($bytes) . "\0" . $bytes . "\0");
        }

        return hash_final($hash);
    }

    /**
     * @param array{count:int,digest:string} $retained
     * @return array{counts:array<string,int>,current_reliance_digest:string,monitor_state_digest:string,retained_output_count:int,retained_output_digest:string,digest:string}
     */
    private function semanticState(array $retained): array
    {
        $counts = [];
        foreach (self::SEMANTIC_TABLES as $table) {
            $counts[$table] = (int) $this->db->scalar('SELECT COUNT(*) FROM ' . Database::identifier($table));
        }

        $ledger = new DecisionLedger($this->db, $this->clock);
        $reliance = [];
        foreach ($this->db->all('SELECT decision_record_id FROM decision_records ORDER BY decision_record_id') as $row) {
            $id = (string) $row['decision_record_id'];
            $reliance[$id] = $ledger->currentReliance($id);
        }
        $runs = $this->db->all(
            'SELECT monitor_run_id,tenant_id,workspace_id,mission_id,execution_mode,stage,revision,event_digest
             FROM monitor_runs ORDER BY monitor_run_id'
        );

        $state = [
            'counts' => $counts,
            'current_reliance_digest' => Digest::canonicalDigest($reliance),
            'monitor_state_digest' => Digest::canonicalDigest($runs),
            'retained_output_count' => $retained['count'],
            'retained_output_digest' => $retained['digest'],
        ];
        $state['digest'] = Digest::canonicalDigest($state);

        return $state;
    }

    /** Verify M10 and Monitor append-only histories without writing verification records. */
    private function governedHistoryIntegrity(): Outcome
    {
        $ledger = new DecisionLedger($this->db, $this->clock);
        foreach ($this->db->all('SELECT decision_ledger_stream_id FROM decision_ledger_streams ORDER BY decision_ledger_stream_id') as $row) {
            $verified = $ledger->verifyStream((string) $row['decision_ledger_stream_id']);
            if (($verified['status'] ?? '') !== 'PASS') {
                return Outcome::refuse('RESTORE_M10_CHAIN_INVALID', [
                    'stream_id' => $row['decision_ledger_stream_id'],
                ]);
            }
        }

        $journal = new RunJournal($this->db, $this->clock);
        foreach ($this->db->all(
            'SELECT monitor_run_id,tenant_id,workspace_id,initiated_by_operator_id
             FROM monitor_runs ORDER BY monitor_run_id'
        ) as $row) {
            $replayed = $journal->replay([
                'tenant_id' => $row['tenant_id'],
                'workspace_id' => $row['workspace_id'],
                'operator_id' => $row['initiated_by_operator_id'],
            ], (string) $row['monitor_run_id']);
            if (!$replayed->isOk() || ($replayed->value()['integrity'] ?? '') !== 'PASS') {
                return Outcome::refuse('RESTORE_MONITOR_CHAIN_INVALID', [
                    'monitor_run_id' => $row['monitor_run_id'],
                ]);
            }
        }

        return Outcome::ok();
    }

    /**
     * Prove retained outputs can still be materialized as safe read models without invoking M02.
     *
     * Surfacing APIs intentionally record access evaluations, so a restore verifier must not call
     * them: verification is read-only. Instead this replays the immutable render inputs themselves,
     * re-derives M16 artifact/projection digests, checks current reliance/visibility state, decodes
     * every stored JSON field, and JSON-encodes the exact read model a surface would hand onward.
     *
     * P04 role-distribution outputs are also materialized from their retained safe summaries. The
     * returned manifest fact is a count+digest only; the rendered customer/tenant text never enters
     * the backup manifest.
     *
     * @return Outcome<array{count:int,digest:string}>
     */
    private function retainedOutputState(): Outcome
    {
        $rendered = [];
        $ledger = new DecisionLedger($this->db, $this->clock);

        $projections = $this->db->all(
            "SELECT cop.customer_output_projection_id,cop.release_artifact_id,cop.audience_class,
                    cop.status AS projection_status,cop.refusal_reason_codes,
                    cop.content_elements_safe_json,cop.redaction_decisions_json,
                    cop.contains_claim,cop.caveat_blocks_present,cop.projection_digest,cop.projected_at,
                    ra.artifact_kind,ra.decision_record_id,ra.proposal_id,ra.source_use_profile_digest,
                    ra.content_elements_json AS artifact_content_elements_json,
                    ra.uncertainty_summary_safe,ra.claim_ceiling,ra.artifact_digest,
                    ra.status AS artifact_status
             FROM customer_output_projections cop
             JOIN release_artifacts ra ON ra.release_artifact_id=cop.release_artifact_id
             ORDER BY cop.customer_output_projection_id"
        );
        foreach ($projections as $row) {
            try {
                $projectionElements = json_decode((string) $row['content_elements_safe_json'], true, 128, JSON_THROW_ON_ERROR);
                $redactions = json_decode((string) $row['redaction_decisions_json'], true, 128, JSON_THROW_ON_ERROR);
                $refusals = json_decode((string) $row['refusal_reason_codes'], true, 128, JSON_THROW_ON_ERROR);
                $artifactElements = json_decode((string) $row['artifact_content_elements_json'], true, 128, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return Outcome::refuse('RETAINED_OUTPUT_JSON_INVALID', [
                    'projection_id' => $row['customer_output_projection_id'],
                ]);
            }
            if (!is_array($projectionElements) || !array_is_list($projectionElements)
                || !is_array($redactions) || !array_is_list($redactions)
                || !is_array($refusals) || !array_is_list($refusals)
                || !is_array($artifactElements) || !array_is_list($artifactElements)) {
                return Outcome::refuse('RETAINED_OUTPUT_JSON_SHAPE_INVALID', [
                    'projection_id' => $row['customer_output_projection_id'],
                ]);
            }

            $artifactDigest = Digest::canonicalDigest([
                'artifactKind' => (string) $row['artifact_kind'],
                'decisionRecordId' => (string) $row['decision_record_id'],
                'proposalId' => (string) $row['proposal_id'],
                'sourceUseProfileDigest' => (string) $row['source_use_profile_digest'],
                'contentElements' => $artifactElements,
                'uncertaintySummarySafe' => (string) $row['uncertainty_summary_safe'],
                'claimCeiling' => (string) $row['claim_ceiling'],
            ]);
            if (!hash_equals((string) $row['artifact_digest'], $artifactDigest)) {
                return Outcome::refuse('RETAINED_RELEASE_ARTIFACT_DIGEST_INVALID', [
                    'release_artifact_id' => $row['release_artifact_id'],
                ]);
            }

            $projectionDigest = Digest::canonicalDigest([
                'releaseArtifactId' => (string) $row['release_artifact_id'],
                'audienceClass' => (string) $row['audience_class'],
                'elements' => $projectionElements,
            ]);
            if (!hash_equals((string) $row['projection_digest'], $projectionDigest)) {
                return Outcome::refuse('RETAINED_OUTPUT_PROJECTION_DIGEST_INVALID', [
                    'projection_id' => $row['customer_output_projection_id'],
                ]);
            }
            if (($row['projection_status'] === 'PROJECTED' && $refusals !== [])
                || ($row['projection_status'] === 'REFUSED' && ($refusals === [] || $projectionElements !== []))) {
                return Outcome::refuse('RETAINED_OUTPUT_PROJECTION_STATE_INVALID', [
                    'projection_id' => $row['customer_output_projection_id'],
                ]);
            }

            $latest = $this->db->one(
                'SELECT event_kind FROM release_visibility_activations
                 WHERE customer_output_projection_id=? ORDER BY event_sequence DESC LIMIT 1',
                [(string) $row['customer_output_projection_id']],
            );
            $visible = $row['projection_status'] === 'PROJECTED'
                && $row['artifact_status'] === 'RELEASED_INTERNAL'
                && ($latest['event_kind'] ?? null) === 'ACTIVATED'
                && $ledger->currentReliance((string) $row['decision_record_id']) === 'ACTIVE_AS_RECORDED';

            $model = [
                'kind' => 'M16_OUTPUT_PROJECTION',
                'projection_id' => (string) $row['customer_output_projection_id'],
                'release_artifact_id' => (string) $row['release_artifact_id'],
                'artifact_kind' => (string) $row['artifact_kind'],
                'audience_class' => (string) $row['audience_class'],
                'status' => (string) $row['projection_status'],
                'visible_now' => $visible,
                'content_elements' => $projectionElements,
                'redaction_decisions' => $redactions,
                'contains_claim' => (bool) $row['contains_claim'],
                'caveat_blocks_present' => (bool) $row['caveat_blocks_present'],
                'uncertainty_summary_safe' => (string) $row['uncertainty_summary_safe'],
                'claim_ceiling' => (string) $row['claim_ceiling'],
                'projected_at' => (string) $row['projected_at'],
            ];
            try {
                json_encode($model, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable) {
                return Outcome::refuse('RETAINED_OUTPUT_NOT_RENDERABLE', [
                    'projection_id' => $row['customer_output_projection_id'],
                ]);
            }
            $rendered[] = $model;
        }

        $distributed = $this->db->all(
            "SELECT rdo.released_distribution_output_id,rdo.output_kind,rdo.decision_record_id,
                    rdo.released_at,rdo.role_distribution_briefing_id,rdo.distribution_routing_record_id,
                    b.target_org_role,b.briefing_summary_safe,b.briefing_status,
                    rr.target_org_role AS routing_target_org_role,rr.routed_at
             FROM released_distribution_outputs rdo
             LEFT JOIN role_distribution_briefings b
               ON b.role_distribution_briefing_id=rdo.role_distribution_briefing_id
             LEFT JOIN distribution_routing_records rr
               ON rr.distribution_routing_record_id=rdo.distribution_routing_record_id
             ORDER BY rdo.released_distribution_output_id"
        );
        foreach ($distributed as $row) {
            if ($row['output_kind'] === 'ROLE_DISTRIBUTION_BRIEFING') {
                if ($row['role_distribution_briefing_id'] === null
                    || $row['target_org_role'] === null
                    || $row['briefing_summary_safe'] === null) {
                    return Outcome::refuse('RETAINED_ROLE_DISTRIBUTION_INVALID', [
                        'released_distribution_output_id' => $row['released_distribution_output_id'],
                    ]);
                }
                $model = [
                    'kind' => 'ROLE_DISTRIBUTION_BRIEFING',
                    'released_distribution_output_id' => (string) $row['released_distribution_output_id'],
                    'role_distribution_briefing_id' => (string) $row['role_distribution_briefing_id'],
                    'target_org_role' => (string) $row['target_org_role'],
                    'briefing_summary_safe' => (string) $row['briefing_summary_safe'],
                    'briefing_status' => (string) $row['briefing_status'],
                    'decision_record_id' => (string) $row['decision_record_id'],
                    'released_at' => (string) $row['released_at'],
                ];
            } elseif ($row['output_kind'] === 'DISTRIBUTION_ROUTING_RECORD') {
                if ($row['distribution_routing_record_id'] === null || $row['routing_target_org_role'] === null) {
                    return Outcome::refuse('RETAINED_ROUTING_OUTPUT_INVALID', [
                        'released_distribution_output_id' => $row['released_distribution_output_id'],
                    ]);
                }
                $model = [
                    'kind' => 'DISTRIBUTION_ROUTING_RECORD',
                    'released_distribution_output_id' => (string) $row['released_distribution_output_id'],
                    'distribution_routing_record_id' => (string) $row['distribution_routing_record_id'],
                    'target_org_role' => (string) $row['routing_target_org_role'],
                    'decision_record_id' => (string) $row['decision_record_id'],
                    'routed_at' => (string) $row['routed_at'],
                    'released_at' => (string) $row['released_at'],
                ];
            } else {
                return Outcome::refuse('RETAINED_DISTRIBUTION_OUTPUT_KIND_INVALID', [
                    'released_distribution_output_id' => $row['released_distribution_output_id'],
                ]);
            }
            try {
                json_encode($model, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable) {
                return Outcome::refuse('RETAINED_OUTPUT_NOT_RENDERABLE', [
                    'released_distribution_output_id' => $row['released_distribution_output_id'],
                ]);
            }
            $rendered[] = $model;
        }

        usort($rendered, static function (array $a, array $b): int {
            return strcmp(Digest::canonicalDigest($a), Digest::canonicalDigest($b));
        });

        return Outcome::ok([
            'count' => count($rendered),
            'digest' => Digest::canonicalDigest($rendered),
        ]);
    }

    private function copyReferencedArtifacts(string $packageRoot): Outcome
    {
        $resolved = $this->referencedArtifactDigests();
        if (!$resolved->isOk()) {
            return $resolved;
        }
        $digests = $resolved->value();
        $store = new EvidenceStore($this->root . '/storage/evidence');
        $out = [];

        $sourceRoot = realpath($this->root . '/storage/evidence');
        if ($digests !== [] && ($sourceRoot === false || !is_dir($sourceRoot) || is_link($this->root . '/storage/evidence'))) {
            return Outcome::refuse('BACKUP_EVIDENCE_ROOT_INVALID');
        }

        foreach ($digests as $digest) {
            $sourcePath = sprintf(
                '%s/sha256/%s/%s/%s',
                rtrim((string) $sourceRoot, DIRECTORY_SEPARATOR),
                substr($digest, 0, 2),
                substr($digest, 2, 2),
                $digest,
            );
            $sourceReal = realpath($sourcePath);
            if ($sourceReal === false || is_link($sourcePath)
                || !str_starts_with($sourceReal, (string) $sourceRoot . DIRECTORY_SEPARATOR)) {
                return Outcome::refuse('BACKUP_REFERENCED_ARTIFACT_PATH_INVALID', ['digest' => $digest]);
            }
            $read = $store->get($digest);
            if (!$read->isOk()) {
                return Outcome::refuse('BACKUP_REFERENCED_ARTIFACT_UNAVAILABLE', ['digest' => $digest]);
            }
            $bytes = (string) $read->value();
            $path = self::artifactPath($packageRoot, $digest);
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return Outcome::failure('BACKUP_ARTIFACT_DIR_CREATE_FAILED');
            }
            if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
                return Outcome::failure('BACKUP_ARTIFACT_COPY_FAILED', ['digest' => $digest]);
            }
            @chmod($path, 0600);
            $out[] = ['digest' => $digest, 'size' => strlen($bytes)];
        }

        return Outcome::ok($out);
    }

    /** @return Outcome<list<string>> */
    private function referencedArtifactDigests(): Outcome
    {
        $set = [];
        $invalid = false;
        $add = static function (mixed $value) use (&$set, &$invalid): void {
            if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
                $invalid = true;
                return;
            }
            $set[$value] = true;
        };

        foreach ($this->db->all("SELECT storage_reference_safe FROM raw_artifacts WHERE storage_reference_safe LIKE 'sha256:%'") as $row) {
            $ref = (string) $row['storage_reference_safe'];
            if (preg_match('/^sha256:([a-f0-9]{64})$/D', $ref, $match) !== 1) {
                $invalid = true;
                continue;
            }
            $add($match[1]);
        }
        foreach ([
            ['SELECT content_hash AS digest FROM evidence_objects WHERE evidence_type=?', ['UPLOADED_SOURCE_CAPTURE']],
            ['SELECT payload_digest AS digest FROM monitor_capture_payloads', []],
            ['SELECT artifact_digest AS digest FROM provider_model_candidate_artifacts', []],
            // P05 can crash after the inspected request artifact is persisted but before the run
            // cursor advances. In that state this binding is the ONLY durable reference needed to
            // reconstruct the exact request/result relationship during reconciliation.
            ['SELECT request_artifact_digest AS digest FROM monitor_model_attempt_bindings', []],
            ['SELECT candidate_artifact_digest AS digest FROM monitor_evaluation_results WHERE candidate_artifact_digest IS NOT NULL', []],
        ] as [$sql, $params]) {
            foreach ($this->db->all($sql, $params) as $row) {
                $add($row['digest'] ?? null);
            }
        }

        $walk = static function (mixed $value, ?string $key = null) use (&$walk, $add): void {
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk($child, is_string($childKey) ? $childKey : null);
                }
                return;
            }
            if ($key !== null && str_ends_with($key, '_artifact')) {
                $add($value);
            }
        };
        foreach ($this->db->all('SELECT cursor_json FROM monitor_runs') as $row) {
            try {
                $walk(json_decode((string) $row['cursor_json'], true, 128, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                return Outcome::refuse('BACKUP_MONITOR_ARTIFACT_REFERENCE_INVALID');
            }
        }
        foreach ($this->db->all('SELECT payload_json FROM monitor_run_events') as $row) {
            try {
                $walk(json_decode((string) $row['payload_json'], true, 128, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                return Outcome::refuse('BACKUP_MONITOR_ARTIFACT_REFERENCE_INVALID');
            }
        }
        if ($invalid) {
            return Outcome::refuse('BACKUP_ARTIFACT_REFERENCE_INVALID');
        }

        $digests = array_keys($set);
        sort($digests, SORT_STRING);

        return Outcome::ok($digests);
    }

    private function admittedGrantCount(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->db->all(
            'SELECT g.execution_grant_id,g.valid_from,g.valid_until,
                    (SELECT e.event_kind FROM monitor_execution_grant_events e
                     WHERE e.execution_grant_id=g.execution_grant_id
                     ORDER BY e.sequence_no DESC LIMIT 1) AS event_kind
             FROM monitor_execution_grants g'
        ) as $grant) {
            // A future-dated grant whose last lifecycle event is ADMITTED is not active
            // YET, but it would become active later without any new owner act. P10 therefore treats
            // both current and future admitted windows as execution-capable state that must be
            // suspended/revoked before backup. Expired admitted history cannot reactivate.
            if (($grant['event_kind'] ?? null) === 'ADMITTED'
                && $now < (string) $grant['valid_until']) {
                $count++;
            }
        }

        return $count;
    }

    private function copyRequestLogPrefix(string $packageRoot): Outcome
    {
        $source = $this->root . '/storage/request.log';
        if (is_link($source)) {
            return Outcome::refuse('BACKUP_REQUEST_LOG_SYMLINK_REFUSED');
        }
        if (!is_file($source)) {
            return Outcome::ok(null);
        }

        $input = @fopen($source, 'rb');
        if ($input === false || !@flock($input, LOCK_SH)) {
            if (is_resource($input)) fclose($input);
            return Outcome::failure('BACKUP_REQUEST_LOG_UNREADABLE');
        }
        try {
            // RequestLog appends with LOCK_EX. Holding LOCK_SH while taking fstat and copying means
            // this is an exact append-only prefix, never a line observed halfway through a writer.
            $stat = fstat($input);
            $size = is_array($stat) ? ($stat['size'] ?? null) : null;
            if (!is_int($size) || $size < 0) {
                return Outcome::failure('BACKUP_REQUEST_LOG_UNREADABLE');
            }

            $outputPath = $packageRoot . DIRECTORY_SEPARATOR . self::REQUEST_LOG_PREFIX;
            $output = @fopen($outputPath, 'wb');
            if ($output === false) {
                return Outcome::failure('BACKUP_REQUEST_LOG_COPY_FAILED');
            }
            try {
                $remaining = $size;
                $hash = hash_init('sha256');
                while ($remaining > 0) {
                    $chunk = fread($input, min(65536, $remaining));
                    if ($chunk === false || $chunk === '') {
                        @unlink($outputPath);
                        return Outcome::failure('BACKUP_REQUEST_LOG_COPY_FAILED');
                    }
                    $remaining -= strlen($chunk);
                    hash_update($hash, $chunk);
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        @unlink($outputPath);
                        return Outcome::failure('BACKUP_REQUEST_LOG_COPY_FAILED');
                    }
                }
                fflush($output);
            } finally {
                fclose($output);
            }
            @chmod($outputPath, 0600);

            return Outcome::ok([
                'path' => self::REQUEST_LOG_PREFIX,
                'size' => $size,
                'sha256' => hash_final($hash),
            ]);
        } finally {
            @flock($input, LOCK_UN);
            fclose($input);
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private static function artifactRelativePath(string $digest): string
    {
        return sprintf('evidence/sha256/%s/%s/%s', substr($digest, 0, 2), substr($digest, 2, 2), $digest);
    }

    private static function artifactPath(string $root, string $digest): string
    {
        return sprintf(
            '%s/evidence/sha256/%s/%s/%s',
            rtrim($root, DIRECTORY_SEPARATOR),
            substr($digest, 0, 2),
            substr($digest, 2, 2),
            $digest,
        );
    }
}
