<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

use Allfather\Loopia\Core\Ids;

/**
 * One opaque reference per HTTP request, so a beta partner can report a failure without
 * describing it and an operator can find it without asking them to.
 *
 * THE PROBLEM IT SOLVES. "The battlecards page said I am not allowed" is four different states —
 * no membership, no role, no tier, no active workspace — and the surfaces deliberately do not print
 * which, because a denial that explains itself to the denied is a probe. A reference closes the gap
 * from the other end: the partner quotes an opaque string, and the operator looks it up on a plane
 * the partner cannot see.
 *
 * WHAT IT MUST NOT BE, and these are requirements rather than preferences:
 *
 *   NOT DERIVED FROM ANYTHING. Not the tenant, not the workspace, not the user, not the email, not
 *   the peer address, not a timestamp with account meaning. A reference that encodes its subject is
 *   a subject in a form people paste into support tickets, chat logs and screenshots. This is 16
 *   bytes from the CSPRNG and nothing else, so it correlates ONE request and discloses nothing.
 *
 *   NOT SEQUENTIAL. A counter would let anyone holding two references infer how much traffic the
 *   deployment takes, and how much of it is theirs.
 *
 *   NOT CALLER-SUPPLIED. `X-Request-ID` on the way IN is not read. Honouring it would let a caller
 *   choose the string their own requests are recorded under — collide them deliberately, or plant
 *   one that looks like somebody else's. The server issues; the header is an output only.
 *
 * It is NOT a session identifier, NOT a token and NOT an authorization: holding one grants nothing,
 * which is precisely why it is safe to show on an error page.
 */
final class RequestId
{
    /** The response header the reference is carried on. */
    public const HEADER = 'X-Request-ID';

    /** 16 bytes -> 22 base64url characters. Enough that two requests never collide in practice. */
    private const ENTROPY_BYTES = 16;

    /** A fresh, opaque, server-side reference. */
    public static function issue(): string
    {
        return Ids::token(self::ENTROPY_BYTES);
    }

    /**
     * Whether a string has the shape this class issues.
     *
     * Used by the tests that assert opacity, and by anything that would otherwise be tempted to
     * render a reference it did not issue.
     */
    public static function isWellFormed(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{22}$/', $value) === 1;
    }
}
