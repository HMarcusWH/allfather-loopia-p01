<?php

declare(strict_types=1);

/** DU-01: no scheduler, model or native history-reader invocation. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Workflow\{
    HistoryReviewPolicy,HistoryReviewDue,PointInTimeHistoricalBasis
};

$args=array_slice($argv,1);
if(count($args)!==1||!in_array($args[0],['--status','--help','--synthetic-development'],true)){
    fwrite(STDERR,"DU01_U05_DUE_OWNER_CONTEXT_REQUIRED\n");exit(3);
}
if($args[0]==='--status'||$args[0]==='--help'){
    echo json_encode([
        'version'=>HistoryReviewDue::VERSION,
        'state'=>'OFFLINE_HUMAN_DUE_CANDIDATE_ONLY',
        'owner_source_eligibility'=>'NOT_VERIFIED',
        'U05_policy_adopted'=>false,
        'model_call_authorized'=>false,
        'scheduler_action_authorized'=>false,
        'notification_authorized'=>false,
        'customer_release_authorized'=>false,
        'synthetic_requires'=>'ALLFATHER_DU01_SYNTHETIC_TEST=1',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit(0);
}
if(getenv('ALLFATHER_DU01_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"DU01_SYNTHETIC_TEST_MODE_REQUIRED\n");exit(3);
}
try{
    $path=dirname(__DIR__,2).'/tests/fixtures/li05_offline_basis_vectors.json';
    $v=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    $input=[
        'scope'=>$v['scope'],'period'=>$v['period'],
        'baseline_period'=>$v['baseline_period'],'baseline_null_reason'=>null,
        'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
        'rows'=>$v['rows'],'max_references'=>8,
        'coverage'=>['state'=>'KNOWN','plan_version_id'=>'du01.cli.fixture',
            'planned_slots'=>2,'captured'=>2,'unchanged'=>1,
            'failed'=>0,'skipped'=>0,'unreported'=>0],
    ];
    $b=PointInTimeHistoricalBasis::freeze([
        'calendar'=>PointInTimeHistoricalBasis::CALENDAR,
        'legacy_input'=>$input,'previous_basis_digest'=>null,
        'review_at_utc'=>'2026-03-15T00:00:00Z',
    ]);
    if(!$b->isOk())throw new \RuntimeException('DU01_FIXTURE_BASIS_REFUSED');
    $sources=array_values(array_unique(array_column($v['rows'],'source_ref_id')));
    $p=HistoryReviewPolicy::freeze([
        'version'=>HistoryReviewPolicy::VERSION,'scope'=>$v['scope'],
        'monitored_source_refs'=>$sources,
        'cadence_seconds'=>86400,'maximum_wait_seconds'=>7*86400,
        'cooldown_seconds'=>3600,'max_pending_work'=>3,
        'max_basis_references'=>20,'unique_event_threshold'=>null,
        'maximum_reviews_per_utc_day'=>2,'calendar'=>'UTC_SECONDS_V1',
        'supported_triggers'=>[
            'new_evidence'=>true,'review_clock'=>true,
            'verified_event_count'=>false,'contradiction'=>false,
        ],
    ]);
    if(!$p->isOk())throw new \RuntimeException('DU01_FIXTURE_POLICY_REFUSED');
    $d=HistoryReviewDue::assess([
        'basis'=>$b->value(),'policy'=>$p->value(),
        'state'=>[
            'latest_observed_at_utc'=>'2026-03-01T00:00:00Z',
            'latest_available_at_utc'=>'2026-03-15T00:00:00Z',
            'last_queued_at_utc'=>null,'last_analysed_at_utc'=>null,
            'last_human_reviewed_at_utc'=>'2026-03-14T00:00:00Z',
            'last_considered_at_utc'=>'2026-03-14T00:00:00Z',
            'last_considered_basis_digest'=>$b->value()['basis_digest'],
            'pending_work'=>0,'reviews_queued_today'=>0,
            'current_source_posture'=>'OWNER_METADATA_READ_TEST_ONLY',
            'independent_event_count'=>null,'verified_contradiction_count'=>null,
        ],
        'at_utc'=>'2026-03-16T00:00:00Z',
    ]);
    if(!$d->isOk())throw new \RuntimeException('DU01_FIXTURE_DUE_REFUSED');
    echo json_encode([
        'version'=>HistoryReviewDue::VERSION,
        'candidate_digest'=>$d->value()['candidate_digest'],
        'decision'=>$d->value()['status'],
        'reason'=>$d->value()['reason'],
        'basis_knowledge_cutoff_utc'=>$d->value()['basis_knowledge_cutoff_utc'],
        'same_as_last_considered'=>$d->value()['same_as_last_considered'],
        'fixture_only'=>true,
        'owner_rights_verified'=>false,
        'model_call_authorized'=>false,
        'notification_authorized'=>false,
        'source_bytes_returned'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"DU01_SYNTHETIC_DUE_UNAVAILABLE\n");exit(4);
}
