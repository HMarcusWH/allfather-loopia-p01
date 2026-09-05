<?php

declare(strict_types=1);

namespace Allfather\Loopia\Core;

/**
 * The single canonical action-class ordering.
 *
 * Every gate that compares a requested action class to a ceiling MUST resolve rank through this
 * class: a second ordering is a second authority plane, and the two would drift.
 *
 * `FORBIDDEN` and any unrecognized value have NO rank, so callers fail closed. There is
 * deliberately no default rank — an unknown ceiling must never compare as permissive.
 */
final class ActionClass
{
    /** @var list<string> */
    public const CANONICAL = [
        'OBSERVE',
        'ANALYZE',
        'RELEASE_INTELLIGENCE',
        'PREPARE_EXTERNAL_ACTION',
        'EXECUTE_CONSEQUENTIAL_ACTION',
    ];

    /**
     * The classes this projection can dispatch at all.
     *
     * The two external-effect classes are absent, and their absence is enforced in three places
     * that do not depend on each other: M03 refuses to issue a contract granting them, M02 lists
     * the matching permissions as EXCLUDED, and the M06 V1 scope filter routes any request for
     * them to REQUIRE_APPROVAL — which, with no M09 approval plane in this projection, is
     * terminal.
     *
     * @var list<string>
     */
    public const DISPATCHABLE_IN_PROJECTION = ['OBSERVE', 'ANALYZE'];

    /** 1-based rank, or null for FORBIDDEN and anything outside the canonical set. */
    public static function rank(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        $index = array_search($value, self::CANONICAL, true);

        return $index === false ? null : $index + 1;
    }

    public static function isCanonical(mixed $value): bool
    {
        return self::rank($value) !== null;
    }

    /**
     * True only when BOTH sides are canonical AND the request is at or below the ceiling.
     *
     * An unknown or FORBIDDEN value on either side yields false, so the caller refuses.
     */
    public static function withinCeiling(mixed $requested, mixed $ceiling): bool
    {
        $requestedRank = self::rank($requested);
        $ceilingRank = self::rank($ceiling);

        return $requestedRank !== null && $ceilingRank !== null && $requestedRank <= $ceilingRank;
    }
}
