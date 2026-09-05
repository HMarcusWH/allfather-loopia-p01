<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P07A;

/**
 * The P07 outreach content law, in ONE place.
 *
 * Here rather than inside the arbiter because the M08 rule and the M11 content audit must return
 * the same answer. Two copies would be two chances to drift, and the one that drifts quietly is
 * the audit — which then reports clean forever.
 *
 * THESE ARE DETERMINISTIC MARKERS, NOT A CLASSIFIER, and the list is short on purpose. Each phrase
 * is one that makes a claim outreach copy is never entitled to make: a promised return, an absent
 * risk, a manufactured deadline, a fabricated selection. A longer list of judgement calls would be
 * a worse rule, because a rule an operator cannot predict is one they route around.
 *
 * The primary guarantees are elsewhere and are structural: consent, pseudonymization, single-use
 * artifacts, and the fact that this projection has no channel to send on. This is the content check
 * layered over those.
 */
final class ContentPolicy
{
    /** @var list<string> */
    public const PROHIBITED_MARKERS = [
        'guaranteed returns',
        'risk-free profit',
        'act now or lose',
        'you have been selected',
    ];

    /** Does this copy carry a prohibited marker? Case-insensitive, substring. */
    public static function hasProhibitedContent(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::PROHIBITED_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
