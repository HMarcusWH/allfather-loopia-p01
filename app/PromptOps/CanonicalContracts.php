<?php
declare(strict_types=1);

namespace Allfather\Loopia\PromptOps;

/** Pure, runtime-inert canonical PromptOps port. See docs/PROMPTOPS_PORT.md. */
final class CanonicalContracts
{
    public const ROLES = ['entity_twin_analyst', 'source_quality_critic', 'mcm_hmwh_diagnostic_critic',
        'governance_arbiter_advisor', 'promptops_critic', 'meta_validation_critic'];
    public const CLAIM_CEILING = 'INTERNAL_PROMPTOPS_AND_META_VALIDATION_ARCHITECTURE_IMPORTED_RUNTIME_INERT';

    public static function hashPromptVersion(array $record): string
    {
        $parts = [];
        foreach (['promptId', 'role', 'version', 'systemPrompt', 'schemaVersion'] as $key) {
            if (!isset($record[$key]) || !is_string($record[$key])) {
                throw new \InvalidArgumentException('Prompt hash requires string identity fields');
            }
            $parts[] = $record[$key];
        }
        return hash('sha256', implode("\x1f", $parts));
    }

    public static function hashSchema(string $schemaVersion, mixed $schema, string $outputContractId): string
    {
        return hash('sha256', implode("\x1f", [$schemaVersion, self::stableJson($schema), $outputContractId]));
    }

    /** Narrow JSON-schema domain: ASCII object keys, integer constraints, Unicode string values. */
    public static function stableJson(mixed $value): string
    {
        if ($value instanceof \stdClass || (is_array($value) && !array_is_list($value))) {
            $map = (array) $value;
            $keys = array_map('strval', array_keys($map));
            foreach ($keys as $key) {
                if (preg_match('/[^\x20-\x7e]/', $key)) {
                    throw new \InvalidArgumentException('Non-ASCII schema keys outside bounded port');
                }
            }
            sort($keys, SORT_STRING);
            $parts = [];
            foreach ($keys as $key) {
                $parts[] = self::stableJson($key) . ':' . self::stableJson($map[$key]);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (is_array($value)) {
            return '[' . implode(',', array_map(self::stableJson(...), $value)) . ']';
        }
        if (!(is_null($value) || is_bool($value) || is_string($value) || is_int($value))) {
            throw new \InvalidArgumentException('Value outside bounded prompt schema JSON domain');
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /** Array-backed canonical store; deliberately retains upstream first-match semantics. */
    public static function resolve(array $store, string $role, string $nowUtc): array
    {
        $fail = static fn (string $code, string $message): array => [
            'status' => 'failed', 'failure' => ['role' => $role, 'code' => $code, 'message' => $message],
        ];
        $binding = null;
        foreach ($store['bindings'] as $candidate) {
            if ($candidate['role'] === $role) { $binding = $candidate; break; }
        }
        if ($binding === null) { return $fail('missing_binding', "Missing prompt binding for {$role}"); }
        if ($binding['approvalStatus'] !== 'approved') { return $fail('unapproved_binding', 'Prompt binding is not approved'); }
        if (!self::active($binding['activation'], $nowUtc)) { return $fail('inactive_binding', 'Prompt binding is outside its activation window'); }
        $prompt = null;
        foreach ($store['versions'] as $candidate) {
            if ($candidate['promptId'] === $binding['promptId'] && $candidate['version'] === $binding['version']) {
                $prompt = $candidate; break;
            }
        }
        if ($prompt === null) { return $fail('missing_prompt_version', 'Prompt binding points to a missing prompt version'); }
        if ($prompt['approvalStatus'] !== 'approved') { return $fail('unapproved_binding', 'Prompt version is not approved'); }
        if (!self::active($prompt['activation'], $nowUtc)) { return $fail('inactive_binding', 'Prompt version is outside its activation window'); }
        if ($prompt['role'] !== $binding['role']) { return $fail('role_mismatch', 'Prompt role differs from binding role'); }
        $actual = self::hashPromptVersion($prompt);
        if ($prompt['promptSha256'] !== $actual) { return $fail('prompt_hash_mismatch', 'Prompt record hash is stale'); }
        if ($actual !== $binding['expectedPromptSha256']) { return $fail('prompt_hash_mismatch', 'Prompt hash differs from expected hash'); }
        if ($prompt['schemaSha256'] !== $binding['expectedSchemaSha256']) { return $fail('schema_hash_mismatch', 'Schema hash differs from expected hash'); }
        return ['status' => 'resolved', 'resolved' => [
            'role' => $binding['role'], 'promptId' => $prompt['promptId'], 'version' => $prompt['version'],
            'systemPrompt' => $prompt['systemPrompt'], 'schemaVersion' => $prompt['schemaVersion'],
            'promptSha256' => $prompt['promptSha256'], 'schemaSha256' => $prompt['schemaSha256'],
            'bindingId' => $binding['bindingId'], 'source' => $binding['source'],
            'bindingSource' => $binding['source'], 'promptVersionSource' => $prompt['source'],
            'resolvedAtUtc' => $nowUtc, 'liveLlmCallsEnabled' => false, 'toolDispatchEnabled' => false,
            'externalActionAuthorized' => false, 'customerOutputEmitted' => false,
        ]];
    }

    public static function evaluate(array $store, bool $strict, string $nowUtc, array $requiredRoles = self::ROLES): array
    {
        $results = array_map(static fn ($r) => self::resolve($store, $r, $nowUtc), $requiredRoles);
        $resolved = []; $failures = []; $misroutes = []; $fallback = $store['storeMode'] === 'typescript-fallback';
        foreach ($results as $index => $result) {
            if ($result['status'] === 'failed') { $failures[] = $result['failure']; continue; }
            $r = $result['resolved']; $resolved[] = $r['role'];
            $fallback = $fallback || $r['bindingSource'] === 'typescript-fallback' || $r['promptVersionSource'] === 'typescript-fallback';
            if ($r['role'] !== $requiredRoles[$index]) { $misroutes[] = [$requiredRoles[$index], $r['role']]; }
        }
        $resolverFailureCount = count($failures);
        $unresolved = array_values(array_filter($requiredRoles, static fn ($r) => !in_array($r, $resolved, true)));
        $duplicates = [];
        foreach ($resolved as $i => $r) { if (array_search($r, $resolved, true) !== $i) { $duplicates[] = $r; } }
        foreach ($unresolved as $r) { $failures[] = ['role' => $r, 'code' => 'unresolved_required_role', 'message' => "Required role {$r} was not resolved exactly once"]; }
        foreach ($duplicates as $r) { $failures[] = ['role' => $r, 'code' => 'duplicate_resolved_role', 'message' => "Resolved role {$r} appeared more than once"]; }
        foreach ($misroutes as [$r, $actual]) { $failures[] = ['role' => $r, 'code' => 'misrouted_resolved_role', 'message' => "Requested role {$r} resolved as {$actual}"]; }
        if (count($resolved) !== count($requiredRoles)) {
            $failures[] = ['role' => $requiredRoles[0] ?? null, 'code' => 'resolved_role_count_mismatch',
                'message' => 'Resolved ' . count($resolved) . ' roles for ' . count($requiredRoles) . ' required roles'];
        }
        $blocked = $strict && $fallback;
        if ($blocked) { $failures[] = ['role' => $requiredRoles[0] ?? null, 'code' => 'strict_fallback_record_source', 'message' => 'Strict durable store mode blocks fallback-sourced prompt records']; }
        $oneToOne = !$unresolved && !$duplicates && !$misroutes && count($resolved) === count($requiredRoles);
        $report = ['status' => $blocked ? 'blocked' : ($resolverFailureCount === 0 && $oneToOne ? 'pass' : 'fail'),
            'claimCeiling' => self::CLAIM_CEILING, 'checkedAtUtc' => $nowUtc, 'strictDurableStoreMode' => $strict,
            'fallbackUsed' => $fallback, 'requiredRoles' => $requiredRoles, 'resolvedRoles' => $resolved,
            'unresolvedRoles' => $unresolved, 'failures' => $failures];
        foreach (['liveLlmCallsEnabled', 'promptMutationEnabled', 'durableRuntimeStoreEnabled', 'hostReadOnlySurfaceWidened',
            'extensionApiWidened', 'networkFetchEnabled', 'ownerGoAccepted', 'externalActionAuthorized',
            'customerOutputEmitted', 'crmWritePerformed', 'sendActionPerformed', 'externalWritePerformed',
            'productionReady', 'roadTest001Executed'] as $flag) { $report[$flag] = false; }
        return $report;
    }

    private static function active(array $window, string $now): bool
    {
        return strcmp($window['startsAt'], $now) <= 0
            && (!isset($window['endsAt']) || strcmp($now, $window['endsAt']) < 0);
    }
}
