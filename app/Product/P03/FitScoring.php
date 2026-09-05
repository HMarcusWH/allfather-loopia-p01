<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P03;

/**
 * The P03 fit scorers — pure, deterministic, and deliberately modest about what they measure.
 *
 * A FIT SCORE IS A REASON TO INVESTIGATE. It is a term-overlap measurement between what a customer
 * sells and what a competitor's page said. It is NOT a claim that the prospect wants to buy, and
 * nothing here can turn it into one: the output is a band and a count, and the vocabulary the band
 * is written into has no member meaning "intends to purchase".
 *
 * That separation is the invariant this whole module exists to hold. A product-fit hypothesis and
 * buyer intent are different claims about different things — the first about a match, the second
 * about a person's state of mind — and a scoring function that quietly promoted one into the other
 * would be manufacturing evidence about people from evidence about pages.
 *
 * Both scorers are PURE so a replay months later recomputes the same band from the same inputs. A
 * score that could not be recomputed would not be evidence of anything.
 */
final class FitScoring
{
    /**
     * Bumped on ANY semantic change to the ICP scorer.
     *
     * Replay compares stored scores against a recomputation, and an unbumped drift is a finding
     * rather than an explanation — so a change that forgets this constant is caught rather than
     * absorbed.
     */
    public const ICP_SCORER_VERSION = 'p03-icp-fit-scorer-v2';

    /**
     * Stopwords for the PRODUCT-SIGNAL scorer only.
     *
     * Deliberately NOT applied by the ICP tokenizer below. A tenant declaring an excluded segment
     * of "signal filter" means those two words; filtering "signal" out would silently degrade the
     * declaration to "filter" and match segments the tenant never excluded.
     *
     * @var list<string>
     */
    private const FIT_STOPWORDS = [
        'the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'has', 'have', 'will',
        'its', 'our', 'your', 'their', 'into', 'than', 'more', 'not', 'but', 'you', 'all', 'can',
        'out', 'new', 'now', 'signal',
    ];

    /**
     * Tokenize for the product-signal scorer: lowercase, alphanumeric split, three characters or
     * more, stopwords removed.
     *
     * @return list<string>
     */
    public static function fitTerms(string ...$texts): array
    {
        return self::tokenize($texts, self::FIT_STOPWORDS);
    }

    /**
     * Tokenize for the ICP scorer: the SAME rules, minus the stopword filter.
     *
     * It must stay semantically identical to the arbiter's own admission tokenizer, so a
     * tenant-declared segment matches the same way in the scorer's attribution and in the gate that
     * refuses on it. Two tokenizers that disagreed would let a segment be excluded by one and
     * admitted by the other.
     *
     * @return list<string>
     */
    public static function icpTerms(string ...$texts): array
    {
        return self::tokenize($texts, []);
    }

    /**
     * Score the overlap between what the customer sells and what the signal said.
     *
     * @param list<string> $productTerms
     * @param list<string> $signalTerms
     * @return array{kind: string, band?: string, overlap_count?: int, coverage?: float}
     */
    public static function scoreFit(array $productTerms, array $signalTerms): array
    {
        // Nothing to compare is not a weak match — it is no measurement at all, and reporting it as
        // UNFIT would assert a negative finding nobody established.
        if ($productTerms === [] || $signalTerms === []) {
            return ['kind' => 'INSUFFICIENT'];
        }

        $overlap = count(array_intersect($productTerms, $signalTerms));
        $coverage = $overlap / count($productTerms);

        $band = match (true) {
            $overlap === 0 => 'UNFIT',
            $overlap >= 3 && $coverage >= 0.5 => 'STRONG',
            $overlap >= 2 || $coverage >= 0.5 => 'PROBABLE',
            default => 'WEAK',
        };

        return ['kind' => 'SCORED', 'band' => $band, 'overlap_count' => $overlap, 'coverage' => $coverage];
    }

    /**
     * Score a prospect against the tenant's declared ideal-customer profile.
     *
     * SEPARATE from `scoreFit` on purpose. This records a visible component pinned to a profile
     * VERSION; the fit band stays the product-signal score. Folding them into one number would make
     * "why was this prospect surfaced?" unanswerable — a single score cannot say whether the match
     * came from the product, the profile, or an exclusion that should have fired.
     *
     * The scale is bounded and the exclusion is NEGATIVE: a matched excluded segment subtracts,
     * because a tenant that declared a segment out of scope has said something the positive
     * dimensions must not be able to outvote silently.
     *
     * @param array{
     *     icp_profile_version_id: string, industries: list<string>, geographies: list<string>,
     *     company_size_band: ?string, buyer_role_classes: list<string>, excluded_segments: list<string>
     * } $profileVersion
     * @param array{terms: list<string>, company_size_band?: ?string} $prospectFacts
     * @return array{scorer_version: string, icp_profile_version_id: string, score: int, matched_dimensions: list<string>}
     */
    public static function scoreIcpFit(array $profileVersion, array $prospectFacts): array
    {
        $matched = [];
        $score = 0;
        $terms = $prospectFacts['terms'];

        if (self::listHasWholeTermMatch($profileVersion['industries'], $terms)) {
            $score += 25;
            $matched[] = 'industries';
        }
        if (self::listHasWholeTermMatch($profileVersion['geographies'], $terms)) {
            $score += 20;
            $matched[] = 'geographies';
        }
        if (self::listHasWholeTermMatch($profileVersion['buyer_role_classes'], $terms)) {
            $score += 20;
            $matched[] = 'buyer_role_classes';
        }
        // The size band is scored ONLY when the prospect declares one. Absence means "do not score
        // this dimension" — never a default guess, because a guessed band would be an assertion
        // about the prospect that nothing observed.
        $declaredSize = $prospectFacts['company_size_band'] ?? null;
        if ($profileVersion['company_size_band'] !== null && $declaredSize !== null
            && $profileVersion['company_size_band'] === $declaredSize
        ) {
            $score += 20;
            $matched[] = 'company_size_band';
        }
        if (self::listHasWholeTermMatch($profileVersion['excluded_segments'], $terms)) {
            $score -= 35;
            $matched[] = 'excluded_segments';
        }

        return [
            'scorer_version' => self::ICP_SCORER_VERSION,
            'icp_profile_version_id' => $profileVersion['icp_profile_version_id'],
            'score' => max(-35, min(85, $score)),
            'matched_dimensions' => $matched,
        ];
    }

    /**
     * Does any declared entry match, with EVERY one of its terms present?
     *
     * Whole-entry matching, not any-term: a declared "public sector" must not match a prospect that
     * merely mentions "sector". A tenant's declaration is a phrase, and honouring only half of it
     * would exclude or include on a term they never wrote.
     *
     * @param list<string> $list
     * @param list<string> $facts
     */
    private static function listHasWholeTermMatch(array $list, array $facts): bool
    {
        foreach ($list as $entry) {
            $entryTerms = self::icpTerms($entry);
            if ($entryTerms === []) {
                continue;
            }
            if (array_diff($entryTerms, $facts) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $texts
     * @param list<string> $stopwords
     * @return list<string>
     */
    private static function tokenize(array $texts, array $stopwords): array
    {
        $terms = [];
        foreach ($texts as $text) {
            foreach (preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [] as $raw) {
                $token = trim($raw);
                if (strlen($token) >= 3 && !in_array($token, $stopwords, true)) {
                    $terms[$token] = true;
                }
            }
        }

        return array_keys($terms);
    }
}
