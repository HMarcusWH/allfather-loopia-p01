<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};

/**
 * AN-04: an explicit, typed, immutable assessment-successor candidate.
 * It is distinct from the original historical finding, a later observation,
 * an IV-01 invalidation event, an M10 correction, or an admitted human grade.
 */
final class HistoryReviewAssessmentSuccessor
{
    public const VERSION='an04.assessment-successor-candidate.v1';

    /** Deterministic pair reconstruction; never mutates either ancestor. */
    public static function compose(array $prior,array $next):Outcome
    {
        if(!HistoryReviewOutcomes::verify($prior)->isOk()
            || !HistoryReviewOutcomes::verify($next)->isOk()
            || $prior['finding_digest']!==$next['finding_digest']
            || $prior['scope']!==$next['scope']
            || $prior['review_id']!==$next['review_id']
            || $prior['historical_basis_digest']!==$next['historical_basis_digest']
            || $prior['original_dependency_graph_digest']
                !==$next['original_dependency_graph_digest']
            || $prior['historical_knowledge_cutoff_utc']
                !==$next['historical_knowledge_cutoff_utc']
            || $prior['original_source_ref_ids']!==$next['original_source_ref_ids']
            || $next['prior_annotation_digest']!==$prior['annotation_digest']
            || $next['inspected_at_utc']<=$prior['inspected_at_utc']){
            return Outcome::refuse('AN04_ASSESSMENT_SUCCESSOR_PARENT_OR_CLOCK_INVALID');
        }
        $candidate=[
            'version'=>self::VERSION,
            'status'=>'ASSESSMENT_SUCCESSOR_CANDIDATE_NOT_ADJUDICATED',
            'scope'=>$prior['scope'],
            'review_id'=>$prior['review_id'],
            'finding_digest'=>$prior['finding_digest'],
            'historical_basis_digest'=>$prior['historical_basis_digest'],
            'original_dependency_graph_digest'=>$prior['original_dependency_graph_digest'],
            'from_annotation_digest'=>$prior['annotation_digest'],
            'to_annotation_digest'=>$next['annotation_digest'],
            'from_reported_disposition'=>$prior['reported_disposition'],
            'to_reported_disposition'=>$next['reported_disposition'],
            'prior_inspected_at_utc'=>$prior['inspected_at_utc'],
            'successor_inspected_at_utc'=>$next['inspected_at_utc'],
            'reported_by'=>$next['observer'],
            'observer_identity_authenticated'=>false,
            'adjudication_status'=>'NOT_ADJUDICATED',
            'iv01_invalidation_disposition'=>'CANDIDATE_REVIEW_ONLY_NO_OWNER_EVENT',
            'm10_correction_or_release_written'=>false,
            'business_outcome_verified'=>false,
            'automatic_model_or_policy_mutation'=>false,
            'customer_visibility_authorized'=>false,
        ];
        $candidate['successor_digest']=Digest::canonicalDigest($candidate);
        return Outcome::ok($candidate);
    }

    /** Self-hash alone cannot authenticate an owner; require exact frozen parents. */
    public static function verify(array $candidate,array $prior,array $next):Outcome
    {
        $fresh=self::compose($prior,$next);
        if(!$fresh->isOk()
            || !is_string($candidate['successor_digest']??null)
            || $fresh->value()!==$candidate){
            return Outcome::refuse('AN04_ASSESSMENT_SUCCESSOR_REHASH_OR_AUTHORITY_FORGERY');
        }
        return Outcome::ok([
            'integrity'=>'SOURCE_PAIR_RECONSTRUCTED_CANDIDATE_ONLY',
            'outcome_adjudicated'=>false,
            'iv01_invalidation_performed'=>false,
            'm10_record_written'=>false,
        ]);
    }
}
