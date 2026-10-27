<?php

declare(strict_types=1);

/**
 * LI-07E / R4 independent-volume candidate recovery extension.
 *
 * CLI + trusted ephemeral Docker + original QA marker are mandatory.
 * No Railway account, customer database, actual historical-use licence or
 * approved 270/280/290 migration is accepted or produced by this code.
 *
 * Invoked only by ops/qa/m17/restore.sh --r4-candidate after M17Qa::init()
 * and M17Qa::seed(). Preserve the old seven-table QA route unchanged.
 */
require_once __DIR__ . '/qa.php';

use Allfather\Loopia\Core\{FixedClock,Ids};
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M17\ReviewDue;
use Allfather\Loopia\Tests\Seed;
use Allfather\Loopia\Workflow\{
    HistoryReviewBasis,HistoryReviewBasisStore,HistoryReviewDueStore,
};

final class R4Qa
{
    private const TABLES = [
        'history_review_bases',
        'history_review_basis_refs',
        'temporal_compression_profiles',
        'temporal_compression_profile_rules',
        'temporal_compression_runs',
        'temporal_rollups',
        'temporal_memories',
        'temporal_memory_status_transitions',
        'compression_lineage_links',
        'history_review_policies',
        'history_review_policy_events',
        'history_review_due_checks',
    ];
    private const CLOCK = '2026-10-26T18:00:00.000Z';

    private static function evidenceRoot(string $input, bool $allowNew): string
    {
        M17Qa::requireTrue(str_starts_with($input, '/'), 'R4_EVIDENCE_ROOT_MUST_BE_ABSOLUTE');
        $parent=realpath(dirname($input));
        $temp=realpath(sys_get_temp_dir());
        M17Qa::requireTrue($parent!==false && $temp!==false
            && str_starts_with($parent.'/', $temp.'/')
            && !is_link($input)
            && !str_contains($input,'/public_html/')
            && !str_contains($input,'/storage/evidence/'),
            'R4_EVIDENCE_MUST_BE_ISOLATED_RUNNER_TEMP');
        if ($allowNew && !is_dir($input)) {
            M17Qa::requireTrue(@mkdir($input,0700,true), 'R4_EVIDENCE_TMP_CREATE_REFUSED');
        }
        M17Qa::requireTrue(is_dir($input)
            && (realpath($input)?:'')===$parent.'/'.basename($input),
            'R4_EVIDENCE_TMP_DIRECTORY_INVALID');
        return $input;
    }

    /** Installed ONLY on the original QA database, marker-bound to this run. */
    private static function proposal(string $number, int $count): array
    {
        $filenames=[
            '270'=>'270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql',
            '290'=>'290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql',
        ];
        M17Qa::requireTrue(isset($filenames[$number]),'R4_UNEXPECTED_PROPOSAL');
        $path=dirname(__DIR__,3).'/docs/longitudinal/proposals/'.$filenames[$number];
        M17Qa::requireTrue(is_file($path),'R4_UNRATIFIED_DDL_MISSING');
        $statements=Migrator::splitStatements((string)file_get_contents($path));
        M17Qa::requireTrue(count($statements)===$count,'R4_DDL_SHAPE_CHANGED_REVIEW_REQUIRED');
        return $statements;
    }

    private static function counted(Database $db): array
    {
        $counts=[];
        foreach (self::TABLES as $table) {
            $counts[$table]=(int)$db->scalar('SELECT COUNT(*) FROM '.
                Database::identifier($table));
        }
        return $counts;
    }

    private static function triggerCount(Database $db): int
    {
        return (int)$db->scalar(
            "SELECT COUNT(*) FROM information_schema.triggers
             WHERE trigger_schema=DATABASE()
               AND (trigger_name LIKE 'history_%_no_%'
                    OR trigger_name LIKE 'm17_%_no_%')"
        );
    }

    /** @return array<string,mixed> */
    public static function extend(Database $db, string $seedFile,string $stateFile,string $evidenceDir):array
    {
        M17Qa::requireTrue(is_file($seedFile)&&!file_exists($stateFile),
            'R4_SYNTHETIC_SEED_REQUIRED');
        $evidenceDir=self::evidenceRoot($evidenceDir,true);
        $source=M17Qa::json($seedFile);
        // Original 280 QC has already completed and produced five memories.
        M17Qa::requireTrue(
            M17Qa::familyCount($db)===7 && M17Qa::triggerCount($db)===14,
            'R4_SEVEN_FAMILY_M17_PRECONDITION'
        );
        $p270=self::proposal('270',6);
        $p290=self::proposal('290',9);
        // A *second* synthetic MariaDB DDL interruption: create first 270
        // table, observe the incomplete schema, then resume with the original
        // exact statements. This never touches database/migrations/.
        $db->pdo()->exec($p270[0]);
        $partial=(int)$db->scalar(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name IN
               ('history_review_bases','history_review_basis_refs')"
        );
        M17Qa::requireTrue($partial===1,'R4_270_INTERRUPTION_NOT_OBSERVED');
        foreach($p270 as $sql)$db->pdo()->exec($sql);
        foreach($p290 as $sql)$db->pdo()->exec($sql);
        M17Qa::requireTrue(self::triggerCount($db)===24,
            'R4_TWENTY_FOUR_SERVER_SIDE_TRIGGERS_REQUIRED');
        foreach(['ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',
                 'ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',
                 'ALLFATHER_LI08C_DISPOSABLE_SCHEMA_LOADED'] as $flag){
            if(!defined($flag))define($flag,true);
        }

        $clock=new FixedClock(self::CLOCK,1);
        $tenant=Seed::tenantWorkspace($db,$clock,'r4-synthetic-basis');
        $competitor=Ids::new();
        $db->insert('competitors',[
            'competitor_id'=>$competitor, 'schema_version'=>'1',
            'tenant_id'=>$tenant['tenant_id'],
            'workspace_id'=>$tenant['workspace_id'],
            'display_name'=>'R4 synthetic history recovery only',
            'competitor_class'=>'DIRECT','status'=>'ACTIVE',
            'created_at'=>$clock->now(),
        ]);
        $scope=[
            'tenant_id'=>$tenant['tenant_id'],
            'workspace_id'=>$tenant['workspace_id'],
            'competitor_id'=>$competitor,
            'question_id'=>Ids::new(),
        ];
        $sourceVector=M17Qa::json(dirname(__DIR__,3).
            '/tests/fixtures/li05_offline_basis_vectors.json');
        foreach($sourceVector['rows'] as &$ref){
            foreach($scope as $key=>$value)$ref[$key]=$value;
        }
        unset($ref);
        $template=[
            'scope'=>$scope,
            'period'=>$sourceVector['period'],
            'baseline_period'=>$sourceVector['baseline_period'],
            'baseline_null_reason'=>null,
            'knowledge_cutoff_utc'=>$sourceVector['knowledge_cutoff_utc'],
            'rows'=>$sourceVector['rows'],
            'max_references'=>8,
            'coverage'=>$sourceVector['known_coverage'],
        ];
        $basisStore=HistoryReviewBasisStore::forDisposableTest($db,$clock);
        $dueStore=HistoryReviewDueStore::forDisposableTest($db,$clock);
        M17Qa::requireTrue($basisStore->isOk()&&$dueStore->isOk(),
            'R4_DISPOSABLE_ONLY_WRITERS_REQUIRED');
        $basisStore=$basisStore->value();$dueStore=$dueStore->value();
        $bases=[];
        foreach(['original','late','fork'] as $label){
            $input=$template;
            if($label!=='original'){
                $input['knowledge_cutoff_utc']='2026-03-20T00:00:00Z';
            }
            if($label==='fork'){
                $extra=$input['rows'][2];
                $extra['ref_id']='r4-fork-ref';
                $extra['snapshot_id']='r4-fork-snapshot';
                $extra['evidence_id']='r4-fork-evidence';
                $extra['source_ref_id']='r4-fork-source';
                $extra['payload_sha256']=str_repeat('d',64);
                $extra['recorded_at']='2026-03-17T00:00:00Z';
                $extra['known_at']='2026-03-17T00:00:00Z';
                $input['rows'][]=$extra;
            }
            $basis=HistoryReviewBasis::freeze($input);
            M17Qa::requireTrue($basis->isOk(),'R4_FROZEN_BASIS_REFUSED');
            $row=$basisStore->recordCandidate($basis->value());
            M17Qa::requireTrue($row->isOk(),'R4_HISTORY_BASIS_PERSISTENCE_REFUSED');
            $bases[]=[
                'label'=>$label,
                'history_review_basis_id'=>$row->value()['history_review_basis_id'],
                'manifest_digest'=>$basis->value()['manifest_digest'],
                'manifest'=>$basis->value(),
            ];
        }
        $policy=[
            'version'=>ReviewDue::VERSION,'cadence_seconds'=>86400,
            'maximum_wait_seconds'=>604800,'cooldown_seconds'=>3600,
            'max_pending_work'=>3,
        ];
        $adopted=$dueStore->recordPolicyCandidate($scope,$policy);
        M17Qa::requireTrue($adopted->isOk(),'R4_TEST_ONLY_DUE_POLICY_REFUSED');
        $pid=$adopted->value()['history_review_policy_id'];
        $dueIds=[];
        $state=[
            'last_considered_basis_digest'=>null,
            'last_considered_at_utc'=>null,
            'last_queued_at_utc'=>null,
            'pending_work'=>0,
            'source_eligibility'=>'NOT_ASSESSED',
        ];
        foreach($bases as $i=>$row){
            $at=sprintf('2026-10-26T18:00:%02dZ',$i);
            $due=$dueStore->recordDueCandidate(
                $scope,$pid,$row['history_review_basis_id'],$state,$at,
            );
            M17Qa::requireTrue($due->isOk(),'R4_DUE_RECORD_REFUSED');
            $dueIds[]=$due->value()['history_review_due_check_id'];
        }

        // Real M05-owned synthetic upload. No real customer/source data, no
        // historical-purpose grant. Private bytes are saved by EvidenceStore
        // and will be packed into the encrypted age archive.
        $bytes="ALLFATHER_R4_SYNTHETIC_UPLOAD_ONLY\n".
            "No external source or historical licence inferred.\n";
        $storage=new EvidenceStore($evidenceDir);
        $saved=$storage->put($bytes);
        M17Qa::requireTrue($saved->isOk(),'R4_M05_SYNTHETIC_BYTES_WRITE_FAILED');
        $digest=$saved->value()['digest'];
        $spine=new EvidenceSpine($db,$clock);
        $sourceId=$spine->registerSource(
            $scope['tenant_id'],$scope['workspace_id'],
            'UPLOADED_ARTIFACT','internal://r4/owner-synthetic',
            'INTERNAL_ANALYSIS','INTERNAL_DEMO','ACTIVE_FOR_CAPTURE',
        );
        $capture=$spine->capture([
            'tenant_id'=>$scope['tenant_id'],
            'workspace_id'=>$scope['workspace_id'],
            'source_ref_id'=>$sourceId,
            'evidence_type'=>'UPLOADED_SOURCE_CAPTURE',
            'captured_content_reference_safe'=>'evidence://'.$digest,
            'content_hash'=>$digest,
            'capture_method'=>'OWNER_UPLOAD',
            'permitted_use_scope'=>'INTERNAL_ANALYSIS',
            'sensitivity_class'=>'INTERNAL_DEMO',
        ]);
        M17Qa::requireTrue($capture->isOk(),'R4_M05_SYNTHETIC_CAPTURE_REFUSED');
        $evidenceId=$capture->value()['evidence_id'];

        $counts=self::counted($db);
        foreach($counts as $table=>$n){
            M17Qa::requireTrue($n>0,'R4_FAMILY_NOT_POPULATED_'.strtoupper($table));
        }
        M17Qa::requireTrue(count($counts)===12,'R4_NOT_TWELVE_TABLE_FAMILIES');
        M17Qa::requireTrue(self::triggerCount($db)===24,'R4_APPEND_ONLY_TRIGGERS_MISSING');

        $stateOut=[
            'scope'=>$scope,
            'bases'=>$bases,
            'policy_id'=>$pid,
            'due_ids'=>$dueIds,
            'synthetic_evidence_id'=>$evidenceId,
            'synthetic_source_id'=>$sourceId,
            'synthetic_content_digest'=>$digest,
            'synthetic_bytes_length'=>strlen($bytes),
            'r4_family_count'=>12,
            'r4_append_only_triggers'=>24,
            'U03_historical_use_admitted'=>false,
            'U05_policy_adopted'=>false,
            'source_bytes_real_customer'=>false,
        ];
        // Refresh the original QA manifest only after every R4 row is durable,
        // so original M17Qa verify/compare checks ALL tables and triggers.
        $source['manifest']=M17Qa::manifest($db);
        M17Qa::file($seedFile,$source);
        M17Qa::file($stateFile,$stateOut);
        return [
            'status'=>'PASS',
            'families'=>count($counts),
            'triggers'=>self::triggerCount($db),
            'source_digest_verified'=>true,
            'history_use_granted'=>false,
        ];
    }

    public static function verify(Database $db,string $seedFile,string $stateFile,string $evidenceDir):array
    {
        $evidenceDir=self::evidenceRoot($evidenceDir,false);
        $seed=M17Qa::json($seedFile);
        $state=M17Qa::json($stateFile);
        M17Qa::verify($db,$seed['manifest']);
        M17Qa::checkSemantics($db,$seed);
        $counts=self::counted($db);
        M17Qa::requireTrue(count($counts)===12,'R4_FAMILY_COUNT_AFTER_RESTORE');
        foreach($counts as $table=>$n){
            M17Qa::requireTrue($n>0,'R4_RESTORED_FAMILY_EMPTY_'.strtoupper($table));
        }
        M17Qa::requireTrue(self::triggerCount($db)===24,'R4_RESTORED_TRIGGERS_MISSING');
        M17Qa::requireTrue((int)$db->scalar(
            "SELECT COUNT(*) FROM schema_migrations
             WHERE migration_id LIKE '270_%'
                OR migration_id LIKE '280_%'
                OR migration_id LIKE '290_%'"
        )===0,'R4_CANDIDATE_SCHEMA_PROMOTED_IN_LEDGER');

        foreach(['ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED',
                 'ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED',
                 'ALLFATHER_LI08C_DISPOSABLE_SCHEMA_LOADED'] as $flag){
            if(!defined($flag))define($flag,true);
        }
        $clock=new FixedClock(self::CLOCK,1);
        $basisStore=HistoryReviewBasisStore::forDisposableTest($db,$clock);
        $dueStore=HistoryReviewDueStore::forDisposableTest($db,$clock);
        M17Qa::requireTrue($basisStore->isOk()&&$dueStore->isOk(),
            'R4_RESTORED_WRITERS_NOT_TEST_ONLY');
        $bs=$basisStore->value();
        foreach($state['bases'] as $row){
            $back=$bs->replayCandidate($state['scope'],$row['history_review_basis_id']);
            M17Qa::requireTrue($back->isOk() &&
                ($back->value()['integrity']??null)==='MATCH_METADATA_ONLY' &&
                ($back->value()['manifest']['manifest_digest']??null)===$row['manifest_digest'],
                'R4_RESTORED_BASIS_DIGEST_OR_LINEAGE_MISMATCH');
            $replay=$bs->recordCandidate($row['manifest']);
            M17Qa::requireTrue($replay->isOk() &&
                ($replay->value()['action']??null)==='EXACT_REPLAY_NO_WRITE',
                'R4_RESTORED_BASIS_REPLAY_WROTE_ROW');
        }
        $due=$dueStore->value()->listDueCandidates($state['scope'],20);
        M17Qa::requireTrue($due->isOk() && count($due->value()['rows'])===3,
            'R4_RESTORED_DUE_LEDGER_INCOMPLETE');
        $m05=$db->one(
            'SELECT e.content_hash,e.evidence_status,e.source_ref_id,
                    s.status AS source_status
             FROM evidence_objects e
             JOIN source_references s ON s.source_ref_id=e.source_ref_id
             WHERE e.evidence_id=? AND e.tenant_id=? AND e.workspace_id=?',
            [
                $state['synthetic_evidence_id'],
                $state['scope']['tenant_id'],
                $state['scope']['workspace_id'],
            ],
        );
        M17Qa::requireTrue($m05!==null
            && $m05['content_hash']===$state['synthetic_content_digest']
            && $m05['source_ref_id']===$state['synthetic_source_id']
            && $m05['evidence_status']==='CAPTURED'
            && $m05['source_status']==='ACTIVE_FOR_CAPTURE',
            'R4_RESTORED_M05_OWNER_EVIDENCE_NOT_BOUND');
        $read=(new EvidenceStore($evidenceDir))->get($state['synthetic_content_digest']);
        M17Qa::requireTrue($read->isOk()
            && strlen($read->value())===$state['synthetic_bytes_length']
            && hash('sha256',$read->value())===$state['synthetic_content_digest'],
            'R4_RESTORED_M05_PRIVATE_BYTES_FAILED');
        $foreign=$state['scope'];
        $foreign['tenant_id']='another-tenant';
        M17Qa::requireTrue(
            $bs->replayCandidate($foreign,$state['bases'][0]['history_review_basis_id'])
                ->reasonCode()==='LI05C_BASIS_NOT_FOUND',
            'R4_RESTORED_CROSS_TENANT_SOURCE_READ');
        try {
            $db->run('UPDATE history_review_bases
                      SET candidate_state=candidate_state WHERE history_review_basis_id=?',
                [$state['bases'][0]['history_review_basis_id']]);
            M17Qa::fail('R4_APPEND_ONLY_UPDATE_SUCCEEDED_AFTER_RESTORE');
        } catch(\PDOException) {
            // Server-side immutability retained, even for no-op update.
        }
        M17Qa::requireTrue(($state['U03_historical_use_admitted']??null)===false
            && ($state['U05_policy_adopted']??null)===false
            && ($state['source_bytes_real_customer']??null)===false,
            'R4_RESTORED_STATE_FALSELY_CLAIMS_PERMISSION');
        return [
            'status'=>'PASS',
            'candidate_table_families'=>12,
            'append_only_triggers'=>24,
            'all_database_table_digests_match'=>true,
            'synthetic_M05_private_bytes_verified'=>true,
            'immutable_fork_and_basis_replay'=>true,
            'U03_historical_use_admitted'=>false,
            'Railway_tested'=>false,
        ];
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME']??''))!==__FILE__) return;

try {
    $command=$argv[1]??'';
    $db=M17Qa::db();
    if($command==='extend'){
        M17Qa::requireTrue(count($argv)===5,'R4_EXTEND_ARGUMENTS_INVALID');
        $result=R4Qa::extend($db,$argv[2],$argv[3],$argv[4]);
    } elseif($command==='verify'){
        M17Qa::requireTrue(count($argv)===5,'R4_VERIFY_ARGUMENTS_INVALID');
        $result=R4Qa::verify($db,$argv[2],$argv[3],$argv[4]);
    } else {
        M17Qa::fail('R4_UNKNOWN_COMMAND');
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
} catch(\Throwable $e) {
    // Never echo internal exceptions, source bytes, DSNs or paths.
    $code=$e instanceof \PDOException
        ? '_SQLSTATE_'.preg_replace('/[^A-Z0-9]/','_',strtoupper((string)$e->getCode()))
        .'_CODE_'.(int)($e->errorInfo[1]??0)
        : '';
    M17Qa::fail('R4_EXCEPTION_'.strtoupper((new \ReflectionClass($e))->getShortName()).$code);
}
