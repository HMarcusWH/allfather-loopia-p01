<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Cr03RobotsFixture.php';
require_once __DIR__ . '/../support/Cr03RobotsFixtureAcquisition.php';

use Allfather\Loopia\Ingestion\DisabledRobotsAcquisition;
use Allfather\Loopia\Ingestion\RobotsPolicyReader;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Cr03RobotsFixture;
use Allfather\Loopia\Tests\Cr03RobotsFixtureAcquisition;

$gold = Cr03RobotsFixture::golden();
Assert::same('cr03.robots-golden.v1', $gold['schema_version'], 'CR03 fixture registry locked');
Assert::same('SYNTHETIC_OFFLINE_ONLY', $gold['status'], 'robots test material grants no owner permissions');
Assert::same(20, count($gold['cases']), '20 deterministic REP golden examples present');

foreach ($gold['cases'] as $test) {
    $scope = Cr03RobotsFixture::scope($test['target_path'], ['product_token' => $test['product_token']]);
    $source = Cr03RobotsFixture::source($test['robots']);
    $port = new Cr03RobotsFixtureAcquisition($source);
    $read = $port->obtain($scope);
    Assert::true($read->isOk(), $test['id'] . ': offline fixed policy supplied without network');
    if (!$read->isOk()) {
        continue;
    }
    $outcome = RobotsPolicyReader::decide($read->value(), $scope);
    Assert::true($outcome->isOk(), $test['id'] . ': full local interpretation computes');
    if (!$outcome->isOk()) {
        continue;
    }
    $result = $outcome->value();
    Assert::same($test['expected_rfc'], $result['rfc_decision'], $test['id'] . ': correct RFC 9309 disposition');
    Assert::same($test['expected_reason'], $result['rfc_reason'], $test['id'] . ': most-specific explanation');
    Assert::same($test['expected_rule'], $result['matched_rule']['directive'] ?? null, $test['id'] . ': chosen rule');
    Assert::same('FRESH', $result['robots_freshness'], $test['id'] . ': current synthetic policy is fresh');
    Assert::same($test['expected_rfc'] === 'DISALLOW' ? 'DENY_ROBOTS' : 'HOLD_NOT_AUTHORIZED',
        $result['effective_acquisition'], $test['id'] . ': robots never mints actual fetch right');
    Assert::same(false, $result['owner_source_use_authorized'], $test['id'] . ': source card still needs independent review');
    Assert::same(false, $result['m06_m01_execution_authorized'], $test['id'] . ': M06/M01 still separately owned');
    Assert::same(false, $result['new_robots_request_authorized'], $test['id'] . ': no implied GET /robots.txt');
    Assert::same(0, $result['network_attempt_count'], $test['id'] . ': zero network');
    Assert::same(0, $port->networkAttempts, $test['id'] . ': fixture readback touches no socket');
    Assert::same(1, $port->readbacks, $test['id'] . ': one explicit fixture readback only');
    Assert::same($result['decision_digest'], RobotsPolicyReader::decide($read->value(), $scope)->value()['decision_digest'],
        $test['id'] . ': deterministic immutable decision digest');
    Assert::true(is_string($result['target_path_digest']) && strlen($result['target_path_digest']) === 64,
        $test['id'] . ': target path never copied into audit receipt');
    Assert::false(str_contains(json_encode($result, JSON_THROW_ON_ERROR), $test['robots']),
        $test['id'] . ': raw robots policy never copied into decision');
}

$groupPolicy = Cr03RobotsFixture::source("User-agent: loopiaallfather\nDisallow: /pricing/private\n");
$upperAgent = Cr03RobotsFixture::scope('/pricing/private', ['product_token' => 'LOOPIAALLFATHER']);
Assert::same('DISALLOW', RobotsPolicyReader::decide($groupPolicy, $upperAgent)->value()['rfc_decision'],
    'agent-group lookup is case-insensitive');

$unused = Cr03RobotsFixture::source("User-agent: LoopiaAllfather\nDisallow: /pricing/foo\n");
$result = RobotsPolicyReader::decide($unused, Cr03RobotsFixture::scope('/pricing/bar'))->value();
Assert::same('ALLOW', $result['rfc_decision'], 'no matching path defaults to RFC ALLOW');
Assert::same('HOLD_NOT_AUTHORIZED', $result['effective_acquisition'], 'default RFC allow is never network permission');

$stale = Cr03RobotsFixture::source("User-agent: *\nAllow: /\n", [
    'received_at_ms' => Cr03RobotsFixture::NOW_MS - RobotsPolicyReader::MAX_TTL_MS - 1,
    'observed_at_ms' => Cr03RobotsFixture::NOW_MS - RobotsPolicyReader::MAX_TTL_MS - 100,
]);
$read = RobotsPolicyReader::decide($stale, Cr03RobotsFixture::scope())->value();
Assert::same('EXPIRED', $read['robots_freshness'], 'cache older than 24 hours is stale');
Assert::same('UNKNOWN', $read['rfc_decision'], 'expired robots bytes never retain stale allow');
Assert::same('ROBOTS_POLICY_EXPIRED', $read['rfc_reason'], 'stale cache has explicit reason');
$exact = Cr03RobotsFixture::source("User-agent: *\nDisallow: /pricing/\n", [
    'received_at_ms' => Cr03RobotsFixture::NOW_MS - RobotsPolicyReader::MAX_TTL_MS,
    'observed_at_ms' => Cr03RobotsFixture::NOW_MS - RobotsPolicyReader::MAX_TTL_MS - 1,
]);
Assert::same('FRESH', RobotsPolicyReader::decide($exact, Cr03RobotsFixture::scope())->value()['robots_freshness'],
    '24-hour inclusive TTL boundary deterministic');

foreach ([
    ['UNAVAILABLE', 404, 'UNAVAILABLE_MAY_ALLOW', 'HOLD_NOT_AUTHORIZED'],
    ['UNAVAILABLE', 429, 'UNAVAILABLE_MAY_ALLOW', 'HOLD_NOT_AUTHORIZED'],
    ['UNREACHABLE', 503, 'DISALLOW', 'DENY_ROBOTS'],
    ['UNREACHABLE', null, 'DISALLOW', 'DENY_ROBOTS'],
    ['NOT_FETCHED', null, 'UNKNOWN', 'HOLD_NOT_AUTHORIZED'],
] as [$state, $http, $expected, $acquisition]) {
    $result = RobotsPolicyReader::decide(Cr03RobotsFixture::event($state, $http), Cr03RobotsFixture::scope());
    Assert::true($result->isOk(), $state . ': source availability classified');
    if (!$result->isOk()) {
        continue;
    }
    $r = $result->value();
    Assert::same($expected, $r['rfc_decision'], $state . ': RFC availability outcome');
    Assert::same($acquisition, $r['effective_acquisition'], $state . ': stricter Allfather disposition');
    if ($http === 429) {
        Assert::true(in_array('HTTP_429_REQUIRES_OWNER_RETRY_SCHEDULE', $r['owner_policy_holds'], true),
            '429 not treated as permission to retry immediately');
    }
}
$scope = Cr03RobotsFixture::scope('/pricing/allowed', [
    'last_capture_at_ms' => Cr03RobotsFixture::NOW_MS - 500,
    'min_interval_ms' => 3600000,
    'retry_after_until_ms' => Cr03RobotsFixture::NOW_MS + 10000,
    'remaining_attempts' => 0,
    'remaining_bytes' => 0,
]);
$restricted = RobotsPolicyReader::decide(Cr03RobotsFixture::source("User-agent: *\nAllow: /\n"), $scope)->value();
foreach (['SOURCE_ATTEMPT_OR_BYTE_BUDGET_EXHAUSTED', 'SOURCE_OWNER_CADENCE_HOLD', 'RETRY_AFTER_ACTIVE'] as $reason) {
    Assert::true(in_array($reason, $restricted['owner_policy_holds'], true), 'bounded local owner floor: ' . $reason);
}
Assert::same('HOLD_NOT_AUTHORIZED', $restricted['effective_acquisition'], 'all budget/throttle checks prevent positive native action');

$disabled = new DisabledRobotsAcquisition();
Assert::same('CR03_DISTINCT_ROBOTS_REQUEST_NOT_ADMITTED',
    $disabled->obtain(Cr03RobotsFixture::scope())->reasonCode(),
    'default native robots port refuses independently of RFC outcome');

// RFC §2.5: the parser must process at least 500 KiB before its stated byte ceiling.
Assert::true(RobotsPolicyReader::MAX_ROBOTS_BYTES >= 500 * 1024, 'RFC lower bound on parser byte ceiling');
$nearLimit = "User-agent: *\nDisallow: /pricing/private\n"
    . str_repeat('#' . str_repeat('x', 7998) . "\n", 64);
Assert::true(strlen($nearLimit) >= 500 * 1024 && strlen($nearLimit) <= RobotsPolicyReader::MAX_ROBOTS_BYTES,
    'fixture exercises at least 500 KiB without breaching finite limit');
$large = RobotsPolicyReader::decide(Cr03RobotsFixture::source($nearLimit), Cr03RobotsFixture::scope('/pricing/private'));
Assert::true($large->isOk(), 'RFC-compatible minimum byte bound processed (64 bounded comment lines)');
if ($large->isOk()) {
    Assert::same('DISALLOW', $large->value()['rfc_decision'], 'large policy retains effective parseable rules');
}
