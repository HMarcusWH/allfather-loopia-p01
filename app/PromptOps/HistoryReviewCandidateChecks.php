<?php

declare(strict_types=1);

namespace Allfather\Loopia\PromptOps;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\P01WindowProfileV2;
use Allfather\Loopia\Substrate\M17\Vocabulary;
use Allfather\Loopia\Workflow\{HistoryReviewAdmission,PointInTimeHistoricalBasis};

/**
 * AN-02 immutable, code-only historical analyst and challenge roles.
 *
 * This is NOT an adopted PromptBundleRegistry role or a model response
 * validator. It has no free-text/raw-source payload, dispatch, or external
 * provider integration. The role's mechanical evidence-set verification
 * does not establish that a claim actually follows from those sources.
 */
final class HistoryReviewCandidateChecks
{
    public const ANALYST_VERSION='an02.local-historical-analyst.v1';
    public const CRITIC_VERSION='an02.local-historical-critic.v1';
    public const MOCK_MODE='LOCAL_SYNTHETIC_FIXTURE_ONLY';
    private const HEX='/^[a-f0-9]{64}$/D';
    private const ANALYST_KEYS=[
        'review_id','role','mode','result_kind','observed_ref_ids',
        'support_ref_ids','contrary_ref_ids','alternative_explanations',
        'limitation_codes','would_change_assessment','statement_code',
    ];
    private const CRITIC_KEYS=[
        'review_id','role','mode','analyst_digest','disposition',
        'challenge_ref_ids','unresolved_contrary_ref_ids',
        'concern_codes','next_check_code',
    ];
    public const ALTERNATIVES=[
        'ROUTINE_MAINTENANCE','SEASONAL_OR_SCHEDULED_CHANGE',
        'NONCAUSAL_CORRELATION','SOURCE_COVERAGE_ARTIFACT',
        'UNKNOWN_EXTERNAL_EXPLANATION',
    ];
    public const LIMITATIONS=[
        'CURRENT_SOURCE_USE_UNADMITTED','ORIGIN_INDEPENDENCE_UNKNOWN',
        'COVERAGE_ONLY_WITHIN_SUPPLIED_INPUT','CAUSALITY_NOT_ESTABLISHED',
        'MODEL_TRUTH_NOT_VERIFIED','SOURCE_BYTES_NOT_INSPECTED',
        'KNOWN_AT_CUTOFF_LIMITED',
    ];
    public const NEXT_CHECKS=[
        'REQUEST_HUMAN_SOURCE_REVIEW','REQUEST_ADDITIONAL_OWNER_EVIDENCE',
        'RECONCILE_CONTRARY_REFERENCES','CHECK_FUTURE_OBSERVATIONS',
        'NO_FURTHER_ACTION_RECOMMENDED',
    ];
    public const STATEMENTS=[
        'DESCRIPTIVE_SHIFT_HYPOTHESIS','NO_DISTINCT_PATTERN_DETECTED',
        'SOURCE_BASIS_INCOMPLETE','ABSTAIN_FROM_INTERPRETATION',
    ];

    /** @param array<string,mixed> $output */
    public static function analyst(
        array $request,array $basis,array $memory,array $output,
    ):Outcome {
        if(!HistoryReviewAdmission::verify($request)->isOk()
            || !PointInTimeHistoricalBasis::verify($basis)->isOk()
            || !P01WindowProfileV2::verify($memory)->isOk()
            || ($request['scope']??null)!==($basis['scope']??null)
            || ($request['basis_digest']??null)!==($basis['basis_digest']??null)
            || ($memory['run_identity']['tenant_id']??null)!==$request['scope']['tenant_id']
            || ($memory['run_identity']['workspace_id']??null)!==$request['scope']['workspace_id']
            || ($memory['run_identity']['subject_id']??null)!==$request['scope']['competitor_id']
            || ($memory['run_identity']['question_id']??null)!==$request['scope']['question_id']
            || ($memory['selected_reference_count']??null)!==($basis['selected_count']??null)
            || !Vocabulary::exactKeys($output,self::ANALYST_KEYS)) {
            return Outcome::refuse('AN02_ANALYST_SCOPE_BASIS_OR_ROLE_INVALID');
        }
        if($output['role']!=='HISTORICAL_ANALYST'
            || $output['mode']!==self::MOCK_MODE
            || $output['review_id']!==$request['review_id']
            || !in_array($output['result_kind'],[
                'HYPOTHESIS_FOR_HUMAN','NO_NEW_INSIGHT',
                'INSUFFICIENT_EVIDENCE','ABSTAIN',
            ],true)
            || !in_array($output['statement_code'],self::STATEMENTS,true)
            || !self::codes($output['alternative_explanations'],self::ALTERNATIVES,5)
            || !self::codes($output['limitation_codes'],self::LIMITATIONS,7)
            || !self::codes($output['would_change_assessment'],self::NEXT_CHECKS,5)
            || !self::ids($output['observed_ref_ids'],32)
            || !self::ids($output['support_ref_ids'],32)
            || !self::ids($output['contrary_ref_ids'],32)) {
            return Outcome::refuse('AN02_ANALYST_OUTPUT_CONTRACT_INVALID');
        }
        $derived=\Allfather\Loopia\Workflow\HistoryM17V2CandidateBridge::compose(
            $basis,$memory['run_identity']['data_class'],
            $memory['run_identity']['window_kind'],
        );
        if(!$derived->isOk()
            || $derived->value()['candidate_v2']['candidate_digest']
                !==$memory['candidate_digest']){
            return Outcome::refuse('AN02_ANALYST_M17_PRIMARY_REF_LINEAGE_MISMATCH');
        }
        $refs=$basis['selected_references'];
        $known=array_fill_keys(array_column($refs,'ref_id'),true);
        $trueContrary=[];
        foreach($refs as $ref){
            if($ref['semantic_polarity']==='CONTRARY')$trueContrary[]=$ref['ref_id'];
        }
        sort($trueContrary,SORT_STRING);
        $claimedContrary=$output['contrary_ref_ids'];
        sort($claimedContrary,SORT_STRING);
        $observed=$output['observed_ref_ids'];
        $expectedObserved=array_column($refs,'ref_id');
        if($observed!==$expectedObserved
            || $claimedContrary!==$trueContrary
            || !self::subset($output['support_ref_ids'],$known)
            || $basis['knowledge_cutoff_utc']>$request['requested_at_utc']) {
            return Outcome::refuse('AN02_ANALYST_EVIDENCE_OR_CONTRARY_OMITTED');
        }
        if($output['result_kind']==='HYPOTHESIS_FOR_HUMAN'){
            if($output['support_ref_ids']===[]
                || $output['alternative_explanations']===[]
                || $output['limitation_codes']==[]
                || $output['would_change_assessment']=== []
                || $output['statement_code']!=='DESCRIPTIVE_SHIFT_HYPOTHESIS'){
                return Outcome::refuse('AN02_HYPOTHESIS_WITHOUT_ALTERNATIVES_OR_SUPPORT');
            }
        }elseif($output['result_kind']==='NO_NEW_INSIGHT'
            && $output['statement_code']!=='NO_DISTINCT_PATTERN_DETECTED'){
            return Outcome::refuse('AN02_NO_NEW_INSIGHT_CONTRACT_INVALID');
        }elseif($output['result_kind']==='INSUFFICIENT_EVIDENCE'
            && $output['statement_code']!=='SOURCE_BASIS_INCOMPLETE'){
            return Outcome::refuse('AN02_INSUFFICIENT_EVIDENCE_CONTRACT_INVALID');
        }elseif($output['result_kind']==='ABSTAIN'
            && $output['statement_code']!=='ABSTAIN_FROM_INTERPRETATION'){
            return Outcome::refuse('AN02_ABSTAIN_CONTRACT_INVALID');
        }
        $record=[
            'version'=>self::ANALYST_VERSION,
            'role'=>'HISTORICAL_ANALYST',
            'candidate_state'=>'SYNTHETIC_ROLE_OUTPUT_NOT_ADMITTED',
            'scope'=>$request['scope'],
            'review_id'=>$request['review_id'],
            'request_candidate_digest'=>$request['candidate_digest'],
            'basis_digest'=>$basis['basis_digest'],
            'm17_run_digest'=>$memory['derivation_identity_digest'],
            'known_at_cutoff_utc'=>$basis['knowledge_cutoff_utc'],
            'output'=>$output,
            'basis_selected_reference_count'=>$basis['selected_count'],
            'contrary_refs_preserved'=>true,
            'primary_evidence_text_checked'=>false,
            'factual_truth_verified'=>false,
            'external_model_output'=>false,
            'critic_human_independent'=>false,
            'human_factual_release_approved'=>false,
            'human_delivery_approved'=>false,
            'customer_output_authorized'=>false,
        ];
        $record['candidate_digest']=Digest::canonicalDigest($record);
        return Outcome::ok($record);
    }

    /** @param array<string,mixed> $output */
    public static function critic(array $analyst,array $output):Outcome
    {
        if(!self::verifyAnalyst($analyst)->isOk()
            || !Vocabulary::exactKeys($output,self::CRITIC_KEYS)
            || $output['review_id']!==$analyst['review_id']
            || $output['role']!=='HISTORICAL_CRITIC'
            || $output['mode']!==self::MOCK_MODE
            || $output['analyst_digest']!==$analyst['candidate_digest']
            || !in_array($output['disposition'],[
                'PASS_TO_HUMAN','REVISE','BLOCK',
                'NO_NEW_INSIGHT','INSUFFICIENT_EVIDENCE',
            ],true)
            || !self::ids($output['challenge_ref_ids'],32)
            || !self::ids($output['unresolved_contrary_ref_ids'],32)
            || !self::codes($output['concern_codes'],[
                'CONTRARY_EVIDENCE_UNRESOLVED','ALTERNATIVE_NOT_TESTED',
                'PRIMARY_EVIDENCE_UNVERIFIED','INSUFFICIENT_COVERAGE',
                'DUPLICATED_ORIGIN_UNKNOWN','CLAIM_OVERREACH','NONE',
            ],7)
            || !in_array($output['next_check_code'],self::NEXT_CHECKS,true)) {
            return Outcome::refuse('AN02_CRITIC_OUTPUT_CONTRACT_INVALID');
        }
        $known=array_fill_keys($analyst['output']['observed_ref_ids'],true);
        if(!self::subset($output['challenge_ref_ids'],$known)
            || !self::subset($output['unresolved_contrary_ref_ids'],$known)) {
            return Outcome::refuse('AN02_CRITIC_REFERENCE_OUTSIDE_BASIS');
        }
        $trueContrary=$analyst['output']['contrary_ref_ids'];
        sort($trueContrary,SORT_STRING);
        $claimed=$output['unresolved_contrary_ref_ids'];
        sort($claimed,SORT_STRING);
        if($claimed!==$trueContrary) {
            return Outcome::refuse('AN02_CRITIC_CANNOT_ERASE_CONTRARY');
        }
        if($output['disposition']==='PASS_TO_HUMAN'
            && ($output['concern_codes']===[]
                || $output['concern_codes']===['NONE']
                || $analyst['output']['result_kind']!=='HYPOTHESIS_FOR_HUMAN')) {
            return Outcome::refuse('AN02_CRITIC_MUST_CHALLENGE_BEFORE_HUMAN');
        }
        if($output['disposition']==='NO_NEW_INSIGHT'
            && $analyst['output']['result_kind']!=='NO_NEW_INSIGHT'){
            return Outcome::refuse('AN02_NO_NEW_INSIGHT_MISMATCH');
        }
        if($output['disposition']==='INSUFFICIENT_EVIDENCE'
            && $analyst['output']['result_kind']!=='INSUFFICIENT_EVIDENCE'){
            return Outcome::refuse('AN02_INSUFFICIENT_EVIDENCE_MISMATCH');
        }
        $record=[
            'version'=>self::CRITIC_VERSION,
            'candidate_state'=>'SYNTHETIC_CRITIC_NOT_HUMAN',
            'scope'=>$analyst['scope'],'review_id'=>$analyst['review_id'],
            'request_candidate_digest'=>$analyst['request_candidate_digest'],
            'basis_digest'=>$analyst['basis_digest'],
            'analyst_candidate_digest'=>$analyst['candidate_digest'],
            'output'=>$output,
            'contrary_refs_preserved'=>true,
            'independent_empirical_verification'=>false,
            'model_critic_is_human'=>false,
            'human_factual_release_approved'=>false,
            'human_delivery_approved'=>false,
            'customer_output_authorized'=>false,
        ];
        $record['candidate_digest']=Digest::canonicalDigest($record);
        return Outcome::ok($record);
    }

    public static function verifyAnalyst(array $value):Outcome
    {
        if(!Vocabulary::exactKeys($value,[
            'version','role','candidate_state','scope','review_id',
            'request_candidate_digest','basis_digest','m17_run_digest',
            'known_at_cutoff_utc','output','basis_selected_reference_count',
            'contrary_refs_preserved','primary_evidence_text_checked',
            'factual_truth_verified','external_model_output',
            'critic_human_independent','human_factual_release_approved',
            'human_delivery_approved','customer_output_authorized',
            'candidate_digest',
        ]) || !is_array($value['output']??null)
            || !Vocabulary::exactKeys($value['output'],self::ANALYST_KEYS)
            || !self::ids($value['output']['observed_ref_ids'],32)
            || !self::ids($value['output']['support_ref_ids'],32)
            || !self::ids($value['output']['contrary_ref_ids'],32)
            || !self::codes($value['output']['alternative_explanations'],self::ALTERNATIVES,5)
            || !self::codes($value['output']['limitation_codes'],self::LIMITATIONS,7)
            || !self::codes($value['output']['would_change_assessment'],self::NEXT_CHECKS,5)
            || !in_array($value['output']['statement_code'],self::STATEMENTS,true)
            || !in_array($value['output']['result_kind'],[
                'HYPOTHESIS_FOR_HUMAN','NO_NEW_INSIGHT',
                'INSUFFICIENT_EVIDENCE','ABSTAIN',
            ],true)
            || ($value['output']['mode']??null)!==self::MOCK_MODE
            || !is_int($value['basis_selected_reference_count'])
            || $value['basis_selected_reference_count']!==count($value['output']['observed_ref_ids'])
            || !self::subset($value['output']['support_ref_ids'],
                array_fill_keys($value['output']['observed_ref_ids'],true))
            || !self::subset($value['output']['contrary_ref_ids'],
                array_fill_keys($value['output']['observed_ref_ids'],true))
            || !Vocabulary::utc($value['known_at_cutoff_utc'])){
            return Outcome::refuse('AN02_ANALYST_CANDIDATE_NOT_REPLAYABLE');
        }
        if(!self::integrity($value,self::ANALYST_VERSION,[
            'contrary_refs_preserved'=>true,'primary_evidence_text_checked'=>false,
            'factual_truth_verified'=>false,'external_model_output'=>false,
            'critic_human_independent'=>false,
            'human_factual_release_approved'=>false,
            'human_delivery_approved'=>false,'customer_output_authorized'=>false,
        ]) || ($value['candidate_state']??null)!=='SYNTHETIC_ROLE_OUTPUT_NOT_ADMITTED'
            || ($value['role']??null)!=='HISTORICAL_ANALYST'
            || ($value['output']['role']??null)!=='HISTORICAL_ANALYST'
            || ($value['output']['review_id']??null)!==($value['review_id']??null)) {
            return Outcome::refuse('AN02_ANALYST_CANDIDATE_NOT_REPLAYABLE');
        }
        return Outcome::ok(['integrity_only'=>true,'human_review_approved'=>false]);
    }

    public static function verifyCritic(array $value):Outcome
    {
        if(!Vocabulary::exactKeys($value,[
            'version','candidate_state','scope','review_id',
            'request_candidate_digest','basis_digest',
            'analyst_candidate_digest','output','contrary_refs_preserved',
            'independent_empirical_verification','model_critic_is_human',
            'human_factual_release_approved','human_delivery_approved',
            'customer_output_authorized','candidate_digest',
        ]) || !is_array($value['output']??null)
            || !Vocabulary::exactKeys($value['output'],self::CRITIC_KEYS)
            || ($value['output']['mode']??null)!==self::MOCK_MODE
            || !in_array($value['output']['disposition'],[
                'PASS_TO_HUMAN','REVISE','BLOCK','NO_NEW_INSIGHT',
                'INSUFFICIENT_EVIDENCE',
            ],true)
            || !self::ids($value['output']['challenge_ref_ids'],32)
            || !self::ids($value['output']['unresolved_contrary_ref_ids'],32)
            || !self::codes($value['output']['concern_codes'],[
                'CONTRARY_EVIDENCE_UNRESOLVED','ALTERNATIVE_NOT_TESTED',
                'PRIMARY_EVIDENCE_UNVERIFIED','INSUFFICIENT_COVERAGE',
                'DUPLICATED_ORIGIN_UNKNOWN','CLAIM_OVERREACH','NONE',
            ],7)
            || !in_array($value['output']['next_check_code'],self::NEXT_CHECKS,true)){
            return Outcome::refuse('AN02_CRITIC_CANDIDATE_NOT_REPLAYABLE');
        }
        if(!self::integrity($value,self::CRITIC_VERSION,[
            'contrary_refs_preserved'=>true,'independent_empirical_verification'=>false,
            'model_critic_is_human'=>false,
            'human_factual_release_approved'=>false,
            'human_delivery_approved'=>false,'customer_output_authorized'=>false,
        ]) || ($value['candidate_state']??null)!=='SYNTHETIC_CRITIC_NOT_HUMAN'
            || ($value['output']['analyst_digest']??null)!==($value['analyst_candidate_digest']??null)
            || ($value['output']['role']??null)!=='HISTORICAL_CRITIC'
            || ($value['output']['review_id']??null)!==($value['review_id']??null)) {
            return Outcome::refuse('AN02_CRITIC_CANDIDATE_NOT_REPLAYABLE');
        }
        return Outcome::ok(['integrity_only'=>true,'human_review_approved'=>false]);
    }

    /** @param array<string,bool> $allowed */
    private static function subset(array $items,array $allowed):bool
    {
        foreach($items as $id) if(!isset($allowed[$id]))return false;
        return true;
    }
    private static function ids(mixed $v,int $max):bool
    {
        if(!is_array($v)||!array_is_list($v)||count($v)>$max)return false;
        $seen=[];
        foreach($v as $id){
            if(!Vocabulary::id($id)||isset($seen[$id]))return false;
            $seen[$id]=true;
        }
        return true;
    }
    private static function codes(mixed $v,array $allowed,int $max):bool
    {
        return self::ids($v,$max) && self::subset($v,array_fill_keys($allowed,true));
    }
    /** @param array<string,bool> $flags */
    private static function integrity(array $v,string $version,array $flags):bool
    {
        if(($v['version']??null)!==$version
            || !is_string($v['candidate_digest']??null)
            || preg_match(self::HEX,$v['candidate_digest'])!==1
            || !Vocabulary::scope($v['scope']??null)
            || !Vocabulary::id($v['review_id']??null))return false;
        foreach($flags as $k=>$expected)if(($v[$k]??null)!==$expected)return false;
        try{
            $d=$v['candidate_digest'];unset($v['candidate_digest']);
            return hash_equals($d,Digest::canonicalDigest($v));
        }catch(\Throwable){return false;}
    }
}
