<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Product\P01\{
    DerivedSectionEvidence,SemanticDelta,ObservationFeatureSet
};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents($root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$p=array_values(array_filter($matrix['build_packages'],
    static fn(array $x):bool=>($x['id']??null)==='CR-04'));
Assert::same(1,count($p),'CR04 one manifest capability');
Assert::same('BUILT_INERT',$p[0]['build_status'],'CR04 source-built only');
foreach(['native_permission','customer_visibility','production_activation',
         'live_migration','external_effects'] as $key){
    Assert::same(false,$p[0][$key],'CR04 cannot promote '.$key);
}
Assert::same('cr04.derived-section-p01-context.v1',
    DerivedSectionEvidence::VERSION,'new candidate context carries version');
Assert::same('cr02.semantic-delta.v1',SemanticDelta::VERSION,
    'old CR02 section delta owner remains unchanged');
Assert::same('ob03.observation-feature-set.v1',ObservationFeatureSet::VERSION,
    'existing OB03 metadata owner reused');
Assert::false(is_file($root.'/database/migrations/360_derived_section_evidence.sql'),
    'no shadow raw evidence or runtime source migration added');
Assert::false(is_file($root.'/ops/crawler/run.php'),
    'existing native P01 acquisition runner cannot be bypassed');
$src=(string)file_get_contents($root.'/app/Product/P01/DerivedSectionEvidence.php');
Assert::true(str_contains($src,'SemanticDelta::compare('),
    'the read-only context actually derives a section delta, not a dummy stub');
Assert::true(str_contains($src,'ObservationFeatureSet::compose('),
    'the derived delta reaches existing OB03 feature composer');
Assert::true(str_contains($src,'CR04_U01_U02_U03_U06_NATIVE_CONTEXT_NOT_ADOPTED'),
    'model provider cannot be called through derived context');
