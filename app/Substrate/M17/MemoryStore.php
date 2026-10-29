<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;

/**
 * LI-07B candidate persistence, U04 NOT ADOPTED. This is NOT wired to the app.
 * Its only DB factory is an explicit disposable-CLI-test fixture, and the candidate
 * DDL lives outside the production migrator's directory. No real evidence use.
 *
 * The database holds metadata-only candidates. It is not the source of truth for
 * rights, M05 evidence, M02 permissions, P02 state, M10 approval or M16 release.
 */
final class MemoryStore
{
    public const VERSION = 'li07b.candidate-memory-store.v1';

    private function __construct(private readonly Database $db, private readonly Clock $clock) {}

    /**
     * TM-02 explicit governed runtime construction boundary.
     * Candidate source/DDL is not authority to install 280, create a
     * model context or read retained M05 bytes. Before a separate U03/U04
     * adoption and an exact signed owner factory, refusal is unconditional
     * and occurs BEFORE ANY SQL lookup.
     */
    public static function forRuntime(Database $db, Clock $clock, array $ownerAdmission = []): Outcome
    {
        return Outcome::refuse('TM02_U03_U04_RUNTIME_FACTORY_NOT_ADOPTED');
    }

    /** A deliberate TEST-ONLY bootstrap. No production factory exists before U04. */
    public static function forDisposableTest(Database $db, Clock $clock): Outcome
    {
        if (PHP_SAPI !== 'cli' || !defined('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED') !== true) {
            return Outcome::refuse('M17_U04_NOT_ADOPTED_PERSISTENCE_DISABLED');
        }
        return Outcome::ok(new self($db, $clock));
    }

    /**
     * Stable proposed identity. It is a *candidate* (not an adopted canonical key).
     * Output key is (run_id, PERIOD_SUMMARY, ordinal 0) and is a separate unique law.
     */
    public static function preview(array $frozenInputs): Outcome
    {
        $rollup = RollupEngine::derive($frozenInputs);
        if (!$rollup->isOk()) return $rollup;
        $value = $rollup->value();
        if ($value['status'] !== 'OFFLINE_NOT_ADMITTED') {
            return Outcome::refuse('M17_CANDIDATE_EMPTY_INPUT_REFUSED');
        }
        $scope = $value['scope'];
        $period = $value['window_bounds'];
        $identity = [
            'identity_version' => self::VERSION,
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'subject_kind' => 'P01_COMPETITOR',
            'subject_id' => $scope['competitor_id'],
            'question_id' => $scope['question_id'],
            'data_class' => $value['data_class'],
            'window_kind' => $period['kind'],
            'period_start_utc' => $period['start_inclusive_utc'],
            'period_end_utc' => $period['end_exclusive_utc'],
            'engine_version' => $value['engine_version'],
            'input_digest' => $value['input_digest'],
            'profile_digest' => $value['profile_resolution_digest'],
        ];
        return Outcome::ok([
            'identity' => $identity,
            'derivation_identity_digest' => Digest::canonicalDigest($identity),
            'rollup' => $value,
            'frozen_inputs' => $frozenInputs,
            'candidate_state' => 'NOT_ADMITTED',
            'historical_reuse_admitted' => false,
            'memory_is_evidence' => false,
            'memory_is_release' => false,
        ]);
    }

    /**
     * Disposable fixtures only. Strict transactional deduplication; a new input digest
     * produces a new immutable run. An explicit late-input predecessor must pass the
     * non-dropping LI-07A lineage test; incomparable forks coexist, never LWW.
     */
    public function persistCandidate(array $frozenInputs, ?string $priorMemoryId = null): Outcome
    {
        // Original v1 entrypoint, semantic identity and stable digests unchanged.
        return $this->persistByIdentity($frozenInputs, $priorMemoryId, null);
    }

    /**
     * TM-02: same disposable 280 owner and transaction law, but a separately
     * versioned U04 Option A P01 run identity. Never export as production
     * constructor, and never let the caller choose a run digest.
     */
    public function persistP01V2Candidate(array $frozenInputs, ?string $priorMemoryId = null): Outcome
    {
        $v2 = P01WindowProfileV2::freeze($frozenInputs);
        if (!$v2->isOk()) {
            return Outcome::refuse('TM02_V2_FROZEN_INPUTS_NOT_REPLAYABLE');
        }
        return $this->persistByIdentity($frozenInputs, $priorMemoryId, $v2->value());
    }

    /** @param ?array<string,mixed> $v2 Exactly rederived, never caller-assigned. */
    private function persistByIdentity(
        array $frozenInputs, ?string $priorMemoryId, ?array $v2,
    ): Outcome {
        $candidate = self::preview($frozenInputs);
        if (!$candidate->isOk()) return $candidate;
        $v = $candidate->value();
        // V2 only changes the immutable run derivation key; the original
        // seven-table 280 FK topology, v1 rollup and stored reference rows
        // retain their old physical shapes for independent replay.
        if ($v2 !== null) {
            $fresh = P01WindowProfileV2::freeze($frozenInputs);
            if (!$fresh->isOk()
                || $fresh->value() !== $v2
                || !P01WindowProfileV2::verify($v2)->isOk()
                || $v2['v1_rollup_digest'] !== $v['rollup']['rollup_digest']
                || $v2['run_identity']['input_digest'] !== $v['identity']['input_digest']
                || $v2['run_identity']['resolved_profile_digest'] !== $v['identity']['profile_digest']
                || $v2['run_identity']['engine_version'] !== $v['identity']['engine_version']) {
                return Outcome::refuse('TM02_V2_DERIVATION_IDENTITY_MISMATCH');
            }
            $v['derivation_identity_digest'] = $v2['derivation_identity_digest'];
        }
        $id = $v['identity'];
        if ($priorMemoryId !== null && !Ids::isValid($priorMemoryId)) {
            return Outcome::refuse('M17_PREDECESSOR_ID_INVALID');
        }
        try {
            return $this->db->transaction(function (Database $tx) use ($v, $id, $priorMemoryId, $frozenInputs, $v2): Outcome {
                $existing = $tx->one(
                    'SELECT m.temporal_memory_id,m.supersedes_memory_id,r.rollup_digest
                     FROM temporal_compression_runs cr
                     JOIN temporal_memories m ON m.compression_run_id=cr.compression_run_id
                     JOIN temporal_rollups r ON r.temporal_rollup_id=m.temporal_rollup_id
                     WHERE cr.derivation_identity_digest=? AND cr.tenant_id=? AND cr.workspace_id=?
                       AND m.memory_class=? AND m.output_ordinal=0 FOR UPDATE',
                    [$v['derivation_identity_digest'],$id['tenant_id'],$id['workspace_id'],'PERIOD_SUMMARY'],
                );
                if ($existing !== null) {
                    if ($existing['rollup_digest'] !== $v['rollup']['rollup_digest']
                        || $existing['supersedes_memory_id'] !== $priorMemoryId) {
                        return Outcome::refuse('M17_CANDIDATE_IDENTICAL_RUN_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_REPLAY_NO_WRITE','memory_id'=>$existing['temporal_memory_id'],
                        'rollup_digest'=>$existing['rollup_digest'],'historical_reuse_admitted'=>false,
                    ]);
                }
                if ($priorMemoryId !== null) {
                    $prior = $tx->one(
                        'SELECT m.temporal_memory_id,r.rollup_json,
                                cr.derivation_identity_digest,cr.frozen_inputs_json
                         FROM temporal_memories m
                         JOIN temporal_rollups r ON r.temporal_rollup_id=m.temporal_rollup_id
                         JOIN temporal_compression_runs cr ON cr.compression_run_id=m.compression_run_id
                         WHERE m.temporal_memory_id=? AND m.tenant_id=? AND m.workspace_id=?
                           AND m.subject_kind=? AND m.subject_id=? AND m.data_class=?
                           AND m.memory_class=? FOR UPDATE',
                        [$priorMemoryId,$id['tenant_id'],$id['workspace_id'],$id['subject_kind'],
                            $id['subject_id'],$id['data_class'],'PERIOD_SUMMARY'],
                    );
                    if ($prior === null) return Outcome::refuse('M17_PREDECESSOR_NOT_AVAILABLE');
                    if ($v2 !== null) {
                        $oldInputs=json_decode((string)$prior['frozen_inputs_json'],true,64,JSON_THROW_ON_ERROR);
                        $oldV2=is_array($oldInputs) ? P01WindowProfileV2::freeze($oldInputs) : Outcome::refuse('TM02_PREDECESSOR_INPUT_INVALID');
                        if (!$oldV2->isOk() || $oldV2->value()['derivation_identity_digest']!==$prior['derivation_identity_digest']) {
                            return Outcome::refuse('TM02_V2_PREDECESSOR_VERSION_MISMATCH');
                        }
                    }
                    $original = json_decode((string)$prior['rollup_json'], true, 64, JSON_THROW_ON_ERROR);
                    $successor = Lineage::proposeLateSuccessor($original, $v['rollup']);
                    if (!$successor->isOk()) return $successor;
                }
                // Tenant and workspace must be related, not merely independently valid IDs.
                if ($tx->one('SELECT 1 FROM workspaces WHERE workspace_id=? AND tenant_id=?',
                    [$id['workspace_id'],$id['tenant_id']]) === null) {
                    return Outcome::refuse('M17_FIXTURE_SCOPE_NOT_AVAILABLE');
                }
                if ($tx->one('SELECT 1 FROM competitors WHERE competitor_id=? AND tenant_id=? AND workspace_id=?',
                    [$id['subject_id'],$id['tenant_id'],$id['workspace_id']]) === null) {
                    return Outcome::refuse('M17_FIXTURE_COMPETITOR_NOT_AVAILABLE');
                }
                $now = $this->clock->now();
                $profileCandidate = $frozenInputs['profile_candidate'];
                $profile = $tx->one(
                    'SELECT profile_id,profile_json FROM temporal_compression_profiles
                     WHERE tenant_id=? AND workspace_id=? AND subject_kind=? AND subject_id=? AND profile_digest=? FOR UPDATE',
                    [$id['tenant_id'],$id['workspace_id'],$id['subject_kind'],$id['subject_id'],$id['profile_digest']],
                );
                $profileJson = self::json($profileCandidate);
                if ($profile === null) {
                    $profileId = Ids::new();
                    $tx->insert('temporal_compression_profiles', [
                        'profile_id'=>$profileId,'tenant_id'=>$id['tenant_id'],
                        'workspace_id'=>$id['workspace_id'],'subject_kind'=>$id['subject_kind'],
                        'subject_id'=>$id['subject_id'],'profile_version'=>(string)$profileCandidate['version'],
                        'profile_digest'=>$id['profile_digest'],'profile_json'=>$profileJson,
                        'candidate_state'=>'NOT_ADMITTED','created_at'=>$now,
                    ]);
                } else {
                    $profileId = (string)$profile['profile_id'];
                    if ($profile['profile_json'] !== $profileJson) {
                        return Outcome::refuse('M17_PROFILE_DIGEST_COLLISION');
                    }
                }
                $runId=Ids::new();$rollupId=Ids::new();$memoryId=Ids::new();
                $scope=['tenant_id'=>$id['tenant_id'],'workspace_id'=>$id['workspace_id']];
                $tx->insert('temporal_compression_runs', [
                    'compression_run_id'=>$runId,...$scope,'subject_kind'=>$id['subject_kind'],
                    'subject_id'=>$id['subject_id'],'data_class'=>$id['data_class'],
                    'question_id'=>$id['question_id'],'window_kind'=>$id['window_kind'],'period_start_utc'=>$id['period_start_utc'],
                    'period_end_utc'=>$id['period_end_utc'],'engine_version'=>$id['engine_version'],
                    'input_digest'=>$id['input_digest'],'profile_digest'=>$id['profile_digest'],
                    'derivation_identity_digest'=>$v['derivation_identity_digest'],'profile_id'=>$profileId,
                    'frozen_inputs_json'=>self::json($frozenInputs),
                    'window_json'=>self::json($frozenInputs['window']),
                    'profile_json'=>$profileJson,'candidate_state'=>'NOT_ADMITTED','created_at'=>$now,
                ]);
                $tx->insert('temporal_rollups', [
                    'temporal_rollup_id'=>$rollupId,'compression_run_id'=>$runId,...$scope,
                    'rollup_digest'=>$v['rollup']['rollup_digest'],
                    'rollup_json'=>self::json($v['rollup']),'created_at'=>$now,
                ]);
                $tx->insert('temporal_memories', [
                    'temporal_memory_id'=>$memoryId,'compression_run_id'=>$runId,
                    'temporal_rollup_id'=>$rollupId,...$scope,'subject_kind'=>$id['subject_kind'],
                    'subject_id'=>$id['subject_id'],'data_class'=>$id['data_class'],
                    'question_id'=>$id['question_id'],'memory_class'=>'PERIOD_SUMMARY','output_ordinal'=>0,
                    'period_start_utc'=>$id['period_start_utc'],'period_end_utc'=>$id['period_end_utc'],
                    'content_digest'=>$v['rollup']['rollup_digest'],'candidate_state'=>'NOT_ADMITTED',
                    'supersedes_memory_id'=>$priorMemoryId,'created_at'=>$now,
                ]);
                $tx->insert('temporal_memory_status_transitions', [
                    'temporal_memory_status_transition_id'=>Ids::new(),
                    'temporal_memory_id'=>$memoryId,...$scope,'sequence_no'=>1,
                    'event_kind'=>'CANDIDATE_RECORDED',
                    'reason_code'=>$priorMemoryId===null ? 'INITIAL_DISPOSABLE_CANDIDATE' : 'ADDITIVE_LATE_INPUT_CANDIDATE',
                    'recorded_at'=>$now,
                ]);
                foreach ($v['rollup']['lineage']['selected_refs'] as $ordinal=>$ref) {
                    $tx->insert('compression_lineage_links', [
                        'compression_lineage_link_id'=>Ids::new(),
                        'temporal_memory_id'=>$memoryId,...$scope,'reference_ordinal'=>$ordinal,
                        'ref_type'=>$ref['ref_type'],'ref_id'=>$ref['ref_id'],
                        'source_ref_id'=>$ref['source_ref_id'],
                        'observed_at_utc'=>$ref['observed_at_utc'],
                        'payload_sha256'=>$ref['payload_sha256'],'polarity'=>$ref['polarity'],
                        'conflict_flag'=>$ref['conflict_flag'] ? 1 : 0,
                        'diagnostic_flag'=>$ref['diagnostic_flag'] ? 1 : 0,'created_at'=>$now,
                    ]);
                }
                return Outcome::ok([
                    'action'=>'DISPOSABLE_CANDIDATE_RECORDED',
                    'memory_id'=>$memoryId,'compression_run_id'=>$runId,
                    'rollup_digest'=>$v['rollup']['rollup_digest'],
                    'historical_reuse_admitted'=>false,
                ]);
            });
        } catch (\PDOException $e) {
            return Database::classify($e,'M17_IDENTITY_RACE_REFUSED');
        } catch (\Throwable $error) {
            // An exception TYPE is safe diagnostic evidence; never log SQL, parameters or bytes.
            return Outcome::failure('M17_DISPOSABLE_PERSISTENCE_FAILED', [
                'exception_type' => get_debug_type($error),
                'exception_file' => basename($error->getFile()),
                'exception_line' => $error->getLine(),
            ]);
        }
    }

    /**
     * TM-02: scoped readback verifies the stored U04 P01 v2 run key, its
     * original deterministic v1 frozen derivation and EVERY selected
     * reference/contrary flag in the existing 280 lineage table.
     * Reported unavailable refs produce the underlying typed NOT_REPLAYABLE,
     * never a source refetch or reconstructed primary evidence.
     *
     * @param list<string> $unavailableRefIds Explicit caller-described missing input, not M05 admission
     */
    public function replayP01V2Candidate(
        array $scope, string $memoryId, array $unavailableRefIds = [],
    ): Outcome {
        if (!Vocabulary::scope($scope) || !Ids::isValid($memoryId)
            || !array_is_list($unavailableRefIds)
            || count($unavailableRefIds) > InputSet::MAX_REFS) {
            return Outcome::refuse('TM02_V2_REPLAY_SELECTOR_INVALID');
        }
        try {
            $row=$this->db->one(
                'SELECT m.temporal_memory_id,m.content_digest,m.candidate_state,
                        r.derivation_identity_digest,r.frozen_inputs_json,
                        r.input_digest,r.profile_digest,r.engine_version,
                        rr.rollup_json,rr.rollup_digest
                 FROM temporal_memories m
                 JOIN temporal_compression_runs r
                   ON r.compression_run_id=m.compression_run_id
                 JOIN temporal_rollups rr
                   ON rr.temporal_rollup_id=m.temporal_rollup_id
                 WHERE m.temporal_memory_id=? AND m.tenant_id=? AND m.workspace_id=?
                   AND m.subject_kind=? AND m.subject_id=? AND r.question_id=?
                   AND m.candidate_state=? AND r.candidate_state=?',
                [$memoryId,$scope['tenant_id'],$scope['workspace_id'],
                 'P01_COMPETITOR',$scope['competitor_id'],$scope['question_id'],
                 'NOT_ADMITTED','NOT_ADMITTED'],
            );
            if ($row===null) return Outcome::refuse('TM02_V2_MEMORY_NOT_IN_SCOPE');
            $inputs=json_decode((string)$row['frozen_inputs_json'],true,64,JSON_THROW_ON_ERROR);
            $expected=json_decode((string)$row['rollup_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($inputs) || !is_array($expected)
                || !RollupEngine::verify($expected)->isOk()) {
                return Outcome::refuse('TM02_V2_STORED_INPUTS_INVALID');
            }
            $v2=P01WindowProfileV2::freeze($inputs);
            if(!$v2->isOk()
                || $v2->value()['derivation_identity_digest']!==$row['derivation_identity_digest']
                || $v2->value()['v1_rollup_digest']!==$row['rollup_digest']
                || $expected['rollup_digest']!==$row['rollup_digest']
                || $row['content_digest']!==$row['rollup_digest']
                || $row['input_digest']!==$v2->value()['run_identity']['input_digest']
                || $row['profile_digest']!==$v2->value()['run_identity']['resolved_profile_digest']
                || $row['engine_version']!==$v2->value()['run_identity']['engine_version']
                || $inputs['input_set']['scope']!==$scope) {
                return Outcome::refuse('TM02_V2_DERIVATION_MISMATCH');
            }
            $refs=$this->db->all(
                'SELECT ref_type,ref_id,source_ref_id,observed_at_utc,
                        payload_sha256,polarity,conflict_flag,diagnostic_flag
                 FROM compression_lineage_links
                 WHERE temporal_memory_id=? AND tenant_id=? AND workspace_id=?
                 ORDER BY reference_ordinal',
                [$memoryId,$scope['tenant_id'],$scope['workspace_id']],
            );
            $selected=$expected['lineage']['selected_refs'];
            if(count($refs)!==count($selected)) {
                return Outcome::refuse('TM02_V2_LINEAGE_COUNT_MISMATCH');
            }
            foreach($refs as $ordinal=>$record){
                $ref=$selected[$ordinal];
                foreach(['ref_type','ref_id','source_ref_id','observed_at_utc',
                         'payload_sha256','polarity'] as $field) {
                    if($record[$field]!==$ref[$field]){
                        return Outcome::refuse('TM02_V2_LINEAGE_REFERENCE_MISMATCH');
                    }
                }
                if((int)$record['conflict_flag']!==($ref['conflict_flag']?1:0)
                    || (int)$record['diagnostic_flag']!==($ref['diagnostic_flag']?1:0)){
                    return Outcome::refuse('TM02_V2_LINEAGE_REFERENCE_MISMATCH');
                }
            }
            $replayed=Replay::check([
                'expected_rollup'=>$expected,
                'frozen_inputs'=>$inputs,
                'reported_unavailable_ref_ids'=>$unavailableRefIds,
            ]);
            if(!$replayed->isOk())return $replayed;
            return Outcome::ok([
                'version'=>'tm02.p01-memory-v2-readback.v1',
                'candidate_state'=>'NOT_ADMITTED',
                'identity_version'=>P01WindowProfileV2::VERSION,
                'derivation_identity_digest'=>$v2->value()['derivation_identity_digest'],
                'exact_rollup_digest'=>$expected['rollup_digest'],
                'selected_reference_count'=>count($refs),
                'contrary_reference_count'=>count($expected['lineage']['contrary_or_conflict_ref_ids']),
                'replay'=>$replayed->value(),
                'source_bytes_verified'=>false,
                'current_source_rights_verified'=>false,
                'historical_reuse_admitted'=>false,
                'model_or_customer_output_authorized'=>false,
            ]);
        } catch (\Throwable) {
            return Outcome::failure('TM02_V2_DISPOSABLE_REPLAY_FAILED');
        }
    }

    /** Snapshot of incomparable heads. An ambiguous head is a HOLD, not LWW. */
    public function headCandidates(array $scope, string $dataClass, string $windowKind,
        string $periodStart, string $periodEnd): Outcome
    {
        if (!Vocabulary::scope($scope) || !in_array($dataClass,Vocabulary::SUPPORTED_CLASSES,true)
            || !in_array($windowKind,Vocabulary::WINDOW_KINDS,true)
            || !Vocabulary::utc($periodStart) || !Vocabulary::utc($periodEnd) || $periodStart >= $periodEnd) {
            return Outcome::refuse('M17_HEAD_SCOPE_INVALID');
        }
        $heads = $this->db->all(
            'SELECT m.temporal_memory_id,m.content_digest
             FROM temporal_memories m JOIN temporal_compression_runs r ON r.compression_run_id=m.compression_run_id
             WHERE m.tenant_id=? AND m.workspace_id=? AND m.subject_kind=? AND m.subject_id=?
               AND r.question_id=? AND m.data_class=? AND r.window_kind=? AND m.period_start_utc=? AND m.period_end_utc=?
               AND NOT EXISTS(SELECT 1 FROM temporal_memories child
                 WHERE child.supersedes_memory_id=m.temporal_memory_id
                   AND child.tenant_id=m.tenant_id AND child.workspace_id=m.workspace_id)
             ORDER BY m.temporal_memory_id LIMIT 65',
            [$scope['tenant_id'],$scope['workspace_id'],'P01_COMPETITOR',$scope['competitor_id'],
                $scope['question_id'],$dataClass,$windowKind,$periodStart,$periodEnd],
        );
        return Outcome::ok([
            'status'=>count($heads)>1 ? 'AMBIGUOUS_HEAD_HOLD' : (count($heads)===1
                ? 'SINGLE_CANDIDATE_NOT_ADMITTED' : 'NO_CANDIDATE'),
            'candidates'=>$heads,'source_rights_verified'=>false,
            'historical_reuse_admitted'=>false,
        ]);
    }

    /** Recalculate from frozen metadata only, never from live sources. */
    public function replayCandidate(array $scope, string $memoryId): Outcome
    {
        if (!Vocabulary::scope($scope) || !Ids::isValid($memoryId)) return Outcome::refuse('M17_REPLAY_SELECTOR_INVALID');
        $row=$this->db->one(
            'SELECT r.frozen_inputs_json,rr.rollup_json FROM temporal_memories m
             JOIN temporal_compression_runs r ON r.compression_run_id=m.compression_run_id
             JOIN temporal_rollups rr ON rr.temporal_rollup_id=m.temporal_rollup_id
             WHERE m.temporal_memory_id=? AND m.tenant_id=? AND m.workspace_id=? AND m.subject_kind=? AND m.subject_id=? AND r.question_id=?',
            [$memoryId,$scope['tenant_id'],$scope['workspace_id'],'P01_COMPETITOR',$scope['competitor_id'],$scope['question_id']],
        );
        if ($row===null) return Outcome::refuse('M17_REPLAY_CANDIDATE_NOT_FOUND');
        try {
            return Replay::check([
                'expected_rollup'=>json_decode($row['rollup_json'],true,64,JSON_THROW_ON_ERROR),
                'frozen_inputs'=>json_decode($row['frozen_inputs_json'],true,64,JSON_THROW_ON_ERROR),
                'reported_unavailable_ref_ids'=>[],
            ]);
        } catch (\Throwable) { return Outcome::failure('M17_REPLAY_STORED_CANDIDATE_INVALID'); }
    }

    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
