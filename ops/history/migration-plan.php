<?php

declare(strict_types=1);

/** RT-01 read-only schema candidate plan; never executes database DDL. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Ops\HistorySchemaPlan;

$args=array_slice($argv,1);
if(count($args)!==1||!in_array($args[0],[
    '--help','--status','--synthetic-development',
],true)){
    fwrite(STDERR,"RT01_LIVE_MIGRATION_OR_UNBOUNDED_ARG_NOT_ADOPTED\n");
    exit(3);
}
try{
    $plan=HistorySchemaPlan::inspect(dirname(__DIR__,2));
    if(!$plan->isOk()){
        fwrite(STDERR,"RT01_CANDIDATE_SCHEMA_SOURCE_HOLD\n");
        exit(4);
    }
    $v=$plan->value();
    echo json_encode([
        'version'=>HistorySchemaPlan::VERSION,
        'status'=>$v['status'],
        'plan_digest'=>$v['plan_digest'],
        'family_count'=>$v['family_count'],
        'candidate_tables'=>$v['table_count'],
        'candidate_append_only_triggers'=>$v['trigger_count'],
        'topological_family_order'=>$v['install_order'],
        'provisional_registry_divergence_families'=>
            $v['provisional_registry_differences'],
        'legacy_900_replayed'=>false,
        'runtime_migration_applied'=>false,
        'new_owner_permission'=>false,
        'customer_visibility_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"RT01_CANDIDATE_PLAN_UNAVAILABLE\n");exit(4);
}
