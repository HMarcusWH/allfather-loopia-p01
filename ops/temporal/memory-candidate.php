<?php

declare(strict_types=1);

/** TM-02 bounded offline candidate preview; not an M17 production runner. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Substrate\M17\{MemoryStore,P01WindowProfileV2};
use Allfather\Loopia\Workflow\{PointInTimeHistoricalBasis,HistoryM17V2CandidateBridge};

$args=array_slice($argv,1);
if(count($args)!==1 || !in_array($args[0],[
    '--status','--help','--synthetic-development',
],true)){
    fwrite(STDERR,"TM02_NATIVE_M17_OWNER_CONTEXT_NOT_AVAILABLE\n");
    exit(3);
}
if($args[0]==='--help'||$args[0]==='--status'){
    echo json_encode([
        'version'=>P01WindowProfileV2::VERSION,
        'state'=>'INERT_M17_V2_CANDIDATE_ONLY',
        'real_time_factory_admitted'=>false,
        'candidate_280_migrated'=>false,
        'historical_source_reuse_admitted'=>false,
        'provider_model_call_authorized'=>false,
        'customer_release_authorized'=>false,
        'fixture_option'=>'--synthetic-development requires ALLFATHER_TM02_SYNTHETIC_TEST=1',
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
if(getenv('ALLFATHER_TM02_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"TM02_SYNTHETIC_FIXTURE_ONLY\n");exit(3);
}
try{
    $path=dirname(__DIR__,2).'/tests/fixtures/li05_offline_basis_vectors.json';
    $v=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    $input=[
        'scope'=>$v['scope'],'period'=>$v['period'],
        'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
        'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,
        'coverage'=>[
            'state'=>'KNOWN','plan_version_id'=>'tm02.cli.synthetic',
            'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
            'failed'=>0,'skipped'=>0,'unreported'=>0,
        ],
    ];
    $basis=PointInTimeHistoricalBasis::freeze([
        'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
        'legacy_input'=>$input,'previous_basis_digest'=>null,
        'review_at_utc'=>'2026-03-16T00:00:00Z',
    ]);
    if(!$basis->isOk())throw new \RuntimeException('TM02_BASIS_REFUSED');
    $bridge=HistoryM17V2CandidateBridge::compose(
        $basis->value(),'SOURCE_DIFF','MONTH');
    if(!$bridge->isOk())throw new \RuntimeException('TM02_BRIDGE_REFUSED');
    $p=MemoryStore::preview($bridge->value()['frozen_m17_inputs']);
    if(!$p->isOk())throw new \RuntimeException('TM02_PREVIEW_REFUSED');
    echo json_encode([
        'version'=>P01WindowProfileV2::VERSION,
        'run_identity_digest'=>$bridge->value()['m17_v2_run_digest'],
        'v1_rollup_digest'=>$bridge->value()['v1_rollup_digest'],
        'selected_count'=>$bridge->value()['lineage_count'],
        'contrary_count'=>$bridge->value()['candidate_v2']['contrary_reference_count'],
        'candidate_only'=>true,
        'source_bytes_returned'=>false,
        'model_call_authorized'=>false,
        'runtime_storage_written'=>false,
        'customer_output_authorized'=>false,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(\Throwable){
    fwrite(STDERR,"TM02_SYNTHETIC_PREVIEW_FAILED\n");exit(4);
}
