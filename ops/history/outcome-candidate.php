<?php

declare(strict_types=1);

/** AN-04 offline outcome/follow-up PREVIEW; never a native reviewer. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Workflow\{HistoryReviewOutcomes,HistoryReviewFollowUp};

$args=array_slice($argv,1);
if(count($args)!==1
    || !in_array($args[0],['--help','--status','--synthetic-development'],true)){
    fwrite(STDERR,"AN04_NATIVE_OUTCOME_OWNER_NOT_ADOPTED\n");
    exit(3);
}
if($args[0]==='--help'||$args[0]==='--status'){
    echo json_encode([
        'version'=>HistoryReviewOutcomes::VERSION,
        'status'=>'U03_U07_U10_UNADJUDICATED_REPORT_ONLY',
        'followup_version'=>HistoryReviewFollowUp::VERSION,
        'historical_finding_immutable'=>true,
        'source_owner_authentication'=>false,
        'human_outcome_adjudication'=>false,
        'provider_calls'=>0,
        'scheduler_or_prompt_update'=>false,
        'customer_release_authorized'=>false,
        'synthetic_requires'=>'ALLFATHER_AN04_SYNTHETIC_TEST=1',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if(getenv('ALLFATHER_AN04_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"AN04_SYNTHETIC_TEST_MODE_REQUIRED\n");
    exit(3);
}
try {
    require_once dirname(__DIR__,2).'/tests/support/An03MockFinding.php';
    $base=\Allfather\Loopia\Tests\An03MockFinding::inputs();
    if(!$base->isOk())throw new \RuntimeException('BAD_FIXTURE');
    $finding=HistoricalFindingAdapter::propose($base->value());
    if(!$finding->isOk())throw new \RuntimeException('BAD_FINDING');
    $new=[
        'ref_id'=>'an04_cli_later_ref',
        'source_ref_id'=>'an04_cli_later_source',
        'evidence_id'=>'an04_cli_later_evidence',
        'snapshot_id'=>'an04_cli_later_snapshot',
        'payload_sha256'=>str_repeat('f',64),
        'observed_at_utc'=>'2026-03-18T00:00:00Z',
        'known_at_utc'=>'2026-03-19T00:00:00Z',
        'polarity'=>'UNKNOWN',
    ];
    $report=HistoryReviewOutcomes::propose([
        'finding'=>$finding->value(),
        'prior_annotation'=>null,
        'observer'=>['actor_type'=>'SYNTHETIC_FIXTURE','actor_id'=>'an04_cli_reporter'],
        'disposition'=>'STILL_UNRESOLVED',
        'new_evidence'=>[$new],
        'inspected_at_utc'=>'2026-10-30T12:00:00Z',
        'deadline_utc'=>'2026-11-05T00:00:00Z',
        'next_check_code'=>'HUMAN_VALIDATE_SOURCE',
    ]);
    if(!$report->isOk())throw new \RuntimeException('BAD_ANNOTATION');
    echo json_encode([
        'version'=>HistoryReviewOutcomes::VERSION,
        'annotation_digest'=>$report->value()['annotation_digest'],
        'original_finding_digest'=>$finding->value()['finding_digest'],
        'status'=>$report->value()['status'],
        'reported_disposition'=>$report->value()['reported_disposition'],
        'later_ref_count'=>$report->value()['new_ref_count'],
        'human_outcome_adjudicated'=>false,
        'observer_identity_authenticated'=>false,
        'iv01_invalidation_executed'=>false,
        'source_bytes_exported'=>false,
        'provider_or_scheduler_actions'=>0,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"AN04_SYNTHETIC_OUTCOME_PREVIEW_UNAVAILABLE\n");
    exit(4);
}
