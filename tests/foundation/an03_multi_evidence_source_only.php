<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Product\P01\{HistoricalFindingAdapter,CompanySignals};
use Allfather\Loopia\Workflow\{HistoryReviewRelease,HistoricalFindingStore,ReleaseStages};

$root=dirname(__DIR__,2);
$matrix=json_decode((string)file_get_contents(
    $root.'/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true,64,JSON_THROW_ON_ERROR);
$matches=array_values(array_filter($matrix['build_packages'],
    static fn(array $p):bool=>($p['id']??null)==='AN-03'));
Assert::same(1,count($matches),'AN03 unique package in frozen capability register');
Assert::same('BUILT_INERT',$matches[0]['build_status'],
    'multi-evidence source adaptation is never native release');
foreach([
    'native_permission','customer_visibility','production_activation',
    'live_migration','external_effects',
] as $key){
    Assert::same(false,$matches[0][$key],'no runtime grant: '.$key);
}
Assert::same('an03.multi-evidence-provisional-finding.v1',
    HistoricalFindingAdapter::VERSION,
    'multi-reference candidate has a separately versioned exact identity');
Assert::same('an03.provisional-multi-evidence-release-hold.v1',
    HistoryReviewRelease::VERSION,
    'M10/IV01 current reliance adoption stays U03/U07 HOLD');
Assert::same('an03.disposable-multi-reference-binding-store.v1',
    HistoricalFindingStore::VERSION,
    'candidate SQL remains CLI-disposable and not an M10 ledger');
Assert::true(is_file(
    $root.'/docs/longitudinal/proposals/330_historical_finding_bindings_CANDIDATE.sql'),
    'exact family330 exists as an uninstalled candidate');
Assert::false(is_file(
    $root.'/database/migrations/330_historical_finding_bindings.sql'),
    'no 330 migration auto-lands through existing migrator');
Assert::false(is_file($root.'/ops/history/finding.php'),
    'no native authorized historical finding release entrypoint');
Assert::true(is_file($root.'/tests/support/An03MockFinding.php'),
    'synthetic mock lives only under tests/support, not HTTP routes');
$release=(string)file_get_contents($root.'/app/Workflow/ReleaseStages.php');
Assert::false(str_contains($release,'HistoricalFindingAdapter'),
    'old P01 source-diff route remains unchanged and cannot fake multi-evidence support');
Assert::false(str_contains($release,'HistoryReviewRelease'),
    'the existing M10 release path is not weakened by synthetic new code');
$signal=(string)file_get_contents($root.'/app/Product/P01/CompanySignals.php');
Assert::false(str_contains($signal,'HistoricalFindingAdapter'),
    'no synthetic evidence entered canonical P01 proposal owner');
