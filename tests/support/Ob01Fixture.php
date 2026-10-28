<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\PromptOps\ObservationMaterialityV2;

/**
 * OB-01 offline-development fixtures. Explicitly not P01/M05 current source
 * rights, not real sensor data, and not an authenticated model result.
 */
final class Ob01Fixture
{
    public const TENANT_ID = '00000000-0000-4000-8000-000000000021';
    public const WORKSPACE_ID = '00000000-0000-4000-8000-000000000022';
    public const SOURCE_ID = '00000000-0000-4000-8000-000000000023';
    public const BEFORE_ID = 'fixture-src-before';
    public const AFTER_ID = 'fixture-src-after';
    public const TENANT_CONTEXT_ID = 'fixture-owner-context';

    /** @return array<string,mixed> */
    public static function loadCases(): array
    {
        $path = dirname(__DIR__) . '/fixtures/ob01/materiality_v2_synthetic.json';
        return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    public static function scenario(array $row): array
    {
        return [
            'schema_version' => ObservationMaterialityV2::VERSION,
            'synthetic_scope' => ObservationMaterialityV2::FIXTURE_SCOPE,
            'tenant_id' => self::TENANT_ID,
            'workspace_id' => self::WORKSPACE_ID,
            'before_evidence_id' => self::BEFORE_ID,
            'after_evidence_id' => self::AFTER_ID,
            'before_text' => $row['before'],
            'after_text' => $row['after'],
            'case_kind' => $row['kind'],
            'source_consistency' => $row['consistency'],
            'structural_magnitude' => $row['structural'],
            'language' => 'en',
            'proposed_significance' => $row['significance'],
            'attention' => $row['attention'],
            'assessment_reason' => $row['reason'],
            'tenant_context_ref_id' => $row['tenant_context'] ? self::TENANT_CONTEXT_ID : null,
        ];
    }

    /** @param array<string,mixed> $scenario
     *  @return array{envelope:array<string,mixed>,digest:string}
     */
    public static function context(array $scenario): array
    {
        $evidence = [
            [
                'evidence_id' => $scenario['before_evidence_id'],
                'basis' => 'CAPTURED_SOURCE_OBSERVATION',
                'tenant_id' => $scenario['tenant_id'],
                'workspace_id' => $scenario['workspace_id'],
                'text_sha256' => hash('sha256', $scenario['before_text']),
                'binding' => [
                    'monitored_source_id' => self::SOURCE_ID,
                    'snapshot_sequence' => 1,
                ],
            ],
            [
                'evidence_id' => $scenario['after_evidence_id'],
                'basis' => 'CAPTURED_SOURCE_OBSERVATION',
                'tenant_id' => $scenario['tenant_id'],
                'workspace_id' => $scenario['workspace_id'],
                'text_sha256' => hash('sha256', $scenario['after_text']),
                'binding' => [
                    'monitored_source_id' => self::SOURCE_ID,
                    'snapshot_sequence' => 2,
                ],
            ],
        ];
        if ($scenario['tenant_context_ref_id'] !== null) {
            $evidence[] = [
                'evidence_id' => $scenario['tenant_context_ref_id'],
                'basis' => 'TENANT_PROVIDED',
                'tenant_id' => $scenario['tenant_id'],
                'workspace_id' => $scenario['workspace_id'],
            ];
        }
        $envelope = [
            'fixture_scope' => ObservationMaterialityV2::FIXTURE_SCOPE,
            'tenant_id' => $scenario['tenant_id'],
            'workspace_id' => $scenario['workspace_id'],
            'evidence' => $evidence,
        ];
        return ['envelope' => $envelope, 'digest' => Digest::canonicalDigest($envelope)];
    }
}
