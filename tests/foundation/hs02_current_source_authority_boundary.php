<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\{HistoryReviewRightsGateway,HistoryReviewContext};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents($root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$rows=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='HS-02'));
Assert::same(1,count($rows),'HS02 has one registered source-only capability item');
Assert::same('BUILT_INERT',$rows[0]['build_status'],
    'owner-native reader is built, historical purpose remains unadmitted');
foreach(['native_permission','customer_visibility','production_activation',
         'live_migration','external_effects'] as $flag){
    Assert::same(false,$rows[0][$flag],'HS02 must not promote '.$flag);
}
Assert::same(3,count(HistoryReviewRightsGateway::PURPOSES),
    'internal, model and external disclosure purposes are distinct');
Assert::same(3,count(HistoryReviewRightsGateway::STAGES),
    'pre, post and release phases each have an independent owner recheck slot');
$context=new HistoryReviewContext();
Assert::same('LI05C_U03_U06_HISTORICAL_MODEL_PURPOSE_NOT_ADOPTED',
    $context->revalidate([])->reasonCode(),
    'existing ModelContextAuthority remains unmodified at dispatch');
Assert::same('LI05C_HISTORICAL_ROUTING_NOT_ADOPTED',
    $context->validateRoutingPackage([],[],[])->reasonCode(),
    'existing release route remains inert');
Assert::false(is_file($root.'/database/migrations/270_history_review_bases.sql'),
    'historical-use code has not admitted family 270 migration');
Assert::true(is_file($root.'/tests/support/Hs02SyntheticUse.php'),
    'only test/support implements a positive deterministic fixture');
$source=(string)file_get_contents($root.'/app/Workflow/HistoryReviewRightsGateway.php');
Assert::false(str_contains($source,'new SourcePolicy'),
    'source rights are checked by native owner rather than a second policy implementation');
Assert::true(str_contains($source,'HistoricalCaptureProbe'),
    'source owner remains the existing M02/P01/M05 native checker');
