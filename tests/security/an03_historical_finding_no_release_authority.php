<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/An03MockFinding.php';

use Allfather\Loopia\Core\{Clock,Digest};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Tests\{Assert,An03MockFinding};
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Workflow\{HistoricalFindingStore,HistoryReviewRelease};

$fakeDb=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$clock=new Clock();
Assert::same('AN03_U03_U06_U07_NATIVE_FINDING_OWNER_NOT_ADOPTED',
    HistoricalFindingStore::forRuntime($fakeDb,$clock,[
        'caller_claims_m07_allow'=>true,
        'm10_verdict'=>'ALLOW_INFORMATIONAL_RELEASE',
    ])->reasonCode(),
    'a caller self-claim cannot construct a native multi-evidence writer');
$fixtureFactory=HistoricalFindingStore::forDisposableTest($fakeDb,$clock);
if(PHP_SAPI==='cli'
    && defined('ALLFATHER_AN03_DISPOSABLE_SCHEMA_LOADED')
    && constant('ALLFATHER_AN03_DISPOSABLE_SCHEMA_LOADED')===true){
    Assert::true($fixtureFactory->isOk(),
        'existing explicitly disposable SQL marker remains test only');
}else{
    Assert::same('AN03_FAMILY330_RUNTIME_NOT_ADOPTED',
        $fixtureFactory->reasonCode(),
        'no production caller has a fixture writer factory');
}
Assert::same('AN03_U07_MULTI_EVIDENCE_NATIVE_P01_ROUTE_NOT_ADOPTED',
    HistoricalFindingAdapter::toCompanySignal(['fake_allow'=>true])->reasonCode(),
    'no CompanySignal can be minted by selecting one fake origin');
Assert::same('AN03_U03_U06_U07_M10_HISTORICAL_RELEASE_NOT_ADOPTED',
    HistoryReviewRelease::release(['fake_allow'=>true],
        ['actor'=>'M10','adopted'=>true])->reasonCode(),
    'M10 release cannot be passed a rehashed self-approval');
$root=dirname(__DIR__,2);
foreach([
    'app/Product/P01/HistoricalFindingAdapter.php',
    'app/Workflow/HistoryReviewRelease.php',
    'app/Workflow/HistoricalFindingStore.php',
] as $path){
    $source=(string)file_get_contents($root.'/'.$path);
    foreach([
        'curl_exec(', 'new ModelExecutor(', '->send(',
        'new SourceCaptureStage(', 'new MonitorService(',
        'releaseP01(', 'new DecisionLedger(',
        'mail(', 'unlink(', 'DELETE FROM',
    ] as $needle){
        Assert::false(str_contains($source,$needle),
            'provisional finding path cannot read, dispatch, erase or release: '.$needle);
    }
}
$f=An03MockFinding::inputs();
Assert::true($f->isOk(),'fixed synthetic source reviewer chain exists');
if(!$f->isOk())return;
$proposal=HistoricalFindingAdapter::propose($f->value());
Assert::true($proposal->isOk(),'source-only positive case through frozen reviewers');
if(!$proposal->isOk())return;
$c=$proposal->value();
foreach([
    'human_factual_release_approved',
    'human_delivery_approved','m07_relevance_review_admitted',
    'm08_policy_admission_granted','m10_release_authorized',
    'm16_internal_artifact_created','customer_visibility_authorized',
    'historical_source_use_admitted','p02_twin_update_authorized',
    'p04_distribution_authorized',
] as $key){
    $bad=$c;$bad[$key]=true;
    $copy=$bad;unset($copy['finding_digest']);
    $bad['finding_digest']=Digest::canonicalDigest($copy);
    Assert::same('AN03_FINDING_FORGED_AUTHORITY',
        HistoricalFindingAdapter::verify($bad)->reasonCode(),
        'rehashed '+$key+' cannot widen an owner grant');
}
foreach($c['primary_references'] as $ref){
    Assert::same(false,$ref['m05_original_bytes_read'],
        'source ref is original metadata only, no retained M05 source read');
    Assert::same(false,$ref['independence_verified'],
        'multiple references are not empirically independent witnesses');
}
$ownerClaim=[
    'version'=>'hs02.historical-use-inspection.v1',
    'decision'=>'ALLOW',
    'basis_digest'=>$c['basis_digest'],
    'current_m02_p01_m05_checked'=>true,
    'recheck_stage'=>'PRE_RELEASE',
    'current_owner_fingerprint'=>str_repeat('a',64),
];
Assert::same('AN03_UNTRUSTED_OR_NONHOLD_OWNER_ASSERTION',
    HistoryReviewRelease::inspect($c,'PRE_RELEASE',$ownerClaim)->reasonCode(),
    'caller cannot turn M05 current read metadata into historical release');
$ownerClaim['decision']='HOLD_U03_HISTORICAL_USE_NOT_RATIFIED';
$ownerClaim['m10_current_reliance_checked']=false;
$ownerClaim['u03_owner_decisions_ratified']=false;
$ownerClaim['historical_reuse_admitted']=false;
$ownerClaim['customer_release_authorized']=false;
$r=HistoryReviewRelease::inspect($c,'PRE_RELEASE',$ownerClaim);
Assert::true($r->isOk(),'a shape-checked U03 HOLD remains only a safe diagnostic');
Assert::same(false,$r->value()['m07_m08_m10_m16_release_authorized'],
    'even valid-shaped source holder cannot authorize product release');
