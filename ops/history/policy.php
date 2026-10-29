<?php

declare(strict_types=1);

/** DU-01: source-only inspect; NOT an owner policy configuration command. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Workflow\HistoryReviewPolicy;

$args=array_slice($argv,1);
if(count($args)!==1||!in_array($args[0],['--status','--help','--synthetic-development'],true)){
    fwrite(STDERR,"DU01_U05_POLICY_OWNER_CONTEXT_REQUIRED\n");exit(3);
}
if($args[0]==='--status'||$args[0]==='--help'){
    echo json_encode([
        'version'=>HistoryReviewPolicy::VERSION,
        'state'=>'CANDIDATE_ONLY_U05_NOT_ADOPTED',
        'factory'=>'CLI_SYNTHETIC_INSPECT_ONLY',
        'source_rights_verified'=>false,'scheduler_active'=>false,
        'model_execution_enabled'=>false,'review_events_persisted'=>false,
        'runtime_migration_installed'=>false,
        'synthetic_requires'=>'ALLFATHER_DU01_SYNTHETIC_TEST=1',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if(getenv('ALLFATHER_DU01_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"DU01_SYNTHETIC_TEST_MODE_REQUIRED\n");exit(3);
}
try{
    $fixture=dirname(__DIR__,2).'/tests/fixtures/li05_offline_basis_vectors.json';
    $v=json_decode((string)file_get_contents($fixture),true,64,JSON_THROW_ON_ERROR);
    $sources=array_values(array_unique(array_column($v['rows'],'source_ref_id')));
    $p=HistoryReviewPolicy::freeze([
        'version'=>HistoryReviewPolicy::VERSION,'scope'=>$v['scope'],
        'monitored_source_refs'=>$sources,
        'cadence_seconds'=>86400,'maximum_wait_seconds'=>7*86400,
        'cooldown_seconds'=>3600,'max_pending_work'=>3,
        'max_basis_references'=>20,'unique_event_threshold'=>null,
        'maximum_reviews_per_utc_day'=>2,'calendar'=>'UTC_SECONDS_V1',
        'supported_triggers'=>[
            'new_evidence'=>true,'review_clock'=>true,
            'verified_event_count'=>false,'contradiction'=>false,
        ],
    ]);
    if(!$p->isOk())throw new \RuntimeException('DU01_SYNTHETIC_POLICY_INVALID');
    echo json_encode([
        'version'=>HistoryReviewPolicy::VERSION,
        'policy_digest'=>$p->value()['policy_digest'],
        'source_reference_count'=>count($sources),
        'owner_policy_adopted'=>false,
        'native_source_use_admitted'=>false,
        'live_schedule_authorized'=>false,
        'runtime_write_performed'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"DU01_SYNTHETIC_POLICY_UNAVAILABLE\n");exit(4);
}
