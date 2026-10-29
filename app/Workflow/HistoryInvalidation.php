<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * IV-01: bounded, resumable invalidation PREVIEW on a candidate dependency DAG.
 * Current use must HOLD synchronously while an invalidation is pending.
 * Actual native M05/M10 rights, deletion/erasure, backup/retention decisions
 * and M10 correction records remain owned outside this component.
 */
final class HistoryInvalidation
{
    public const VERSION='iv01.history-invalidation-candidate.v1';
    public const MAX_BATCH=32;
    private const CAUSE_FIELDS=[
        'cause_kind','root_node_id','owner_decision_ref_safe',
        'owner_evidence_digest','recorded_at_utc','owner_cause_authenticated',
    ];
    private const CAUSES=[
        'SOURCE_RIGHTS_REVOKED','CAPTURE_BYTES_MISSING',
        'CONTRADICTORY_EVIDENCE','ASSESSMENT_SUPERSEDED',
        'RETENTION_EXPIRED','LAWFUL_ERASURE',
    ];

    /** @param array<string,mixed> $input */
    public static function freezeCause(array $input): Outcome
    {
        if(!Vocabulary::exactKeys($input,self::CAUSE_FIELDS)
            || !in_array($input['cause_kind'],self::CAUSES,true)
            || !Vocabulary::id($input['root_node_id'])
            || !Vocabulary::id($input['owner_decision_ref_safe'])
            || !Vocabulary::utc($input['recorded_at_utc'])
            || !is_string($input['owner_evidence_digest'])
            || preg_match('/^[a-f0-9]{64}$/D',$input['owner_evidence_digest'])!==1
            || $input['owner_cause_authenticated']!==false) {
            return Outcome::refuse('IV01_CAUSE_CONTRACT_INVALID');
        }
        $result=$input;
        $result['version']=self::VERSION;
        $result['provisional_cause_only']=true;
        $result['rights_currently_verified']=false;
        $result['retention_or_purge_authorized']=false;
        $result['cause_digest']=Digest::canonicalDigest($result);
        return Outcome::ok($result);
    }

    /** @param array<string,mixed> $cause */
    public static function verifyCause(array $cause): Outcome
    {
        if(!is_string($cause['cause_digest']??null)
            || preg_match('/^[a-f0-9]{64}$/D',$cause['cause_digest'])!==1
            || ($cause['version']??null)!==self::VERSION
            || ($cause['provisional_cause_only']??null)!==true
            || ($cause['rights_currently_verified']??null)!==false
            || ($cause['retention_or_purge_authorized']??null)!==false
            || ($cause['owner_cause_authenticated']??null)!==false) {
            return Outcome::refuse('IV01_CAUSE_NOT_AUTHENTICATED');
        }
        $input=[];
        foreach(self::CAUSE_FIELDS as $field){
            if(!array_key_exists($field,$cause))
                return Outcome::refuse('IV01_CAUSE_NOT_AUTHENTICATED');
            $input[$field]=$cause[$field];
        }
        $replayed=self::freezeCause($input);
        if(!$replayed->isOk()
            || Digest::canonicalDigest($replayed->value())!==Digest::canonicalDigest($cause)){
            return Outcome::refuse('IV01_CAUSE_REPLAY_INVALID');
        }
        return Outcome::ok(['integrity'=>'CANDIDATE_ONLY','owner_authenticated'=>false]);
    }

    /**
     * All propagation is iterative and bounded. The prior receipt must
     * identify exactly the processed prefix; a caller cannot skip a chunk.
     *
     * @param array<string,mixed> $input
     */
    public static function plan(array $input): Outcome
    {
        if(!Vocabulary::exactKeys($input,[
            'graph','cause','evaluated_at_utc','batch_size','prior_receipt',
        ]) || !is_array($input['graph'])
            || !HistoryDependencyIndex::verify($input['graph'])->isOk()
            || !is_array($input['cause'])
            || !self::verifyCause($input['cause'])->isOk()
            || !Vocabulary::utc($input['evaluated_at_utc'])
            || !is_int($input['batch_size'])
            || $input['batch_size']<1 || $input['batch_size']>self::MAX_BATCH) {
            return Outcome::refuse('IV01_PLAN_CONTRACT_INVALID');
        }
        $graph=$input['graph'];$cause=$input['cause'];
        if($cause['recorded_at_utc']>$input['evaluated_at_utc'])
            return Outcome::refuse('IV01_CAUSE_FROM_FUTURE');
        $nodes=array_column($graph['nodes'],'kind','node_id');
        if(!array_key_exists($cause['root_node_id'],$nodes))
            return Outcome::refuse('IV01_CAUSE_ROOT_NOT_IN_GRAPH');
        $eventDigest=Digest::canonicalDigest([
            'scope'=>$graph['scope'],'graph_digest'=>$graph['graph_digest'],
            'cause_digest'=>$cause['cause_digest'],
        ]);
        $blocked=$graph['status']!=='CANDIDATE_NOT_OWNER_VERIFIED';
        $ordered=$blocked?[]:self::reachable($graph,$cause['root_node_id']);
        $prior=$input['prior_receipt'];
        $offset=0;
        if($prior!==null){
            if(!is_array($prior)
                || !self::verifyReceipt($prior)->isOk()
                || ($prior['event_digest']??null)!==$eventDigest
                || ($prior['graph_digest']??null)!==$graph['graph_digest']
                || ($prior['cause_digest']??null)!==$cause['cause_digest']
                || ($prior['status']??null)!=='PROPAGATION_INCOMPLETE_HOLD'
                || !is_int($prior['next_cursor']??null)
                || $prior['next_cursor']<1
                || $prior['next_cursor']>count($ordered)
                || ($prior['processed_prefix_digest']??null)!==Digest::canonicalDigest(
                    array_slice($ordered,0,$prior['next_cursor']))) {
                return Outcome::refuse('IV01_RESUME_RECEIPT_INVALID');
            }
            $offset=$prior['next_cursor'];
        }
        $batch=$blocked?[]:array_slice($ordered,$offset,$input['batch_size']);
        $next=$blocked?null:$offset+count($batch);
        $complete=!$blocked && $next===count($ordered);
        $status=$blocked
            ? ($graph['cycle_detected']?'HOLD_CYCLE':'HOLD_EXCESS_FANOUT')
            : ($complete?'COMPLETE_CANDIDATE_HOLD':'PROPAGATION_INCOMPLETE_HOLD');
        $receipt=[
            'version'=>self::VERSION,'status'=>$status,
            'scope'=>$graph['scope'],
            'event_digest'=>$eventDigest,
            'graph_digest'=>$graph['graph_digest'],
            'cause_digest'=>$cause['cause_digest'],
            'cause_kind'=>$cause['cause_kind'],
            'evaluated_at_utc'=>$input['evaluated_at_utc'],
            'cursor_start'=>$offset,
            'batch_size'=>$input['batch_size'],
            'affected_total'=>$blocked?null:count($ordered),
            'affected_in_batch'=>$batch,
            'next_cursor'=>$blocked?null:($complete?null:$next),
            'processed_prefix_digest'=>Digest::canonicalDigest(
                array_slice($ordered,0,$blocked?0:$next)),
            'remaining_count'=>$blocked?null:count($ordered)-$next,
            'unscanned_dependencies_use_hold'=>true,
            'synchronous_reliance_hold'=>true,
            'owner_cause_authenticated'=>false,
            'owner_reference_verified'=>false,
            'source_use_admitted'=>false,
            'm10_current_reliance_authorized'=>false,
            'purge_or_key_destruction_executed'=>false,
            'retention_extension_authorized'=>false,
            'runtime_persistence_authorized'=>false,
            'model_or_customer_output_authorized'=>false,
        ];
        $receipt['receipt_digest']=Digest::canonicalDigest($receipt);
        return Outcome::ok($receipt);
    }

    /** Structural integrity of a receipt, not native source/rights admission. */
    public static function verifyReceipt(array $receipt): Outcome
    {
        if(($receipt['version']??null)!==self::VERSION
            || !is_string($receipt['receipt_digest']??null)
            || preg_match('/^[a-f0-9]{64}$/D',$receipt['receipt_digest'])!==1
            || !in_array($receipt['status']??null,[
                'HOLD_CYCLE','HOLD_EXCESS_FANOUT',
                'PROPAGATION_INCOMPLETE_HOLD','COMPLETE_CANDIDATE_HOLD',
            ],true)) {
            return Outcome::refuse('IV01_RECEIPT_INVALID');
        }
        foreach([
            'owner_cause_authenticated','owner_reference_verified','source_use_admitted',
            'm10_current_reliance_authorized','purge_or_key_destruction_executed',
            'retention_extension_authorized','runtime_persistence_authorized',
            'model_or_customer_output_authorized',
        ] as $field){
            if(($receipt[$field]??null)!==false)
                return Outcome::refuse('IV01_RECEIPT_AUTHORITY_FORGERY');
        }
        try{
            $digest=$receipt['receipt_digest'];
            unset($receipt['receipt_digest']);
            if(!hash_equals($digest,Digest::canonicalDigest($receipt)))
                return Outcome::refuse('IV01_RECEIPT_DIGEST_MISMATCH');
        }catch(\Throwable){
            return Outcome::refuse('IV01_RECEIPT_INVALID');
        }
        return Outcome::ok(['integrity'=>'SOURCE_ONLY','current_use_admitted'=>false]);
    }

    /**
     * Synchronous read/use gate, independent of whether the propagation
     * cursor has finished. Not a substitute for real M02/P01/M05/M10 rights.
     */
    public static function inspectReliance(array $graph,array $cause,string $nodeId): Outcome
    {
        if(!HistoryDependencyIndex::verify($graph)->isOk()
            || !self::verifyCause($cause)->isOk()
            || !Vocabulary::id($nodeId)) {
            return Outcome::refuse('IV01_RELIANCE_CONTRACT_INVALID');
        }
        if(!in_array($nodeId,array_column($graph['nodes'],'node_id'),true))
            return Outcome::refuse('IV01_RELIANCE_NODE_NOT_FOUND');
        $affected=$graph['status']==='CANDIDATE_NOT_OWNER_VERIFIED'
            ? self::reachable($graph,$cause['root_node_id']):[];
        return Outcome::ok([
            'decision'=>'HOLD',
            'reason'=>in_array($nodeId,$affected,true)
                ? 'AFFECTED_BY_INCOMPLETE_INVALIDATION'
                : 'UNVERIFIED_OR_UNSCANNED_DEPENDENCY',
            'node_id'=>$nodeId,'cause_digest'=>$cause['cause_digest'],
            'real_source_rights_rechecked'=>false,
            'source_use_authorized'=>false,
            'model_or_customer_output_authorized'=>false,
        ]);
    }

    /** @return list<string> */
    private static function reachable(array $graph,string $root): array
    {
        $adj=[];
        foreach($graph['links'] as $link)$adj[$link['parent_id']][]=$link['child_id'];
        foreach($adj as &$children)sort($children,SORT_STRING);
        unset($children);
        $seen=[];$queue=[$root];$out=[];
        while($queue!==[]){
            $id=array_shift($queue);
            if(isset($seen[$id]))continue;
            $seen[$id]=true;$out[]=$id;
            foreach($adj[$id]??[] as $child)if(!isset($seen[$child]))$queue[]=$child;
        }
        return $out;
    }
}
