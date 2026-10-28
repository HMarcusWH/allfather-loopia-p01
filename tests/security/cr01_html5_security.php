<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Cr01Fixture.php';

use Allfather\Loopia\Ingestion\HtmlDocumentProjection;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Cr01Fixture;

$valid = Cr01Fixture::cases()['cases'][0]['html'];
$source = Cr01Fixture::source($valid);

$wrong = $source;
$wrong['raw_sha256'] = str_repeat('0', 64);
Assert::same('CR01_ORIGINAL_BYTE_DIGEST_MISMATCH', HtmlDocumentProjection::project($wrong, $valid)->reasonCode(), 'wrong original bytes refused');
$wrong = $source;
$wrong['input_kind'] = 'REAL_NETWORK_CAPTURE';
Assert::same('CR01_NATIVE_OWNER_BINDING_NOT_IMPLEMENTED', HtmlDocumentProjection::project($wrong, $valid)->reasonCode(), 'self-declared live source right cannot unlock parser');
$wrong = $source;
$wrong['tenant_id'] = $source['workspace_id'];
Assert::same('CR01_SCOPE_OR_ORIGIN_AMBIGUOUS', HtmlDocumentProjection::project($wrong, $valid)->reasonCode(), 'collapsed tenant/workspace refused');
$wrong = $source;
$wrong['known_at'] = '2026-10-20T00:00:00.000Z';
Assert::same('CR01_SOURCE_CLOCK_ORDER', HtmlDocumentProjection::project($wrong, $valid)->reasonCode(), 'knowledge cannot precede observation');
$wrong = $source;
$wrong['media_type'] = 'application/pdf';
Assert::same('CR01_MEDIA_OR_ENCODING_UNSUPPORTED', HtmlDocumentProjection::project($wrong, $valid)->reasonCode(), 'unreviewed MIME rejected');
$wrong = $source;
$wrong['source_reference_url'] = 'https://unapproved.invalid/path';
Assert::same('CR01_SOURCE_ENVELOPE_INVALID', HtmlDocumentProjection::project($wrong, $valid)->reasonCode(), 'unknown origin field cannot smuggle URL or rights');

$oversized = str_repeat('Z', 131073);
Assert::same('CR01_INPUT_BYTE_LIMIT', HtmlDocumentProjection::project(Cr01Fixture::source($oversized), $oversized)->reasonCode(), 'oversized HTML is refused');
$nul = '<html>' . chr(0) . '</html>';
Assert::same('CR01_INPUT_NUL_FORBIDDEN', HtmlDocumentProjection::project(Cr01Fixture::source($nul), $nul)->reasonCode(), 'null byte refused');
$badEncoding = '<html>' . chr(255) . '</html>';
if (extension_loaded('mbstring')) {
    Assert::same('CR01_INPUT_UTF8_INVALID', HtmlDocumentProjection::project(Cr01Fixture::source($badEncoding), $badEncoding)->reasonCode(), 'invalid UTF-8 refused');
}
$many = '<!doctype html><html><body>' . str_repeat('<i>sample</i>', 3501) . '</body></html>';
Assert::same('CR01_MARKUP_COMPLEXITY_LIMIT', HtmlDocumentProjection::project(Cr01Fixture::source($many), $many)->reasonCode(), 'unbounded markup refuses before DOM');
$xml = '<?xml version="1.0"?><html><body>X</body></html>';
Assert::same('CR01_XML_NOT_HTML5', HtmlDocumentProjection::project(Cr01Fixture::source($xml), $xml)->reasonCode(), 'XML namespace parser not substituted');
$meta = '<html><head><meta charset="iso-8859-1"></head><body>x</body></html>';
Assert::same('CR01_META_CHARSET_UNSUPPORTED', HtmlDocumentProjection::project(Cr01Fixture::source($meta), $meta)->reasonCode(), 'non-UTF-8 meta declaration refused');
if (HtmlDocumentProjection::capability()['status'] === 'SUPPORTED_NATIVE_HTML5') {
    $manySections = '<!doctype html><html><body><main>' . str_repeat('<section>separate claim</section>', 65) . '</main></body></html>';
    Assert::same('CR01_SECTION_LIMIT', HtmlDocumentProjection::project(Cr01Fixture::source($manySections), $manySections)->reasonCode(), '65 sections cannot be silently truncated to 64');
    $nav = '<!doctype html><html><body><nav>' . str_repeat('<a>Item</a>', 49) . '</nav></body></html>';
    Assert::same('CR01_NAV_ITEM_LIMIT', HtmlDocumentProjection::project(Cr01Fixture::source($nav), $nav)->reasonCode(), 'navigation item cap enforced');
    $deep = '<!doctype html><html><body>' . str_repeat('<div>', 53) . 'inside' . str_repeat('</div>', 53) . '</body></html>';
    Assert::same('CR01_DOM_DEPTH_OR_SELECTOR_LIMIT', HtmlDocumentProjection::project(Cr01Fixture::source($deep), $deep)->reasonCode(), 'deep repaired DOM refused');
}
