<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P05;

/**
 * The two P05 content laws, in ONE place.
 *
 * They are here rather than inside the arbiter or the composer because THREE callers need exactly
 * the same answer: the M08 admission rule, the M11 content-compliance audit, and any surface that
 * wants to explain a refusal. Three copies of a regex is three chances for the gate and the audit
 * to disagree about what a dark pattern is — and the one that disagrees quietly is the audit,
 * which then reports clean forever.
 *
 * WHAT THESE ARE:
 *
 *   COMPARATIVE_CLAIM   Does this copy make a claim ABOUT A COMPETITOR? Not a judgement of whether
 *                       the claim is true — a deterministic marker that a comparison is being
 *                       made, which is what triggers the citation requirement.
 *
 *   FORBIDDEN           Does this copy carry a dark pattern, a deception marker, or sensitive-trait
 *                       targeting? Each is a thing marketing must not do regardless of evidence:
 *                       no citation makes fake scarcity honest, and no legal basis makes targeting
 *                       by medical condition acceptable.
 *
 * WHAT THESE ARE NOT: a complete classifier of either. They are deterministic markers, and they say
 * so. The primary guarantees are structural — a draft is a DRAFT, there is no publish path, and
 * every comparative claim must cite governed evidence — and these patterns are the content check
 * layered over them, not a substitute for them.
 */
final class MarketingPatterns
{
    /**
     * Markers that copy is comparing against a competitor.
     *
     * Deliberately broad on the FIRST pattern: naming a competitor at all is enough to require a
     * citation. Over-triggering here costs a citation nobody minds writing; under-triggering lets
     * an uncited comparative claim reach a customer.
     *
     * @var list<string>
     */
    public const COMPARATIVE_CLAIM = [
        '/\b(?:competitor|rival|alternative|vs\.?|versus)\b/i',
        '/\b(?:better|best|faster|cheaper|stronger|superior|outperform(?:s|ed)?|beats?)\s+(?:than|vs\.?|versus)\b/i',
        '/\b(?:unlike|compared\s+to)\b/i',
    ];

    /**
     * Markers of copy that must not ship whatever it cites.
     *
     * Four groups, and they are different prohibitions rather than four spellings of one:
     * self-described deception, manufactured urgency, targeting BY a protected trait, and
     * justifying an action BY one.
     *
     * @var list<string>
     */
    public const FORBIDDEN = [
        '/\b(?:dark\s*pattern|deceptive|mislead(?:ing)?|trick(?:ed)?\s+users?|fake\s+(?:scarcity|timer|review))\b/i',
        '/\b(?:only\s+\d+\s+(?:minutes|seconds)\s+left|act\s+now\s+or\s+lose|pressure\s+them|force\s+the\s+click)\b/i',
        '/\btarget\s+(?:pregnant|disabled|minorities|religion|religious|ethnicity|race|medical|health\s+condition|debt|addiction)\b/i',
        '/\b(?:based\s+on|because\s+of)\s+(?:race|religion|ethnicity|disability|pregnancy|medical\s+condition|health\s+condition)\b/i',
    ];

    /** Does this copy make a comparative claim? */
    public static function hasComparativeClaim(string $content): bool
    {
        return self::matchesAny($content, self::COMPARATIVE_CLAIM);
    }

    /** Does this copy carry a forbidden marketing pattern? */
    public static function hasForbiddenPattern(string $content): bool
    {
        return self::matchesAny($content, self::FORBIDDEN);
    }

    /** @param list<string> $patterns */
    private static function matchesAny(string $content, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }
}
