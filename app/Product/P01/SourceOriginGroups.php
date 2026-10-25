<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Outcome;

/**
 * LI-04: deterministic EXACT-BYTE grouping, explicitly NOT origin verification.
 *
 * One payload hash is one exact byte class, not an event, business fact,
 * independent source or corroborating witness. U02 owner assertions and
 * rights-checking still have no runtime contract here.
 */
final class SourceOriginGroups
{
    public const VERSION = 'li04.byte-repeat-groups.pure.v1';
    public const MAX_CAPTURES = 500;

    /**
     * @param array{tenant_id:string,workspace_id:string} $scope
     * @param list<array<string,mixed>> $captures
     */
    public static function inspect(array $scope, array $captures): Outcome
    {
        if (!self::validId($scope['tenant_id'] ?? null)
            || !self::validId($scope['workspace_id'] ?? null)
            || count(array_diff(array_keys($scope), ['tenant_id','workspace_id'])) !== 0) {
            return Outcome::refuse('LI04_ORIGIN_SCOPE_INVALID');
        }
        if (!array_is_list($captures) || count($captures) > self::MAX_CAPTURES) {
            return Outcome::refuse('LI04_ORIGIN_SET_UNBOUNDED');
        }
        $seen = [];
        $groups = [];
        $sources = [];
        $identity = [];
        foreach ($captures as $capture) {
            if (!is_array($capture)
                || array_diff(['capture_id','source_ref_id','payload_sha256','tenant_id','workspace_id'], array_keys($capture)) !== []
                || array_diff(array_keys($capture), ['capture_id','source_ref_id','payload_sha256','tenant_id','workspace_id']) !== []
                || !self::validId($capture['capture_id'])
                || !self::validId($capture['source_ref_id'])
                || ($capture['tenant_id'] ?? null) !== $scope['tenant_id']
                || ($capture['workspace_id'] ?? null) !== $scope['workspace_id']) {
                return Outcome::refuse('LI04_ORIGIN_SOURCE_SCOPE_OR_ID_INVALID');
            }
            $digest = $capture['payload_sha256'];
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return Outcome::refuse('LI04_ORIGIN_DIGEST_INVALID');
            }
            $id = $capture['capture_id'];
            if (isset($seen[$id])) return Outcome::refuse('LI04_ORIGIN_DUPLICATE_CAPTURE');
            $seen[$id] = true;
            $sources[$capture['source_ref_id']] = true;
            $groups[$digest][] = $id;
            $identity[] = implode("\x1f", [$id, $capture['source_ref_id'], $digest]);
        }
        sort($identity, SORT_STRING);
        ksort($groups, SORT_STRING);
        $equivalences = [];
        foreach ($groups as $digest => $ids) {
            sort($ids, SORT_STRING);
            $equivalences[] = [
                'payload_sha256' => $digest,
                'capture_ids' => $ids,
                'capture_count' => count($ids),
                'interpretation' => 'EXACT_BYTES_ONLY_NOT_COMMON_ORIGIN',
            ];
        }
        return Outcome::ok([
            'schema_version' => self::VERSION,
            'input_fingerprint' => hash('sha256', implode("\n", $identity)),
            'capture_count' => count($captures),
            'distinct_exact_payload_count' => count($equivalences),
            'exact_byte_repeat_count' => count($captures) - count($equivalences),
            'distinct_source_ref_count' => count($sources),
            'exact_payload_groups' => $equivalences,
            'event_identity' => 'UNKNOWN',
            'common_origin_assessments' => 'NOT_PERFORMED',
            'independent_corroboration_count' => null,
            'independent_sources_certified' => false,
            'business_materiality' => 'NOT_ASSESSED',
            'source_rights' => 'NOT_CHECKED_BY_PURE_GROUPER',
            'evidence_created' => false,
            'release_authorized' => false,
        ]);
    }

    private static function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value) === 1;
    }
}
