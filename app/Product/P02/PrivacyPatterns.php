<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

/**
 * The P02 privacy detectors — content-based, not flag-based.
 *
 * A twin is a structured picture of an organization. These detectors exist because the difference
 * between a legitimate business fact and a prohibited one is often visible only in what the field
 * is CALLED and what it CONTAINS, and asking the writer to declare which they meant would put the
 * boundary in the hands of the party crossing it.
 *
 * FOUR PROHIBITIONS, and the reason each exists is different:
 *
 *   PERSON OVERREACH      A field about a person's psychology, emotions, health, politics, religion,
 *                         sexuality, ethnicity or private life. These are not business facts about
 *                         an organization, and a system that recorded them would be building a
 *                         dossier on a human being under cover of modelling a company.
 *
 *   EMPLOYEE SCORING      A field that rates an individual employee — loyalty, productivity,
 *                         diligence, responsiveness. This is the covert-telemetry line: the product
 *                         may model that an organization has a finance function, and may not score
 *                         the person who runs it.
 *
 *   BUYER INTENT AS FACT  A field asserting someone intends to buy, recorded as a KNOWN FACT. As a
 *                         hypothesis it is fine — hypotheses are what this system trades in. As a
 *                         certainty it is a claim about a person's mind that nothing observed.
 *
 *   INDIVIDUAL IDENTIFIER An email, handle or phone number in a field value. A ROLE CLASS is
 *                         permitted; the person holding it is not the subject of this system.
 *
 * The patterns are SUBSTRING matches on a normalized key, deliberately broad. A detector that
 * missed "psychographic" because it only knew "psychology" would be a detector in name only, and
 * the cost of a false positive here is an operator renaming a field.
 */
final class PrivacyPatterns
{
    /**
     * Fields about a person's inner life or protected characteristics.
     *
     * Stems rather than whole words, so `psycholog` catches psychology, psychological and
     * psychologist; `disabilit` catches disability and disabilities.
     *
     * @var list<string>
     */
    public const PERSON_OVERREACH = [
        'psycholog', 'psychograph', 'personality', 'emotion', 'mood', 'sentiment',
        'sensitive_trait', 'health', 'medical', 'disabilit', 'politic', 'religio',
        'sexual', 'orientation', 'ethnic', 'private_life', 'privatelife', 'vulnerab',
    ];

    /**
     * Fields that rate an individual employee.
     *
     * `surveillance` and `covert` are here because a field named for the practice is asserting the
     * practice, whatever it holds.
     *
     * @var list<string>
     */
    public const EMPLOYEE_SCORING = [
        'loyalty', 'productivity', 'diligence', 'responsiveness_score', 'surveillance', 'covert',
    ];

    /** @var list<string> */
    public const BUYER_INTENT = [
        'buyer_intent', 'intent_to_buy', 'ready_to_buy', 'will_buy', 'purchase_intent',
    ];

    /**
     * Value statuses that assert a field as SUPPORTED — sourced, provided, or derived from
     * something real.
     *
     * A field outside this set is a claim the twin makes without support, which is a different
     * problem from a prohibited one: `UNSUPPORTED_CLAIM` and `INFERRED_HYPOTHESIS` are honest
     * labels, and the twin may carry them as long as it says so.
     *
     * @var list<string>
     */
    public const SUPPORTED_STATUSES = [
        'KNOWN_FACT', 'TENANT_PROVIDED', 'PUBLIC_SOURCE', 'LICENSED_SOURCE', 'DERIVED_SUMMARY',
    ];

    /**
     * Individual-identifier shapes.
     *
     * Each is deliberately specific enough not to fire on ordinary business text:
     *
     *   The bracketed-obfuscation pattern REQUIRES brackets, so it does not match the substring
     *   "at" inside words like "strategy" or "data".
     *
     *   The handle pattern requires two or more characters after the `@` and no space, so a stray
     *   "@" in prose does not fire.
     *
     * @var list<string>
     */
    public const INDIVIDUAL_IDENTIFIER_PATTERNS = [
        // An email address.
        '/[\w.+%-]+@[\w-]+\.[\w.-]+/i',
        // A bracketed obfuscated email: `john.doe (at) rival.example`.
        '/[\w.+%-]+\s*[([]\s*at\s*[)\]]\s*[\w.-]+/i',
        // A profile URL, or a bare @handle.
        '/(?:linkedin\.com\/in\/|twitter\.com\/|x\.com\/|instagram\.com\/|facebook\.com\/[\w.]|@[A-Za-z0-9_]{2,})/i',
    ];

    /** Eight or more digits with separators — a phone-like run. */
    private const PHONE_LIKE_RUN = '/(?:\+?\d[\s().-]?){7,}\d/';

    /**
     * An ISO-ish date is NOT an identifier.
     *
     * Its 4-2-2 grouping is distinct from any phone grouping, so ordinary dates and timestamps in a
     * business summary must not trip the detector. The optional trailing group takes one or two
     * digits only, so `2026-06-07 1234567` still flags — the exclusion is narrow on purpose.
     */
    private const ISO_DATE_SHAPE = '/^\d{4}[-. ]\d{2}[-. ]\d{2}( \d{1,2})?$/';

    /**
     * Does a field key match any pattern in a list?
     *
     * @param list<string> $patterns
     */
    public static function keyMatchesAny(string $fieldKey, array $patterns): bool
    {
        $normalized = strtolower(trim($fieldKey));
        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** Does a text value contain an individual identifier? */
    public static function containsIndividualIdentifier(string $text): bool
    {
        foreach (self::INDIVIDUAL_IDENTIFIER_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return self::containsPhoneLikeRun($text);
    }

    /**
     * A phone-like run, excluding date shapes.
     *
     * EVERY run is inspected rather than only the first: a summary carrying a date AND a phone
     * number must flag on the phone number, and stopping at the first match would let the date
     * shield it.
     */
    private static function containsPhoneLikeRun(string $text): bool
    {
        if (preg_match_all(self::PHONE_LIKE_RUN, $text, $matches) === false) {
            return false;
        }
        foreach ($matches[0] as $run) {
            if (preg_match(self::ISO_DATE_SHAPE, trim($run)) !== 1) {
                return true;
            }
        }

        return false;
    }
}
