<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ingestion;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;

/**
 * CR-03: bounded RFC 9309 policy interpretation over ALREADY SUPPLIED bytes.
 *
 * No HTTP, DNS, database, M06/M01 invocation or owner-authority creation.
 * The result records what robots rules say; it NEVER authorizes a read.
 * Current P01 Capture, SourcePolicy and EgressGuard remain sole native owners.
 */
final class RobotsPolicyReader
{
    public const VERSION = 'cr03.robots-disposition.v1';
    public const SOURCE_VERSION = 'cr03.robots-policy-bytes.v1';
    public const SCOPE_VERSION = 'cr03.acquisition-scope.v1';

    // RFC 9309 §2.5: a parser's byte ceiling MUST be at least 500 KiB.
    // This implementation accepts up to 512 KiB (524288 bytes), then HOLDS.
    public const MAX_ROBOTS_BYTES = 524288;
    public const MAX_LINES = 12000;
    public const MAX_LINE_BYTES = 8192;
    public const MAX_GROUPS = 1024;
    public const MAX_RULES = 8192;
    public const MAX_PATTERN_BYTES = 2048;
    public const MAX_WILDCARDS_PER_RULE = 16;
    public const MAX_PATH_BYTES = 4096;
    public const MAX_TTL_MS = 86400000; // 24 h; no stale-allow fallback
    private const POLICY_STATUSES = ['FETCHED', 'UNAVAILABLE', 'UNREACHABLE', 'NOT_FETCHED'];

    private const SOURCE_KEYS = [
        'body', 'content_type', 'http_status', 'kind', 'observed_at_ms',
        'origin', 'raw_sha256', 'received_at_ms', 'robots_url',
        'source_card_id', 'source_ref_id', 'status', 'tenant_id', 'workspace_id',
    ];
    private const SCOPE_KEYS = [
        'allowed_url_scope', 'kind', 'last_capture_at_ms', 'min_interval_ms',
        'native_execution_admitted', 'now_ms', 'owner_source_use',
        'product_token', 'purpose', 'remaining_attempts', 'remaining_bytes',
        'retry_after_until_ms', 'robots_url', 'source_card_id', 'source_ref_id',
        'target_url', 'tenant_id', 'workspace_id',
    ];

    /**
     * @param array<string,mixed> $source RobotsPolicyBytes.v1, offline fixture.
     * @param array<string,mixed> $scope AcquisitionScope.v1, offline fixture.
     * @return Outcome<array<string,mixed>>
     */
    public static function decide(array $source, array $scope): Outcome
    {
        $keys = array_keys($source); sort($keys, SORT_STRING);
        if ($keys !== self::SOURCE_KEYS || ($source['kind'] ?? null) !== self::SOURCE_VERSION) {
            return Outcome::refuse('CR03_ROBOTS_SOURCE_CONTRACT_INVALID');
        }
        $keys = array_keys($scope); sort($keys, SORT_STRING);
        if ($keys !== self::SCOPE_KEYS || ($scope['kind'] ?? null) !== self::SCOPE_VERSION) {
            return Outcome::refuse('CR03_ACQUISITION_SCOPE_CONTRACT_INVALID');
        }
        foreach (['tenant_id', 'workspace_id', 'source_ref_id', 'source_card_id'] as $key) {
            if (!is_string($scope[$key]) || !Ids::isValid($scope[$key])
                || !is_string($source[$key]) || !Ids::isValid($source[$key])
                || $scope[$key] !== $source[$key]) {
                return Outcome::refuse('CR03_OWNER_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
            }
        }
        if ($scope['tenant_id'] === $scope['workspace_id']
            || $scope['source_ref_id'] === $scope['source_card_id']) {
            return Outcome::refuse('CR03_AMBIGUOUS_OWNER_IDENTITY');
        }
        // This is a TEST/CANDIDATE carrier. A boolean or a JSON registry
        // cannot mint a native M06/M01/source-rights admission.
        if ($scope['native_execution_admitted'] !== false
            || $scope['owner_source_use'] !== 'NOT_ADMITTED'
            || $scope['purpose'] !== 'OFFLINE_ROBOTS_INTERPRETATION') {
            return Outcome::refuse('CR03_NATIVE_OWNER_ADMISSION_REQUIRED');
        }
        if (!is_string($scope['product_token'])
            || preg_match('/^[A-Za-z_-]{1,64}$/D', $scope['product_token']) !== 1
            || $scope['product_token'] === '*') {
            return Outcome::refuse('CR03_PRODUCT_TOKEN_INVALID');
        }
        $origin = self::origin($scope['allowed_url_scope']);
        if ($origin === null
            || $origin !== self::origin($scope['target_url'])
            || $origin !== ($source['origin'] ?? null)
            || $source['robots_url'] !== $origin . '/robots.txt'
            || $scope['robots_url'] !== $source['robots_url']
            || !SourcePolicy::urlWithinScope($scope['allowed_url_scope'], $scope['target_url'])
            || $scope['target_url'] === $scope['robots_url']
            || !self::isSafeTarget($scope['target_url'])) {
            return Outcome::refuse('CR03_SOURCE_CARD_PATH_NOT_ROBOTS_AUTHORITY', [], 'SCOPE', 'S4');
        }
        foreach (['now_ms', 'min_interval_ms', 'remaining_attempts', 'remaining_bytes'] as $name) {
            if (!is_int($scope[$name]) || $scope[$name] < 0) {
                return Outcome::refuse('CR03_THROTTLE_OR_BUDGET_INVALID');
            }
        }
        if ($scope['now_ms'] <= 0 || $scope['min_interval_ms'] > 31536000000
            || $scope['remaining_attempts'] > 1000000 || $scope['remaining_bytes'] > 1000000000) {
            return Outcome::refuse('CR03_THROTTLE_OR_BUDGET_INVALID');
        }
        foreach (['last_capture_at_ms', 'retry_after_until_ms'] as $name) {
            if ($scope[$name] !== null && (!is_int($scope[$name]) || $scope[$name] < 0)) {
                return Outcome::refuse('CR03_THROTTLE_OR_BUDGET_INVALID');
            }
        }
        if ($scope['last_capture_at_ms'] !== null && $scope['last_capture_at_ms'] > $scope['now_ms']) {
            return Outcome::refuse('CR03_FUTURE_CAPTURE_TIME');
        }
        if (!in_array($source['status'], self::POLICY_STATUSES, true)) {
            return Outcome::refuse('CR03_ROBOTS_STATUS_INVALID');
        }
        $rfc = 'UNKNOWN';
        $reason = 'ROBOTS_NOT_FETCHED';
        $freshness = 'NO_POLICY';
        $matched = null;
        $selectedGroups = [];
        $ruleCount = 0;
        $parseWarnings = [];
        $bodyDigest = null;
        $received = $source['received_at_ms'];
        $observed = $source['observed_at_ms'];
        if ($source['status'] === 'NOT_FETCHED') {
            if ($source['body'] !== null || $source['raw_sha256'] !== null
                || $source['http_status'] !== null || $source['content_type'] !== null
                || $received !== null || $observed !== null) {
                return Outcome::refuse('CR03_UNFETCHED_POLICY_HAS_BYTES');
            }
        } else {
            if (!is_int($received) || !is_int($observed)
                || $received <= 0 || $observed <= 0
                || $received < $observed || $received > $scope['now_ms']
                || $scope['now_ms'] - $received > 31536000000) {
                return Outcome::refuse('CR03_POLICY_CLOCK_INVALID');
            }
            if ($source['body'] !== null || $source['raw_sha256'] !== null) {
                if (!is_string($source['body']) || !is_string($source['raw_sha256'])
                    || !preg_match('/^[a-f0-9]{64}$/D', $source['raw_sha256'])
                    || !hash_equals(hash('sha256', $source['body']), $source['raw_sha256'])) {
                    return Outcome::refuse('CR03_POLICY_BYTE_DIGEST_INVALID');
                }
                $bodyDigest = $source['raw_sha256'];
            }
            $age = $scope['now_ms'] - $received;
            $freshness = $age <= self::MAX_TTL_MS ? 'FRESH' : 'EXPIRED';
        }

        if ($source['status'] === 'FETCHED') {
            if ($source['http_status'] !== 200
                || !is_string($source['content_type'])
                || strtolower(trim(explode(';', $source['content_type'])[0])) !== 'text/plain'
                || $bodyDigest === null) {
                return Outcome::refuse('CR03_ROBOTS_HTTP_OR_CONTENT_TYPE_INVALID');
            }
            $body = $source['body'];
            if (strlen($body) > self::MAX_ROBOTS_BYTES) {
                return Outcome::refuse('CR03_ROBOTS_BYTE_CEILING');
            }
            if (!mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) {
                return Outcome::refuse('CR03_ROBOTS_ENCODING_INVALID');
            }
            if ($freshness === 'EXPIRED') {
                $rfc = 'UNKNOWN';
                $reason = 'ROBOTS_POLICY_EXPIRED';
            } else {
                $parsed = self::parse($body);
                if (!$parsed->isOk()) {
                    return $parsed;
                }
                $data = $parsed->value();
                $ruleCount = $data['rule_count'];
                $parseWarnings = $data['warnings'];
                $judgment = self::evaluate($data['groups'], $scope['product_token'], $scope['target_url']);
                if (!$judgment->isOk()) {
                    return $judgment;
                }
                $decision = $judgment->value();
                $rfc = $decision['allowed'] ? 'ALLOW' : 'DISALLOW';
                $reason = $decision['reason'];
                $matched = $decision['matched'];
                $selectedGroups = $decision['groups'];
            }
        } elseif ($source['status'] === 'UNAVAILABLE') {
            if ($source['body'] !== null || $source['raw_sha256'] !== null
                || !is_int($source['http_status'])
                || $source['http_status'] < 400 || $source['http_status'] > 499) {
                return Outcome::refuse('CR03_UNAVAILABLE_STATUS_INVALID');
            }
            if ($source['http_status'] === 429) {
                $reason = 'ROBOTS_HTTP_429_THROTTLE';
                $rfc = 'UNAVAILABLE_MAY_ALLOW';
            } else {
                $reason = 'ROBOTS_HTTP_4XX_UNAVAILABLE';
                $rfc = 'UNAVAILABLE_MAY_ALLOW';
            }
        } elseif ($source['status'] === 'UNREACHABLE') {
            if ($source['body'] !== null || $source['raw_sha256'] !== null
                || ($source['http_status'] !== null
                    && (!is_int($source['http_status']) || $source['http_status'] < 500 || $source['http_status'] > 599))) {
                return Outcome::refuse('CR03_UNREACHABLE_STATUS_INVALID');
            }
            $rfc = 'DISALLOW';
            $reason = 'ROBOTS_UNREACHABLE_DISALLOW';
        }

        $holds = [];
        // RFC 9309 is a crawl hint, NEVER source licensing or API authority.
        // Even RFC ALLOW is held until a separate native owner decision.
        if ($rfc !== 'DISALLOW') {
            $holds[] = 'NATIVE_SOURCE_USE_AND_M06_M01_ADMISSION_REQUIRED';
        }
        if ($freshness !== 'FRESH') {
            $holds[] = 'ROBOTS_FRESH_POLICY_REQUIRED';
        }
        if ($scope['remaining_attempts'] === 0 || $scope['remaining_bytes'] === 0) {
            $holds[] = 'SOURCE_ATTEMPT_OR_BYTE_BUDGET_EXHAUSTED';
        }
        if ($scope['last_capture_at_ms'] !== null
            && $scope['now_ms'] < $scope['last_capture_at_ms'] + $scope['min_interval_ms']) {
            $holds[] = 'SOURCE_OWNER_CADENCE_HOLD';
        }
        if ($scope['retry_after_until_ms'] !== null && $scope['now_ms'] < $scope['retry_after_until_ms']) {
            $holds[] = 'RETRY_AFTER_ACTIVE';
        }
        if ($source['status'] === 'UNAVAILABLE' && $source['http_status'] === 429) {
            $holds[] = 'HTTP_429_REQUIRES_OWNER_RETRY_SCHEDULE';
        }
        $result = [
            'schema_version' => self::VERSION,
            'policy_protocol' => 'RFC9309_OFFLINE_INTERPRETATION',
            'robots_url' => $source['robots_url'],
            'source_origin' => $origin,
            'source_ref_id' => $scope['source_ref_id'],
            'source_card_id' => $scope['source_card_id'],
            'tenant_id' => $scope['tenant_id'],
            'workspace_id' => $scope['workspace_id'],
            'purpose' => $scope['purpose'],
            'target_path_digest' => hash('sha256', self::targetPath($scope['target_url']) ?? ''),
            'product_token' => $scope['product_token'],
            'robots_bytes_digest' => $bodyDigest,
            'robots_freshness' => $freshness,
            'robots_status' => $source['status'],
            'robots_received_at_ms' => $received,
            'rfc_decision' => $rfc,
            'rfc_reason' => $reason,
            'matched_rule' => $matched,
            'matched_groups' => $selectedGroups,
            'parsed_rule_count' => $ruleCount,
            'parser_warnings' => $parseWarnings,
            'owner_policy_holds' => array_values(array_unique($holds)),
            'owner_source_use_authorized' => false,
            'm06_m01_execution_authorized' => false,
            'new_robots_request_authorized' => false,
            'network_attempt_count' => 0,
            'effective_acquisition' => $rfc === 'DISALLOW' ? 'DENY_ROBOTS' : 'HOLD_NOT_AUTHORIZED',
            'source_candidate_only' => true,
        ];
        $result['decision_digest'] = Digest::canonicalDigest($result);
        return Outcome::ok($result);
    }

    /**
     * Bounded REP groups. Malformed individual records are ignored as the RFC
     * requires; structural resource/encoding failures are REFUSED, not ALLOW.
     * @return Outcome<array{groups:list<array>,rule_count:int,warnings:list<string>}>
     */
    private static function parse(string $body): Outcome
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $lines = preg_split('/\r\n|\r|\n/', $body);
        if (!is_array($lines) || count($lines) > self::MAX_LINES) {
            return Outcome::refuse('CR03_ROBOTS_LINE_CEILING');
        }
        $groups = [];
        $agents = [];
        $rules = [];
        $hadRules = false;
        $warning = [];
        $total = 0;
        $finish = static function () use (&$agents, &$rules, &$groups, &$hadRules): void {
            if ($agents !== []) {
                $groups[] = ['agents' => $agents, 'rules' => $rules];
            }
            $agents = [];
            $rules = [];
            $hadRules = false;
        };
        foreach ($lines as $line) {
            if (strlen($line) > self::MAX_LINE_BYTES) {
                return Outcome::refuse('CR03_ROBOTS_LINE_BYTE_CEILING');
            }
            // A '#' begins a comment. Literal hash in a path is %23.
            $commentAt = strpos($line, '#');
            if ($commentAt !== false) {
                $line = substr($line, 0, $commentAt);
            }
            $line = trim($line, " \t");
            if ($line === '') {
                continue; // empty lines may appear inside a group
            }
            if (preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/D', $line, $parts) !== 1) {
                $warning['INVALID_LINE_SKIPPED'] = true;
                continue;
            }
            $key = strtolower($parts[1]);
            $value = trim($parts[2], " \t");
            if ($key === 'user-agent') {
                if ($value !== '*' && preg_match('/^[A-Za-z_-]+$/D', $value) !== 1) {
                    $warning['INVALID_AGENT_SKIPPED'] = true;
                    continue;
                }
                if ($hadRules) {
                    $finish();
                }
                $agents[] = strtolower($value);
                if (count($agents) > 128) {
                    return Outcome::refuse('CR03_ROBOTS_AGENT_CEILING');
                }
                continue;
            }
            if ($key !== 'allow' && $key !== 'disallow') {
                // Sitemap/crawl-delay/unknown records MUST NOT terminate groups.
                continue;
            }
            if ($agents === []) {
                $warning['ORPHAN_RULE_SKIPPED'] = true;
                continue;
            }
            $hadRules = true;
            if ($value === '') {
                continue; // empty Disallow has no restrictions
            }
            if (!str_starts_with($value, '/') && !str_starts_with($value, '*')) {
                $warning['INVALID_RULE_SKIPPED'] = true;
                continue;
            }
            if (strlen($value) > self::MAX_PATTERN_BYTES
                || substr_count($value, '*') > self::MAX_WILDCARDS_PER_RULE) {
                return Outcome::refuse('CR03_ROBOTS_PATTERN_CEILING');
            }
            $anchored = str_ends_with($value, '$');
            $path = $anchored ? substr($value, 0, -1) : $value;
            if (str_contains($path, '$')) {
                $warning['INVALID_RULE_SKIPPED'] = true;
                continue;
            }
            $canonical = self::normalizePath($path, true);
            if ($canonical === null) {
                $warning['INVALID_RULE_SKIPPED'] = true;
                continue;
            }
            $specificity = self::specificity($canonical);
            $rules[] = ['directive' => strtoupper($key), 'pattern' => $canonical,
                'anchored' => $anchored, 'specificity_octets' => $specificity];
            $total++;
            if ($total > self::MAX_RULES) {
                return Outcome::refuse('CR03_ROBOTS_RULE_CEILING');
            }
        }
        $finish();
        if (count($groups) > self::MAX_GROUPS) {
            return Outcome::refuse('CR03_ROBOTS_GROUP_CEILING');
        }
        return Outcome::ok(['groups' => $groups, 'rule_count' => $total,
            'warnings' => array_keys($warning)]);
    }

    /** @return Outcome<array{allowed:bool,reason:string,matched:?array,groups:list<string>}> */
    private static function evaluate(array $groups, string $token, string $target): Outcome
    {
        $path = self::targetPath($target);
        if ($path === null) {
            return Outcome::refuse('CR03_TARGET_PATH_INVALID');
        }
        $canonical = self::normalizePath($path, false);
        if ($canonical === null || strlen($canonical) > self::MAX_PATH_BYTES) {
            return Outcome::refuse('CR03_TARGET_PATH_INVALID');
        }
        // RFC: the top-level /robots.txt URI is implicitly permitted by REP.
        // This does NOT authorize the *request* to fetch it.
        if ($canonical === '/robots.txt') {
            return Outcome::ok(['allowed' => true, 'reason' => 'ROBOTS_FILE_IMPLICIT_ALLOW',
                'matched' => null, 'groups' => []]);
        }
        $token = strtolower($token);
        $matching = [];
        $fallback = [];
        foreach ($groups as $group) {
            if (in_array($token, $group['agents'], true)) {
                $matching[] = $group;
            } elseif (in_array('*', $group['agents'], true)) {
                $fallback[] = $group;
            }
        }
        $selected = $matching !== [] ? $matching : $fallback;
        $groupKind = $matching !== [] ? $token : ($fallback !== [] ? '*' : 'NO_APPLICABLE_GROUP');
        $best = null;
        foreach ($selected as $group) {
            foreach ($group['rules'] as $rule) {
                if (!self::matches($canonical, $rule['pattern'], $rule['anchored'])) {
                    continue;
                }
                if ($best === null
                    || $rule['specificity_octets'] > $best['specificity_octets']
                    || ($rule['specificity_octets'] === $best['specificity_octets']
                        && $rule['directive'] === 'ALLOW' && $best['directive'] === 'DISALLOW')) {
                    $best = $rule;
                }
            }
        }
        $decision = $best === null || $best['directive'] === 'ALLOW';
        // Never emit the untrusted raw rule or target path in the receipt.
        $matched = $best === null ? null : [
            'directive' => $best['directive'],
            'rule_sha256' => Digest::canonicalDigest($best),
            'specificity_octets' => $best['specificity_octets'],
            'anchored' => $best['anchored'],
        ];
        return Outcome::ok(['allowed' => $decision,
            'reason' => $best === null ? 'ROBOTS_NO_MATCH_DEFAULT_ALLOW'
                : ($decision ? 'ROBOTS_ALLOW_MOST_SPECIFIC' : 'ROBOTS_DISALLOW_MOST_SPECIFIC'),
            'matched' => $matched, 'groups' => [$groupKind]]);
    }

    /** Deterministic literal segments plus '*' glob and optional '$' suffix. No regex DoS. */
    private static function matches(string $target, string $pattern, bool $anchored): bool
    {
        $tokens = explode('*', $pattern);
        if (!str_starts_with($target, $tokens[0])) {
            return false;
        }
        $cursor = strlen($tokens[0]);
        for ($i = 1, $n = count($tokens); $i < $n; $i++) {
            $literal = $tokens[$i];
            if ($literal === '') {
                continue;
            }
            if ($anchored && $i === $n - 1) {
                $pos = strlen($target) - strlen($literal);
                if ($pos < $cursor || substr($target, $pos) !== $literal) {
                    return false;
                }
                $cursor = strlen($target);
                continue;
            }
            $pos = strpos($target, $literal, $cursor);
            if ($pos === false) {
                return false;
            }
            $cursor = $pos + strlen($literal);
        }
        // A trailing wildcard can consume the remainder, including with an end anchor.
        if ($anchored && count($tokens) > 1 && $tokens[count($tokens) - 1] === '') {
            return true;
        }
        return !$anchored || $cursor === strlen($target);
    }

    /** RFC 3986 path normalization; no dynamic regex or network access. */
    private static function normalizePath(string $path, bool $pattern): ?string
    {
        $out = '';
        for ($i = 0, $n = strlen($path); $i < $n; $i++) {
            $c = $path[$i];
            $byte = ord($c);
            if ($c === '%') {
                if ($i + 2 >= $n || !ctype_xdigit($path[$i + 1]) || !ctype_xdigit($path[$i + 2])) {
                    return null;
                }
                $hex = strtoupper(substr($path, $i + 1, 2));
                $raw = chr(hexdec($hex));
                $out .= self::isUnreserved($raw) ? $raw : '%' . $hex;
                $i += 2;
            } elseif ($byte < 32 || $byte === 127) {
                return null;
            } elseif ($byte >= 128) {
                $out .= '%' . strtoupper(bin2hex($c));
            } elseif (($c === '*' || $c === '$') && !$pattern) {
                $out .= '%' . strtoupper(bin2hex($c));
            } elseif (self::isUnreserved($c) || $c === '/' || $c === '?'
                || $c === '=' || $c === '&' || ($pattern && $c === '*')) {
                $out .= $c;
            } else {
                $out .= '%' . strtoupper(bin2hex($c));
            }
            if (strlen($out) > self::MAX_PATH_BYTES) {
                return null;
            }
        }
        return $out;
    }

    private static function specificity(string $pattern): int
    {
        $octets = 0;
        for ($i = 0, $n = strlen($pattern); $i < $n; $i++) {
            if ($pattern[$i] === '*') {
                continue;
            }
            if ($pattern[$i] === '%') {
                $i += 2;
            }
            $octets++;
        }
        return $octets;
    }

    private static function isUnreserved(string $c): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]$/D', $c) === 1;
    }

    private static function origin(string $url): ?string
    {
        if (!is_string($url) || trim($url) !== $url || strlen($url) > self::MAX_PATH_BYTES
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ($parts['host'] ?? '') === '' || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (str_contains($host, '[') || str_contains($host, ']')
            || !preg_match('/^[a-z0-9.-]+$/D', $host)) {
            return null; // intentionally no IPv6 literal in offline candidate interface
        }
        $port = $parts['port'] ?? 443;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            return null;
        }
        return 'https://' . $host . ($port === 443 ? '' : ':' . $port);
    }

    private static function targetPath(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['fragment'])) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function isSafeTarget(string $url): bool
    {
        $path = self::targetPath($url);
        if ($path === null || strlen($path) > self::MAX_PATH_BYTES) {
            return false;
        }
        return self::normalizePath($path, false) !== null;
    }
}

