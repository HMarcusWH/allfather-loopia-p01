<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * The private index behind the opaque support reference.
 *
 * The record is intentionally tiny: request id, method, path and response status. It carries no
 * tenant/workspace/operator identifier, no email, no query string, no user agent, and no body.
 * The file lives outside public_html and deployment tooling is required to preserve runtime
 * storage rather than synchronize it from a checkout.
 */
final class RequestLog
{
    public const PREFIX = 'af-request';
    public const RELATIVE_PATH = 'storage/request.log';

    private const MAX_PATH = 200;

    /** The deterministic private sink an operator can grep. */
    public static function path(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'request.log';
    }

    /**
     * Record one completed HTTP request. Failure to log must never fail the response.
     *
     * Direct Kernel tests run under CLI and are not HTTP requests, so they do not touch the real
     * runtime log. `format()` and `path()` remain testable without creating state in the checkout.
     */
    public static function record(string $requestId, string $method, string $path, int $status): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $written = @file_put_contents(
            self::path(),
            self::format($requestId, $method, $path, $status) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
        if ($written !== false) {
            // Defence in depth. The primary boundary is that storage/ is outside public_html;
            // mode 0600 also keeps a permissive account umask from making the flat index broader.
            @chmod(self::path(), 0600);
        }
    }

    /**
     * Correlate an unexpected runtime exception with the request without exporting its message,
     * stack, SQL or governed values. This uses PHP's ordinary diagnostic sink, separate from the
     * support-reference index above.
     */
    public static function recordFailure(string $requestId, \Throwable $error): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        @error_log(sprintf(
            'af-runtime-error id=%s class=%s',
            self::sanitize($requestId, 64),
            self::sanitize($error::class, 160),
        ));
    }

    public static function format(string $requestId, string $method, string $path, int $status): string
    {
        return sprintf(
            '%s id=%s method=%s path=%s status=%d',
            self::PREFIX,
            self::sanitize($requestId, 64),
            self::sanitize($method, 10),
            self::sanitize($path, self::MAX_PATH),
            $status,
        );
    }

    private static function sanitize(string $value, int $maxLength): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        if (strlen($clean) > $maxLength) {
            $clean = substr($clean, 0, $maxLength) . '...';
        }

        return $clean === '' ? '-' : $clean;
    }
}
