<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;

/**
 * CR-02: read-only, bounded fingerprints of CR-01 DocumentProjection.v1.
 *
 * This is derived, synthetic-only provenance. It cannot authenticate a source
 * rights decision or verify original bytes from an interpreter-supplied hash.
 * Full SHA-256 values are retained; normalized excerpts are NOT emitted.
 */
final class SectionFingerprint
{
    public const VERSION = 'cr02.section-fingerprint.v1';
    public const MAX_SECTIONS = 64;
    public const MAX_TOKEN_MATCHES = 32;
    public const MAX_CONTENT_BYTES = 4096;

    private const TOP_KEYS = [
        'business_materiality', 'customer_visible', 'derived_not_primary_evidence',
        'limits_profile', 'node_count', 'normalizer_version', 'parser_backend',
        'parser_version', 'production_authorized', 'projection_digest',
        'raw_bytes_stored_here', 'schema_version', 'section_count', 'sections',
        'source_binding', 'warnings',
    ];
    private const BINDING_KEYS = [
        'capture_ref_id', 'evidence_ref_id', 'known_at', 'monitored_source_id',
        'observed_at', 'raw_sha256', 'retention_class', 'source_card_id',
        'source_card_sha256', 'source_ref_id', 'tenant_id', 'workspace_id',
    ];
    private const SECTION_KEYS = [
        'excerpt', 'excerpt_truncated', 'interpretation_only', 'locator_kind',
        'ordinal', 'ordered_item_digests', 'origin_id', 'parser_version',
        'raw_length_bytes', 'raw_start_byte', 'selector', 'source_card_id',
        'source_offset_verified', 'source_sha256', 'text_bytes', 'text_sha256',
        'text_truncated', 'type', 'warnings',
    ];
    private const HEX = '/^[a-f0-9]{64}$/D';

    /**
     * @return Outcome<array{source:array<string,mixed>,sections:list<array<string,mixed>>,warnings:list<string>}>
     */
    public static function fromProjection(array $doc): Outcome
    {
        if (!self::exactKeys($doc, self::TOP_KEYS)
            || ($doc['schema_version'] ?? null) !== DocumentProjectionContract::VERSION
            || ($doc['parser_version'] ?? null) !== DocumentProjectionContract::PARSER_VERSION
            || ($doc['normalizer_version'] ?? null) !== DocumentProjectionContract::NORMALIZER_VERSION
            || ($doc['limits_profile'] ?? null) !== DocumentProjectionContract::LIMITS_VERSION
            || ($doc['parser_backend'] ?? null) !== 'Dom\\HTMLDocument'
            || ($doc['derived_not_primary_evidence'] ?? null) !== true
            || ($doc['production_authorized'] ?? null) !== false
            || ($doc['customer_visible'] ?? null) !== false
            || ($doc['raw_bytes_stored_here'] ?? null) !== false
            || ($doc['business_materiality'] ?? null) !== 'NOT_ASSESSED'
            || !is_int($doc['node_count']) || $doc['node_count'] < 1
            || $doc['node_count'] > DocumentProjectionContract::LIMITS['max_nodes']
            || !is_array($doc['sections']) || !array_is_list($doc['sections'])
            || count($doc['sections']) > self::MAX_SECTIONS
            || !is_int($doc['section_count']) || $doc['section_count'] !== count($doc['sections'])
            || !is_array($doc['warnings']) || !array_is_list($doc['warnings'])
            || count($doc['warnings']) > 16) {
            return Outcome::refuse('CR02_PROJECTION_SHAPE_OR_AUTHORITY');
        }
        foreach ($doc['warnings'] as $warning) {
            if (!is_string($warning) || strlen($warning) > 160) {
                return Outcome::refuse('CR02_PROJECTION_WARNING_INVALID');
            }
        }
        $source = $doc['source_binding'] ?? null;
        if (!is_array($source) || !self::exactKeys($source, self::BINDING_KEYS)
            || $source['retention_class'] !== 'M05_PRIVATE_FIXTURE') {
            return Outcome::refuse('CR02_NATIVE_OWNER_BINDING_NOT_IMPLEMENTED');
        }
        foreach (['tenant_id', 'workspace_id', 'monitored_source_id',
            'source_ref_id', 'source_card_id', 'capture_ref_id', 'evidence_ref_id'] as $key) {
            if (!is_string($source[$key]) || !Ids::isValid($source[$key])) {
                return Outcome::refuse('CR02_SOURCE_IDENTITY_INVALID');
            }
        }
        if ($source['tenant_id'] === $source['workspace_id']
            || $source['source_ref_id'] === $source['monitored_source_id']
            || $source['capture_ref_id'] === $source['evidence_ref_id']) {
            return Outcome::refuse('CR02_SOURCE_IDENTITY_AMBIGUOUS');
        }
        foreach (['raw_sha256', 'source_card_sha256'] as $key) {
            if (!is_string($source[$key]) || preg_match(self::HEX, $source[$key]) !== 1) {
                return Outcome::refuse('CR02_SOURCE_DIGEST_INVALID');
            }
        }
        foreach (['observed_at', 'known_at'] as $key) {
            if (!is_string($source[$key]) || !Clock::isCanonical($source[$key])) {
                return Outcome::refuse('CR02_SOURCE_CLOCK_INVALID');
            }
        }
        if ($source['observed_at'] > $source['known_at']) {
            return Outcome::refuse('CR02_SOURCE_CLOCK_ORDER');
        }
        $full = $doc;
        unset($full['projection_digest']);
        if (!is_string($doc['projection_digest'])
            || preg_match(self::HEX, $doc['projection_digest']) !== 1
            || !hash_equals(Digest::canonicalDigest($full), $doc['projection_digest'])) {
            return Outcome::refuse('CR02_PROJECTION_DIGEST_MISMATCH');
        }
        // Existing CR-01 validator independently checks the required source,
        // section and selector carrier; additionally enforce full closed shape.
        if (!DocumentProjectionContract::validateProjection($doc, $source)->isOk()) {
            return Outcome::refuse('CR02_CR01_PROJECTION_INVALID');
        }

        $result = [];
        $selectors = [];
        $last = -1;
        foreach ($doc['sections'] as $section) {
            if (!is_array($section) || !self::exactKeys($section, self::SECTION_KEYS)
                || !is_string($section['selector']) || strlen($section['selector']) > 320
                || $section['selector'] === ''
                || isset($selectors[$section['selector']])
                || !is_int($section['ordinal']) || $section['ordinal'] <= $last
                || $section['type'] === null
                || !in_array($section['type'], DocumentProjectionContract::TYPES, true)
                || $section['parser_version'] !== DocumentProjectionContract::PARSER_VERSION
                || $section['source_sha256'] !== $source['raw_sha256']
                || $section['source_card_id'] !== $source['source_card_id']
                || $section['locator_kind'] !== 'DERIVED_DOM_PATH'
                || $section['source_offset_verified'] !== false
                || $section['raw_start_byte'] !== null || $section['raw_length_bytes'] !== null
                || $section['interpretation_only'] !== true
                || !is_string($section['origin_id'])
                || !hash_equals(
                    hash('sha256', $source['capture_ref_id'] . "\0" . $source['source_ref_id'] . "\0" . $section['selector']),
                    $section['origin_id']
                )
                || !is_string($section['text_sha256'])
                || preg_match(self::HEX, $section['text_sha256']) !== 1
                || !is_int($section['text_bytes']) || $section['text_bytes'] < 1
                || $section['text_bytes'] > self::MAX_CONTENT_BYTES
                || !is_string($section['excerpt']) || $section['excerpt'] === ''
                || strlen($section['excerpt']) > DocumentProjectionContract::LIMITS['max_excerpt_bytes']
                || !mb_check_encoding($section['excerpt'], 'UTF-8')
                || !is_bool($section['excerpt_truncated'])
                || !is_bool($section['text_truncated'])
                || !is_array($section['ordered_item_digests'])
                || !array_is_list($section['ordered_item_digests'])
                || count($section['ordered_item_digests']) > DocumentProjectionContract::LIMITS['max_nav_items']
                || !is_array($section['warnings']) || !array_is_list($section['warnings'])) {
                return Outcome::refuse('CR02_SECTION_PROVENANCE_INVALID');
            }
            $last = $section['ordinal'];
            $selectors[$section['selector']] = true;
            $excerptSize = strlen($section['excerpt']);
            if ($excerptSize > $section['text_bytes']
                || $section['excerpt_truncated'] !== ($excerptSize < $section['text_bytes'])
                || (!$section['excerpt_truncated'] && !$section['text_truncated']
                    && ($excerptSize !== $section['text_bytes']
                        || !hash_equals(hash('sha256', $section['excerpt']), $section['text_sha256'])))) {
                return Outcome::refuse('CR02_SECTION_TEXT_DIGEST_INCONSISTENT');
            }
            foreach ($section['ordered_item_digests'] as $item) {
                if (!is_string($item) || preg_match(self::HEX, $item) !== 1) {
                    return Outcome::refuse('CR02_NAV_ITEM_DIGEST_INVALID');
                }
            }
            if ($section['type'] !== 'NAVIGATION' && $section['ordered_item_digests'] !== []) {
                return Outcome::refuse('CR02_NON_NAV_ORDER_FINGERPRINT');
            }
            foreach ($section['warnings'] as $warning) {
                if (!is_string($warning) || strlen($warning) > 160) {
                    return Outcome::refuse('CR02_SECTION_WARNING_INVALID');
                }
            }
            if ($section['text_truncated'] && !in_array('SECTION_TEXT_LIMIT_TRUNCATED', $section['warnings'], true)) {
                return Outcome::refuse('CR02_TRUNCATION_WARNING_MISSING');
            }

            $navOrdered = $section['ordered_item_digests'];
            $navSorted = $navOrdered;
            sort($navSorted, SORT_STRING);
            $tokens = self::sensitiveTokenHashes($section['excerpt']);
            $result[] = [
                'selector' => $section['selector'],
                'type' => $section['type'],
                'ordinal' => $section['ordinal'],
                'origin_id' => $section['origin_id'],
                'text_sha256' => $section['text_sha256'],
                'text_bytes' => $section['text_bytes'],
                'body_fingerprint' => Digest::canonicalDigest([
                    'version' => self::VERSION,
                    'normalizer_version' => $doc['normalizer_version'],
                    'text_sha256' => $section['text_sha256'],
                    'text_truncated' => $section['text_truncated'],
                ]),
                'order_fingerprint' => $section['type'] === 'NAVIGATION'
                    ? Digest::canonicalDigest(['version' => self::VERSION, 'items' => $navOrdered])
                    : null,
                'nav_set_fingerprint' => $section['type'] === 'NAVIGATION'
                    ? Digest::canonicalDigest(['version' => self::VERSION, 'items' => $navSorted])
                    : null,
                'nav_item_count' => count($navOrdered),
                'sensitive_token_digests' => $tokens,
                'truncated' => $section['text_truncated'] || $section['excerpt_truncated'],
                'normalized_excerpt_completeness' => $section['text_truncated'] ? 'BOUNDED_TEXT_ONLY'
                    : ($section['excerpt_truncated'] ? 'EXCERPT_PARTIAL' : 'EXCERPT_COMPLETE'),
                'original_byte_start_verified' => false,
                'original_byte_length_verified' => false,
                'source_sha256' => $source['raw_sha256'],
            ];
        }

        return Outcome::ok([
            'version' => self::VERSION,
            'source' => $source,
            'projection_digest' => $doc['projection_digest'],
            'sections' => $result,
            'warnings' => $doc['warnings'],
            'body_fingerprint' => $source['raw_sha256'], // original HTML bytes; NOT normalized text
        ]);
    }

    /**
     * Extract only bounded, hashed sensitive tokens from CR-01 excerpts.
     * No token text or original source bytes appear in the output. Digest is
     * evidence of an excerpt change, never proof of source-wide meaning.
     *
     * @return array<string,?string>
     */
    private static function sensitiveTokenHashes(string $text): array
    {
        $expressions = [
            'PRICE' => '/(?:(?<![A-Za-z0-9])\d{1,9}(?:[.,]\d{1,2})?\s*(?:SEK|USD|EUR|kr)\b|\b(?:SEK|USD|EUR)\s*\d{1,9}(?:[.,]\d{1,2})?)/iu',
            'NEGATION' => '/\b(?:not|no|never|without|cannot|can.t|inte|ej|utan|icke)\b/iu',
            'OFFER' => '/\b(?:free\s+trial|trial|offer|offers|unavailable|available|launch|launched|erbjudande|gratis|abonnemang)\b/iu',
            'CONTRACT' => '/\b(?:contract|agreement|binding|termination|notice\s+period|renewal|terms|avtal|upps.gning|bindningstid)\b/iu',
            'DATE' => '/\b(?:20[0-9]{2}(?:-[01][0-9]-[0-3][0-9])?|January|February|March|April|May|June|July|August|September|October|November|December)\b/iu',
        ];
        $out = [];
        foreach ($expressions as $kind => $regex) {
            $matches = [];
            $ok = preg_match_all($regex, $text, $matches);
            if (!is_int($ok) || $ok === 0) {
                $out[$kind] = null;
                continue;
            }
            $tokens = array_slice($matches[0], 0, self::MAX_TOKEN_MATCHES);
            $tokens = array_map(static fn (string $v): string => mb_strtolower(trim($v), 'UTF-8'), $tokens);
            $out[$kind] = Digest::canonicalDigest(['version' => self::VERSION, 'kind' => $kind, 'tokens' => $tokens]);
        }
        return $out;
    }

    /** @param list<string> $want */
    private static function exactKeys(array $given, array $want): bool
    {
        $keys = array_keys($given);
        sort($keys, SORT_STRING);
        sort($want, SORT_STRING);
        return $keys === $want;
    }
}
