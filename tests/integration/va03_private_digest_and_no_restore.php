<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/Va03MockRecovery.php';

use Allfather\Loopia\Ops\{
    BackupManifest,HistoryRecoveryManifest,HistoryRecoveryPreflight,
};
use Allfather\Loopia\Tests\{Assert,Harness,Va03MockRecovery};

$root=dirname(__DIR__,2);
$m=Va03MockRecovery::manifest($root);
Assert::true($m->isOk(),'complete bounded source proposal recovery index');
if(!$m->isOk())return;
$manifest=$m->value();
Assert::true(BackupManifest::inspectUnadoptedHistoryIndex($manifest,$root)->isOk(),
    'real P10 extension source files validate without a database connection');
if(!defined('ALLFATHER_VA03_PRIVATE_FIXTURE_TEST')){
    define('ALLFATHER_VA03_PRIVATE_FIXTURE_TEST',true);
}
$private=sys_get_temp_dir().'/va03-disposable-'.bin2hex(random_bytes(9));
$created=mkdir($private,0700);
Assert::true($created,'unique private synthetic fixture directory created outside repo');
if(!$created)return;
chmod($private,0700);
try{
    foreach(Va03MockRecovery::bytes() as $bytes){
        $name=hash('sha256',$bytes);
        Assert::same(strlen($bytes),file_put_contents($private.'/'.$name,$bytes),
            'synthetic byte fixture is locally retained by its content hash');
        chmod($private.'/'.$name,0600);
    }
    $check=HistoryRecoveryManifest::verifyPrivateFixtureBytes($manifest,$private);
    Assert::true($check->isOk(),
        'all four private synthetic blob hashes/lengths match exact bytes');
    if($check->isOk()){
        Assert::same(false,$check->value()['actual_m05_owner_bytes_authenticated'],
            'positive test never claims actual retained M05 capture bytes');
        Assert::same(false,$check->value()['restore_proved'],
            'synthetic bytes are not physical or logical recovery proof');
    }
    $first=array_values(Va03MockRecovery::bytes())[0];
    $file=$private.'/'.hash('sha256',$first);
    file_put_contents($file,$first.'TAMPER');
    Assert::same('VA03_PRIVATE_BLOB_DIGEST_OR_LENGTH_INVALID',
        HistoryRecoveryManifest::verifyPrivateFixtureBytes($manifest,$private)->reasonCode(),
        'modified retained private blob must fail even though the manifest is unchanged');
    file_put_contents($file,$first);
    chmod($file,0600);
    unlink($file);
    Assert::same('VA03_PRIVATE_REFERENCED_BLOB_MISSING',
        HistoryRecoveryManifest::verifyPrivateFixtureBytes($manifest,$private)->reasonCode(),
        'missing M05 blob fails, not silently marked empty');
}finally{
    foreach(glob($private.'/*')?:[] as $file){
        if(is_file($file) && !is_link($file))unlink($file);
    }
    rmdir($private);
}

// Disposable real MariaDB: no native M10/M11 or P10 changes.
$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$before=[];
foreach(['decision_records','incident_cases','audit_runs','release_artifacts',
         'schema_migrations'] as $table){
    $before[$table]=(int)$db->scalar('SELECT COUNT(*) FROM '.$table);
}
$pre=HistoryRecoveryPreflight::inspect(Va03MockRecovery::preflight($manifest));
Assert::true($pre->isOk(),'metadata-only host check runs against a disposable context');
if($pre->isOk()){
    Assert::same(false,$pre->value()['restore_executed'],
        'no import/restore performed against disposable DB');
}
foreach($before as $table=>$count){
    Assert::same($count,(int)$db->scalar('SELECT COUNT(*) FROM '.$table),
        'no M10/M11/migration mutation from VA03 inspection: '.$table);
}
