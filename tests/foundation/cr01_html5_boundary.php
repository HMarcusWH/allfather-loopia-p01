<?php

declare(strict_types=1);

use Allfather\Loopia\Ingestion\HtmlDocumentProjection;
use Allfather\Loopia\Product\P01\DocumentProjectionContract;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$paths = [
    'app/Ingestion/HtmlDocumentProjection.php',
    'app/Product/P01/HtmlSectionExtractor.php',
    'app/Product/P01/DocumentProjectionContract.php',
];
foreach ($paths as $path) {
    Assert::true(is_file($root . '/' . $path), 'CR01 implementation exists: ' . $path);
    $src = (string) file_get_contents($root . '/' . $path);
    foreach (['CurlHttpsTransport', 'DnsHostResolver', 'OpenAIResponsesTransport', 'PDO(', 'curl_exec(', 'file_get_contents('] as $forbidden) {
        Assert::false(str_contains($src, $forbidden), 'CR01 interpreter has no transport/credential/database/filesystem reads: ' . $path);
    }
}
Assert::same('cr01.document-projection.v1', DocumentProjectionContract::VERSION, 'projection vocabulary remains versioned');
Assert::same(8, count(DocumentProjectionContract::TYPES), 'full closed section vocabulary');
Assert::same(64, DocumentProjectionContract::LIMITS['max_sections'], 'bounded section count');
Assert::same(131072, DocumentProjectionContract::LIMITS['max_input_bytes'], 'bounded input bytes');
Assert::same(
    PHP_VERSION_ID >= 80400 && extension_loaded('dom') && class_exists('Dom\\HTMLDocument') && extension_loaded('mbstring')
        ? 'SUPPORTED_NATIVE_HTML5' : 'UNSUPPORTED_HTML5_CAPABILITY',
    HtmlDocumentProjection::capability()['status'],
    'version/extension aware backend, never HTML4 fallback',
);
