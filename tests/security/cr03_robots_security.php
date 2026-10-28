<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Cr03RobotsFixture.php';
require_once __DIR__ . '/../support/Cr03RobotsFixtureAcquisition.php';

use Allfather\Loopia\Ingestion\RobotsPolicyReader;
use Allfather\Loopia\Ingestion\DisabledRobotsAcquisition;
use Allfather\Loopia\Ingestion\SourcePolicy;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Cr03RobotsFixture;
use Allfather\Loopia\Tests\Cr03RobotsFixtureAcquisition;

$policy = Cr03RobotsFixture::source("User-agent: *\nDisallow: /pricing/secret\n");
$scope = Cr03RobotsFixture::scope('/pricing/secret');

$bad = $policy; $bad['raw_sha256'] = str_repeat('f', 64);
Assert::same('CR03_POLICY_BYTE_DIGEST_INVALID', RobotsPolicyReader::decide($bad, $scope)->reasonCode(),
    'tampered policy bytes cannot be laundered into an allow');
$bad = $scope; $bad['source_ref_id'] = '00000000-0000-4000-8000-000000001234';
Assert::same('CR03_OWNER_SCOPE_MISMATCH', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'foreign source-reference binding refuses');
$bad = $scope; $bad['tenant_id'] = '00000000-0000-4000-8000-000000001234';
Assert::same('CR03_OWNER_SCOPE_MISMATCH', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'foreign tenant cannot consume this robots fixture');
$bad = $scope; $bad['native_execution_admitted'] = true;
Assert::same('CR03_NATIVE_OWNER_ADMISSION_REQUIRED', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'caller-asserted boolean cannot mint execution grant');
$bad = $scope; $bad['owner_source_use'] = 'ACTIVE';
Assert::same('CR03_NATIVE_OWNER_ADMISSION_REQUIRED', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'robots approval cannot replace source licensing');
$bad = $scope; $bad['purpose'] = 'LIVE_CRAWLING';
Assert::same('CR03_NATIVE_OWNER_ADMISSION_REQUIRED', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'candidate purpose cannot activate network');
$bad = $scope; $bad['approved_robots_fetch'] = true;
Assert::same('CR03_ACQUISITION_SCOPE_CONTRACT_INVALID', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'unknown flag cannot expand acquisition permission');
$bad = $scope; $bad['robots_url'] = 'https://example.org/pricing/robots.txt';
Assert::same('CR03_SOURCE_CARD_PATH_NOT_ROBOTS_AUTHORITY', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'robots.txt is exact root URI, never page-relative');
$bad = $scope; $bad['target_url'] = 'https://example.org/robots.txt';
Assert::same('CR03_SOURCE_CARD_PATH_NOT_ROBOTS_AUTHORITY', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'a page-scoped /pricing card cannot authorize root /robots.txt');
Assert::false(SourcePolicy::urlWithinScope('https://example.org/pricing', 'https://example.org/robots.txt'),
    'original owner SourcePolicy rejects implicit root robots fetch from /pricing');
$bad = $scope; $bad['target_url'] = 'https://evil.invalid/pricing/secret';
Assert::same('CR03_SOURCE_CARD_PATH_NOT_ROBOTS_AUTHORITY', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'cross-origin target fails before any network');
$bad = $scope; $bad['target_url'] = 'https://example.org/pricing/%2e%2e/robots.txt';
Assert::same('CR03_SOURCE_CARD_PATH_NOT_ROBOTS_AUTHORITY', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'encoded traversal refuses via existing SourcePolicy');
$bad = $policy; $bad['source_card_id'] = '00000000-0000-4000-8000-000000009999';
Assert::same('CR03_OWNER_SCOPE_MISMATCH', RobotsPolicyReader::decide($bad, $scope)->reasonCode(),
    'source card cannot be swapped after robots receipt');
$bad = $policy; $bad['body'] = "\xFF"; $bad['raw_sha256'] = hash('sha256', $bad['body']);
Assert::same('CR03_ROBOTS_ENCODING_INVALID', RobotsPolicyReader::decide($bad, $scope)->reasonCode(),
    'invalid UTF-8 robots document refuses');
$bad = $policy; $bad['body'] = "User-agent: *\nDisallow: /pricing/\0"; $bad['raw_sha256'] = hash('sha256', $bad['body']);
Assert::same('CR03_ROBOTS_ENCODING_INVALID', RobotsPolicyReader::decide($bad, $scope)->reasonCode(),
    'NULs refuse before matching');
$big = str_repeat('X', RobotsPolicyReader::MAX_ROBOTS_BYTES + 1);
Assert::same('CR03_ROBOTS_BYTE_CEILING', RobotsPolicyReader::decide(Cr03RobotsFixture::source($big), $scope)->reasonCode(),
    'robust maximum source size bounded');
$line = "User-agent: *\n" . 'Disallow: /' . str_repeat('A', 8200);
Assert::same('CR03_ROBOTS_LINE_BYTE_CEILING', RobotsPolicyReader::decide(Cr03RobotsFixture::source($line), $scope)->reasonCode(),
    'oversized line refused, not truncated to a false allow');
$pat = "User-agent: *\nDisallow: /" . str_repeat('*a', 17);
Assert::same('CR03_ROBOTS_PATTERN_CEILING', RobotsPolicyReader::decide(Cr03RobotsFixture::source($pat), $scope)->reasonCode(),
    'path wildcard count bounded without regex backtracking');
$bad = $policy; $bad['http_status'] = 404;
Assert::same('CR03_ROBOTS_HTTP_OR_CONTENT_TYPE_INVALID', RobotsPolicyReader::decide($bad, $scope)->reasonCode(),
    '200 bytes cannot be relabeled as HTTP 404 without distinct status');
$bad = $policy; $bad['robots_url'] = 'http://example.org/robots.txt';
Assert::same('CR03_SOURCE_CARD_PATH_NOT_ROBOTS_AUTHORITY', RobotsPolicyReader::decide($bad, $scope)->reasonCode(),
    'non-HTTPS robots origin refused');
$bad = $policy; $bad['received_at_ms'] = Cr03RobotsFixture::NOW_MS + 1;
Assert::same('CR03_POLICY_CLOCK_INVALID', RobotsPolicyReader::decide($bad, $scope)->reasonCode(),
    'future robots evidence cannot establish freshness');
$bad = $scope; $bad['min_interval_ms'] = -1;
Assert::same('CR03_THROTTLE_OR_BUDGET_INVALID', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'malformed throttle cannot authorize source read');
$bad = $scope; $bad['retry_after_until_ms'] = 'tomorrow';
Assert::same('CR03_THROTTLE_OR_BUDGET_INVALID', RobotsPolicyReader::decide($policy, $bad)->reasonCode(),
    'unparseable retry-after refuses');
$port = new Cr03RobotsFixtureAcquisition($policy);
$bad = $scope; $bad['tenant_id'] = '00000000-0000-4000-8000-000000000098';
Assert::same('CR03_FIXTURE_SOURCE_SCOPE_MISMATCH', $port->obtain($bad)->reasonCode(),
    'fixture cannot read a foreign tenant');
Assert::same(0, $port->networkAttempts, 'no hidden network on all denied paths');
Assert::same(0, $port->readbacks, 'refused fixture read leaves no audited readback');
Assert::same('CR03_DISTINCT_ROBOTS_REQUEST_NOT_ADMITTED',
    (new DisabledRobotsAcquisition())->obtain($scope)->reasonCode(),
    'native adapter stays disabled even with apparently valid robots bytes');
