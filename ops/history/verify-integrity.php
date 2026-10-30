<?php

declare(strict_types=1);

/** VA-03 source-only candidate integrity preview. No restore or credential act. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Ops\{
    BackupManifest,HistoryRecoveryManifest,HistoryRecoveryPreflight,
};

$args=array_slice($argv,1);
if(count($args)!==1
    || !in_array($args[0],['--help','--status','--synthetic-development'],true)){
    fwrite(STDERR,"VA03_NO_RESTORE_OWNER_PERMISSION\n");
    exit(3);
}
if($args[0]==='--help'||$args[0]==='--status'){
    echo json_encode([
        'version'=>HistoryRecoveryManifest::VERSION,
        'preflight_version'=>HistoryRecoveryPreflight::VERSION,
        'status'=>'NO_RESTORE_NOT_HOST_VERIFIED',
        'candidate_family_count'=>count(HistoryRecoveryManifest::FAMILIES),
        'real_database_dump_read'=>false,
        'private_m05_bytes_read'=>false,
        'volume_restore_executed'=>false,
        'host_backup_pair_verified'=>false,
        'age_p11_sql_backup_key_cutovers'=>false,
        'customer_or_runtime_authority'=>false,
        'synthetic_requires'=>'ALLFATHER_VA03_SYNTHETIC_TEST=1',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if(getenv('ALLFATHER_VA03_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"VA03_SYNTHETIC_FIXTURE_REQUIRED\n");
    exit(3);
}
try{
    require_once dirname(__DIR__,2).'/tests/support/Va03MockRecovery.php';
    $root=dirname(__DIR__,2);
    $m=\Allfather\Loopia\Tests\Va03MockRecovery::manifest($root);
    if(!$m->isOk())throw new \DomainException('NO_FIXTURE_MANIFEST');
    $verified=BackupManifest::inspectUnadoptedHistoryIndex($m->value(),$root);
    if(!$verified->isOk())throw new \DomainException('FAMILY_DIGEST_MISMATCH');
    $input=\Allfather\Loopia\Tests\Va03MockRecovery::preflight($m->value());
    $review=HistoryRecoveryPreflight::inspect($input);
    if(!$review->isOk())throw new \DomainException('PREFLIGHT_REFUSED');
    echo json_encode([
        'version'=>HistoryRecoveryManifest::VERSION,
        'status'=>$review->value()['status'],
        'manifest_digest'=>$m->value()['manifest_digest'],
        'preflight_digest'=>$review->value()['preflight_digest'],
        'proposed_schema_file_hashes_verified'=>true,
        'family_count'=>$m->value()['schema_family_count'],
        'private_artifact_count'=>$m->value()['private_artifact_count'],
        'source_image_owner_verified'=>false,
        'private_m05_bytes_authenticated'=>false,
        'real_p10_r5_restore_proved'=>false,
        'p11_sql_account_rotated'=>false,
        'external_or_customer_actions'=>0,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"VA03_FIXED_SOURCE_PREVIEW_UNAVAILABLE\n");
    exit(4);
}
