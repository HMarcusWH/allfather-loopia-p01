<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * One outbound response, with the security headers set in the constructor rather than by whoever
 * remembers.
 *
 * The server-rendered surfaces keep the original no-script CSP. The authenticated React shell has
 * one separate response profile that permits only same-origin script/style/image/connect sources;
 * widening that one route must never silently widen login or the governed SSR surfaces with it.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $cookies Set-Cookie values, kept OUT of `$headers` because a header map
     *                              can hold one value per name and a response may set several
     *                              cookies at once — a login rotates two.
     */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly array $cookies = [],
    ) {
    }

    /** The same response, additionally setting one cookie. */
    public function withCookie(string $setCookieValue): self
    {
        return new self($this->status, $this->body, $this->headers, [...$this->cookies, $setCookieValue]);
    }

    /**
     * The same response with one more header — a NEW response, never a mutation.
     *
     * Immutability is what keeps the constructor's security headers meaningful: a response that
     * could be edited in place is a response whose CSP a later layer could quietly relax. Callers
     * get a copy or nothing.
     *
     * An existing name is overwritten rather than duplicated, because a header map holds one value
     * per name and two `Content-Security-Policy` headers are resolved by the browser taking the
     * INTERSECTION — a rule nobody wrote.
     */
    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => $value], $this->cookies);
    }

    /** @param array<string, string> $extra */
    public static function html(string $body, int $status = 200, array $extra = []): self
    {
        return new self($status, $body, [
            'Content-Type' => 'text/html; charset=utf-8',
            ...self::securityHeaders(),
            ...$extra,
        ]);
    }

    /**
     * The authenticated React shell. This profile is deliberately NOT the default HTML profile.
     * No inline script/style, no eval, no CDN and no cross-origin API are permitted.
     *
     * @param array<string, string> $extra
     */
    public static function operatorConsoleHtml(string $body, int $status = 200, array $extra = []): self
    {
        return new self($status, $body, [
            'Content-Type' => 'text/html; charset=utf-8',
            ...self::operatorConsoleSecurityHeaders(),
            ...$extra,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        return new self($status, json_encode($payload, JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/json; charset=utf-8',
            ...self::securityHeaders(),
        ]);
    }

    /** @param array<string, string> $extra */
    public static function redirect(string $location, array $extra = []): self
    {
        return new self(303, '', [
            'Location' => $location,
            ...self::securityHeaders(),
            ...$extra,
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        // Appended, never replaced: a response that rotates two cookies must emit both.
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        echo $this->body;
    }

    /** @return array<string, string> */
    private static function securityHeaders(): array
    {
        return [
            // No script at all, inline or otherwise. The server-rendered console stays scriptless.
            'Content-Security-Policy' =>
                "default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; "
                . "base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            // A governance console shows decisions that change. A cached page could show a viewer a
            // release that has since been withdrawn.
            'Cache-Control' => 'no-store, private',
        ];
    }

    /** @return array<string, string> */
    private static function operatorConsoleSecurityHeaders(): array
    {
        return [
            'Content-Security-Policy' =>
                "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; "
                . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ];
    }
}
