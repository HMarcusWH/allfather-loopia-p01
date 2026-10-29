<?php

declare(strict_types=1);

namespace Allfather\Loopia\Jobs;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Workflow\HistoryReviewQueue;

/**
 * AQ-01: pure human review-work planning. NOT a JobMachine job type.
 *
 * The live scheduler's MONITOR_STEP and three-unread backpressure stay
 * unchanged. U05/U08 must ratify this handler before any runtime registration.
 */
final class HistoryReviewDueHandler
{
    public const VERSION='aq01.inert-due-handler.v1';

    /** @param array<string,mixed> $input */
    public static function preview(array $input):Outcome
    {
        $queue=HistoryReviewQueue::freeze($input);
        if(!$queue->isOk())return $queue;
        $value=$queue->value();
        return Outcome::ok([
            'version'=>self::VERSION,
            'status'=>$value['status'],
            'work_candidate_digest'=>$value['candidate_digest'],
            'member_count'=>$value['member_count'],
            'new_member_count'=>count($value['member_ids_new']),
            'critical_member_count'=>count($value['critical_member_ids']),
            'basis_digest'=>$value['basis_digest'],
            'policy_digest'=>$value['policy_digest'],
            'scheduler_job_created'=>false,
            'job_lease_acquired'=>false,
            'model_attempt_reserved'=>false,
            'human_acceptance_recorded'=>false,
            'U05_U08_runtime_admitted'=>false,
            'customer_notification_sent'=>false,
        ]);
    }

    public static function activate(array $decision=[]):Outcome
    {
        return Outcome::refuse('AQ01_U05_U08_REVIEW_DUE_SCHEDULER_NOT_ADOPTED');
    }
}
