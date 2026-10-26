<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * LI-07A pure lineage over *caller-supplied* LI-06A reference metadata.
 * No M05 byte reads, authenticated source provenance, memory IDs or new rights.
 */
final class Lineage
{
    public const VERSION = 'li07a.offline-lineage.v1';

    /** @param array{input_set:array<string,mixed>,window:array<string,mixed>} $input */
    public static function derive(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, ['input_set', 'window'])
            || !is_array($input['input_set']) || !is_array($input['window'])
            || !InputSet::verify($input['input_set'])->isOk()
            || !WindowEngine::verifyWindow($input['window'])->isOk()) {
            return Outcome::refuse('M17_LINEAGE_INPUT_INVALID');
        }
        $window = $input['window'];
        $selected = [];
        $outside = [];
        $contrary = [];
        $diagnostic = [];
        foreach ($input['input_set']['refs'] as $ref) {
            if ($ref['observed_at_utc'] < $window['start_inclusive_utc']
                || $ref['observed_at_utc'] >= $window['end_exclusive_utc']) {
                $outside[] = $ref['ref_id'];
                continue;
            }
            // Full metadata reference retained. Never a raw source body or a reconstructed claim.
            $selected[] = [
                'ref_type' => $ref['ref_type'],
                'ref_id' => $ref['ref_id'],
                'source_ref_id' => $ref['source_ref_id'],
                'observed_at_utc' => $ref['observed_at_utc'],
                'payload_sha256' => $ref['payload_sha256'],
                'polarity' => $ref['polarity'],
                'conflict_flag' => $ref['conflict_flag'],
                'diagnostic_flag' => $ref['diagnostic_flag'],
            ];
            if ($ref['polarity'] === 'CONTRARY' || $ref['conflict_flag']) {
                $contrary[] = $ref['ref_id'];
            }
            if ($ref['diagnostic_flag']) {
                $diagnostic[] = $ref['ref_id'];
            }
        }
        // InputSet::freeze orders by timestamp then ref ID. Preserve that order for replay.
        $manifest = [
            'version' => self::VERSION,
            'status' => 'OFFLINE_NOT_ADMITTED',
            'scope' => $input['input_set']['scope'],
            'data_class' => $input['input_set']['data_class'],
            'input_digest' => $input['input_set']['input_digest'],
            'window_digest' => $window['window_digest'],
            'selected_refs' => $selected,
            'excluded_outside_window_ref_ids' => $outside,
            'selected_count' => count($selected),
            'excluded_count' => count($outside),
            'contrary_or_conflict_ref_ids' => $contrary,
            'high_diagnosticity_ref_ids' => $diagnostic,
            'lineage_complete_within_supplied_input' => true,
            'current_source_rights_verified' => false,
            'historical_reuse_admitted' => false,
        ];
        return Outcome::ok([...$manifest, 'lineage_digest' => Digest::canonicalDigest($manifest)]);
    }

    /** A hash is an integrity check, not authenticated access to the referenced source. */
    public static function verify(array $manifest): Outcome
    {
        $digest = $manifest['lineage_digest'] ?? null;
        if (!is_string($digest) || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            return Outcome::refuse('M17_LINEAGE_DIGEST_INVALID');
        }
        unset($manifest['lineage_digest']);
        if (!Vocabulary::exactKeys($manifest, [
            'version', 'status', 'scope', 'data_class', 'input_digest', 'window_digest',
            'selected_refs', 'excluded_outside_window_ref_ids', 'selected_count',
            'excluded_count', 'contrary_or_conflict_ref_ids', 'high_diagnosticity_ref_ids',
            'lineage_complete_within_supplied_input', 'current_source_rights_verified',
            'historical_reuse_admitted',
        ]) || ($manifest['version'] ?? null) !== self::VERSION
            || ($manifest['status'] ?? null) !== 'OFFLINE_NOT_ADMITTED'
            || !Vocabulary::scope($manifest['scope'] ?? null)
            || !in_array($manifest['data_class'] ?? null, Vocabulary::SUPPORTED_CLASSES, true)
            || ($manifest['current_source_rights_verified'] ?? null) !== false
            || ($manifest['historical_reuse_admitted'] ?? null) !== false
            || ($manifest['lineage_complete_within_supplied_input'] ?? null) !== true
            || !self::digest($manifest['input_digest'] ?? null)
            || !self::digest($manifest['window_digest'] ?? null)
            || !is_array($manifest['selected_refs']) || !array_is_list($manifest['selected_refs'])
            || count($manifest['selected_refs']) > InputSet::MAX_REFS
            || !is_array($manifest['excluded_outside_window_ref_ids'])
            || !array_is_list($manifest['excluded_outside_window_ref_ids'])
            || !is_array($manifest['contrary_or_conflict_ref_ids'])
            || !array_is_list($manifest['contrary_or_conflict_ref_ids'])
            || !is_array($manifest['high_diagnosticity_ref_ids'])
            || !array_is_list($manifest['high_diagnosticity_ref_ids'])
            || ($manifest['selected_count'] ?? null) !== count($manifest['selected_refs'])
            || ($manifest['excluded_count'] ?? null) !== count($manifest['excluded_outside_window_ref_ids'])
            || $manifest['selected_count'] + $manifest['excluded_count'] > InputSet::MAX_REFS) {
            return Outcome::refuse('M17_LINEAGE_SHAPE_INVALID');
        }
        $seen = [];
        $conflicts = [];
        $diagnostics = [];
        foreach ($manifest['selected_refs'] as $ref) {
            if (!is_array($ref) || !Vocabulary::exactKeys($ref, [
                'ref_type', 'ref_id', 'source_ref_id', 'observed_at_utc', 'payload_sha256',
                'polarity', 'conflict_flag', 'diagnostic_flag',
            ]) || $ref['ref_type'] !== $manifest['data_class']
                || !Vocabulary::id($ref['ref_id']) || !Vocabulary::id($ref['source_ref_id'])
                || !Vocabulary::utc($ref['observed_at_utc'])
                || !self::digest($ref['payload_sha256'])
                || !in_array($ref['polarity'], Vocabulary::POLARITY, true)
                || !is_bool($ref['conflict_flag']) || !is_bool($ref['diagnostic_flag'])
                || isset($seen[$ref['ref_id']])) {
                return Outcome::refuse('M17_LINEAGE_SHAPE_INVALID');
            }
            $seen[$ref['ref_id']] = true;
            if ($ref['polarity'] === 'CONTRARY' || $ref['conflict_flag']) {
                $conflicts[] = $ref['ref_id'];
            }
            if ($ref['diagnostic_flag']) {
                $diagnostics[] = $ref['ref_id'];
            }
        }
        foreach ($manifest['excluded_outside_window_ref_ids'] as $refId) {
            if (!Vocabulary::id($refId) || isset($seen[$refId])) {
                return Outcome::refuse('M17_LINEAGE_SHAPE_INVALID');
            }
            $seen[$refId] = true;
        }
        if ($conflicts !== $manifest['contrary_or_conflict_ref_ids']
            || $diagnostics !== $manifest['high_diagnosticity_ref_ids']) {
            return Outcome::refuse('M17_LINEAGE_SHAPE_INVALID');
        }
        try {
            if (!hash_equals($digest, Digest::canonicalDigest($manifest))) {
                return Outcome::refuse('M17_LINEAGE_DIGEST_MISMATCH');
            }
        } catch (\Throwable) {
            return Outcome::refuse('M17_LINEAGE_SHAPE_INVALID');
        }
        return Outcome::ok(['digest_verified' => true, 'source_authority_verified' => false]);
    }

    /**
     * A descriptive late-input successor PROPOSAL, not a persisted M17 memory chain.
     * The old candidate must be an exact subset of the new, including unchanged hash/time
     * for every old ref. Profile revisions and redactions need their own adopted U04 law.
     */
    public static function proposeLateSuccessor(array $prior, array $next): Outcome
    {
        if (!RollupEngine::verify($prior)->isOk() || !RollupEngine::verify($next)->isOk()
            || ($prior['status'] ?? null) !== 'OFFLINE_NOT_ADMITTED'
            || ($next['status'] ?? null) !== 'OFFLINE_NOT_ADMITTED'
            || $prior['scope'] !== $next['scope']
            || $prior['data_class'] !== $next['data_class']
            || $prior['window_bounds'] !== $next['window_bounds']
            || $prior['profile_resolution_digest'] !== $next['profile_resolution_digest']) {
            return Outcome::refuse('M17_SUCCESSOR_SCOPE_PERIOD_OR_PROFILE_MISMATCH');
        }
        $before = [];
        foreach ($prior['lineage']['selected_refs'] as $ref) {
            $before[$ref['ref_id']] = $ref;
        }
        $after = [];
        foreach ($next['lineage']['selected_refs'] as $ref) {
            $after[$ref['ref_id']] = $ref;
        }
        foreach ($before as $id => $old) {
            if (!array_key_exists($id, $after) || $after[$id] !== $old) {
                return Outcome::refuse('M17_SUCCESSOR_WOULD_DROP_OR_REWRITE_INPUT');
            }
        }
        $priorExcluded = $prior['lineage']['excluded_outside_window_ref_ids'];
        $nextExcluded = $next['lineage']['excluded_outside_window_ref_ids'];
        $nextAllIds = array_fill_keys(array_merge(array_keys($after), $nextExcluded), true);
        foreach ($priorExcluded as $oldOutsideId) {
            if (!isset($nextAllIds[$oldOutsideId]) || !in_array($oldOutsideId, $nextExcluded, true)) {
                return Outcome::refuse('M17_SUCCESSOR_WOULD_DROP_OR_RECLASSIFY_OUTSIDE_REF');
            }
        }
        $newIds = array_values(array_diff(array_keys($after), array_keys($before)));
        if ($newIds === [] || $prior['input_digest'] === $next['input_digest']) {
            return Outcome::refuse('M17_SUCCESSOR_NO_NEW_SELECTED_INPUT');
        }
        sort($newIds, SORT_STRING);
        $result = [
            'version' => 'li07a.offline-successor.v1',
            'status' => 'OFFLINE_SUCCESSOR_PROPOSAL_NOT_ADMITTED',
            'reported_reason' => 'ADDITIONAL_IN_PERIOD_INPUT',
            'scope' => $prior['scope'],
            'data_class' => $prior['data_class'],
            'window_bounds' => $prior['window_bounds'],
            'supersedes_rollup_digest' => $prior['rollup_digest'],
            'successor_rollup_digest' => $next['rollup_digest'],
            'new_selected_ref_ids' => $newIds,
            'old_rollup_unchanged' => true,
            'canonical_U04_identity_adopted' => false,
            'historical_reuse_admitted' => false,
        ];
        return Outcome::ok([...$result, 'proposal_digest' => Digest::canonicalDigest($result)]);
    }

    private static function digest(mixed $v): bool
    {
        return is_string($v) && preg_match('/^[0-9a-f]{64}$/D', $v) === 1;
    }
}
