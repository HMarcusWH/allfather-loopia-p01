<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Http\Kernel;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\RequestId;
use Allfather\Loopia\Http\RequestLog;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}

$clock = Harness::clock();
$kernel = new Kernel(
    $db,
    $clock,
    new Authenticator($db, $clock),
    new View(dirname(__DIR__, 2) . '/app/Http/templates'),
);

$marker = 'THIS_EXCEPTION_MUST_NEVER_REACH_THE_CALLER';
$kernel->route('GET', '/login', static function () use ($marker): never {
    throw new RuntimeException($marker);
});
$kernel->route('GET', '/healthz', static function () use ($marker): never {
    throw new RuntimeException($marker);
}, 'JSON_401');

$request = static fn (string $path): Request => Request::fromGlobals(
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '198.51.100.9'],
    [],
    [],
    [],
);

$html = $kernel->handle($request('/login'));
Assert::same(500, $html->status, 'an unexpected HTML handler exception becomes a safe 500');
Assert::true(isset($html->headers[RequestId::HEADER]), 'the safe HTML 500 still carries X-Request-ID');
Assert::false(str_contains($html->body, $marker), 'the exception message never reaches the HTML response');
Assert::false(str_contains($html->body, 'RuntimeException'), 'the exception class never reaches the HTML response');
Assert::contains('Support reference', $html->body, 'the HTML failure tells the caller what to quote');

$json = $kernel->handle($request('/healthz'));
Assert::same(500, $json->status, 'an unexpected JSON handler exception becomes a safe 500');
Assert::true(isset($json->headers[RequestId::HEADER]), 'the safe JSON 500 still carries X-Request-ID');
Assert::contains('application/json', $json->headers['Content-Type'] ?? '', 'the JSON route stays JSON on failure');
$payload = json_decode($json->body, true, 32, JSON_THROW_ON_ERROR);
Assert::same('UNAVAILABLE', $payload['status'] ?? null, 'the JSON failure names only safe availability state');
Assert::equalsList(['UNEXPECTED_ERROR'], $payload['reason_codes'] ?? [], 'the JSON failure has one opaque reason code');
Assert::false(array_key_exists('data', $payload), 'an unexpected JSON failure carries no governed data key');
Assert::false(str_contains($json->body, $marker), 'the exception message never reaches JSON');

$root = dirname(__DIR__, 2);
Assert::same(
    realpath($root . '/storage') . DIRECTORY_SEPARATOR . 'request.log',
    RequestLog::path(),
    'the request reference has one deterministic private log path',
);
Assert::false(
    str_starts_with(RequestLog::path(), realpath($root . '/public_html') . DIRECTORY_SEPARATOR),
    'the request log is outside the served tree',
);
$line = RequestLog::format("id\nforged", 'GET', "/safe\nforged", 500);
Assert::false(str_contains($line, "\n"), 'control characters cannot forge a second request-log line');
Assert::contains('status=500', $line, 'the private request index records the final response status');
