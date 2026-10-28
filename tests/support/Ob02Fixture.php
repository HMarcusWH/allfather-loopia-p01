<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Product\P01\ObservationAssessment;

/** Pure source-card shaped fixture, NOT native P01/M05 approval. */
final class Ob02Fixture
{
    public const AT = '2026-10-27T12:00:00.000Z';

    public static function uuid(int $id): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $id);
    }

    /** @return array<string,mixed> */
    public static function examples(): array
    {
        $file = dirname(__DIR__) . '/fixtures/ob02/metadata_coverage_v1.json';
        return json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    public static function actor(): array
    {
        return ['tenant_id'=>self::uuid(1), 'workspace_id'=>self::uuid(2),
            'actor_type'=>'OPERATOR','actor_id'=>self::uuid(9)];
    }

    /** @return array<string,mixed> */
    public static function native(int $sequence=1): array
    {
        return [
            'tenant_id'=>self::uuid(1),'workspace_id'=>self::uuid(2),
            'competitor_id'=>self::uuid(3),'monitored_source_id'=>self::uuid(4),
            'source_ref_id'=>self::uuid(5),'source_license_card_id'=>self::uuid(6),
            'source_snapshot_id'=>self::uuid(7),'evidence_id'=>self::uuid(8),
            'evidence_source_ref_id'=>self::uuid(5),'evidence_status'=>'CAPTURED',
            'snapshot_sequence'=>$sequence,
            'content_hash'=>str_repeat('a',64),'captured_at'=>self::AT,
        ];
    }

    /** @param list<string> $significance
     *  @return array<string,mixed>
     */
    public static function history(int $sequence,string $coverage,array $significance): array
    {
        $comparisons=[];
        foreach ($significance as $i=>$s) {
            $comparisons[]=['source_diff_id'=>self::uuid(20+$i),
                'structural_significance'=>$s,
                'detected_at'=>'2026-10-27T12:01:00.000Z'];
        }
        return [
            'source_snapshot_id'=>self::uuid(7),
            'monitored_source_id'=>self::uuid(4),
            'snapshot_sequence'=>$sequence,
            'captured_at'=>self::AT,
            'coverage_state'=>$coverage,
            'comparisons'=>$comparisons,
            'business_materiality'=>'NOT_ASSESSED',
            'observation_assessment'=>'NOT_IMPLEMENTED',
            'release_status'=>'NOT_INFERRED',
        ];
    }
}
