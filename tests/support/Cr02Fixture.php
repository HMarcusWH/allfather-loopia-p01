<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\DocumentProjectionContract;

/**
 * CR-02 pure PHP 8.2 fixture: constructs a CR-01-shaped, exactly digested
 * SYNTHETIC candidate without claiming HTML5 actually parsed on this host.
 * On PHP 8.5 the separate native workflow runs REAL CR-01 parser outputs.
 */
final class Cr02Fixture
{
    /** @return array<string,mixed> */
    public static function cases(): array
    {
        $file = dirname(__DIR__) . '/fixtures/semantic_html/semantic_delta_vectors.json';
        return json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $c
     *  @return array<string,mixed>
     */
    public static function syntheticProjection(array $c, string $side): array
    {
        if (!in_array($side, ['before','after'], true)) {
            throw new \InvalidArgumentException('CR02_ONLY_BEFORE_AFTER');
        }
        $suffix = $side === 'before' ? '0' : '1';
        $uuid = static fn (string $last): string => '00000000-0000-4000-8000-0000000000' . $last;
        $html = $c[$side . '_html'];
        $source = [
            'tenant_id' => $uuid('01'), 'workspace_id' => $uuid('02'),
            'monitored_source_id' => $uuid('03'),
            'source_ref_id' => $uuid('04'),
            'source_card_id' => $uuid('05'),
            'source_card_sha256' => str_repeat('c', 64),
            'capture_ref_id' => $uuid('1' . $suffix),
            'evidence_ref_id' => $uuid('2' . $suffix),
            'raw_sha256' => hash('sha256', $html),
            'observed_at' => $side === 'before' ? '2026-10-27T12:00:00.000Z' : '2026-10-27T12:01:00.000Z',
            'known_at' => $side === 'before' ? '2026-10-27T12:00:30.000Z' : '2026-10-27T12:01:30.000Z',
            'retention_class' => 'M05_PRIVATE_FIXTURE',
        ];
        $specs = [[
            'type' => $c['type'],
            'text' => $c[$side . '_text'],
            'selector' => $c[$side . '_selector'],
            'nav' => $c[$side . '_nav'],
        ]];
        foreach ($c['extra_' . $side . '_sections'] ?? [] as $extra) {
            $specs[] = $extra + ['nav' => []];
        }
        $sections = [];
        foreach ($specs as $i => $spec) {
            $text = $spec['text'];
            $excerpt = mb_strcut($text, 0, 256, 'UTF-8');
            $nav = array_map(static fn (string $x): string => hash('sha256', $x), $spec['nav']);
            $sections[] = [
                'ordinal' => $i + 1,
                'type' => $spec['type'],
                'origin_id' => hash('sha256', $source['capture_ref_id'] . "\0"
                    . $source['source_ref_id'] . "\0" . $spec['selector']),
                'selector' => $spec['selector'],
                'locator_kind' => 'DERIVED_DOM_PATH',
                'raw_start_byte' => null,
                'raw_length_bytes' => null,
                'source_offset_verified' => false,
                'source_sha256' => $source['raw_sha256'],
                'source_card_id' => $source['source_card_id'],
                'parser_version' => DocumentProjectionContract::PARSER_VERSION,
                'text_sha256' => hash('sha256', $text),
                'text_bytes' => strlen($text),
                'excerpt' => $excerpt,
                'excerpt_truncated' => strlen($excerpt) < strlen($text),
                'text_truncated' => false,
                'ordered_item_digests' => $nav,
                'interpretation_only' => true,
                'warnings' => [],
            ];
        }
        $projection = [
            'schema_version' => DocumentProjectionContract::VERSION,
            'parser_version' => DocumentProjectionContract::PARSER_VERSION,
            'normalizer_version' => DocumentProjectionContract::NORMALIZER_VERSION,
            'limits_profile' => DocumentProjectionContract::LIMITS_VERSION,
            'parser_backend' => 'Dom\\HTMLDocument',
            'source_binding' => $source,
            'sections' => $sections,
            'warnings' => [
                'DERIVED_DOM_LOCATORS_NOT_RAW_BYTE_POSITIONS',
                'HTML5_REPAIR_DETAILS_UNVERIFIED',
            ],
            'node_count' => max(3, count($sections) * 2),
            'section_count' => count($sections),
            'raw_bytes_stored_here' => false,
            'derived_not_primary_evidence' => true,
            'business_materiality' => 'NOT_ASSESSED',
            'customer_visible' => false,
            'production_authorized' => false,
        ];
        $projection['projection_digest'] = Digest::canonicalDigest($projection);
        return $projection;
    }
}
