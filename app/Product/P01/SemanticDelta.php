<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * CR-02: bounded derived-only SemanticDelta.v1 between two already-projected
 * synthetic, private captures. This class has no source fetch, DB, provider,
 * scheduler, M05/M10 writer, model materiality or customer release path.
 *
 * P01 ChangeDetection is the existing raw/line-diff authority; no status,
 * provenance or threshold in that owner is replaced by this derived readback.
 */
final class SemanticDelta
{
    public const VERSION = 'cr02.semantic-delta.v1';
    public const ALGORITHM_VERSION = 'cr02.same-selector-unique-content-alignment.v1';
    public const MAX_PAIR_COMPARISONS = 4096; // 64 x 64 worst case
    public const MAX_DELTAS = 128;
    public const MAX_WARNINGS = 20;
    private const SENSITIVE = ['PRICE','NEGATION','OFFER','CONTRACT','DATE'];

    /**
     * @param array<string,mixed> $before CR-01 DocumentProjection.v1
     * @param array<string,mixed> $after CR-01 DocumentProjection.v1
     * @return Outcome<array<string,mixed>>
     */
    public static function compare(array $before, array $after): Outcome
    {
        $left = SectionFingerprint::fromProjection($before);
        if (!$left->isOk()) {
            return $left;
        }
        $right = SectionFingerprint::fromProjection($after);
        if (!$right->isOk()) {
            return $right;
        }
        $b = $left->value();
        $a = $right->value();
        $bs = $b['source'];
        $as = $a['source'];
        foreach (['tenant_id', 'workspace_id', 'monitored_source_id', 'source_ref_id',
            'source_card_id', 'source_card_sha256', 'retention_class'] as $key) {
            if ($bs[$key] !== $as[$key]) {
                return Outcome::refuse('CR02_CROSS_SCOPE_OR_CARD_REVISION', [], 'SCOPE', 'S4');
            }
        }
        if ($bs['capture_ref_id'] === $as['capture_ref_id']
            || $bs['evidence_ref_id'] === $as['evidence_ref_id']
            || $b['projection_digest'] === $a['projection_digest']) {
            return Outcome::refuse('CR02_NOT_DISTINCT_CAPTURE_PROJECTIONS');
        }
        if ($bs['observed_at'] >= $as['observed_at']
            || $bs['known_at'] > $as['known_at']) {
            return Outcome::refuse('CR02_SNAPSHOT_CLOCK_NOT_FORWARD');
        }

        $leftSections = $b['sections'];
        $rightSections = $a['sections'];
        $lookup = [];
        foreach ($rightSections as $i => $section) {
            $lookup[$section['selector']] = $i;
        }
        $leftUsed = [];
        $rightUsed = [];
        $pairs = [];
        $warnings = [];
        $alignmentComparisons = 0;

        // Strong evidence: exact DOM location. CR-01 paths are *derived* and
        // are not original HTML byte offsets or primary M05 object identities.
        foreach ($leftSections as $i => $section) {
            $j = $lookup[$section['selector']] ?? null;
            if ($j === null) {
                continue;
            }
            $pairs[] = [$i, $j, 'EXACT_DERIVED_SELECTOR'];
            $leftUsed[$i] = true;
            $rightUsed[$j] = true;
        }
        // Weak fallback: same type + exact normalized body hash, unique on
        // both unmatched sides. Precompute left counts in linear time so the
        // only quadratic loop below is the explicitly counted 64x64 scan.
        $unmatchedLeftCounts = [];
        foreach ($leftSections as $i => $s) {
            if (!isset($leftUsed[$i])) {
                $key = $s['type'] . "\0" . $s['body_fingerprint'];
                $unmatchedLeftCounts[$key] = ($unmatchedLeftCounts[$key] ?? 0) + 1;
            }
        }
        foreach ($leftSections as $i => $s) {
            if (isset($leftUsed[$i])) {
                continue;
            }
            $candidates = [];
            foreach ($rightSections as $j => $t) {
                $alignmentComparisons++;
                if ($alignmentComparisons > self::MAX_PAIR_COMPARISONS) {
                    return Outcome::refuse('CR02_ALIGNMENT_COMPARISON_LIMIT');
                }
                if (!isset($rightUsed[$j]) && $s['type'] === $t['type']
                    && $s['body_fingerprint'] === $t['body_fingerprint']) {
                    $candidates[] = $j;
                }
            }
            if (count($candidates) !== 1) {
                continue;
            }
            $j = $candidates[0];
            $leftKey = $s['type'] . "\0" . $s['body_fingerprint'];
            if (($unmatchedLeftCounts[$leftKey] ?? 0) !== 1) {
                continue;
            }
            $pairs[] = [$i, $j, 'WEAK_UNIQUE_TYPE_AND_BODY'];
            $leftUsed[$i] = true;
            $rightUsed[$j] = true;
            $warnings['WEAK_CONTENT_ALIGNMENT_NOT_PRIMARY_IDENTITY'] = true;
        }

        $deltas = [];
        foreach ($pairs as [$i, $j, $alignment]) {
            $s = $leftSections[$i];
            $t = $rightSections[$j];
            $changes = [];
            $uncertainty = [];
            if ($s['type'] !== $t['type']) {
                $kind = 'SECTION_TYPE_CHANGED';
                $uncertainty[] = 'TYPE_CHANGED_PROVENANCE_REVIEW_REQUIRED';
            } elseif ($alignment === 'WEAK_UNIQUE_TYPE_AND_BODY') {
                $kind = 'POSSIBLE_SECTION_MOVE';
                $uncertainty[] = 'SAME_TYPE_AND_CONTENT_NOT_PROOF_OF_IDENTICAL_ELEMENT';
            } elseif ($s['type'] === 'NAVIGATION'
                && $s['nav_item_count'] > 0 && $t['nav_item_count'] > 0
                && $s['nav_set_fingerprint'] === $t['nav_set_fingerprint']
                && $s['order_fingerprint'] !== $t['order_fingerprint']) {
                $kind = 'ORDER_ONLY';
                $uncertainty[] = 'NON_NAV_TEXT_WITHIN_SECTION_NOT_INDEPENDENTLY_PROVED_UNCHANGED';
            } elseif ($s['type'] === 'NAVIGATION'
                && $s['nav_set_fingerprint'] !== $t['nav_set_fingerprint']) {
                $kind = 'NAV_ITEMS_CHANGED';
                $uncertainty[] = 'DIFFERENT_NAV_LABEL_SET_NOT_A_PRICE_SIGNAL';
            } elseif ($s['text_sha256'] === $t['text_sha256']
                && $s['order_fingerprint'] === $t['order_fingerprint']) {
                $kind = 'SECTION_UNCHANGED';
            } elseif ($s['text_sha256'] === $t['text_sha256']) {
                $kind = 'ORDER_FINGERPRINT_CHANGED';
                $uncertainty[] = 'NAV_STRUCTURE_CHANGED_WITHOUT_TEXT_PROOF';
            } else {
                foreach (self::SENSITIVE as $name) {
                    if (($s['sensitive_token_digests'][$name] ?? null)
                        !== ($t['sensitive_token_digests'][$name] ?? null)) {
                        $changes[] = $name;
                    }
                }
                $kind = count($changes) === 1 ? ($changes[0] . '_CHANGED')
                    : (count($changes) > 1 ? 'MULTIPLE_SENSITIVE_TOKENS_CHANGED' : 'TEXT_CHANGED');
                if ($s['truncated'] || $t['truncated']) {
                    $uncertainty[] = 'EXCERPT_NOT_FULL_SOURCE';
                }
            }
            if ($s['truncated'] || $t['truncated']) {
                $uncertainty[] = 'SECTION_TRUNCATED_OR_EXCERPT_PARTIAL';
            }
            if ($alignment === 'WEAK_UNIQUE_TYPE_AND_BODY') {
                $uncertainty[] = 'WEAK_SECTION_ALIGNMENT';
            }
            $deltas[] = self::record($s, $t, $kind, $alignment, $changes, array_values(array_unique($uncertainty)));
        }

        // Unaligned sections are not silently interpreted as a proved
        // removal/new event: changed selectors and parser repair may explain
        // the difference. Preserve explicit unknowns for later owners.
        foreach ($leftSections as $i => $s) {
            if (!isset($leftUsed[$i])) {
                $deltas[] = self::record($s, null, 'UNPAIRED_BEFORE_UNKNOWN',
                    'UNPAIRED', [], ['MISSING_STRONG_SECTION_ALIGNMENT']);
            }
        }
        foreach ($rightSections as $i => $s) {
            if (!isset($rightUsed[$i])) {
                $deltas[] = self::record(null, $s, 'UNPAIRED_AFTER_UNKNOWN',
                    'UNPAIRED', [], ['MISSING_STRONG_SECTION_ALIGNMENT']);
            }
        }
        if (count($deltas) > self::MAX_DELTAS) {
            return Outcome::refuse('CR02_DELTA_COUNT_LIMIT');
        }
        usort($deltas, static fn (array $x, array $y): int
            => ($x['before_ordinal'] ?? PHP_INT_MAX) <=> ($y['before_ordinal'] ?? PHP_INT_MAX)
                ?: ($x['after_ordinal'] ?? PHP_INT_MAX) <=> ($y['after_ordinal'] ?? PHP_INT_MAX)
                ?: strcmp((string) $x['change_kind'], (string) $y['change_kind']));

        $counts = [
            'matched' => count($pairs),
            'unaligned_before' => count($leftSections) - count($leftUsed),
            'unaligned_after' => count($rightSections) - count($rightUsed),
            'changed' => 0,
            'order_only' => 0,
            'unchanged' => 0,
            'weak_alignment' => 0,
            'sensitive_token_changes' => 0,
            'truncated_or_partial' => 0,
        ];
        foreach ($deltas as $d) {
            $kind = $d['change_kind'];
            if ($kind === 'SECTION_UNCHANGED') {
                $counts['unchanged']++;
            } else {
                $counts['changed']++;
            }
            if ($kind === 'ORDER_ONLY') {
                $counts['order_only']++;
            }
            if ($d['alignment'] === 'WEAK_UNIQUE_TYPE_AND_BODY') {
                $counts['weak_alignment']++;
            }
            if ($d['sensitive_token_kinds'] !== []) {
                $counts['sensitive_token_changes']++;
            }
            if (in_array('SECTION_TRUNCATED_OR_EXCERPT_PARTIAL', $d['uncertainty_codes'], true)) {
                $counts['truncated_or_partial']++;
            }
        }

        $unknown = [];
        if ($leftSections === [] || $rightSections === []) {
            $unknown[] = 'NO_TYPED_SECTION_COVERAGE';
        }
        if ($counts['unaligned_before'] || $counts['unaligned_after']) {
            $unknown[] = 'UNALIGNED_SECTION_PRESENCE_OR_IDENTITY';
        }
        if ($counts['weak_alignment']) {
            $unknown[] = 'WEAK_TYPE_AND_HASH_ALIGNMENT';
        }
        if ($counts['truncated_or_partial']) {
            $unknown[] = 'BOUNDED_EXCERPT_COVERAGE';
        }
        if ($b['warnings'] !== [] || $a['warnings'] !== []) {
            $unknown[] = 'HTML5_PARSER_RECOVERY_DETAILS_UNVERIFIED';
        }
        $rawChanged = $bs['raw_sha256'] !== $as['raw_sha256'];
        if ($rawChanged && $counts['changed'] === 0) {
            $unknown[] = 'RAW_SOURCE_BYTES_DIFFER_WITHOUT_TYPED_SECTION_CHANGE';
        }
        if (!$rawChanged && $counts['changed'] !== 0) {
            return Outcome::refuse('CR02_IDENTICAL_RAW_BYTES_INCONSISTENT_PROJECTIONS');
        }
        if ($unknown !== []) {
            $summary = 'UNKNOWN_OR_PARTIAL';
        } elseif ($counts['changed'] === 0) {
            $summary = 'NO_DERIVED_SECTION_CHANGE';
        } elseif ($counts['changed'] === $counts['order_only']) {
            $summary = 'ORDER_ONLY';
        } else {
            $summary = 'TYPED_SECTION_CHANGE';
        }
        // The parser currently always records recovery-detail uncertainty
        // even for well-formed input. Distinguish that limitation from the
        // narrower structural classification in a separate field.
        $structural = ($counts['unaligned_before'] || $counts['unaligned_after'])
            ? 'UNKNOWN_ALIGNMENT'
            : ($counts['changed'] === 0 ? 'NO_TYPED_CHANGE'
                : ($counts['changed'] === $counts['order_only'] ? 'ORDER_ONLY' : 'TYPED_CHANGE'));

        $result = [
            'schema_version' => self::VERSION,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'fingerprint_version' => SectionFingerprint::VERSION,
            'source_scope' => [
                'tenant_id' => $bs['tenant_id'],
                'workspace_id' => $bs['workspace_id'],
                'source_ref_id' => $bs['source_ref_id'],
                'monitored_source_id' => $bs['monitored_source_id'],
                'source_card_id' => $bs['source_card_id'],
                'source_card_sha256' => $bs['source_card_sha256'],
            ],
            'before' => self::sourceRef($b),
            'after' => self::sourceRef($a),
            'original_raw_body_changed' => $rawChanged,
            'raw_bytes_not_reinspected_by_cr02' => true,
            'alignment_effort' => [
                'before_sections' => count($leftSections),
                'after_sections' => count($rightSections),
                'max_pair_comparisons' => self::MAX_PAIR_COMPARISONS,
                'pair_comparisons_performed' => $alignmentComparisons,
            ],
            'delta_count' => count($deltas),
            'counts' => $counts,
            'deltas' => $deltas,
            'structural_classification' => $structural,
            'evidence_coverage' => $summary,
            'unknown_dimensions' => $unknown,
            'derived_only' => true,
            'semantic_truth_verified' => false,
            'independent_materiality_assessed' => false,
            'commercial_significance' => 'NOT_ASSESSED',
            'attention_recommendation' => 'NONE',
            'source_use_rights_rechecked' => false,
            'native_runtime_authorized' => false,
            'provider_or_model_authorized' => false,
            'notification_authorized' => false,
            'customer_release_authorized' => false,
            'production_authorized' => false,
            'persisted' => false,
        ];
        $result['delta_digest'] = Digest::canonicalDigest($result);
        return Outcome::ok($result);
    }

    /** @return array<string,mixed> */
    private static function sourceRef(array $v): array
    {
        $s = $v['source'];
        return [
            'capture_ref_id' => $s['capture_ref_id'],
            'evidence_ref_id' => $s['evidence_ref_id'],
            'raw_sha256' => $s['raw_sha256'],
            'projection_digest' => $v['projection_digest'],
            'observed_at' => $s['observed_at'],
            'known_at' => $s['known_at'],
            'parser_version' => DocumentProjectionContract::PARSER_VERSION,
            'normalizer_version' => DocumentProjectionContract::NORMALIZER_VERSION,
        ];
    }

    /** @return array<string,mixed> */
    private static function record(?array $a, ?array $b, string $kind, string $alignment, array $sensitive, array $unknown): array
    {
        return [
            'change_kind' => $kind,
            'alignment' => $alignment,
            'before_type' => $a['type'] ?? null,
            'after_type' => $b['type'] ?? null,
            'before_ordinal' => $a['ordinal'] ?? null,
            'after_ordinal' => $b['ordinal'] ?? null,
            'before_selector' => $a['selector'] ?? null,
            'after_selector' => $b['selector'] ?? null,
            'before_origin_id' => $a['origin_id'] ?? null,
            'after_origin_id' => $b['origin_id'] ?? null,
            'before_text_sha256' => $a['text_sha256'] ?? null,
            'after_text_sha256' => $b['text_sha256'] ?? null,
            'before_body_fingerprint' => $a['body_fingerprint'] ?? null,
            'after_body_fingerprint' => $b['body_fingerprint'] ?? null,
            'before_order_fingerprint' => $a['order_fingerprint'] ?? null,
            'after_order_fingerprint' => $b['order_fingerprint'] ?? null,
            'before_source_sha256' => $a['source_sha256'] ?? null,
            'after_source_sha256' => $b['source_sha256'] ?? null,
            'sensitive_token_kinds' => $sensitive,
            'uncertainty_codes' => $unknown,
            'source_excerpt_not_exported' => true,
            'original_byte_offsets_verified' => false,
        ];
    }
}
