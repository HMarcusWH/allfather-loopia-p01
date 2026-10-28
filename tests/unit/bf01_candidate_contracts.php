<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/LongitudinalBuildHarness.php';

use Allfather\Loopia\Ops\BuildFirstContracts;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\LongitudinalBuildHarness;

$root = dirname(__DIR__, 2);
$contracts = BuildFirstContracts::loadAndValidate($root);
Assert::same(64, count($contracts['index']), 'all planned 64 cross-package envelopes are indexed');
Assert::same(14, count($contracts['primary_contracts']), '14 primary contracts have typed payloads and examples');
Assert::same(false, $contracts['can_grant_execution'], 'candidate registry cannot create execution grants');

$cases = json_decode((string) file_get_contents($root . '/tests/fixtures/longitudinal/contracts/candidate_examples.v1.json'), true, 64, JSON_THROW_ON_ERROR)['cases'];
foreach ($cases as $name => $pair) {
    $accepted = BuildFirstContracts::checkCandidate($contracts, $name, $pair['positive']);
    Assert::same('VALID_CANDIDATE', $accepted['outcome'], "positive candidate for {$name}");
    Assert::true(is_string($accepted['digest']) && strlen($accepted['digest']) === 64, "digest for {$name}");
    $negative = BuildFirstContracts::checkCandidate($contracts, $name, $pair['refusal']['candidate']);
    Assert::same('BF01_REQUIRED_FIELD_MISSING', $negative['reason_code'], "missing typed field refused for {$name}");
}

$base = $cases['HistoricalBasis']['positive'];
$bad = $base;
$bad['payload']['period_end'] = $bad['payload']['period_start'];
Assert::same('BF01_CLOCK_ORDER', BuildFirstContracts::checkCandidate($contracts, 'HistoricalBasis', $bad)['reason_code'], 'empty/reversed temporal period denied');
$bad = $cases['CapturedSourceEnvelope']['positive'];
$bad['payload']['raw_source_body'] = 'malicious injected bytes';
Assert::same('BF01_PAYLOAD_EXTRA_FIELD', BuildFirstContracts::checkCandidate($contracts, 'CapturedSourceEnvelope', $bad)['reason_code'], 'raw source payload cannot hide in candidate envelope');
$bad = $cases['CurrentUseDecision']['positive'];
$bad['payload']['authorization_proven'] = true;
Assert::same('BF01_FIELD_TYPE_INVALID', BuildFirstContracts::checkCandidate($contracts, 'CurrentUseDecision', $bad)['reason_code'], 'caller cannot assert owner-approved source use');
$bad = $cases['InternalAttention']['positive'];
$bad['payload']['recipient_role'] = "SALES\nBcc: attacker@example.com";
Assert::same('BF01_FIELD_TYPE_INVALID', BuildFirstContracts::checkCandidate($contracts, 'InternalAttention', $bad)['reason_code'], 'recipient role cannot contain CRLF/header-like values');
$bad['payload']['send_email'] = true;
Assert::same('BF01_PAYLOAD_EXTRA_FIELD', BuildFirstContracts::checkCandidate($contracts, 'InternalAttention', $bad)['reason_code'], 'no optional send capability');
$bad = $cases['DocumentProjection']['positive'];
$bad['payload']['sections'][0]['body'] = 'raw source text';
Assert::same('BF01_FIELD_TYPE_INVALID', BuildFirstContracts::checkCandidate($contracts, 'DocumentProjection', $bad)['reason_code'], 'section source bytes forbidden');
$bad = $cases['AttemptReservation']['positive'];
$bad['payload']['usage_known'] = true;
Assert::same('BF01_FIELD_TYPE_INVALID', BuildFirstContracts::checkCandidate($contracts, 'AttemptReservation', $bad)['reason_code'], 'spending cannot be established by candidate booleans');
$bad = $cases['RecoveryManifest']['positive'];
$bad['payload']['restored'] = true;
Assert::same('BF01_PAYLOAD_EXTRA_FIELD', BuildFirstContracts::checkCandidate($contracts, 'RecoveryManifest', $bad)['reason_code'], 'offline manifest cannot declare host restore');
$bad = $cases['DocumentProjection']['positive'];
$bad['payload']['sections'] = [['kind' => 'UNKNOWN', 'text_digest' => str_repeat('a',64), 'locator_kind' => 'NONE', 'locator_ref' => 'fixture:1', 'status' => 'UNKNOWN'], ['kind' => 'UNKNOWN']];
Assert::same('BF01_FIELD_TYPE_INVALID', BuildFirstContracts::checkCandidate($contracts, 'DocumentProjection', $bad)['reason_code'], 'mixed valid/invalid sections denied');

$offline = LongitudinalBuildHarness::forCase('allowed');
Assert::same('2026-10-28T00:00:00.000Z', $offline->now(), 'clock pinned to synthetic epoch');
$allow = $offline->observe();
Assert::same('FETCHED', $allow['outcome'], 'real EgressGuard allows explicitly scoped fixture');
Assert::same(['https://bf01.example/pricing'], $offline->attemptedUrls(), 'one fake transport request, no network');
Assert::same(['93.184.216.34'], $offline->pinnedIps(), 'fake transport received pre-vetted IP pin');
Assert::same(false, $offline->modelPosture()['is_live'], 'model transport is offline fixture');
Assert::same(0, $offline->modelPosture()['calls'], 'no model calls on fixture observation');
$offline->advanceDays(1);
Assert::same('2026-10-29T00:00:00.000Z', $offline->now(), 'deterministic clock explicitly advanced');
Assert::throws(static fn () => $offline->advanceDays(0), \DomainException::class, 'refuse invalid clock step');

$redirect = LongitudinalBuildHarness::forCase('redirect_refused');
Assert::same('REFUSED', $redirect->observe()['outcome'], 'redirect exits source scope and is refused');
Assert::same(['https://bf01.example/pricing'], $redirect->attemptedUrls(), 'out of scope target never even reaches fake transport');
$denied = LongitudinalBuildHarness::forCase('allowed');
Assert::same('REFUSED', $denied->observe('https://bf01.example/outside')['outcome'], 'unapproved initial path is refused before transport');
Assert::same([], $denied->attemptedUrls(), 'no request even to fake transport for unapproved initial path');
Assert::throws(static fn () => LongitudinalBuildHarness::forCase('live_source'), \DomainException::class, 'cannot select live source case');
$T='00000000-0000-4000-8000-000000000001';$W='00000000-0000-4000-8000-000000000002';$X='00000000-0000-4000-8000-000000000003';
$entry=$offline->appendCandidate($T,$W,'REVIEW_DUE_CANDIDATE',str_repeat('a',64));
Assert::same(1,$entry['stored'],'one ephemeral append-only candidate');
Assert::same(1,count($offline->candidateRecords($T,$W)),'same scope can inspect own synthetic events');
Assert::same([], $offline->candidateRecords($T,$X),'different workspace cannot inspect synthetic records');
Assert::throws(static fn () => $offline->appendCandidate($T,$T,'REVIEW_DUE_CANDIDATE',str_repeat('a',64)), \DomainException::class, 'collapsed scope denied');
Assert::throws(static fn () => $offline->appendCandidate($T,$W,'RELEASED_INTELLIGENCE',str_repeat('a',64)), \DomainException::class, 'test persistence cannot create M10 release');
Assert::same(1,count($offline->candidateRecords($T,$W)),'refusal writes no event');
