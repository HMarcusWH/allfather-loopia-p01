<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Migrator,Database};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{
    HistoryReviewBasis,HistoryReviewBasisStore,HistoryM17CandidateBridge
};
use Allfather\Loopia\Substrate\M17\MemoryStore;

$db=Harness::freshDb();
if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }
$root=dirname(__DIR__,2);
$proposals=[
    $root.'/docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql',
    $root.'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql',
];
$tables=['compression_lineage_links','temporal_memory_status_transitions',
    'temporal_memories','temporal_rollups','temporal_compression_runs',
    'temporal_compression_profile_rules','temporal_compression_profiles',
    'history_review_basis_refs','history_review_bases'];
$installed=false;
try {
    foreach($proposals as $path) {
        Assert::true(is_file($path),'unadopted 270/280 SQL stays outside production migrations');
        foreach(Migrator::splitStatements((string)file_get_contents($path)) as $statement) {
            $db->pdo()->exec($statement);
        }
    }
    $installed=true;
    if (!defined('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED')) define('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',true);
    if (!defined('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED')) define('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',true);
    $clock=Harness::clock('2026-10-26T18:00:00.000Z');
    $a=Seed::tenantWorkspace($db,$clock,'history-m17-a');
    $b=Seed::tenantWorkspace($db,$clock,'history-m17-b');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1','tenant_id'=>$a['tenant_id'],
        'workspace_id'=>$a['workspace_id'],'display_name'=>'Synthetic P01 M17',
        'competitor_class'=>'DIRECT','status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=['tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new()];
    $v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
        true,64,JSON_THROW_ON_ERROR);
    foreach($v['rows'] as &$row) foreach($scope as $field=>$id) $row[$field]=$id;
    unset($row);
    $basisInput=['scope'=>$scope,'period'=>$v['period'],
        'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
        'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,'coverage'=>$v['known_coverage']];
    $basisStore=HistoryReviewBasisStore::forDisposableTest($db,$clock)->value();
    $memoryStore=MemoryStore::forDisposableTest($db,$clock)->value();
    $persist=function(array $input,?string $parent=null)use($basisStore,$memoryStore):array {
        $basis=HistoryReviewBasis::freeze($input);
        Assert::true($basis->isOk(),'point-in-time metadata basis frozen');
        $stored=$basisStore->recordCandidate($basis->value());
        Assert::true($stored->isOk(),'unadopted 270 graph persisted via disposable store');
        $bridge=HistoryM17CandidateBridge::compose($basis->value(),'SOURCE_DIFF','MONTH');
        Assert::true($bridge->isOk(),'pure bounded M17 bridge composes metadata without rights');
        $memory=$memoryStore->persistCandidate($bridge->value()['frozen_m17_inputs'],$parent);
        Assert::true($memory->isOk(),'unadopted G21 graph stores complete offline rollup');
        if(!$memory->isOk()) throw new RuntimeException('synthetic M17 fixture rejected');
        return ['basis'=>$basis->value(),'basisStore'=>$stored->value(),
            'bridge'=>$bridge->value(),'memory'=>$memory->value()];
    };
    $first=$persist($basisInput);
    $again=$basisStore->recordCandidate($first['basis']);
    Assert::same('EXACT_REPLAY_NO_WRITE',$again->value()['action'],
        'same historical basis produces no duplicate row');
    $replay=$memoryStore->replayCandidate($scope,$first['memory']['memory_id']);
    Assert::same('MATCH_METADATA_ONLY',$replay->value()['outcome'],
        'metadata replay never implies M05 authentication');
    $late=$basisInput;$late['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
    $second=$persist($late,$first['memory']['memory_id']);
    Assert::notSame($first['memory']['memory_id'],$second['memory']['memory_id'],
        'late known-at evidence produces a distinct immutable memory successor');
    $fork=$late;
    $more=$fork['rows'][2];
    $more['ref_id']='ref_fork';$more['snapshot_id']='snapshot_fork';
    $more['evidence_id']='evidence_fork';$more['source_ref_id']='source_fork';
    $more['payload_sha256']=str_repeat('c',64);
    $more['known_at']='2026-03-17T00:00:00Z';
    $more['recorded_at']='2026-03-17T00:00:00Z';
    $fork['rows'][]=$more;
    $third=$persist($fork,$first['memory']['memory_id']);
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories'),
        'two distinct same-parent successors and old memory all retained');
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM history_review_bases'),
        'two successor bases and the original remain immutable');
    Assert::same('AMBIGUOUS_HEAD_HOLD',
        $memoryStore->headCandidates($scope,'SOURCE_DIFF','MONTH',
            $first['bridge']['frozen_m17_inputs']['window']['start_inclusive_utc'],
            $first['bridge']['frozen_m17_inputs']['window']['end_exclusive_utc'])->value()['status'],
        'same-period branching never silently resolves to the newest run');
    $foreign=$scope; $foreign['tenant_id']=$b['tenant_id'];$foreign['workspace_id']=$b['workspace_id'];
    Assert::same('LI05C_BASIS_NOT_FOUND',$basisStore->replayCandidate(
        $foreign,$first['basisStore']['history_review_basis_id'])->reasonCode(),
        'foreign tenant denied basis readback');
    Assert::same('M17_REPLAY_CANDIDATE_NOT_FOUND',
        $memoryStore->replayCandidate($foreign,$first['memory']['memory_id'])->reasonCode(),
        'foreign tenant denied M17 memory readback');
    Assert::same(false,$third['bridge']['historical_reuse_admitted'],
        'even a fully connected synthetic graph has no historical-use permission');
    Assert::same(0,(int)$db->scalar("SELECT COUNT(*) FROM schema_migrations
        WHERE migration_id LIKE '27%' OR migration_id LIKE '28%'"),
        'candidate G21 and source-basis tables never joined the live migration ledger');
} finally {
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $table) $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
