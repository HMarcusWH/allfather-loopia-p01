<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{HistoryDependencyIndex,HistoryInvalidation};

$scope=[
    'tenant_id'=>'iv01_tenant','workspace_id'=>'iv01_workspace',
    'competitor_id'=>'iv01_competitor','question_id'=>'iv01_question',
];
$kinds=[
    'capture'=>'CAPTURE','section'=>'SECTION_DERIVATIVE',
    'assessment'=>'ASSESSMENT','basis'=>'HISTORICAL_BASIS',
    'memory'=>'TEMPORAL_MEMORY','analysis'=>'ANALYSIS',
    'finding'=>'FINDING','attention'=>'ATTENTION',
];
$nodes=[];
foreach($kinds as $id=>$kind){
    $nodes[]=['node_id'=>$id,'kind'=>$kind,
        'owner_ref_digest'=>hash('sha256','synthetic-owner:'.$id)];
}
$ids=array_keys($kinds);
$links=[];
for($i=0;$i<count($ids)-1;$i++){
    $links[]=[
        'parent_id'=>$ids[$i],'child_id'=>$ids[$i+1],
        'relation'=>'DERIVED_FROM',
    ];
}
$input=[
    'scope'=>$scope,'catalog_cutoff_utc'=>'2026-10-29T00:00:00Z',
    'nodes'=>$nodes,'links'=>$links,
];
$graph=HistoryDependencyIndex::freeze($input);
Assert::true($graph->isOk(),'typed capture→attention dependency graph candidate built');
if(!$graph->isOk())return;
$g=$graph->value();
Assert::same('CANDIDATE_NOT_OWNER_VERIFIED',$g['status'],
    'synthetic links do not establish M05 owner source authority');
Assert::same(8,$g['node_count'],'all eight lineage families retained');
Assert::same(7,$g['link_count'],'seven typed derivative edges');
Assert::same(false,$g['cycle_detected'],'normal synthetic owner graph is a DAG');
Assert::same(false,$g['history_source_use_admitted'],'graph cannot grant U03');
Assert::true(HistoryDependencyIndex::verify($g)->isOk(),
    'candidate graph identity and deterministic sorting replay');
$reversed=$input;
$reversed['nodes']=array_reverse($nodes);
$reversed['links']=array_reverse($links);
Assert::same($g['graph_digest'],
    HistoryDependencyIndex::freeze($reversed)->value()['graph_digest'],
    'caller edge/node ordering cannot change graph identity');
$cause=HistoryInvalidation::freezeCause([
    'cause_kind'=>'SOURCE_RIGHTS_REVOKED',
    'root_node_id'=>'capture',
    'owner_decision_ref_safe'=>'synthetic_revocation_1',
    'owner_evidence_digest'=>hash('sha256','synthetic-capture'),
    'recorded_at_utc'=>'2026-10-29T00:01:00Z',
    'owner_cause_authenticated'=>false,
]);
Assert::true($cause->isOk(),'synthetic rights withdrawal has an immutable typed cause');
if(!$cause->isOk())return;
$cc=$cause->value();
Assert::true(HistoryInvalidation::verifyCause($cc)->isOk(),
    'cause hash is deterministic but explicitly not owner authenticated');
$req=[
    'graph'=>$g,'cause'=>$cc,'evaluated_at_utc'=>'2026-10-29T00:02:00Z',
    'batch_size'=>3,'prior_receipt'=>null,
];
$first=HistoryInvalidation::plan($req);
Assert::true($first->isOk(),'bounded first invalidation batch calculated');
if(!$first->isOk())return;
$f=$first->value();
Assert::same('PROPAGATION_INCOMPLETE_HOLD',$f['status'],
    'first batch cannot pretend to have scanned all dependencies');
Assert::same(['capture','section','assessment'],$f['affected_in_batch'],
    'owner root and first descendants included in deterministic BFS');
Assert::same(3,$f['next_cursor'],'bounded continuation points to next member');
Assert::same(8,$f['affected_total'],'every downstream derived object affected');
Assert::same(true,$f['synchronous_reliance_hold'],
    'new uses must HOLD before propagation finishes');
$next=$req;
$next['prior_receipt']=$f;
$second=HistoryInvalidation::plan($next);
Assert::true($second->isOk(),'the exact prior receipt permits bounded continuation');
Assert::same(6,$second->value()['next_cursor'],'no member is silently skipped');
$third=$req;$third['prior_receipt']=$second->value();
$final=HistoryInvalidation::plan($third);
Assert::true($final->isOk(),'last bounded batch calculated');
Assert::same('COMPLETE_CANDIDATE_HOLD',$final->value()['status'],
    'complete candidate remains unauthorised for source/customer use');
Assert::same(null,$final->value()['next_cursor'],'final chunk is terminal');
Assert::same(['finding','attention'],$final->value()['affected_in_batch'],
    'trailing human/customer attention objects included');
Assert::true(HistoryInvalidation::verifyReceipt($final->value())->isOk(),
    'final immutable receipt has consistent digest');
$repeat=HistoryInvalidation::plan($third);
Assert::same($final->value()['receipt_digest'],$repeat->value()['receipt_digest'],
    'exact same continuation replay is deterministic');
Assert::same('AFFECTED_BY_INCOMPLETE_INVALIDATION',
    HistoryInvalidation::inspectReliance($g,$cc,'finding')->value()['reason'],
    'synchronous read holds downstream finding before processing is completed');
Assert::same(false,HistoryInvalidation::inspectReliance($g,$cc,'attention')
    ->value()['source_use_authorized'],'no history read can mint rights');
$skipped=$req;$skipped['prior_receipt']=$f;
$skipped['prior_receipt']['next_cursor']=7;
Assert::same('IV01_RESUME_RECEIPT_INVALID',
    HistoryInvalidation::plan($skipped)->reasonCode(),
    'caller cannot invent a progress cursor to bypass descendants');
$future=$req;$future['evaluated_at_utc']='2026-10-28T23:59:59Z';
Assert::same('IV01_CAUSE_FROM_FUTURE',
    HistoryInvalidation::plan($future)->reasonCode(),
    'invalidation cause cannot predate actual evidence arrival');
$cycle=[
    'scope'=>$scope,'catalog_cutoff_utc'=>'2026-10-29T00:00:00Z',
    'nodes'=>[
        ['node_id'=>'basis1','kind'=>'HISTORICAL_BASIS','owner_ref_digest'=>str_repeat('a',64)],
        ['node_id'=>'basis2','kind'=>'HISTORICAL_BASIS','owner_ref_digest'=>str_repeat('b',64)],
    ],
    'links'=>[
        ['parent_id'=>'basis1','child_id'=>'basis2','relation'=>'SUCCESSOR_OF'],
        ['parent_id'=>'basis2','child_id'=>'basis1','relation'=>'SUCCESSOR_OF'],
    ],
];
$cycleGraph=HistoryDependencyIndex::freeze($cycle);
Assert::same('HOLD_CYCLE',$cycleGraph->value()['status'],
    'same-kind cyclic successor provenance cannot recurse');
$cycled=HistoryInvalidation::freezeCause([
    'cause_kind'=>'ASSESSMENT_SUPERSEDED',
    'root_node_id'=>'basis1',
    'owner_decision_ref_safe'=>'synthetic_cycle',
    'owner_evidence_digest'=>str_repeat('a',64),
    'recorded_at_utc'=>'2026-10-29T00:01:00Z',
    'owner_cause_authenticated'=>false,
])->value();
$held=HistoryInvalidation::plan([
    'graph'=>$cycleGraph->value(),'cause'=>$cycled,
    'evaluated_at_utc'=>'2026-10-29T00:02:00Z',
    'batch_size'=>3,'prior_receipt'=>null,
]);
Assert::same('HOLD_CYCLE',$held->value()['status'],
    'cycles produce a finite conservative HOLD, not recursion or success');
$fanout=[
    'scope'=>$scope,'catalog_cutoff_utc'=>'2026-10-29T00:00:00Z',
    'nodes'=>[['node_id'=>'root','kind'=>'CAPTURE','owner_ref_digest'=>str_repeat('c',64)]],
    'links'=>[],
];
for($i=0;$i<HistoryDependencyIndex::MAX_FANOUT+1;$i++){
    $id='sec_'.$i;
    $fanout['nodes'][]=['node_id'=>$id,'kind'=>'SECTION_DERIVATIVE','owner_ref_digest'=>str_repeat('d',64)];
    $fanout['links'][]=['parent_id'=>'root','child_id'=>$id,'relation'=>'DERIVED_FROM'];
}
$wide=HistoryDependencyIndex::freeze($fanout);
Assert::same('HOLD_EXCESS_FANOUT',$wide->value()['status'],
    'large fan-out is bounded and recorded as HOLD');
