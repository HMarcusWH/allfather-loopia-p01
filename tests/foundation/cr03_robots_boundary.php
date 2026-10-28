<?php

declare(strict_types=1);

use Allfather\Loopia\Ingestion\RobotsPolicyReader;
use Allfather\Loopia\Ingestion\DisabledRobotsAcquisition;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
Assert::same('cr03.robots-disposition.v1', RobotsPolicyReader::VERSION, 'versioned RFC/local owner contract');
Assert::same(524288, RobotsPolicyReader::MAX_ROBOTS_BYTES, 'minimum 500 KiB parser capacity with 512 KiB bound');
Assert::same(86400000, RobotsPolicyReader::MAX_TTL_MS, '24-hour robots freshness cap');
Assert::same('CR03_DISTINCT_ROBOTS_REQUEST_NOT_ADMITTED',
    (new DisabledRobotsAcquisition())->obtain([])->reasonCode(),
    'default native acquisition port never leaves disabled state');
foreach ([
    'app/Ingestion/RobotsPolicyReader.php',
    'app/Ingestion/RobotsAcquisitionPort.php',
    'app/Ingestion/DisabledRobotsAcquisition.php',
] as $path) {
    $contents = (string) file_get_contents($root . '/' . $path);
    foreach (['CurlHttpsTransport', 'DnsHostResolver', 'OpenAIResponsesTransport',
        'curl_exec(', 'file_get_contents(', 'new PDO(', 'new \\PDO(', 'fopen(', 'fsockopen('] as $unsafe) {
        Assert::false(str_contains($contents, $unsafe), 'CR03 policy cannot fetch, open files or contact provider: ' . $path . ' / ' . $unsafe);
    }
}
$factory = (string) file_get_contents($root . '/app/Workflow/MonitorFactory.php');
Assert::false(str_contains($factory, 'RobotsPolicyReader') || str_contains($factory, 'Cr03RobotsFixtureAcquisition'),
    'current live MonitorFactory does not wire candidate CR03 as source authority');
Assert::false(is_file($root . '/database/migrations/255_robots_policy.sql'),
    'CR03 did not invent a live robots migration family');
$matrix = json_decode((string) file_get_contents($root . '/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'), true, 64, JSON_THROW_ON_ERROR);
$rows = array_column($matrix['build_packages'], null, 'id');
Assert::same('BUILT_INERT', $rows['CR-03']['build_status'], 'CR03 is source-built but runtime inert');
foreach (['native_permission','customer_visibility','production_activation','live_migration','external_effects'] as $flag) {
    Assert::same(false, $rows['CR-03'][$flag], 'source package status cannot activate: ' . $flag);
}
$adr = (string) file_get_contents($root . '/docs/CRAWLER_ROBOTS_ACQUISITION_ADR.md');
foreach (['RFC 9309', 'M06/M01', 'SourcePolicy', 'EgressGuard',
    'UNAVAILABLE_MAY_ALLOW', 'UNREACHABLE', '512 KiB', '24 hours'] as $part) {
    Assert::contains($part, $adr, 'CR03 ADR records scope/authority/availability: ' . $part);
}
