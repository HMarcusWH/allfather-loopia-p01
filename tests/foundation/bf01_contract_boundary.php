<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\BuildFirstContracts;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$registry = BuildFirstContracts::loadAndValidate($root);
Assert::same('SYNTHETIC_CANDIDATE_ONLY_NOT_RUNTIME_CONTRACT_ADOPTION', $registry['register_status'], 'BF-01 registry cannot turn into runtime law');
Assert::same(false, $registry['can_install_sql'], 'BF-01 cannot install DB migration');
Assert::same(false, $registry['can_disclose_source_bytes'], 'BF-01 cannot disclose primary evidence bytes');
Assert::same(false, $registry['can_bind_native_factories'], 'BF-01 cannot wire native model/crawler factories');
$entries = array_column($registry['index'], null, 'name');
Assert::true(isset($entries['CapturedSourceEnvelope'], $entries['HistoricalBasis'], $entries['PackageBuildReceipt'], $entries['RecoveryManifest']), 'owner contracts are indexed');
Assert::same('NOT_GRANTED', $entries['CurrentUseDecision']['production_adoption'], 'proposed owner decision remains unadopted');
Assert::same('UNVERIFIED_UNTIL_REAL_CONSUMER_IN_RT02', $entries['CurrentUseDecision']['producer_wiring'], 'missing native producer is never treated as implemented');
Assert::same(64, count($entries), '64 unique index names loaded');
$guardFiles = [
    $root . '/app/Workflow/MonitorFactory.php',
    $root . '/public_html/index.php',
    $root . '/worker/tick.php',
];
foreach ($guardFiles as $path) {
    $source = (string) file_get_contents($path);
    Assert::false(str_contains($source, 'LongitudinalBuildHarness') || str_contains($source, 'tests/support/'),
        'web/product/scheduler do not import BF-01 fake authority: ' . basename($path));
}
Assert::true(is_file($root . '/tests/support/LongitudinalBuildHarness.php'),
    'fixture harness exists only under tests/support, outside the app autoloader');
