<?php

declare(strict_types=1);

/**
 * RT-02: source-only evidence carrier, NOT the authorized V10 road test.
 * No live tenant selector, DB, source fetch, model, P10 restore or M10 release.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Ops\{BuildFreezeInspector,HistoryRecoveryManifest};

$args=array_slice($argv,1);
if(count($args)!==1
    || !in_array($args[0],['--status','--synthetic-development'],true)){
    fwrite(STDERR,"RT02_HISTORY_ROAD_TEST_NOT_OWNER_ADOPTED\n");
    exit(3);
}
$root=dirname(__DIR__,2);
try{
    $inventory=BuildFreezeInspector::inspect($root);
    if(!$inventory->isOk()){
        fwrite(STDERR,"RT02_HISTORY_EVIDENCE_SOURCE_HOLD\n");
        exit(4);
    }
    $output=[
        'version'=>'rt02.history-review-evidence-export.v1',
        'status'=>'SOURCE_METADATA_ONLY_V10_NOT_EXECUTED',
        'source_inventory_digest'=>$inventory->value()['inventory_digest'],
        'source_package_count'=>$inventory->value()['package_count'],
        'schema_plan_digest'=>$inventory->value()['schema_plan_digest'],
        'candidate_schema_families'=>$inventory->value()['schema_families'],
        'actual_historical_review_records_exported'=>0,
        'actual_m05_private_bytes_exported'=>0,
        'real_m10_release_records_exported'=>0,
        'actual_human_decisions_exported'=>0,
        'real_provider_requests_executed'=>0,
        'host_restore_verified'=>false,
        'owner_adoption_verified'=>false,
        'verification_phase'=>'V01_V11_NOT_RUN',
        'external_customer_delivery'=>false,
    ];
    if($args[0]==='--synthetic-development'){
        if(getenv('ALLFATHER_RT02_SYNTHETIC_TEST')!=='1'){
            fwrite(STDERR,"RT02_EXPLICIT_SYNTHETIC_EVIDENCE_TEST_REQUIRED\n");
            exit(3);
        }
        require_once $root.'/tests/support/Va03MockRecovery.php';
        $manifest=\Allfather\Loopia\Tests\Va03MockRecovery::manifest($root);
        if(!$manifest->isOk()
            || !HistoryRecoveryManifest::verify($manifest->value())->isOk()){
            fwrite(STDERR,"RT02_CANDIDATE_RECOVERY_MANIFEST_HOLD\n");
            exit(4);
        }
        $output['status']='FIXTURE_SOURCE_AND_RECOVERY_MANIFEST_ONLY';
        $output['synthetic_recovery_manifest_digest']=$manifest->value()['manifest_digest'];
        $output['synthetic_private_artifacts_count']=count(
            $manifest->value()['private_artifacts']??[]);
        $output['host_restore_verified']=false;
    }
    echo json_encode($output,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"RT02_HISTORY_EVIDENCE_SOURCE_INTEGRITY_UNAVAILABLE\n");
    exit(4);
}
