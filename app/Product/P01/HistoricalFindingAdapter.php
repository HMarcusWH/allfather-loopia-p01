<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\PromptOps\HistoryReviewCandidateChecks;
use Allfather\Loopia\Substrate\M17\Vocabulary;
use Allfather\Loopia\Workflow\{
    HistoryDependencyIndex,HistoryReviewService,PointInTimeHistoricalBasis
};

/**
 * AN-03: a bounded, cited, MULTI-REFERENCE historical finding proposal.
 *
 * Unlike the single-source-diff CompanySignals path, this refuses to
 * manufacture a single arbitrarily selected diff as the "source" of a
 * temporal conclusion. It is NEVER a native M04/M07/M08/M10/M16 grant.
 *
 * Both the primary source refs and the exact analyst+critic journal are
 * synthetic metadata, NOT authenticated M05 source bytes or human reviews.
 */
final class HistoricalFindingAdapter
{
    public const VERSION='an03.multi-evidence-provisional-finding.v1';
    public const MAX_PRIMARY_REFS=20;
    private const HEX='/^[0-9a-f]{64}$/D';

    /**
     * @param array<string,mixed> $input
     * Exact inputs: HS-01 basis, AN-02 run and its THREE journal events,
     * immutable analyst and critic candidate artifacts. No provider response
     * body, customer text, source URL, approval flag or loose evidence IDs.
     */
    public static function propose(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,[
            'basis','review_run','review_events','analyst','critic',
        ]) || !is_array($input['basis'])
            || !PointInTimeHistoricalBasis::verify($input['basis'])->isOk()
            || !is_array($input['review_run'])
            || !HistoryReviewService::verifyRun($input['review_run'])->isOk()
            || !is_array($input['analyst'])
            || !HistoryReviewCandidateChecks::verifyAnalyst($input['analyst'])->isOk()
            || !is_array($input['critic'])
            || !HistoryReviewCandidateChecks::verifyCritic($input['critic'])->isOk()
            || !is_array($input['review_events'])
            || !array_is_list($input['review_events'])
            || count($input['review_events'])!==3) {
            return Outcome::refuse('AN03_REVIEW_BASIS_OR_ARTIFACT_UNVERIFIED');
        }
        $basis=$input['basis'];$run=$input['review_run'];
        $a=$input['analyst'];$c=$input['critic'];$events=$input['review_events'];
        if($run['state']!=='REQUEST_INSPECTION_REQUIRED'
            || $basis['status']!=='OFFLINE_NOT_ADMITTED'
            || $basis['selection_integrity']!=='COMPLETE_WITHIN_INPUT'
            || $basis['selected_count']<2
            || $basis['selected_count']>self::MAX_PRIMARY_REFS
            || $run['scope']!==$basis['scope']
            || $run['basis_digest']!==$basis['basis_digest']
            || $run['selected_count']!==$basis['selected_count']
            || $run['contrary_count']!==$basis['selected_contrary_count']
            || $run['request_candidate_digest']!==$a['request_candidate_digest']
            || $run['basis_digest']!==$a['basis_digest']
            || $run['m17_run_digest']!==$a['m17_run_digest']
            || $run['scope']!==$a['scope']
            || $run['review_id']!==$a['review_id']
            || $c['scope']!==$run['scope']
            || $c['review_id']!==$run['review_id']
            || $c['basis_digest']!==$basis['basis_digest']
            || $c['request_candidate_digest']!==$run['request_candidate_digest']
            || $c['analyst_candidate_digest']!==$a['candidate_digest']
            || $c['output']['analyst_digest']!==$a['candidate_digest']
            || $a['output']['result_kind']!=='HYPOTHESIS_FOR_HUMAN'
            || $a['output']['statement_code']!=='DESCRIPTIVE_SHIFT_HYPOTHESIS'
            || $c['output']['disposition']!=='PASS_TO_HUMAN'
            || $basis['knowledge_cutoff_utc']!==$run['knowledge_cutoff_utc']
            || $basis['historical_reuse_admitted']!==false) {
            return Outcome::refuse('AN03_MULTI_EVIDENCE_NOT_AN_ADMITTED_REVIEW');
        }
        $wanted=[
            ['SYNTHETIC_REQUEST_INSPECTED_NO_GRANT',
                'REQUEST_INSPECTION_REQUIRED','ANALYST_READY',null,null],
            ['ANALYST_CANDIDATE_RETAINED','ANALYST_READY','CRITIC_REQUIRED',
                'ANALYST',$a['candidate_digest']],
            ['CRITIC_CHALLENGE_RETAINED','CRITIC_REQUIRED','HUMAN_REVIEW_REQUIRED',
                'CRITIC',$c['candidate_digest']],
        ];
        $prior=null;$lastAt=$run['requested_at_utc'];
        foreach($events as $index=>$event){
            if(!is_array($event)
                || !HistoryReviewService::verifyEvent($event)->isOk()
                || $event['sequence_no']!==$index+1
                || $event['run_digest']!==$run['run_digest']
                || $event['request_candidate_digest']!==$run['request_candidate_digest']
                || $event['prior_event_digest']!==$prior
                || $event['event_kind']!==$wanted[$index][0]
                || $event['state_from']!==$wanted[$index][1]
                || $event['state_to']!==$wanted[$index][2]
                || $event['artifact_kind']!==$wanted[$index][3]
                || $event['artifact_digest']!==$wanted[$index][4]
                || $event['at_utc']<$lastAt) {
                return Outcome::refuse('AN03_REVIEW_CHAIN_NOT_A_HUMAN_HOLD');
            }
            $lastAt=$event['at_utc'];$prior=$event['event_digest'];
        }
        $all=$basis['selected_references'];
        $observed=array_column($all,'ref_id');
        $support=$a['output']['support_ref_ids'];
        $contrary=$a['output']['contrary_ref_ids'];
        $criticContrary=$c['output']['unresolved_contrary_ref_ids'];
        $actualContrary=[];$actualSupport=[];
        foreach($all as $ref){
            if($ref['semantic_polarity']==='CONTRARY')$actualContrary[]=$ref['ref_id'];
            if($ref['semantic_polarity']==='SUPPORT')$actualSupport[]=$ref['ref_id'];
        }
        $sorted=static function(array $x):array{sort($x,SORT_STRING);return $x;};
        if($observed!==$a['output']['observed_ref_ids']
            || $support===[]
            || $sorted(array_unique($support))!==$sorted($support)
            || array_diff($support,$actualSupport)!==[]
            || $sorted($contrary)!==$sorted($actualContrary)
            || $sorted($criticContrary)!==$sorted($actualContrary)
            // Excluded later-known contrary inputs are not knowable at this
            // point-in-time cut and MUST remain excluded. A selection-cap
            // loss of eligible contrary sources is instead handled by
            // HS-01 selection_integrity=INSUFFICIENT_BASIS above.
            || $a['primary_evidence_text_checked']!==false
            || $c['independent_empirical_verification']!==false) {
            return Outcome::refuse('AN03_SUPPORT_OR_CONTRARY_BUNDLE_INVALID');
        }
        // Complete selected references are retained, even when the analyst
        // assigned support to only one. Never infer corroborating publishers.
        $refs=[];$refNodes=[];$links=[];
        foreach($all as $ref){
            $refs[]=[
                'ref_id'=>$ref['ref_id'],
                'source_ref_id'=>$ref['source_ref_id'],
                'evidence_id'=>$ref['evidence_id'],
                'snapshot_id'=>$ref['snapshot_id'],
                'payload_sha256'=>$ref['payload_sha256'],
                'observed_at_utc'=>$ref['observed_at'],
                'known_at_utc'=>$ref['known_at'],
                'polarity'=>$ref['semantic_polarity'],
                'cited_as_support'=>in_array($ref['ref_id'],$support,true),
                'contrary_retained'=>$ref['semantic_polarity']==='CONTRARY',
                'm05_original_bytes_read'=>false,
                'independence_verified'=>false,
            ];
            $nodeId='capture:'.$ref['ref_id'];
            $refNodes[]=[
                'node_id'=>$nodeId,'kind'=>'CAPTURE',
                'owner_ref_digest'=>Digest::canonicalDigest([
                    'ref_id'=>$ref['ref_id'],
                    'evidence_id'=>$ref['evidence_id'],
                    'snapshot_id'=>$ref['snapshot_id'],
                    'payload_sha256'=>$ref['payload_sha256'],
                ]),
            ];
            $links[]=[
                'parent_id'=>$nodeId,'child_id'=>'basis:'.$run['review_id'],
                'relation'=>'DERIVED_FROM',
            ];
        }
        $refNodes[]=[
            'node_id'=>'basis:'.$run['review_id'],
            'kind'=>'HISTORICAL_BASIS',
            'owner_ref_digest'=>$basis['basis_digest'],
        ];
        $refNodes[]=[
            'node_id'=>'analysis:'.$run['review_id'],
            'kind'=>'ANALYSIS','owner_ref_digest'=>$a['candidate_digest'],
        ];
        $refNodes[]=[
            'node_id'=>'finding:'.$run['review_id'],
            'kind'=>'FINDING','owner_ref_digest'=>$c['candidate_digest'],
        ];
        $links[]=[
            'parent_id'=>'basis:'.$run['review_id'],
            'child_id'=>'analysis:'.$run['review_id'],'relation'=>'DERIVED_FROM',
        ];
        $links[]=[
            'parent_id'=>'analysis:'.$run['review_id'],
            'child_id'=>'finding:'.$run['review_id'],'relation'=>'DERIVED_FROM',
        ];
        $graph=HistoryDependencyIndex::freeze([
            'scope'=>$run['scope'],
            'catalog_cutoff_utc'=>$events[2]['at_utc'],
            'nodes'=>$refNodes,'links'=>$links,
        ]);
        if(!$graph->isOk() || $graph->value()['status']!=='CANDIDATE_NOT_OWNER_VERIFIED'){
            return Outcome::refuse('AN03_DEPENDENCY_GRAPH_NOT_REPLAYABLE');
        }
        $finding=[
            'version'=>self::VERSION,
            'status'=>'PROVISIONAL_MULTI_REFERENCE_HUMAN_REVIEW_HOLD',
            'scope'=>$run['scope'],
            'review_id'=>$run['review_id'],
            'review_run_digest'=>$run['run_digest'],
            'request_candidate_digest'=>$run['request_candidate_digest'],
            'basis_digest'=>$basis['basis_digest'],
            'm17_derived_context_digest'=>$run['m17_run_digest'],
            'known_at_cutoff_utc'=>$basis['knowledge_cutoff_utc'],
            'review_chain_event_digests'=>array_column($events,'event_digest'),
            'analyst_candidate_digest'=>$a['candidate_digest'],
            'critic_candidate_digest'=>$c['candidate_digest'],
            'statement_code'=>'DESCRIPTIVE_SHIFT_HYPOTHESIS',
            'claim_support_ref_ids'=>$support,
            'contrary_ref_ids'=>$actualContrary,
            'primary_references'=>$refs,
            'selected_primary_ref_count'=>count($refs),
            'support_ref_count'=>count($support),
            'contrary_ref_count'=>count($actualContrary),
            'separate_real_world_event_count'=>null,
            'independent_publisher_count'=>null,
            'source_content_quoted'=>false,
            'claimed_business_materiality'=>'NOT_ASSESSED',
            'independent_fact_or_causality_verified'=>false,
            'historical_source_use_admitted'=>false,
            'human_factual_release_approved'=>false,
            'human_delivery_approved'=>false,
            'm07_relevance_review_admitted'=>false,
            'm08_policy_admission_granted'=>false,
            'm10_current_reliance_verified'=>false,
            'm10_release_authorized'=>false,
            'm16_internal_artifact_created'=>false,
            'p02_twin_update_authorized'=>false,
            'p04_distribution_authorized'=>false,
            'customer_visibility_authorized'=>false,
            'dependency_graph'=>$graph->value(),
        ];
        $finding['finding_digest']=Digest::canonicalDigest($finding);
        return Outcome::ok($finding);
    }

    /** Self-hashed exact candidate integrity, not native M05/M10 authorization. */
    public static function verify(array $finding):Outcome
    {
        if(($finding['version']??null)!==self::VERSION
            || ($finding['status']??null)!=='PROVISIONAL_MULTI_REFERENCE_HUMAN_REVIEW_HOLD'
            || !Vocabulary::scope($finding['scope']??null)
            || !Vocabulary::id($finding['review_id']??null)
            || !Vocabulary::utc($finding['known_at_cutoff_utc']??null)
            || ($finding['statement_code']??null)!=='DESCRIPTIVE_SHIFT_HYPOTHESIS'
            || ($finding['claimed_business_materiality']??null)!=='NOT_ASSESSED'
            || !is_array($finding['dependency_graph']??null)
            || !HistoryDependencyIndex::verify($finding['dependency_graph'])->isOk()
            || $finding['dependency_graph']['scope']!==$finding['scope']
            || $finding['dependency_graph']['status']!=='CANDIDATE_NOT_OWNER_VERIFIED'
            || !is_array($finding['primary_references']??null)
            || !array_is_list($finding['primary_references'])
            || count($finding['primary_references'])<2
            || count($finding['primary_references'])>self::MAX_PRIMARY_REFS
            || !is_array($finding['claim_support_ref_ids']??null)
            || !array_is_list($finding['claim_support_ref_ids'])
            || !is_array($finding['contrary_ref_ids']??null)
            || !array_is_list($finding['contrary_ref_ids'])
            || !is_array($finding['review_chain_event_digests']??null)
            || count($finding['review_chain_event_digests'])!==3
            || ($finding['selected_primary_ref_count']??null)!==count($finding['primary_references'])
            || ($finding['support_ref_count']??null)!==count($finding['claim_support_ref_ids'])
            || ($finding['contrary_ref_count']??null)!==count($finding['contrary_ref_ids'])
            || !array_key_exists('separate_real_world_event_count',$finding)
            || $finding['separate_real_world_event_count']!==null
            || !array_key_exists('independent_publisher_count',$finding)
            || $finding['independent_publisher_count']!==null
            || !is_string($finding['finding_digest']??null)
            || preg_match(self::HEX,$finding['finding_digest'])!==1) {
            return Outcome::refuse('AN03_FINDING_CANDIDATE_CONTRACT_INVALID');
        }
        foreach([
            'source_content_quoted','independent_fact_or_causality_verified',
            'historical_source_use_admitted','human_factual_release_approved',
            'human_delivery_approved','m07_relevance_review_admitted',
            'm08_policy_admission_granted','m10_current_reliance_verified',
            'm10_release_authorized','m16_internal_artifact_created',
            'p02_twin_update_authorized','p04_distribution_authorized',
            'customer_visibility_authorized',
        ] as $flag){
            if(($finding[$flag]??null)!==false){
                return Outcome::refuse('AN03_FINDING_FORGED_AUTHORITY');
            }
        }
        foreach([
            'review_run_digest','request_candidate_digest','basis_digest',
            'm17_derived_context_digest','analyst_candidate_digest','critic_candidate_digest',
        ] as $hashField){
            if(!is_string($finding[$hashField]??null)
                || preg_match(self::HEX,$finding[$hashField])!==1){
                return Outcome::refuse('AN03_FINDING_PARENT_DIGEST_INVALID');
            }
        }
        foreach($finding['review_chain_event_digests'] as $eventDigest){
            if(!is_string($eventDigest)
                || preg_match(self::HEX,$eventDigest)!==1){
                return Outcome::refuse('AN03_FINDING_EVENT_DIGEST_INVALID');
            }
        }
        $ids=[];$contrary=[];$support=[];$nodes=$finding['dependency_graph']['nodes'];
        foreach($finding['primary_references'] as $ref){
            if(!is_array($ref)
                || !Vocabulary::exactKeys($ref,[
                    'ref_id','source_ref_id','evidence_id','snapshot_id','payload_sha256',
                    'observed_at_utc','known_at_utc','polarity','cited_as_support',
                    'contrary_retained','m05_original_bytes_read','independence_verified',
                ]) || !Vocabulary::id($ref['ref_id'])
                || isset($ids[$ref['ref_id']])
                || !Vocabulary::id($ref['source_ref_id'])
                || !Vocabulary::id($ref['evidence_id'])
                || !Vocabulary::id($ref['snapshot_id'])
                || !is_string($ref['payload_sha256'])
                || preg_match(self::HEX,$ref['payload_sha256'])!==1
                || !Vocabulary::utc($ref['observed_at_utc'])
                || !Vocabulary::utc($ref['known_at_utc'])
                || $ref['known_at_utc']>$finding['known_at_cutoff_utc']
                || !in_array($ref['polarity'],['SUPPORT','CONTRARY','UNKNOWN'],true)
                || !is_bool($ref['cited_as_support'])
                || $ref['contrary_retained']!==($ref['polarity']==='CONTRARY')
                || $ref['m05_original_bytes_read']!==false
                || $ref['independence_verified']!==false
                || ($ref['cited_as_support']&&$ref['polarity']!=='SUPPORT')){
                return Outcome::refuse('AN03_FINDING_PRIMARY_REF_INVALID');
            }
            $ids[$ref['ref_id']]=true;
            if($ref['polarity']==='CONTRARY')$contrary[]=$ref['ref_id'];
            if($ref['cited_as_support'])$support[]=$ref['ref_id'];
        }
        $sort=static function(array $a):array{sort($a,SORT_STRING);return $a;};
        if($support===[]
            || $sort($support)!==$sort($finding['claim_support_ref_ids'])
            || $sort($contrary)!==$sort($finding['contrary_ref_ids'])
            || count($finding['dependency_graph']['nodes'])!==count($ids)+3
            || count($finding['dependency_graph']['links'])!==count($ids)+2){
            return Outcome::refuse('AN03_FINDING_LINEAGE_INCOMPLETE');
        }
        // A self-hash may be recomputed by any caller. The dependency graph
        // must therefore be regenerated from these exact primary records,
        // rather than accepted because its internal self-hash happens to pass.
        $graphNodes=[];$graphLinks=[];
        foreach($finding['primary_references'] as $r){
            $id='capture:'.$r['ref_id'];
            $graphNodes[]=[
                'node_id'=>$id,
                'kind'=>'CAPTURE',
                'owner_ref_digest'=>Digest::canonicalDigest([
                    'ref_id'=>$r['ref_id'],'evidence_id'=>$r['evidence_id'],
                    'snapshot_id'=>$r['snapshot_id'],'payload_sha256'=>$r['payload_sha256'],
                ]),
            ];
            $graphLinks[]=['parent_id'=>$id,
                'child_id'=>'basis:'.$finding['review_id'],
                'relation'=>'DERIVED_FROM'];
        }
        $graphNodes[]=[
            'node_id'=>'basis:'.$finding['review_id'],
            'kind'=>'HISTORICAL_BASIS',
            'owner_ref_digest'=>$finding['basis_digest'],
        ];
        $graphNodes[]=[
            'node_id'=>'analysis:'.$finding['review_id'],
            'kind'=>'ANALYSIS',
            'owner_ref_digest'=>$finding['analyst_candidate_digest'],
        ];
        $graphNodes[]=[
            'node_id'=>'finding:'.$finding['review_id'],
            'kind'=>'FINDING',
            'owner_ref_digest'=>$finding['critic_candidate_digest'],
        ];
        $graphLinks[]=['parent_id'=>'basis:'.$finding['review_id'],
            'child_id'=>'analysis:'.$finding['review_id'],
            'relation'=>'DERIVED_FROM'];
        $graphLinks[]=['parent_id'=>'analysis:'.$finding['review_id'],
            'child_id'=>'finding:'.$finding['review_id'],
            'relation'=>'DERIVED_FROM'];
        $rebuilt=HistoryDependencyIndex::freeze([
            'scope'=>$finding['scope'],
            'catalog_cutoff_utc'=>$finding['dependency_graph']['catalog_cutoff_utc'],
            'nodes'=>$graphNodes,'links'=>$graphLinks,
        ]);
        if(!$rebuilt->isOk()
            || $rebuilt->value()!==$finding['dependency_graph']){
            return Outcome::refuse('AN03_FINDING_DEPENDENCY_GRAPH_REWRITTEN');
        }
        try{
            $copy=$finding;$checksum=$copy['finding_digest'];
            unset($copy['finding_digest']);
            if(!hash_equals($checksum,Digest::canonicalDigest($copy))){
                return Outcome::refuse('AN03_FINDING_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('AN03_FINDING_HASH_INVALID');
        }
        return Outcome::ok([
            'integrity'=>'SELF_HASHED_PROVISIONAL_ONLY',
            'current_source_owner_authenticated'=>false,
            'm10_release_authorized'=>false,
        ]);
    }

    /** Closed existing CompanySignals single-diff route cannot represent this. */
    public static function toCompanySignal(array $finding):Outcome
    {
        return Outcome::refuse('AN03_U07_MULTI_EVIDENCE_NATIVE_P01_ROUTE_NOT_ADOPTED');
    }
}
