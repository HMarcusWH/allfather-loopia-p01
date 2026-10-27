<?php

declare(strict_types=1);

/**
 * R3: a DISPOSABLE ONLY complete 270/280/290 populated candidate recovery.
 *
 * Deliberately NOT a production migration, mysqldump, Railway volume restore,
 * native M05 bytes restoration, schema adoption, provider grant or runtime GO.
 * All identities, rights and observations in this test are synthetic.
 */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Substrate\M17\{MemoryStore,ReviewDue};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{
    HistoryReviewBasis,HistoryReviewBasisStore,
    HistoryM17CandidateBridge,HistoryReviewDueStore,
};

$db=Harness::freshDb();
if($db===null){Assert::skip(Harness::unavailableReason());return;}
$root=dirname(__DIR__,2);
$paths=[
    $root.'/docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql',
    $root.'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql',
    $root.'/docs/longitudinal/proposals/290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql',
];
$keys=[
    'history_review_bases'=>'history_review_basis_id',
    'history_review_basis_refs'=>'history_review_basis_ref_id',
    'temporal_compression_profiles'=>'profile_id',
    'temporal_compression_profile_rules'=>'profile_rule_id',
    'temporal_compression_runs'=>'compression_run_id',
    'temporal_rollups'=>'temporal_rollup_id',
    'temporal_memories'=>'temporal_memory_id',
    'temporal_memory_status_transitions'=>'temporal_memory_status_transition_id',
    'compression_lineage_links'=>'compression_lineage_link_id',
    'history_review_policies'=>'history_review_policy_id',
    'history_review_policy_events'=>'history_review_policy_event_id',
    'history_review_due_checks'=>'history_review_due_check_id',
];
$tables=array_keys($keys);
$statements=[];
foreach($paths as $path){
    Assert::true(is_file($path),'each candidate SQL is outside live migration directory');
    $statements[]=Migrator::splitStatements((string)file_get_contents($path));
}
Assert::same([6,21,9],array_map('count',$statements),
    'candidate chain is 2+7+3 tables with 4+14+6 immutable triggers');
$view=static function(Database $db)use($keys):array{
    $snapshot=[];
    foreach($keys as $table=>$key){
        $snapshot[$table]=$db->all('SELECT * FROM '.Database::identifier($table).
            ' ORDER BY '.Database::identifier($key));
    }
    return $snapshot;
};
$reject=static function(callable $f,string $label):void{
    try {$f(); Assert::fail($label.' unexpectedly accepted');}
    catch(\PDOException) {Assert::pass();}
};
$baseMigrations=$db->all('SELECT migration_id,content_digest,statement_count
    FROM schema_migrations ORDER BY migration_id');
Assert::true(count($baseMigrations)>0,'existing P01 base schema populated before candidate DDL');

try {
    // A CI interruption AFTER first DDL creates an incomplete schema.
    $db->pdo()->exec($statements[0][0]);
    Assert::same(1,(int)$db->scalar("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='history_review_bases'"),
        'a partial DDL execution can leave one new table on MariaDB');
    Assert::same(0,(int)$db->scalar("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='history_review_basis_refs'"),
        'interrupted install must not falsely claim complete 270');
    // An exact retry is idempotent for the first table and completes all 36
    // independent DDL statements. It does not add a migration ledger entry.
    foreach($statements as $set)foreach($set as $ddl)$db->pdo()->exec($ddl);
    foreach(['ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',
             'ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',
             'ALLFATHER_LI08C_DISPOSABLE_SCHEMA_LOADED'] as $key) {
        if(!defined($key))define($key,true);
    }
    Assert::same(12,(int)$db->scalar('SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE()
          AND table_name IN ('.implode(',',array_fill(0,count($tables),'?')).')',$tables),
        'partial-install repair yields all twelve G21/LI05/U05 candidate tables');
    $triggerCount=static fn():int=>(int)$db->scalar("SELECT COUNT(*) FROM information_schema.triggers
        WHERE trigger_schema=DATABASE()
          AND (trigger_name LIKE 'history_%_no_%' OR trigger_name LIKE 'm17_%_no_%')");
    Assert::same(24,$triggerCount(),'all 24 server-side append-only triggers installed');
    $clock=Harness::clock('2026-10-26T18:00:00.000Z');
    $a=Seed::tenantWorkspace($db,$clock,'r3-populated-a');
    $b=Seed::tenantWorkspace($db,$clock,'r3-populated-b');
    $competitor=Ids::new();
    $db->insert('competitors',[
        'competitor_id'=>$competitor,'schema_version'=>'1',
        'tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'display_name'=>'Synthetic R3 recovery competitor',
        'competitor_class'=>'DIRECT','status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope=[
        'tenant_id'=>$a['tenant_id'],'workspace_id'=>$a['workspace_id'],
        'competitor_id'=>$competitor,'question_id'=>Ids::new(),
    ];
    $seed=json_decode((string)file_get_contents(dirname(__DIR__).
        '/fixtures/li05_offline_basis_vectors.json'),true,64,JSON_THROW_ON_ERROR);
    foreach($seed['rows'] as &$row){
        foreach($scope as $dimension=>$value)$row[$dimension]=$value;
    }
    unset($row);
    $input=[
        'scope'=>$scope,'period'=>$seed['period'],
        'baseline_period'=>$seed['baseline_period'],'baseline_null_reason'=>null,
        'knowledge_cutoff_utc'=>$seed['knowledge_cutoff_utc'],
        'rows'=>$seed['rows'],'max_references'=>8,
        'coverage'=>$seed['known_coverage'],
    ];
    $basisStore=HistoryReviewBasisStore::forDisposableTest($db,$clock);
    $memoryStore=MemoryStore::forDisposableTest($db,$clock);
    $dueStore=HistoryReviewDueStore::forDisposableTest($db,$clock);
    Assert::true($basisStore->isOk() && $memoryStore->isOk() && $dueStore->isOk(),
        'all three writers are disposable/CLI-only');
    $basisStore=$basisStore->value();$memoryStore=$memoryStore->value();
    $dueStore=$dueStore->value();

    $record=static function(array $input,?string $parent=null)
        use($basisStore,$memoryStore):array{
        $basis=HistoryReviewBasis::freeze($input);
        Assert::true($basis->isOk(),'bounded synthetic basis freezes');
        if(!$basis->isOk())throw new \RuntimeException('LI05 basis unavailable');
        $stored=$basisStore->recordCandidate($basis->value());
        Assert::true($stored->isOk(),'immutable synthetic owner metadata persisted');
        if(!$stored->isOk())throw new \RuntimeException('270 owner metadata unavailable');
        $bridge=HistoryM17CandidateBridge::compose($basis->value(),'SOURCE_DIFF','MONTH');
        Assert::true($bridge->isOk(),'same monthly input/profile identity remains deterministic');
        if(!$bridge->isOk())throw new \RuntimeException('280 bridge unavailable');
        $m=$memoryStore->persistCandidate($bridge->value()['frozen_m17_inputs'],$parent);
        Assert::true($m->isOk(),'M17 rollup and lineage persisted');
        if(!$m->isOk())throw new \RuntimeException('280 candidate persistence refused: '.$m->reasonCode());
        return ['basis'=>$basis->value(),'stored'=>$stored->value(),'memory'=>$m->value(),
                'bridge'=>$bridge->value()];
    };
    $initial=$record($input);
    $late=$input;$late['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
    $successor=$record($late,$initial['memory']['memory_id']);
    $fork=$late;
    $extra=$fork['rows'][2];
    $extra['ref_id']='r3-fork-ref';$extra['snapshot_id']='r3-fork-snapshot';
    $extra['evidence_id']='r3-fork-evidence';$extra['source_ref_id']='r3-fork-source';
    $extra['payload_sha256']=str_repeat('d',64);
    $extra['known_at']='2026-03-17T00:00:00Z';
    $extra['recorded_at']='2026-03-17T00:00:00Z';
    $fork['rows'][]=$extra;
    $forked=$record($fork,$initial['memory']['memory_id']);
    $profileId=(string)$db->scalar(
        'SELECT profile_id FROM temporal_compression_profiles LIMIT 1');
    $db->insert('temporal_compression_profile_rules',[
        'profile_rule_id'=>Ids::new(),'profile_id'=>$profileId,
        'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
        'ordinal_no'=>0,'rule_digest'=>str_repeat('b',64),
        'rule_json'=>'{"r3_synthetic_only":true}','created_at'=>$clock->now(),
    ]);
    $policy=[
        'version'=>ReviewDue::VERSION,'cadence_seconds'=>86400,
        'maximum_wait_seconds'=>604800,'cooldown_seconds'=>3600,'max_pending_work'=>3,
    ];
    $policyRecord=$dueStore->recordPolicyCandidate($scope,$policy);
    Assert::true($policyRecord->isOk(),'versioned review policy candidate persisted');
    $policyId=$policyRecord->value()['history_review_policy_id'];
    $state=[
        'last_considered_basis_digest'=>null,'last_considered_at_utc'=>null,
        'last_queued_at_utc'=>null,'pending_work'=>0,'source_eligibility'=>'NOT_ASSESSED',
    ];
    foreach([$initial,$successor,$forked] as $i=>$item){
        $out=$dueStore->recordDueCandidate($scope,$policyId,
            $item['stored']['history_review_basis_id'],$state,
            sprintf('2026-10-26T18:00:%02dZ',$i));
        Assert::true($out->isOk(),'each frozen basis generates distinct immutable due metadata');
    }
    $before=$view($db);
    Assert::same(12,count($before),'all twelve candidate table families exported');
    foreach($before as $name=>$data){
        Assert::true(count($data)>0,'populated candidate recovery includes '.$name);
    }
    Assert::same(3,count($before['history_review_bases']),'original and forked basis all retained');
    Assert::same(3,count($before['temporal_memories']),'original and forked M17 memories retained');
    Assert::same(3,count($before['history_review_due_checks']),'three independent review evaluations retained');
    Assert::same('AMBIGUOUS_HEAD_HOLD',$memoryStore->headCandidates(
        $scope,'SOURCE_DIFF','MONTH',
        $initial['bridge']['frozen_m17_inputs']['window']['start_inclusive_utc'],
        $initial['bridge']['frozen_m17_inputs']['window']['end_exclusive_utc'],
    )->value()['status'],'multiple incomparable late-input successors remain visible');
    Assert::same($baseMigrations,$db->all(
        'SELECT migration_id,content_digest,statement_count
         FROM schema_migrations ORDER BY migration_id'),
        'installed candidate schema does not alter original migration history');

    // Re-run all CREATE TABLE IF NOT EXISTS on a database with actual data.
    foreach($statements as $set)foreach($set as $stmt){
        if(preg_match('/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i',$stmt)===1)
            $db->pdo()->exec($stmt);
    }
    Assert::same($before,$view($db),'populated no-op DDL changes none of the twelve candidate families');

    // Test append-only triggers on the database, not just mocked PHP guards.
    foreach($keys as $table=>$pk){
        $firstRow=$before[$table][0];$id=$firstRow[$pk];
        $reject(static fn()=> $db->run(
            'UPDATE '.Database::identifier($table).' SET '.Database::identifier($pk).
            '=? WHERE '.Database::identifier($pk).'=?',[$id,$id]),
            $table.' server-side immutable UPDATE');
        $reject(static fn()=> $db->run(
            'DELETE FROM '.Database::identifier($table).' WHERE '.Database::identifier($pk).'=?',[$id]),
            $table.' server-side immutable DELETE');
    }
    $second=Harness::secondConnection();
    Assert::true($second!==null,'independent DB connection available for negative FK/replay');
    if($second!==null){
        $foreign=$before['history_review_due_checks'][0];
        $foreign['history_review_due_check_id']=Ids::new();
        $foreign['tenant_id']=$b['tenant_id'];
        $foreign['workspace_id']=$b['workspace_id'];
        $reject(static fn()=> $second->insert('history_review_due_checks',$foreign),
            'foreign tenant cannot link to original due basis/policy');
        $duplicate=$before['history_review_due_checks'][0];
        $duplicate['history_review_due_check_id']=Ids::new();
        $reject(static fn()=> $second->insert('history_review_due_checks',$duplicate),
            'repeated due policy, basis and clock must be unique across connections');
    }

    // Logical export -> DROP only disposable candidate tables -> recreated DDL
    // -> restore in explicit FK/topological order. NEVER a Railway backup claim.
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        foreach(array_reverse($tables) as $table)
            $db->pdo()->exec('DROP TABLE '.Database::identifier($table));
    }finally{$db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');}
    foreach($statements as $set)foreach($set as $stmt)$db->pdo()->exec($stmt);
    foreach($keys as $table=>$pk){
        if($table==='temporal_memories'){
            $remaining=$before[$table];$known=[];
            while($remaining!==[]){
                $next=[];$progress=0;
                foreach($remaining as $data){
                    $parent=$data['supersedes_memory_id'];
                    if($parent===null||isset($known[$parent])){
                        $db->insert($table,$data);
                        $known[$data['temporal_memory_id']]=true;$progress++;
                    }else $next[]=$data;
                }
                if($progress===0)throw new \RuntimeException('R3 restoration cycle in memory ancestry');
                $remaining=$next;
            }
            continue;
        }
        foreach($before[$table] as $data)$db->insert($table,$data);
    }
    Assert::same($before,$view($db),
        'logical rebuild preserves exact bytes and rows across twelve table families');
    Assert::same(24,$triggerCount(),'all 24 immutable triggers survive logical rebuild');
    Assert::same($baseMigrations,$db->all(
        'SELECT migration_id,content_digest,statement_count
         FROM schema_migrations ORDER BY migration_id'),
        'base schema ledger survives candidate-only logical rebuild');
    Assert::same('MATCH_METADATA_ONLY',$basisStore->replayCandidate(
        $scope,$initial['stored']['history_review_basis_id'])->value()['integrity'],
        'historical basis digests and refs replay after restore without M05 reuse');
    Assert::same('MATCH_METADATA_ONLY',$memoryStore->replayCandidate(
        $scope,$initial['memory']['memory_id'])->value()['outcome'],
        'M17 derived rollup replay remains metadata-only after restore');
    Assert::same('AMBIGUOUS_HEAD_HOLD',$memoryStore->headCandidates(
        $scope,'SOURCE_DIFF','MONTH',
        $initial['bridge']['frozen_m17_inputs']['window']['start_inclusive_utc'],
        $initial['bridge']['frozen_m17_inputs']['window']['end_exclusive_utc'],
    )->value()['status'],'two competing late-input successors survive restored DB');
    Assert::same('EXACT_REPLAY_NO_WRITE',$basisStore->recordCandidate(
        $initial['basis'])->value()['action'],
        'restored source-basis identity never mints a duplicate row');
    Assert::same('EXACT_REPLAY_NO_WRITE',$memoryStore->persistCandidate(
        $initial['bridge']['frozen_m17_inputs'])->value()['action'],
        'restored M17 run identity never mints a duplicate row');
    Assert::same('EXACT_POLICY_REPLAY_NO_WRITE',$dueStore->recordPolicyCandidate(
        $scope,$policy)->value()['action'],
        'restored due policy identity never mints duplicate owner state');
    Assert::same(3,count($dueStore->listDueCandidates($scope,20)->value()['rows']),
        'all three review candidates survive logical rebuild');
    $foreignScope=$scope;$foreignScope['tenant_id']=$b['tenant_id'];
    $foreignScope['workspace_id']=$b['workspace_id'];
    Assert::same('LI05C_BASIS_NOT_FOUND',$basisStore->replayCandidate(
        $foreignScope,$initial['stored']['history_review_basis_id'])->reasonCode(),
        'cross-tenant restored history cannot disclose candidate refs');
    Assert::same(0,count($dueStore->listDueCandidates($foreignScope,20)->value()['rows']),
        'cross-tenant restored review ledger is empty');
    Assert::same(false,$memoryStore->replayCandidate(
        $scope,$initial['memory']['memory_id'])->value()['source_bytes_verified'],
        'logical restore is NOT proof of private source bytes, licence or authority');
    $bad=$initial['basis'];$bad['historical_reuse_admitted']=true;
    Assert::same('LI05C_BASIS_MANIFEST_INVALID',
        $basisStore->recordCandidate($bad)->reasonCode(),
        'candidate schema cannot mint historical rights after recovery');
}finally {
    // All tables were created only in disposable freshDb() CI test schema.
    // CLEANUP SHALL NEVER target operational DBs or the migration ledger.
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        foreach(array_reverse($tables) as $table)
            $db->pdo()->exec('DROP TABLE IF EXISTS '.Database::identifier($table));
    }finally{$db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');}
}
