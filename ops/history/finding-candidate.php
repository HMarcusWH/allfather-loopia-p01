<?php

declare(strict_types=1);

/** AN-03: source-only read-only candidate preview, no M10/CompanySignal. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__,2).'/app/autoload.php';

use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Workflow\HistoryReviewRelease;

$args=array_slice($argv,1);
if(count($args)!==1
    || !in_array($args[0],['--help','--status','--synthetic-development'],true)){
    fwrite(STDERR,"AN03_NATIVE_HISTORICAL_FINDING_NOT_ADOPTED\n");
    exit(3);
}
if($args[0]==='--help'||$args[0]==='--status'){
    echo json_encode([
        'version'=>HistoricalFindingAdapter::VERSION,
        'status'=>'MULTI_EVIDENCE_BUILD_CANDIDATE_NOT_ADMITTED',
        'owner_source_reuse_admitted'=>false,
        'm07_m08_m10_release_authorized'=>false,
        'human_factual_review_approved'=>false,
        'human_delivery_review_approved'=>false,
        'p02_p04_action_authorized'=>false,
        'live_schema_installed'=>false,
        'external_provider_calls'=>0,
        'customer_visibility_authorized'=>false,
        'synthetic_requires'=>'ALLFATHER_AN03_SYNTHETIC_TEST=1',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}
if(getenv('ALLFATHER_AN03_SYNTHETIC_TEST')!=='1'){
    fwrite(STDERR,"AN03_SYNTHETIC_TEST_MODE_REQUIRED\n");
    exit(3);
}
try{
    require_once dirname(__DIR__,2).'/tests/support/An03MockFinding.php';
    $a=\Allfather\Loopia\Tests\An03MockFinding::inputs();
    if(!$a->isOk())throw new \RuntimeException('AN03_FIXTURE_INVALID');
    $finding=HistoricalFindingAdapter::propose($a->value());
    if(!$finding->isOk())throw new \RuntimeException('AN03_PROPOSAL_INVALID');
    $pre=HistoryReviewRelease::inspect($finding->value());
    if(!$pre->isOk())throw new \RuntimeException('AN03_HOLD_INVALID');
    echo json_encode([
        'version'=>HistoricalFindingAdapter::VERSION,
        'finding_digest'=>$finding->value()['finding_digest'],
        'basis_digest'=>$finding->value()['basis_digest'],
        'dependency_graph_digest'=>$finding->value()['dependency_graph']['graph_digest'],
        'selected_primary_ref_count'=>$finding->value()['selected_primary_ref_count'],
        'support_ref_count'=>$finding->value()['support_ref_count'],
        'contrary_ref_count'=>$finding->value()['contrary_ref_count'],
        'release_disposition'=>$pre->value()['disposition'],
        'source_text_or_identifiers_exported'=>false,
        'provider_calls'=>0,
        'human_or_customer_release_authorized'=>false,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
}catch(\Throwable){
    fwrite(STDERR,"AN03_SYNTHETIC_FINDING_PREVIEW_UNAVAILABLE\n");
    exit(4);
}
