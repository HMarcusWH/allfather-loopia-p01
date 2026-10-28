<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Ids;

/**
 * BF-01: executable, strictly scoped candidate contracts for offline builds.
 *
 * An accepted CANDIDATE is never an M02/M05/source-use decision, an execution
 * grant, or an M10 informational release. The owning runtime remains elsewhere.
 */
final class BuildFirstContracts
{
    public const PRIMARY_CONTRACTS = [
        'CapturedSourceEnvelope', 'DocumentProjection', 'SemanticDelta',
        'ObservationAssessmentCandidate', 'HistoricalBasis', 'CurrentUseDecision',
        'MemoryCandidate', 'DependencyIndex', 'ReviewDueDecision',
        'AttemptReservation', 'ReviewRun', 'HistoricalFindingCandidate',
        'InternalAttention', 'RecoveryManifest',
    ];

    private const V = '1.0.0-candidate';
    private const STATES = [
        'UNKNOWN', 'CANDIDATE', 'HELD', 'REFUSED', 'NOT_RUN',
        'NOT_ASSESSED', 'NONMATERIAL', 'PROVISIONAL', 'DUE', 'NOT_DUE',
    ];

    /** @return array<string,mixed> */
    public static function loadAndValidate(string $root): array
    {
        $root = rtrim($root, '/') . '/';
        $basis = BuildFirstRegistry::loadAndValidate($root);
        $registry = self::readJson($root . 'docs/longitudinal/build-first/CONTRACT_REGISTRY.json');
        $examples = self::readJson($root . 'tests/fixtures/longitudinal/contracts/candidate_examples.v1.json');

        self::expect(($registry['schema_version'] ?? null) === 'allfather.build_first.contract_registry.v1', 'REGISTRY_VERSION');
        self::expect(($examples['schema_version'] ?? null) === 'allfather.build_first.examples.v1', 'EXAMPLE_VERSION');
        self::expect(($registry['register_status'] ?? null) === 'SYNTHETIC_CANDIDATE_ONLY_NOT_RUNTIME_CONTRACT_ADOPTION', 'CANDIDATE_STATUS');
        self::expect(($examples['classification'] ?? null) === 'SYNTHETIC_ONLY_NO_EXECUTION_GRANT', 'EXAMPLE_CLASSIFICATION');
        self::expect(($registry['source_plan_sha256'] ?? null) === $basis['decisions']['execution_manifest_sha256'], 'PLAN_SOURCE_DRIFT');
        self::expect(($registry['source_integration_base'] ?? null) === $basis['decisions']['source_snapshot']['base_sha']
            || ($registry['source_integration_base'] ?? null) === '6719f769035198dd9dd6b492a727d5e06d788328', 'INTEGRATION_BASE');
        foreach (['can_grant_execution', 'can_bind_native_factories', 'can_disclose_source_bytes', 'can_install_sql'] as $k) {
            self::expect(($registry[$k] ?? null) === false, 'AUTHORITY_BOUNDARY_' . $k);
        }
        self::expect(($registry['common_candidate_schema']['unknown_representation'] ?? null) === 'UNKNOWN', 'UNKNOWN_DISPOSITION');
        self::expect(($registry['common_candidate_schema']['additional_properties'] ?? null) === false, 'UNKNOWN_KEYS');
        self::expect(($registry['default_fixture_env'] ?? null) === 'CLI_ONLY', 'FIXTURE_SCOPE');

        $packages = array_column($basis['capabilities']['build_packages'], null, 'id');
        $contracts = [];
        foreach ($registry['index'] ?? [] as $record) {
            self::expect(is_array($record), 'INDEX_RECORD');
            $name = $record['name'] ?? null;
            self::expect(is_string($name) && preg_match('/^[A-Z][A-Za-z0-9]+$/D', $name) === 1 && !isset($contracts[$name]), 'INDEX_ID');
            self::expect(($record['version'] ?? null) === self::V, 'INDEX_VERSION');
            self::expect(($record['carrier'] ?? null) === 'BF01_CANDIDATE_ENVELOPE_V1', 'CANDIDATE_CARRIER');
            self::expect(($record['production_adoption'] ?? null) === 'NOT_GRANTED', 'INDEX_PROMOTED');
            self::expect(($record['producer_wiring'] ?? null) === 'UNVERIFIED_UNTIL_REAL_CONSUMER_IN_RT02', 'INDEX_WIRING');
            $producers = $record['producers'] ?? null;
            $consumers = $record['consumers'] ?? null;
            self::expect(is_array($producers) && is_array($consumers), 'PRODUCER_CONSUMER_SHAPE');
            if ($producers === []) {
                self::expect(($record['external_owner_input'] ?? false) === true, 'UNOWNED_EXTERNAL_INPUT');
            }
            foreach ([...$producers, ...$consumers] as $package) {
                self::expect(is_string($package) && isset($packages[$package]), 'UNKNOWN_PACKAGE_BINDING');
            }
            $contracts[$name] = $record;
        }
        self::expect(count($contracts) === 64, 'INDEX_COUNT');
        $primary = [];
        foreach ($registry['primary_contracts'] ?? [] as $record) {
            self::expect(is_array($record), 'PRIMARY_RECORD');
            $name = $record['name'] ?? null;
            self::expect(is_string($name) && isset($contracts[$name]) && in_array($name, self::PRIMARY_CONTRACTS, true) && !isset($primary[$name]), 'PRIMARY_ID');
            self::expect(($record['version'] ?? null) === self::V, 'PRIMARY_VERSION');
            self::expect(($record['candidate_only'] ?? false) === true && ($record['owner_import_required'] ?? false) === true, 'PRIMARY_AUTHORITY');
            $fields = $record['fields'] ?? null;
            self::expect(is_array($fields) && !array_is_list($fields), 'PRIMARY_FIELDS');
            $common = $registry['common_candidate_schema']['fields'] ?? null;
            self::expect(is_array($common), 'COMMON_FIELDS');
            foreach ($common as $field => $type) {
                self::expect(($fields[$field] ?? null) === $type, 'MISSING_COMMON_FIELD');
            }
            foreach ($fields as $field => $type) {
                self::expect(is_string($field) && preg_match('/^[a-z][a-z0-9_]*$/D', $field) === 1, 'FIELD_NAME');
                self::expect(in_array($type, ['uuid','sha256','sha1','clock','text','uint','bool','false','ref','refs','state','sections'], true), 'UNSUPPORTED_FIELD_TYPE');
            }
            $primary[$name] = $record;
        }
        self::expect(count($primary) === count(self::PRIMARY_CONTRACTS), 'PRIMARY_COUNT');
        foreach (self::PRIMARY_CONTRACTS as $name) {
            self::expect(isset($primary[$name]), 'MISSING_PRIMARY');
        }
        self::expect(count($examples['cases'] ?? []) === 64, 'EXAMPLE_COVERAGE');
        foreach ($contracts as $name => $_) {
            $pair = $examples['cases'][$name] ?? null;
            self::expect(is_array($pair), 'NO_EXAMPLE_' . $name);
            $positive = self::checkCandidate($registry, $name, $pair['positive'] ?? null);
            self::expect($positive['outcome'] === 'VALID_CANDIDATE', 'POSITIVE_EXAMPLE_' . $name . '_' . $positive['reason_code']);
            $refusal = $pair['refusal'] ?? null;
            self::expect(is_array($refusal), 'NO_REFUSAL_EXAMPLE');
            $negative = self::checkCandidate($registry, $name, $refusal['candidate'] ?? null);
            self::expect($negative['outcome'] === 'REFUSED'
                && $negative['reason_code'] === ($refusal['reason_code'] ?? null), 'REFUSAL_EXAMPLE_' . $name);
        }
        return $registry;
    }

    /** @param array<string,mixed> $registry
     *  @return array{outcome:string,reason_code:string,digest:?string}
     */
    public static function checkCandidate(array $registry, string $name, mixed $instance): array
    {
        $refuse = static fn (string $why): array => ['outcome' => 'REFUSED', 'reason_code' => $why, 'digest' => null];
        if (($registry['schema_version'] ?? null) !== 'allfather.build_first.contract_registry.v1') {
            return $refuse('BF01_REGISTRY_INVALID');
        }
        $index = [];
        foreach ($registry['index'] ?? [] as $item) {
            if (is_array($item) && isset($item['name'])) { $index[$item['name']] = $item; }
        }
        $entry = $index[$name] ?? null;
        if ($entry === null) { return $refuse('BF01_CONTRACT_UNKNOWN'); }
        if (!is_array($instance) || array_is_list($instance)) { return $refuse('BF01_ENVELOPE_NOT_OBJECT'); }
        if (array_diff(array_keys($instance), ['contract','version','candidate_only','payload']) !== []) {
            return $refuse('BF01_ENVELOPE_EXTRA_FIELD');
        }
        if (($instance['contract'] ?? null) !== $name || ($instance['version'] ?? null) !== self::V
            || ($instance['candidate_only'] ?? null) !== true) {
            return $refuse('BF01_ENVELOPE_IDENTITY');
        }
        $payload = $instance['payload'] ?? null;
        if (!is_array($payload) || array_is_list($payload)) { return $refuse('BF01_PAYLOAD_NOT_OBJECT'); }
        $fields = $registry['common_candidate_schema']['fields'] ?? [];
        foreach ($registry['primary_contracts'] ?? [] as $record) {
            if (($record['name'] ?? null) === $name) {
                $fields = $record['fields'];
                break;
            }
        }
        if (!is_array($fields)) { return $refuse('BF01_FIELD_SCHEMA_MISSING'); }
        foreach ($fields as $key => $type) {
            if (!array_key_exists($key, $payload)) {
                return $refuse('BF01_REQUIRED_FIELD_MISSING');
            }
        }
        if (array_diff(array_keys($payload), array_keys($fields)) !== []) {
            return $refuse('BF01_PAYLOAD_EXTRA_FIELD');
        }
        foreach ($fields as $key => $type) {
            if (!self::validField((string) $type, $payload[$key])) {
                return $refuse('BF01_FIELD_TYPE_INVALID');
            }
        }
        if (($payload['scope_tenant_id'] ?? null) === ($payload['scope_workspace_id'] ?? null)) {
            return $refuse('BF01_SCOPE_COLLAPSED');
        }
        if ($name === 'HistoricalBasis') {
            if ($payload['period_start'] >= $payload['period_end'] || $payload['known_cutoff'] < $payload['period_start']) {
                return $refuse('BF01_CLOCK_ORDER');
            }
        }
        if ($name === 'CapturedSourceEnvelope' && $payload['known_at'] < $payload['observed_at']) {
            return $refuse('BF01_CLOCK_ORDER');
        }
        if ($name === 'CurrentUseDecision' && $payload['authorization_proven'] !== false) {
            return $refuse('BF01_NONOWNER_AUTHORIZATION');
        }
        return ['outcome'=>'VALID_CANDIDATE','reason_code'=>'BF01_CANDIDATE_ONLY_NOT_AUTHORITY','digest'=>Digest::canonicalDigest($instance)];
    }

    private static function validField(string $type, mixed $value): bool
    {
        return match ($type) {
            'uuid' => is_string($value) && Ids::isValid($value),
            'sha256' => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1,
            'sha1' => is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) === 1,
            'clock' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) === 1
                && \Allfather\Loopia\Core\Clock::parse($value) !== null,
            'text' => is_string($value) && strlen($value) > 0 && strlen($value) <= 1024
                && !str_contains($value, "\0") && preg_match('/[\r\n]/', $value) !== 1,
            'uint' => is_int($value) && $value >= 0 && $value <= 1000000,
            'bool' => is_bool($value),
            'false' => $value === false,
            'ref' => self::validRef($value),
            'refs' => is_array($value) && array_is_list($value) && count($value) <= 64
                && array_reduce($value, static fn (bool $ok, mixed $v): bool => $ok && self::validRef($v), true)
                && count(array_unique($value)) === count($value),
            'state' => is_string($value) && in_array($value, self::STATES, true),
            'sections' => self::validSections($value),
            default => false,
        };
    }

    private static function validRef(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9._:\/-]{0,190}$/D', $value) === 1
            && !str_contains($value, '://') && !str_contains($value, '..');
    }

    private static function validSections(mixed $sections): bool
    {
        if (!is_array($sections) || !array_is_list($sections) || count($sections) > 32) { return false; }
        foreach ($sections as $section) {
            if (!is_array($section) || array_is_list($section)
                || array_keys($section) !== ['kind','text_digest','locator_kind','locator_ref','status']) {
                return false;
            }
            if (!in_array($section['kind'], ['NAVIGATION','PRICING','PRODUCT','CAREERS','NEWS','METADATA','UNKNOWN'], true)
                || !self::validField('sha256', $section['text_digest'])
                || !in_array($section['locator_kind'], ['DERIVED','ORIGINAL','NONE'], true)
                || !self::validRef($section['locator_ref'])
                || !self::validField('state', $section['status'])) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) { throw new \DomainException('BF01_MISSING_REGISTRY_OR_EXAMPLES'); }
        try { $value = json_decode($contents, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \DomainException('BF01_INVALID_REGISTRY_JSON', 0, $e); }
        if (!is_array($value) || array_is_list($value)) { throw new \DomainException('BF01_REGISTRY_NOT_OBJECT'); }
        return $value;
    }

    private static function expect(bool $ok, string $reason): void
    {
        if (!$ok) { throw new \DomainException('BF01_' . $reason); }
    }
}
