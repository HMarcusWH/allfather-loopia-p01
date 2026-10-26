<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\{Database,Migrator};
use Allfather\Loopia\Substrate\M17\{InputSet,MemoryStore};
use Allfather\Loopia\Tests\{Assert,Harness,Li07aFixture,Seed};

require_once dirname(__DIR__).'/support/Li07aFixture.php';

/*
 * LI07B / U04 **CANDIDATE ONLY**.
 * Logical, metadata-only populated export/rebuild/replay inside disposable CI MariaDB.
 * NOT a host-volume/database backup, production restore or a granted schema migration.
 * No privileged source/provider/M05 access and no persistence outside the disposable schema.
 */
$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$root = dirname(__DIR__, 2);
$sqlPath = $root . '/docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql';
$statements = Migrator::splitStatements((string) file_get_contents($sqlPath));
Assert::same(21, count($statements), '7 tables + 14 append-only triggers in proposal, not a migrator release');
$keys = [
    'temporal_compression_profiles' => 'profile_id',
    'temporal_compression_profile_rules' => 'profile_rule_id',
    'temporal_compression_runs' => 'compression_run_id',
    'temporal_rollups' => 'temporal_rollup_id',
    'temporal_memories' => 'temporal_memory_id',
    'temporal_memory_status_transitions' => 'temporal_memory_status_transition_id',
    'compression_lineage_links' => 'compression_lineage_link_id',
];
$tables = array_keys($keys);
$drop = array_reverse($tables);
$rows = static function (Database $d) use ($keys): array {
    $out = [];
    foreach ($keys as $table => $pk) {
        // All identifiers are fixed literals, never input or dynamic user data.
        $out[$table] = $d->all('SELECT * FROM `'.$table.'` ORDER BY `'.$pk.'`');
    }
    return $out;
};
$rejects = static function (callable $attempt, string $why): void {
    try { $attempt(); Assert::fail($why . ': accepted'); }
    catch (\PDOException) { Assert::pass(); }
};
try {
    foreach ($statements as $statement) $db->pdo()->exec($statement);
    if (!defined('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED')) {
        define('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED', true);
    }
    $clock = Harness::clock('2026-10-26T18:00:00.000Z');
    $tenantA = Seed::tenantWorkspace($db, $clock, 'm17-restore-a');
    $tenantB = Seed::tenantWorkspace($db, $clock, 'm17-restore-b');
    $competitorId = Ids::new();
    $db->insert('competitors', [
        'competitor_id'=>$competitorId, 'schema_version'=>'1',
        'tenant_id'=>$tenantA['tenant_id'], 'workspace_id'=>$tenantA['workspace_id'],
        'display_name'=>'Synthetic test-only competitor', 'competitor_class'=>'DIRECT',
        'status'=>'ACTIVE','created_at'=>$clock->now(),
    ]);
    $scope = ['tenant_id'=>$tenantA['tenant_id'], 'workspace_id'=>$tenantA['workspace_id'],
        'competitor_id'=>$competitorId, 'question_id'=>Ids::new()];
    $inputs = Li07aFixture::inputs(null, $scope);
    $maker = MemoryStore::forDisposableTest($db, $clock);
    Assert::true($maker->isOk(), 'disposable-only writer factory refuses general runtime');
    $store = $maker->value();
    $base = $store->persistCandidate($inputs);
    Assert::true($base->isOk(), 'initial non-admitted candidate is persisted to disposable schema');
    if (!$base->isOk()) return;
    $baseId = $base->value()['memory_id'];
    $children = [];
    foreach (['b', 'c'] as $letter) {
        $extra = $inputs['input_set']['refs'][0];
        $extra['ref_id'] = 'synthetic-successor-' . $letter;
        $extra['source_ref_id'] = 'synthetic-source-' . $letter;
        $extra['payload_sha256'] = str_repeat($letter, 64);
        $new = InputSet::freeze([
            'scope'=>$scope, 'data_class'=>$inputs['input_set']['data_class'],
            'refs'=>[...$inputs['input_set']['refs'], $extra],
        ]);
        Assert::true($new->isOk(), 'new late-arriving metadata set freezes correctly');
        $next = $inputs;
        $next['input_set'] = $new->value();
        $saved = $store->persistCandidate($next, $baseId);
        Assert::true($saved->isOk(), 'both incomparable same-period successors are retained');
        if (!$saved->isOk()) return;
        $children[] = $saved->value()['memory_id'];
    }
    Assert::same('AMBIGUOUS_HEAD_HOLD', $store->headCandidates(
        $scope, $inputs['input_set']['data_class'], $inputs['window']['kind'],
        $inputs['window']['start_inclusive_utc'], $inputs['window']['end_exclusive_utc'],
    )->value()['status'], 'before export, incomparable heads hold instead of last-write-wins');
    Assert::same('MATCH_METADATA_ONLY', $store->replayCandidate($scope, $baseId)->value()['outcome'],
        'source-free replay before snapshot works with metadata only');

    // Question-specific partition: identical competitor, different question != same memory.
    $otherScope = $scope; $otherScope['question_id'] = Ids::new();
    $otherInputs = Li07aFixture::inputs(null, $otherScope);
    $other = $store->persistCandidate($otherInputs);
    Assert::true($other->isOk(), 'same competitor with different question has separate identity');
    Assert::same('M17_REPLAY_CANDIDATE_NOT_FOUND',
        $store->replayCandidate($otherScope, $baseId)->reasonCode(),
        'question scope prevents cross-question read of valid memory id');
    Assert::same('M17_REPLAY_CANDIDATE_NOT_FOUND',
        $store->replayCandidate([
            'tenant_id'=>$tenantB['tenant_id'], 'workspace_id'=>$tenantB['workspace_id'],
            'competitor_id'=>$competitorId, 'question_id'=>$scope['question_id'],
        ], $baseId)->reasonCode(), 'cross-tenant memory never enumerates');

    // Populate the seventh family: a rule is only metadata, NOT an adopted profile or law.
    $profileId = (string)$db->scalar('SELECT profile_id FROM temporal_compression_profiles LIMIT 1');
    $db->insert('temporal_compression_profile_rules', [
        'profile_rule_id'=>Ids::new(), 'profile_id'=>$profileId,
        'tenant_id'=>$scope['tenant_id'], 'workspace_id'=>$scope['workspace_id'],
        'ordinal_no'=>0,'rule_digest'=>str_repeat('b',64),
        'rule_json'=>'{"synthetic_fixture_only":true}','created_at'=>$clock->now(),
    ]);
    $before = $rows($db);
    Assert::same(7, count($before), 'all seven families included in logical metadata export');
    foreach ($before as $table=>$data) Assert::true(count($data) >= 1, 'populated candidate family: ' . $table);
    Assert::same(4, count($before['temporal_memories']), 'two branches plus separate question preserved');
    Assert::same(14, (int)$db->scalar(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'm17_%_no_%'"
    ), '14 durable append-only triggers exist before export');

    // The database, not PHP-only convention, blocks UPDATE/DELETE on every family.
    foreach ($keys as $table=>$pk) {
        $id = $before[$table][0][$pk];
        $rejects(static fn()=> $db->run('UPDATE `'.$table.'` SET `'.$pk.'`=? WHERE `'.$pk.'`=?',[$id,$id]),
            $table.' UPDATE must be forbidden by SQL trigger');
        $rejects(static fn()=> $db->run('DELETE FROM `'.$table.'` WHERE `'.$pk.'`=?',[$id]),
            $table.' DELETE must be forbidden by SQL trigger');
    }

    // The ordinary production migration ledger is unaffected by *proposed* SQL.
    $again = (new Migrator($db, $root.'/database/migrations'))->up();
    Assert::same([], $again['applied'], 'normal migration no-op applies no candidate DDL');
    Assert::same([], $again['drifted'], 'no mutation of any retained applied migration');
    Assert::same(0, (int)$db->scalar(
        "SELECT COUNT(*) FROM schema_migrations WHERE migration_id LIKE '280_m17_%'"
    ), 'U04 has not been installed or migrated');
    foreach (array_slice($statements,0,7) as $ddl) $db->pdo()->exec($ddl);
    Assert::same($before, $rows($db), 'CREATE TABLE IF NOT EXISTS re-run leaves all populated candidate rows intact');

    // Independent connection proves server-enforced uniqueness, not an artificial single-connection check.
    $second = Harness::secondConnection();
    Assert::true($second !== null, 'independent MariaDB connection exists for server constraint verification');
    if ($second !== null) {
        $rootRow = $before['temporal_compression_runs'][0];
        $rootRow['compression_run_id'] = Ids::new();
        $rejects(static fn()=> $second->insert('temporal_compression_runs', $rootRow),
            'second independent connection cannot duplicate exact derivation identity');
        Assert::same($before['temporal_compression_runs'], $rows($db)['temporal_compression_runs'],
            'second-connection failed duplicate does not alter original immutable run');
        $foreign = $before['compression_lineage_links'][0];
        $foreign['compression_lineage_link_id'] = Ids::new();
        $foreign['tenant_id'] = $tenantB['tenant_id'];
        $foreign['workspace_id'] = $tenantB['workspace_id'];
        $rejects(static fn()=> $second->insert('compression_lineage_links', $foreign),
            'second independent connection cannot point foreign tenant at another tenant memory');
    }

    // Disposable **logical** populated rebuild (not mysqldump, not Railway storage restore).
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    try { foreach (array_reverse($tables) as $table) $db->pdo()->exec('DROP TABLE `'.$table.'`'); }
    finally { $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1'); }
    foreach ($statements as $stmt) $db->pdo()->exec($stmt);
    foreach (['temporal_compression_profiles','temporal_compression_profile_rules',
              'temporal_compression_runs','temporal_rollups'] as $table) {
        foreach ($before[$table] as $data) $db->insert($table,$data);
    }
    $pending = $before['temporal_memories'];
    $known = [];
    while ($pending !== []) {
        $rest = []; $progress = 0;
        foreach ($pending as $data) {
            $parent = $data['supersedes_memory_id'];
            if ($parent === null || isset($known[$parent])) {
                $db->insert('temporal_memories',$data);
                $known[$data['temporal_memory_id']] = true;
                $progress++;
            } else $rest[]=$data;
        }
        if ($progress === 0) throw new \RuntimeException('LI07B test-only restore could not resolve supersession ancestry');
        $pending = $rest;
    }
    foreach (['temporal_memory_status_transitions','compression_lineage_links'] as $table) {
        foreach ($before[$table] as $data) $db->insert($table,$data);
    }
    Assert::same($before, $rows($db), 'populated logical export/rebuild matches exact database rows in all seven families');
    Assert::same(14, (int)$db->scalar(
        "SELECT COUNT(*) FROM information_schema.triggers
         WHERE trigger_schema=DATABASE() AND trigger_name LIKE 'm17_%_no_%'"
    ), 'append-only constraints survived logical rebuild');
    Assert::same('AMBIGUOUS_HEAD_HOLD',$store->headCandidates(
        $scope,$inputs['input_set']['data_class'],$inputs['window']['kind'],
        $inputs['window']['start_inclusive_utc'],$inputs['window']['end_exclusive_utc'],
    )->value()['status'], 'competing successors remain incomparable after rebuild');
    Assert::same('MATCH_METADATA_ONLY',$store->replayCandidate($scope,$baseId)->value()['outcome'],
        'frozen metadata replays without refetch after logical rebuild');
    Assert::same('EXACT_REPLAY_NO_WRITE',$store->persistCandidate($inputs)->value()['action'],
        'idempotent exact-input replay still writes no row after restore');
    Assert::same(false,$store->replayCandidate($scope,$baseId)->value()['source_bytes_verified'],
        'logical restore never authenticates original source bytes');
} finally {
    // Fixture owns no customer data. DROP is test-only, never included in proposed migration.
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
    try { foreach ($drop as $table) $db->pdo()->exec('DROP TABLE IF EXISTS `'.$table.'`'); }
    finally { $db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1'); }
}
