<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/Va03MockRecovery.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Ops\{
    HistoryRecoveryManifest,HistoryRecoveryPreflight,HistoryCredentialCutover,
};
use Allfather\Loopia\Tests\{Assert,Va03MockRecovery};

$root=dirname(__DIR__,2);
$sourcePaths=[
    'app/Ops/HistoryRecoveryManifest.php',
    'app/Ops/HistoryRecoveryPreflight.php',
    'app/Ops/HistoryCredentialCutover.php',
    'ops/history/verify-integrity.php',
];
foreach($sourcePaths as $path){
    $source=(string)file_get_contents($root.'/'.$path);
    foreach([
        'curl_exec(', 'new ModelExecutor(', 'mail(',
        'new SchedulerRunner(', 'new SourceCaptureStage(',
        'new DecisionLedger(', 'releaseP01(', 'releaseRoleDistribution(',
        'DELETE FROM ', 'ALTER USER ', 'shell_exec(', 'passthru(',
        'proc_open(',
    ] as $needle){
        Assert::false(str_contains($source,$needle),
            'candidate/preview cannot restore, rotate, dispatch or release: '.$path);
    }
}
$m=Va03MockRecovery::manifest($root);
Assert::true($m->isOk(),'fixed synthetic index is source-bound');
if(!$m->isOk())return;
$manifest=$m->value();
$serialized=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
Assert::true(strlen($serialized)<32768,
    'public candidate index must be bounded and contain no private byte stream');
foreach([
    'APP_MASTER_KEY','DB_PASSWORD','SCHEDULER_TOKEN',
    'BEGIN PRIVATE KEY','api_key','credential_plaintext',
    'VA03-SYNTHETIC-M05-BYTES-NOT-A-REAL-CAPTURE',
    'VA03-SYNTHETIC-ANALYST-ARTIFACT',
] as $secret){
    Assert::false(str_contains($serialized,$secret),
        'the index contains digests rather than secret/source bytes');
}
// The full runner shares one PHP process across integration/security layers;
 // in isolation the marker is absent, after integration it is deliberately
 // present. Prove the exact expected refusal in BOTH legal test orders.
$privateRead=HistoryRecoveryManifest::verifyPrivateFixtureBytes(
    $manifest,$root.'/public_html',
);
$marked=defined('ALLFATHER_VA03_PRIVATE_FIXTURE_TEST')
    && constant('ALLFATHER_VA03_PRIVATE_FIXTURE_TEST')===true;
$refusal=$marked?'VA03_PRIVATE_FIXTURE_ROOT_INVALID'
    :'VA03_PRIVATE_READ_RUNTIME_NOT_ADOPTED';
Assert::same($refusal,$privateRead->reasonCode(),
    'web-served public_html cannot be a private blob root, even with fixture marker');
Assert::true(str_contains(
    (string)file_get_contents($root.'/app/Ops/HistoryRecoveryManifest.php'),
    'ALLFATHER_VA03_PRIVATE_FIXTURE_TEST'),
    'fixture-only private byte verification cannot be called by ordinary runtime');
$bad=$manifest;
$bad['private_artifacts'][0]['embedded_bytes']=true;
$copy=$bad;unset($copy['manifest_digest']);
$bad['manifest_digest']=Digest::canonicalDigest($copy);
Assert::same('VA03_RECOVERY_MANIFEST_REHASH_OR_AUTHORITY_FORGED',
    HistoryRecoveryManifest::verify($bad)->reasonCode(),
    'rehashed manifest with embedded payload cannot authorize disclosure');
$bad=Va03MockRecovery::preflight($manifest);
$bad['r5_backup_pair']['same_checkpoint_proven']=true;
Assert::same('VA03_R5_INVENTORY_NOT_RESTORE_EVIDENCE',
    HistoryRecoveryPreflight::inspect($bad)->reasonCode(),
    'two R5 provider-listed snapshots cannot forge a same-cut backup pair');
$bad=Va03MockRecovery::preflight($manifest);
$bad['credential_readiness']['p11_rewrap_verified']=true;
Assert::same('VA03_RECOVERY_CREDENTIAL_CUTOVERS_NOT_PROVEN',
    HistoryRecoveryPreflight::inspect($bad)->reasonCode(),
    'self-asserted P11 key rewrap is not a real cutover');
$bad=Va03MockRecovery::preflight($manifest);
$bad['recovery_evidence'][0]['private_bytes_verified']=true;
Assert::same('VA03_RESTORE_EVIDENCE_CANNOT_BE_INVENTED',
    HistoryRecoveryPreflight::inspect($bad)->reasonCode(),
    'existing-live source proof cannot be asserted from a fixture');
Assert::same('VA03_RESTORE_REQUIRES_SEPARATE_P10_P11_OWNER_APPROVAL',
    HistoryRecoveryPreflight::requestRestore(['host_backup_verified'=>true])->reasonCode(),
    'unsupported invocation never reaches Railway Restore or P10 import');
