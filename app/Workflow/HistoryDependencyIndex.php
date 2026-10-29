<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * IV-01: typed, bounded metadata-only historical dependency graph.
 *
 * Node IDs, digests and edges are caller-supplied CANDIDATES. M02/M05/M10
 * native owners must revalidate references and current rights at real use;
 * this graph is never an alternate evidence, permission or retention owner.
 */
final class HistoryDependencyIndex
{
    public const VERSION='iv01.history-dependency-index.v1';
    public const MAX_NODES=256;
    public const MAX_EDGES=512;
    public const MAX_FANOUT=64;
    private const KINDS=[
        'CAPTURE'=>0,'SECTION_DERIVATIVE'=>1,'ASSESSMENT'=>2,
        'HISTORICAL_BASIS'=>3,'TEMPORAL_MEMORY'=>4,
        'ANALYSIS'=>5,'FINDING'=>6,'ATTENTION'=>7,
    ];
    private const FIELDS=['scope','catalog_cutoff_utc','nodes','links'];
    private const NODE_FIELDS=['node_id','kind','owner_ref_digest'];
    private const LINK_FIELDS=['parent_id','child_id','relation'];

    /** @param array<string,mixed> $input */
    public static function freeze(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input,self::FIELDS)
            || !Vocabulary::scope($input['scope'])
            || !Vocabulary::utc($input['catalog_cutoff_utc'])
            || !is_array($input['nodes'])||!array_is_list($input['nodes'])
            || count($input['nodes'])<1||count($input['nodes'])>self::MAX_NODES
            || !is_array($input['links'])||!array_is_list($input['links'])
            || count($input['links'])>self::MAX_EDGES) {
            return Outcome::refuse('IV01_GRAPH_CONTRACT_INVALID');
        }
        $nodes=[];$ids=[];$ranks=[];
        foreach($input['nodes'] as $node){
            if (!is_array($node)||!Vocabulary::exactKeys($node,self::NODE_FIELDS)
                || !Vocabulary::id($node['node_id'])
                || !is_string($node['kind'])
                || !array_key_exists($node['kind'],self::KINDS)
                || !is_string($node['owner_ref_digest'])
                || preg_match('/^[a-f0-9]{64}$/D',$node['owner_ref_digest'])!==1
                || isset($ids[$node['node_id']])) {
                return Outcome::refuse('IV01_GRAPH_NODE_INVALID');
            }
            $ids[$node['node_id']]=true;
            $ranks[$node['node_id']]=self::KINDS[$node['kind']];
            $nodes[]=$node;
        }
        usort($nodes,static fn(array $a,array $b):int=>
            strcmp($a['node_id'],$b['node_id']));
        $links=[];$linkIds=[];$adj=[];$indegree=array_fill_keys(array_keys($ids),0);
        $maxFanout=0;
        foreach($input['links'] as $link){
            if(!is_array($link)||!Vocabulary::exactKeys($link,self::LINK_FIELDS)
                || !is_string($link['parent_id'])
                || !is_string($link['child_id'])
                || !isset($ids[$link['parent_id']])
                || !isset($ids[$link['child_id']])
                || $link['parent_id']===$link['child_id']
                || !in_array($link['relation'],['DERIVED_FROM','SUCCESSOR_OF'],true)) {
                return Outcome::refuse('IV01_GRAPH_LINK_INVALID');
            }
            $a=$ranks[$link['parent_id']];$b=$ranks[$link['child_id']];
            if(($link['relation']==='DERIVED_FROM' && $b<=$a)
                || ($link['relation']==='SUCCESSOR_OF' && $b!==$a)) {
                return Outcome::refuse('IV01_GRAPH_ILLEGAL_OWNER_TRANSITION');
            }
            $key=$link['parent_id']."\0".$link['child_id']."\0".$link['relation'];
            if(isset($linkIds[$key]))return Outcome::refuse('IV01_GRAPH_DUPLICATE_LINK');
            $linkIds[$key]=true;
            $links[]=$link;
            $adj[$link['parent_id']][]=$link['child_id'];
            $indegree[$link['child_id']]++;
        }
        usort($links,static fn(array $a,array $b):int=>
            [$a['parent_id'],$a['child_id'],$a['relation']]
            <=>
            [$b['parent_id'],$b['child_id'],$b['relation']]);
        foreach($adj as $parent=>&$children){
            sort($children,SORT_STRING);
            $maxFanout=max($maxFanout,count($children));
        }
        unset($children);
        // Kahn topological check, bounded and iterative; even same-kind
        // successor cycles become explicit HOLD, never recursion.
        $queue=[];
        foreach($indegree as $id=>$degree)if($degree===0)$queue[]=$id;
        sort($queue,SORT_STRING);
        $seen=0;
        while($queue!==[]){
            $id=array_shift($queue);$seen++;
            foreach($adj[$id]??[] as $child){
                $indegree[$child]--;
                if($indegree[$child]===0){
                    $queue[]=$child;sort($queue,SORT_STRING);
                }
            }
        }
        $cycle=$seen!==count($nodes);
        $status=$cycle?'HOLD_CYCLE'
            : ($maxFanout>self::MAX_FANOUT?'HOLD_EXCESS_FANOUT'
                :'CANDIDATE_NOT_OWNER_VERIFIED');
        $graph=[
            'version'=>self::VERSION,'scope'=>$input['scope'],
            'catalog_cutoff_utc'=>$input['catalog_cutoff_utc'],
            'nodes'=>$nodes,'links'=>$links,
            'node_count'=>count($nodes),'link_count'=>count($links),
            'max_direct_fanout'=>$maxFanout,
            'cycle_detected'=>$cycle,'status'=>$status,
            'owner_reference_status'=>'NOT_AUTHENTICATED',
            'graph_completeness'=>'NOT_OWNER_VERIFIED',
            'history_source_use_admitted'=>false,
            'retention_execution_authorized'=>false,
            'model_or_customer_release_authorized'=>false,
            'runtime_persistence_authorized'=>false,
        ];
        $graph['graph_digest']=Digest::canonicalDigest($graph);
        return Outcome::ok($graph);
    }

    /** Candidate structural replay, never original owner attestation. */
    public static function verify(array $graph): Outcome
    {
        if(!is_string($graph['graph_digest']??null)
            || preg_match('/^[a-f0-9]{64}$/D',$graph['graph_digest'])!==1
            || ($graph['version']??null)!==self::VERSION
            || ($graph['owner_reference_status']??null)!=='NOT_AUTHENTICATED'
            || ($graph['graph_completeness']??null)!=='NOT_OWNER_VERIFIED'
            || ($graph['history_source_use_admitted']??null)!==false
            || ($graph['retention_execution_authorized']??null)!==false
            || ($graph['model_or_customer_release_authorized']??null)!==false
            || ($graph['runtime_persistence_authorized']??null)!==false) {
            return Outcome::refuse('IV01_GRAPH_DIGEST_OR_AUTHORITY_INVALID');
        }
        $input=[];
        foreach(self::FIELDS as $field){
            if(!array_key_exists($field,$graph)){
                return Outcome::refuse('IV01_GRAPH_REPLAY_INVALID');
            }
            $input[$field]=$graph[$field];
        }
        $again=self::freeze($input);
        if(!$again->isOk()
            || Digest::canonicalDigest($again->value())!==Digest::canonicalDigest($graph)) {
            return Outcome::refuse('IV01_GRAPH_REPLAY_INVALID');
        }
        return Outcome::ok(['integrity'=>'CANDIDATE_DIGEST_ONLY','owner_authenticated'=>false]);
    }
}
