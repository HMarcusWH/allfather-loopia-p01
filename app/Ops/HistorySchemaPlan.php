<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Db\Migrator;

/** RT-01: candidate SQL vs provisional allocation and immutable 900 reconciliation. */
final class HistorySchemaPlan
{
    public const VERSION='rt01.candidate-physical-schema-plan.v1';
    public const FAMILIES=['260','265','270','280','290','300','310','320','330','340','345','350'];
    public const EXPECTED_TABLES=38;
    public const EXPECTED_TRIGGERS=76;
    private const EXISTING_OWNER_PARENTS=[
        'tenants','workspaces','competitors','monitored_sources',
        'source_snapshots','evidence_objects','source_diffs','source_references',
    ];

    public static function inspect(string $root):Outcome
    {
        $root=realpath($root);
        if($root===false)return Outcome::refuse('RT01_REPOSITORY_ROOT_MISSING');
        $regPath=$root.'/docs/longitudinal/build-first/SCHEMA_FAMILY_REGISTRY.json';
        $oldPath=$root.'/database/migrations/900_deferred_foreign_keys.sql';
        if(is_link($regPath)||!is_file($regPath)||is_link($oldPath)||!is_file($oldPath)){
            return Outcome::refuse('RT01_SOURCE_OR_LEGACY_900_MISSING');
        }
        try{$registry=json_decode((string)file_get_contents($regPath),true,64,JSON_THROW_ON_ERROR);}
        catch(\Throwable){return Outcome::refuse('RT01_REGISTRY_JSON_INVALID');}
        if(!is_array($registry)
            || ($registry['schema_version']??null)!=='allfather.build_first.schema_registry.v1'
            || ($registry['any_live_migrations_applied']??null)!==false
            || !is_array($registry['families']??null)){
            return Outcome::refuse('RT01_REGISTRY_NOT_SOURCE_CANDIDATE');
        }
        $old=(string)file_get_contents($oldPath);
        $oldBlob=sha1('blob '.strlen($old)."\0".$old);
        if(!hash_equals((string)($registry['legacy_migration_900']['source_git_blob_sha1']??''),$oldBlob)){
            return Outcome::refuse('RT01_MIGRATION_900_IMMUTABILITY_BROKEN');
        }
        $idx=[];$entries=[];$owners=[];$allTriggers=[];
        foreach($registry['families'] as $f){
            $label=$f['label']??null;
            if(!is_string($label)||isset($idx[$label]))return Outcome::refuse('RT01_REGISTRY_FAMILY_DUPLICATE');
            $idx[$label]=$f;
        }
        if(count($idx)!==count(self::FAMILIES))return Outcome::refuse('RT01_FAMILY_COUNT_MISMATCH');
        $paths=glob($root.'/docs/longitudinal/proposals/*_CANDIDATE*.sql')?:[];
        if(count($paths)!==count(self::FAMILIES))return Outcome::refuse('RT01_UNEXPECTED_DDL_FILE_COUNT');
        foreach(self::FAMILIES as $family){
            if(($idx[$family]['status']??null)!=='CANDIDATE_NOT_INSTALLED'){
                return Outcome::refuse('RT01_FAMILY_ALREADY_ADOPTED_OR_MISSING');
            }
            $match=glob($root.'/docs/longitudinal/proposals/'.
                $family.'_*_CANDIDATE*.sql')?:[];
            if(count($match)!==1||is_link($match[0])){
                return Outcome::refuse('RT01_FAMILY_DDL_AMBIGUOUS');
            }
            $sql=file_get_contents($match[0]);
            if(!is_string($sql)||strlen($sql)<100||strlen($sql)>262144){
                return Outcome::refuse('RT01_DDL_NOT_READABLE');
            }
            $text=preg_replace('/--[^\r\n]*/','',$sql);
            if(!is_string($text)||preg_match('/\b(?:DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM|ALTER\s+USER|ON\s+DELETE\s+CASCADE)\b/i',$text)===1){
                return Outcome::refuse('RT01_DESTRUCTIVE_DDL_NOT_PERMITTED');
            }
            preg_match_all('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z_0-9]+)/i',$text,$t);
            preg_match_all('/\bCREATE\s+TRIGGER\s+([a-zA-Z_0-9]+)/i',$text,$g);
            preg_match_all('/\bREFERENCES\s+([a-zA-Z_0-9]+)/i',$text,$r);
            $tables=array_values($t[1]);$triggers=array_values($g[1]);
            if($tables===[]||count($triggers)!==2*count($tables)
                || count($tables)!==count(array_unique($tables))
                || count($triggers)!==count(array_unique($triggers))){
                return Outcome::refuse('RT01_CANDIDATE_TABLE_TRIGGER_CONTRACT_INVALID');
            }
            foreach($tables as $table){
                if(!preg_match('/^[a-z][a-z0-9_]{1,127}$/D',$table)||isset($owners[$table])){
                    return Outcome::refuse('RT01_DUPLICATE_PHYSICAL_TABLE');
                }
                $owners[$table]=$family;
            }
            foreach($triggers as $trigger){
                if(isset($allTriggers[$trigger]))return Outcome::refuse('RT01_DUPLICATE_TRIGGER');
                $allTriggers[$trigger]=true;
            }
            $original=array_map(static fn(array $t):mixed=>$t['name']??null,$idx[$family]['tables']??[]);
            if(in_array(null,$original,true)||count($original)!==count(array_unique($original))){
                return Outcome::refuse('RT01_REGISTRY_TABLE_LIST_INVALID');
            }
            sort($tables,SORT_STRING);sort($original,SORT_STRING);sort($triggers,SORT_STRING);
            $added=array_values(array_diff($tables,$original));
            $unbuilt=array_values(array_diff($original,$tables));
            $stmt=Migrator::splitStatements($sql);
            if(count($stmt)!==count($tables)+count($triggers)){
                return Outcome::refuse('RT01_STATEMENT_SPLIT_DRIFT');
            }
            $refs=array_values(array_unique($r[1]));sort($refs,SORT_STRING);
            $entries[$family]=[
                'family'=>$family,
                'source_path'=>'docs/longitudinal/proposals/'.basename($match[0]),
                'source_sha256'=>hash('sha256',$sql),
                'table_names'=>$tables,'trigger_names'=>$triggers,
                'fk_reference_targets'=>$refs,
                'provisional_registry_tables'=>$original,
                'physical_additions'=>$added,'unmaterialized_registry_tables'=>$unbuilt,
                'statement_count'=>count($stmt),
                'installed_in_live_schema'=>false,'owner_ratified'=>false,
            ];
        }
        $depends=[];$edges=[];
        foreach($entries as $family=>$entry){
            $dep=[];
            foreach($entry['fk_reference_targets'] as $target){
                if(isset($owners[$target])){
                    if($owners[$target]!==$family)$dep[$owners[$target]]=true;
                }elseif(!in_array($target,self::EXISTING_OWNER_PARENTS,true)){
                    return Outcome::refuse('RT01_UNRECONCILED_FK_PARENT');
                }
            }
            $depends[$family]=array_keys($dep);sort($depends[$family],SORT_STRING);
            foreach($depends[$family] as $required){
                $edges[]=['before'=>$required,'after'=>$family];
            }
        }
        $order=[];$remaining=$depends;
        while($remaining!==[]){
            $ready=[];
            foreach($remaining as $name=>$deps){
                if(array_diff($deps,$order)===[])$ready[]=$name;
            }
            sort($ready,SORT_STRING);
            if($ready===[])return Outcome::refuse('RT01_FK_TOPOLOGICAL_CYCLE');
            $next=$ready[0];$order[]=$next;unset($remaining[$next]);
        }
        $ordered=[];
        foreach($order as $family){
            $ordered[]=$entries[$family]+['required_prior_families'=>$depends[$family]];
        }
        $tableCount=count($owners);$triggerCount=count($allTriggers);
        if($tableCount!==self::EXPECTED_TABLES||$triggerCount!==self::EXPECTED_TRIGGERS){
            return Outcome::refuse('RT01_PHYSICAL_SCHEMA_COMPLETENESS_MISMATCH');
        }
        $drift=[];
        foreach($ordered as $row){
            if($row['physical_additions']!==[]||$row['unmaterialized_registry_tables']!==[]){
                $drift[]=$row['family'];
            }
        }
        $v=[
            'version'=>self::VERSION,'status'=>'CANDIDATE_DDL_PACKAGED_NOT_MIGRATED',
            'families'=>$ordered,'family_count'=>count($ordered),
            'table_count'=>$tableCount,'trigger_count'=>$triggerCount,
            'install_order'=>$order,'fk_dependency_edges'=>$edges,
            'provisional_registry_differences'=>$drift,
            'registry_digest'=>hash_file('sha256',$regPath),
            'immutable_900_git_blob_sha1'=>$oldBlob,
            'immutable_900_content_sha256'=>hash('sha256',$old),
            'candidate_sql_index_digest'=>Digest::canonicalDigest($ordered),
            'legacy_900_modified_or_rerun'=>false,
            'live_schema_installed'=>false,'new_runtime_owners_enabled'=>false,
            'customer_visibility_authorized'=>false,
        ];
        $v['plan_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    public static function verify(string $root,array $plan):Outcome
    {
        $fresh=self::inspect($root);
        if(!$fresh->isOk()||$fresh->value()!==$plan){
            return Outcome::refuse('RT01_PHYSICAL_SCHEMA_PLAN_STALE_OR_FORGED');
        }
        return Outcome::ok(['integrity'=>'EXACT_CANDIDATE_SQL_SOURCE',
            'runtime_migrated'=>false]);
    }
}
