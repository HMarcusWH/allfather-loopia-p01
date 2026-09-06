<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * One outbound response, with the security headers set in the constructor rather than by whoever
 * remembers.
 *
 * The CSP is deliberately strict: no inline script, no external origin, nothing framed. The console
 * loads one stylesheet and no JavaScript at all — a governance surface that cannot execute
 * arbitrary script is a governance surface with one fewer way to lie about what it shows.
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

    /** @param array<string, string> $extra */
    public static function html(string $body, int $status = 200, array $extra = []): self
    {
        return new self($status, $body, [
            'Content-Type' => 'text/html; charset=utf-8',
            ...self::securityHeaders(),
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
            // No script at all, inline or otherwise. The console is server-rendered.
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
}
