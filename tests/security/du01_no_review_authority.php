<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    HistoryReviewPolicy,HistoryReviewDue,PointInTimeHistoricalBasis
};

$fixture=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$input=[
    'scope'=>$fixture['scope'],'period'=>$fixture['period'],
    'baseline_period'=>$fixture['baseline_period'],'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$fixture['knowledge_cutoff_utc'],'rows'=>$fixture['rows'],
    'max_references'=>8,
    'coverage'=>['state'=>'KNOWN','plan_version_id'=>'du01-test',
        'planned_slots'=>2,'captured'=>2,'unchanged'=>0,
        'failed'=>0,'skipped'=>0,'unreported'=>0],
];
$basis=PointInTimeHistoricalBasis::freeze([
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$input,'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-15T00:00:00Z',
])->value();
$policy=HistoryReviewPolicy::freeze([
    'version'=>HistoryReviewPolicy::VERSION,'scope'=>$fixture['scope'],
    'monitored_source_refs'=>['source_a','source_b'],
    'cadence_seconds'=>86400,'maximum_wait_seconds'=>7*86400,
    'cooldown_seconds'=>60,'max_pending_work'=>3,
    'max_basis_references'=>20,'unique_event_threshold'=>null,
    'maximum_reviews_per_utc_day'=>3,'calendar'=>'UTC_SECONDS_V1',
    'supported_triggers'=>[
        'new_evidence'=>true,'review_clock'=>true,
        'verified_event_count'=>false,'contradiction'=>false,
    ],
])->value();
$state=[
    'latest_observed_at_utc'=>'2026-03-01T00:00:00Z',
    'latest_available_at_utc'=>'2026-03-15T00:00:00Z',
    'last_queued_at_utc'=>null,'last_analysed_at_utc'=>null,
    'last_human_reviewed_at_utc'=>null,'last_considered_at_utc'=>null,
    'last_considered_basis_digest'=>null,
    'pending_work'=>0,'reviews_queued_today'=>0,
    'current_source_posture'=>'UNKNOWN',
    'independent_event_count'=>null,'verified_contradiction_count'=>null,
];
$request=['policy'=>$policy,'basis'=>$basis,'state'=>$state,
    'at_utc'=>'2026-03-16T00:00:00Z'];
$result=HistoryReviewDue::assess($request);
Assert::same('HOLD_CURRENT_RIGHTS',$result->value()['status'],
    'unverified source right is a HOLD even for first scheduled review');
foreach(['model_call_authorized','new_source_fetch_authorized',
          'scheduler_action_authorized','notification_authorized',
          'customer_output_authorized','U03_historical_reuse_admitted',
          'U05_policy_adopted','review_occurrence_reserved'] as $flag){
    Assert::same(false,$result->value()[$flag],
        'review recommendation cannot grant '.$flag);
}
$spoof=$request;$spoof['state']['independent_event_count']=1000;
Assert::same('DU01_DUE_STATE_OR_ORIGIN_UNKNOWN',
    HistoryReviewDue::assess($spoof)->reasonCode(),
    '1000 alleged capture events cannot become 1000 real-world independent events');
$spoof=$request;$spoof['state']['verified_contradiction_count']=1;
Assert::same('DU01_DUE_STATE_OR_ORIGIN_UNKNOWN',
    HistoryReviewDue::assess($spoof)->reasonCode(),
    'caller claimed contradiction cannot bypass U02');
$spoof=$request;$spoof['policy']['scheduler_activation_authorized']=true;
$unsigned=$spoof['policy'];unset($unsigned['policy_digest']);
$spoof['policy']['policy_digest']=Digest::canonicalDigest($unsigned);
Assert::same('DU01_DUE_CONTRACT_INVALID',
    HistoryReviewDue::assess($spoof)->reasonCode(),
    'valid forged hash cannot mint source/recurrence authority');
$spoof=$request;$spoof['state']['current_source_posture']='ALLOW';
Assert::same('DU01_DUE_SCOPE_OR_CUTOFF_INVALID',
    HistoryReviewDue::assess($spoof)->reasonCode(),
    'caller-supplied ALLOW vocabulary is never accepted');
$spoof=$request;$spoof['state']['last_considered_at_utc']='2026-03-16T00:00:00Z';
Assert::same('DU01_DUE_WATERMARK_INVALID',
    HistoryReviewDue::assess($spoof)->reasonCode(),
    'watermark time cannot be separated from its exact considered digest');
$spoof=$request;$spoof['basis']['historical_reuse_admitted']=true;
Assert::same('DU01_DUE_CONTRACT_INVALID',
    HistoryReviewDue::assess($spoof)->reasonCode(),
    'source basis grant cannot be self asserted');
$spoof=$policy;$spoof['supported_triggers']['contradiction']=true;
$unsigned=$spoof;unset($unsigned['policy_digest']);
$spoof['policy_digest']=Digest::canonicalDigest($unsigned);
Assert::same('DU01_POLICY_REPLAY_MISMATCH',
    HistoryReviewPolicy::verify($spoof)->reasonCode(),
    'recomputed policy hash cannot approve contradiction escalation');
foreach(['app/Workflow/HistoryReviewPolicy.php',
         'app/Workflow/HistoryReviewDue.php'] as $path){
    $code=(string)file_get_contents(dirname(__DIR__,2).'/'.$path);
    foreach(['curl_exec(', 'new PDO(', 'file_get_contents(', 'ModelExecutor::',
             'releaseP01(', 'new SchedulerRunner(', 'sendMail('] as $effect){
        Assert::false(str_contains($code,$effect),
            'DU01 pure candidate code cannot source fetch/dispatch/notify');
    }
}
