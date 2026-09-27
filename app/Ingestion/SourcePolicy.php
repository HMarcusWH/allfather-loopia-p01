<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

use Allfather\Loopia\Core\Digest;

/**
 * The source policy compiler — ONE pure function that turns Sources-plane authority into the
 * operational constraints every capture must obey.
 *
 * Its inputs are a source card, its latest terms review, the M05 source reference, and the
 * connector descriptor. Its output is a snapshot: what may be fetched, from where, how often, how
 * large, and — when the answer is "nothing" — exactly why.
 *
 * TWO PROPERTIES MAKE IT USEFUL, and both are structural rather than aspirational:
 *
 *   IT IS PURE. No database access, no clock read — the evaluation instant is a parameter. So the
 *   same inputs always produce a byte-identical output, which is what lets a replay months later
 *   recompute a stored policy's digest and treat a difference as a real integrity finding rather
 *   than as formula drift.
 *
 *   IT CAN ONLY NARROW. Policy never grants what the card and reference do not already permit. A
 *   compile that cannot establish an authority BLOCKS, and records why.
 *
 * It is the ONE law. P01's capture path and the M15 ingestion spine both compile through this
 * function rather than each carrying its own copy of the gates — because two copies of a
 * fail-closed rule are two rules, and they drift.
 */
final class SourcePolicy
{
    /** Bumped on ANY semantic change: a replay compares digests across versions. */
    public const COMPILER_VERSION = 'ing_policy_compiler_v3';

    /**
     * The CLOSED vocabulary of fail-closed reason codes this compiler can emit.
     *
     * Upstream expresses this as a union TYPE, and its own compiler then checks exhaustively that
     * every consumer handles every member — so an added reason code cannot reach production
     * unhandled. PHP has no equivalent check, so the vocabulary is declared here and pinned by
     * conformance instead: the P01 adapter must map or explicitly scope out every member, and a
     * code added to this compiler without a decision about P01 fails the suite.
     *
     * @var list<string>
     */
    public const FAIL_CLOSED_REASON_CODES = [
        'SOURCE_SCOPE_MISMATCH',
        'SOURCE_CARD_NOT_APPROVED',
        'SOURCE_CARD_REVIEW_EXPIRED',
        'SOURCE_USE_PROHIBITED',
        'SOURCE_LICENSE_PROHIBITED',
        'SOURCE_CONTRACT_REQUIRED_BUT_MISSING',
        'SOURCE_RETENTION_PROHIBITED',
        'SOURCE_REFERENCE_NOT_ACTIVE',
        'SOURCE_SENSITIVITY_PROHIBITED',
        'ROBOTS_NOT_CHECKED',
        'TERMS_RECHECK_OVERDUE',
        'SOURCE_TYPE_UNSUPPORTED',
        'CONNECTOR_CLASS_NOT_PROMOTED',
        'CONNECTOR_NOT_ACTIVATED',
        'SOURCE_URL_MISSING_OR_INVALID',
    ];

    /**
     * The closed card-type to connector-class mapping.
     *
     * `search_api` has NO mapping, so it blocks as SOURCE_TYPE_UNSUPPORTED. Widening this table is
     * a deliberate decision, never an edit made in passing.
     *
     * @var array<string, ?string>
     */
    private const SOURCE_TYPE_TO_CONNECTOR_CLASS = [
        'website' => 'PUBLIC_WEB_PAGE',
        'press_release' => 'PUBLIC_WEB_PAGE',
        'rss' => 'RSS_OR_ATOM_FEED',
        'public_api' => 'JSON_API',
        'licensed_api' => 'JSON_API',
        'customer_upload' => 'TENANT_UPLOADED_ARTIFACT',
        'manual' => 'TENANT_UPLOADED_ARTIFACT',
        'search_api' => null,
    ];

    /**
     * The connector classes this projection has actually promoted.
     *
     * Every other class blocks at COMPILE time with CONNECTOR_CLASS_NOT_PROMOTED, regardless of
     * what any descriptor row says. That is defence in depth on top of the registry: flipping a
     * descriptor to ACTIVE is not enough to make an unpromoted class dispatchable.
     *
     * @var list<string>
     */
    public const PROMOTED_CONNECTOR_CLASSES = [
        'SYNTHETIC_TEST_FIXTURE',
        'PUBLIC_WEB_PAGE',
        'TENANT_UPLOADED_ARTIFACT',
    ];

    /**
     * Classes that open a socket. These REQUIRE a valid https card URL, because that URL is the
     * allowlist anchor. Upload and fixture classes never dial out, so they need none.
     *
     * @var list<string>
     */
    private const NETWORK_CONNECTOR_CLASSES = [
        'PUBLIC_WEB_PAGE', 'SITEMAP_OR_URL_LIST', 'RSS_OR_ATOM_FEED', 'JSON_API', 'CSV_OR_STATIC_FILE',
    ];

    /**
     * Cadence windows per caching policy, in milliseconds.
     *
     * `explicit_contract` carries NO built-in window: its cadence comes from the rate-limit policy.
     * Inventing a duration for a contract nobody here can read would be asserting a term the
     * contract may not contain.
     *
     * @var array<string, int>
     */
    private const CACHE_POLICY_WINDOW_MS = [
        'no_cache' => 0,
        'short_cache' => 3_600_000,
        'standard_cache' => 86_400_000,
        'explicit_contract' => 0,
    ];

    private const DEFAULT_MAX_RESPONSE_BYTES = 5_000_000;
    private const DEFAULT_TIMEOUT_MS = 10_000;

    /**
     * Compile the policy for one source.
     *
     * @param array{source_ref_id: string, tenant_id: string, status: string, content_sensitivity_class: string} $reference
     * @param array<string, mixed>|null $card
     * @param array<string, mixed>|null $latestTermsReview
     * @param array<string, mixed>|null $connector
     * @param int $evaluationTimeMs caller-supplied; never read from the clock inside
     * @return array<string, mixed>
     */
    public static function compile(
        array $reference,
        ?array $card,
        ?array $latestTermsReview,
        ?array $connector,
        int $evaluationTimeMs,
    ): array {
        $reasons = [];
        $refuse = static function (string $code) use (&$reasons): void {
            if (!in_array($code, $reasons, true)) {
                $reasons[] = $code;
            }
        };

        // --- card/reference coherence: a FOREIGN card grants nothing --------------------------
        if ($card !== null
            && ((string) $card['tenant_id'] !== $reference['tenant_id']
                || (string) $card['source_ref_id'] !== $reference['source_ref_id'])
        ) {
            $refuse('SOURCE_SCOPE_MISMATCH');
        }

        // --- card authority --------------------------------------------------------------------
        if ($card === null || (string) $card['review_status'] !== 'approved') {
            $refuse('SOURCE_CARD_NOT_APPROVED');
        }
        $reviewExpiresAt = $card['review_expires_at'] ?? null;
        if ($reviewExpiresAt !== null) {
            $expiresMs = self::parseMs((string) $reviewExpiresAt);
            // An UNREADABLE expiry is treated as expired. A card that declares a review window but
            // records it in a form nothing can read has not established that the window is open.
            if ($expiresMs === null || $evaluationTimeMs > $expiresMs) {
                $refuse('SOURCE_CARD_REVIEW_EXPIRED');
            }
        }
        if (($card['allowed_use_class'] ?? null) === 'prohibited') {
            $refuse('SOURCE_USE_PROHIBITED');
        }
        if (($card['license_class'] ?? null) === 'PROHIBITED') {
            $refuse('SOURCE_LICENSE_PROHIBITED');
        }
        $tenantOwnedUpload = ($card['source_type'] ?? null) === 'customer_upload'
            && ($card['data_ownership'] ?? null) === 'TENANT_OWNED'
            && ($card['allowed_use_class'] ?? null) === 'customer_owned_tenant_scoped';
        if ($card !== null && !$tenantOwnedUpload
            && (($card['source_type'] ?? null) === 'licensed_api' || str_starts_with((string) ($card['license_class'] ?? ''), 'LICENSED_'))
            && (!is_string($card['contract_reference_id'] ?? null) || trim($card['contract_reference_id']) === '')) {
            $refuse('SOURCE_CONTRACT_REQUIRED_BUT_MISSING');
        }
        if (($card['retention_class'] ?? null) === 'prohibited') {
            $refuse('SOURCE_RETENTION_PROHIBITED');
        }
        if ($reference['status'] !== 'ACTIVE_FOR_CAPTURE') {
            $refuse('SOURCE_REFERENCE_NOT_ACTIVE');
        }
        if ($reference['content_sensitivity_class'] === 'PROHIBITED_IN_V1') {
            $refuse('SOURCE_SENSITIVITY_PROHIBITED');
        }

        // --- robots and terms ------------------------------------------------------------------
        // The statuses are ALWAYS derived, so an auditor can see the review state of a source that
        // does not currently require rechecking. They only BLOCK when the card declares a recheck
        // window: a card that never asked for one is not failing a rule it did not set.
        $recheckWindowMs = $card['recheck_terms_after_ms'] ?? null;
        $reviewedMs = $latestTermsReview === null
            ? null
            : self::parseMs((string) $latestTermsReview['reviewed_at']);
        $reviewExpiresDeclared = ($latestTermsReview['expires_at'] ?? null) !== null;
        $reviewExpiresMs = $reviewExpiresDeclared
            ? self::parseMs((string) $latestTermsReview['expires_at'])
            : null;

        // An unreadable `reviewed_at` cannot establish that the window is still open, so it counts
        // as elapsed — the same direction as never having been reviewed.
        $windowElapsed = $recheckWindowMs !== null
            && ($reviewedMs === null || ($evaluationTimeMs - $reviewedMs) > (int) $recheckWindowMs);
        // A DECLARED but unreadable expiry counts as expired, for the same reason.
        $reviewExpired = $reviewExpiresDeclared
            && ($reviewExpiresMs === null || $evaluationTimeMs > $reviewExpiresMs);

        $robotsStatus = ($latestTermsReview === null || !((bool) ($latestTermsReview['robots_policy_checked'] ?? false)))
            ? 'NOT_CHECKED'
            : ($windowElapsed ? 'RECHECK_OVERDUE' : 'CHECKED_OK');
        $termsStatus = ($latestTermsReview === null || !((bool) ($latestTermsReview['terms_policy_checked'] ?? false)))
            ? 'NOT_CHECKED'
            : ($reviewExpired ? 'REVIEW_EXPIRED' : ($windowElapsed ? 'RECHECK_OVERDUE' : 'CHECKED_OK'));

        if ($recheckWindowMs !== null) {
            // The two codes are distinct because the remedies are: an unchecked robots policy needs
            // someone to check it; a lapsed window needs the whole review redone.
            if ($robotsStatus === 'NOT_CHECKED') {
                $refuse('ROBOTS_NOT_CHECKED');
            }
            if ($termsStatus !== 'CHECKED_OK') {
                $refuse('TERMS_RECHECK_OVERDUE');
            }
        }

        // --- connector resolution ----------------------------------------------------------------
        $mappedClass = $card === null
            ? null
            : (self::SOURCE_TYPE_TO_CONNECTOR_CLASS[(string) $card['source_type']] ?? null);
        if ($card !== null && $mappedClass === null) {
            $refuse('SOURCE_TYPE_UNSUPPORTED');
        }
        $classUnderEvaluation = $connector['connector_class'] ?? $mappedClass;
        if ($classUnderEvaluation !== null
            && !in_array($classUnderEvaluation, self::PROMOTED_CONNECTOR_CLASSES, true)
        ) {
            $refuse('CONNECTOR_CLASS_NOT_PROMOTED');
        }
        if ($connector === null || (string) $connector['status'] !== 'ACTIVE') {
            $refuse('CONNECTOR_NOT_ACTIVATED');
        } elseif (
            // The fixture class is the test and replay vehicle: no card type maps to it, so it is
            // exempt from the mapping check — but it still rides every authority gate above.
            (string) $connector['connector_class'] !== 'SYNTHETIC_TEST_FIXTURE'
            && $card !== null
            && $mappedClass !== null
            && (string) $connector['connector_class'] !== $mappedClass
        ) {
            $refuse('CONNECTOR_NOT_ACTIVATED');
        }

        // --- URL scope ---------------------------------------------------------------------------
        $resolvedClass = $connector['connector_class'] ?? $mappedClass ?? 'SYNTHETIC_TEST_FIXTURE';
        $urlScope = self::deriveUrlScope($card['source_url_safe'] ?? null);
        if (in_array($resolvedClass, self::NETWORK_CONNECTOR_CLASSES, true) && $urlScope === null) {
            $refuse('SOURCE_URL_MISSING_OR_INVALID');
        }

        // --- the snapshot -------------------------------------------------------------------------
        // Every projection falls back to the MOST restrictive value when the card is absent, so a
        // missing card never reads as a permissive one.
        $policy = [
            'status' => $reasons === [] ? 'COMPILED_ALLOW' : 'COMPILED_BLOCKED',
            'compiler_version' => self::COMPILER_VERSION,
            'source_ref_id' => $reference['source_ref_id'],
            'source_license_card_id' => $card['source_license_card_id'] ?? null,
            'connector_class' => $resolvedClass,
            'connector_version' => $connector['connector_version'] ?? 'UNRESOLVED',
            'allowed_url_scope' => $urlScope['scope'] ?? null,
            'allowed_hosts' => $urlScope === null ? [] : [$urlScope['host']],
            'allowed_methods' => ['GET'],
            'allowed_content_types' => $connector['required_content_types'] ?? [],
            'max_response_bytes' => self::DEFAULT_MAX_RESPONSE_BYTES,
            'timeout_ms' => self::DEFAULT_TIMEOUT_MS,
            'max_redirects' => 1,
            'cross_host_redirect_forbidden' => true,
            'robots_status' => $robotsStatus,
            'terms_status' => $termsStatus,
            'license_class' => $card['license_class'] ?? 'PROHIBITED',
            'contract_reference_id' => is_string($card['contract_reference_id'] ?? null) && trim($card['contract_reference_id']) !== '' ? trim($card['contract_reference_id']) : null,
            'allowed_use_class' => $card['allowed_use_class'] ?? 'prohibited',
            'retention_class' => $card['retention_class'] ?? 'prohibited',
            'minimum_fetch_interval_ms' => $card === null ? 0 : self::minimumFetchIntervalMs(
                $card['caching_policy'] ?? null,
                $card['rate_limit_policy'] ?? null,
            ),
            'customer_output_allowed' => (bool) ($card['customer_output_allowed'] ?? false),
            'sensitivity_class' => $reference['content_sensitivity_class'],
            'stale_recheck_required' => $card !== null && (
                ($card['warn_after_ms'] ?? null) !== null
                || ($card['error_after_ms'] ?? null) !== null
                || ($card['recheck_terms_after_ms'] ?? null) !== null
            ),
            'blocked_reason' => $reasons === [] ? null : 'compile blocked: ' . implode(', ', $reasons),
            'fail_closed_reason_codes' => $reasons,
        ];

        // The digest covers every semantic field and the input identities — but NOT the evaluation
        // time. Time reaches the digest only through the statuses and reason codes it produced, so
        // two compiles with identical semantics deduplicate rather than accumulating near-copies.
        $policy['policy_digest'] = self::digest($policy);

        return $policy;
    }

    /**
     * The ONE digest derivation, shared by `compile()` and by replay verification.
     *
     * A shared derivation is what makes a divergence a real integrity finding: if the compiler and
     * the verifier each had their own formula, every divergence would first have to be triaged as
     * possible formula drift, and a genuine one would be indistinguishable from it.
     *
     * @param array<string, mixed> $policy
     */
    public static function digest(array $policy): string
    {
        unset($policy['policy_digest']);
        ksort($policy);

        return Digest::canonicalDigest($policy);
    }

    /**
     * The cadence floor: the MAX of the caching window and any rate-limit floor.
     *
     * Taking the max rather than either alone means a source protected by both keeps the stricter
     * of the two, which is the only reading that respects both.
     */
    private static function minimumFetchIntervalMs(?string $cachingPolicy, ?string $rateLimitPolicy): int
    {
        $cache = self::CACHE_POLICY_WINDOW_MS[$cachingPolicy ?? 'no_cache'] ?? 0;
        $rate = 0;
        if ($rateLimitPolicy !== null) {
            $trimmed = trim($rateLimitPolicy);
            if (preg_match('/^\d+$/', $trimmed) === 1) {
                $rate = (int) $trimmed;
            } else {
                try {
                    $parsed = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($parsed) && is_int($parsed['minIntervalMs'] ?? null) && $parsed['minIntervalMs'] >= 0) {
                        $rate = $parsed['minIntervalMs'];
                    }
                } catch (\JsonException) {
                    // An unreadable rate-limit policy imposes no EXTRA floor; the caching window
                    // still applies. It does not fail the compile, because a malformed operator
                    // note is not evidence that the source forbids fetching.
                    $rate = 0;
                }
            }
        }

        return max($cache, $rate);
    }

    /**
     * The card URL is the egress authority: https only.
     *
     * Its HOSTNAME becomes the single-host allowlist, and its ORIGIN plus path prefix becomes the
     * authority scope. The two are deliberately different values answering different questions:
     *
     *   `host` feeds DNS resolution and private-address screening, so it must stay a bare hostname.
     *   `scope` is the authority the card grants — and a card for `https://example.com/pricing`
     *   authorizes THAT service, not every https service someone can stand up on another port of
     *   the same hostname.
     *
     * Folding the port out of the scope would let `https://example.com:8443/pricing` match a card
     * that never mentioned 8443, while the transport dutifully dialled the other service.
     *
     * @return array{scope: string, host: string}|null
     */
    public static function deriveUrlScope(?string $cardUrl): ?array
    {
        if ($cardUrl === null || trim($cardUrl) === '') {
            return null;
        }
        $parts = parse_url($cardUrl);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        // A card written as `https://example.com:443/x` normalizes to the same scope as
        // `https://example.com/x`: one card, one stored authority, however the owner spelled it.
        $authority = ($port === null || $port === 443) ? $host : "{$host}:{$port}";

        return ['scope' => 'https://' . $authority . ($parts['path'] ?? '/'), 'host' => $host];
    }

    /** Whether a concrete capture URL falls inside a compiled authority scope. */
    public static function urlWithinScope(string $allowedUrlScope, string $captureUrl): bool
    {
        $allowed = self::deriveUrlScope($allowedUrlScope);
        $candidate = self::deriveUrlScope($captureUrl);
        if ($allowed === null || $candidate === null) {
            return false;
        }
        $a = parse_url($allowed['scope']);
        $c = parse_url($candidate['scope']);
        if ($a === false || $c === false
            || ($a['scheme'] ?? null) !== ($c['scheme'] ?? null)
            || strtolower((string) ($a['host'] ?? '')) !== strtolower((string) ($c['host'] ?? ''))
            || (int) ($a['port'] ?? 443) !== (int) ($c['port'] ?? 443)) {
            return false;
        }
        $allowedPath = $a['path'] ?? '/';
        $candidatePath = $c['path'] ?? '/';
        if ($allowedPath === '/') {
            return true;
        }
        // A path prefix must end on a path-segment boundary. A card for /pricing admits
        // /pricing and /pricing/enterprise, but not /pricing-admin or /pricing2.
        $base = rtrim($allowedPath, '/');

        return $candidatePath === $base || str_starts_with($candidatePath, $base . '/');
    }

    /**
     * Milliseconds since the epoch for a canonical ISO-8601 instant, or null when unreadable.
     *
     * Null rather than a sentinel, so every caller has to decide what an unreadable timestamp means
     * for ITS rule. A sentinel would make that decision once, silently, and probably wrongly for
     * one of them — the two callers here fail closed in opposite directions.
     */
    private static function parseMs(string $iso): ?int
    {
        $timestamp = strtotime($iso);

        return $timestamp === false ? null : $timestamp * 1000;
    }
}
