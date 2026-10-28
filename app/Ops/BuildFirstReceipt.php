<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\Digest;

/**
 * Deterministic read-only BF-01 source/package receipt.
 *
 * The supplied Git SHA is a caller declaration (never silently reclassified
 * as a GitHub/host attestation). The measured file digest is computed locally
 * over an explicit allowlist, with no .env, credential, raw evidence or DB reads.
 */
final class BuildFirstReceipt
{
    private const CHECKED_PATHS = [
        'app/Ops/BuildFirstRegistry.php',
        'app/Ops/BuildFirstContracts.php',
        'app/Ops/BuildFirstReceipt.php',
        'docs/longitudinal/BUILD_FIRST_DECISION_MATRIX.json',
        'docs/longitudinal/build-first/CAPABILITY_MATRIX.json',
        'docs/longitudinal/build-first/SCHEMA_FAMILY_REGISTRY.json',
        'docs/longitudinal/build-first/CONTRACT_REGISTRY.json',
        'tests/fixtures/longitudinal/contracts/candidate_examples.v1.json',
        'tests/support/LongitudinalBuildHarness.php',
        'ops/ci/check-build-completeness.php',
    ];

    /** @return array<string,mixed> */
    public static function compose(string $root, string $package, ?string $declaredSha = null): array
    {
        $root = rtrim($root, '/') . '/';
        $source = BuildFirstRegistry::loadAndValidate($root);
        $contracts = BuildFirstContracts::loadAndValidate($root);
        $packages = array_column($source['capabilities']['build_packages'], null, 'id');
        if (!isset($packages[$package])) {
            throw new \DomainException('BF01_UNKNOWN_PACKAGE');
        }
        if ($declaredSha !== null && preg_match('/^[a-f0-9]{40}$/D', $declaredSha) !== 1) {
            throw new \DomainException('BF01_INVALID_DECLARED_SHA');
        }
        $files = [];
        foreach (self::CHECKED_PATHS as $path) {
            $full = $root . $path;
            if (!is_file($full) || is_link($full)) {
                throw new \DomainException('BF01_MISSING_OR_SYMLINKED_CONTRACT_SOURCE');
            }
            $digest = hash_file('sha256', $full);
            if (!is_string($digest)) {
                throw new \DomainException('BF01_UNREADABLE_CONTRACT_SOURCE');
            }
            $files[$path] = $digest;
        }
        ksort($files, SORT_STRING);
        $p = $packages[$package];
        return [
            'schema_version' => 'allfather.build_first.package_receipt.v1',
            'kind' => 'SOURCE_ONLY_BUILD_READBACK',
            'package_id' => $package,
            'wave' => $p['wave'],
            'implementation_state' => $p['build_status'],
            'dependencies' => $p['depends_on'],
            'unresolved_owner_gates' => $p['owner_gates'],
            'source' => [
                'declared_git_sha' => $declaredSha ?? 'UNDECLARED',
                'git_identity_verified' => false,
                'git_identity_evidence' => 'CALLER_DECLARATION_NOT_INDEPENDENT_GIT_ATTESTATION',
                'measured_allowlist_fingerprint' => Digest::canonicalDigest($files),
                'measured_files' => $files,
                'source_plan_sha256' => $contracts['source_plan_sha256'],
                'adopted_canonical_pin' => $source['decisions']['source_snapshot']['adopted_upstream_pin'],
            ],
            'interface_contracts' => [
                'indexed' => count($contracts['index']),
                'typed_primary' => count($contracts['primary_contracts']),
                'synthetic_positive_examples' => 64,
                'synthetic_refusal_examples' => 64,
                'producer_consumer_wiring' => 'UNVERIFIED_UNTIL_RT_02',
            ],
            'qualification' => [
                'focused_tests' => 'NOT_EXECUTED_BY_RECEIPT_COMMAND',
                'github_ci' => 'NOT_OBSERVED_BY_RECEIPT_COMMAND',
                'full_system' => 'NOT_RUN_DEFERRED_V01_V11',
                'railway_staging' => 'NOT_ASSESSED',
                'recovery' => 'NOT_ASSESSED',
                'model_quality' => 'NOT_ASSESSED',
            ],
            'authority' => [
                'new_execution_grants' => 0,
                'source_use_grants' => 0,
                'migrations_installed' => 0,
                'external_effect_authority' => false,
                'customer_output_authority' => false,
                'production_authority' => false,
                'existing_runtime_admissions' => 'NOT_QUERIED',
            ],
        ];
    }
}
