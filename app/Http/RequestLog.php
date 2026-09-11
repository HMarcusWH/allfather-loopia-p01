<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * The other end of the support reference.
 *
 * A reference a partner can quote and an operator cannot look up is a worse feature than no
 * reference at all: it invites somebody to ask for it and then leaves the person who receives it
 * with nothing to do. So each request is recorded once, and the record is what makes
 * `/support`'s "quote this and we will find it" true rather than aspirational.
 *
 * IT GOES TO THE PHP ERROR LOG, not to a table. Three reasons, and the third is the deciding one:
 * Loopia gives the deployment a log it already rotates; a governed table would need a migration,
 * an append-only trigger and a retention decision for data that is pure diagnostics; and a request
 * log that writes to the database cannot record the request where the DATABASE is what failed —
 * which is the case the reference exists for.
 *
 * WHAT IS RECORDED, and the list is closed on purpose:
 *   the reference, the method, the path, and the response status.
 *
 * WHAT IS NOT, and every omission is deliberate rather than an oversight to be filled in later:
 *   NO tenant, workspace, operator or user identifier. Correlating a reference to a person is the
 *   operator's job, done from the session and audit records that already exist under their own
 *   access rules — not a thing to duplicate into a flat file with no access control on it.
 *   NO query string. It is caller-supplied, and this projection's own reflected-input test exists
 *   because caller-supplied values reach places nobody expected.
 *   NO email, address or user agent. `auth_attempts` already records what is needed about sign-in
 *   attempts, hashed, in an append-only table.
 *   NO request or response body.
 *
 * THE PATH IS SANITIZED before it is written. A log line is a format an operator's tooling parses,
 * and a path containing a newline would let a caller forge a second line in it — the same class of
 * injection as the escaping rule that governs every value reaching a page.
 */
final class RequestLog
{
    /** The prefix an operator greps for. */
    public const PREFIX = 'af-request';

    /** Longest path fragment recorded. A path beyond this is truncated, never wrapped. */
    private const MAX_PATH = 200;

    /**
     * Record one completed request.
     *
     * CLI IS NOT A REQUEST. The Kernel is exercised directly by the test suite and could be by a
     * future maintenance command, and under a CLI SAPI `error_log` writes to stderr — so recording
     * there would mean a test run emitting one line per simulated request into the same stream the
     * results are read from. There is no HTTP request being served in that case, so there is
     * nothing to correlate. `format()` is public precisely so the line's shape stays under test
     * without the write.
     *
     * Failure to log never fails the request: `error_log` returns false when no destination is
     * writable, and a deployment whose log directory is misconfigured should still serve the page
     * it was asked for.
     */
    public static function record(string $requestId, string $method, string $path, int $status): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        @error_log(self::format($requestId, $method, $path, $status));
    }

    /**
     * The exact line `record` writes.
     *
     * Separated from the write so the sanitizing can be asserted directly: the interesting property
     * of this class is what the line CANNOT contain, and that is a property of the formatting.
     */
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

    /**
     * One field, reduced to something that cannot forge a log line.
     *
     * Control characters — newline first among them — are what turn one record into two. Everything
     * outside the printable range is replaced rather than stripped, so a path that was tampered
     * with still looks tampered with in the log instead of quietly reading as a shorter, valid one.
     */
    private static function sanitize(string $value, int $maxLength): string
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        if (strlen($clean) > $maxLength) {
            $clean = substr($clean, 0, $maxLength) . '...';
        }

        return $clean === '' ? '-' : $clean;
    }
}
