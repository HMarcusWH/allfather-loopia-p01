<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\ReviewDue;
use Allfather\Loopia\Tests\Assert;

$scope=[
    'tenant_id'=>'tenant-a','workspace_id'=>'workspace-a',
    'competitor_id'=>'competitor-a','question_id'=>'question-a',
];
$policy=[
    'version'=>ReviewDue::VERSION,
    'cadence_seconds'=>86400,
    'maximum_wait_seconds'=>7*86400,
    'cooldown_seconds'=>3600,
    'max_pending_work'=>3,
];
$state=[
    'current_basis_digest'=>str_repeat('b',64),
    'latest_basis_known_at_utc'=>'2026-10-20T18:00:00Z',
    'last_considered_basis_digest'=>str_repeat('a',64),
    'last_considered_at_utc'=>'2026-10-18T18:00:00Z',
    'last_queued_at_utc'=>null,
    'pending_work'=>0,
    'source_eligibility'=>'NOT_ASSESSED',
];
$input=['scope'=>$scope,'policy'=>$policy,'state'=>$state,'at_utc'=>'2026-10-26T18:00:00Z'];
$get=static function(array $value):array {
    $x=ReviewDue::assess($value);
    Assert::true($x->isOk(),'synthetic review-due candidate is a deterministic calculation');
    return $x->value();
};
$first=$get($input);
Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$first['status'],'changed basis + cadence proposes human review');
foreach (['model_call_authorized','new_source_fetch_authorized','notification_authorized',
          'U03_historical_reuse_admitted','U05_policy_adopted','scheduler_action_authorized',
          'customer_output_authorized','M10_release_authorized'] as $key) {
    Assert::same(false,$first[$key],'review-due proposal never grants '.$key);
}
Assert::same($first['candidate_digest'],$get($input)['candidate_digest'],'same inputs produce same due digest');
$alter=$input;
$alter['state']['last_considered_basis_digest']=str_repeat('b',64);
Assert::same('NO_NEW_BASIS_CANDIDATE',$get($alter)['status'],'duplicate review basis cannot become a new analysis');
$alter=$input;
$alter['state']['pending_work']=3;
Assert::same('DEFERRED_BACKPRESSURE',$get($alter)['status'],'pending cap prevents silently accumulating work');
$alter=$input;
$alter['state']['pending_work']=1;
Assert::same('DEFERRED_COALESCE_REVIEW',$get($alter)['status'],'compatible pending work requests coalescing not a second job');
$alter=$input;
$alter['state']['source_eligibility']='CURRENT_RIGHTS_BLOCKED';
Assert::same('HOLD_CURRENT_RIGHTS',$get($alter)['status'],'revoked source cannot become a reason to spend');
$alter=$input;
$alter['state']['last_queued_at_utc']='2026-10-26T17:30:00Z';
Assert::same('DEFERRED_COOLDOWN',$get($alter)['status'],'cooldown suppresses duplicate attention');
$alter=$input;
$alter['state']['last_considered_at_utc']='2026-10-26T17:30:00Z';
Assert::same('NOT_DUE_CANDIDATE',$get($alter)['status'],'fresh consideration respects cadence');
$alter=$input;
$alter['state']['last_considered_at_utc']=null;
$alter['state']['last_considered_basis_digest']=null;
Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$get($alter)['status'],'first observed basis proposes initial review');
$alter=$input;
$alter['state']['latest_basis_known_at_utc']='2026-10-28T18:00:00Z';
Assert::same('M17_REVIEW_DUE_FUTURE_KNOWLEDGE',ReviewDue::assess($alter)->reasonCode(),
    'future knowledge never enters a historical due decision');
$alter=$input;
$alter['state']['last_considered_at_utc']=null;
Assert::same('M17_REVIEW_DUE_WATERMARK_INCONSISTENT',ReviewDue::assess($alter)->reasonCode(),
    'watermark time and digest cannot be independently dropped');
$alter=$input;
$alter['policy']['version']='unapproved';
Assert::same('M17_REVIEW_DUE_POLICY_UNADOPTED',ReviewDue::assess($alter)->reasonCode(),
    'unknown due policy cannot be silently executed');
$alter=$input;
$alter['policy']['max_pending_work']=0;
Assert::same('M17_REVIEW_DUE_POLICY_INVALID',ReviewDue::assess($alter)->reasonCode(),
    'zero backlog cap does not silently starve work');
$alter=$input;
$alter['state']['source_eligibility']='APPROVED_BY_CALLER';
Assert::same('M17_REVIEW_DUE_STATE_INVALID',ReviewDue::assess($alter)->reasonCode(),
    'caller cannot construct an adopted source-permission token');
$alter=$input;
$alter['scope']['tenant_id']='';
Assert::same('M17_REVIEW_DUE_INPUT_INVALID',ReviewDue::assess($alter)->reasonCode(),
    'missing tenant scope refused');
$alter=$input;
$alter['ignore_limits']=true;
Assert::same('M17_REVIEW_DUE_INPUT_INVALID',ReviewDue::assess($alter)->reasonCode(),
    'unexpected control keys cannot alter decision');
$alter=$input;
$alter['state']['current_basis_digest']=str_repeat('g',64);
Assert::same('M17_REVIEW_DUE_BASIS_INVALID',ReviewDue::assess($alter)->reasonCode(),
    'malformed digest refuses');
