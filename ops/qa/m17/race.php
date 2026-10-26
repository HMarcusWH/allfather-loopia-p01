<?php

declare(strict_types=1);

/**
 * PR #87: isolated, real process/connection races against the unadopted M17 fixture.
 * All inputs are synthetic. No staging DB, model call, fetch, authorization or migration.
 */
require_once __DIR__.'/qa.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M17\MemoryStore;

try {
    $db = M17Qa::db();
    if (!function_exists('proc_open') || !function_exists('proc_terminate')) M17Qa::fail('PHP_PROCESS_CONTROL_UNAVAILABLE');
    $out = $argv[1] ?? '';
    M17Qa::requireTrue($out !== '' && !file_exists($out), 'RACE_RECEIPT_PATH_REQUIRED');
    $work = sys_get_temp_dir().'/m17-qa-race-'.bin2hex(random_bytes(8));
    M17Qa::requireTrue(mkdir($work, 0700), 'PRIVATE_RACE_DIRECTORY_FAILED');
    $receipt = ['status'=>'NOT_RUN','source'=>'M17_CANDIDATE_ONLY','db_version'=>M17Qa::version($db),
        'runner'=>getenv('GITHUB_ACTIONS')==='true'?'GITHUB_ACTIONS':'LOCAL_DOCKER',
        'cases'=>[],'U04'=>'NOT_RATIFIED','U03'=>'NOT_ADOPTED','Railway'=>'NOT_TESTED'];
    M17Qa::file($out, $receipt);
    $cleanup = static function () use ($work): void {
        foreach (glob($work.'/*') ?: [] as $item) if (is_file($item)) unlink($item);
        rmdir($work);
    };

    M17Qa::installOverlapTrigger($db);

    $runPair = static function (string $scenario, array $inputs, ?string $prior, array $secondInputs, ?string $secondPrior)
        use ($db,$work,&$receipt,$out): array {
        $name = $scenario.'-'.bin2hex(random_bytes(4));
        $cases = [
            ['inputs'=>$inputs,'prior'=>$prior],
            ['inputs'=>$secondInputs,'prior'=>$secondPrior],
        ];
        $processes = [];
        $files = [];
        for ($i=0;$i<2;$i++) {
            $prefix = $work.'/'.$name.'-'.$i;
            $caseFile=$prefix.'.case';
            $ready=$prefix.'.ready'; $done=$prefix.'.done';
            M17Qa::file($caseFile, $cases[$i]);
            $cmd=[PHP_BINARY,__DIR__.'/qa.php','race-worker',$caseFile,$ready,$work.'/'.$name.'.go',$done];
            $spec=[0=>['pipe','r'],1=>['file',$prefix.'.stdout','w'],2=>['file',$prefix.'.stderr','w']];
            $p=proc_open($cmd,$spec,$pipes, dirname(__DIR__,3));
            M17Qa::requireTrue(is_resource($p),'RACE_CHILD_NOT_STARTED');
            fclose($pipes[0]);
            $processes[]=$p;
            $files[]=['ready'=>$ready,'done'=>$done,'prefix'=>$prefix];
        }
        $readyIds=[];
        $end=microtime(true)+15;
        while (microtime(true)<$end) {
            $all=true;
            foreach ($files as $file) $all=$all && is_file($file['ready']);
            if ($all) break;
            foreach ($processes as $p) {
                $s=proc_get_status($p);
                M17Qa::requireTrue($s['running'], 'RACE_WORKER_EXITED_BEFORE_BARRIER');
            }
            usleep(30000);
        }
        foreach ($files as $file) {
            M17Qa::requireTrue(is_file($file['ready']), 'RACE_BARRIER_NEVER_FILLED');
            $readyIds[]=(int)M17Qa::json($file['ready'])['connection_id'];
        }
        M17Qa::requireTrue(count(array_unique($readyIds))===2, 'RACE_CONNECTIONS_NOT_INDEPENDENT');
        M17Qa::file($work.'/'.$name.'.go',['go'=>true]);

        // QA-only AFTER INSERT trigger holds a real M17 run INSERT inside its transaction.
        // Require another worker's connection to be simultaneously executing SQL, instead of
        // inferring overlap merely from nearly simultaneous process start timestamps.
        $observed=false;
        $sawLock=false;
        $processCountMax=0;
        $commandsSeen=[];
        $watchEnd=microtime(true)+11;
        while (microtime(true)<$watchEnd) {
            $holder=(int)($db->scalar("SELECT IS_USED_LOCK('m17_qa_overlap_lock')") ?? 0);
            if (in_array($holder,$readyIds,true)) {
                $sawLock=true;
                $other=$readyIds[0]===$holder?$readyIds[1]:$readyIds[0];
                $sessions=$db->all('SELECT ID,COMMAND FROM information_schema.PROCESSLIST WHERE ID IN (?,?)',[$holder,$other]);
                $processCountMax=max($processCountMax,count($sessions));
                $observed=count($sessions)===2;
                foreach ($sessions as $row) {
                    $command=(string)$row['COMMAND'];
                    $commandsSeen[$command]=true;
                    if ((int)$row['ID']===$other
                        && !in_array($command,['Query','Execute','Prepare'],true)) $observed=false;
                }
                if ($observed) break;
            }
            usleep(30000);
        }
        $done=[];
        $deadline=microtime(true)+32;
        while (microtime(true)<$deadline) {
            if (is_file($files[0]['done']) && is_file($files[1]['done'])) break;
            usleep(35000);
        }
        foreach ($processes as $p) {
            $status=proc_get_status($p);
            if ($status['running']) proc_terminate($p,9);
            proc_close($p);
        }
        foreach ($files as $file) {
            M17Qa::requireTrue(is_file($file['done']), 'RACE_WORKER_NO_RESULT');
            $done[]=M17Qa::json($file['done']);
        }
        $witness=['scenario'=>$scenario,'lock_seen'=>$sawLock,'max_live_sessions'=>$processCountMax,
            'processlist_commands'=>array_keys($commandsSeen),
            'worker_outcomes'=>array_map(static fn($r)=>['ok'=>$r['is_ok'],'reason'=>$r['reason'],
                'action'=>$r['action'],'driver_code'=>$r['driver_code'],'sqlstate'=>$r['sqlstate']],$done)];
        echo 'M17_QA_OBSERVATION '.json_encode($witness,JSON_THROW_ON_ERROR)."\n";
        M17Qa::requireTrue($observed, 'NO_OBSERVED_OVERLAPPING_M17_TRANSACTIONS');
        foreach ($done as $d) {
            M17Qa::requireTrue(in_array((int)$d['connection_id'],$readyIds,true),'WRONG_RACE_CONNECTION_ID');
            $transient=$d['reason']==='DATABASE_ERROR'
                && in_array((int)$d['driver_code'],[1205,1213],true);
            M17Qa::requireTrue($d['is_ok'] || $d['reason']==='M17_IDENTITY_RACE_REFUSED'
                || $transient, 'UNEXPECTED_RACE_FAILURE');
        }
        $result=['scenario'=>$scenario,'status'=>'PASS','overlapping_db_queries_observed'=>true,
            'connections_independent'=>true,'workers'=>array_map(static fn($v)=>[
                'is_ok'=>$v['is_ok'],'action'=>$v['action'],'reason'=>$v['reason'],
                'sqlstate'=>$v['sqlstate'],'driver_code'=>$v['driver_code']],$done)];
        $receipt['cases'][]=$result;
        M17Qa::file($out,$receipt);
        return $done;
    };

    // A racing independent question may lose to an InnoDB lock/deadlock while both
    // remain legitimate distinct identities. Record that original failure; only a
    // separately labelled, deterministic *manual test continuation* may try again.
    // This is NOT a runtime automatic retry, idempotency claim or budget refund.
    $manualContinuation=static function (string $scenario, array $outcomes, array $inputsByWriter, ?string $priorMemoryId = null)
        use ($db,&$receipt,$out): array {
        $needed=0;
        M17Qa::requireTrue($outcomes[0]['is_ok'] || $outcomes[1]['is_ok'],
            'RACE_NO_COMMITTED_WINNER');
        foreach ($outcomes as $i=>$result) {
            if ($result['is_ok']) continue;
            $transient=$result['reason']==='DATABASE_ERROR'
                && in_array((int)($result['driver_code']??0),[1205,1213],true);
            M17Qa::requireTrue($result['reason']==='M17_IDENTITY_RACE_REFUSED' || $transient,
                'RACE_UNEXPECTED_REFUSAL');
            $next=M17Qa::memory($db)->persistCandidate($inputsByWriter[$i],$priorMemoryId);
            M17Qa::requireTrue($next->isOk() && in_array($next->value()['action'],
                ['DISPOSABLE_CANDIDATE_RECORDED','EXACT_REPLAY_NO_WRITE'],true),
                'EXPLICIT_MANUAL_TEST_CONTINUATION_REFUSED');
            $outcomes[$i]['is_ok']=true;
            $outcomes[$i]['memory_id']=$next->value()['memory_id'];
            $outcomes[$i]['manual_test_continuation']=true;
            $needed++;
        }
        $receipt['manual_test_continuations'][]=['scenario'=>$scenario,
            'number'=>$needed,'automatic_retry'=>false,'original_race_failures_preserved'=>true];
        M17Qa::file($out,$receipt);
        return $outcomes;
    };

    // (1) Identical derivation: one run, rollup, memory, status and complete lineage.
    $scope=M17Qa::scope($db,'race-identical');
    $inputs=M17Qa::inputs($scope);
    $results=$runPair('identical-run',$inputs,null,$inputs,null);
    $derivation=MemoryStore::preview($inputs)->value()['derivation_identity_digest'];
    $rows=$db->all('SELECT compression_run_id FROM temporal_compression_runs WHERE derivation_identity_digest=?',[$derivation]);
    M17Qa::requireTrue(count($rows)===1,'DUPLICATE_DERIVATION_PERSISTED');
    $run=(string)$rows[0]['compression_run_id'];
    foreach (['temporal_rollups','temporal_memories'] as $table) {
        M17Qa::requireTrue((int)$db->scalar('SELECT COUNT(*) FROM '.Database::identifier($table).' WHERE compression_run_id=?',[$run])===1,
            'PARTIAL_OR_DUPLICATE_RUN_GRAPH');
    }
    $memory=(string)$db->scalar('SELECT temporal_memory_id FROM temporal_memories WHERE compression_run_id=?',[$run]);
    M17Qa::requireTrue((int)$db->scalar('SELECT COUNT(*) FROM temporal_memory_status_transitions WHERE temporal_memory_id=?',[$memory])===1,
        'DUPLICATE_TRANSITION');
    M17Qa::requireTrue((int)$db->scalar('SELECT COUNT(*) FROM compression_lineage_links WHERE temporal_memory_id=?',[$memory])===3,
        'INCOMPLETE_LINEAGE');
    $replay=M17Qa::memory($db)->persistCandidate($inputs);
    M17Qa::requireTrue($replay->isOk() && $replay->value()['action']==='EXACT_REPLAY_NO_WRITE',
        'POST_RACE_IDENTICAL_REPLAY_NOT_IDEMPOTENT');

    // (2) Two actual concurrent successors of the same immutable original.
    $scope=M17Qa::scope($db,'race-fork');
    $baseInputs=M17Qa::inputs($scope);
    $base=M17Qa::storeRow($db,$baseInputs);
    $forkInputs=[M17Qa::inputs($scope,'b'),M17Qa::inputs($scope,'c')];
    $children=$runPair('competing-successors',$forkInputs[0],$base,$forkInputs[1],$base);
    // A failed concurrent fork remains failed in the original race evidence.
    // Explicitly continue only after the overlap and with the same predecessor.
    $children=$manualContinuation('competing-successors',$children,$forkInputs,$base);
    M17Qa::requireTrue($children[0]['is_ok'] && $children[1]['is_ok'],
        'FORK_MANUAL_CONTINUATION_FAILED');
    M17Qa::requireTrue($children[0]['memory_id']!==$children[1]['memory_id'],'CHILDREN_NOT_DISTINCT');
    M17Qa::requireTrue((int)$db->scalar('SELECT COUNT(*) FROM temporal_memories WHERE supersedes_memory_id=?',[$base])===2,
        'CONCURRENT_FORK_CARDINALITY');
    $head=M17Qa::memory($db)->headCandidates(
        $scope,$baseInputs['input_set']['data_class'],$baseInputs['window']['kind'],
        $baseInputs['window']['start_inclusive_utc'],$baseInputs['window']['end_exclusive_utc']
    );
    M17Qa::requireTrue($head->isOk() && $head->value()['status']==='AMBIGUOUS_HEAD_HOLD'
        && count($head->value()['candidates'])===2,'CONCURRENT_FORK_DID_NOT_HOLD');

    // (3) Same competitor, different question: no accidental logical identity union.
    $scope=M17Qa::scope($db,'race-question');
    $another=$scope;$another['question_id']=Ids::new();
    $questionInputs=[M17Qa::inputs($scope),M17Qa::inputs($another)];
    $question=$runPair('separate-questions',$questionInputs[0],null,$questionInputs[1],null);
    $question=$manualContinuation('separate-questions',$question,$questionInputs);
    M17Qa::requireTrue($question[0]['memory_id']!==$question[1]['memory_id'],
        'SEPARATE_QUESTION_OUTPUT_COLLISION');
    M17Qa::requireTrue(M17Qa::memory($db)->replayCandidate($scope,$question[1]['memory_id'])
        ->reasonCode()==='M17_REPLAY_CANDIDATE_NOT_FOUND','QUESTION_SCOPE_READ_LEAK');

    // (4) Different tenants/workspaces: no foreign lineage or cross-scope discovery.
    $a=M17Qa::scope($db,'race-tenant-a');
    $b=M17Qa::scope($db,'race-tenant-b');
    $tenantInputs=[M17Qa::inputs($a),M17Qa::inputs($b)];
    $tenant=$runPair('separate-tenants',$tenantInputs[0],null,$tenantInputs[1],null);
    $tenant=$manualContinuation('separate-tenants',$tenant,$tenantInputs);
    M17Qa::requireTrue($tenant[0]['memory_id']!==$tenant[1]['memory_id'],
        'SEPARATE_TENANT_OUTPUT_COLLISION');
    M17Qa::requireTrue(M17Qa::memory($db)->replayCandidate($a,$tenant[1]['memory_id'])
        ->reasonCode()==='M17_REPLAY_CANDIDATE_NOT_FOUND','TENANT_RACE_ISOLATION_FAILED');

    M17Qa::removeOverlapTrigger($db);
    M17Qa::requireTrue(M17Qa::triggerCount($db)===14,'RACE_LEFT_UNAPPROVED_EXTRA_TRIGGER');
    M17Qa::requireTrue(
        (int)$db->scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '280_m17_%'")===0,
        'RACE_INSTALLED_UNADOPTED_PRODUCTION_MIGRATION'
    );
    $receipt['status']='PASS';
    $receipt['scenarios']=count($receipt['cases']);
    $receipt['worker_connections_unique_each_scenario']=true;
    $receipt['final_g21_families']=M17Qa::familyCount($db);
    $receipt['final_append_only_triggers']=M17Qa::triggerCount($db);
    M17Qa::file($out,$receipt);
    $cleanup();
    echo json_encode(['status'=>'PASS','scenarios'=>count($receipt['cases']),'overlapping_db_transactions'=>true])."\n";
} catch (\Throwable $e) {
    if (isset($receipt,$out) && $out!=='') {
        $receipt['status']='FAIL';
        $receipt['failure_class']=(new ReflectionClass($e))->getShortName();
        M17Qa::file($out,$receipt);
    }
    M17Qa::fail('RACE_EXCEPTION_'.preg_replace('/[^A-Z0-9_]/','_',strtoupper((new ReflectionClass($e))->getShortName())));
}
