<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\{Digest,Outcome};

/**
 * LI-08 / U05 CANDIDATE: cheap deterministic review-due inspection.
 *
 * No current U05 owner authority is adopted. This is a pure calculation over
 * caller-supplied, explicitly untrusted metadata: it cannot fetch a source,
 * allocate a job, reserve budget, summon a model, mutate a queue or notify anyone.
 * A DUE verdict means "candidate for separately admitted human attention".
 */
final class ReviewDue
{
    public const VERSION = 'li08.review-due-candidate.v1';
    private const POLICY_KEYS = [
        'version', 'cadence_seconds', 'maximum_wait_seconds',
        'cooldown_seconds', 'max_pending_work',
    ];
    private const STATE_KEYS = [
        'current_basis_digest', 'latest_basis_known_at_utc',
        'last_considered_basis_digest', 'last_considered_at_utc',
        'last_queued_at_utc', 'pending_work', 'source_eligibility',
    ];

    /** @param array<string,mixed> $input */
    public static function assess(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, ['scope','policy','state','at_utc'])
            || !Vocabulary::scope($input['scope'])
            || !Vocabulary::utc($input['at_utc'])
            || !is_array($input['policy'])
            || !Vocabulary::exactKeys($input['policy'], self::POLICY_KEYS)
            || !is_array($input['state'])
            || !Vocabulary::exactKeys($input['state'], self::STATE_KEYS)) {
            return Outcome::refuse('M17_REVIEW_DUE_INPUT_INVALID');
        }
        $policy = $input['policy'];
        $state = $input['state'];
        if (($policy['version'] ?? null) !== self::VERSION) {
            return Outcome::refuse('M17_REVIEW_DUE_POLICY_UNADOPTED');
        }
        foreach ([
            'cadence_seconds'=>[1, 90 * 86400],
            'maximum_wait_seconds'=>[1, 365 * 86400],
            'cooldown_seconds'=>[0, 90 * 86400],
            'max_pending_work'=>[1, 100],
        ] as $field => [$lower, $upper]) {
            if (!is_int($policy[$field]) || $policy[$field] < $lower
                || $policy[$field] > $upper) {
                return Outcome::refuse('M17_REVIEW_DUE_POLICY_INVALID');
            }
        }
        if ($policy['maximum_wait_seconds'] < $policy['cadence_seconds']) {
            return Outcome::refuse('M17_REVIEW_DUE_POLICY_INVALID');
        }
        foreach (['current_basis_digest','last_considered_basis_digest'] as $key) {
            $value=$state[$key];
            if ($value === null && $key === 'last_considered_basis_digest') continue;
            if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D',$value)!==1) {
                return Outcome::refuse('M17_REVIEW_DUE_BASIS_INVALID');
            }
        }
        if (!Vocabulary::utc($state['latest_basis_known_at_utc'])
            || !is_int($state['pending_work'])
            || $state['pending_work'] < 0 || $state['pending_work'] > 100000
            || !in_array($state['source_eligibility'], [
                'NOT_ASSESSED','CURRENT_RIGHTS_BLOCKED','OWNER_READ_METADATA_ONLY',
            ], true)) {
            return Outcome::refuse('M17_REVIEW_DUE_STATE_INVALID');
        }
        foreach (['last_considered_at_utc','last_queued_at_utc'] as $key) {
            if ($state[$key] !== null && !Vocabulary::utc($state[$key])) {
                return Outcome::refuse('M17_REVIEW_DUE_STATE_INVALID');
            }
        }
        if (($state['last_considered_at_utc'] === null)
            !== ($state['last_considered_basis_digest'] === null)) {
            return Outcome::refuse('M17_REVIEW_DUE_WATERMARK_INCONSISTENT');
        }
        $now = strtotime($input['at_utc']);
        $known = strtotime($state['latest_basis_known_at_utc']);
        if ($known > $now) return Outcome::refuse('M17_REVIEW_DUE_FUTURE_KNOWLEDGE');
        foreach (['last_considered_at_utc','last_queued_at_utc'] as $key) {
            if ($state[$key] !== null && strtotime($state[$key]) > $now) {
                return Outcome::refuse('M17_REVIEW_DUE_FUTURE_WATERMARK');
            }
        }
        $unchanged = $state['last_considered_basis_digest'] !== null
            && hash_equals($state['last_considered_basis_digest'],$state['current_basis_digest']);
        $elapsed = $state['last_considered_at_utc'] === null
            ? null : ($now - strtotime($state['last_considered_at_utc']));
        $age = $now - $known;
        $due = !$unchanged && (
            $elapsed === null
            || $elapsed >= $policy['cadence_seconds']
            || $age >= $policy['maximum_wait_seconds']
        );
        $reason = $elapsed === null ? 'FIRST_CONSIDERATION'
            : ($elapsed >= $policy['maximum_wait_seconds'] ? 'MAXIMUM_WAIT'
                : ($elapsed >= $policy['cadence_seconds'] ? 'CADENCE' : 'NOT_YET_DUE'));

        $status = 'NOT_DUE_CANDIDATE';
        if ($unchanged) {
            $status='NO_NEW_BASIS_CANDIDATE'; $reason='ALREADY_CONSIDERED_EXACT_BASIS';
        } elseif ($state['source_eligibility']==='CURRENT_RIGHTS_BLOCKED') {
            $status='HOLD_CURRENT_RIGHTS'; $reason='CURRENT_RIGHTS_BLOCKED';
        } elseif ($state['pending_work'] >= $policy['max_pending_work']) {
            $status='DEFERRED_BACKPRESSURE'; $reason='PENDING_CAP_REACHED';
        } elseif ($state['pending_work'] > 0 && $due) {
            $status='DEFERRED_COALESCE_REVIEW'; $reason='PENDING_WORK_HAS_PRECEDENCE';
        } elseif ($state['last_queued_at_utc'] !== null
            && $now-strtotime($state['last_queued_at_utc']) < $policy['cooldown_seconds']) {
            $status='DEFERRED_COOLDOWN'; $reason='COOLDOWN';
        } elseif ($due) {
            $status='DUE_CANDIDATE_HUMAN_REVIEW_ONLY';
        }
        // The result may support a future separately ratified policy; no field is
        // an access token, a job reservation or authority for downstream action.
        $result = [
            'version'=>self::VERSION, 'scope'=>$input['scope'],
            'status'=>$status, 'reason'=>$reason, 'at_utc'=>$input['at_utc'],
            'current_basis_digest'=>$state['current_basis_digest'],
            'last_considered_basis_digest'=>$state['last_considered_basis_digest'],
            'source_eligibility'=>$state['source_eligibility'],
            'pending_work'=>$state['pending_work'],
            'calendar_decision_not_inferred'=>true,
            'U05_policy_adopted'=>false, 'U03_historical_reuse_admitted'=>false,
            'new_source_fetch_authorized'=>false, 'model_call_authorized'=>false,
            'scheduler_action_authorized'=>false, 'notification_authorized'=>false,
            'M10_release_authorized'=>false, 'customer_output_authorized'=>false,
        ];
        return Outcome::ok([...$result,'candidate_digest'=>Digest::canonicalDigest($result)]);
    }
}
