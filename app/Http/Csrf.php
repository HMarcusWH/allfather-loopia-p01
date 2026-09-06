<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

use Allfather\Loopia\Core\Ids;

/**
 * CSRF verification for state-changing requests.
 *
 * WHICH REQUESTS ARE CHECKED is decided by method, not by route. A route list is something a future
 * change can forget to update; "every request that is not a safe read" cannot be forgotten.
 *
 * THE SERVER NEVER STORES A USABLE TOKEN. The token lives in the caller's cookie; what the server
 * keeps is its digest — in `platform_sessions.csrf_token_hash` once there is a session, and nowhere
 * at all before then. So a database read yields nothing a forger can present, and the comparison is
 * constant-time either way.
 *
 * TWO POSTURES, because a login has no session to bind to yet:
 *
 *   AFTER SIGN-IN the presented token is checked against the SESSION's stored digest. The cookie is
 *   what carries the token to the browser, but the authority is the server-side session row: a
 *   forged cookie proves nothing, because its digest will not match the session's.
 *
 *   BEFORE SIGN-IN there is no session row, so the only thing available is the double-submit pair —
 *   the token in the form must equal the token in the cookie. That is genuinely weaker, and the
 *   `__Host-` prefix is what keeps it honest: a cookie with that prefix can only be set by a secure
 *   origin for this exact host and path, so a sibling subdomain cannot plant one. This posture is
 *   reachable ONLY where there is no session, and it ends the moment one exists.
 */
final class Csrf
{
    /**
     * The `__Host-` prefix is load-bearing, not decoration. A browser accepts a cookie under this
     * name ONLY when it is Secure, Path=/, and carries no Domain — which is exactly what makes the
     * pre-auth double-submit check meaningful: no other host, including a sibling subdomain, can
     * write it.
     */
    public const COOKIE_NAME = '__Host-af_csrf';

    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Shortest token this will treat as a token at all; the issued length is far above it. */
    private const MIN_TOKEN_LENGTH = 32;

    public static function required(string $method): bool
    {
        return !in_array($method, self::SAFE_METHODS, true);
    }

    /** A fresh token, for a pre-auth form or a newly established session. */
    public static function issue(): string
    {
        return Ids::token(32);
    }

    public static function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Constant-time comparison of a presented token against a stored digest. */
    public static function verify(string $presentedToken, string $storedDigest): bool
    {
        if (strlen($presentedToken) < self::MIN_TOKEN_LENGTH || $storedDigest === '') {
            return false;
        }

        return hash_equals($storedDigest, self::digest($presentedToken));
    }

    /**
     * The Set-Cookie value carrying a token to the caller.
     *
     * SameSite=Strict is what makes the pre-auth posture defensible: the cookie is not sent on any
     * cross-site request at all, so a forged form cannot even reach the comparison with the
     * matching cookie attached.
     */
    public static function cookie(string $token): string
    {
        return sprintf('%s=%s; Path=/; HttpOnly; SameSite=Strict; Secure', self::COOKIE_NAME, $token);
    }

    /** The Set-Cookie value that clears it. */
    public static function clearedCookie(): string
    {
        return sprintf('%s=; Path=/; HttpOnly; SameSite=Strict; Secure; Max-Age=0', self::COOKIE_NAME);
    }
}
