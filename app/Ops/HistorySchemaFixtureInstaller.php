<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\{Database,Migrator};

/**
 * RT-01: candidate schema installer ONLY against an independently disposable
 * CLI MariaDB test database, never staging/main/production. No implicit
 * rollback of MariaDB DDL: partial installs must be inspected and cleaned
 * explicitly on the disposable test target.
 */
final class HistorySchemaFixtureInstaller
{
    public const VERSION='rt01.disposable-schema-verifier.v1';

    public static function preflight(Database $db,string $root,array $plan):Outcome
    {
        if(!self::fixtureAllowed($db)
            || !HistorySchemaPlan::verify($root,$plan)->isOk()){
            return Outcome::refuse('RT01_RUNTIME_OR_PLAN_NOT_DISPOSABLE');
        }
        $migration=$db->one(
            'SELECT content_digest FROM schema_migrations WHERE migration_id=?',
            ['900_deferred_foreign_keys'],
        );
        if($migration===null||$migration['content_digest']!==$plan['immutable_900_content_sha256']){
            return Outcome::refuse('RT01_NATIVE_900_LEDGER_NOT_EXACT');
        }
        $tables=[];
        foreach($plan['families'] as $f){
            foreach($f['table_names'] as $table)$tables[$table]=true;
        }
        $triggers=[];
        foreach($plan['families'] as $f){
            foreach($f['trigger_names'] as $name)$triggers[$name]=true;
        }
        $existingTables=$db->all(
            'SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_type=?',
            ['BASE TABLE'],
        );
        $existingTriggers=$db->all(
            'SELECT trigger_name AS name FROM information_schema.triggers
             WHERE trigger_schema=DATABASE()',
        );
        $foundTables=[];
        foreach($existingTables as $row){
            if(isset($tables[$row['name']]))$foundTables[$row['name']]=true;
        }
        $foundTriggers=[];
        foreach($existingTriggers as $row){
            if(isset($triggers[$row['name']]))$foundTriggers[$row['name']]=true;
        }
        $tableCount=count($foundTables);$triggerCount=count($foundTriggers);
        $status=match(true){
            $tableCount===0 && $triggerCount===0
                =>'READY_EMPTY_DISPOSABLE_TEST_DATABASE',
            $tableCount===$plan['table_count']
                && $triggerCount===$plan['trigger_count']
                =>'COMPLETE_DISPOSABLE_SCHEMA_PRESENT_REVERIFY_CONTENT',
            default=>'HOLD_PARTIAL_NONTRANSACTIONAL_DDL',
        };
        return Outcome::ok([
            'version'=>self::VERSION,
            'status'=>$status,
            'plan_digest'=>$plan['plan_digest'],
            'tables_present'=>$tableCount,
            'triggers_present'=>$triggerCount,
            'expected_tables'=>$plan['table_count'],
            'expected_triggers'=>$plan['trigger_count'],
            'native_migration_900_rerun'=>false,
            'new_runtime_migrations_applied'=>false,
            'automatic_partial_repair'=>false,
            'owner_admitted'=>false,
            'customer_visible'=>false,
        ]);
    }

    public static function install(Database $db,string $root,array $plan):Outcome
    {
        $pre=self::preflight($db,$root,$plan);
        if(!$pre->isOk())return $pre;
        if($pre->value()['status']!=='READY_EMPTY_DISPOSABLE_TEST_DATABASE'){
            return Outcome::refuse('RT01_DISPOSABLE_INSTALL_REQUIRES_EMPTY_SCHEMA');
        }
        foreach($plan['families'] as $f){
            $path=$root.'/'.$f['source_path'];
            if(is_link($path)||!is_file($path)
                || !hash_equals($f['source_sha256'],hash_file('sha256',$path))){
                return Outcome::refuse('RT01_SOURCE_SQL_CHANGED_BEFORE_EXECUTION');
            }
            $sql=(string)file_get_contents($path);
            $statements=Migrator::splitStatements($sql);
            foreach($statements as $index=>$statement){
                try{$db->pdo()->exec($statement);}
                catch(\PDOException){
                    // DDL commits in MariaDB; do NOT pretend rollback succeeded.
                    return Outcome::refuse('RT01_DISPOSABLE_DDL_PARTIAL_HOLD',[
                        'family'=>$f['family'],'statement_ordinal'=>$index+1,
                        'automatic_rollback_performed'=>false,
                    ]);
                }
            }
        }
        $after=self::preflight($db,$root,$plan);
        if(!$after->isOk()
            || $after->value()['status']!=='COMPLETE_DISPOSABLE_SCHEMA_PRESENT_REVERIFY_CONTENT'){
            return Outcome::refuse('RT01_CANDIDATE_INSTALL_COMPLETENESS_HOLD');
        }
        return Outcome::ok([
            'version'=>self::VERSION,
            'status'=>'FULL_12_FAMILY_DISPOSABLE_SCHEMA_INSTALLED_ONLY',
            'plan_digest'=>$plan['plan_digest'],
            'family_count'=>$plan['family_count'],
            'tables_created'=>$plan['table_count'],
            'append_only_triggers_created'=>$plan['trigger_count'],
            'source_family_order'=>$plan['install_order'],
            'legacy_900_edited_or_rerun'=>false,
            'live_runtime_migration_applied'=>false,
            'actual_owner_permissions_granted'=>false,
            'external_effects_authorized'=>false,
            'customer_visibility_authorized'=>false,
        ]);
    }

    private static function fixtureAllowed(Database $db):bool
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST')
            || constant('ALLFATHER_RT01_DISPOSABLE_SCHEMA_TEST')!==true){
            return false;
        }
        try{
            $name=$db->scalar('SELECT DATABASE()');
            return is_string($name)
                && preg_match('/(?:^|_)test(?:_|$)/D',$name)===1;
        }catch(\Throwable){
            return false;
        }
    }
}
