<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    HistoryReviewPolicy,HistoryReviewDue,HistoryReviewQueue,
    PointInTimeHistoricalBasis,HistoryDependencyIndex,HistoryInvalidation
};
use Allfather\Loopia\Jobs\{HistoryReviewDueHandler,TemporalRollupHandler};

$v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$basis=PointInTimeHistoricalBasis::freeze([
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>[
        'scope'=>$v['scope'],'period'=>$v['period'],
        'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
        'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,
        'coverage'=>[
            'state'=>'KNOWN','plan_version_id'=>'aq01.fixture',
            'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
            'failed'=>0,'skipped'=>0,'unreported'=>0,
        ],
    ],
    'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-15T00:00:00Z',
]);
Assert::true($basis->isOk(),'synthetic HS01 basis with fixed known cutoff');
if(!$basis->isOk())return;
$policy=HistoryReviewPolicy::freeze([
    'version'=>HistoryReviewPolicy::VERSION,
    'scope'=>$v['scope'],
    'monitored_source_refs'=>['source_a','source_b','source_late'],
    'cadence_seconds'=>86400,
    'maximum_wait_seconds'=>604800,
    'cooldown_seconds'=>3600,
    'max_pending_work'=>3,
    'max_basis_references'=>20,
    'unique_event_threshold'=>null,
    'maximum_reviews_per_utc_day'=>2,
    'calendar'=>'UTC_SECONDS_V1',
    'supported_triggers'=>[
        'new_evidence'=>true,'review_clock'=>true,
        'verified_event_count'=>false,'contradiction'=>false,
    ],
]);
Assert::true($policy->isOk(),'U05 proposed policy is scoped and frozen');
if(!$policy->isOk())return;
$clock='2026-03-16T00:00:00Z';
$due=HistoryReviewDue::assess([
    'policy'=>$policy->value(),'basis'=>$basis->value(),
    'state'=>[
        'latest_observed_at_utc'=>'2026-03-01T00:00:00Z',
        'latest_available_at_utc'=>'2026-03-15T00:00:00Z',
        'last_queued_at_utc'=>null,'last_analysed_at_utc'=>null,
        'last_human_reviewed_at_utc'=>'2026-03-14T00:00:00Z',
        'last_considered_at_utc'=>'2026-03-14T00:00:00Z',
        'last_considered_basis_digest'=>$basis->value()['basis_digest'],
        'pending_work'=>0,'reviews_queued_today'=>0,
        'current_source_posture'=>'OWNER_METADATA_READ_TEST_ONLY',
        'independent_event_count'=>null,'verified_contradiction_count'=>null,
    ],
    'at_utc'=>$clock,
]);
Assert::true($due->isOk(),'DU01 quiet due is synthetically reproducible');
if(!$due->isOk())return;
Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$due->value()['status'],
    'unchanged basis may trigger clock-based HUMAN review candidate');
$empty=[
    'pending_reviews'=>0,'pending_member_ids'=>[],
    'oldest_pending_at_utc'=>null,
    'last_human_reviewed_at_utc'=>'2026-03-14T00:00:00Z',
];
$members=[[
    'member_id'=>'quiet_clock',
    'member_kind'=>'QUIET_REVIEW_CLOCK',
    'reference_digest'=>null,
    'known_at_utc'=>$clock,'recorded_at_utc'=>$clock,
],[
    'member_id'=>'capture_1',
    'member_kind'=>'OBSERVATION',
    'reference_digest'=>str_repeat('a',64),
    'known_at_utc'=>'2026-03-15T00:00:00Z',
    'recorded_at_utc'=>'2026-03-01T00:00:00Z',
]];
$input=[
    'policy'=>$policy->value(),'due'=>$due->value(),
    'graph'=>null,'members'=>$members,
    'at_utc'=>$clock,'queue_state'=>$empty,
];
$one=HistoryReviewQueue::freeze($input);
Assert::true($one->isOk(),'pure queue candidate built with quiet clock and one observation');
if(!$one->isOk())return;
$q=$one->value();
Assert::same('HUMAN_REVIEW_CANDIDATE_ONLY',$q['status'],
    'QUIET_CLOCK is not invalid late-known source evidence');
Assert::same(2,$q['member_count'],
    'both clock proposal and original observation remain represented');
Assert::same([],$q['critical_member_ids'],'no invented invalidation event');
Assert::same([],$q['outside_frozen_cutoff_member_ids'],
    'clock is not new evidence after the frozen knowledge cutoff');
Assert::same(null,$q['independent_event_count'],
    'capture repetition cannot become real-world independent event count');
Assert::same(false,$q['review_work_reserved'],
    'candidate does not call actual JobMachine enqueue or reserve');
Assert::true(HistoryReviewQueue::verify($q)->isOk(),'candidate digest verifies offline');
Assert::same($q['candidate_digest'],
    HistoryReviewQueue::freeze($input)->value()['candidate_digest'],
    'repeat exact tick and membership derives identical digest');
Assert::same('HUMAN_REVIEW_CANDIDATE_ONLY',
    HistoryReviewDueHandler::preview($input)->value()['status'],
    'handler composes real pure queue without native job registration');

$reverse=$input;$reverse['members']=array_reverse($input['members']);
Assert::same($q['candidate_digest'],
    HistoryReviewQueue::freeze($reverse)->value()['candidate_digest'],
    'priority-and-time ordering makes replay stable');
$coalesce=$input;
$coalesce['queue_state']=[
    'pending_reviews'=>1,
    'pending_member_ids'=>['capture_1'],
    'oldest_pending_at_utc'=>'2026-03-15T00:00:00Z',
    'last_human_reviewed_at_utc'=>'2026-03-14T00:00:00Z',
];
$r=HistoryReviewQueue::freeze($coalesce);
Assert::same('COALESCE_PENDING_HUMAN_CANDIDATE_ONLY',$r->value()['status'],
    'one queued review coalesces human attention without a new job');
Assert::same(['capture_1'],$r->value()['member_ids_reused_from_pending'],
    'existing member preserved exactly');
Assert::same(['quiet_clock'],$r->value()['member_ids_new'],
    'quiet-review candidate still present, not discarded');
$lost=$coalesce;
$lost['queue_state']['pending_member_ids']=['missing_pending'];
Assert::same('AQ01_PENDING_MEMBER_WOULD_BE_DROPPED',
    HistoryReviewQueue::freeze($lost)->reasonCode(),
    'coalescing must carry every previously queued member, not hide a source event');
$full=$coalesce;
$full['members'][]=[
    'member_id'=>'capture_2',
    'member_kind'=>'OBSERVATION',
    'reference_digest'=>str_repeat('d',64),
    'known_at_utc'=>'2026-03-15T00:00:00Z',
    'recorded_at_utc'=>'2026-03-05T00:00:00Z',
];
$full['queue_state']['pending_reviews']=3;
$full['queue_state']['pending_member_ids']=['capture_1','quiet_clock','capture_2'];
Assert::same('DEFERRED_BACKPRESSURE_HOLD',
    HistoryReviewQueue::freeze($full)->value()['status'],
    'U05-configured maximum pending review work is never silently bypassed');

$critical=$input;
$critical['members'][]=[
    'member_id'=>'invalidated_ref',
    'member_kind'=>'INVALIDATION',
    'reference_digest'=>str_repeat('b',64),
    'known_at_utc'=>'2026-03-16T00:00:00Z',
    'recorded_at_utc'=>'2026-03-16T00:00:00Z',
];
$r=HistoryReviewQueue::freeze($critical);
Assert::same('HOLD_CRITICAL_SOURCE_OR_INVALIDATION_REVIEW',$r->value()['status'],
    'source-rights invalidation outranks quiet-work disposition');
Assert::same('invalidated_ref',$r->value()['members'][0]['member_id'],
    'critical member first, not silently dropped by coalescing');
Assert::same(['invalidated_ref'],$r->value()['outside_frozen_cutoff_member_ids'],
    'invalidation after cutoff is retained but never used in frozen analysis');
Assert::same(3,$r->value()['member_count'],
    'critical escalation preserves all existing members');
$contrary=$input;$contrary['members'][]=[
    'member_id'=>'contrary_1',
    'member_kind'=>'CONTRARY_EVIDENCE',
    'reference_digest'=>str_repeat('c',64),
    'known_at_utc'=>'2026-03-15T00:00:00Z',
    'recorded_at_utc'=>'2026-03-15T00:00:00Z',
];
Assert::same('HOLD_CRITICAL_SOURCE_OR_INVALIDATION_REVIEW',
    HistoryReviewQueue::freeze($contrary)->value()['status'],
    'contrary evidence cannot be buried by a quiet event');
$duplicate=$input;$duplicate['members'][]=$members[0];
Assert::same('AQ01_MEMBER_ID_OR_CLOCK_INVALID',
    HistoryReviewQueue::freeze($duplicate)->reasonCode(),
    'duplicate member ID cannot create independent corroboration');
$bad=$q;$bad['scheduler_handler_registered']=true;
$unsigned=$bad;unset($unsigned['candidate_digest']);
$bad['candidate_digest']=Digest::canonicalDigest($unsigned);
Assert::same('AQ01_QUEUE_FORGED_GRANT',
    HistoryReviewQueue::verify($bad)->reasonCode(),
    'self-hashed flag cannot register a runtime handler');
$bad=$input;$bad['queue_state']['pending_reviews']=1;
Assert::same('AQ01_PENDING_WATERMARK_INCONSISTENT',
    HistoryReviewQueue::freeze($bad)->reasonCode(),
    'pending count without exact identities/clock refuses');
$foreign=$input;$foreign['due']['scope']['tenant_id']='different_tenant';
Assert::same('AQ01_QUEUE_CONTRACT_INVALID',
    HistoryReviewQueue::freeze($foreign)->reasonCode(),
    'tampered due scope loses its original hash validity');
Assert::same('AQ01_U05_U08_REVIEW_DUE_SCHEDULER_NOT_ADOPTED',
    HistoryReviewDueHandler::activate(['caller_says_yes'=>true])->reasonCode(),
    'caller cannot register U05/U08 handler');
Assert::same('AQ01_U04_U05_U08_TEMPORAL_JOB_NOT_ADOPTED',
    TemporalRollupHandler::activate(['owner'=>'APPROVED_BY_CALLER'])->reasonCode(),
    'rollup handler cannot register a new worker or model');
