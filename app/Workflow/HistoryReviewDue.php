<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * DU-01: bounded deterministic review-due arithmetic over an HS-01 candidate.
 *
 * Unlike v1, an unchanged/quiet basis may be reviewed on its own clock.
 * A DUE is an inert human work candidate, never a scheduler occurrence,
 * model attempt, rights decision, notification, release or truth assertion.
 */
final class HistoryReviewDue
{
    public const VERSION='du01.review-due-candidate.v2';
    private const STATE_FIELDS=[
        'latest_observed_at_utc','latest_available_at_utc',
        'last_queued_at_utc','last_analysed_at_utc',
        'last_human_reviewed_at_utc','last_considered_at_utc',
        'last_considered_basis_digest','pending_work',
        'reviews_queued_today','current_source_posture',
        'independent_event_count','verified_contradiction_count',
    ];

    /** @param array<string,mixed> $input */
    public static function assess(array $input): Outcome
    {
        if (!is_array($input)
            || !Vocabulary::exactKeys($input,['policy','basis','state','at_utc'])
            || !is_array($input['policy'])
            || !HistoryReviewPolicy::verify($input['policy'])->isOk()
            || !is_array($input['basis'])
            || !PointInTimeHistoricalBasis::verify($input['basis'])->isOk()
            || !is_array($input['state'])
            || !Vocabulary::exactKeys($input['state'],self::STATE_FIELDS)
            || !Vocabulary::utc($input['at_utc'])) {
            return Outcome::refuse('DU01_DUE_CONTRACT_INVALID');
        }
        $p=$input['policy'];$basis=$input['basis'];$s=$input['state'];$now=$input['at_utc'];
        if ($p['scope']!==$basis['scope']
            || $basis['selected_count']>$p['max_basis_references']
            || $basis['knowledge_cutoff_utc']>$now
            || $basis['review_at_utc']>$now
            || !in_array($s['current_source_posture'],[
                'UNKNOWN','CURRENT_RIGHTS_BLOCKED','OWNER_METADATA_READ_TEST_ONLY',
            ],true)) {
            return Outcome::refuse('DU01_DUE_SCOPE_OR_CUTOFF_INVALID');
        }
        foreach($basis['selected_references'] as $ref){
            if(!in_array($ref['source_ref_id'],$p['monitored_source_refs'],true)) {
                return Outcome::refuse('DU01_SOURCE_OUTSIDE_FROZEN_POLICY');
            }
        }
        if (!is_int($s['pending_work'])||$s['pending_work']<0||$s['pending_work']>100000
            || !is_int($s['reviews_queued_today'])||$s['reviews_queued_today']<0
            || $s['reviews_queued_today']>100000
            || $s['independent_event_count']!==null
            || $s['verified_contradiction_count']!==null) {
            // Neither U02 real-world events nor independently verified
            // contradictions exist as owner-admitted historical trigger inputs.
            return Outcome::refuse('DU01_DUE_STATE_OR_ORIGIN_UNKNOWN');
        }
        foreach([
            'latest_observed_at_utc','latest_available_at_utc',
            'last_queued_at_utc','last_analysed_at_utc',
            'last_human_reviewed_at_utc','last_considered_at_utc',
        ] as $key) {
            if ($s[$key]!==null
                && (!Vocabulary::utc($s[$key])||$s[$key]>$now)) {
                return Outcome::refuse('DU01_DUE_WATERMARK_INVALID');
            }
        }
        if (($s['last_considered_at_utc']===null)!==($s['last_considered_basis_digest']===null)
            || ($s['last_considered_basis_digest']!==null
                && (!is_string($s['last_considered_basis_digest'])
                    || preg_match('/^[a-f0-9]{64}$/D',
                        $s['last_considered_basis_digest'])!==1))
            || ($s['latest_observed_at_utc']!==null
                && $s['latest_available_at_utc']!==null
                && $s['latest_observed_at_utc']>$s['latest_available_at_utc'])
            || ($s['latest_available_at_utc']!==null
                && $s['latest_available_at_utc']>$basis['knowledge_cutoff_utc'])) {
            return Outcome::refuse('DU01_DUE_WATERMARK_INVALID');
        }
        $same=$s['last_considered_basis_digest']!==null
            && hash_equals($s['last_considered_basis_digest'],$basis['basis_digest']);
        $anchor=$s['last_human_reviewed_at_utc']??$s['last_considered_at_utc'];
        $elapsed=$anchor===null?null:(strtotime($now)-strtotime($anchor));
        $clockDue=$elapsed===null
            || $elapsed >= $p['cadence_seconds'];
        $maxWait=$elapsed!==null && $elapsed >= $p['maximum_wait_seconds'];
        $changed=!$same && $basis['selected_count']>0;
        // Triggered on clock even for an unchanged basis. Not an assertion
        // that a real-world event exists, or even that captures were complete.
        $wouldBeDue=$basis['selected_count']>0 && $clockDue;
        $reason=$anchor===null?'FIRST_CONSIDERATION'
            : ($maxWait?'MAXIMUM_WAIT':
                ($clockDue?($same?'QUIET_REVIEW_CLOCK':'NEW_BASIS_REVIEW_CLOCK')
                    : ($changed?'NEW_BASIS_WAITING_CADENCE':'CLOCK_NOT_YET_DUE')));
        $status='NOT_DUE_CANDIDATE';
        if ($s['current_source_posture']!=='OWNER_METADATA_READ_TEST_ONLY') {
            $status='HOLD_CURRENT_RIGHTS';$reason='SOURCE_RIGHTS_NOT_OWNER_ADMITTED';
        } elseif ($basis['selection_integrity']!=='COMPLETE_WITHIN_INPUT'
            || $basis['selected_count']===0) {
            $status='HOLD_CURRENT_RIGHTS';$reason='INCOMPLETE_OR_EMPTY_HISTORICAL_BASIS';
        } elseif ($s['pending_work'] >= $p['max_pending_work']
            || $s['reviews_queued_today'] >= $p['maximum_reviews_per_utc_day']) {
            $status='DEFERRED_BACKPRESSURE';$reason='BACKLOG_OR_DAILY_WORK_BUDGET';
        } elseif ($s['pending_work']>0 && $wouldBeDue) {
            $status='DEFERRED_COALESCE_REVIEW';$reason='EXISTING_PENDING_REVIEW';
        } elseif ($s['last_queued_at_utc']!==null
            && strtotime($now)-strtotime($s['last_queued_at_utc'])<$p['cooldown_seconds']) {
            $status='DEFERRED_COOLDOWN';$reason='COOLDOWN_WINDOW';
        } elseif ($wouldBeDue) {
            // Reuse the established candidate family-290 disposition.
            // The extra QUIET_REVIEW_CLOCK reason is inside the hashed row;
            // no old SQL check constraint or v1 digest is changed.
            $status='DUE_CANDIDATE_HUMAN_REVIEW_ONLY';
        } elseif($same) {
            $status='NO_NEW_BASIS_CANDIDATE';
        }
        $record=[
            'version'=>self::VERSION,
            'status'=>$status,'reason'=>$reason,
            'policy_digest'=>$p['policy_digest'],
            'basis_digest'=>$basis['basis_digest'],
            'basis_knowledge_cutoff_utc'=>$basis['knowledge_cutoff_utc'],
            'at_utc'=>$now,
            'scope'=>$basis['scope'],
            'same_as_last_considered'=>$same,
            'review_clock_due'=>$clockDue,
            'new_basis_since_consideration'=>$changed,
            'max_wait_reached'=>$maxWait,
            'watermarks'=>[
                'observed'=>$s['latest_observed_at_utc'],
                'available'=>$s['latest_available_at_utc'],
                'queued'=>$s['last_queued_at_utc'],
                'analysed'=>$s['last_analysed_at_utc'],
                'human_reviewed'=>$s['last_human_reviewed_at_utc'],
                'considered'=>$s['last_considered_at_utc'],
                'considered_digest'=>$s['last_considered_basis_digest'],
            ],
            'pending_work'=>$s['pending_work'],
            'reviews_queued_today'=>$s['reviews_queued_today'],
            'verified_real_world_event_count'=>null,
            'verified_contradiction_count'=>null,
            'source_identity_owner_verified'=>false,
            'review_occurrence_reserved'=>false,
            'U05_policy_adopted'=>false,
            'U03_historical_reuse_admitted'=>false,
            'new_source_fetch_authorized'=>false,
            'model_call_authorized'=>false,
            'scheduler_action_authorized'=>false,
            'notification_authorized'=>false,
            'customer_output_authorized'=>false,
        ];
        $record['candidate_digest']=Digest::canonicalDigest($record);
        return Outcome::ok($record);
    }

    /** Record hash consistency; not a current owner, source, or policy signature. */
    public static function verify(array $value): Outcome
    {
        if(!Vocabulary::exactKeys($value,[
            'version','status','reason','policy_digest','basis_digest',
            'basis_knowledge_cutoff_utc','at_utc','scope',
            'same_as_last_considered','review_clock_due',
            'new_basis_since_consideration','max_wait_reached',
            'watermarks','pending_work','reviews_queued_today',
            'verified_real_world_event_count','verified_contradiction_count',
            'source_identity_owner_verified','review_occurrence_reserved',
            'U05_policy_adopted','U03_historical_reuse_admitted',
            'new_source_fetch_authorized','model_call_authorized',
            'scheduler_action_authorized','notification_authorized',
            'customer_output_authorized','candidate_digest',
        ])
            || !Vocabulary::scope($value['scope']??null)
            || !is_array($value['watermarks']??null)
            || !Vocabulary::exactKeys($value['watermarks'],[
                'observed','available','queued','analysed',
                'human_reviewed','considered','considered_digest',
            ])
            || $value['verified_real_world_event_count']!==null
            || $value['verified_contradiction_count']!==null
            || !is_string($value['basis_digest']??null)
            || preg_match('/^[a-f0-9]{64}$/D',$value['basis_digest'])!==1
            || !is_string($value['policy_digest']??null)
            || preg_match('/^[a-f0-9]{64}$/D',$value['policy_digest'])!==1
            || !Vocabulary::utc($value['at_utc']??null)
            || !Vocabulary::utc($value['basis_knowledge_cutoff_utc']??null)
            || $value['basis_knowledge_cutoff_utc']>$value['at_utc']
            || ($value['version']??null)!==self::VERSION
            || !is_string($value['candidate_digest']??null)
            || preg_match('/^[a-f0-9]{64}$/D',$value['candidate_digest'])!==1
            || !in_array($value['status']??null,[
                'DUE_CANDIDATE_HUMAN_REVIEW_ONLY','NO_NEW_BASIS_CANDIDATE',
                'NOT_DUE_CANDIDATE','HOLD_CURRENT_RIGHTS',
                'DEFERRED_BACKPRESSURE','DEFERRED_COALESCE_REVIEW',
                'DEFERRED_COOLDOWN',
            ],true)) {
            return Outcome::refuse('DU01_DUE_RESULT_INVALID');
        }
        foreach(['U05_policy_adopted','U03_historical_reuse_admitted',
            'new_source_fetch_authorized','model_call_authorized',
            'scheduler_action_authorized','notification_authorized',
            'customer_output_authorized','review_occurrence_reserved',
            'source_identity_owner_verified'] as $field){
            if(($value[$field]??null)!==false)return Outcome::refuse('DU01_DUE_AUTHORITY_FORGERY');
        }
        try{
            $digest=$value['candidate_digest'];unset($value['candidate_digest']);
            if(!hash_equals($digest,Digest::canonicalDigest($value))){
                return Outcome::refuse('DU01_DUE_DIGEST_MISMATCH');
            }
        }catch(\Throwable) {return Outcome::refuse('DU01_DUE_RESULT_INVALID');}
        return Outcome::ok(['integrity_only'=>true,'model_call_authorized'=>false]);
    }
}
