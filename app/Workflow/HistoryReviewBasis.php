<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * LI-05A offline, hash-bound candidate manifest. No admitted historical rights or model context.
 * A digest freezes the exact candidate value, not its truth, provenance or permission.
 */
final class HistoryReviewBasis
{
    public const SCHEMA_VERSION = 'li05.offline-basis-candidate.v1';
    private const MANIFEST_KEYS = [
        'schema_version', 'status', 'scope', 'period', 'baseline_period',
        'baseline_null_reason', 'knowledge_cutoff_utc', 'selector_version',
        'eligibility_version', 'references', 'input_count', 'selected_count',
        'excluded_counts_by_reason', 'coverage', 'contra_evidence_not_included',
        'selection_integrity', 'rights_disposition', 'historical_reuse_admitted',
    ];
    private const REF_KEYS = [
        'ref_id', 'source_ref_id', 'evidence_id', 'snapshot_id', 'assessment_id',
        'payload_sha256', 'event_at', 'observed_at', 'recorded_at', 'known_at',
        'analyzed_at', 'analysis_as_of_cutoff', 'semantic_polarity', 'origin_group',
        'provenance', 'rights_disposition',
    ];

    /** @param array<string,mixed> $input */
    public static function freeze(array $input): Outcome
    {
        $candidate = HistoricalEvidenceSelector::select($input);
        if (!$candidate->isOk()) {
            return $candidate;
        }
        $manifest = $candidate->value();
        $manifest['manifest_digest'] = Digest::canonicalDigest($manifest);
        return Outcome::ok($manifest);
    }

    /** Verify integrity of a candidate only; never admits or authorizes a historical use. */
    public static function verify(array $manifest): Outcome
    {
        $digest = $manifest['manifest_digest'] ?? null;
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            return Outcome::refuse('LI05_BASIS_DIGEST_INVALID');
        }
        unset($manifest['manifest_digest']);
        if (!self::keys($manifest, self::MANIFEST_KEYS)
            || $manifest['schema_version'] !== self::SCHEMA_VERSION
            || $manifest['selector_version'] !== HistoricalEvidenceSelector::VERSION
            || $manifest['eligibility_version'] !== HistoricalEvidenceEligibility::VERSION
            || $manifest['rights_disposition'] !== 'NOT_ASSESSED'
            || $manifest['historical_reuse_admitted'] !== false
            || !is_array($manifest['references']) || !array_is_list($manifest['references'])
            || count($manifest['references']) > HistoricalEvidenceSelector::MAX_REFERENCES
            || !is_int($manifest['selected_count'])
            || $manifest['selected_count'] !== count($manifest['references'])
            || !is_int($manifest['input_count'])
            || $manifest['input_count'] < $manifest['selected_count']
            || !in_array($manifest['selection_integrity'], ['COMPLETE_WITHIN_INPUT', 'INSUFFICIENT_BASIS'], true)
            || $manifest['status'] !== ($manifest['selection_integrity'] === 'INSUFFICIENT_BASIS'
                ? 'INSUFFICIENT_BASIS' : 'OFFLINE_NOT_ADMITTED')) {
            return Outcome::refuse('LI05_BASIS_SHAPE_INVALID');
        }
        foreach ($manifest['references'] as $row) {
            if (!is_array($row) || !self::keys($row, self::REF_KEYS)
                || $row['rights_disposition'] !== 'NOT_ASSESSED'
                || !is_string($row['payload_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $row['payload_sha256']) !== 1
                || !HistoricalEvidenceSelector::utc($row['observed_at'])
                || !HistoricalEvidenceSelector::utc($row['recorded_at'])
                || !HistoricalEvidenceSelector::utc($row['known_at'])
                || !in_array($row['analysis_as_of_cutoff'], ['AVAILABLE', 'NOT_KNOWN'], true)) {
                return Outcome::refuse('LI05_BASIS_SHAPE_INVALID');
            }
        }
        try {
            $actual = Digest::canonicalDigest($manifest);
        } catch (\Throwable) {
            return Outcome::refuse('LI05_BASIS_SHAPE_INVALID');
        }
        if (!hash_equals($digest, $actual)) {
            return Outcome::refuse('LI05_BASIS_DIGEST_MISMATCH');
        }
        return Outcome::ok(['digest_verified' => true, 'historical_reuse_admitted' => false]);
    }

    /** @param list<string> $expected */
    private static function keys(array $map, array $expected): bool
    {
        $keys = array_keys($map);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        return $keys === $expected;
    }
}
