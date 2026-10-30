<?php

declare(strict_types=1);

/** VA-02 bounded local assurance report, not an authenticated M11 host audit. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(2); }
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Product\P04\InternalAttention;
use Allfather\Loopia\Substrate\M17\Assurance as M17Assurance;
use Allfather\Loopia\Workflow\HistoryReviewAssurance;

$args=array_slice($argv,1);
if(count($args)!==1
    || !in_array($args[0],['--help','--status','--synthetic-development'],true)){
    fwrite(STDERR,"VA02_M11_RUNTIME_NOT_ADOPTED\n");exit(3);
}
if($args[0]!=='--synthetic-development'){
    echo json_encode([
        'status'=>'LOCAL_SOURCE_SCORECARD_ONLY',
        'version'=>HistoryReviewAssurance::VERSION,
        'source_use'=>'U03_NOT_ADOPTED',
        'provider_cost'=>'UNKNOWN',
        'host_restore'=>'NOT_VERIFIED_HOST',
        'm11_incident_written'=>false,
        'deployment_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit(0);
}
if(getenv('ALLFATHER_VA02_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"VA02_EXPLICIT_SYNTHETIC_TEST_REQUIRED\n");exit(3);
}
try{
    require_once dirname(__DIR__,2).'/tests/support/An03MockFinding.php';
    require_once dirname(__DIR__,2).'/tests/support/Aq02FixtureAttentionOwner.php';
    require_once dirname(__DIR__,2).'/tests/support/Aq02MockAttention.php';
    if(!defined('ALLFATHER_AQ02_SYNTHETIC_TEST'))define('ALLFATHER_AQ02_SYNTHETIC_TEST',true);
    $f=\Allfather\Loopia\Tests\Aq02MockAttention::input();
    if(!$f->isOk())throw new \DomainException('FIXTURE_MISSING');
    $a=InternalAttention::composeFixture($f->value(),
        new \Allfather\Loopia\Tests\Aq02FixtureAttentionOwner());
    if(!$a->isOk())throw new \DomainException('ATTENTION_MISSING');
    $o=M17Assurance::inspect(
        $f->value()['native_release_proposal'],
        $f->value()['outcome_annotation'],$a->value(),
        '2026-10-30T12:00:00Z',
    );
    if(!$o->isOk())throw new \DomainException('PROVENANCE_MISSING');
    $r=HistoryReviewAssurance::compose([
        'observation'=>$o->value(),'queue'=>null,
        'coverage'=>[
            'status'=>'NOT_VERIFIED','planned_slots'=>null,
            'reported_slots'=>null,'oldest_missing_at_utc'=>null,
        ],
        'cost'=>[
            'status'=>'UNKNOWN_PROVIDER_OUTCOME','reserved_units'=>null,
            'spent_units'=>null,'unknown_attempts'=>null,
        ],
        'recovery'=>[
            'status'=>'NOT_VERIFIED_HOST','private_bytes'=>'NOT_VERIFIED',
            'credential_rotation'=>'NOT_VERIFIED','source_image'=>'NOT_VERIFIED',
        ],
        'at_utc'=>'2026-10-30T12:00:00Z',
    ]);
    if(!$r->isOk())throw new \DomainException('REPORT_MISSING');
    echo json_encode([
        'version'=>HistoryReviewAssurance::VERSION,
        'status'=>$r->value()['status'],
        'report_digest'=>$r->value()['report_digest'],
        'selected_reference_count'=>$r->value()['selected_reference_count'],
        'contrary_reference_count'=>$r->value()['contrary_reference_count'],
        'provider_spent_units'=>null,
        'queue_pending_reviews'=>null,
        'recovery_status'=>'NOT_VERIFIED_HOST',
        'source_rights_authenticated'=>false,
        'live_m11_incident_written'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"VA02_SYNTHETIC_REPORT_UNAVAILABLE\n");exit(4);
}
