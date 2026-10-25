<?php
declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$adapter=(string)file_get_contents($root.'/app/Http/ReadAdapters/CompetitorHistoryAdapter.php');
$ui=(string)file_get_contents($root.'/frontend/console/src/views/p01/competitor-history.jsx');
$index=(string)file_get_contents($root.'/public_html/index.php');
$navigation=(string)file_get_contents($root.'/frontend/console/src/data/navigation.js');
$reads=(string)file_get_contents($root.'/frontend/console/src/data/surface-reads.js');

Assert::contains('ObservationHistory::listSnapshots', $adapter,'owner-reused history contract named explicitly');
Assert::contains("'actor_type'=>'OPERATOR'", $adapter,'only session operator is used as current principal');
Assert::contains('CURRENT_ELIGIBILITY_CHECKED', $adapter,'must demand current source rights');
Assert::contains('business_materiality_available', $adapter,'no false inference');
Assert::contains('HISTORY_METADATA_UNAVAILABLE',$adapter,'fail closed on unsafe metadata');
Assert::false(str_contains($adapter,'use Allfather\\Loopia\\Db\\Database'),'adapter never bypasses owner with direct DB access');
Assert::false(str_contains($adapter,'CurlHttpsTransport'),'adapter never fetches a source');
Assert::false(str_contains($adapter,'ModelExecutor'),'adapter never calls provider');
Assert::false(str_contains($index,'CompetitorHistoryAdapter'),'no live HTTP route registered');
Assert::false(str_contains($index,'/console/api/v1/p01/history'),'no public history GET added');
Assert::false(str_contains($navigation,'competitor-history.jsx'),'new preview not in navigation');
Assert::false(str_contains($reads,'P01_COMPETITOR_HISTORY'),'no new datasource descriptor');
Assert::false(str_contains($ui,'dangerouslySetInnerHTML'),'untrusted competitor name rendered as escaped React text');
Assert::false(str_contains($ui,'fetch('),'view initiates no fetch');
Assert::false(str_contains($ui,'useEffect'),'view initiates no side effects');
Assert::contains('onNextPage', $ui,'pagination explicit click only');
Assert::true(is_file($root.'/docs/LI16_INERT_HISTORY_PREVIEW.md'),'inert limitations documented');
Assert::false(is_file($root.'/database/migrations/270_history_review_bases.sql'),'new review basis schema was not minted by history preview');
