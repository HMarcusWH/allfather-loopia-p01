<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

/** Source-card-shaped TEST FIXTURE ONLY. Never imported by app factories. */
final class Cr01Fixture
{
    /** @return array<string,mixed> */
    public static function source(string $bytes): array
    {
        $hex = static fn (int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);
        return [
            'tenant_id' => $hex(1), 'workspace_id' => $hex(2),
            'monitored_source_id' => $hex(3), 'source_ref_id' => $hex(4),
            'source_card_id' => $hex(5), 'source_card_sha256' => str_repeat('a', 64),
            'capture_ref_id' => $hex(6), 'evidence_ref_id' => $hex(7),
            'raw_sha256' => hash('sha256', $bytes),
            'observed_at' => '2026-10-27T12:00:00.000Z',
            'known_at' => '2026-10-27T12:01:00.000Z',
            'media_type' => 'text/html', 'encoding' => 'UTF-8',
            'retention_class' => 'M05_PRIVATE_FIXTURE',
            'input_kind' => 'SYNTHETIC_PRIVATE_EVIDENCE_FIXTURE',
        ] + [];
    }

    /** @return array<string,mixed> */
    public static function cases(): array
    {
        $path = dirname(__DIR__) . '/fixtures/semantic_html/cr01_cases_v1.json';
        return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }
}
