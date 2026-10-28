<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;

/**
 * CR-01 closed, candidate-only input/output contract.
 *
 * The bytes are supplied by the caller and never fetched here. A synthetic
 * fixture is not evidence of an approved native M05 retrieval, and this
 * interface cannot be made live by changing a flag. CR-04 owns any future
 * authenticated P01/M05 adapter and must introduce a separately reviewed
 * native authorization path rather than accepting fixture declarations.
 */
final class DocumentProjectionContract
{
    public const VERSION = 'cr01.document-projection.v1';
    public const PARSER_VERSION = 'php-dom-html5-native.v1';
    public const NORMALIZER_VERSION = 'cr01.utf8-whitespace.v1';
    public const LIMITS_VERSION = 'cr01.limits.v1';

    public const TYPES = [
        'NAVIGATION',
        'PRICING',
        'PRODUCT_SERVICE',
        'CAREERS',
        'NEWS_PRESS',
        'STRUCTURED_META',
        'FOOTER_LEGAL',
        'UNCLASSIFIED',
    ];

    /** All limits are finite and identity-bearing. No per-request overrides. */
    public const LIMITS = [
        'max_input_bytes' => 131072,
        'max_markup_openers' => 3500,
        'max_nodes' => 5000,
        'max_depth' => 48,
        'max_attributes' => 6000,
        'max_sections' => 64,
        'max_text_nodes_per_section' => 1000,
        'max_section_text_bytes' => 4096,
        'max_excerpt_bytes' => 256,
        'max_nav_items' => 48,
        'max_total_wall_ms' => 1500,
    ];

    private const SOURCE_KEYS = [
        'capture_ref_id', 'encoding', 'evidence_ref_id', 'input_kind',
        'known_at', 'media_type', 'monitored_source_id', 'observed_at',
        'raw_sha256', 'retention_class', 'source_card_id', 'source_card_sha256',
        'source_ref_id', 'tenant_id', 'workspace_id',
    ];

    /** @return Outcome<array<string,mixed>> */
    public static function validateSource(array $source, string $bytes): Outcome
    {
        $keys = array_keys($source);
        sort($keys, SORT_STRING);
        if ($keys !== self::SOURCE_KEYS) {
            return Outcome::refuse('CR01_SOURCE_ENVELOPE_INVALID');
        }
        if ($source['input_kind'] !== 'SYNTHETIC_PRIVATE_EVIDENCE_FIXTURE'
            || $source['retention_class'] !== 'M05_PRIVATE_FIXTURE') {
            return Outcome::refuse('CR01_NATIVE_OWNER_BINDING_NOT_IMPLEMENTED');
        }
        foreach ([
            'capture_ref_id', 'evidence_ref_id', 'monitored_source_id', 'source_card_id',
            'source_ref_id', 'tenant_id', 'workspace_id',
        ] as $key) {
            if (!is_string($source[$key]) || !Ids::isValid($source[$key])) {
                return Outcome::refuse('CR01_SCOPE_OR_ORIGIN_INVALID');
            }
        }
        if ($source['tenant_id'] === $source['workspace_id']
            || $source['capture_ref_id'] === $source['evidence_ref_id']
            || $source['monitored_source_id'] === $source['source_ref_id']) {
            return Outcome::refuse('CR01_SCOPE_OR_ORIGIN_AMBIGUOUS');
        }
        foreach (['source_card_sha256', 'raw_sha256'] as $key) {
            if (!is_string($source[$key])
                || preg_match('/^[a-f0-9]{64}$/D', $source[$key]) !== 1) {
                return Outcome::refuse('CR01_SOURCE_DIGEST_INVALID');
            }
        }
        if ($source['media_type'] !== 'text/html' || $source['encoding'] !== 'UTF-8') {
            return Outcome::refuse('CR01_MEDIA_OR_ENCODING_UNSUPPORTED');
        }
        foreach (['observed_at', 'known_at'] as $field) {
            if (!is_string($source[$field]) || !Clock::isCanonical($source[$field])) {
                return Outcome::refuse('CR01_SOURCE_CLOCK_INVALID');
            }
        }
        if ($source['known_at'] < $source['observed_at']) {
            return Outcome::refuse('CR01_SOURCE_CLOCK_ORDER');
        }
        if (!hash_equals($source['raw_sha256'], hash('sha256', $bytes))) {
            return Outcome::refuse('CR01_ORIGINAL_BYTE_DIGEST_MISMATCH');
        }
        return Outcome::ok($source);
    }

    /** Returns errors instead of trusting an interpreter-authored projection. */
    public static function validateProjection(array $projection, array $source): Outcome
    {
        if (($projection['schema_version'] ?? null) !== self::VERSION
            || ($projection['parser_version'] ?? null) !== self::PARSER_VERSION
            || ($projection['normalizer_version'] ?? null) !== self::NORMALIZER_VERSION
            || ($projection['limits_profile'] ?? null) !== self::LIMITS_VERSION
            || ($projection['parser_backend'] ?? null) !== 'Dom\\HTMLDocument'
            || ($projection['derived_not_primary_evidence'] ?? null) !== true
            || ($projection['production_authorized'] ?? null) !== false) {
            return Outcome::refuse('CR01_PROJECTION_CONTRACT_INVALID');
        }
        $binding = $projection['source_binding'] ?? null;
        if (!is_array($binding)
            || ($binding['tenant_id'] ?? null) !== $source['tenant_id']
            || ($binding['workspace_id'] ?? null) !== $source['workspace_id']
            || ($binding['source_ref_id'] ?? null) !== $source['source_ref_id']
            || ($binding['source_card_id'] ?? null) !== $source['source_card_id']
            || ($binding['source_card_sha256'] ?? null) !== $source['source_card_sha256']
            || ($binding['capture_ref_id'] ?? null) !== $source['capture_ref_id']
            || ($binding['evidence_ref_id'] ?? null) !== $source['evidence_ref_id']
            || ($binding['raw_sha256'] ?? null) !== $source['raw_sha256']) {
            return Outcome::refuse('CR01_PROJECTION_SOURCE_BINDING_MISMATCH');
        }
        $sections = $projection['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)
            || count($sections) > self::LIMITS['max_sections']) {
            return Outcome::refuse('CR01_SECTION_LIMIT_OR_SHAPE');
        }
        $ids = [];
        $lastOrdinal = -1;
        foreach ($sections as $section) {
            if (!is_array($section)
                || !in_array($section['type'] ?? null, self::TYPES, true)
                || ($section['source_sha256'] ?? null) !== $source['raw_sha256']
                || ($section['source_card_id'] ?? null) !== $source['source_card_id']
                || ($section['parser_version'] ?? null) !== self::PARSER_VERSION
                || ($section['source_offset_verified'] ?? null) !== false
                || !array_key_exists('raw_start_byte', $section)
                || !array_key_exists('raw_length_bytes', $section)
                || $section['raw_start_byte'] !== null
                || $section['raw_length_bytes'] !== null
                || ($section['locator_kind'] ?? null) !== 'DERIVED_DOM_PATH'
                || !is_string($section['selector'] ?? null)
                || strlen($section['selector']) > 320
                || !is_int($section['ordinal'] ?? null)
                || $section['ordinal'] <= $lastOrdinal
                || !is_string($section['origin_id'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $section['origin_id']) !== 1
                || isset($ids[$section['origin_id']])) {
                return Outcome::refuse('CR01_SECTION_PROVENANCE_INVALID');
            }
            $lastOrdinal = $section['ordinal'];
            $ids[$section['origin_id']] = true;
        }
        return Outcome::ok($projection);
    }
}
