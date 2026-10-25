<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Outcome;

/**
 * LI-04: a bounded, deterministic feature CANDIDATE over already-supplied bytes.
 *
 * This is intentionally a pure function. It does not fetch from EvidenceStore,
 * check source rights, assign business materiality, create M05 evidence, write
 * observations, or become an execution/scheduling/release capability. Historical
 * byte eligibility (U03) is an external, not-yet-adopted owner responsibility.
 */
final class ObservationFeatures
{
    public const VERSION = 'li04.observation-features.pure.v1';
    public const MAX_BODY_BYTES = 32768;
    public const MAX_NAV_LABELS = 12;

    /**
     * @param array<string,mixed> $input
     * @return Outcome
     *
     * Required: before/after body + SHA-256, evidence and snapshot refs, format.
     * format: TEXT_PLAIN or HTML_NAV_SUBSET (conservative, not a browser parser).
     */
    public static function extract(array $input): Outcome
    {
        $required = [
            'before_body','after_body','before_body_sha256','after_body_sha256',
            'before_evidence_id','after_evidence_id','before_snapshot_id',
            'after_snapshot_id','format',
        ];
        if (array_diff($required, array_keys($input)) !== []
            || array_diff(array_keys($input), $required) !== []) {
            return Outcome::refuse('LI04_FEATURE_INPUT_CONTRACT_INVALID');
        }
        foreach (['before_body','after_body'] as $key) {
            if (!is_string($input[$key])
                || $input[$key] === ''
                || strlen($input[$key]) > self::MAX_BODY_BYTES
                || preg_match('//u', $input[$key]) !== 1) {
                return Outcome::refuse('LI04_FEATURE_BYTES_UNAVAILABLE_OR_INVALID');
            }
        }
        foreach (['before_evidence_id','after_evidence_id','before_snapshot_id','after_snapshot_id'] as $key) {
            if (!is_string($input[$key])
                || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $input[$key]) !== 1) {
                return Outcome::refuse('LI04_FEATURE_IDENTITY_INVALID');
            }
        }
        if ($input['before_snapshot_id'] === $input['after_snapshot_id']
            || $input['before_evidence_id'] === $input['after_evidence_id']) {
            return Outcome::refuse('LI04_FEATURE_PAIR_NOT_DISTINCT');
        }
        if (!in_array($input['format'], ['TEXT_PLAIN', 'HTML_NAV_SUBSET'], true)) {
            return Outcome::refuse('LI04_FEATURE_FORMAT_UNSUPPORTED');
        }
        foreach (['before','after'] as $side) {
            $digest = $input[$side.'_body_sha256'];
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || !hash_equals(hash('sha256', $input[$side.'_body']), $digest)) {
                return Outcome::refuse('LI04_FEATURE_BYTES_DIGEST_MISMATCH');
            }
        }

        $before = $input['before_body'];
        $after = $input['after_body'];
        $fingerprint = hash('sha256', implode("\x1f", [
            self::VERSION, $input['format'],
            $input['before_snapshot_id'], $input['after_snapshot_id'],
            $input['before_evidence_id'], $input['after_evidence_id'],
            $input['before_body_sha256'], $input['after_body_sha256'],
        ]));
        $result = static function (string $status, array $features, array $notes) use ($input, $fingerprint): Outcome {
            foreach ($features as &$feature) {
                $feature['before_snapshot_id'] = $input['before_snapshot_id'];
                $feature['after_snapshot_id'] = $input['after_snapshot_id'];
                $feature['evidence_refs'] = [$input['before_evidence_id'], $input['after_evidence_id']];
                $feature['extractor_version'] = self::VERSION;
            }
            unset($feature);
            return Outcome::ok([
                'schema_version' => 'li04.feature-candidates.v1',
                'extractor_version' => self::VERSION,
                'input_fingerprint' => $fingerprint,
                'status' => $status,
                'features' => $features,
                'extraction_notes' => $notes,
                'structural_diff_not_reclassified' => true,
                'business_materiality' => 'NOT_ASSESSED',
                'source_rights' => 'NOT_CHECKED_BY_PURE_EXTRACTOR',
                'independent_origin_count' => null,
                'evidence_status' => 'NOT_MINTED',
                'human_review_required' => true,
                'release_authorized' => false,
                'execution_authorized' => false,
            ]);
        };
        if (hash_equals($input['before_body_sha256'], $input['after_body_sha256'])) {
            return $result('BYTE_IDENTICAL', [], []);
        }
        if ($input['format'] === 'HTML_NAV_SUBSET' &&
            (preg_match('~<(?:script|style|iframe|svg|form)\b~i', $before) === 1
            || preg_match('~<(?:script|style|iframe|svg|form)\b~i', $after) === 1)) {
            return $result('UNSUPPORTED_MARKUP', [], ['UNTRUSTED_ACTIVE_MARKUP_NOT_PARSED']);
        }

        $features = [];
        $notes = [];
        if ($input['format'] === 'HTML_NAV_SUBSET') {
            $left = self::navigationOrder($before);
            $right = self::navigationOrder($after);
            if ($left !== null && $right !== null) {
                $a = $left;
                $b = $right;
                sort($a, SORT_STRING);
                sort($b, SORT_STRING);
                if ($a === $b && $left !== $right) {
                    $features[] = [
                        'kind' => 'NAV_ORDER_CHANGED',
                        'proof_class' => 'BOUNDED_EXACT_NAV_LABEL_PERMUTATION',
                        'before_order_untrusted' => $left,
                        'after_order_untrusted' => $right,
                    ];
                } elseif ($a !== $b) {
                    $notes[] = 'NAV_LABEL_SET_CHANGED_NO_ORDER_CLAIM';
                }
            } else {
                $notes[] = 'NAV_SUBSET_UNSUPPORTED_OR_AMBIGUOUS';
            }
        }

        $plainBefore = self::plain($before, $input['format']);
        $plainAfter = self::plain($after, $input['format']);
        $priceBefore = self::onePrice($plainBefore);
        $priceAfter = self::onePrice($plainAfter);
        if ($priceBefore['status'] === 'ONE' && $priceAfter['status'] === 'ONE') {
            if ($priceBefore['currency'] === $priceAfter['currency']) {
                if ($priceBefore['minor'] !== $priceAfter['minor']) {
                    $features[] = [
                        'kind' => 'PRICE_TOKEN_CHANGED',
                        'proof_class' => 'ONE_UNAMBIGUOUS_CURRENCY_TOKEN_EACH',
                        'currency' => $priceBefore['currency'],
                        'before_minor_units' => $priceBefore['minor'],
                        'after_minor_units' => $priceAfter['minor'],
                    ];
                }
            } else {
                $notes[] = 'PRICE_CURRENCY_CHANGED_NO_MAGNITUDE_COMPARISON';
            }
        } elseif ($priceBefore['status'] === 'AMBIGUOUS' || $priceAfter['status'] === 'AMBIGUOUS') {
            $notes[] = 'PRICE_TOKENS_AMBIGUOUS';
        }

        $negBefore = self::negationState($plainBefore);
        $negAfter = self::negationState($plainAfter);
        if ($negBefore['state'] !== $negAfter['state']
            && $negBefore['state'] !== 'AMBIGUOUS' && $negAfter['state'] !== 'AMBIGUOUS'
            && $negBefore['anchor'] !== '' && $negBefore['anchor'] === $negAfter['anchor']
            && strlen($negBefore['anchor']) >= 8) {
            $features[] = [
                'kind' => 'NEGATION_TOKEN_CHANGED',
                'proof_class' => 'SAME_LEXICAL_ANCHOR_EXCEPT_SINGLE_NEGATION',
                'before_polarity' => $negBefore['state'],
                'after_polarity' => $negAfter['state'],
            ];
        }

        $dateBefore = self::oneIsoDate($plainBefore);
        $dateAfter = self::oneIsoDate($plainAfter);
        if ($dateBefore !== null && $dateAfter !== null
            && $dateBefore['date'] !== $dateAfter['date']
            && $dateBefore['anchor'] === $dateAfter['anchor']) {
            $features[] = [
                'kind' => 'DATE_LITERAL_CHANGED',
                'proof_class' => 'SAME_LEXICAL_ANCHOR_EXCEPT_ISO_DATE',
                'before_date_literal' => $dateBefore['date'],
                'after_date_literal' => $dateAfter['date'],
                'event_date_verified' => false,
            ];
        }

        return $result(
            $features === [] ? 'UNCLASSIFIED_CHANGE' : 'FEATURES_OBSERVED_NOT_ASSESSED',
            $features, array_values(array_unique($notes)),
        );
    }

    /** @return list<string>|null */
    private static function navigationOrder(string $html): ?array
    {
        if (preg_match_all('~<nav(?:\s[^<>]*)?>(.*?)</nav\s*>~is', $html, $navs) !== 1) {
            return null; // zero/multiple NAVs are not one authoritative menu
        }
        $inside = $navs[1][0];
        $count = preg_match_all('~<a\b~i', $inside);
        if ($count === false || $count < 2 || $count > self::MAX_NAV_LABELS
            || preg_match_all('~</a\s*>~i', $inside) !== $count
            || preg_match_all('~<a\b[^<>]*>([^<>]{1,80})</a\s*>~iu', $inside, $anchors) !== $count) {
            return null;
        }
        $labels = [];
        foreach ($anchors[1] as $raw) {
            $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim((string) preg_replace('/\s+/u', ' ', $decoded));
            if ($label === '' || strlen($label) > 80 || in_array($label, $labels, true)) {
                return null; // duplicate labels make order alignment ambiguous
            }
            $labels[] = $label;
        }
        return $labels;
    }

    private static function plain(string $body, string $format): string
    {
        $text = $format === 'HTML_NAV_SUBSET' ? html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $body;
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array{status:string,minor?:int,currency?:string} */
    private static function onePrice(string $text): array
    {
        $pattern = '/(?<![\p{L}\p{N}])(?:(SEK|EUR|USD|kr)\s*(\d{1,6}(?:[.,]\d{1,2})?)|(\d{1,6}(?:[.,]\d{1,2})?)\s*(SEK|EUR|USD|kr))(?![\p{L}\p{N}])/iu';
        $matches = [];
        $count = preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
        if ($count === false || $count > 1) return ['status'=>'AMBIGUOUS'];
        if ($count === 0) return ['status'=>'NONE'];
        $m = $matches[0];
        $unit = strtoupper(($m[1] ?? '') !== '' ? $m[1] : ($m[4] ?? ''));
        $currency = $unit === 'KR' ? 'SEK' : $unit;
        $value = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
        $split = explode('.', str_replace(',', '.', $value), 2);
        $minor = ((int) $split[0]) * 100 + (int) str_pad($split[1] ?? '', 2, '0');
        return ['status'=>'ONE', 'minor'=>$minor, 'currency'=>$currency];
    }

    /** @return array{state:string,anchor:string} */
    private static function negationState(string $text): array
    {
        $count = preg_match_all('/\b(?:not|inte)\b/iu', $text);
        if ($count === false || $count > 1) return ['state'=>'AMBIGUOUS','anchor'=>''];
        $stripped = (string) preg_replace('/\b(?:not|inte)\b/iu', '', $text);
        $anchor = strtolower(trim((string) preg_replace('/\s+/u', ' ', $stripped)));
        return ['state'=>$count === 1 ? 'NEGATED' : 'AFFIRMATIVE', 'anchor'=>$anchor];
    }

    /** @return array{date:string,anchor:string}|null */
    private static function oneIsoDate(string $text): ?array
    {
        if (preg_match_all('/(?<!\d)(\d{4}-\d{2}-\d{2})(?!\d)/', $text, $m) !== 1) {
            return null;
        }
        return [
            'date'=>$m[1][0],
            'anchor'=>trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(
                '/(?<!\d)\d{4}-\d{2}-\d{2}(?!\d)/', '<DATE>', $text))),
        ];
    }
}
