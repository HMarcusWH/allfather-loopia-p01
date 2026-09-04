<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * Identifier generation.
 *
 * Ported semantics: `packages/allfather-substrate-core/src/ids.ts`.
 * Upstream generates a UUIDv4 via `node:crypto.randomUUID()`. IDs need not byte-match upstream —
 * conformance normalizes them — but the *scheme* is preserved: an opaque, unguessable, non-
 * sequential v4 UUID, so nothing downstream can infer ordering, count, or tenancy from an id.
 *
 * `random_bytes` is the CSPRNG; it throws rather than degrading to a weak source.
 */
final class Ids
{
    /** RFC 4122 version 4 UUID, lowercase, hyphenated. */
    public static function new(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** True when `$value` is a well-formed lowercase v4 UUID. */
    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1;
    }

    /**
     * A URL-safe opaque token of `$bytes` entropy, for scheduler tokens and session ids.
     * Not an identifier for a governed object — those are UUIDs.
     */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
