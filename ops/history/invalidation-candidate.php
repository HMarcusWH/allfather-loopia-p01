<?php

declare(strict_types=1);

/** IV-01 inert, read-only fixture preview: never an erasure or job command. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Workflow\{HistoryDependencyIndex,HistoryInvalidation};

$args=array_slice($argv,1);
if(count($args)!==1||!in_array($args[0],
    ['--status','--help','--synthetic-development'],true)){
    fwrite(STDERR,"IV01_OWNER_INVALIDATION_CONTEXT_REQUIRED\n");
    exit(3);
}
if($args[0]==='--status'||$args[0]==='--help'){
    echo json_encode([
        'version'=>HistoryInvalidation::VERSION,
        'status'=>'CANDIDATE_ONLY_NO_NATIVE_ERASURE',
        'source_owner_ratified'=>false,
        'live_invalidation_scheduler'=>false,
        'family350_runtime_migrated'=>false,
        'delete_or_key_destruction_authorized'=>false,
        'customer_model_output_authorized'=>false,
        'synthetic_requires'=>'ALLFATHER_IV01_SYNTHETIC_TEST=1',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit(0);
}
if(getenv('ALLFATHER_IV01_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"IV01_SYNTHETIC_TEST_MODE_REQUIRED\n");exit(3);
}
try {
    $scope=[
        'tenant_id'=>'iv01_fixture_tenant',
        'workspace_id'=>'iv01_fixture_workspace',
        'competitor_id'=>'iv01_fixture_competitor',
        'question_id'=>'iv01_fixture_question',
    ];
    $nodes=[
        ['node_id'=>'capture','kind'=>'CAPTURE','owner_ref_digest'=>str_repeat('a',64)],
        ['node_id'=>'basis','kind'=>'HISTORICAL_BASIS','owner_ref_digest'=>str_repeat('b',64)],
        ['node_id'=>'attention','kind'=>'ATTENTION','owner_ref_digest'=>str_repeat('c',64)],
    ];
    $graph=HistoryDependencyIndex::freeze([
        'scope'=>$scope,'catalog_cutoff_utc'=>'2026-10-29T00:00:00Z',
        'nodes'=>$nodes,'links'=>[
            ['parent_id'=>'capture','child_id'=>'basis','relation'=>'DERIVED_FROM'],
            ['parent_id'=>'basis','child_id'=>'attention','relation'=>'DERIVED_FROM'],
        ],
    ]);
    if(!$graph->isOk())throw new \RuntimeException('INVALID_GRAPH');
    $cause=HistoryInvalidation::freezeCause([
        'cause_kind'=>'SOURCE_RIGHTS_REVOKED',
        'root_node_id'=>'capture','owner_decision_ref_safe'=>'fixture_only',
        'owner_evidence_digest'=>str_repeat('d',64),
        'recorded_at_utc'=>'2026-10-29T00:01:00Z',
        'owner_cause_authenticated'=>false,
    ]);
    if(!$cause->isOk())throw new \RuntimeException('INVALID_CAUSE');
    $plan=HistoryInvalidation::plan([
        'graph'=>$graph->value(),'cause'=>$cause->value(),
        'evaluated_at_utc'=>'2026-10-29T00:02:00Z',
        'batch_size'=>2,'prior_receipt'=>null,
    ]);
    if(!$plan->isOk())throw new \RuntimeException('INVALID_PLAN');
    $p=$plan->value();
    echo json_encode([
        'version'=>HistoryInvalidation::VERSION,
        'status'=>$p['status'],
        'graph_digest'=>$p['graph_digest'],
        'receipt_digest'=>$p['receipt_digest'],
        'affected_count'=>$p['affected_total'],
        'batch_count'=>count($p['affected_in_batch']),
        'next_cursor'=>$p['next_cursor'],
        'source_owner_verified'=>false,'source_use_admitted'=>false,
        'purge_or_key_destruction_executed'=>false,
        'model_or_customer_output_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"IV01_SYNTHETIC_PREVIEW_UNAVAILABLE\n");exit(4);
}
