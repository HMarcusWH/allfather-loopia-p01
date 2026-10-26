<?php

declare(strict_types=1);

/**
 * PR #87: disposable-only M17 qualification. Not a production entrypoint.
 * All DB-mutating commands require a GitHub-hosted runner or an explicit local
 * Docker acknowledgement, a loopback MariaDB connection, a restricted QA user,
 * an exact QA database name and a per-run persisted database marker.
 */
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Substrate\M17\{InputSet,MemoryStore};
use Allfather\Loopia\Tests\{Li07aFixture,Seed};

$root = dirname(__DIR__, 3);
require_once $root.'/app/autoload.php';
require_once $root.'/tests/support/Li07aFixture.php';
require_once $root.'/tests/support/Seed.php';

final class M17Qa
{
    private const TABLES = [
        'temporal_compression_profiles' => 'profile_id',
        'temporal_compression_profile_rules' => 'profile_rule_id',
        'temporal_compression_runs' => 'compression_run_id',
        'temporal_rollups' => 'temporal_rollup_id',
        'temporal_memories' => 'temporal_memory_id',
        'temporal_memory_status_transitions' => 'temporal_memory_status_transition_id',
        'compression_lineage_links' => 'compression_lineage_link_id',
    ];
    private const CLOCK = '2026-10-26T18:00:00.000Z';

    public static function fail(string $code): never
    {
        // Never echo exception messages, credentials, SQL or source contents.
        fwrite(STDERR, "M17_QA_FAIL: {$code}\n");
        exit(1);
    }

    public static function requireTrue(bool $ok, string $code): void
    {
        if (!$ok) self::fail($code);
    }

    public static function file(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $tmp = $path.'.tmp.'.getmypid();
        file_put_contents($tmp, $encoded."\n", LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    public static function json(string $path): array
    {
        $data = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        self::requireTrue(is_array($data), 'INVALID_JSON_FIXTURE');
        return $data;
    }

    private static function config(): array
    {
        $file = getenv('TEST_ENV_FILE');
        $run = getenv('M17_QA_RUN_ID');
        self::requireTrue(is_string($file) && $file !== '' && is_file($file), 'EXPLICIT_TEST_ENV_REQUIRED');
        self::requireTrue(is_string($run) && preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D', $run) === 1, 'QA_RUN_ID_REQUIRED');
        self::requireTrue(realpath($file) !== realpath(dirname(__DIR__, 3).'/.env'), 'RUNTIME_ENV_FILE_FORBIDDEN');
        self::requireTrue(!is_link($file) && (fileperms($file) & 0077) === 0, 'TEST_ENV_PERMISSIONS');
        self::requireTrue(
            (getenv('GITHUB_ACTIONS') === 'true'
                && getenv('RUNNER_ENVIRONMENT') === 'github-hosted')
            || getenv('M17_QA_LOCAL_DOCKER') === 'EXPLICIT_DISPOSABLE_DOCKER_ONLY',
            'TRUSTED_EPHEMERAL_RUNNER_REQUIRED'
        );
        $env = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::requireTrue(is_array($env), 'TEST_ENV_PARSE_FAILED');
        self::requireTrue(($env['APP_ENV'] ?? '') === 'testing', 'TEST_ENV_MODE_REFUSED');
        self::requireTrue(($env['DB_HOST'] ?? '') === '127.0.0.1', 'NON_LOOPBACK_TARGET_REFUSED');
        self::requireTrue(($env['DB_NAME'] ?? '') === 'm17_candidate_qa', 'NON_DISPOSABLE_DATABASE_REFUSED');
        self::requireTrue(($env['DB_USER'] ?? '') === 'm17_qa', 'NON_QA_PRINCIPAL_REFUSED');
        self::requireTrue(isset($env['DB_PASSWORD']) && $env['DB_PASSWORD'] !== '', 'QA_PASSWORD_REQUIRED');
        self::requireTrue(ctype_digit((string)($env['DB_PORT'] ?? '')), 'QA_PORT_INVALID');
        return $env;
    }

    public static function db(bool $requireMarker = true): Database
    {
        $v = self::config();
        $db = Database::connect($v['DB_HOST'], (int)$v['DB_PORT'], $v['DB_NAME'], $v['DB_USER'], $v['DB_PASSWORD']);
        $id = $db->one('SELECT DATABASE() d, CURRENT_USER() u, VERSION() v, CONNECTION_ID() c, @@hostname h');
        self::requireTrue(($id['d'] ?? '') === 'm17_candidate_qa', 'SERVER_DATABASE_IDENTITY_MISMATCH');
        self::requireTrue(str_starts_with((string)($id['u'] ?? ''), 'm17_qa@'), 'SERVER_DB_USER_MISMATCH');
        self::requireTrue(
            preg_match('/^10\.(6|11)\./', (string)($id['v'] ?? '')) === 1,
            'UNTESTED_MARIADB_VERSION'
        );
        if ($requireMarker) {
            $marker = $db->one('SELECT run_id FROM m17_qa_run_identity WHERE marker_id=1');
            self::requireTrue(($marker['run_id'] ?? '') === getenv('M17_QA_RUN_ID'), 'DISPOSABLE_SERVER_MARKER_MISMATCH');
        } else {
            $n = (int)$db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()');
            self::requireTrue($n === 0, 'INITIAL_QA_DATABASE_MUST_BE_EMPTY');
        }
        return $db;
    }

    public static function version(Database $db): string
    {
        return (string)$db->scalar('SELECT VERSION()');
    }

    public static function memory(Database $db): MemoryStore
    {
        if (!defined('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED')) {
            define('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED', true);
        }
        $result = MemoryStore::forDisposableTest($db, new \Allfather\Loopia\Core\FixedClock(self::CLOCK, 1));
        self::requireTrue($result->isOk(), 'CANDIDATE_ONLY_FACTORY_REFUSED');
        return $result->value();
    }

    private static function proposal(): array
    {
        $path = dirname(__DIR__, 3).'/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql';
        self::requireTrue(is_file($path), 'UNADOPTED_G21_PROPOSAL_MISSING');
        $parts = Migrator::splitStatements((string)file_get_contents($path));
        self::requireTrue(count($parts) === 21, 'EXPECTED_SEVEN_TABLE_FOURTEEN_TRIGGER_PROPOSAL');
        return $parts;
    }

    public static function init(Database $db): array
    {
        $m = new Migrator($db, dirname(__DIR__, 3).'/database/migrations');
        $first = $m->up();
        self::requireTrue($first['drifted'] === [], 'APPLIED_MIGRATION_DRIFT');
        $second = $m->up();
        self::requireTrue($second['applied'] === [] && $second['drifted'] === [], 'ACTIVE_MIGRATION_NOOP_FAILED');
        $db->pdo()->exec('CREATE TABLE m17_qa_run_identity (marker_id INT PRIMARY KEY, run_id VARCHAR(100) NOT NULL) ENGINE=InnoDB');
        $db->insert('m17_qa_run_identity', ['marker_id'=>1, 'run_id'=>getenv('M17_QA_RUN_ID')]);
        $db->pdo()->exec('CREATE TABLE m17_qa_crash_probe (id INT PRIMARY KEY, value_text VARCHAR(60) NOT NULL) ENGINE=InnoDB');
        $db->insert('m17_qa_crash_probe', ['id'=>1, 'value_text'=>'COMMITTED_BEFORE_CRASH']);
        $parts = self::proposal();
        // Deliberately interrupt the candidate DDL after three auto-committed statements.
        // This is *not* a transactional DDL rollback or an approved migration.
        for ($i=0; $i<3; $i++) $db->pdo()->exec($parts[$i]);
        $partial = self::familyCount($db);
        self::requireTrue($partial === 3, 'INTERRUPTED_DDL_STATE_NOT_OBSERVED');
        foreach ($parts as $sql) $db->pdo()->exec($sql);
        self::requireTrue(self::familyCount($db) === 7 && self::triggerCount($db) === 14, 'DDL_RESUME_MISSING_OBJECTS');
        for ($i=0; $i<7; $i++) $db->pdo()->exec($parts[$i]);
        self::requireTrue(self::familyCount($db) === 7, 'CANDIDATE_CREATE_TABLE_NOOP_FAILED');
        self::requireTrue(
            (int)$db->scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '280_m17_%'") === 0,
            'UNADOPTED_MIGRATION_APPEARED_IN_LEDGER'
        );
        return ['status'=>'PASS','partial_ddl_tables'=>$partial,'final_tables'=>7,'append_only_triggers'=>14,
            'active_migration_noop'=>true,'candidate_in_production_migrator'=>false,'database_version'=>self::version($db)];
    }

    public static function familyCount(Database $db): int
    {
        $n = 0;
        foreach (array_keys(self::TABLES) as $name) {
            $n += (int)$db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$name]);
        }
        return $n;
    }

    public static function triggerCount(Database $db): int
    {
        return (int)$db->scalar("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'm17_%_no_%'");
    }

    public static function scope(Database $db, string $key = 'm17qa'): array
    {
        $clock = new \Allfather\Loopia\Core\FixedClock(self::CLOCK, 1);
        $tenant = Seed::tenantWorkspace($db, $clock, $key);
        $competitor = Ids::new();
        $db->insert('competitors', [
            'competitor_id'=>$competitor,'schema_version'=>'1',
            'tenant_id'=>$tenant['tenant_id'],'workspace_id'=>$tenant['workspace_id'],
            'display_name'=>'M17 synthetic fixture only','competitor_class'=>'DIRECT',
            'status'=>'ACTIVE','created_at'=>$clock->now(),
        ]);
        return ['tenant_id'=>$tenant['tenant_id'],'workspace_id'=>$tenant['workspace_id'],
            'competitor_id'=>$competitor,'question_id'=>Ids::new()];
    }

    public static function inputs(array $scope, ?string $successor = null): array
    {
        $inputs = Li07aFixture::inputs(null, $scope);
        if ($successor !== null) {
            self::requireTrue(in_array($successor, ['b','c','d','e'], true), 'BAD_SUCCESSOR_SEED');
            $newRef = $inputs['input_set']['refs'][0];
            $newRef['ref_id'] = 'm17-synthetic-ref-'.$successor;
            $newRef['source_ref_id'] = 'm17-synthetic-source-'.$successor;
            $newRef['payload_sha256'] = str_repeat($successor, 64);
            $set = InputSet::freeze(['scope'=>$scope, 'data_class'=>$inputs['input_set']['data_class'],
                'refs'=>[...$inputs['input_set']['refs'], $newRef]]);
            self::requireTrue($set->isOk(), 'FROZEN_SUCCESSOR_INVALID');
            $inputs['input_set'] = $set->value();
        }
        return $inputs;
    }

    public static function storeRow(Database $db, array $inputs, ?string $prior = null): string
    {
        $r = self::memory($db)->persistCandidate($inputs, $prior);
        self::requireTrue($r->isOk(), 'SYNTHETIC_MEMORY_PERSIST_REFUSED');
        return (string)$r->value()['memory_id'];
    }

    public static function installOverlapTrigger(Database $db): void
    {
        // A missing profile is the first contested write in an identical-run race.
        // Hold both that INSERT and the run INSERT: either one can be the observed
        // overlapping transaction, even if InnoDB deadlocks before the run stage.
        foreach ([
            'profile'=>'temporal_compression_profiles',
            'run'=>'temporal_compression_runs',
        ] as $suffix=>$table) {
            $db->pdo()->exec("CREATE TRIGGER m17_qa_overlap_{$suffix} BEFORE INSERT ON {$table}
                FOR EACH ROW BEGIN
                    IF COALESCE(@m17_qa_overlap,0)=1 THEN
                        SET @m17_qa_lock_status = GET_LOCK('m17_qa_overlap_lock',5);
                        DO SLEEP(4);
                        DO RELEASE_LOCK('m17_qa_overlap_lock');
                    END IF;
                END");
        }
    }

    public static function removeOverlapTrigger(Database $db): void
    {
        $db->pdo()->exec('DROP TRIGGER IF EXISTS m17_qa_overlap_profile');
        $db->pdo()->exec('DROP TRIGGER IF EXISTS m17_qa_overlap_run');
    }

    public static function manifest(Database $db): array
    {
        $tables = $db->all("SELECT table_name AS name FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name");
        $rows = [];
        foreach ($tables as $row) {
            $name = (string)$row['name'];
            $all = $db->all('SELECT * FROM '.Database::identifier($name));
            $encoded = [];
            foreach ($all as $values) $encoded[] = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            sort($encoded, SORT_STRING);
            $rows[$name] = ['count'=>count($encoded),'sha256'=>hash('sha256',implode("\n",$encoded))];
        }
        $trigger = $db->all("SELECT trigger_name,action_statement,action_timing,event_manipulation,event_object_table
            FROM information_schema.triggers WHERE trigger_schema=DATABASE() ORDER BY trigger_name");
        $triggers = hash('sha256',json_encode($trigger,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return ['tables'=>$rows,'triggers'=>['count'=>count($trigger),'sha256'=>$triggers],
            'family_count'=>self::familyCount($db),'m17_append_only_triggers'=>self::triggerCount($db),
            'db_line'=>preg_match('/^10\.(6|11)/', self::version($db), $match) ? $match[0] : 'UNKNOWN'];
    }

    public static function verify(Database $db, array $expected): array
    {
        $now = self::manifest($db);
        self::requireTrue($now === $expected, 'RESTORED_METADATA_SCHEMA_OR_ROWS_DIFFER');
        self::requireTrue($now['family_count'] === 7 && $now['m17_append_only_triggers'] === 14,
            'RESTORED_M17_G21_OBJECTS_MISSING');
        foreach (array_keys(self::TABLES) as $name) {
            self::requireTrue(($now['tables'][$name]['count'] ?? 0) > 0, 'RESTORED_G21_FAMILY_NOT_POPULATED');
        }
        self::requireTrue(
            (int)$db->scalar("SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '280_m17_%'") === 0,
            'RESTORE_PROMOTED_UNADOPTED_G21'
        );
        return ['status'=>'PASS','family_count'=>7,'append_only_triggers'=>14,
            'all_table_digest_match'=>true,'database_version'=>self::version($db)];
    }

    public static function seed(Database $db): array
    {
        $scope = self::scope($db,'m17-backup');
        $inputs = self::inputs($scope);
        $base = self::storeRow($db,$inputs);
        self::storeRow($db,self::inputs($scope,'b'),$base);
        self::storeRow($db,self::inputs($scope,'c'),$base);
        $other = $scope;
        $other['question_id'] = Ids::new();
        self::storeRow($db,self::inputs($other));
        $foreign = self::scope($db,'m17-foreign');
        $sample = self::storeRow($db,self::inputs($foreign));
        $store = self::memory($db);
        self::requireTrue($store->headCandidates($scope,$inputs['input_set']['data_class'],
            $inputs['window']['kind'],$inputs['window']['start_inclusive_utc'],
            $inputs['window']['end_exclusive_utc'])->value()['status']==='AMBIGUOUS_HEAD_HOLD',
            'PRE_BACKUP_FORK_AMBIGUITY_MISSING');
        self::requireTrue($store->replayCandidate($foreign,$base)->reasonCode()==='M17_REPLAY_CANDIDATE_NOT_FOUND',
            'PRE_BACKUP_CROSS_TENANT_ISOLATION_FAILED');
        self::requireTrue($store->replayCandidate($scope,$sample)->reasonCode()==='M17_REPLAY_CANDIDATE_NOT_FOUND',
            'PRE_BACKUP_REVERSE_SCOPE_ISOLATION_FAILED');
        $profile=(string)$db->scalar('SELECT profile_id FROM temporal_compression_profiles WHERE tenant_id=? AND workspace_id=? LIMIT 1',
            [$scope['tenant_id'],$scope['workspace_id']]);
        $db->insert('temporal_compression_profile_rules', [
            'profile_rule_id'=>Ids::new(),'profile_id'=>$profile,
            'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
            'ordinal_no'=>0,'rule_digest'=>str_repeat('b',64),
            'rule_json'=>'{"synthetic_only":true}','created_at'=>self::CLOCK,
        ]);
        return ['scope'=>$scope,'memory_id'=>$base,'foreign_scope'=>$foreign,
            'foreign_memory_id'=>$sample,'input_set'=>$inputs,
            'manifest'=>self::manifest($db)];
    }

    public static function checkSemantics(Database $db, array $seed): array
    {
        $scope=$seed['scope']; $inputs=$seed['input_set']; $base=$seed['memory_id'];
        $store=self::memory($db);
        self::requireTrue($store->headCandidates($scope,$inputs['input_set']['data_class'],
            $inputs['window']['kind'],$inputs['window']['start_inclusive_utc'],
            $inputs['window']['end_exclusive_utc'])->value()['status']==='AMBIGUOUS_HEAD_HOLD',
            'RESTORED_FORK_NOT_AMBIGUOUS');
        self::requireTrue($store->replayCandidate($scope,$base)->value()['outcome']==='MATCH_METADATA_ONLY',
            'RESTORED_REPLAY_NOT_DETERMINISTIC');
        self::requireTrue($store->replayCandidate($seed['foreign_scope'],$base)->reasonCode()==='M17_REPLAY_CANDIDATE_NOT_FOUND',
            'RESTORED_CROSS_TENANT_READ');
        $r = $store->persistCandidate($inputs);
        self::requireTrue($r->isOk() && $r->value()['action']==='EXACT_REPLAY_NO_WRITE',
            'RESTORED_IDENTICAL_REPLAY_WROTE_DATA');
        $before=(int)$db->scalar('SELECT COUNT(*) FROM temporal_memories');
        self::requireTrue($before===5, 'RESTORED_MEMORY_CARDINALITY');
        try {
            $db->run('UPDATE temporal_memories SET content_digest=content_digest WHERE temporal_memory_id=?',[$base]);
            self::fail('RESTORED_APPEND_ONLY_TRIGGER_PERMITTED_UPDATE');
        } catch (\PDOException) { /* required SQL-level refusal */ }
        self::requireTrue((int)$db->scalar('SELECT COUNT(*) FROM temporal_memories')===5,
            'RESTORED_APPEND_ONLY_CHANGED_ROWS');
        return ['status'=>'PASS','fork_hold'=>true,'scoped_replay'=>true,'immutable_sql'=>true,'memories'=>5];
    }
}

// Importable by independent QA scripts without executing the CLI dispatch.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

try {
    $cmd = $argv[1] ?? '';
    if ($cmd === 'guard') {
        $db=M17Qa::db(false);
        echo json_encode(['status'=>'PASS','server_version'=>M17Qa::version($db),
            'server_fingerprint'=>hash('sha256',(string)$db->scalar('SELECT @@hostname'))])."\n";
    } elseif ($cmd === 'init') {
        $db=M17Qa::db(false);
        echo json_encode(M17Qa::init($db),JSON_THROW_ON_ERROR)."\n";
    } elseif ($cmd === 'seed') {
        $db=M17Qa::db();
        $seed=M17Qa::seed($db);
        $out=$argv[2]??'';
        M17Qa::requireTrue($out!=='' && !file_exists($out), 'SEED_OUTPUT_PATH_REQUIRED');
        M17Qa::file($out,$seed);
        echo json_encode(['status'=>'PASS','family_count'=>$seed['manifest']['family_count'],
            'triggers'=>$seed['manifest']['m17_append_only_triggers']])."\n";
    } elseif ($cmd === 'verify') {
        $db=M17Qa::db();
        $seed=M17Qa::json($argv[2]??'');
        $result=M17Qa::verify($db,$seed['manifest']);
        $semantics=M17Qa::checkSemantics($db,$seed);
        echo json_encode(['status'=>'PASS','metadata'=>$result,'semantics'=>$semantics])."\n";
    } elseif ($cmd === 'manifest') {
        $db=M17Qa::db();
        $out=$argv[2]??'';
        M17Qa::requireTrue($out!=='' && !file_exists($out),'MANIFEST_OUTPUT_PATH_REQUIRED');
        M17Qa::file($out, M17Qa::manifest($db));
        echo '{"status":"PASS","manifest_written":true}'."\n";
    } elseif ($cmd === 'compare') {
        $db=M17Qa::db();
        $expected=M17Qa::json($argv[2]??'');
        echo json_encode(M17Qa::verify($db,$expected),JSON_THROW_ON_ERROR)."\n";
    } elseif ($cmd === 'hold') {
        $db=M17Qa::db();
        $pdo=$db->pdo();
        $ready=$argv[2]??'';
        M17Qa::requireTrue($ready!==''&&!file_exists($ready),'HOLD_READY_FILE_REQUIRED');
        $pdo->beginTransaction();
        $db->run("UPDATE m17_qa_crash_probe SET value_text='UNCOMMITTED_MUST_ROLL_BACK' WHERE id=1");
        M17Qa::file($ready,['connection_id'=>(int)$db->scalar('SELECT CONNECTION_ID()'),
            'transaction_open'=>$pdo->inTransaction()]);
        // Docker forcibly kills the server while this transaction is open.
        $end=microtime(true)+35;
        while (microtime(true)<$end) usleep(100000);
        $pdo->rollBack();
        M17Qa::fail('HOLD_WAS_NOT_INTERRUPTED_BY_CONTAINER_CRASH');
    } elseif ($cmd === 'race-worker') {
        $case=M17Qa::json($argv[2]??'');
        $ready=$argv[3]??''; $go=$argv[4]??''; $out=$argv[5]??'';
        M17Qa::requireTrue($ready!==''&&$go!==''&&$out!==''&&!file_exists($ready),'BAD_WORKER_PATHS');
        $db=M17Qa::db();
        $connection=(int)$db->scalar('SELECT CONNECTION_ID()');
        $db->pdo()->exec('SET @m17_qa_overlap=1');
        M17Qa::file($ready,['connection_id'=>$connection,'pid'=>getmypid()]);
        $end=microtime(true)+12;
        while (!is_file($go) && microtime(true)<$end) usleep(20000);
        M17Qa::requireTrue(is_file($go),'RACE_BARRIER_TIMEOUT');
        $result=M17Qa::memory($db)->persistCandidate($case['inputs'],$case['prior']??null);
        M17Qa::file($out,[
            'connection_id'=>$connection,'pid'=>getmypid(),
            'is_ok'=>$result->isOk(),'reason'=>$result->isOk()?'OK':$result->reasonCode(),
            'sqlstate'=>$result->isOk()?'':(string)($result->detail['sqlstate']??''),
            'driver_code'=>$result->isOk()?0:(int)($result->detail['driver_code']??0),
            'action'=>$result->isOk()?($result->value()['action']??'UNKNOWN'):'NONE',
            'memory_id'=>$result->isOk()?($result->value()['memory_id']??null):null,
        ]);
    } else {
        M17Qa::fail('UNKNOWN_QA_COMMAND');
    }
} catch (\Throwable $e) {
    // Never include native exception messages (SQL text and credentials may be embedded).
    $category = (new \ReflectionClass($e))->getShortName();
    $driver = $e instanceof \PDOException ? '_SQLSTATE_'.preg_replace('/[^A-Z0-9]/','_',strtoupper((string)$e->getCode()))
        .'_CODE_'.(int)($e->errorInfo[1]??0) : '';
    M17Qa::fail('EXCEPTION_'.strtoupper($category).$driver);
}
