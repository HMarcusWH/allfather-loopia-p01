<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * One inbound HTTP request, read once and treated as data thereafter.
 *
 * Superglobals are read HERE and nowhere else, so every other layer receives values through a typed
 * surface rather than reaching for `$_GET` wherever it happens to need one. That is not tidiness:
 * it means there is a single place to audit for what the outside world can influence.
 */
final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $form
     * @param array<string, string> $cookies
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $form,
        public readonly array $cookies,
        public readonly string $ip,
        public readonly ?string $userAgent,
    ) {
    }

    /** @param array<string, mixed> $server */
    public static function fromGlobals(array $server, array $get, array $post, array $cookie): self
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? rtrim($path, '/') ?: '/' : '/',
            self::strings($get),
            self::strings($post),
            self::strings($cookie),
            // The DIRECT peer address only. `X-Forwarded-For` is caller-controlled, and trusting it
            // would let anybody choose the address their login attempts are rate-limited against.
            (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0'),
            isset($server['HTTP_USER_AGENT']) ? (string) $server['HTTP_USER_AGENT'] : null,
        );
    }

    public function input(string $key, string $default = ''): string
    {
        return $this->form[$key] ?? $this->query[$key] ?? $default;
    }

    public function cookie(string $key): ?string
    {
        return $this->cookies[$key] ?? null;
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            // Scalars only. A nested array where a string is expected is a shape the caller chose,
            // and coercing it would invent a value nobody sent.
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
