<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * IV-01: CLI + disposable-MariaDB ONLY append-only graph/event/receipt store.
 * It never deletes evidence, grants permissions, reads raw source bytes or
 * calls a model. No production factory, native 350 migration or live hook.
 */
final class HistoryInvalidationStore
{
    public const VERSION='iv01.disposable-invalidation-store.v1';

    private function __construct(
        private readonly Database $db,private readonly Clock $clock
    ) {}

    public static function forDisposableTest(Database $db,Clock $clock):Outcome
    {
        if(PHP_SAPI!=='cli'
            || !defined('ALLFATHER_IV01_DISPOSABLE_SCHEMA_LOADED')
            || constant('ALLFATHER_IV01_DISPOSABLE_SCHEMA_LOADED')!==true){
            return Outcome::refuse('IV01_SCHEMA_OR_OWNER_AUTHORITY_NOT_ADOPTED');
        }
        return Outcome::ok(new self($db,$clock));
    }

    /** @param array<string,mixed> $graph */
    public function recordGraph(array $graph):Outcome
    {
        if(!HistoryDependencyIndex::verify($graph)->isOk())
            return Outcome::refuse('IV01_GRAPH_NOT_REPLAYABLE');
        $scope=$graph['scope'];$json=self::json($graph);
        try{
            return $this->db->transaction(function(Database $tx)use(
                $scope,$graph,$json
            ):Outcome{
                $old=$tx->one(
                    'SELECT history_dependency_index_id,graph_json
                     FROM history_dependency_index_candidates
                     WHERE tenant_id=? AND workspace_id=? AND competitor_id=?
                       AND question_id=? AND graph_digest=? FOR UPDATE',
                    [$scope['tenant_id'],$scope['workspace_id'],$scope['competitor_id'],
                     $scope['question_id'],$graph['graph_digest']],
                );
                if($old!==null){
                    if(!hash_equals((string)$old['graph_json'],$json))
                        return Outcome::refuse('IV01_GRAPH_DIGEST_CONFLICT');
                    return Outcome::ok([
                        'action'=>'EXACT_GRAPH_REPLAY_NO_WRITE',
                        'history_dependency_index_id'=>$old['history_dependency_index_id'],
                        'owner_authenticated'=>false,
                    ]);
                }
                $id=Ids::new();
                $tx->insert('history_dependency_index_candidates',[
                    'history_dependency_index_id'=>$id,...$scope,
                    'graph_digest'=>$graph['graph_digest'],
                    'graph_json'=>$json,
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_GRAPH_RECORDED',
                    'history_dependency_index_id'=>$id,
                    'graph_digest'=>$graph['graph_digest'],
                    'owner_authenticated'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'IV01_GRAPH_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('IV01_GRAPH_STORAGE_FAILED');
        }
    }

    /** @param array<string,string> $scope @param array<string,mixed> $cause */
    public function recordCause(array $scope,string $graphId,array $cause):Outcome
    {
        if(!Vocabulary::scope($scope)||!Ids::isValid($graphId)
            || !HistoryInvalidation::verifyCause($cause)->isOk()) {
            return Outcome::refuse('IV01_CAUSE_STORE_CONTRACT_INVALID');
        }
        try{
            return $this->db->transaction(function(Database $tx)use(
                $scope,$graphId,$cause
            ):Outcome{
                $graphRow=$tx->one(
                    'SELECT graph_json FROM history_dependency_index_candidates
                     WHERE history_dependency_index_id=? AND tenant_id=?
                       AND workspace_id=? AND competitor_id=? AND question_id=?
                       AND candidate_state=? FOR UPDATE',
                    [$graphId,$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],'TEST_ONLY_NOT_ADMITTED'],
                );
                if($graphRow===null)return Outcome::refuse('IV01_GRAPH_NOT_IN_CURRENT_SCOPE');
                $graph=self::decode($graphRow['graph_json']);
                if(!HistoryDependencyIndex::verify($graph)->isOk()
                    || !in_array($cause['root_node_id'],
                        array_column($graph['nodes'],'node_id'),true)) {
                    return Outcome::refuse('IV01_GRAPH_OR_CAUSE_NOT_REPLAYABLE');
                }
                $old=$tx->one(
                    'SELECT history_invalidation_event_id,cause_json
                     FROM history_invalidation_events
                     WHERE history_dependency_index_id=? AND cause_digest=?
                     FOR UPDATE',
                    [$graphId,$cause['cause_digest']],
                );
                $json=self::json($cause);
                if($old!==null){
                    if(!hash_equals((string)$old['cause_json'],$json))
                        return Outcome::refuse('IV01_CAUSE_DIGEST_CONFLICT');
                    return Outcome::ok([
                        'action'=>'EXACT_CAUSE_REPLAY_NO_WRITE',
                        'history_invalidation_event_id'=>$old['history_invalidation_event_id'],
                        'owner_cause_authenticated'=>false,
                    ]);
                }
                $id=Ids::new();
                $tx->insert('history_invalidation_events',[
                    'history_invalidation_event_id'=>$id,
                    'history_dependency_index_id'=>$graphId,
                    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                    'cause_kind'=>$cause['cause_kind'],
                    'cause_digest'=>$cause['cause_digest'],
                    'cause_json'=>$json,'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_CAUSE_RECORDED',
                    'history_invalidation_event_id'=>$id,
                    'cause_digest'=>$cause['cause_digest'],
                    'owner_cause_authenticated'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'IV01_CAUSE_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('IV01_CAUSE_STORAGE_FAILED');
        }
    }

    /**
     * Advance exactly one bounded candidate batch; a stale/wrong cursor
     * cannot skip a dependency or overwrite a prior receipt.
     * @param array<string,string> $scope
     */
    public function advance(
        array $scope,string $eventId,int $expectedCursor,
        string $atUtc,int $batchSize,
    ):Outcome {
        if(!Vocabulary::scope($scope)||!Ids::isValid($eventId)
            || $expectedCursor<0||$expectedCursor>HistoryDependencyIndex::MAX_NODES
            || !Vocabulary::utc($atUtc)
            || $batchSize<1||$batchSize>HistoryInvalidation::MAX_BATCH){
            return Outcome::refuse('IV01_ADVANCE_SELECTOR_INVALID');
        }
        try{
            return $this->db->transaction(function(Database $tx)use(
                $scope,$eventId,$expectedCursor,$atUtc,$batchSize
            ):Outcome{
                $record=$tx->one(
                    'SELECT e.cause_json,e.cause_digest,g.graph_json,g.graph_digest
                     FROM history_invalidation_events e
                     JOIN history_dependency_index_candidates g
                       ON g.history_dependency_index_id=e.history_dependency_index_id
                       AND g.tenant_id=e.tenant_id AND g.workspace_id=e.workspace_id
                     WHERE e.history_invalidation_event_id=?
                       AND e.tenant_id=? AND e.workspace_id=?
                       AND g.competitor_id=? AND g.question_id=?
                       AND e.candidate_state=? AND g.candidate_state=? FOR UPDATE',
                    [$eventId,$scope['tenant_id'],$scope['workspace_id'],
                     $scope['competitor_id'],$scope['question_id'],
                     'TEST_ONLY_NOT_ADMITTED','TEST_ONLY_NOT_ADMITTED'],
                );
                if($record===null)return Outcome::refuse('IV01_EVENT_NOT_IN_CURRENT_SCOPE');
                $graph=self::decode($record['graph_json']);
                $cause=self::decode($record['cause_json']);
                if(!HistoryDependencyIndex::verify($graph)->isOk()
                    || !HistoryInvalidation::verifyCause($cause)->isOk()
                    || $graph['graph_digest']!==$record['graph_digest']
                    || $cause['cause_digest']!==$record['cause_digest']){
                    return Outcome::refuse('IV01_STORED_OWNER_CANDIDATE_MISMATCH');
                }
                $existing=$tx->one(
                    'SELECT history_invalidation_receipt_id,receipt_json
                     FROM history_invalidation_receipts
                     WHERE history_invalidation_event_id=? AND cursor_start=?
                     FOR UPDATE',
                    [$eventId,$expectedCursor],
                );
                if($existing!==null){
                    $prior=self::decode($existing['receipt_json']);
                    if(!HistoryInvalidation::verifyReceipt($prior)->isOk()
                        || $prior['evaluated_at_utc']!==$atUtc
                        || $prior['batch_size']!==$batchSize){
                        return Outcome::refuse('IV01_CURSOR_REPLAY_CONFLICT');
                    }
                    return Outcome::ok([
                        'action'=>'EXACT_RECEIPT_REPLAY_NO_WRITE',
                        'history_invalidation_receipt_id'=>$existing['history_invalidation_receipt_id'],
                        'status'=>$prior['status'],
                        'next_cursor'=>$prior['next_cursor'],
                        'source_use_authorized'=>false,
                    ]);
                }
                $last=$tx->one(
                    'SELECT receipt_json
                     FROM history_invalidation_receipts
                     WHERE history_invalidation_event_id=?
                     ORDER BY cursor_start DESC LIMIT 1 FOR UPDATE',
                    [$eventId],
                );
                $priorReceipt=$last===null?null:self::decode($last['receipt_json']);
                if($priorReceipt===null && $expectedCursor!==0)
                    return Outcome::refuse('IV01_RESUME_PREFIX_REQUIRED');
                if($priorReceipt!==null
                    && $priorReceipt['next_cursor']!==$expectedCursor)
                    return Outcome::refuse('IV01_RESUME_PREFIX_MISMATCH');
                $result=HistoryInvalidation::plan([
                    'graph'=>$graph,'cause'=>$cause,'evaluated_at_utc'=>$atUtc,
                    'batch_size'=>$batchSize,'prior_receipt'=>$priorReceipt,
                ]);
                if(!$result->isOk())return $result;
                $receipt=$result->value();
                if($receipt['cursor_start']!==$expectedCursor)
                    return Outcome::refuse('IV01_CURSOR_SOURCE_MISMATCH');
                $id=Ids::new();
                $tx->insert('history_invalidation_receipts',[
                    'history_invalidation_receipt_id'=>$id,
                    'history_invalidation_event_id'=>$eventId,
                    'tenant_id'=>$scope['tenant_id'],'workspace_id'=>$scope['workspace_id'],
                    'cursor_start'=>$expectedCursor,
                    'next_cursor'=>$receipt['next_cursor'],
                    'disposition'=>$receipt['status'],
                    'receipt_digest'=>$receipt['receipt_digest'],
                    'receipt_json'=>self::json($receipt),
                    'candidate_state'=>'TEST_ONLY_NOT_ADMITTED',
                    'created_at'=>$this->clock->now(),
                ]);
                return Outcome::ok([
                    'action'=>'DISPOSABLE_RECEIPT_RECORDED',
                    'history_invalidation_receipt_id'=>$id,
                    'status'=>$receipt['status'],
                    'next_cursor'=>$receipt['next_cursor'],
                    'affected_in_batch'=>$receipt['affected_in_batch'],
                    'receipt_digest'=>$receipt['receipt_digest'],
                    'source_use_authorized'=>false,
                    'purge_executed'=>false,
                    'model_or_customer_output_authorized'=>false,
                ]);
            });
        }catch(\PDOException $e){
            return Database::classify($e,'IV01_ADVANCE_RACE_REFUSED');
        }catch(\Throwable){
            return Outcome::failure('IV01_ADVANCE_DEPENDENCY_FAILED');
        }
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    private static function decode(string $json):array
    {
        $value=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new \UnexpectedValueException('Invalid candidate JSON');
        return $value;
    }
}
