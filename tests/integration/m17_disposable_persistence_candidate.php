<?php

declare(strict_types=1);

use Allfather\Loopia\Core\{Ids,Outcome};
use Allfather\Loopia\Db\Migrator;
use Allfather\Loopia\Substrate\M17\{InputSet,MemoryStore};
use Allfather\Loopia\Tests\{Assert,Harness,Li07aFixture,Seed};

require_once dirname(__DIR__).'/support/Li07aFixture.php';
$db=Harness::freshDb();
if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }

$path=dirname(__DIR__,2).'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql';
Assert::true(is_file($path),'proposal SQL exists outside runtime migrations');
$statements=Migrator::splitStatements((string)file_get_contents($path));
Assert::true(count($statements)>=21,'seven tables plus 14 append-only triggers are actually parsed');
$tables=['compression_lineage_links','temporal_memory_status_transitions','temporal_memories',
    'temporal_rollups','temporal_compression_runs','temporal_compression_profile_rules',
    'temporal_compression_profiles'];
$created=false;
try {
    foreach ($statements as $stmt) { $db->pdo()->exec($stmt); }
    $created=true;
    $present=(int)$db->scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name IN ('compression_lineage_links','temporal_memory_status_transitions',
        'temporal_memories','temporal_rollups','temporal_compression_runs',
        'temporal_compression_profile_rules','temporal_compression_profiles')");
    Assert::same(7,$present,'seven candidate families created only in disposable MariaDB');
    define('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',true);
    $clock=Harness::clock('2026-10-26T18:00:00.000Z');
    $a=Seed::tenantWorkspace($db,$clock,'li07b-a');
    $b=Seed::tenantWorkspace($db,$clock,'li07b-b');
    $competitorId=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitorId,'schema_version'=>'1','tenant_id'=>$a['tenant_id'],
        'workspace_id'=>$a['workspace_id'],'display_name'=>'Synthetic Competitor LI07B',
        'competitor_class'=>'DIRECT','status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=['tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'competitor_id'=>$competitorId,'question_id'=>Ids::new()];
    $inputs=Li07aFixture::inputs(null,$scope);
    $factory=MemoryStore::forDisposableTest($db,$clock);
    Assert::true($factory->isOk(),'only explicit disposable test bootstrap creates persistence harness');
    $store=$factory->value();
    $first=$store->persistCandidate($inputs);
    $reason=$first->isOk() ? 'OK' : $first->reasonCode();
    $sqlState=$first->isOk() ? '' : (string)($first->detail['sqlstate'] ?? '');
    $driverCode=$first->isOk() ? '' : (string)($first->detail['driver_code'] ?? '');
    $exceptionType=$first->isOk() ? '' : (string)($first->detail['exception_type'] ?? '');
    $exceptionFile=$first->isOk() ? '' : (string)($first->detail['exception_file'] ?? '');
    $exceptionLine=$first->isOk() ? '' : (string)($first->detail['exception_line'] ?? '');
    Assert::true($first->isOk(),'candidate writes to seven-family fixture schema'
        .' [result='.$reason.',sqlstate='.$sqlState.',dbcode='.$driverCode.',exception='.$exceptionType.',file='.$exceptionFile.',line='.$exceptionLine.']');
    if (!$first->isOk()) return;
    $v=$first->value();
    Assert::same('DISPOSABLE_CANDIDATE_RECORDED',$v['action'],'first input creates only a non-admitted candidate');
    Assert::same(false,$v['historical_reuse_admitted'],'persistence cannot manufacture source-use rights');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories'),'one initial candidate recorded');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM temporal_compression_runs'),'one immutable run');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM temporal_rollups'),'one immutable derived rollup');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM temporal_compression_profiles'),'one profile snapshot');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memory_status_transitions'),'append-only candidate event');
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM compression_lineage_links'),'full in-period lineage, not top-k');
    $again=$store->persistCandidate($inputs);
    Assert::true($again->isOk(),'repeat same input is idempotent');
    Assert::same('EXACT_REPLAY_NO_WRITE',$again->value()['action'],'repeated run creates zero rows');
    Assert::same($v['memory_id'],$again->value()['memory_id'],'same derivation resolves same memory id');
    Assert::same(1,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories'),'no duplicated memory row');
    $replay=$store->replayCandidate($scope,$v['memory_id']);
    Assert::true($replay->isOk(),'frozen metadata replay is available');
    Assert::same('MATCH_METADATA_ONLY',$replay->value()['outcome'],'matches are metadata-only, not M05 authentication');
    $otherScope=['tenant_id'=>$b['tenant_id'],'workspace_id'=>$b['workspace_id'],
        'competitor_id'=>$competitorId,'question_id'=>$scope['question_id']];
    Assert::same('M17_REPLAY_CANDIDATE_NOT_FOUND',$store->replayCandidate($otherScope,$v['memory_id'])->reasonCode(),
        'foreign tenant may not read or enumerate candidate');
    $alternate=$inputs;
    $alternate['input_set']['scope']=$otherScope;
    Assert::same('M17_ROLLUP_INPUT_INVALID',$store->persistCandidate($alternate)->reasonCode(),
        'foreign-scope substitution breaks frozen input integrity');
    // A new same-period event creates a true immutable successor, not a re-write.
    $extra=$inputs['input_set']['refs'][0];
    $extra['ref_id']='late-input-b';
    $extra['source_ref_id']='late-source-b';
    $extra['payload_sha256']=str_repeat('b',64);
    $refs=[...$inputs['input_set']['refs'],$extra];
    $new=InputSet::freeze(['scope'=>$scope,'data_class'=>$inputs['input_set']['data_class'],'refs'=>$refs]);
    Assert::true($new->isOk(),'late-input successor input set frozen');
    $successorInputs=$inputs;
    $successorInputs['input_set']=$new->value();
    $child=$store->persistCandidate($successorInputs,$v['memory_id']);
    Assert::true($child->isOk(),'late input stored as new run and linked candidate');
    Assert::notSame($v['memory_id'],$child->value()['memory_id'],'old immutable memory preserved');
    Assert::same(2,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories'),'both old/new memories remain');
    $head=$store->headCandidates($scope,$inputs['input_set']['data_class'],
        $inputs['window']['kind'],$inputs['window']['start_inclusive_utc'],
        $inputs['window']['end_exclusive_utc']);
    Assert::same('SINGLE_CANDIDATE_NOT_ADMITTED',$head->value()['status'],
        'one nonadmitted successor head cannot imply operational current memory');
    // A second distinct child of the original is an incomparable branch and must HOLD.
    $extra['ref_id']='late-input-c';
    $extra['payload_sha256']=str_repeat('c',64);
    $new2=InputSet::freeze(['scope'=>$scope,'data_class'=>$inputs['input_set']['data_class'],
        'refs'=>[...$inputs['input_set']['refs'],$extra]]);
    $forkInputs=$inputs;$forkInputs['input_set']=$new2->value();
    $fork=$store->persistCandidate($forkInputs,$v['memory_id']);
    Assert::true($fork->isOk(),'second incomparable successor preserved without LWW');
    Assert::same('AMBIGUOUS_HEAD_HOLD',$store->headCandidates($scope,$inputs['input_set']['data_class'],
        $inputs['window']['kind'],$inputs['window']['start_inclusive_utc'],
        $inputs['window']['end_exclusive_utc'])->value()['status'],'concurrent fork triggers visible ambiguity');
    Assert::same(3,(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories'),'forks retained alongside prior');
    Assert::same('M17_CANDIDATE_IDENTICAL_RUN_CONFLICT',
        $store->persistCandidate($successorInputs,null)->reasonCode(),
        'same run cannot silently change predecessor association');
    // Append-only storage is database-enforced, not just source convention.
    try {
        $db->run('UPDATE temporal_memories SET content_digest=? WHERE temporal_memory_id=?',
            [str_repeat('0',64),$v['memory_id']]);
        Assert::fail('immutable memory UPDATE was ACCEPTED');
    } catch (\PDOException $e) { Assert::pass(); }
    try {
        $db->run('DELETE FROM compression_lineage_links WHERE temporal_memory_id=?',[$v['memory_id']]);
        Assert::fail('lineage DELETE was ACCEPTED');
    } catch (\PDOException $e) { Assert::pass(); }
    Assert::same(0,(int)$db->scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_id='280_m17_temporal_memory.sql'"),
        'disposable proposal never entered the production migration ledger');
} finally {
    // CLEANUP IN DISPOSABLE QA ONLY. No DROP exists in the candidate design file or product code.
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($tables as $table) $db->pdo()->exec('DROP TABLE IF EXISTS `'.$table.'`');
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
}
