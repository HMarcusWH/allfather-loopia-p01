<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Outcome;

/**
 * Pure LI-07A derivation replay. Metadata equality only; never fetches source bytes,
 * calls a model, certifies current rights, rewrites a summary, or emits an M11 finding.
 */
final class Replay
{
    public const VERSION = 'li07a.offline-replay.v1';

    /**
     * Expected must be digest-intact. Missing frozen metadata or reported redacted inputs
     * fail visibly as NOT_REPLAYABLE, not as a synthetic zero or a guessed MATCH.
     * @param array<string,mixed> $input
     */
    public static function check(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, [
            'expected_rollup', 'frozen_inputs', 'reported_unavailable_ref_ids',
        ]) || !is_array($input['expected_rollup'])
            || !RollupEngine::verify($input['expected_rollup'])->isOk()
            || !is_array($input['reported_unavailable_ref_ids'])
            || !array_is_list($input['reported_unavailable_ref_ids'])
            || count($input['reported_unavailable_ref_ids']) > InputSet::MAX_REFS) {
            return Outcome::refuse('M17_REPLAY_CONTRACT_INVALID');
        }
        $expected = $input['expected_rollup'];
        $seen = [];
        $validRefIds = array_fill_keys(array_merge(
            array_column($expected['lineage']['selected_refs'], 'ref_id'),
            $expected['lineage']['excluded_outside_window_ref_ids'],
        ), true);
        foreach ($input['reported_unavailable_ref_ids'] as $id) {
            if (!Vocabulary::id($id) || !isset($validRefIds[$id]) || isset($seen[$id])) {
                return Outcome::refuse('M17_REPLAY_UNAVAILABLE_REF_INVALID');
            }
            $seen[$id] = true;
        }
        $missing = array_keys($seen);
        sort($missing, SORT_STRING);
        if ($missing !== []) {
            return Outcome::ok([
                'version' => self::VERSION,
                'outcome' => 'NOT_REPLAYABLE',
                'reason_code' => 'INPUT_REF_UNAVAILABLE_REPORTED',
                'reported_unavailable_ref_ids' => $missing,
                'expected_digest' => $expected['rollup_digest'],
                'source_bytes_verified' => false,
                'historical_reuse_admitted' => false,
            ]);
        }
        if ($input['frozen_inputs'] === null) {
            return Outcome::ok([
                'version' => self::VERSION,
                'outcome' => 'NOT_REPLAYABLE',
                'reason_code' => 'FROZEN_INPUTS_UNAVAILABLE',
                'reported_unavailable_ref_ids' => [],
                'expected_digest' => $expected['rollup_digest'],
                'source_bytes_verified' => false,
                'historical_reuse_admitted' => false,
            ]);
        }
        if (!is_array($input['frozen_inputs']) || !Vocabulary::exactKeys($input['frozen_inputs'], [
            'input_set', 'window', 'profile_candidate', 'importance_weights',
        ])) {
            return Outcome::refuse('M17_REPLAY_FROZEN_INPUT_CONTRACT_INVALID');
        }
        $rederived = RollupEngine::derive($input['frozen_inputs']);
        if (!$rederived->isOk()) {
            // Do not relabel malformed/now-unavailable inputs as a successful replay.
            return Outcome::ok([
                'version' => self::VERSION,
                'outcome' => 'NOT_REPLAYABLE',
                'reason_code' => 'FROZEN_METADATA_REDERIVATION_REFUSED',
                'reported_unavailable_ref_ids' => [],
                'expected_digest' => $expected['rollup_digest'],
                'source_bytes_verified' => false,
                'historical_reuse_admitted' => false,
            ]);
        }
        $actual = $rederived->value();
        $match = $actual === $expected;
        return Outcome::ok([
            'version' => self::VERSION,
            'outcome' => $match ? 'MATCH_METADATA_ONLY' : 'DIVERGED',
            'reason_code' => $match ? 'EXACT_FROZEN_METADATA_MATCH' : 'M17_TEMPORAL_REPLAY_DIVERGENCE_CANDIDATE',
            'reported_unavailable_ref_ids' => [],
            'expected_digest' => $expected['rollup_digest'],
            'actual_digest' => $actual['rollup_digest'],
            'source_bytes_verified' => false,
            'historical_reuse_admitted' => false,
        ]);
    }
}
