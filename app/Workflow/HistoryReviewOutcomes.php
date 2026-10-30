<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * AN-04: later-evidence OUTCOME ANNOTATION CANDIDATE on an immutable
 * provisional AN-03 historical finding.
 *
 * Outcomes are unadjudicated hypotheses about subsequent information,
 * not owner-authenticated business success, M11 truth or feedback weights.
 * Only new append-only successors can revise an earlier annotation.
 */
final class HistoryReviewOutcomes
{
    public const VERSION='an04.provisional-history-outcome.v2';
    public const MAX_NEW_REFS=32;
    public const KINDS=[
        'STILL_UNRESOLVED','REPORTED_CONFIRMED_IN_SCOPE',
        'REPORTED_CONTRADICTED','COVERAGE_INSUFFICIENT',
        'REPORTED_SUPERSEDED',
    ];
    private const HEX='/^[a-f0-9]{64}$/D';
    private const INPUT_KEYS=[
        'finding','prior_annotation','observer','disposition','new_evidence',
        'inspected_at_utc','deadline_utc','next_check_code',
    ];
    private const EVIDENCE_KEYS=[
        'ref_id','source_ref_id','evidence_id','snapshot_id',
        'payload_sha256','observed_at_utc','known_at_utc','polarity',
    ];

    /** Candidate-only frozen, later-known evidence snapshot. */
    public static function propose(array $input):Outcome
    {
        if(!Vocabulary::exactKeys($input,self::INPUT_KEYS)
            || !is_array($input['finding'])
            || !HistoricalFindingAdapter::verify($input['finding'])->isOk()
            || !is_array($input['observer'])
            || !Vocabulary::exactKeys($input['observer'],[
                'actor_type','actor_id',
            ])
            || !in_array($input['observer']['actor_type'],[
                'SYNTHETIC_FIXTURE','OPERATOR_REPORTED',
            ],true)
            || !Vocabulary::id($input['observer']['actor_id'])
            || !in_array($input['disposition'],self::KINDS,true)
            || !Vocabulary::utc($input['inspected_at_utc'])
            || ($input['deadline_utc']!==null
                && (!Vocabulary::utc($input['deadline_utc'])
                    || $input['deadline_utc']<$input['inspected_at_utc']))
            || !in_array($input['next_check_code'],[
                'HUMAN_VALIDATE_SOURCE','RECHECK_CONTRARY_EVIDENCE',
                'WAIT_NEXT_OBSERVATION','REVIEW_COVERAGE_GAPS',
                'ESCALATE_OWNER_SCOPE',
            ],true)
            || !is_array($input['new_evidence'])
            || !array_is_list($input['new_evidence'])
            || count($input['new_evidence'])>self::MAX_NEW_REFS){
            return Outcome::refuse('AN04_OUTCOME_INPUT_OR_SCOPE_INVALID');
        }
        $finding=$input['finding'];$prior=$input['prior_annotation'];
        if($input['inspected_at_utc']<$finding['known_at_cutoff_utc']){
            return Outcome::refuse('AN04_CANNOT_REWRITE_HISTORICAL_KNOWLEDGE');
        }
        if($prior!==null){
            if(!is_array($prior)
                || !self::verify($prior)->isOk()
                || $prior['finding_digest']!==$finding['finding_digest']
                || $prior['scope']!==$finding['scope']
                || $prior['inspected_at_utc']>=$input['inspected_at_utc']){
                return Outcome::refuse('AN04_OUTCOME_PREDECESSOR_CONFLICT');
            }
        }
        $oldRefs=array_fill_keys(
            array_column($finding['primary_references'],'ref_id'),true,
        );
        $seen=[];$rows=[];$support=0;$contrary=0;
        foreach($input['new_evidence'] as $ref){
            if(!is_array($ref)
                || !Vocabulary::exactKeys($ref,self::EVIDENCE_KEYS)
                || !Vocabulary::id($ref['ref_id'])
                || isset($seen[$ref['ref_id']])
                || isset($oldRefs[$ref['ref_id']])
                || !Vocabulary::id($ref['source_ref_id'])
                || !Vocabulary::id($ref['evidence_id'])
                || !Vocabulary::id($ref['snapshot_id'])
                || !is_string($ref['payload_sha256'])
                || preg_match(self::HEX,$ref['payload_sha256'])!==1
                || !Vocabulary::utc($ref['observed_at_utc'])
                || !Vocabulary::utc($ref['known_at_utc'])
                || $ref['observed_at_utc']>$input['inspected_at_utc']
                || $ref['known_at_utc']>$input['inspected_at_utc']
                || $ref['known_at_utc']<=$finding['known_at_cutoff_utc']
                || !in_array($ref['polarity'],['SUPPORT','CONTRARY','UNKNOWN'],true)){
                return Outcome::refuse('AN04_NEW_EVIDENCE_METADATA_INVALID_OR_BACKDATED');
            }
            $seen[$ref['ref_id']]=true;
            if($ref['polarity']==='SUPPORT')$support++;
            if($ref['polarity']==='CONTRARY')$contrary++;
            $rows[]=$ref;
        }
        usort($rows,static fn(array $a,array $b):int=>
            [$a['known_at_utc'],$a['ref_id']]
            <=>
            [$b['known_at_utc'],$b['ref_id']]);
        if(($input['disposition']==='REPORTED_CONTRADICTED' && $contrary<1)
            || ($input['disposition']==='REPORTED_CONFIRMED_IN_SCOPE' && $support<1)
            || ($input['disposition']==='REPORTED_SUPERSEDED' && $rows===[])){
            return Outcome::refuse('AN04_OUTCOME_CLAIM_WITHOUT_LATER_EVIDENCE');
        }
        $originalSources=array_values(array_unique(
            array_column($finding['primary_references'],'source_ref_id'),
        ));
        sort($originalSources,SORT_STRING);
        $annot=[
            'version'=>self::VERSION,
            'status'=>'OUTCOME_ANNOTATION_CANDIDATE_NOT_ADJUDICATED',
            'scope'=>$finding['scope'],
            'review_id'=>$finding['review_id'],
            'finding_digest'=>$finding['finding_digest'],
            'historical_basis_digest'=>$finding['basis_digest'],
            'historical_knowledge_cutoff_utc'=>$finding['known_at_cutoff_utc'],
            'prior_annotation_digest'=>$prior['annotation_digest']??null,
            'observer'=>$input['observer'],
            'observer_identity_authenticated'=>false,
            'original_dependency_graph_digest'=>$finding['dependency_graph']['graph_digest'],
            'original_source_ref_ids'=>$originalSources,
            'uncertainty_code'=>'LATER_EVIDENCE_NOT_OWNER_AUTHENTICATED',
            'adjudication_status'=>'NOT_ADJUDICATED',
            'iv01_invalidation_status'=>'PROPOSAL_ONLY_NO_INVALIDATION_EXECUTED',
            'reported_disposition'=>$input['disposition'],
            'inspected_at_utc'=>$input['inspected_at_utc'],
            'deadline_utc'=>$input['deadline_utc'],
            'next_check_code'=>$input['next_check_code'],
            'later_evidence_refs'=>$rows,
            'new_ref_count'=>count($rows),
            'new_support_count'=>$support,
            'new_contrary_count'=>$contrary,
            'original_finding_immutable'=>true,
            'new_source_owner_verified'=>false,
            'evidence_bytes_read_here'=>false,
            'outcome_adjudicated_by_human'=>false,
            'business_success_confirmed'=>false,
            'causal_attribution_established'=>false,
            'm11_reliability_weight_admitted'=>false,
            'automatic_prompt_or_weight_update'=>false,
            'automatic_schedule_update'=>false,
            'provider_or_fetch_authorized'=>false,
            'm10_release_or_withdrawal_authorized'=>false,
            'customer_visibility_authorized'=>false,
        ];
        $annot['annotation_digest']=Digest::canonicalDigest($annot);
        return Outcome::ok($annot);
    }

    /** Exact self-integrity, not source owner/adjudicator authentication. */
    public static function verify(array $annotation):Outcome
    {
        if(($annotation['version']??null)!==self::VERSION
            || ($annotation['status']??null)!=='OUTCOME_ANNOTATION_CANDIDATE_NOT_ADJUDICATED'
            || !Vocabulary::scope($annotation['scope']??null)
            || !Vocabulary::id($annotation['review_id']??null)
            || !is_array($annotation['observer']??null)
            || !Vocabulary::exactKeys($annotation['observer'],[
                'actor_type','actor_id',
            ])
            || !in_array($annotation['observer']['actor_type'],[
                'SYNTHETIC_FIXTURE','OPERATOR_REPORTED',
            ],true)
            || !Vocabulary::id($annotation['observer']['actor_id'])
            || ($annotation['observer_identity_authenticated']??null)!==false
            || !is_string($annotation['original_dependency_graph_digest']??null)
            || preg_match(self::HEX,$annotation['original_dependency_graph_digest'])!==1
            || !is_array($annotation['original_source_ref_ids']??null)
            || !array_is_list($annotation['original_source_ref_ids'])
            || count($annotation['original_source_ref_ids'])<1
            || count($annotation['original_source_ref_ids'])>self::MAX_NEW_REFS
            || count(array_unique($annotation['original_source_ref_ids']))
                !==count($annotation['original_source_ref_ids'])
            || ($annotation['uncertainty_code']??null)!=='LATER_EVIDENCE_NOT_OWNER_AUTHENTICATED'
            || ($annotation['adjudication_status']??null)!=='NOT_ADJUDICATED'
            || ($annotation['iv01_invalidation_status']??null)!=='PROPOSAL_ONLY_NO_INVALIDATION_EXECUTED'
            || !is_string($annotation['annotation_digest']??null)
            || preg_match(self::HEX,$annotation['annotation_digest'])!==1
            || !in_array($annotation['reported_disposition']??null,self::KINDS,true)
            || !Vocabulary::utc($annotation['historical_knowledge_cutoff_utc']??null)
            || !Vocabulary::utc($annotation['inspected_at_utc']??null)
            || $annotation['inspected_at_utc']<$annotation['historical_knowledge_cutoff_utc']
            || !is_array($annotation['later_evidence_refs']??null)
            || !array_is_list($annotation['later_evidence_refs'])
            || count($annotation['later_evidence_refs'])>self::MAX_NEW_REFS
            || ($annotation['new_ref_count']??null)!==count($annotation['later_evidence_refs'])
            || ($annotation['original_finding_immutable']??null)!==true){
            return Outcome::refuse('AN04_ANNOTATION_CONTRACT_INVALID');
        }
        foreach([
            'new_source_owner_verified','evidence_bytes_read_here',
            'outcome_adjudicated_by_human','business_success_confirmed',
            'causal_attribution_established','m11_reliability_weight_admitted',
            'automatic_prompt_or_weight_update','automatic_schedule_update',
            'provider_or_fetch_authorized','m10_release_or_withdrawal_authorized',
            'customer_visibility_authorized',
        ] as $flag){
            if(($annotation[$flag]??null)!==false){
                return Outcome::refuse('AN04_ANNOTATION_FORGED_AUTHORITY');
            }
        }
        foreach(['finding_digest','historical_basis_digest'] as $name){
            if(!is_string($annotation[$name]??null)
                || preg_match(self::HEX,$annotation[$name])!==1){
                return Outcome::refuse('AN04_PARENT_IDENTITY_INVALID');
            }
        }
        if(($annotation['prior_annotation_digest']??null)!==null
            && (!is_string($annotation['prior_annotation_digest'])
                || preg_match(self::HEX,$annotation['prior_annotation_digest'])!==1)){
            return Outcome::refuse('AN04_PARENT_ANNOTATION_DIGEST_INVALID');
        }
        if(($annotation['deadline_utc']??null)!==null
            && (!Vocabulary::utc($annotation['deadline_utc'])
                || $annotation['deadline_utc']<$annotation['inspected_at_utc'])){
            return Outcome::refuse('AN04_ANNOTATION_DEADLINE_INVALID');
        }
        $sortedSources=$annotation['original_source_ref_ids'];
        foreach($sortedSources as $sourceId){
            if(!Vocabulary::id($sourceId)){
                return Outcome::refuse('AN04_ANNOTATION_SOURCE_ID_INVALID');
            }
        }
        $expectedSources=$sortedSources;
        sort($expectedSources,SORT_STRING);
        if($expectedSources!==$sortedSources){
            return Outcome::refuse('AN04_ANNOTATION_SOURCE_ORDER_INVALID');
        }
        $seen=[];$countSupport=0;$countContrary=0;$previous=null;
        foreach($annotation['later_evidence_refs'] as $row){
            if(!is_array($row)
                || !Vocabulary::exactKeys($row,self::EVIDENCE_KEYS)
                || !Vocabulary::id($row['ref_id'])
                || isset($seen[$row['ref_id']])
                || !Vocabulary::id($row['source_ref_id'])
                || !Vocabulary::id($row['evidence_id'])
                || !Vocabulary::id($row['snapshot_id'])
                || !is_string($row['payload_sha256'])
                || preg_match(self::HEX,$row['payload_sha256'])!==1
                || !Vocabulary::utc($row['observed_at_utc'])
                || !Vocabulary::utc($row['known_at_utc'])
                || $row['observed_at_utc']>$annotation['inspected_at_utc']
                || $row['known_at_utc']>$annotation['inspected_at_utc']
                || $row['known_at_utc']<=$annotation['historical_knowledge_cutoff_utc']
                || !in_array($row['polarity'],['SUPPORT','CONTRARY','UNKNOWN'],true)){
                return Outcome::refuse('AN04_ANNOTATION_LATER_REF_INVALID');
            }
            $position=[$row['known_at_utc'],$row['ref_id']];
            if($previous!==null && $previous>$position){
                return Outcome::refuse('AN04_ANNOTATION_REFERENCE_ORDER_INVALID');
            }
            $previous=$position;$seen[$row['ref_id']]=true;
            if($row['polarity']==='SUPPORT')$countSupport++;
            if($row['polarity']==='CONTRARY')$countContrary++;
        }
        if(($annotation['new_support_count']??null)!==$countSupport
            || ($annotation['new_contrary_count']??null)!==$countContrary
            || ($annotation['reported_disposition']==='REPORTED_CONTRADICTED'
                && $countContrary<1)
            || ($annotation['reported_disposition']==='REPORTED_CONFIRMED_IN_SCOPE'
                && $countSupport<1)
            || ($annotation['reported_disposition']==='REPORTED_SUPERSEDED'
                && $annotation['later_evidence_refs']===[])){
            return Outcome::refuse('AN04_ANNOTATION_DISPOSITION_OR_COUNT_INVALID');
        }
        try{
            $copy=$annotation;$checksum=$copy['annotation_digest'];
            unset($copy['annotation_digest']);
            if(!hash_equals($checksum,Digest::canonicalDigest($copy))){
                return Outcome::refuse('AN04_ANNOTATION_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('AN04_ANNOTATION_ENCODING_INVALID');
        }
        return Outcome::ok(['integrity'=>'SELF_HASHED_CANDIDATE_ONLY',
            'business_outcome_adjudicated'=>false]);
    }
}
