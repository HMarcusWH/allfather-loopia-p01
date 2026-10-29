<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{
    HistoryReviewPolicy,HistoryReviewDue,PointInTimeHistoricalBasis
};

$v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$input=[
    'scope'=>$v['scope'],'period'=>$v['period'],
    'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
    'rows'=>$v['rows'],'max_references'=>8,
    'coverage'=>[
        'state'=>'KNOWN','plan_version_id'=>'fixture.du01',
        'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
        'failed'=>0,'skipped'=>0,'unreported'=>0,
    ],
];
$b=PointInTimeHistoricalBasis::freeze([
    'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
    'legacy_input'=>$input,'previous_basis_digest'=>null,
    'review_at_utc'=>'2026-03-15T00:00:00Z',
]);
Assert::true($b->isOk(),'HS-01 source candidate frozen for due policy');
if(!$b->isOk())return;
Assert::same('COMPLETE_WITHIN_INPUT',$b->value()['selection_integrity'],
    'fixture has explicit complete-within-input metadata; not owner admission');
$raw=[
    'version'=>HistoryReviewPolicy::VERSION,'scope'=>$v['scope'],
    'monitored_source_refs'=>['source_b','source_a','source_late'],
    'cadence_seconds'=>86400,'maximum_wait_seconds'=>7*86400,
    'cooldown_seconds'=>3600,'max_pending_work'=>3,
    'max_basis_references'=>20,'unique_event_threshold'=>null,
    'maximum_reviews_per_utc_day'=>2,'calendar'=>'UTC_SECONDS_V1',
    'supported_triggers'=>[
        'new_evidence'=>true,'review_clock'=>true,
        'verified_event_count'=>false,'contradiction'=>false,
    ],
];
$p=HistoryReviewPolicy::freeze($raw);
Assert::true($p->isOk(),'typed review policy v2 built as source-only candidate');
if(!$p->isOk())return;
$policy=$p->value();
Assert::same(['source_a','source_b','source_late'],$policy['monitored_source_refs'],
    'source scope canonically ordered');
Assert::true(HistoryReviewPolicy::verify($policy)->isOk(),
    'policy verifies and replays exact allowed offline inputs');
Assert::same(false,$policy['owner_policy_adopted'],'U05 not ratified by hash');
$reorder=$raw;$reorder['monitored_source_refs']=array_reverse($raw['monitored_source_refs']);
Assert::same($policy['policy_digest'],
    HistoryReviewPolicy::freeze($reorder)->value()['policy_digest'],
    'source list ordering cannot change policy digest');
$s=[
    'latest_observed_at_utc'=>'2026-03-01T00:00:00Z',
    'latest_available_at_utc'=>'2026-03-15T00:00:00Z',
    'last_queued_at_utc'=>null,'last_analysed_at_utc'=>null,
    'last_human_reviewed_at_utc'=>'2026-03-14T00:00:00Z',
    'last_considered_at_utc'=>'2026-03-14T00:00:00Z',
    'last_considered_basis_digest'=>$b->value()['basis_digest'],
    'pending_work'=>0,'reviews_queued_today'=>0,
    'current_source_posture'=>'OWNER_METADATA_READ_TEST_ONLY',
    'independent_event_count'=>null,'verified_contradiction_count'=>null,
];
$request=['policy'=>$policy,'basis'=>$b->value(),'state'=>$s,'at_utc'=>'2026-03-16T00:00:00Z'];
$out=HistoryReviewDue::assess($request);
Assert::true($out->isOk(),'quiet-stream review due supports a deterministic clock tick');
if(!$out->isOk())return;
$d=$out->value();
Assert::same('DUE_CANDIDATE_HUMAN_REVIEW_ONLY',$d['status'],
    'QUIET: unchanged frozen basis still becomes due at cadence');
Assert::same('QUIET_REVIEW_CLOCK',$d['reason'],
    'reason distinguishes time-only review from a detected business event');
Assert::same(true,$d['same_as_last_considered'],'no false new business change');
Assert::same(false,$d['new_basis_since_consideration'],'unchanged source basis is recorded');
Assert::same('2026-03-15T00:00:00Z',$d['basis_knowledge_cutoff_utc'],
    'review clock cannot silently replace frozen knowledge cutoff');
Assert::same(false,$d['model_call_authorized'],'due candidate never grants funded provider spend');
Assert::true(HistoryReviewDue::verify($d)->isOk(),'due self-hash valid but no U05 grants');
$again=HistoryReviewDue::assess($request);
Assert::same($d['candidate_digest'],$again->value()['candidate_digest'],
    'same clock and watermarks produce bit-identical due recommendation');
$soon=$request;$soon['at_utc']='2026-03-14T12:00:00Z';
Assert::same('DU01_DUE_SCOPE_OR_CUTOFF_INVALID',
    HistoryReviewDue::assess($soon)->reasonCode(),
    'review cannot run before knowledge cutoff');
$soon=$request;$soon['state']['last_human_reviewed_at_utc']='2026-03-15T20:00:00Z';
Assert::same('NO_NEW_BASIS_CANDIDATE',HistoryReviewDue::assess($soon)->value()['status'],
    'unchanged basis is not due until own clock reaches cadence');
$blocked=$request;$blocked['state']['current_source_posture']='UNKNOWN';
Assert::same('HOLD_CURRENT_RIGHTS',HistoryReviewDue::assess($blocked)->value()['status'],
    'unknown current owner rights are HOLD, not model permission');
$blocked['state']['current_source_posture']='CURRENT_RIGHTS_BLOCKED';
Assert::same('HOLD_CURRENT_RIGHTS',HistoryReviewDue::assess($blocked)->value()['status'],
    'revocation is HOLD even when clock says due');
$pending=$request;$pending['state']['pending_work']=3;
Assert::same('DEFERRED_BACKPRESSURE',HistoryReviewDue::assess($pending)->value()['status'],
    'bounded human queue does not multiply pending review jobs');
$pending=$request;$pending['state']['pending_work']=1;
Assert::same('DEFERRED_COALESCE_REVIEW',HistoryReviewDue::assess($pending)->value()['status'],
    'pending event coalesces without erasing evidence');
$cool=$request;$cool['state']['last_queued_at_utc']='2026-03-15T23:40:00Z';
Assert::same('DEFERRED_COOLDOWN',HistoryReviewDue::assess($cool)->value()['status'],
    'cooldown suppresses duplicate attention');
$daily=$request;$daily['state']['reviews_queued_today']=2;
Assert::same('DEFERRED_BACKPRESSURE',HistoryReviewDue::assess($daily)->value()['status'],
    'daily work budget is a limit not a new authority token');
$future=$request;$future['state']['latest_available_at_utc']='2026-03-20T00:00:00Z';
Assert::same('DU01_DUE_WATERMARK_INVALID',HistoryReviewDue::assess($future)->reasonCode(),
    'knowledge newer than frozen cutoff cannot silently join review');
$policyBad=$raw;$policyBad['supported_triggers']['contradiction']=true;
Assert::same('DU01_UNRATIFIED_SEMANTIC_TRIGGER',HistoryReviewPolicy::freeze($policyBad)->reasonCode(),
    'unverified contradiction trigger must stay disabled');
$policyBad=$raw;$policyBad['unique_event_threshold']=10;
Assert::same('DU01_UNRATIFIED_SEMANTIC_TRIGGER',HistoryReviewPolicy::freeze($policyBad)->reasonCode(),
    'unverified real-world unique-event threshold cannot trigger on raw captures');
$policyBad=$raw;$policyBad['cadence_seconds']=0;
Assert::same('DU01_POLICY_LIMIT_INVALID',HistoryReviewPolicy::freeze($policyBad)->reasonCode(),
    'unbounded zero-cadence scheduling refused');
$policyBad=$raw;$policyBad['supported_triggers']=null;
Assert::same('DU01_POLICY_CONTRACT_INVALID',HistoryReviewPolicy::freeze($policyBad)->reasonCode(),
    'malformed trigger type refuses without PHP TypeError');
$wrong=$request;$wrong['state']['independent_event_count']=5;
Assert::same('DU01_DUE_STATE_OR_ORIGIN_UNKNOWN',HistoryReviewDue::assess($wrong)->reasonCode(),
    'caller cannot claim independent events are certified');
$wrong=$request;$wrong['state']['unknown_grant']=true;
Assert::same('DU01_DUE_CONTRACT_INVALID',HistoryReviewDue::assess($wrong)->reasonCode(),
    'unexpected field cannot create permission');
$wrong=$d;$wrong['model_call_authorized']=true;$copy=$wrong;
unset($copy['candidate_digest']);$wrong['candidate_digest']=Digest::canonicalDigest($copy);
Assert::same('DU01_DUE_AUTHORITY_FORGERY',HistoryReviewDue::verify($wrong)->reasonCode(),
    'rehashed model grant is not an owner admission');
