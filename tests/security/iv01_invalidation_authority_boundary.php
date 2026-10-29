<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{HistoryDependencyIndex,HistoryInvalidation};

$scope=[
    'tenant_id'=>'iv01_tenant','workspace_id'=>'iv01_workspace',
    'competitor_id'=>'iv01_competitor','question_id'=>'iv01_question',
];
$graph=HistoryDependencyIndex::freeze([
    'scope'=>$scope,'catalog_cutoff_utc'=>'2026-10-29T00:00:00Z',
    'nodes'=>[
        ['node_id'=>'capture','kind'=>'CAPTURE','owner_ref_digest'=>str_repeat('a',64)],
        ['node_id'=>'basis','kind'=>'HISTORICAL_BASIS','owner_ref_digest'=>str_repeat('b',64)],
        ['node_id'=>'attention','kind'=>'ATTENTION','owner_ref_digest'=>str_repeat('c',64)],
    ],
    'links'=>[
        ['parent_id'=>'capture','child_id'=>'basis','relation'=>'DERIVED_FROM'],
        ['parent_id'=>'basis','child_id'=>'attention','relation'=>'DERIVED_FROM'],
    ],
])->value();
$cause=HistoryInvalidation::freezeCause([
    'cause_kind'=>'LAWFUL_ERASURE','root_node_id'=>'capture',
    'owner_decision_ref_safe'=>'unapproved_fixture_erasure',
    'owner_evidence_digest'=>str_repeat('d',64),
    'recorded_at_utc'=>'2026-10-29T00:01:00Z',
    'owner_cause_authenticated'=>false,
])->value();
$plan=HistoryInvalidation::plan([
    'graph'=>$graph,'cause'=>$cause,
    'evaluated_at_utc'=>'2026-10-29T00:02:00Z',
    'batch_size'=>2,'prior_receipt'=>null,
])->value();
foreach(['purge_or_key_destruction_executed','owner_cause_authenticated',
         'm10_current_reliance_authorized','retention_extension_authorized',
         'runtime_persistence_authorized','model_or_customer_output_authorized'] as $key){
    $fake=$plan;$fake[$key]=true;
    $un=$fake;unset($un['receipt_digest']);
    $fake['receipt_digest']=Digest::canonicalDigest($un);
    Assert::same('IV01_RECEIPT_AUTHORITY_FORGERY',
        HistoryInvalidation::verifyReceipt($fake)->reasonCode(),
        "self-hashed {$key} cannot create a source-owner permission");
}
$fake=$cause;$fake['owner_cause_authenticated']=true;
$un=$fake;unset($un['cause_digest']);
$fake['cause_digest']=Digest::canonicalDigest($un);
Assert::same('IV01_CAUSE_NOT_AUTHENTICATED',
    HistoryInvalidation::verifyCause($fake)->reasonCode(),
    'signed-looking fixture event cannot pretend to be native owner proof');
$fake=$graph;$fake['history_source_use_admitted']=true;
$un=$fake;unset($un['graph_digest']);
$fake['graph_digest']=Digest::canonicalDigest($un);
Assert::same('IV01_GRAPH_DIGEST_OR_AUTHORITY_INVALID',
    HistoryDependencyIndex::verify($fake)->reasonCode(),
    'graph candidate cannot self-issue current-source use');
Assert::same('HOLD',HistoryInvalidation::inspectReliance($graph,$cause,'attention')
    ->value()['decision'],'unscanned attention must HOLD synchronously');
Assert::same(false,HistoryInvalidation::inspectReliance($graph,$cause,'attention')
    ->value()['source_use_authorized'],'completion progress is not permission');
Assert::same('IV01_RELIANCE_NODE_NOT_FOUND',
    HistoryInvalidation::inspectReliance($graph,$cause,'other_tenant')->reasonCode(),
    'foreign/unknown dependency cannot be enumerated');
$foreign=$graph;$foreign['scope']['tenant_id']='other';
Assert::same('IV01_GRAPH_REPLAY_INVALID',
    HistoryDependencyIndex::verify($foreign)->reasonCode(),
    'cross-scope mutation breaks frozen graph integrity');
$bad=HistoryDependencyIndex::freeze([
    'scope'=>$scope,'catalog_cutoff_utc'=>'2026-10-29T00:00:00Z',
    'nodes'=>[
        ['node_id'=>'attention','kind'=>'ATTENTION','owner_ref_digest'=>str_repeat('a',64)],
        ['node_id'=>'capture','kind'=>'CAPTURE','owner_ref_digest'=>str_repeat('b',64)],
    ],
    'links'=>[
        ['parent_id'=>'attention','child_id'=>'capture','relation'=>'DERIVED_FROM'],
    ],
]);
Assert::same('IV01_GRAPH_ILLEGAL_OWNER_TRANSITION',$bad->reasonCode(),
    'a derived customer attention must not be treated as an earlier capture');
$bad=HistoryInvalidation::freezeCause([
    'cause_kind'=>'SOURCE_RIGHTS_REVOKED','root_node_id'=>'capture',
    'owner_decision_ref_safe'=>'fixture','owner_evidence_digest'=>str_repeat('d',64),
    'recorded_at_utc'=>'2026-10-29T00:01:00Z',
    'owner_cause_authenticated'=>true,
]);
Assert::same('IV01_CAUSE_CONTRACT_INVALID',$bad->reasonCode(),
    'caller cannot mark a cause authenticated');
foreach(['app/Workflow/HistoryDependencyIndex.php',
         'app/Workflow/HistoryInvalidation.php',
         'app/Workflow/HistoryInvalidationStore.php'] as $path){
    $code=(string)file_get_contents(dirname(__DIR__,2).'/'.$path);
    foreach(['curl_exec(', 'new ModelExecutor(', 'file_get_contents(', 'public function delete(',
             'public function purge(', 'privateKey', 'unlink('] as $forbidden){
        Assert::false(str_contains($code,$forbidden),
            'IV01 graph/invalidation source has no external execution or raw erasure');
    }
}
