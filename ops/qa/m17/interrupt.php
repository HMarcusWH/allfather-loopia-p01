<?php

declare(strict_types=1);

/**
 * LI-19 — kill an actual candidate M17 writer with run + rollup inserted but
 * still uncommitted, then prove no partial immutable graph survives.
 *
 * A test-only AFTER INSERT rollup trigger is the deterministic pause point.
 * This file cannot be invoked without the disposable database guard.
 */
require_once __DIR__.'/qa.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;

$case = $argv[1] ?? '';
if ($case === 'worker') {
    $fixture = M17Qa::json($argv[2] ?? '');
    $ready = $argv[3] ?? '';
    M17Qa::requireTrue($ready !== '' && !file_exists($ready), 'INTERRUPT_WORKER_READY_PATH_INVALID');
    $db = M17Qa::db();
    $db->pdo()->exec('SET @m17_qa_interrupt=1');
    M17Qa::file($ready, [
        'server_connection_id' => (int)$db->scalar('SELECT CONNECTION_ID()'),
        'pid' => getmypid(),
    ]);
    // The parent MUST kill this process during the SQL AFTER INSERT trigger.
    $response = M17Qa::memory($db)->persistCandidate($fixture['inputs']);
    M17Qa::fail('M17_CANDIDATE_WRITER_WAS_NOT_INTERRUPTED_'.$response->reasonCode());
}

M17Qa::requireTrue($case === 'test', 'INTERRUPT_UNKNOWN_MODE');
$out = $argv[2] ?? '';
M17Qa::requireTrue($out !== '' && !file_exists($out), 'INTERRUPT_RECEIPT_REQUIRED');
$db = M17Qa::db();
M17Qa::requireTrue(function_exists('proc_open') && function_exists('proc_terminate'),
    'PROCESS_TERMINATION_UNAVAILABLE');

$work = sys_get_temp_dir().'/m17-interrupt-'.bin2hex(random_bytes(8));
M17Qa::requireTrue(mkdir($work,0700), 'INTERRUPT_PRIVATE_DIRECTORY_FAILED');
$testTrigger='m17_qa_pause_rollup_after_insert';
$addedTrigger=false;
$child=null;
$receipt=['status'=>'NOT_RUN','scope'=>'M17_CANDIDATE_ONLY',
    'runner'=>(getenv('GITHUB_ACTIONS')==='true'?'GITHUB_ACTIONS':'LOCAL_DOCKER'),
    'U04'=>'NOT_RATIFIED','U03'=>'NOT_ADOPTED','Railway'=>'NOT_TESTED'];
M17Qa::file($out,$receipt);

try {
    // This test uses only the documented unadopted schema and fixture writer.
    M17Qa::requireTrue(M17Qa::triggerCount($db)===14, 'INTERRUPT_EXPECTS_EXACT_14_APPEND_ONLY_TRIGGERS');
    $scope=M17Qa::scope($db, 'interrupt-writer');
    $inputs=M17Qa::inputs($scope);
    $preview=\Allfather\Loopia\Substrate\M17\MemoryStore::preview($inputs);
    M17Qa::requireTrue($preview->isOk(), 'INTERRUPT_SYNTHETIC_INPUT_INVALID');

    // An AFTER INSERT trigger executes after the current rollup was written
    // while all preceding profile/run statements remain inside the same txn.
    $db->pdo()->exec("CREATE TRIGGER {$testTrigger} AFTER INSERT ON temporal_rollups
        FOR EACH ROW BEGIN
          IF COALESCE(@m17_qa_interrupt,0)=1 THEN
            SET @m17_qa_interrupt_lock = GET_LOCK('m17_qa_abort_after_rollup',10);
            DO SLEEP(24);
            DO RELEASE_LOCK('m17_qa_abort_after_rollup');
          END IF;
        END");
    $addedTrigger=true;
    $before=M17Qa::manifest($db);
    $caseFile=$work.'/case.json';
    $ready=$work.'/child.ready';
    M17Qa::file($caseFile,['inputs'=>$inputs]);
    $spec=[0=>['pipe','r'],1=>['file',$work.'/stdout.log','w'],2=>['file',$work.'/stderr.log','w']];
    $child=proc_open([PHP_BINARY,__FILE__,'worker',$caseFile,$ready],$spec,$pipes,dirname(__DIR__,3));
    M17Qa::requireTrue(is_resource($child), 'INTERRUPT_WORKER_NOT_STARTED');
    fclose($pipes[0]);

    $end=microtime(true)+12;
    while (!is_file($ready) && microtime(true)<$end) {
        $s=proc_get_status($child);
        M17Qa::requireTrue($s['running'], 'INTERRUPT_WORKER_EXITED_BEFORE_READY');
        usleep(35000);
    }
    M17Qa::requireTrue(is_file($ready),'INTERRUPT_WORKER_NEVER_READY');
    $childConnectionId=(int)M17Qa::json($ready)['server_connection_id'];

    // Observe the exact child's MariaDB connection inside the trigger pause.
    $holdSeen=false;
    $observedAsExecute=false;
    $end=microtime(true)+14;
    while (microtime(true)<$end) {
        $holder=(int)($db->scalar("SELECT IS_USED_LOCK('m17_qa_abort_after_rollup')")??0);
        if ($holder===$childConnectionId) {
            $holdSeen=true;
            $sql=$db->one('SELECT COMMAND FROM information_schema.PROCESSLIST WHERE ID=?',[$childConnectionId]);
            $observedAsExecute=in_array(($sql['COMMAND']??''),['Query','Execute','Prepare'],true);
            if ($observedAsExecute) break;
        }
        $s=proc_get_status($child);
        M17Qa::requireTrue($s['running'],'INTERRUPT_WORKER_EXITED_BEFORE_SQL_HOLD');
        usleep(35000);
    }
    M17Qa::requireTrue($holdSeen && $observedAsExecute,
        'INTERRUPT_DID_NOT_REACH_M17_ROLLUP_INSERT');
    // Terminate the actual PHP process in the middle of its M17 transaction.
    M17Qa::requireTrue(proc_terminate($child,9),'INTERRUPT_SIGKILL_FAILED');
    proc_close($child);
    $child=null;
    // MariaDB must detect the disconnected session and rollback its transaction.
    $end=microtime(true)+12;
    while (microtime(true)<$end) {
        $holder=$db->scalar("SELECT IS_USED_LOCK('m17_qa_abort_after_rollup')");
        if ($holder===null) break;
        usleep(50000);
    }
    M17Qa::requireTrue($db->scalar("SELECT IS_USED_LOCK('m17_qa_abort_after_rollup')")===null,
        'INTERRUPTED_M17_TRANSACTION_STILL_HELD');
    $after=M17Qa::manifest($db);
    M17Qa::requireTrue($after===$before, 'PARTIAL_M17_WRITER_GRAPH_SURVIVED_INTERRUPTION');

    // Explicitly ordered *operator test continuation*, not automatic runtime retry.
    $recovered=M17Qa::memory($db)->persistCandidate($inputs);
    M17Qa::requireTrue($recovered->isOk()
        && ($recovered->value()['action']??null)==='DISPOSABLE_CANDIDATE_RECORDED',
        'POST_ABORT_EXPLICIT_CANDIDATE_CONTINUATION_FAILED');
    $runDigest=$preview->value()['derivation_identity_digest'];
    $run=(string)$db->scalar('SELECT compression_run_id FROM temporal_compression_runs WHERE derivation_identity_digest=?',[$runDigest]);
    M17Qa::requireTrue(Ids::isValid($run), 'POST_ABORT_M17_RUN_MISSING');
    $memory=(string)$db->scalar('SELECT temporal_memory_id FROM temporal_memories WHERE compression_run_id=?',[$run]);
    M17Qa::requireTrue(Ids::isValid($memory),'POST_ABORT_M17_MEMORY_MISSING');
    M17Qa::requireTrue((int)$db->scalar('SELECT COUNT(*) FROM temporal_rollups WHERE compression_run_id=?',[$run])===1,
        'POST_ABORT_ROLLUP_NOT_UNIQUE');
    M17Qa::requireTrue((int)$db->scalar('SELECT COUNT(*) FROM compression_lineage_links WHERE temporal_memory_id=?',[$memory])===3,
        'POST_ABORT_M17_LINEAGE_INCOMPLETE');
    M17Qa::requireTrue((int)$db->scalar('SELECT COUNT(*) FROM temporal_memory_status_transitions WHERE temporal_memory_id=?',[$memory])===1,
        'POST_ABORT_M17_STATUS_INCOMPLETE');

    $db->pdo()->exec("DROP TRIGGER {$testTrigger}");
    $addedTrigger=false;
    M17Qa::requireTrue(M17Qa::triggerCount($db)===14,'INTERRUPT_CLEANUP_CHANGED_APPEND_ONLY_TRIGGER_COUNT');

    $receipt=['status'=>'PASS','test'=>'ACTUAL_M17_PHP_WRITER_KILLED_DURING_ROLLUP_INSERT',
        'server_connection_id_observed'=>true,
        'sql_transaction_overlap_witnessed'=>true,
        'writer_sigkill_after_profile_run_rollup_before_commit'=>true,
        'all_table_row_digests_unchanged_after_abort'=>true,
        'manual_test_continuation_only'=>true,
        'post_continuation_lineage_complete'=>true,
        'append_only_trigger_count'=>14,
        'source'=>'SYNTHETIC_G21_CANDIDATE',
        'U04'=>'NOT_RATIFIED','U03'=>'NOT_ADOPTED',
        'Railway'=>'NOT_TESTED','service_activation'=>false];
    M17Qa::file($out,$receipt);
    echo '{"status":"PASS","actual_m17_writer_interrupted":true,"no_partial_graph":true}'."\n";
} catch (\Throwable $e) {
    // Do not output native SQL exceptions or raw source/input material.
    $receipt['status']='FAIL';
    $receipt['reason']='M17_WRITER_INTERRUPTION_'.preg_replace('/[^A-Z0-9_]/','_',strtoupper((new ReflectionClass($e))->getShortName()));
    M17Qa::file($out,$receipt);
    M17Qa::fail((string)$receipt['reason']);
} finally {
    if (is_resource($child)) {
        $state=proc_get_status($child);
        if ($state['running']) proc_terminate($child,9);
        proc_close($child);
    }
    if ($addedTrigger) {
        try { $db->pdo()->exec("DROP TRIGGER IF EXISTS {$testTrigger}"); } catch (\Throwable) {}
    }
    foreach (glob($work.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($work);
}
