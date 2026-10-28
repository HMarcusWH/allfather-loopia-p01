<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Cr02Fixture.php';

use Allfather\Loopia\Ingestion\HtmlDocumentProjection;
use Allfather\Loopia\Product\P01\SemanticDelta;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Cr02Fixture;

$cases = Cr02Fixture::cases()['cases'];
$cap = HtmlDocumentProjection::capability();
if ($cap['status'] !== 'SUPPORTED_NATIVE_HTML5') {
    // The mandatory PHP 8.2 lane proves EXPLICIT parser unavailability;
    // the independently required PHP 8.5 lane proves actual positive parsing.
    $m = Cr02Fixture::syntheticProjection($cases[0], 'before')['source_binding'];
    $m['input_kind'] = 'SYNTHETIC_PRIVATE_EVIDENCE_FIXTURE';
    $m['media_type'] = 'text/html';
    $m['encoding'] = 'UTF-8';
    $r = HtmlDocumentProjection::project($m, $cases[0]['before_html']);
    Assert::true($r->isRefusal(), 'PHP 8.2 native HTML5 must not silently use HTML4');
    Assert::same('CR01_HTML5_PARSER_CAPABILITY_UNSUPPORTED', $r->reasonCode(),
        'explicit capability refusal, not an invented native positive PASS');
    return;
}
Assert::true(PHP_VERSION_ID >= 80400, 'real HTML5 backend requires at least PHP 8.4');
Assert::same('Dom\\HTMLDocument', $cap['backend'], 'real native HTML5 backend');

foreach ($cases as $case) {
    $versions = [];
    foreach (['before','after'] as $side) {
        $fixture = Cr02Fixture::syntheticProjection($case, $side);
        $source = $fixture['source_binding'];
        $source['input_kind'] = 'SYNTHETIC_PRIVATE_EVIDENCE_FIXTURE';
        $source['media_type'] = 'text/html';
        $source['encoding'] = 'UTF-8';
        $result = HtmlDocumentProjection::project($source, $case[$side . '_html']);
        Assert::true($result->isOk(), 'actual native CR01 parses ' . $case['id'] . '/' . $side
            . ($result->isOk() ? '' : ', reason=' . $result->reasonCode()));
        if (!$result->isOk()) {
            continue 2;
        }
        $versions[$side] = $result->value();
        Assert::same('Dom\\HTMLDocument', $versions[$side]['parser_backend'],
            'source projection came from native HTML5 not PHP8.2 fixture emulation');
        Assert::same(hash('sha256', $case[$side . '_html']), $versions[$side]['source_binding']['raw_sha256'],
            'actual parser pins source body bytes');
    }
    $out = SemanticDelta::compare($versions['before'], $versions['after']);
    Assert::true($out->isOk(), 'real HTML5 projection pair reaches CR02: ' . $case['id']
        . ($out->isOk() ? '' : ', reason=' . $out->reasonCode()));
    if (!$out->isOk()) {
        continue;
    }
    $v = $out->value();
    Assert::true(in_array($case['expected_kind'], array_column($v['deltas'], 'change_kind'), true),
        'native CR01-to-CR02 real positive delta: ' . $case['id'] . '/' . $case['expected_kind']);
    Assert::same($case['expected_structural'] ?? (
        $case['expected_kind'] === 'ORDER_ONLY' ? 'ORDER_ONLY' : (
            $case['expected_kind'] === 'SECTION_UNCHANGED' ? 'NO_TYPED_CHANGE' : 'TYPED_CHANGE'
        )), $v['structural_classification'],
        'actual native HTML5 preserves structural distinction: ' . $case['id']);
    Assert::same(false, $v['customer_release_authorized'],
        'native HTML5 positive remains synthetic, never customer authority');
    Assert::same(false, $v['provider_or_model_authorized'],
        'HTML5 positive never dispatches provider');
    if ($case['id'] === 'navigation-reorder') {
        Assert::same(1, $v['counts']['order_only'], 'actual DOM nav sequence detected');
        Assert::same(0, $v['counts']['sensitive_token_changes'],
            'reordering nav does not become a spurious PRICE_CHANGED');
    }
    if ($case['id'] === 'excerpt-hidden-price') {
        Assert::true(in_array('BOUNDED_EXCERPT_COVERAGE', $v['unknown_dimensions'], true),
            'actual native excerpt truncation retains incomplete coverage');
    }
}
