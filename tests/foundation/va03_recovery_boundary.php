<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\{
    BackupManifest,HistoryRecoveryManifest,HistoryRecoveryPreflight,
    HistoryCredentialCutover,
};
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json',
),true,64,JSON_THROW_ON_ERROR);
$items=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='VA-03'));
Assert::same(1,count($items),'VA03 one frozen package');
Assert::same('BUILT_INERT',$items[0]['build_status'],
    'P10/P11 recovery candidate does not grant actual host restore');
foreach([
    'native_permission','customer_visibility','production_activation',
    'live_migration','external_effects',
] as $key){
    Assert::same(false,$items[0][$key],
        'VA03 cannot grant real operator/runtime capability: '.$key);
}
Assert::same(1,BackupManifest::VERSION,'existing P10 v1 format is not rewritten');
Assert::same('va03.full-restore-manifest.vNext-candidate',
    HistoryRecoveryManifest::VERSION,
    'candidate extension carries a separate closed version');
Assert::same(12,count(HistoryRecoveryManifest::FAMILIES),
    'all 260/265 and 270–350 families included');
Assert::same('va03.readonly-recovery-preflight.v1',
    HistoryRecoveryPreflight::VERSION,
    'provider read-only inventory is not a restore command');
Assert::same('va03.separate-credential-cutovers.v1',
    HistoryCredentialCutover::VERSION,
    'P11, SQL-user and backup-access cutovers stay distinct');
foreach([
    'app/Ops/HistoryRecoveryManifest.php',
    'app/Ops/HistoryRecoveryPreflight.php',
    'app/Ops/HistoryCredentialCutover.php',
    'docs/HISTORY_BACKUP_RESTORE_RUNBOOK.md',
    'ops/history/verify-integrity.php',
] as $p){
    Assert::true(is_file($root.'/'.$p),'VA03 safe source file exists: '.$p);
}
$cli=(string)file_get_contents($root.'/ops/history/verify-integrity.php');
Assert::true(str_contains($cli,"PHP_SAPI !== 'cli'"),
    'web calls are refused before any bootstrap');
Assert::true(strpos($cli,"PHP_SAPI !== 'cli'")<strpos($cli,'autoload.php'),
    'the CLI guard precedes app autoload and secret configuration');
Assert::false(is_file($root.'/database/migrations/360_full_history_recovery.sql'),
    'no shadow runtime schema installed by recovery source');
