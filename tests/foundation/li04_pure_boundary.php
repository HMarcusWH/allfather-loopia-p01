<?php

declare(strict_types=1);

use Allfather\Loopia\Product\P01\ObservationFeatures;
use Allfather\Loopia\Product\P01\SourceOriginGroups;
use Allfather\Loopia\Product\P01\ObservationCoverage;
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
Assert::same('li04.observation-features.pure.v1',ObservationFeatures::VERSION,'typed feature version pinned');
Assert::same('li04.byte-repeat-groups.pure.v1',SourceOriginGroups::VERSION,'byte-equivalence version pinned');
Assert::same('li04.observation-coverage.pure.v1',ObservationCoverage::VERSION,'coverage version pinned');
Assert::same(32768,ObservationFeatures::MAX_BODY_BYTES,'bounded source input limit');
Assert::same(500,SourceOriginGroups::MAX_CAPTURES,'origin grouping bounded');
Assert::same(10000,ObservationCoverage::MAX_PLANNED_SLOTS,'coverage plan bounded');

foreach (['ObservationFeatures.php','SourceOriginGroups.php','ObservationCoverage.php'] as $name) {
    $source=(string)file_get_contents($root.'/app/Product/P01/'.$name);
    Assert::false(str_contains($source,'use Allfather\\Loopia\\Db\\Database'),'LI04 pure methods do not depend on database: '.$name);
    Assert::false(str_contains($source,'use Allfather\\Loopia\\Provider'),'LI04 has no model request/provider: '.$name);
    Assert::false(str_contains($source,'new \PDO('),'LI04 cannot open a connection: '.$name);
    Assert::false(str_contains($source,'public function __construct('),'LI04 remains static pure: '.$name);
}
Assert::false(is_file($root.'/database/migrations/265_observation_features_and_origins.sql'),
    'U02/U03-gated LI04 persistent tables were NOT deployed by pure precursor');
$doc=(string)file_get_contents($root.'/docs/LI04_PURE_OBSERVATION_FEATURES.md');
Assert::contains('U02', $doc,'origin decisions remain pending');
Assert::contains('U03', $doc,'historical source-use remains pending');
Assert::contains('NOT_PERSISTED', $doc,'derived outputs are not being treated as owner-native rows');
