<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{HistoryReviewBasis,HistoryReviewBasisStore,PointInTimeHistoricalBasis};

$db=Harness::freshDb();
if($db===null){ Assert::skip(Harness::unavailableReason()); return; }
$path=dirname(__DIR__,2).'/docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql';
$statements=Migrator::splitStatements((string)file_get_contents($path));
Assert::same(6,count($statements),'existing family 270 remains two tables and four append-only triggers');
$installed=false;
try {
    foreach($statements as $sql) $db->pdo()->exec($sql);
    $installed=true;
    if(!defined('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED')){
        define('ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',true);
    }
    $clock=Harness::clock('2026-10-29T01:00:00.000Z');
    $owner=Seed::tenantWorkspace($db,$clock,'hs01-owner');
    $foreign=Seed::tenantWorkspace($db,$clock,'hs01-foreign');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'display_name'=>'HS01 synthetic competitor','competitor_class'=>'DIRECT',
        'status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=['tenant_id'=>$owner['tenant_id'],'workspace_id'=>$owner['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new()];
    $v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
        true,64,JSON_THROW_ON_ERROR);
    foreach($v['rows'] as &$row) foreach($scope as $k=>$id) $row[$k]=$id;
    unset($row);
    $oldInput=[
        'scope'=>$scope,'period'=>$v['period'],'baseline_period'=>$v['baseline_period'],
        'baseline_null_reason'=>null,'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,'coverage'=>$v['known_coverage'],
    ];
    $req=['calendar'=>PointInTimeHistoricalBasis::CALENDAR,'legacy_input'=>$oldInput,
        'previous_basis_digest'=>null,'review_at_utc'=>'2026-03-16T00:00:00Z'];
    $basis=PointInTimeHistoricalBasis::freeze($req);
    Assert::true($basis->isOk(),'new history basis constructed from synthetic owner metadata');
    $opened=HistoryReviewBasisStore::forDisposableTest($db,$clock);
    Assert::true($opened->isOk(),'existing 270 store remains test-only');
    if(!$opened->isOk()||!$basis->isOk())return;
    $store=$opened->value();
    $first=$store->recordCandidate($basis->value());
    Assert::true($first->isOk(),'point-in-time v2 stored in existing candidate 270 without migration');
    if(!$first->isOk())return;
    $basisId=$first->value()['history_review_basis_id'];
    Assert::same('DISPOSABLE_BASIS_RECORDED',$first->value()['action'],'new candidate appended once');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM history_review_basis_refs'),
        'typed selected references persisted with existing FK');
    $again=$store->recordCandidate($basis->value());
    Assert::same('EXACT_REPLAY_NO_WRITE',$again->value()['action'],'repeating same v2 record writes nothing');
    Assert::same($basisId,$again->value()['history_review_basis_id'],'exact frozen replay retains identity');
    $replay=$store->replayCandidate($scope,$basisId);
    Assert::true($replay->isOk(),'v2 manifest, selected refs and original digest replay from DB');
    Assert::same($basis->value()['basis_digest'],$replay->value()['manifest']['basis_digest'],
        'same original frozen candidate reappears byte-identically');
    Assert::same(3,count($replay->value()['manifest']['excluded_references']),
        'excluded metadata reasons are immutable in the parent JSON');
    Assert::same(false,$replay->value()['historical_reuse_admitted'],'candidate not U03 source-use evidence');

    $later=$req;
    $later['legacy_input']['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
    $later['review_at_utc']='2026-03-21T00:00:00Z';
    $later['previous_basis_digest']=$basis->value()['basis_digest'];
    $child=PointInTimeHistoricalBasis::freeze($later);
    Assert::true($child->isOk(),'later-known successor can be frozen independently');
    $added=$store->recordCandidate($child->value());
    Assert::true($added->isOk(),'new cutoff appends immutable successor not mutation');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM history_review_bases'),
        'new cutoff generates second stored candidate');
    Assert::same(5,(int)$db->scalar('SELECT COUNT(*) FROM history_review_basis_refs'),
        'old 2 refs plus successor 3 refs retained');
    Assert::true($store->replayCandidate($scope,$basisId)->isOk(),
        'parent remains replayable after later-known evidence arrives');

    $legacy=HistoryReviewBasis::freeze($oldInput);
    Assert::true($legacy->isOk(),'original LI-05 still constructs identical legacy candidate');
    $legacyRecord=$store->recordCandidate($legacy->value());
    Assert::true($legacyRecord->isOk(),'legacy reader/writer remains compatible with updated 270 store');
    Assert::true($store->replayCandidate($scope,$legacyRecord->value()['history_review_basis_id'])->isOk(),
        'old and new manifests coexist without rewriting old digest law');

    $wrong=$scope;$wrong['tenant_id']=$foreign['tenant_id'];
    $wrong['workspace_id']=$foreign['workspace_id'];
    Assert::same('LI05C_BASIS_NOT_FOUND',$store->replayCandidate($wrong,$basisId)->reasonCode(),
        'foreign scope refused indistinguishably from missing');
    $forged=$basis->value();$forged['historical_reuse_admitted']=true;
    Assert::same('LI05C_BASIS_MANIFEST_INVALID',$store->recordCandidate($forged)->reasonCode(),
        'no grant through candidate storage');
    try{
        $db->run('UPDATE history_review_bases SET candidate_state=? WHERE history_review_basis_id=?',
            ['ADMITTED',$basisId]);
        Assert::fail('append-only 270 UPDATE should fail');
    } catch(\PDOException){Assert::pass();}
    Assert::same(0,(int)$db->scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '270_%'"),
        'no native migration or owner schema approval was created');
} finally {
    if($installed){
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach(['history_review_basis_refs','history_review_bases'] as $table){
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
