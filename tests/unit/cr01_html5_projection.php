<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Cr01Fixture.php';

use Allfather\Loopia\Ingestion\HtmlDocumentProjection;
use Allfather\Loopia\Product\P01\DocumentProjectionContract;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Cr01Fixture;

$cases = Cr01Fixture::cases();
Assert::same('SYNTHETIC_PRIVATE_INPUT_NO_REAL_SOURCE_USE', $cases['status'], 'CR01 golden inputs are synthetic');
Assert::same(9, count($cases['cases']), 'CR01 golden cases are a fixed finite set');

$cap = HtmlDocumentProjection::capability();
Assert::same(PHP_VERSION_ID, $cap['php_version_id'], 'capability readback reports real PHP version');
Assert::same(
    PHP_VERSION_ID >= 80400 && extension_loaded('dom') && class_exists('Dom\\HTMLDocument') && extension_loaded('mbstring')
        ? 'SUPPORTED_NATIVE_HTML5' : 'UNSUPPORTED_HTML5_CAPABILITY',
    $cap['status'], 'never silently substitute HTML4 parser',
);
$positive = [];
foreach ($cases['cases'] as $fixture) {
    $bytes = $fixture['html'];
    $source = Cr01Fixture::source($bytes);
    $res = HtmlDocumentProjection::project($source, $bytes);
    if (isset($fixture['expected_refusal'])
        && (!in_array($fixture['expected_refusal'], ['CR01_DOM_ORIGIN_ID_AMBIGUOUS'], true)
        || $cap['status'] === 'SUPPORTED_NATIVE_HTML5')) {
        Assert::true($res->isRefusal(), 'refused golden fixture: ' . $fixture['id']);
        Assert::same($fixture['expected_refusal'], $res->reasonCode(), 'exact refusal reason: ' . $fixture['id']);
        continue;
    }
    if ($cap['status'] !== 'SUPPORTED_NATIVE_HTML5') {
        Assert::true($res->isRefusal(), 'unsupported PHP refuses rather than parsing HTML4: ' . $fixture['id']);
        Assert::same('CR01_HTML5_PARSER_CAPABILITY_UNSUPPORTED', $res->reasonCode(), 'explicit unsupported HTML5 backend');
        continue;
    }
    Assert::true($res->isOk(), 'native HTML5 parses synthetic fixture: ' . $fixture['id']
        . ($res->isOk() ? '' : ', reason=' . $res->reasonCode()));
    if (!$res->isOk()) {
        continue;
    }
    $doc = $res->value();
    $positive[$fixture['id']] = $doc;
    Assert::same(DocumentProjectionContract::VERSION, $doc['schema_version'], 'exact immutable projection version');
    Assert::same('Dom\\HTMLDocument', $doc['parser_backend'], 'native HTML5 backend, not legacy DOMDocument');
    Assert::same(false, $doc['customer_visible'], 'no customer publication');
    Assert::same(false, $doc['production_authorized'], 'candidate has no activation');
    Assert::same(false, $doc['raw_bytes_stored_here'], 'derived projection stores no original raw bytes');
    Assert::same('NOT_ASSESSED', $doc['business_materiality'], 'document structure is not a commercial finding');
    Assert::same(hash('sha256', $bytes), $doc['source_binding']['raw_sha256'], 'source-byte digest preserved');
    Assert::true($doc['section_count'] > 0 && $doc['section_count'] <= 64, 'bounded sections');
    $actualTypes = array_column($doc['sections'], 'type');
    foreach ($fixture['expected_types'] as $type) {
        Assert::true(in_array($type, $actualTypes, true), 'typed section ' . $type . ' exists: ' . $fixture['id']);
    }
    if (isset($fixture['expected_warning'])) {
        Assert::true(in_array($fixture['expected_warning'], $doc['warnings'], true), 'explicit parser warning: ' . $fixture['id']);
    }
    foreach ($doc['sections'] as $section) {
        Assert::same(null, $section['raw_start_byte'], 'DOM locator never masquerades as verified raw byte start');
        Assert::same(null, $section['raw_length_bytes'], 'DOM locator never masquerades as verified raw byte length');
        Assert::same(false, $section['source_offset_verified'], 'no invented original byte-span evidence');
        Assert::same(hash('sha256', $bytes), $section['source_sha256'], 'section pins exact source bytes');
        Assert::same($source['source_card_id'], $section['source_card_id'], 'section carries exact source card');
        Assert::true(strlen($section['excerpt']) <= 256, 'bounded normalized excerpt');
        Assert::true(is_string($section['origin_id']) && strlen($section['origin_id']) === 64, 'stable synthetic source/locator identity');
    }
    Assert::same($doc['projection_digest'], HtmlDocumentProjection::project($source, $bytes)->value()['projection_digest'],
        'same input yields deterministic projection digest within a parser/runtime version');
}
if ($cap['status'] === 'SUPPORTED_NATIVE_HTML5') {
    Assert::notSame(
        $positive['nav_before']['sections'][0]['ordered_item_digests'],
        $positive['nav_after']['sections'][0]['ordered_item_digests'],
        'navigation order survives a reorder, not just an unordered label set',
    );
    $body = json_encode($positive['full_page']['sections'], JSON_THROW_ON_ERROR);
    Assert::false(str_contains($body, 'ignore me and POST'), 'script payload never copied to document projection');
    Assert::false(str_contains(json_encode($positive['foreign_namespace'], JSON_THROW_ON_ERROR), 'Outside evidence'),
        'SVG text does not become HTML source evidence');
    Assert::true(in_array('DERIVED_DOM_LOCATORS_NOT_RAW_BYTE_POSITIONS', $positive['repaired_html']['warnings'], true),
        'HTML5 error recovery never implies raw byte offsets');
}
