<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Ops\{BuildFreezeInspector,HistoryRecoveryPreflight};
use Allfather\Loopia\Workflow\{HistoryReviewFactory,HistoryReviewCommandCandidate};
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$r=BuildFreezeInspector::inspect($root);
Assert::true($r->isOk(),'source-only freeze is verifiable without runtime');
if(!$r->isOk())return;
$v=$r->value();
$manifest=json_decode((string)file_get_contents(
    $root.'/docs/BUILD_FREEZE_MANIFEST.json'),
    true,64,JSON_THROW_ON_ERROR);
Assert::same('BIND_WITH_HOSTED_TEST_MERGE_AND_POST_MERGE_RECEIPT',
    $manifest['source_anchor']['rt02_final_git_identity'],
    'no self-referential final Git SHA in tracked source');
Assert::same('NOT_INDEPENDENTLY_ATTESTED',$manifest['license_review'],
    'no fabricated third-party license audit');
Assert::same(false,$v['git_final_sha_or_tree_measured_here'],
    'PHP source cannot attest exact GitHub merge identity');
Assert::same(false,$v['public_archive_created_here'],
    'source audit does not invent downloadable archive artifacts');

foreach([
    'host_migrations_or_recovery_qualified',
    'provider_source_or_paid_model_authorized',
    'real_human_release_approved','customer_visibility_authorized',
] as $field){
    $bad=$v;$bad[$field]=true;
    $copy=$bad;unset($copy['inventory_digest']);
    $bad['inventory_digest']=Digest::canonicalDigest($copy);
    Assert::same('RT02_FREEZE_SOURCE_RECEIPT_FORGED_OR_STALE',
        BuildFreezeInspector::verify($root,$bad)->reasonCode(),
        'caller cannot self-hash a fake '.$field);
}
Assert::same(false,HistoryReviewFactory::forRuntime(['owner'=>true])->isOk(),
    'no generic flag grants a real historical inference provider');
Assert::same(false,HistoryReviewCommandCandidate::forRuntime()->isOk(),
    'real UI02 commands remain owner-adoption gated');
Assert::same(false,HistoryRecoveryPreflight::requestRestore(['allow'=>true])->isOk(),
    'fake RT02 recovery permission cannot invoke host restore');

foreach([
    'app/Ops/BuildFreezeInspector.php',
    'ops/history/shadow-report.php',
    'ops/road-test/export-history-review-evidence.php',
] as $path){
    $source=(string)file_get_contents($root.'/'.$path);
    foreach([
        'curl_exec(', 'new ModelExecutor(', 'new DecisionLedger(',
        'releaseP01(', 'mail(', '->send(', 'DELETE FROM',
        'CREATE USER', 'ALTER USER', 'railway volume restore',
        'APP_MASTER_KEY=', 'DB_PASSWORD=',
    ] as $code){
        Assert::false(str_contains($source,$code),
            'freeze and evidence CLI cannot execute or print secret: '.$path.'/'.$code);
    }
}
$packer=(string)file_get_contents($root.'/ops/history/package-source.sh');
foreach([
    'git push','git commit','curl ','wget ','docker ','railway ',
    'mysqldump ','mysql -','npm install','--force-with-lease',
] as $forbidden){
    Assert::false(str_contains($packer,$forbidden),
        'source archive cannot mutate external runtime: '.$forbidden);
}
foreach([
    'OUTPUT_ALREADY_EXISTS','OUTPUT_PARENT_NOT_OWNER_ONLY',
    'FORBIDDEN_TRACKED_SECRET_OR_BACKUP_PATH',
    'FORBIDDEN_TRACKED_RUNTIME_PATH',
    'SOURCE_ARCHIVE_REFUSED','git -C "$repo_root" archive',
] as $required){
    Assert::true(str_contains($packer,$required),
        'archive exact source/private path guard: '.$required);
}
$encoded=json_encode($v,JSON_THROW_ON_ERROR);
foreach([
    'BEGIN PRIVATE KEY','APP_MASTER_KEY_V1=','DB_PASSWORD=',
    'sk-test-real-key','raw_source_body','customer_email_address',
] as $secret){
    Assert::false(str_contains($encoded,$secret),
        'source-only digest inventory excludes sensitive material: '.$secret);
}
