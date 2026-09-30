<?php
declare(strict_types=1);

/**
 * P10 backup package builder/verifier and clean-target restore verifier.
 *
 * Native dump/import stays in the shell scripts. This command understands the governed identities
 * around those bytes and emits no secret values.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\{Clock,Outcome};
use Allfather\Loopia\Ops\{BackupManifest,BetaCli};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'backup-verify: ' . $message . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
$args = BetaCli::parseArgs(
    [$argv[0], ...array_slice($argv, 2)],
    ['env','dump','package-dir','storage-root','database','operator-id'],
    $fail,
);
$need = static function (string $name) use ($args, $fail): string {
    $value = $args[$name] ?? '';
    if ($value === '' || $value === '1') {
        $fail("--{$name} is required");
    }
    return $value;
};
$root = dirname(__DIR__, 2);

if ($command === 'verify-package') {
    $result = BackupManifest::verifyPackage($need('package-dir'));
    BetaCli::emit(
        $result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()],
        $result->isOk() ? 0 : 1,
    );
}

$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail, true);
$expectedDatabase = $need('database');
if (preg_match('/^[A-Za-z0-9_]+$/D', $expectedDatabase) !== 1
    || (string) $db->scalar('SELECT DATABASE()') !== $expectedDatabase) {
    $fail('--database must exactly match DB_NAME of --env', 4);
}
$manifest = new BackupManifest($db, new Clock(), $root, $config['env']->get('APP_RELEASE_REF'));

$result = match ($command) {
    'database-identity' => Outcome::ok([
        'database_identity_sha256' => BackupManifest::databaseIdentity($db),
        'database_principal_sha256' => BackupManifest::databasePrincipalIdentity($db),
    ]),
    'build-manifest' => $manifest->build($need('dump'), $need('package-dir')),
    'check-target' => (static function () use ($db, $need, $manifest, $config): Outcome {
        if (!in_array($config['environment'], ['development', 'testing'], true)) {
            return Outcome::refuse('RESTORE_REHEARSAL_TARGET_ENVIRONMENT_REQUIRED');
        }
        $loaded = BackupManifest::verifyPackage($need('package-dir'));
        if (!$loaded->isOk()) {
            return $loaded;
        }
        $manifestValue = $loaded->value()['manifest'];
        $operatorId = $need('operator-id');
        if (preg_match('/^[a-f0-9-]{36}$/D', $operatorId) !== 1
            || !hash_equals((string) ($manifestValue['maintenance']['operator_id'] ?? ''), $operatorId)) {
            return Outcome::refuse('RESTORE_OPERATOR_MISMATCH');
        }
        $runtime = $manifest->verifyRuntimeIdentity($manifestValue);
        if (!$runtime->isOk()) {
            return $runtime;
        }
        $source = (string) ($manifestValue['source']['database_identity_sha256'] ?? '');
        $target = BackupManifest::databaseIdentity($db);
        if (hash_equals($source, $target)) {
            return Outcome::refuse('RESTORE_TARGET_EQUALS_SOURCE');
        }
        $sourceLine = BackupManifest::serverLine((string) ($manifestValue['source']['server_version'] ?? ''));
        $targetLine = BackupManifest::serverLine($db->serverVersion());
        if ($sourceLine === null || $targetLine === null || !hash_equals($sourceLine, $targetLine)) {
            return Outcome::refuse('RESTORE_MARIADB_LINE_MISMATCH', [
                'source_line' => $sourceLine,
                'target_line' => $targetLine,
            ]);
        }
        if (($manifestValue['source']['trigger_definitions'] ?? []) !== []
            && !hash_equals(
                (string) ($manifestValue['source']['database_principal_sha256'] ?? ''),
                BackupManifest::databasePrincipalIdentity($db),
            )) {
            return Outcome::refuse('RESTORE_TRIGGER_DEFINER_PRINCIPAL_MISMATCH');
        }

        $objectCount = (int) $db->scalar(
            "SELECT
                (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()) +
                (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()) +
                (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE()) +
                (SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE())"
        );
        if ($objectCount !== 0) {
            return Outcome::refuse('RESTORE_TARGET_NOT_EMPTY', ['object_count' => $objectCount]);
        }

        return Outcome::ok([
            'database_identity_sha256' => $target,
            'database_principal_sha256' => BackupManifest::databasePrincipalIdentity($db),
            'different_from_source' => true,
            'empty_target' => true,
        ]);
    })(),
    'verify-database' => (static function () use ($manifest, $need, $config): Outcome {
        if (!in_array($config['environment'], ['development', 'testing'], true)) {
            return Outcome::refuse('RESTORE_REHEARSAL_TARGET_ENVIRONMENT_REQUIRED');
        }
        $loaded = BackupManifest::verifyPackage($need('package-dir'));
        if (!$loaded->isOk()) {
            return $loaded;
        }
        $manifestValue = $loaded->value()['manifest'];
        $operatorId = $need('operator-id');
        if (preg_match('/^[a-f0-9-]{36}$/D', $operatorId) !== 1
            || !hash_equals((string) ($manifestValue['maintenance']['operator_id'] ?? ''), $operatorId)) {
            return Outcome::refuse('RESTORE_OPERATOR_MISMATCH');
        }

        return $manifest->verifyRestoredDatabase($manifestValue, $need('storage-root'));
    })(),
    default => $fail('usage: verify-restore.php <database-identity|build-manifest|verify-package|check-target|verify-database> [--env=... --database=... --dump=... --package-dir=... --storage-root=... --operator-id=...]'),
};

BetaCli::emit(
    $result->isOk()
        ? ['ok' => true, 'result' => $result->value()]
        : ['ok' => false, 'reason_code' => $result->reasonCode(), 'detail' => $result->detail],
    $result->isOk() ? 0 : 1,
);
