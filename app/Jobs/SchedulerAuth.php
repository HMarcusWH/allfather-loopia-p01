<?php

declare(strict_types=1);

namespace Allfather\Loopia\Jobs;

use Allfather\Loopia\Support\Env;

/**
 * The scheduler's front door.
 *
 * The tick endpoint is the one surface that drives governed work with no operator behind it, so
 * the credential in front of it is the whole of its authority. Five rules, and each is a way this
 * would otherwise leak:
 *
 *   THE COMPARISON IS CONSTANT-TIME, against a stored DIGEST. `.env` may hold either
 *   `SCHEDULER_TOKEN` (the token itself) or `SCHEDULER_TOKEN_HASH` (its sha256), and the hash form
 *   is preferred precisely because a `.env` read then yields nothing presentable.
 *
 *   A MISSING CREDENTIAL IS A REFUSAL, never a default. An unconfigured deployment must not have an
 *   open scheduler; it must have a scheduler nobody can call.
 *
 *   THE TOKEN COMES FROM A HEADER, never the query string. A URL-cron entry sits in a scheduler's
 *   configuration, in access logs and in any Referer it generates, and a token in a URL is a token
 *   in all three.
 *
 *   NOTHING ECHOES IT. The refusal names no credential, no prefix and no length — `Env` already
 *   redacts both keys from every diagnostic surface, and this class adds no second route to them.
 *
 *   IT IS NOT A SESSION. No cookie is read and none is set, so this surface has no ambient
 *   authority to be borrowed by a browser and nothing for a cross-site request to abuse.
 */
final class SchedulerAuth
{
    public const HEADER = 'X-Scheduler-Token';

    /** The `$_SERVER` key the header arrives under. */
    public const SERVER_KEY = 'HTTP_X_SCHEDULER_TOKEN';

    /** Shortest token this will treat as a token at all. */
    private const MIN_TOKEN_LENGTH = 32;

    /**
     * Whether a presented token is the configured one.
     *
     * @return array{authorized: bool, reason_code: ?string}
     */
    public static function verify(Env $env, string $presented): array
    {
        $expectedDigest = $env->has('SCHEDULER_TOKEN_HASH')
            ? strtolower(trim((string) $env->get('SCHEDULER_TOKEN_HASH')))
            : ($env->has('SCHEDULER_TOKEN') ? hash('sha256', (string) $env->get('SCHEDULER_TOKEN')) : null);

        if ($expectedDigest === null || $expectedDigest === '') {
            // Fail CLOSED on absence. A deployment that forgot to configure the scheduler has one
            // nobody can call, which is the only safe reading of "no credential is set".
            return ['authorized' => false, 'reason_code' => 'SCHEDULER_CREDENTIAL_NOT_CONFIGURED'];
        }
        if (strlen($presented) < self::MIN_TOKEN_LENGTH) {
            return ['authorized' => false, 'reason_code' => 'SCHEDULER_TOKEN_MISSING_OR_MALFORMED'];
        }
        if (!hash_equals($expectedDigest, hash('sha256', $presented))) {
            return ['authorized' => false, 'reason_code' => 'SCHEDULER_TOKEN_REJECTED'];
        }

        return ['authorized' => true, 'reason_code' => null];
    }
}
