<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * CSRF verification for state-changing requests.
 *
 * The session stores a DIGEST of the token, never the token, so a database read cannot yield a
 * usable one. The form carries the token; the comparison is constant-time.
 *
 * WHICH REQUESTS ARE CHECKED is decided by method, not by route. A route list is something a future
 * change can forget to update; "every request that is not a safe read" cannot be forgotten.
 */
final class Csrf
{
    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function required(string $method): bool
    {
        return !in_array($method, self::SAFE_METHODS, true);
    }

    /** Constant-time comparison of a presented token against the session's stored digest. */
    public static function verify(string $presentedToken, string $storedDigest): bool
    {
        if ($presentedToken === '' || $storedDigest === '') {
            return false;
        }

        return hash_equals($storedDigest, hash('sha256', $presentedToken));
    }
}
