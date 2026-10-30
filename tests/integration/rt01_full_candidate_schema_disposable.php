<?php

declare(strict_types=1);

use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Ops\{HistorySchemaPlan,HistorySchemaFixtureInstaller};
use Allfather\Loopia\Tests\{Assert,Harness};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$plan=HistorySchemaPlan::inspect($root);
Assert::true($plan->isOk(),'all twelve family SQL sources and topo dependencies validate');
if(!$plan->isOk())return;
$p=$plan->value();
if(!defined('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST')){
    define('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST',true);
}
$baseline=(int)$db->scalar('SELECT COUNT(*) FROM schema_migrations');
$original900=$db->one(
    'SELECT content_digest FROM schema_migrations WHERE migration_id=?',
    ['900_deferred_foreign_keys']);
Assert::true($original900!==null,'native immutable 900 exists before candidate DDL');
if($original900===null)return;
Assert::same($p['immutable_900_content_sha256'],$original900['content_digest'],
    'legacy 900 migration content digest agrees exactly');
$allTables=[];
foreach($p['families'] as $family){
    foreach($family['table_names'] as $table)$allTables[$table]=true;
}
$installed=false;
try{
    $pre=HistorySchemaFixtureInstaller::preflight($db,$root,$p);
    Assert::true($pre->isOk(),'disposable-test-only preflight can read live schema metadata');
    if(!$pre->isOk())return;
    Assert::same('READY_EMPTY_DISPOSABLE_TEST_DATABASE',$pre->value()['status'],
        'candidate tables not implicitly added by ordinary startup');

    // Deliberate interrupted DDL: one source family table exists but its
    // sibling rows/triggers do not. Never AUTO REPAIR or silently resume.
    $f260=$root.'/docs/longitudinal/proposals/260_competitor_assessments_CANDIDATE.sql';
    $statements=Migrator::splitStatements((string)file_get_contents($f260));
    $db->pdo()->exec($statements[0]);
    $partial=HistorySchemaFixtureInstaller::preflight($db,$root,$p);
    Assert::same('HOLD_PARTIAL_NONTRANSACTIONAL_DDL',$partial->value()['status'],
        'partial test DDL is not mistaken for either fresh or complete schema');
    Assert::same('RT01_DISPOSABLE_INSTALL_REQUIRES_EMPTY_SCHEMA',
        HistorySchemaFixtureInstaller::install($db,$root,$p)->reasonCode(),
        'installer refuses non-transactional partial state without retry');
    $db->pdo()->exec('DROP TABLE IF EXISTS p01_observation_assessments');

    $full=HistorySchemaFixtureInstaller::install($db,$root,$p);
    Assert::true($full->isOk(),'12 family candidate DDL installs in FK-safe order on test DB');
    if(!$full->isOk())return;
    $installed=true;
    Assert::same(38,$full->value()['tables_created'],
        'physical candidate table count matches source');
    Assert::same(76,$full->value()['append_only_triggers_created'],
        'all append-only candidate triggers actually created');
    Assert::same(false,$full->value()['live_runtime_migration_applied'],
        'disposable SQL is not a native migration');
    Assert::same(false,$full->value()['external_effects_authorized'],
        'SQL install grants no model/scheduler/source action');
    $again=HistorySchemaFixtureInstaller::preflight($db,$root,$p);
    Assert::same('COMPLETE_DISPOSABLE_SCHEMA_PRESENT_REVERIFY_CONTENT',
        $again->value()['status'],
        'complete candidate install is explicitly separate from native ownership');
    Assert::same('RT01_DISPOSABLE_INSTALL_REQUIRES_EMPTY_SCHEMA',
        HistorySchemaFixtureInstaller::install($db,$root,$p)->reasonCode(),
        'exact repeat refuses, not an implicit auto-upgrade');

    // Interrupt one trigger after a successful test install. The preflight
    // must HOLD rather than relabel the physical schema complete.
    $trigger=$p['families'][0]['trigger_names'][0];
    $db->pdo()->exec('DROP TRIGGER '.Database::identifier($trigger));
    $changed=HistorySchemaFixtureInstaller::preflight($db,$root,$p);
    Assert::same('HOLD_PARTIAL_NONTRANSACTIONAL_DDL',
        $changed->value()['status'],
        'missing immutable trigger blocks re-admission');
    Assert::same('RT01_DISPOSABLE_INSTALL_REQUIRES_EMPTY_SCHEMA',
        HistorySchemaFixtureInstaller::install($db,$root,$p)->reasonCode(),
        'broken DDL requires explicit disposable reconstruction');

    Assert::same($baseline,(int)$db->scalar('SELECT COUNT(*) FROM schema_migrations'),
        'source test installer never appends migration ledger entries');
    Assert::same($original900['content_digest'],(string)$db->scalar(
        'SELECT content_digest FROM schema_migrations WHERE migration_id=?',
        ['900_deferred_foreign_keys']),
        'legacy 900 migration SHA unchanged after all candidate DDL');
}finally{
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach(array_reverse($p['families']) as $family){
        foreach(array_reverse($family['table_names']) as $table){
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        }
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    $left=HistorySchemaFixtureInstaller::preflight($db,$root,$p);
    if($left->isOk()){
        Assert::same(0,$left->value()['tables_present'],
            'all disposable candidate tables removed after test');
        Assert::same(0,$left->value()['triggers_present'],
            'no candidate triggers remain after table cleanup');
    }
}
