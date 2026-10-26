<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * LI-06A offline candidate resolver. U04 M17 implementation authority is NOT adopted.
 * The caller's profiles, law floors, actor scope and calendar are all UNVERIFIED INPUTS.
 * Narrow-only arithmetic here can neither grant source use nor become release authority.
 */
final class ProfileResolver
{
    public const VERSION = 'li06a.offline-profile-resolver.v1';
    public const MAX_CANDIDATES = 48;
    public const MAX_FLOORS = 24;
    private const FIELDS = [
        'day_until_age_days', 'week_until_age_days', 'retention_expectation_days',
        'minimum_lineage', 'aggressiveness',
    ];
    private const CANDIDATE_KEYS = [
        'profile_id', 'level', 'scope', 'data_class', 'status', 'expires_at_utc',
        'reason_code', 'changes',
    ];
    private const FLOOR_KEYS = ['floor', 'effect', 'basis_ref_safe', 'minimum_retention_days'];

    /** @param array<string,mixed> $input */
    public static function resolve(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, [
            'scope', 'data_class', 'evaluation_time_utc', 'candidates', 'floors',
        ]) || !Vocabulary::scope($input['scope'])
            || !Vocabulary::utc($input['evaluation_time_utc'])
            || !is_string($input['data_class'])
            || preg_match('/^[A-Z_]{1,64}$/D', $input['data_class']) !== 1
            || !is_array($input['candidates']) || !array_is_list($input['candidates'])
            || count($input['candidates']) > self::MAX_CANDIDATES
            || !is_array($input['floors']) || !array_is_list($input['floors'])
            || count($input['floors']) > self::MAX_FLOORS) {
            return Outcome::refuse('M17_PROFILE_INPUT_INVALID');
        }
        $supported = in_array($input['data_class'], Vocabulary::SUPPORTED_CLASSES, true);
        // Fail conservatively on unsupported classes, including known inert families. We
        // produce an explicit non-executable description; never pretend to have M13/M14 law.
        if (!$supported && ($input['candidates'] !== [] || $input['floors'] !== [])) {
            return Outcome::refuse('M17_PROFILE_UNSUPPORTED_CLASS_NOT_OVERRIDEABLE');
        }
        $default = match ($input['data_class']) {
            'SOURCE_DIFF' => ['day_until_age_days'=>14, 'week_until_age_days'=>90,
                'retention_expectation_days'=>365, 'minimum_lineage'=>'EVERY_INPUT_REF',
                'aggressiveness'=>'HIGH'],
            'COMPANY_SIGNAL' => ['day_until_age_days'=>30, 'week_until_age_days'=>180,
                'retention_expectation_days'=>730, 'minimum_lineage'=>'EVERY_INPUT_REF',
                'aggressiveness'=>'MEDIUM'],
            default => ['day_until_age_days'=>36500, 'week_until_age_days'=>36500,
                'retention_expectation_days'=>36500, 'minimum_lineage'=>'EVERY_INPUT_REF',
                'aggressiveness'=>'NONE'],
        };
        $resolved = $default;
        $provenance = array_fill_keys(self::FIELDS, $supported ? 'MODULE_DEFAULT' : 'CONSERVATIVE_DEFAULT');
        $rejected = [];
        $conflicts = [];
        $ignored = [];
        $appliedIds = [];
        $eventHold = !$supported;
        $ids = [];
        $candidates = $input['candidates'];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || !Vocabulary::exactKeys($candidate, self::CANDIDATE_KEYS)
                || !Vocabulary::id($candidate['profile_id'])
                || !in_array($candidate['level'], Vocabulary::LEVELS, true)
                || !Vocabulary::scope($candidate['scope'])
                || !in_array($candidate['status'], ['ACTIVE','SUPERSEDED','SUSPENDED'], true)
                || !is_array($candidate['changes']) || $candidate['changes'] === []
                || !self::changesValid($candidate['changes'])
                || !is_string($candidate['data_class'])
                || $candidate['data_class'] !== $input['data_class']) {
                return Outcome::refuse('M17_PROFILE_CANDIDATE_INVALID');
            }
            if (isset($ids[$candidate['profile_id']])) {
                return Outcome::refuse('M17_PROFILE_DUPLICATE_CANDIDATE');
            }
            $ids[$candidate['profile_id']] = true;
            if ($candidate['scope'] !== $input['scope']) {
                return Outcome::refuse('M17_PROFILE_CROSS_SCOPE_REFUSED');
            }
            if (!in_array($candidate['level'], Vocabulary::CANDIDATE_LEVELS, true)) {
                // Modality and entity owners not adopted in this PHP projection.
                return Outcome::refuse('M17_PROFILE_LEVEL_INERT');
            }
            $special = in_array($candidate['level'], ['RISK_OVERRIDE','OPERATOR_OVERRIDE'], true);
            if (($special && !Vocabulary::id($candidate['reason_code']))
                || (!$special && $candidate['reason_code'] !== null)
                || ($candidate['level'] === 'OPERATOR_OVERRIDE'
                    && !Vocabulary::utc($candidate['expires_at_utc']))
                || ($candidate['level'] !== 'OPERATOR_OVERRIDE'
                    && $candidate['expires_at_utc'] !== null)) {
                return Outcome::refuse('M17_PROFILE_OVERRIDE_CONTRACT_INVALID');
            }
        }
        usort($candidates, static function (array $a, array $b): int {
            $la = array_search($a['level'], Vocabulary::LEVELS, true);
            $lb = array_search($b['level'], Vocabulary::LEVELS, true);
            return [$la, $a['profile_id']] <=> [$lb, $b['profile_id']];
        });
        $levelValues = [];
        foreach ($candidates as $candidate) {
            if ($candidate['status'] !== 'ACTIVE'
                || ($candidate['expires_at_utc'] !== null
                    && $candidate['expires_at_utc'] <= $input['evaluation_time_utc'])) {
                $ignored[] = $candidate['profile_id'];
                continue;
            }
            foreach ($candidate['changes'] as $field => $value) {
                $key = $candidate['level'] . ':' . $field;
                if (isset($levelValues[$key]) && $levelValues[$key] !== $value) {
                    $conflicts[$key] = ['level'=>$candidate['level'],
                        'data_class'=>$input['data_class'], 'field'=>$field,
                        'resolved_by'=>'NARROWEST_WINS'];
                }
                $levelValues[$key] = $value;
                if (!self::narrows($field, $resolved[$field], $value)) {
                    $rejected[] = [
                        'level'=>$candidate['level'], 'data_class'=>$input['data_class'],
                        'field'=>$field, 'reason_code'=>'PROFILE_WIDENING_REJECTED',
                        'profile_id'=>$candidate['profile_id'],
                    ];
                    continue;
                }
                $resolved[$field] = $value;
                $provenance[$field] = $candidate['level'];
            }
            $appliedIds[] = $candidate['profile_id'];
        }
        if ($resolved['day_until_age_days'] > $resolved['week_until_age_days']) {
            return Outcome::refuse('M17_PROFILE_LADDER_INVALID');
        }
        $floors = $input['floors'];
        foreach ($floors as $floor) {
            if (!is_array($floor) || !Vocabulary::exactKeys($floor, self::FLOOR_KEYS)
                || !in_array($floor['floor'], Vocabulary::FLOORS, true)
                || !Vocabulary::id($floor['basis_ref_safe'])
                || !in_array($floor['effect'], [
                    'FORCE_EVENT_LEVEL','FORCE_RETENTION_FLOOR','FORBID','REQUIRE_FULL_LINEAGE',
                ], true)) {
                return Outcome::refuse('M17_PROFILE_FLOOR_INVALID');
            }
            $allowedEffects = match ($floor['floor']) {
                'LEGAL_HOLD', 'AUDIT_HOLD', 'EXECUTION_AUDIT_HOLD',
                'PROMPT_PROMOTION_AUDIT_HOLD', 'ADAPTATION_DEPLOYMENT_AUDIT_HOLD'
                    => ['FORCE_EVENT_LEVEL', 'FORCE_RETENTION_FLOOR'],
                'PRIVACY_RETENTION' => ['FORCE_RETENTION_FLOOR'],
                'EVIDENCE_LINEAGE_MINIMUM' => ['REQUIRE_FULL_LINEAGE'],
                'SOURCE_USE_CEILING', 'CUSTOMER_OUTPUT_CEILING', 'TENANT_ISOLATION'
                    => ['FORBID'],
            };
            if (!in_array($floor['effect'], $allowedEffects, true)) {
                return Outcome::refuse('M17_PROFILE_FLOOR_EFFECT_INVALID');
            }
            if ($floor['effect'] === 'FORCE_RETENTION_FLOOR') {
                if (!is_int($floor['minimum_retention_days'])
                    || $floor['minimum_retention_days'] < 0
                    || $floor['minimum_retention_days'] > 36500) {
                    return Outcome::refuse('M17_PROFILE_FLOOR_INVALID');
                }
            } elseif ($floor['minimum_retention_days'] !== null) {
                return Outcome::refuse('M17_PROFILE_FLOOR_INVALID');
            }
            if ($floor['effect'] === 'FORCE_EVENT_LEVEL') {
                $eventHold = true;
            } elseif ($floor['effect'] === 'FORCE_RETENTION_FLOOR') {
                if ($resolved['retention_expectation_days'] < $floor['minimum_retention_days']) {
                    $resolved['retention_expectation_days'] = $floor['minimum_retention_days'];
                    $provenance['retention_expectation_days'] = 'LOCKED_LAW';
                }
            } elseif ($floor['effect'] === 'REQUIRE_FULL_LINEAGE') {
                $resolved['minimum_lineage'] = 'EVERY_INPUT_REF';
                $provenance['minimum_lineage'] = 'LOCKED_LAW';
            }
            // FORBID cannot be transformed into permission. There are no permissions in this contract.
        }
        usort($floors, static fn (array $a, array $b): int =>
            [$a['floor'],$a['effect'],$a['basis_ref_safe']] <=>
            [$b['floor'],$b['effect'],$b['basis_ref_safe']]);
        usort($rejected, static fn (array $a,array $b): int =>
            [$a['level'],$a['field'],$a['profile_id']] <=>
            [$b['level'],$b['field'],$b['profile_id']]);
        $conflicts = array_values($conflicts);
        usort($conflicts, static fn (array $a, array $b): int =>
            [$a['level'],$a['field']] <=> [$b['level'],$b['field']]);
        sort($ignored, SORT_STRING);
        sort($appliedIds, SORT_STRING);
        $result = [
            'version'=>self::VERSION,
            'status'=>$supported ? 'OFFLINE_NOT_ADMITTED' : 'CONSERVATIVE_DEFAULT_NOT_EXECUTABLE',
            'scope'=>$input['scope'], 'data_class'=>$input['data_class'],
            'evaluation_time_utc'=>$input['evaluation_time_utc'],
            'resolved'=>[...$resolved,
                'summary_shape'=>'STATS_ONLY',
                'conflict_behavior'=>'ITEMIZE_ALWAYS',
                'release_eligible'=>false, 'customer_output_eligible'=>false],
            'field_provenance'=>$provenance,
            'applied_floor_candidates'=>$floors,
            'event_level_hold'=>$eventHold,
            'applied_profile_ids'=>$appliedIds,
            'ignored_profile_ids'=>$ignored,
            'rejected_widenings'=>$rejected,
            'same_level_conflicts'=>$conflicts,
            'owner_input_verified'=>false,
            'profile_authority_adopted'=>false,
            'historical_reuse_admitted'=>false,
        ];
        return Outcome::ok([...$result, 'resolution_digest'=>Digest::canonicalDigest($result)]);
    }

    /** Integrity of a value only; cannot authenticate external law floors or grant authority. */
    public static function verify(array $value): Outcome
    {
        if (!isset($value['resolution_digest']) || !is_string($value['resolution_digest'])
            || preg_match('/^[a-f0-9]{64}$/D', $value['resolution_digest']) !== 1
            || ($value['owner_input_verified'] ?? null) !== false
            || ($value['profile_authority_adopted'] ?? null) !== false
            || ($value['historical_reuse_admitted'] ?? null) !== false
            || ($value['resolved']['release_eligible'] ?? null) !== false
            || ($value['resolved']['customer_output_eligible'] ?? null) !== false) {
            return Outcome::refuse('M17_PROFILE_DIGEST_OR_SHAPE_INVALID');
        }
        $digest = $value['resolution_digest'];
        unset($value['resolution_digest']);
        try {
            if (!hash_equals($digest, Digest::canonicalDigest($value))) {
                return Outcome::refuse('M17_PROFILE_DIGEST_MISMATCH');
            }
        } catch (\Throwable) {
            return Outcome::refuse('M17_PROFILE_DIGEST_OR_SHAPE_INVALID');
        }
        return Outcome::ok(['integrity_only'=>true, 'historical_reuse_admitted'=>false]);
    }

    private static function changesValid(array $changes): bool
    {
        foreach ($changes as $field=>$value) {
            if (!in_array($field, self::FIELDS, true)) {
                return false;
            }
            if (in_array($field, ['day_until_age_days','week_until_age_days',
                'retention_expectation_days'], true)) {
                if (!is_int($value) || $value < 0 || $value > 36500) {
                    return false;
                }
            } elseif ($field === 'minimum_lineage'
                ? !in_array($value, Vocabulary::LINEAGE, true)
                : !in_array($value, Vocabulary::AGGRESSIVENESS, true)) {
                return false;
            }
        }
        return true;
    }

    private static function narrows(string $field, mixed $previous, mixed $candidate): bool
    {
        if (in_array($field, ['day_until_age_days', 'week_until_age_days',
            'retention_expectation_days'], true)) {
            return $candidate >= $previous;
        }
        if ($field === 'minimum_lineage') {
            return $previous === $candidate
                || ($candidate === 'EVERY_INPUT_REF'
                    && $previous === 'REPRESENTATIVE_REFS_PLUS_COUNTS');
        }
        $rank = array_flip(Vocabulary::AGGRESSIVENESS);
        return $rank[$candidate] <= $rank[$previous];
    }
}
