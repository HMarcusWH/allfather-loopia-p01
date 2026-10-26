<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Migrator;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{HistoryReviewBasis,HistoryReviewBasisStore};

$db=Harness::freshDb();
if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }
$path=dirname(__DIR__,2).'/docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql';
Assert::true(is_file($path),'unadopted 270 schema is outside runtime migrations');
$sql=Migrator::splitStatements((string)file_get_contents($path));
Assert::same(6,count($sql),'two history tables and four append-only triggers parsed');
$tables=['history_review_basis_refs','history_review_bases'];
$installed=false;
try {
    foreach($sql as $statement) $db->pdo()->exec($statement);
    $installed=true;
    Assert::same(4,(int)$db->scalar("SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'history_%_no_%'"),
        'four history append-only triggers installed in disposable MariaDB');
    if (!defined('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED')) {
        define('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',true);
    }
    $clock=Harness::clock('2026-10-26T18:00:00.000Z');
    $a=Seed::tenantWorkspace($db,$clock,'basis-a');
    $b=Seed::tenantWorkspace($db,$clock,'basis-b');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'display_name'=>'Synthetic P01 History Basis','competitor_class'=>'DIRECT',
        'status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=['tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new()];
    $v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
        true,64,JSON_THROW_ON_ERROR);
    $v['scope']=$scope;
    foreach($v['rows'] as &$ref) foreach($scope as $field=>$id) $ref[$field]=$id;
    unset($ref);
    $input=[
        'scope'=>$scope,'period'=>$v['period'],'baseline_period'=>$v['baseline_period'],
        'baseline_null_reason'=>null,'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,'coverage'=>$v['known_coverage'],
    ];
    $candidate=HistoryReviewBasis::freeze($input);
    Assert::true($candidate->isOk(),'synthetic history metadata candidate frozen');
    $factory=HistoryReviewBasisStore::forDisposableTest($db,$clock);
    Assert::true($factory->isOk(),'test-only store bootstrap accepted in disposable database');
    $store=$factory->value();
    $first=$store->recordCandidate($candidate->value());
    Assert::true($first->isOk(),'metadata-only history candidate persisted with append-only refs');
    if (!$first->isOk()) return;
    $basisId=$first->value()['history_review_basis_id'];
    Assert::same('DISPOSABLE_BASIS_RECORDED',$first->value()['action'],
        'first candidate writes no source bytes or permission');
    Assert::same(false,$first->value()['historical_reuse_admitted'],
        'M05 source-use rights still not adopted');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM history_review_bases'),
        'one candidate basis created');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM history_review_basis_refs'),
        'complete two-ref primary lineage captured');
    $again=$store->recordCandidate($candidate->value());
    Assert::same('EXACT_REPLAY_NO_WRITE',$again->value()['action'],
        'exact repeated basis cannot create duplicate source refs');
    Assert::same($basisId,$again->value()['history_review_basis_id'],
        'identity stabilizes independently of caller repetition');
    $replay=$store->replayCandidate($scope,$basisId);
    Assert::true($replay->isOk(),'stored manifest and ref graph independently verified');
    Assert::same('MATCH_METADATA_ONLY',$replay->value()['integrity'],
        'replay is metadata integrity, not U03 eligibility');
    $foreign=$scope; $foreign['tenant_id']=$b['tenant_id'];
    $foreign['workspace_id']=$b['workspace_id'];
    Assert::same('LI05C_BASIS_NOT_FOUND',
        $store->replayCandidate($foreign,$basisId)->reasonCode(),
        'foreign workspace cannot inspect basis or distinguish it from missing');
    $late=$input;$late['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
    $successor=HistoryReviewBasis::freeze($late);
    Assert::true($successor->isOk(),'late knowledge produces distinct point-in-time basis');
    $child=$store->recordCandidate($successor->value());
    Assert::true($child->isOk(),'late basis stored as new immutable row');
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM history_review_basis_refs'),
        'late successor gains a third ref and old two remain');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM history_review_bases'),
        'late input never mutates the previous basis');
    $tampered=$candidate->value();$tampered['historical_reuse_admitted']=true;
    Assert::same('LI05C_BASIS_MANIFEST_INVALID',
        $store->recordCandidate($tampered)->reasonCode(),
        'caller cannot confer legal use by toggling a metadata flag');
    try{$db->run('UPDATE history_review_bases SET candidate_state=? WHERE history_review_basis_id=?',
        ['ADMITTED',$basisId]);Assert::fail('UPDATE bypassed append-only basis law');}
    catch(\PDOException){Assert::pass();}
    try{$db->run('DELETE FROM history_review_basis_refs WHERE history_review_basis_id=?',[$basisId]);
        Assert::fail('DELETE bypassed lineage append-only law');}
    catch(\PDOException){Assert::pass();}
    Assert::same(0,(int)$db->scalar("SELECT COUNT(*) FROM schema_migrations
        WHERE migration_id LIKE '270_history_review_bases%'"),
        'unadopted schema never enters runtime migration ledger');
} finally {
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach($tables as $t) $db->pdo()->exec('DROP TABLE IF EXISTS '.\Allfather\Loopia\Db\Database::identifier($t));
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
