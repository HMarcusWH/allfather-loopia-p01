<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * The governed clock.
 *
 * Upstream writes `new Date().toISOString()` — always UTC, always millisecond precision, always
 * `Z`-suffixed. That exact spelling is load-bearing: timestamps are stored as text and compared
 * lexicographically for ordering and freshness, so a differently-formatted string would sort
 * wrongly against existing rows. `DateTimeInterface::ATOM` is NOT equivalent (`+00:00`, no
 * milliseconds).
 *
 * Time is injected rather than read from `time()` at each call site so tests can pin it and
 * replay can reproduce a run without touching the wall clock.
 */
class Clock
{
    /** Canonical `YYYY-MM-DDTHH:MM:SS.sssZ`, matching `Date.prototype.toISOString`. */
    public function now(): string
    {
        return self::format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public static function format(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Parse a canonical timestamp back. Returns null when the value is not canonical. */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $value, new \DateTimeZone('UTC'));
        if ($parsed === false) {
            return null;
        }

        // createFromFormat is lenient (it accepts "2026-13-45"); round-trip proves canonicity.
        return self::format($parsed) === $value ? $parsed : null;
    }

    /** True when `$value` is a canonical timestamp string. */
    public static function isCanonical(string $value): bool
    {
        return self::parse($value) !== null;
    }
}
