<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Workflow\PointInTimeHistoricalBasis;

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents($root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$entry=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='HS-01'));
Assert::same(1,count($entry),'one HS-01 package in machine capability matrix');
Assert::same('BUILT_INERT',$entry[0]['build_status'],'HS-01 source built but not activated');
foreach(['native_permission','customer_visibility','production_activation',
         'live_migration','external_effects'] as $key){
    Assert::same(false,$entry[0][$key],'package cannot silently grant '.$key);
}
Assert::same(PointInTimeHistoricalBasis::VERSION,'hs01.point-in-time-historical-basis.v1',
    'versioned candidate interface is discoverable in PHP autoloader');
Assert::true(is_file($root.'/docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql'),
    '270 remains proposed');
Assert::false(is_file($root.'/database/migrations/270_history_review_bases.sql'),
    'do not introduce live migration');
$cli=(string)file_get_contents($root.'/ops/history/basis-preview.php');
Assert::true(str_contains($cli,'ALLFATHER_HS01_SYNTHETIC_TEST'),
    'synthetic demo requires an explicit local guard');
Assert::false(str_contains($cli,'--env='),'no operator credential/environment impersonation');
$owner=(string)file_get_contents($root.'/app/Workflow/HistoryReviewBasis.php');
Assert::true(str_contains($owner,'li05.offline-basis-candidate.v1'),
    'legacy v1 manifest version retained');
