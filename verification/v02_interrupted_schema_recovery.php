<?php

declare(strict_types=1);

/** V02 source-frozen disposable DDL interruption, FK and index audit. */
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Ops\{HistorySchemaPlan,HistorySchemaFixtureInstaller};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$name=(string)$db->scalar('SELECT DATABASE()');
$version=(string)$db->scalar('SELECT VERSION()');
Assert::same('allfather_test',$name,'V02 only uses the disposable test database');
Assert::true(str_starts_with($version,(string)getenv('V02_DB_MAJOR').'.'),
    'engine version is verified from live MariaDB');
if($name!=='allfather_test'
    || !str_starts_with($version,(string)getenv('V02_DB_MAJOR').'.'))return;
if(!defined('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST')){
    define('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST',true);
}
$plan=HistorySchemaPlan::inspect($root);
Assert::true($plan->isOk(),'source-locked physical DDL plan exists');
if(!$plan->isOk())return;
$p=$plan->value();
$clock=Harness::clock('2026-10-30T12:10:00.000Z');
$scope=Seed::tenantWorkspace($db,$clock,'v02-interruption');
$chain=Seed::governedReleaseChain($db,$clock,$scope);
Assert::true(($chain['release']??null)!==null,'native governed synthetic release exists');
$baseline=$db->all('SELECT * FROM decision_records ORDER BY decision_record_id');
Assert::true($baseline!==[],'pre-existing M10 data exists for preservation');
$old900=(string)$db->scalar(
    'SELECT content_digest FROM schema_migrations WHERE migration_id=?',
    ['900_deferred_foreign_keys']);
$all=[];
foreach($p['families'] as $family){
    foreach($family['table_names'] as $table)$all[$table]=true;
}
$firstFamily=$p['families'][0];
$firstTable='p01_observation_assessments';
$statements=Migrator::splitStatements((string)file_get_contents(
    $root.'/'.$firstFamily['source_path']));

$shape=static function(Database $db,array $names):array{
    $out=['fk'=>[],'index'=>[],'triggers'=>[]];
    $fk=$db->all("SELECT table_name,constraint_name FROM information_schema.table_constraints
        WHERE table_schema=DATABASE() AND constraint_type='FOREIGN KEY'");
    foreach($fk as $r){
        if(isset($names[$r['table_name']])){
            $out['fk'][]=$r['table_name'].':'.$r['constraint_name'];
        }
    }
    $ix=$db->all("SELECT table_name,index_name,seq_in_index,column_name,non_unique
        FROM information_schema.statistics WHERE table_schema=DATABASE()");
    foreach($ix as $r){
        if(isset($names[$r['table_name']])){
            $out['index'][]=implode(':',$r);
        }
    }
    $tr=$db->all("SELECT event_object_table,trigger_name,action_timing,event_manipulation
        FROM information_schema.triggers WHERE trigger_schema=DATABASE()");
    foreach($tr as $r){
        if(isset($names[$r['event_object_table']])){
            $out['triggers'][]=implode(':',$r);
        }
    }
    foreach($out as &$rows)sort($rows,SORT_STRING);
    unset($rows);
    return $out;
};
try{
    Assert::same('READY_EMPTY_DISPOSABLE_TEST_DATABASE',
        HistorySchemaFixtureInstaller::preflight($db,$root,$p)->value()['status'],
        'no candidate DDL is pre-installed');
    $db->pdo()->exec($statements[0]);
    Assert::same('HOLD_PARTIAL_NONTRANSACTIONAL_DDL',
        HistorySchemaFixtureInstaller::preflight($db,$root,$p)->value()['status'],
        'one-table interrupted DDL refuses');
    Assert::same('RT01_DISPOSABLE_INSTALL_REQUIRES_EMPTY_SCHEMA',
        HistorySchemaFixtureInstaller::install($db,$root,$p)->reasonCode(),
        'candidate installer never resumes partially committed DDL');
    Assert::same($baseline,$db->all(
        'SELECT * FROM decision_records ORDER BY decision_record_id'),
        'preexisting native decisions survive the interruption');
    $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($firstTable));
    Assert::same('READY_EMPTY_DISPOSABLE_TEST_DATABASE',
        HistorySchemaFixtureInstaller::preflight($db,$root,$p)->value()['status'],
        'manual cleanup only in disposable test DB restores the empty state');

    $forged=$p;$forged['families'][0]['source_sha256']=str_repeat('0',64);
    Assert::same('RT01_RUNTIME_OR_PLAN_NOT_DISPOSABLE',
        HistorySchemaFixtureInstaller::install($db,$root,$forged)->reasonCode(),
        'a tampered candidate source digest cannot execute');

    $installed=HistorySchemaFixtureInstaller::install($db,$root,$p);
    Assert::true($installed->isOk(),'full candidate DDL rebuild after clean restart');
    if(!$installed->isOk())return;
    $expected=$shape($db,$all);
    Assert::same(76,count($expected['triggers']),
        'every candidate table has both append-only triggers');
    Assert::true(count($expected['fk'])>35,
        'candidate DDL contains the expected substantial FK graph');
    Assert::true(count($expected['index'])>60,
        'indexes are actually present in information_schema');

    $trigger=$firstFamily['trigger_names'][0];
    $db->pdo()->exec('DROP TRIGGER '.Database::identifier($trigger));
    Assert::same('HOLD_PARTIAL_NONTRANSACTIONAL_DDL',
        HistorySchemaFixtureInstaller::preflight($db,$root,$p)->value()['status'],
        'a missing immutable trigger is a partial-DDL HOLD');
    Assert::same('RT01_DISPOSABLE_INSTALL_REQUIRES_EMPTY_SCHEMA',
        HistorySchemaFixtureInstaller::install($db,$root,$p)->reasonCode(),
        'partial trigger state cannot auto-repair itself');
    $triggerSql=null;
    foreach($statements as $statement){
        if(preg_match('/CREATE\s+TRIGGER\s+[\x60]?'.preg_quote($trigger,'/').'[\x60]?\b/i',
            $statement)===1){
            $triggerSql=$statement;break;
        }
    }
    Assert::true(is_string($triggerSql),'original trigger SQL is available from source');
    if(!is_string($triggerSql))return;
    $db->pdo()->exec($triggerSql);
    Assert::same($expected,$shape($db,$all),
        'restored trigger matches exact pre-interruption physical state');

    // Detect an unauthorized index without rewriting the native registry.
    $db->pdo()->exec('ALTER TABLE '.Database::identifier($firstTable).
        ' ADD INDEX v02_probe_ix (p01_observation_assessment_id)');
    Assert::notSame($expected['index'],$shape($db,$all)['index'],
        'extra index is detected as a schema parity violation');
    $db->pdo()->exec('ALTER TABLE '.Database::identifier($firstTable).
        ' DROP INDEX v02_probe_ix');
    Assert::same($expected,$shape($db,$all),
        'index correction on disposable DB restores exact parity');

    // A dropped FK must be caught by the independent V02 audit even
    // though RT01's TABLE/TRIGGER preflight asks for additional verification.
    $fkName='fk_ob02_as_tenant';
    Assert::true(in_array($firstTable.':'.$fkName,$expected['fk'],true),
        'frozen DDL includes tenant FK before interruption');
    $db->pdo()->exec('ALTER TABLE '.Database::identifier($firstTable).
        ' DROP FOREIGN KEY '.Database::identifier($fkName));
    $actual=$shape($db,$all);
    Assert::same(count($expected['fk'])-1,count($actual['fk']),
        'missing tenant foreign key is counted exactly');
    Assert::notSame($expected['fk'],$actual['fk'],
        'missing FK cannot count as exact candidate schema integrity');
    Assert::same('COMPLETE_DISPOSABLE_SCHEMA_PRESENT_REVERIFY_CONTENT',
        HistorySchemaFixtureInstaller::preflight($db,$root,$p)->value()['status'],
        'coarse RT01 preflight explicitly demands full FK/index verification');
    $db->pdo()->exec('ALTER TABLE '.Database::identifier($firstTable).
        ' ADD CONSTRAINT '.Database::identifier($fkName).
        ' FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)');
    Assert::same($expected,$shape($db,$all),
        'disposable manual FK repair restores original graph');

    Assert::same($baseline,$db->all(
        'SELECT * FROM decision_records ORDER BY decision_record_id'),
        'native M10 data survives trigger/index/FK corruption and recovery');
    Assert::same(31,(int)$db->scalar('SELECT COUNT(*) FROM schema_migrations'),
        'candidate DDL never updates the native migration ledger');
    Assert::same($old900,(string)$db->scalar(
        'SELECT content_digest FROM schema_migrations WHERE migration_id=?',
        ['900_deferred_foreign_keys']),
        'legacy migration 900 digest remains identical');
}finally{
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach(array_reverse($p['families']) as $family){
        foreach(array_reverse($family['table_names']) as $table){
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        }
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
}
Assert::same($baseline,$db->all(
    'SELECT * FROM decision_records ORDER BY decision_record_id'),
    'manual candidate cleanup leaves native governed records unchanged');
