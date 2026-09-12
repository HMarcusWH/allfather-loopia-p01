<?php

declare(strict_types=1);

use Allfather\Loopia\Http\RequestLog;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);

Assert::same('storage/request.log', RequestLog::RELATIVE_PATH, 'the support reference has one deterministic private relative path');
Assert::true(is_dir($root . '/storage'), 'runtime storage exists as a repository sibling');
Assert::false(is_dir($root . '/public_html/storage'), 'runtime storage is never inside public_html');
Assert::contains('/storage/***', (string) file_get_contents($root . '/ops/deploy/staging-rsync-excludes.txt'), 'root rsync protects all runtime storage from --delete');

$requestLog = (string) file_get_contents($root . '/app/Http/RequestLog.php');
Assert::contains('FILE_APPEND | LOCK_EX', $requestLog, 'request-reference writes are append + per-write locked');
Assert::contains('@chmod(self::path(), 0600)', $requestLog, 'successful request-log writes tighten file permissions');
Assert::false(str_contains($requestLog, '@error_log(self::format('), 'the support-reference index no longer depends on an unspecified PHP error-log destination');

$kernel = (string) file_get_contents($root . '/app/Http/Kernel.php');
Assert::contains('catch (\\Throwable $error)', $kernel, 'Kernel catches unexpected dispatch failures before the support reference is lost');
Assert::contains("'reason_codes' => ['UNEXPECTED_ERROR']", $kernel, 'JSON runtime failures expose only an opaque reason code');

$front = (string) file_get_contents($root . '/public_html/index.php');
Assert::contains('set_exception_handler(', $front, 'the composition root has a last-resort exception boundary');
Assert::contains('RequestLog::recordFailure($request->requestId, $error);', $front, 'the outer boundary correlates the failure to the request without exposing it');
