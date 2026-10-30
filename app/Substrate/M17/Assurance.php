<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Workflow\{HistoryDependencyIndex,HistoryReviewOutcomes,NativeReleaseProposal};

/**
 * VA-02: local temporal/provenance *observations*, not the authoritative
 * M11 audit plane. No M05 source-byte access, model or recovery action.
 */
final class Assurance
{
    public const VERSION='va02.m17-provenance-inspection.v1';

    public static function inspect(
        array $proposal,array $outcome,array $attention,string $observedAtUtc,
    ):Outcome {
        if(!NativeReleaseProposal::verify($proposal)->isOk()
            || !HistoryReviewOutcomes::verify($outcome)->isOk()
            || !InternalAttention::verify($attention)->isOk()
            || !Vocabulary::utc($observedAtUtc)){
            return Outcome::refuse('VA02_PROVENANCE_INPUT_INVALID');
        }
        $finding=$proposal['historical_finding'];
        if(!HistoryDependencyIndex::verify($finding['dependency_graph'])->isOk()
            || $proposal['scope']!==$outcome['scope']
            || $proposal['scope']!==$attention['scope']
            || $proposal['finding_digest']!==$outcome['finding_digest']
            || $proposal['finding_digest']!==$attention['historical_finding_digest']
            || $outcome['annotation_digest']!==$attention['outcome_annotation_digest']
            || $proposal['basis_digest']!==$outcome['historical_basis_digest']
            || $proposal['dependency_graph_digest']!==$outcome['original_dependency_graph_digest']
            || $proposal['known_at_cutoff_utc']>$observedAtUtc
            || $outcome['inspected_at_utc']>$observedAtUtc
            || $attention['inspected_at_utc']>$observedAtUtc){
            return Outcome::refuse('VA02_PROVENANCE_SCOPE_CLOCK_OR_LINEAGE_MISMATCH');
        }
        $selected=$finding['primary_references'];
        $contrary=array_values(array_filter($selected,
            static fn(array $r):bool=>$r['polarity']==='CONTRARY'));
        $support=array_values(array_filter($selected,
            static fn(array $r):bool=>$r['cited_as_support']===true));
        $knownTime=strtotime($proposal['known_at_cutoff_utc']);
        $observedTime=strtotime($observedAtUtc);
        if($knownTime===false||$observedTime===false||$observedTime<$knownTime){
            return Outcome::refuse('VA02_PROVENANCE_TIME_INVALID');
        }
        $v=[
            'version'=>self::VERSION,
            'status'=>'VERIFIED_LOCAL_METADATA_ONLY',
            'scope'=>$proposal['scope'],
            'finding_digest'=>$proposal['finding_digest'],
            'basis_digest'=>$proposal['basis_digest'],
            'dependency_graph_digest'=>$proposal['dependency_graph_digest'],
            'outcome_annotation_digest'=>$outcome['annotation_digest'],
            'attention_digest'=>$attention['attention_digest'],
            'observed_at_utc'=>$observedAtUtc,
            'knowledge_cutoff_utc'=>$proposal['known_at_cutoff_utc'],
            'knowledge_cutoff_age_seconds'=>$observedTime-$knownTime,
            'selected_reference_count'=>count($selected),
            'supporting_reference_count'=>count($support),
            'contrary_reference_count'=>count($contrary),
            'dependency_node_count'=>$finding['dependency_graph']['node_count'],
            'dependency_link_count'=>$finding['dependency_graph']['link_count'],
            'lineage_status'=>'SOURCE_HASH_GRAPH_RECONSTRUCTED',
            'review_stage'=>'HUMAN_REVIEW_REQUIRED',
            'outcome_status'=>$outcome['reported_disposition'],
            'attention_status'=>$attention['status'],
            'source_reuse_status'=>'U03_NOT_ADOPTED',
            'source_rights_authenticated'=>false,
            'independent_source_corrob_verified'=>false,
            'human_outcome_adjudicated'=>false,
            'customer_release_authorized'=>false,
            'recovery_or_host_proof_verified'=>false,
        ];
        $v['observation_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }

    /** A forged report cannot become M05/M10 or host assurance. */
    public static function verify(array $v):Outcome
    {
        if(($v['version']??null)!==self::VERSION
            || ($v['status']??null)!=='VERIFIED_LOCAL_METADATA_ONLY'
            || !Vocabulary::scope($v['scope']??null)
            || !Vocabulary::utc($v['observed_at_utc']??null)
            || !Vocabulary::utc($v['knowledge_cutoff_utc']??null)
            || !is_int($v['knowledge_cutoff_age_seconds']??null)
            || $v['knowledge_cutoff_age_seconds']<0
            || !is_int($v['selected_reference_count']??null)
            || $v['selected_reference_count']<2
            || !is_int($v['contrary_reference_count']??null)
            || !is_int($v['supporting_reference_count']??null)
            || $v['contrary_reference_count']>$v['selected_reference_count']
            || $v['supporting_reference_count']>$v['selected_reference_count']
            || ($v['lineage_status']??null)!=='SOURCE_HASH_GRAPH_RECONSTRUCTED'
            || ($v['source_reuse_status']??null)!=='U03_NOT_ADOPTED'
            || !is_string($v['observation_digest']??null)
            || preg_match('/^[a-f0-9]{64}$/D',$v['observation_digest'])!==1){
            return Outcome::refuse('VA02_PROVENANCE_RECORD_INVALID');
        }
        foreach([
            'source_rights_authenticated','independent_source_corrob_verified',
            'human_outcome_adjudicated','customer_release_authorized',
            'recovery_or_host_proof_verified',
        ] as $flag){
            if(($v[$flag]??null)!==false){
                return Outcome::refuse('VA02_PROVENANCE_FORGED_AUTHORITY');
            }
        }
        try{$copy=$v;$hash=$copy['observation_digest'];unset($copy['observation_digest']);
            if(!hash_equals($hash,Digest::canonicalDigest($copy))){
                return Outcome::refuse('VA02_PROVENANCE_HASH_MISMATCH');
            }
        }catch(\Throwable){return Outcome::refuse('VA02_PROVENANCE_ENCODING_INVALID');}
        return Outcome::ok(['integrity'=>'SELF_HASHED_LOCAL_METADATA',
            'real_owner_authority'=>false]);
    }
}
