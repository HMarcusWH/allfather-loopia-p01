<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * The canonical `undefined` — a property that is absent rather than null.
 *
 * Upstream canonicalization omits `undefined` object properties and encodes `null` as `null`.
 * PHP has one absence value, so the distinction is carried by this marker: a property whose value
 * is `Absent::value()` is omitted from the canonical encoding; a property whose value is `null`
 * encodes as `null`. Collapsing the two would change digests.
 */
final class Absent
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function value(): self
    {
        return self::$instance ??= new self();
    }
}
