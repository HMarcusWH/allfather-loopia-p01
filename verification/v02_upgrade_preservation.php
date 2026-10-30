<?php

declare(strict_types=1);

/**
 * V02 external verification overlay: tests FROZEN source only.
 * This file lives on a separate verification branch, never the frozen build.
 * CI runs it against a throwaway MariaDB service named allfather_test.
 */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Ops\{HistorySchemaPlan,HistorySchemaFixtureInstaller};
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};

$db=Harness::freshDb();
if($db===null){
    Assert::skip(Harness::unavailableReason());
    return;
}
$dbName=(string)$db->scalar('SELECT DATABASE()');
$version=(string)$db->scalar('SELECT VERSION()');
Assert::same('allfather_test',$dbName,
    'V02 verification overlay refuses every non-dedicated database');
Assert::true(str_starts_with($version,(string)getenv('V02_DB_MAJOR').'.'),
    'actual MariaDB version must match specified independent test lane');
if($dbName!=='allfather_test'
    || !str_starts_with($version,(string)getenv('V02_DB_MAJOR').'.')){
    return;
}
$root=dirname(__DIR__,2);
$clock=Harness::clock('2026-10-30T12:10:00.000Z');
$scope=Seed::tenantWorkspace($db,$clock,'v02-source-upgrade');
$second=Seed::tenantWorkspace($db,$clock,'v02-other-workspace');
$role=Seed::role($db,$clock,'v02-reader',['evidence.read']);
Seed::membership($db,$clock,$scope['tenant_id'],
    $scope['workspace_id'],$scope['operator_id'],$role['role_id']);
$guard=new AccessGuard($db,$clock);
$allow=$guard->evaluate('OPERATOR',$scope['operator_id'],
    $scope['tenant_id'],$scope['workspace_id'],
    'evidence.read','EVIDENCE',null,null,true);
$deny=$guard->evaluate('OPERATOR',$scope['operator_id'],
    $second['tenant_id'],$second['workspace_id'],
    'evidence.read','EVIDENCE',null,null,true);
Assert::same('ALLOW',$allow['decision'],
    'native M02 permits only explicit in-scope test membership');
Assert::same('DENY',$deny['decision'],
    'the same operator cannot inspect a different test workspace');

$chain=Seed::governedReleaseChain($db,$clock,$scope);
Assert::true(($chain['release']??null)!==null,
    'real native M05/M07/M08/M10 synthetic informational release was seeded');
Assert::true((int)$db->scalar('SELECT COUNT(*) FROM source_snapshots')>=2,
    'native P01/M05 retained synthetic before/after snapshot rows');
Assert::true((int)$db->scalar('SELECT COUNT(*) FROM decision_records')>=1,
    'native M10 release record exists BEFORE candidate schema upgrade');

$plan=HistorySchemaPlan::inspect($root);
Assert::true($plan->isOk(),'RT01 schema candidate resolves against exact frozen source');
if(!$plan->isOk())return;
$p=$plan->value();
Assert::same(12,$p['family_count'],'all candidate families available');
Assert::same(38,$p['table_count'],'38 candidate tables');
Assert::same(76,$p['trigger_count'],'76 append-only triggers');
if(!defined('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST')){
    define('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST',true);
}

$nativeTables=array_column($db->all(
    "SELECT table_name AS n FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_type='BASE TABLE'"),'n');
sort($nativeTables,SORT_STRING);
$baselineDigest=static function(Database $conn,array $tables):array{
    $output=[];
    foreach($tables as $table){
        $rows=$conn->all('SELECT * FROM '.Database::identifier($table));
        $records=[];
        foreach($rows as $row){
            ksort($row,SORT_STRING);
            $records[]=serialize($row);
        }
        sort($records,SORT_STRING);
        $output[$table]=[
            'rows'=>count($records),
            'sha256'=>hash('sha256',implode("\n",$records)),
        ];
    }
    return $output;
};
$before=$baselineDigest($db,$nativeTables);
Assert::true(array_sum(array_column($before,'rows'))>=25,
    'the upgrade starts with actual populated legacy P01/M02/M05/M10 rows');
$native900=$db->one(
    'SELECT content_digest FROM schema_migrations WHERE migration_id=?',
    ['900_deferred_foreign_keys']
);
Assert::true($native900!==null,
    'immutable migration 900 ledger exists before candidate installation');
if($native900===null)return;
Assert::same($p['immutable_900_content_sha256'],$native900['content_digest'],
    'legacy 900 exact content hash must match frozen source');
$ledgerBefore=count($nativeTables)>0
    ?(int)$db->scalar('SELECT COUNT(*) FROM schema_migrations'):0;
Assert::same(31,$ledgerBefore,'full native migration set has 31 entries');

$pre=HistorySchemaFixtureInstaller::preflight($db,$root,$p);
Assert::true($pre->isOk(),'candidate schema preflight runs only in disposable DB');
if(!$pre->isOk())return;
Assert::same('READY_EMPTY_DISPOSABLE_TEST_DATABASE',
    $pre->value()['status'],
    'all 38 candidate tables absent before upgrade');

$all=[];
foreach($p['families'] as $family){
    foreach($family['table_names'] as $name)$all[]=$name;
}
try{
    $result=HistorySchemaFixtureInstaller::install($db,$root,$p);
    Assert::true($result->isOk(),
        'source-only upgrade installs candidate DDL around EXISTING native rows');
    if(!$result->isOk())return;
    Assert::same('FULL_12_FAMILY_DISPOSABLE_SCHEMA_INSTALLED_ONLY',
        $result->value()['status'],'candidate upgrade is disposable, not deployed');

    Assert::same($before,$baselineDigest($db,$nativeTables),
        'EVERY pre-existing native table retains exact row count and row-byte digest');
    $migration=(new Migrator($db,$root.'/database/migrations'))->up();
    Assert::same([], $migration['applied'],
        're-running native migrations applies no new migration');
    Assert::same([], $migration['drifted'],
        'native migration ledger has no drift after candidate DDL');
    Assert::same(31,count($migration['skipped']),
        'native 31 migrations are all no-op on repeat');
    Assert::same($ledgerBefore,
        (int)$db->scalar('SELECT COUNT(*) FROM schema_migrations'),
        'source candidate installation writes no native migration ledger entries');
    $same900=$db->one(
        'SELECT content_digest FROM schema_migrations WHERE migration_id=?',
        ['900_deferred_foreign_keys']
    );
    Assert::same($native900,$same900,
        'legacy 900 applied ledger entry remains byte-identical');

    $physical=$db->all(
        "SELECT table_name AS n FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='BASE TABLE'");
    $present=array_column($physical,'n');
    Assert::same([],array_values(array_diff($all,$present)),
        'every expected family table exists after upgrade');
    $triggers=$db->all(
        "SELECT event_object_table AS table_name,trigger_name AS name,
                action_timing AS timing,event_manipulation AS event,
                action_statement AS definition
         FROM information_schema.triggers
         WHERE trigger_schema=DATABASE()");
    $byTable=[];
    foreach($triggers as $t){
        if(!in_array($t['table_name'],$all,true))continue;
        $byTable[$t['table_name']][]=$t;
    }
    Assert::same(76,array_sum(array_map('count',$byTable)),
        'exact family-level trigger count checked from information_schema');
    foreach($all as $table){
        $pair=$byTable[$table]??[];
        Assert::same(2,count($pair),
            'every candidate table has TWO append-only triggers: '.$table);
        $events=array_column($pair,'event');
        sort($events,SORT_STRING);
        Assert::same(['DELETE','UPDATE'],$events,
            'both mutation paths are covered for '.$table);
        foreach($pair as $trigger){
            Assert::same('BEFORE',$trigger['timing'],
                'mutation trigger acts before deleting/updating '.$table);
            Assert::true(str_contains(strtoupper($trigger['definition']),'SIGNAL SQLSTATE'),
                'append-only trigger signals SQLSTATE for '.$table);
        }
    }
    $fk=$db->all(
        "SELECT table_name AS source_table,
                referenced_table_name AS parent_table
         FROM information_schema.key_column_usage
         WHERE table_schema=DATABASE() AND referenced_table_name IS NOT NULL");
    $known=array_fill_keys(array_merge($nativeTables,$all),true);
    foreach($fk as $row){
        if(in_array($row['source_table'],$all,true)){
            Assert::true(isset($known[$row['parent_table']]),
                'all candidate foreign keys resolve to existing native or candidate parent');
        }
    }
    Assert::same('RT01_DISPOSABLE_INSTALL_REQUIRES_EMPTY_SCHEMA',
        HistorySchemaFixtureInstaller::install($db,$root,$p)->reasonCode(),
        'candidate installer refuses replay rather than silently mutating populated schema');
    Assert::same($before,$baselineDigest($db,$nativeTables),
        'native data still byte-for-byte preserved after no-op replay');
}finally{
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach(array_reverse($p['families']) as $family){
        foreach(array_reverse($family['table_names']) as $table){
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        }
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
}
Assert::same($before,$baselineDigest($db,$nativeTables),
    'manual disposable candidate cleanup leaves original native records unchanged');
Assert::same(31,(int)$db->scalar('SELECT COUNT(*) FROM schema_migrations'),
    'candidate upgrade and cleanup have not altered native migrations');
