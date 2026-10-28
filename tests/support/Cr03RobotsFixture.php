<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Ingestion\RobotsPolicyReader;

/**
 * Synthetic test metadata. No actual source-use right, secret or network
 * authorization. All times are a fixed test epoch independent of wall time.
 */
final class Cr03RobotsFixture
{
    public const NOW_MS = 1793145600000;
    public const ORIGIN = 'https://example.org';
    private const UUIDS = [
        'tenant_id' => '00000000-0000-4000-8000-000000000001',
        'workspace_id' => '00000000-0000-4000-8000-000000000002',
        'source_ref_id' => '00000000-0000-4000-8000-000000000003',
        'source_card_id' => '00000000-0000-4000-8000-000000000004',
    ];

    /** @return array<string,mixed> */
    public static function scope(string $path = '/pricing/test', array $override = []): array
    {
        $base = [
            'kind' => RobotsPolicyReader::SCOPE_VERSION,
            ...self::UUIDS,
            'allowed_url_scope' => self::ORIGIN . '/pricing',
            'target_url' => self::ORIGIN . $path,
            'robots_url' => self::ORIGIN . '/robots.txt',
            'product_token' => 'LoopiaAllfather',
            'purpose' => 'OFFLINE_ROBOTS_INTERPRETATION',
            'owner_source_use' => 'NOT_ADMITTED',
            'native_execution_admitted' => false,
            'now_ms' => self::NOW_MS,
            'last_capture_at_ms' => null,
            'min_interval_ms' => 0,
            'retry_after_until_ms' => null,
            'remaining_attempts' => 2,
            'remaining_bytes' => 524288,
        ];
        return array_replace($base, $override);
    }

    /** @return array<string,mixed> */
    public static function source(string $body, array $override = []): array
    {
        $base = [
            'kind' => RobotsPolicyReader::SOURCE_VERSION,
            ...self::UUIDS,
            'origin' => self::ORIGIN,
            'robots_url' => self::ORIGIN . '/robots.txt',
            'status' => 'FETCHED',
            'http_status' => 200,
            'content_type' => 'text/plain; charset=utf-8',
            'body' => $body,
            'raw_sha256' => hash('sha256', $body),
            'observed_at_ms' => self::NOW_MS - 3601000,
            'received_at_ms' => self::NOW_MS - 3600000,
        ];
        return array_replace($base, $override);
    }

    /** @return array<string,mixed> */
    public static function event(string $status, ?int $httpStatus): array
    {
        return self::source('', [
            'status' => $status,
            'http_status' => $httpStatus,
            'content_type' => null,
            'body' => null,
            'raw_sha256' => null,
            'observed_at_ms' => $status === 'NOT_FETCHED' ? null : self::NOW_MS - 3000,
            'received_at_ms' => $status === 'NOT_FETCHED' ? null : self::NOW_MS - 2000,
        ]);
    }

    /** @return array<string,mixed> */
    public static function golden(): array
    {
        $path = dirname(__DIR__) . '/fixtures/robots/cr03_golden.json';
        return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }
}
