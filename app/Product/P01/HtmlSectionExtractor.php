<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Outcome;

/**
 * CR-01 bounded, deterministic read of a native HTML5 DOM.
 *
 * This class never opens a URL, stores a body, requests a resource, runs a
 * script, invokes a model or writes M05/M10. DOM selectors are DERIVED and
 * deliberately carry null original-byte offsets; parser recovery cannot
 * manufacture a raw-source locator.
 */
final class HtmlSectionExtractor
{
    private const HTML_NS = 'http://www.w3.org/1999/xhtml';
    private const SKIP_SUBTREES = ['script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'canvas', 'svg', 'math'];

    /** @return Outcome<array{sections:list<array<string,mixed>>,warnings:list<string>,nodes:int}> */
    public static function extract(object $dom, array $source, int $startNs): Outcome
    {
        if (!class_exists('Dom\\HTMLDocument') || !$dom instanceof \Dom\HTMLDocument) {
            return Outcome::refuse('CR01_NATIVE_HTML5_DOM_REQUIRED');
        }
        $max = DocumentProjectionContract::LIMITS;
        $root = $dom->documentElement;
        if ($root === null) {
            return Outcome::refuse('CR01_DOM_EMPTY');
        }
        $stack = [[$root, 'html[1]', 0, false]];
        $sections = [];
        $warnings = [];
        $ids = [];
        $nodes = 0;
        $attributes = 0;
        $fallback = null;
        while ($stack !== []) {
            [$node, $path, $depth, $ancestorSelected] = array_pop($stack);
            $nodes++;
            if ($nodes > $max['max_nodes']) {
                return Outcome::refuse('CR01_DOM_NODE_LIMIT');
            }
            if ($depth > $max['max_depth'] || strlen($path) > 320) {
                return Outcome::refuse('CR01_DOM_DEPTH_OR_SELECTOR_LIMIT');
            }
            if (($nodes % 64) === 0 && self::timedOut($startNs, $max['max_total_wall_ms'])) {
                return Outcome::refuse('CR01_PARSER_TIME_BUDGET');
            }
            $tag = strtolower((string) ($node->localName ?? $node->nodeName ?? ''));
            $ns = $node->namespaceURI ?? null;
            if ($ns !== null && $ns !== '' && $ns !== self::HTML_NS) {
                $warnings['FOREIGN_NAMESPACE_SUBTREE_SKIPPED'] = true;
                continue;
            }
            if (in_array($tag, self::SKIP_SUBTREES, true)) {
                $warnings['ACTIVE_OR_FOREIGN_SUBTREE_OMITTED'] = true;
                continue;
            }
            if ($node->nodeType !== 1) {
                continue;
            }
            foreach (($node->attributes ?? []) as $attr) {
                $attributes++;
                if ($attributes > $max['max_attributes']) {
                    return Outcome::refuse('CR01_DOM_ATTRIBUTE_LIMIT');
                }
                if (strlen((string) $attr->value) > 1024) {
                    return Outcome::refuse('CR01_ATTRIBUTE_VALUE_LIMIT');
                }
            }
            $htmlId = (string) $node->getAttribute('id');
            if ($htmlId !== '') {
                if (strlen($htmlId) > 191 || isset($ids[$htmlId])) {
                    return Outcome::refuse('CR01_DOM_ORIGIN_ID_AMBIGUOUS');
                }
                $ids[$htmlId] = true;
            }

            $kind = !$ancestorSelected ? self::classify($node, $tag, $path) : null;
            $selected = false;
            if ($kind !== null) {
                $out = self::section($node, $kind, $source, $path, $nodes, $startNs);
                if (!$out->isOk()) {
                    return $out;
                }
                $section = $out->value();
                if ($section !== null) {
                    $sections[] = $section;
                    $selected = true;
                    if (count($sections) > $max['max_sections']) {
                        return Outcome::refuse('CR01_SECTION_LIMIT');
                    }
                }
            }
            if ($tag === 'body' && $fallback === null) {
                $fallback = [$node, $path, $nodes];
            }
            // Always traverse non-active descendants for global node, ID and
            // depth checks, even if their enclosing section was selected.
            $children = [];
            $siblings = [];
            foreach (($node->childNodes ?? []) as $child) {
                if (($child->nodeType ?? 0) !== 1) {
                    continue;
                }
                $childName = strtolower((string) ($child->localName ?? $child->nodeName ?? ''));
                $siblings[$childName] = ($siblings[$childName] ?? 0) + 1;
                $children[] = [$child, $path . '/' . $childName . '[' . $siblings[$childName] . ']', $depth + 1, $ancestorSelected || $selected];
            }
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $stack[] = $children[$i];
            }
        }

        if ($sections === [] && $fallback !== null) {
            [$body, $path, $order] = $fallback;
            $out = self::section($body, 'UNCLASSIFIED', $source, $path, $order, $startNs);
            if (!$out->isOk()) {
                return $out;
            }
            $section = $out->value();
            if ($section !== null) {
                $sections[] = $section;
            }
        }
        if (self::timedOut($startNs, $max['max_total_wall_ms'])) {
            return Outcome::refuse('CR01_PARSER_TIME_BUDGET');
        }
        usort($sections, static fn (array $a, array $b): int => $a['ordinal'] <=> $b['ordinal']);
        // All locators are reconstructed from the HTML5 tree; never evidence
        // of the original raw byte positions, especially after tag repairs.
        $warnings['DERIVED_DOM_LOCATORS_NOT_RAW_BYTE_POSITIONS'] = true;
        $warnings['HTML5_REPAIR_DETAILS_UNVERIFIED'] = true;
        return Outcome::ok([
            'sections' => array_values($sections),
            'warnings' => array_keys($warnings),
            'nodes' => $nodes,
        ]);
    }

    private static function classify(object $node, string $tag, string $path): ?string
    {
        if ($tag === 'nav') {
            return 'NAVIGATION';
        }
        if ($tag === 'footer') {
            return 'FOOTER_LEGAL';
        }
        if ($tag === 'title') {
            return 'STRUCTURED_META';
        }
        if ($tag === 'meta') {
            $name = strtolower(trim((string) $node->getAttribute('name')));
            $property = strtolower(trim((string) $node->getAttribute('property')));
            if (in_array($name, ['description', 'application-name', 'author', 'twitter:title', 'twitter:description'], true)
                || in_array($property, ['og:title', 'og:description', 'og:type'], true)) {
                return 'STRUCTURED_META';
            }
            return null;
        }
        $tokens = strtolower(trim(
            (string) $node->getAttribute('id') . ' ' .
            (string) $node->getAttribute('class') . ' ' .
            (string) $node->getAttribute('aria-label')
        ));
        if ($tag === 'header' && preg_match('/(?:^|[\s_-])(?:nav|navigation|menu)(?:$|[\s_-])/u', $tokens) === 1) {
            return 'NAVIGATION';
        }
        if (!in_array($tag, ['article', 'section', 'div', 'p'], true)) {
            return null;
        }
        if (preg_match('/(?:^|[\s_-])(?:pricing|prices|price|plan|plans|priser|pris|offers|erbjudanden)(?:$|[\s_-])/u', $tokens) === 1) {
            return 'PRICING';
        }
        if (preg_match('/(?:^|[\s_-])(?:product|products|service|services|produkter|tjanster|tjänster)(?:$|[\s_-])/u', $tokens) === 1) {
            return 'PRODUCT_SERVICE';
        }
        if (preg_match('/(?:^|[\s_-])(?:careers|career|jobs|job|vacancies|karriar|karriär|jobb)(?:$|[\s_-])/u', $tokens) === 1) {
            return 'CAREERS';
        }
        if (preg_match('/(?:^|[\s_-])(?:news|press|articles|media|nyheter|pressmeddelanden)(?:$|[\s_-])/u', $tokens) === 1) {
            return 'NEWS_PRESS';
        }
        if ($tag === 'article') {
            return 'NEWS_PRESS';
        }
        if ($tag === 'section') {
            return 'UNCLASSIFIED';
        }
        // Residual paragraphs under main are useful, but generic DIVs are
        // omitted to prevent enormous overlapping text projections.
        if ($tag === 'p' && str_contains($path, '/main[')) {
            return 'UNCLASSIFIED';
        }
        return null;
    }

    /** @return Outcome<?array<string,mixed>> */
    private static function section(object $node, string $kind, array $source, string $path, int $ordinal, int $startNs): Outcome
    {
        if (strlen($path) > 320) {
            return Outcome::refuse('CR01_DOM_SELECTOR_LIMIT');
        }
        $tag = strtolower((string) $node->localName);
        if ($tag === 'meta') {
            $content = trim((string) $node->getAttribute('content'));
            $normalized = self::normalize($content);
            if ($normalized === null) {
                return Outcome::refuse('CR01_SECTION_TEXT_ENCODING_INVALID');
            }
            $truncated = strlen($normalized) > DocumentProjectionContract::LIMITS['max_section_text_bytes'];
            if ($truncated) {
                $normalized = mb_strcut($normalized, 0, DocumentProjectionContract::LIMITS['max_section_text_bytes'], 'UTF-8');
            }
        } else {
            $text = self::boundedText($node, $startNs);
            if (!$text->isOk()) {
                return $text;
            }
            [$normalized, $truncated] = $text->value();
        }
        if ($normalized === '') {
            return Outcome::ok(null);
        }
        $excerpt = mb_strcut($normalized, 0, DocumentProjectionContract::LIMITS['max_excerpt_bytes'], 'UTF-8');
        $ordered = [];
        if ($kind === 'NAVIGATION') {
            $nav = self::navigationHashes($node, $startNs);
            if (!$nav->isOk()) {
                return $nav;
            }
            $ordered = $nav->value();
        }
        $sourceHash = $source['raw_sha256'];
        $originId = hash('sha256', $source['capture_ref_id'] . "\0" . $source['source_ref_id'] . "\0" . $path);
        return Outcome::ok([
            'ordinal' => $ordinal,
            'type' => $kind,
            'origin_id' => $originId,
            'selector' => $path,
            'locator_kind' => 'DERIVED_DOM_PATH',
            'raw_start_byte' => null,
            'raw_length_bytes' => null,
            'source_offset_verified' => false,
            'source_sha256' => $sourceHash,
            'source_card_id' => $source['source_card_id'],
            'parser_version' => DocumentProjectionContract::PARSER_VERSION,
            'text_sha256' => hash('sha256', $normalized),
            'text_bytes' => strlen($normalized),
            'excerpt' => $excerpt,
            'excerpt_truncated' => strlen($excerpt) !== strlen($normalized),
            'text_truncated' => $truncated,
            'ordered_item_digests' => $ordered,
            'interpretation_only' => true,
            'warnings' => $truncated ? ['SECTION_TEXT_LIMIT_TRUNCATED'] : [],
        ]);
    }

    /** @return Outcome<array{string,bool}> */
    private static function boundedText(object $root, int $startNs): Outcome
    {
        $max = DocumentProjectionContract::LIMITS;
        $parts = [];
        $bytes = 0;
        $nodes = 0;
        $textNodes = 0;
        $truncated = false;
        $stack = [$root];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (++$nodes > $max['max_nodes']) {
                return Outcome::refuse('CR01_SECTION_SUBTREE_LIMIT');
            }
            if (($nodes % 64) === 0 && self::timedOut($startNs, $max['max_total_wall_ms'])) {
                return Outcome::refuse('CR01_PARSER_TIME_BUDGET');
            }
            if ($node->nodeType === 3) {
                if (++$textNodes > $max['max_text_nodes_per_section']) {
                    return Outcome::refuse('CR01_SECTION_TEXT_NODE_LIMIT');
                }
                $chunk = (string) ($node->nodeValue ?? '');
                $available = $max['max_section_text_bytes'] - $bytes;
                if ($available <= 0) {
                    $truncated = true;
                    continue;
                }
                $piece = mb_strcut($chunk, 0, $available, 'UTF-8');
                $parts[] = $piece;
                $bytes += strlen($piece);
                if (strlen($piece) !== strlen($chunk)) {
                    $truncated = true;
                }
                continue;
            }
            if ($node->nodeType !== 1) {
                continue;
            }
            $tag = strtolower((string) ($node->localName ?? ''));
            $ns = $node->namespaceURI ?? null;
            if (($ns !== null && $ns !== '' && $ns !== self::HTML_NS)
                || in_array($tag, self::SKIP_SUBTREES, true)) {
                continue;
            }
            $children = [];
            foreach (($node->childNodes ?? []) as $child) {
                $children[] = $child;
            }
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $stack[] = $children[$i];
            }
        }
        $normalized = self::normalize(implode(' ', $parts));
        if ($normalized === null) {
            return Outcome::refuse('CR01_SECTION_TEXT_ENCODING_INVALID');
        }
        return Outcome::ok([$normalized, $truncated]);
    }

    /** @return Outcome<list<string>> */
    private static function navigationHashes(object $node, int $startNs): Outcome
    {
        $hashes = [];
        $stack = [$node];
        $visited = 0;
        while ($stack !== []) {
            $item = array_pop($stack);
            if (++$visited > DocumentProjectionContract::LIMITS['max_nodes']) {
                return Outcome::refuse('CR01_NAV_SUBTREE_LIMIT');
            }
            if (($visited % 64) === 0 && self::timedOut($startNs, DocumentProjectionContract::LIMITS['max_total_wall_ms'])) {
                return Outcome::refuse('CR01_PARSER_TIME_BUDGET');
            }
            if ($item->nodeType !== 1) {
                continue;
            }
            $tag = strtolower((string) ($item->localName ?? ''));
            if (in_array($tag, self::SKIP_SUBTREES, true)) {
                continue;
            }
            if ($tag === 'a') {
                $label = self::boundedText($item, $startNs);
                if (!$label->isOk()) {
                    return $label;
                }
                $text = $label->value()[0];
                if ($text !== '') {
                    $hashes[] = hash('sha256', $text);
                    if (count($hashes) > DocumentProjectionContract::LIMITS['max_nav_items']) {
                        return Outcome::refuse('CR01_NAV_ITEM_LIMIT');
                    }
                }
                continue;
            }
            $children = [];
            foreach (($item->childNodes ?? []) as $child) {
                $children[] = $child;
            }
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $stack[] = $children[$i];
            }
        }
        return Outcome::ok($hashes);
    }

    private static function normalize(string $raw): ?string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }
        $normalized = preg_replace('/[\s\p{Z}]+/u', ' ', $raw);
        return $normalized === null ? null : trim($normalized);
    }

    private static function timedOut(int $startNs, int $milliseconds): bool
    {
        return hrtime(true) - $startNs > $milliseconds * 1000000;
    }
}
