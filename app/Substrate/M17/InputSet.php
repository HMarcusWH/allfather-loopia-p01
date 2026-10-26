<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/** Frozen, sorted, caller-supplied references only; never reads M05 evidence bytes or SQL. */
final class InputSet
{
    public const VERSION = 'li06a.offline-input-set.v1';
    public const MAX_REFS = 256;
    private const ROW_KEYS = [
        'ref_id', 'ref_type', 'source_ref_id', 'observed_at_utc', 'payload_sha256',
        'magnitude_milli', 'polarity', 'conflict_flag', 'diagnostic_flag',
    ];

    /** @param array<string,mixed> $input */
    public static function freeze(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, ['scope', 'data_class', 'refs'])
            || !Vocabulary::scope($input['scope'])
            || !in_array($input['data_class'], Vocabulary::SUPPORTED_CLASSES, true)
            || !is_array($input['refs']) || !array_is_list($input['refs'])
            || count($input['refs']) > self::MAX_REFS) {
            return Outcome::refuse('M17_INPUT_SET_INVALID');
        }
        $refs = [];
        $seen = [];
        foreach ($input['refs'] as $ref) {
            if (!is_array($ref) || !Vocabulary::exactKeys($ref, self::ROW_KEYS)
                || !Vocabulary::id($ref['ref_id']) || !Vocabulary::id($ref['source_ref_id'])
                || $ref['ref_type'] !== $input['data_class']
                || !Vocabulary::utc($ref['observed_at_utc'])
                || !is_string($ref['payload_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $ref['payload_sha256']) !== 1
                || ($ref['magnitude_milli'] !== null
                    && (!is_int($ref['magnitude_milli']) || $ref['magnitude_milli'] < 0
                        || $ref['magnitude_milli'] > 1000000))
                || !in_array($ref['polarity'], Vocabulary::POLARITY, true)
                || !is_bool($ref['conflict_flag']) || !is_bool($ref['diagnostic_flag'])) {
                return Outcome::refuse('M17_INPUT_REF_INVALID');
            }
            if (isset($seen[$ref['ref_id']])) {
                return Outcome::refuse('M17_INPUT_DUPLICATE_REF');
            }
            $seen[$ref['ref_id']] = true;
            $refs[] = $ref;
        }
        usort($refs, static fn (array $a, array $b): int =>
            [$a['observed_at_utc'], $a['ref_id']] <=> [$b['observed_at_utc'], $b['ref_id']]);
        $result = [
            'version' => self::VERSION,
            'scope' => $input['scope'],
            'data_class' => $input['data_class'],
            'refs' => $refs,
            'reference_count' => count($refs),
            'rights_disposition' => 'NOT_ASSESSED',
            'historical_reuse_admitted' => false,
        ];
        return Outcome::ok([...$result, 'input_digest' => Digest::canonicalDigest($result)]);
    }

    /** Integrity verification does not authenticate source rights or establish provenance. */
    public static function verify(array $candidate): Outcome
    {
        if (!Vocabulary::exactKeys($candidate, [
            'version', 'scope', 'data_class', 'refs', 'reference_count',
            'rights_disposition', 'historical_reuse_admitted', 'input_digest',
        ]) || ($candidate['version'] ?? null) !== self::VERSION
            || ($candidate['rights_disposition'] ?? null) !== 'NOT_ASSESSED'
            || ($candidate['historical_reuse_admitted'] ?? null) !== false
            || !is_string($candidate['input_digest'])
            || preg_match('/^[a-f0-9]{64}$/D', $candidate['input_digest']) !== 1) {
            return Outcome::refuse('M17_INPUT_SET_SHAPE_INVALID');
        }
        $input = [
            'scope' => $candidate['scope'], 'data_class' => $candidate['data_class'],
            'refs' => $candidate['refs'],
        ];
        $fresh = self::freeze($input);
        if (!$fresh->isOk()) {
            return Outcome::refuse('M17_INPUT_SET_SHAPE_INVALID');
        }
        if ($fresh->value() !== $candidate) {
            return Outcome::refuse('M17_INPUT_SET_REPLAY_MISMATCH');
        }
        return Outcome::ok(['digest_verified' => true, 'historical_reuse_admitted' => false]);
    }
}
