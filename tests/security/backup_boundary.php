<?php
declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Ops\MaintenanceGate;
use Allfather\Loopia\Tests\Assert;

$root = sys_get_temp_dir() . '/allfather-maintenance-' . bin2hex(random_bytes(6));
mkdir($root . '/storage', 0750, true);
$gate = new MaintenanceGate($root);

$lease = $gate->acquireRuntimeLease();
Assert::true($lease->isOk(), 'ordinary runtime acquires a shared maintenance lease');
$blocked = $gate->enter(Ids::new(), 'testing', 'BACKUP', 0);
Assert::same('MAINTENANCE_DRAIN_TIMEOUT', $blocked->reasonCode(), 'maintenance cannot publish while an in-flight runtime lease exists');
$lease->value()['lease']->release();

$operator = Ids::new();
$entered = $gate->enter($operator, 'testing', 'BACKUP', 1);
Assert::true($entered->isOk(), 'maintenance enters after runtime drains');
$state = $gate->status();
Assert::same(true, $state['active'], 'maintenance marker is active');
Assert::same('QUIESCED', $state['state'], 'marker has the explicit quiesced state');
Assert::same($operator, $state['operator_id'], 'marker names the operator, not a secret');

$marker = (string) file_get_contents($root . '/storage/maintenance.json');
foreach (['DB_PASSWORD','APP_MASTER_KEY','SCHEDULER_TOKEN','provider_credential','password'] as $forbidden) {
    Assert::false(str_contains($marker, $forbidden), "maintenance marker contains no {$forbidden}");
}

$runtime = $gate->acquireRuntimeLease();
Assert::same('MAINTENANCE_ACTIVE', $runtime->reasonCode(), 'new runtime work is refused while maintenance is active');
Assert::same('MAINTENANCE_CHANGED_RELOAD_REQUIRED', $gate->leave(Ids::new(), $operator)->reasonCode(), 'a stale maintenance identity cannot reopen runtime');
$operation = $gate->beginOperation($entered->value()['maintenance_id'], $operator, 'BACKUP_EXPORT');
Assert::true($operation->isOk(), 'backup operation pins the exact maintenance window');
Assert::same('MAINTENANCE_OPERATION_ACTIVE', $gate->beginOperation($entered->value()['maintenance_id'], $operator, 'AUTHORITY_STAND_DOWN')->reasonCode(), 'authority stand-down and export operations cannot overlap');
Assert::same('MAINTENANCE_OPERATION_ACTIVE', $gate->leave($entered->value()['maintenance_id'], $operator)->reasonCode(), 'maintenance cannot leave while export operation is active');
Assert::same('MAINTENANCE_OPERATION_CHANGED_RELOAD_REQUIRED', $gate->endOperation(Ids::new(), $operator)->reasonCode(), 'stale operation id cannot release the pin');
Assert::true($gate->endOperation($operation->value()['operation_id'], $operator)->isOk(), 'exact backup operation can end');
Assert::true($gate->leave($entered->value()['maintenance_id'], $operator)->isOk(), 'the exact inspected maintenance window can leave');
Assert::same(false, $gate->status()['active'], 'runtime reopens only after marker removal');

file_put_contents($root . '/storage/maintenance.json', '{"corrupt":true}');
Assert::same('INVALID', $gate->status()['state'], 'a malformed marker fails closed');
Assert::same('MAINTENANCE_STATE_INVALID', $gate->acquireRuntimeLease()->reasonCode(), 'invalid maintenance state never opens runtime');
unlink($root . '/storage/maintenance.json');

if (@symlink($root . '/storage/does-not-exist', $root . '/storage/maintenance.json')) {
    Assert::same('INVALID', $gate->status()['state'], 'a dangling maintenance-marker symlink fails closed');
    @unlink($root . '/storage/maintenance.json');
}

// An operation marker without its maintenance marker is an impossible normal state. Runtime must
// fail closed rather than silently treating that stranded operation as OPEN.
$orphan = [
    'state_version' => MaintenanceGate::STATE_VERSION,
    'operation_id' => Ids::new(),
    'maintenance_id' => Ids::new(),
    'operator_id' => $operator,
    'kind' => 'BACKUP_EXPORT',
    'started_at' => '2026-09-29T00:00:00.000Z',
];
file_put_contents($root . '/storage/maintenance-operation.json', json_encode($orphan, JSON_THROW_ON_ERROR));
Assert::same('MAINTENANCE_OPERATION_ORPHANED', $gate->acquireRuntimeLease()->reasonCode(), 'orphaned backup operation fails runtime closed');
unlink($root . '/storage/maintenance-operation.json');

$repo = dirname(__DIR__, 2);
$index = (string) file_get_contents($repo . '/public_html/index.php');
$kernel = (string) file_get_contents($repo . '/app/Http/Kernel.php');
$scheduler = (string) file_get_contents($repo . '/public_html/scheduler.php');
$worker = (string) file_get_contents($repo . '/worker/tick.php');
Assert::true(strpos($index, 'acquireRuntimeLease()') < strpos($index, 'Bootstrap::load($root)'), 'web quiescence happens before Bootstrap/database/auth composition');
Assert::true(strpos($kernel, '$this->maintenance->status()') < strpos($kernel, '$this->resolveSession($request)'), 'direct Kernel maintenance check happens before session last_seen mutation');
Assert::contains('operationStatus()', $kernel, 'direct Kernel composition also fails closed on an orphaned maintenance operation');
Assert::contains('acquireRuntimeLease()', $scheduler, 'HTTP scheduler shares the maintenance gate');
Assert::contains('acquireRuntimeLease()', $worker, 'CLI scheduler shares the maintenance gate');

$export = (string) file_get_contents($repo . '/ops/backup/export.sh');
$restore = (string) file_get_contents($repo . '/ops/backup/restore-rehearsal.sh');
$verify = (string) file_get_contents($repo . '/ops/backup/verify-restore.php');
$betaCli = (string) file_get_contents($repo . '/app/Ops/BetaCli.php');
$standDown = (string) file_get_contents($repo . '/ops/monitor/stand-down.php');
$rollbackVerifier = (string) file_get_contents($repo . '/ops/rollback/verify-stand-down.php');
$manifestSource = (string) file_get_contents($repo . '/app/Ops/BackupManifest.php');
$probe = (string) file_get_contents($repo . '/ops/loopia-probe/probe.php');
foreach ([$export, $restore, $verify] as $source) {
    Assert::false(str_contains($source, 'MYSQL_PWD'), 'backup tooling never exports a database password environment variable');
    Assert::false(str_contains($source, '--password='), 'backup tooling never puts database passwords on argv');
}
Assert::contains('--defaults-extra-file', $export, 'native export reads credentials from a private client option file');
Assert::contains('env/client option files must be mode 0600 or 0400', $export, 'source env and native credentials both require private filesystem modes');
Assert::contains('identity/env/client files must be mode 0600 or 0400', $restore, 'restore identity, env and native credentials all require private filesystem modes');
Assert::contains('--single-transaction', $export, 'native dump requests a consistent transactional snapshot');
Assert::contains('archive_cleanup=1', $export, 'a failed encryption/digest step cannot leave a partial archive looking complete');
Assert::contains('encrypted archive must be written outside the release tree', $export, 'accepted backup output cannot remain inside the runtime release tree');
Assert::contains('--no-tablespaces', $export, 'native dump avoids unnecessary tablespace privilege on the shared-host path');
Assert::contains('--skip-add-locks', $export, 'native dump does not require restore-time table locks');
Assert::contains('begin-operation', $export, 'backup export pins maintenance before the native snapshot');
Assert::contains('end-operation', $export, 'backup export releases the operation pin on ordinary exit');
Assert::contains('output already exists or is a symlink', $export, 'encrypted output cannot follow a dangling symlink into a served path');
Assert::contains('database-identity', $export, 'export binds the native client to the same host/database identity as PHP configuration');
Assert::contains('native_principal', $export, 'export also binds the native client to the same authenticated database principal');
Assert::contains('native_identity', $restore, 'restore binds the native import client to the verified target identity');
Assert::contains('--expected-sha256=', $restore, 'restore requires the independently recorded encrypted-archive digest');
Assert::contains('encrypted archive sha256 does not match', $restore, 'restore refuses a substituted encrypted archive before decryption');
Assert::contains('native_principal', $restore, 'restore binds the native import principal before importing trigger definers');
Assert::contains('information_schema.routines', $restore, 'empty target check includes routines, not only tables');
Assert::contains('information_schema.events', $restore, 'empty target check includes events, not only tables');
Assert::contains('check-target', $restore, 'restore proves target identity differs before import');
Assert::contains('--operator-id=', $restore, 'restore requires the explicit operator identity bound into the backup');
Assert::contains('RESTORE_OPERATOR_MISMATCH', $verify, 'restore verifier rejects an operator other than the one who authorized the backup window');
Assert::contains('RESTORE_REHEARSAL_TARGET_ENVIRONMENT_REQUIRED', $verify, 'restore rehearsal refuses staging/production target configuration');
Assert::contains('RESTORE_TARGET_NOT_EMPTY', $verify, 'PHP target verification independently refuses any pre-existing database objects');
Assert::contains('target database is not empty', $restore, 'restore refuses a populated target');
Assert::contains('decrypted work dir may not be under public_html', $restore, 'plaintext restore work cannot be placed under a served directory');
Assert::contains("trap 'rm -rf \"\$WORK\"' EXIT", $restore, 'restore always deletes decrypted work, including an operator-selected work directory');
Assert::contains('RESTORE_MARIADB_LINE_MISMATCH', $manifestSource, 'restore is constrained to the source MariaDB major.minor line');
Assert::contains("grep -Ev '^[-d]'", $restore, 'restore rejects tar special-file entries before extraction');
Assert::contains('*/..', $restore, 'restore refuses an archive member ending in a parent-directory segment');
Assert::true(strpos($restore, 'check-target') < strpos($restore, ' < "$WORK/package/database.sql"'), 'target checks run before import');
Assert::true(strpos($verify, "PHP_SAPI !== 'cli'") < strpos($verify, "require __DIR__"), 'restore verifier refuses web execution before loading application code');
Assert::contains('acquireRuntimeLease()', $betaCli, 'ordinary BetaCli database commands hold the same quiescence lease');
Assert::contains("'AUTHORITY_STAND_DOWN'", $standDown, 'authority-narrowing stand-down serializes against backup export before writing');
Assert::contains('BetaCli::connect($env, $fail, true)', $rollbackVerifier, 'read-only stand-down verification remains available during maintenance');
Assert::contains('BACKUP_EXECUTION_GRANTS_STILL_ADMITTED', $manifestSource, 'backup refuses while execution authority is still admitted');
Assert::contains('BACKUP_REQUEST_LOG_SYMLINK_REFUSED', $manifestSource, 'backup never follows a request-log symlink');
Assert::contains('flock($input, LOCK_SH)', $manifestSource, 'request-log prefix is frozen under the same flock discipline as append writers');
Assert::contains('BACKUP_REFERENCED_ARTIFACT_PATH_INVALID', $manifestSource, 'backup never follows referenced artifact bytes outside the evidence root');
Assert::contains('monitor_model_attempt_bindings', $manifestSource, 'backup retains P05 request artifacts even when a crash prevented them reaching the run cursor');
Assert::contains('RESTORE_EXECUTION_GRANTS_ADMITTED', $manifestSource, 'restore verification refuses a database whose execution grants are still live');
Assert::contains('RESTORE_BACKUP_TOOLING_MISMATCH', $manifestSource, 'restore verifier binds itself to the exact P10 operator tooling bytes');
Assert::contains('BACKUP_CONSOLE_MANIFEST_INVALID', $manifestSource, 'backup validates the staged console manifest rather than hashing an arbitrary file');
Assert::contains('BACKUP_NON_TRANSACTIONAL_TABLES', $manifestSource, 'single-transaction backup refuses non-InnoDB application tables');
Assert::contains('database mutation refused by maintenance gate', $probe, 'destructive host capability probes cannot run through a backup window');

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.','..']) as $child) {
            $remove($path . DIRECTORY_SEPARATOR . $child);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
};
$remove($root);
