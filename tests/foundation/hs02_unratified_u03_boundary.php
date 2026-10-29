<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\HistoryReviewRightsGateway;

$root=dirname(__DIR__,2);
$d=json_decode((string)file_get_contents($root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$entry=array_values(array_filter($d['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='HS-02'));
Assert::same(1,count($entry),'exactly one HS02 capability entry');
Assert::same('BUILT_INERT',$entry[0]['build_status'],'HS02 implementation is not source-use activation');
foreach(['native_permission','customer_visibility','production_activation',
    'live_migration','external_effects'] as $flag){
    Assert::same(false,$entry[0][$flag],'HS02 must not grant '.$flag);
}
Assert::same('hs02.current-use-gateway-candidate.v1',HistoryReviewRightsGateway::VERSION,
    'owner historical-use contract is versioned');
Assert::same('HOLD_U03_U06_UNRATIFIED',HistoryReviewRightsGateway::HOLD,
    'no silent U03 approval');
Assert::true(count(HistoryReviewRightsGateway::PURPOSES)===3,
    'separate internal/model/provider purposes');
Assert::true(count(HistoryReviewRightsGateway::STAGES)===4,
    'prepare, dispatch, return and release have explicit recheck calls');
$legacy=(string)file_get_contents($root.'/app/Workflow/HistoryReviewContext.php');
Assert::true(str_contains($legacy,'LI05C_U03_U06_HISTORICAL_MODEL_PURPOSE_NOT_ADOPTED'),
    'legacy history context remains fail-closed');
$policy=(string)file_get_contents($root.'/app/Workflow/HistoryReviewRightsGateway.php');
Assert::true(str_contains($policy,'originOwner->inspect'),
    'M02/P01/M05 current owner reader is required');
Assert::false(str_contains($policy,'public function authorize'),
    'new seam does not implement unratified source-use authority');
