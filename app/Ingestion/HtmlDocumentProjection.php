<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Product\P01\DocumentProjectionContract;
use Allfather\Loopia\Product\P01\HtmlSectionExtractor;

/**
 * CR-01: bounded HTML5 interpretation from already-provided private bytes.
 *
 * This is NOT an alternate crawler, a source-use entitlement, an M05 writer,
 * a commercial materiality assessor, or a customer output. It has no network
 * transport and never accepts a URL. Production P01/M05 source-right
 * revalidation/wiring is deliberately deferred to CR-04.
 *
 * On PHP <8.4 or hosts without ext-dom's native HTML5 parser this explicitly
 * refuses. DOMDocument::loadHTML uses HTML4 semantics and is NOT a silent
 * fallback. The 8.2 package remains compatible; its parsing capability is HOLD.
 */
final class HtmlDocumentProjection
{
    /** @return array{status:string,backend:string,php_version_id:int,dom_extension:bool,mbstring_extension:bool,html5_class:bool} */
    public static function capability(): array
    {
        $hasNative = PHP_VERSION_ID >= 80400
            && extension_loaded('dom') && class_exists('Dom\\HTMLDocument')
            && method_exists('Dom\\HTMLDocument', 'createFromString');
        $hasMb = extension_loaded('mbstring');
        return [
            'status' => $hasNative && $hasMb ? 'SUPPORTED_NATIVE_HTML5' : 'UNSUPPORTED_HTML5_CAPABILITY',
            'backend' => $hasNative ? 'Dom\\HTMLDocument' : 'NONE',
            'php_version_id' => PHP_VERSION_ID,
            'dom_extension' => extension_loaded('dom'),
            'mbstring_extension' => $hasMb,
            'html5_class' => class_exists('Dom\\HTMLDocument'),
        ];
    }

    /**
     * @param array<string,mixed> $source Checked, synthetic, private evidence
     *                                    metadata — no live authority assertions.
     * @return Outcome<array<string,mixed>>
     */
    public static function project(array $source, string $rawBytes): Outcome
    {
        $input = DocumentProjectionContract::validateSource($source, $rawBytes);
        if (!$input->isOk()) {
            return $input;
        }
        $limits = DocumentProjectionContract::LIMITS;
        $rawLength = strlen($rawBytes);
        if ($rawLength === 0 || $rawLength > $limits['max_input_bytes']) {
            return Outcome::refuse('CR01_INPUT_BYTE_LIMIT');
        }
        if (str_contains($rawBytes, "\0")) {
            return Outcome::refuse('CR01_INPUT_NUL_FORBIDDEN');
        }
        if (!extension_loaded('mbstring')) {
            return Outcome::refuse('CR01_MBSTRING_CAPABILITY_UNSUPPORTED');
        }
        if (!mb_check_encoding($rawBytes, 'UTF-8')) {
            return Outcome::refuse('CR01_INPUT_UTF8_INVALID');
        }
        if (substr_count($rawBytes, '<') > $limits['max_markup_openers']) {
            return Outcome::refuse('CR01_MARKUP_COMPLEXITY_LIMIT');
        }
        if (preg_match('~<!DOCTYPE[^>]{0,512}\b(?:SYSTEM|PUBLIC)\b~i', $rawBytes) === 1) {
            return Outcome::refuse('CR01_EXTERNAL_DTD_UNSUPPORTED');
        }
        if (preg_match('~^\s*<\?xml\b~i', $rawBytes) === 1) {
            return Outcome::refuse('CR01_XML_NOT_HTML5');
        }
        // A declared different/meta charset cannot silently be interpreted as
        // UTF-8, and conflicting declarations cannot be chosen by convenience.
        $declared = [];
        if (preg_match_all('~<meta\b[^>]{0,1024}\bcharset\s*=\s*["\x27]?([a-z0-9_-]+)~i', $rawBytes, $match) > 0) {
            foreach ($match[1] as $charset) {
                $declared[strtolower($charset)] = true;
            }
        }
        if (count($declared) > 1) {
            return Outcome::refuse('CR01_META_CHARSET_CONFLICT');
        }
        if ($declared !== [] && !isset($declared['utf-8']) && !isset($declared['utf8'])) {
            return Outcome::refuse('CR01_META_CHARSET_UNSUPPORTED');
        }

        $capability = self::capability();
        if ($capability['status'] !== 'SUPPORTED_NATIVE_HTML5') {
            return Outcome::refuse('CR01_HTML5_PARSER_CAPABILITY_UNSUPPORTED', [
                'requires' => 'PHP>=8.4; ext-dom Dom\\HTMLDocument; mbstring',
                'backend' => $capability['backend'],
                'version_id' => $capability['php_version_id'],
            ], 'CAPABILITY');
        }

        $startedNs = hrtime(true);
        try {
            // The HTML5 living-standard parser processes a string in memory.
            // NONET is defence-in-depth, not a claim that a network-backed
            // parser would be safe. No resource loading/exec paths exist here.
            $doc = \Dom\HTMLDocument::createFromString($rawBytes, LIBXML_NONET, 'UTF-8');
        } catch (\Throwable) {
            return Outcome::refuse('CR01_HTML5_PARSER_REFUSED');
        }
        if (hrtime(true) - $startedNs > $limits['max_total_wall_ms'] * 1000000) {
            return Outcome::refuse('CR01_PARSER_TIME_BUDGET');
        }
        $sections = HtmlSectionExtractor::extract($doc, $source, $startedNs);
        if (!$sections->isOk()) {
            return $sections;
        }
        $payload = $sections->value();
        $projection = [
            'schema_version' => DocumentProjectionContract::VERSION,
            'parser_version' => DocumentProjectionContract::PARSER_VERSION,
            'normalizer_version' => DocumentProjectionContract::NORMALIZER_VERSION,
            'limits_profile' => DocumentProjectionContract::LIMITS_VERSION,
            'parser_backend' => 'Dom\\HTMLDocument',
            'source_binding' => [
                'tenant_id' => $source['tenant_id'],
                'workspace_id' => $source['workspace_id'],
                'monitored_source_id' => $source['monitored_source_id'],
                'source_ref_id' => $source['source_ref_id'],
                'source_card_id' => $source['source_card_id'],
                'source_card_sha256' => $source['source_card_sha256'],
                'capture_ref_id' => $source['capture_ref_id'],
                'evidence_ref_id' => $source['evidence_ref_id'],
                'raw_sha256' => $source['raw_sha256'],
                'observed_at' => $source['observed_at'],
                'known_at' => $source['known_at'],
                'retention_class' => $source['retention_class'],
            ],
            'sections' => $payload['sections'],
            'warnings' => $payload['warnings'],
            'node_count' => $payload['nodes'],
            'section_count' => count($payload['sections']),
            'raw_bytes_stored_here' => false,
            'derived_not_primary_evidence' => true,
            'business_materiality' => 'NOT_ASSESSED',
            'customer_visible' => false,
            'production_authorized' => false,
        ];
        $verified = DocumentProjectionContract::validateProjection($projection, $source);
        if (!$verified->isOk()) {
            return $verified;
        }
        $projection['projection_digest'] = Digest::canonicalDigest($projection);
        return Outcome::ok($projection);
    }
}
