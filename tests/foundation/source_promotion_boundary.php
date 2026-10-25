<?php

declare(strict_types=1);

/**
 * CI-FIX-05: source qualification is the only required PR/main promotion check.
 * This does not assert that an owner installed a GitHub rule or enabled Railway Wait for CI.
 * Runs without network, database or deployment in both the checkout and the rsync-copy foundation suite.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
$excludes = (string) file_get_contents($root . '/ops/deploy/staging-rsync-excludes.txt');

/**
 * Narrow invariant checker, NOT another qualification evaluator or general-purpose YAML parser.
 * The existing qualification_runner_boundary test owns the full job/step drift check.
 *
 * @return list<string>
 */
$promotionProblems = static function (string $yaml): array {
    $problems = [];
    if (preg_match('/^name: CI\s*$/m', $yaml) !== 1) {
        $problems[] = 'CI workflow name changed';
    }
    if (preg_match('/^on:\r?\n  pull_request:\r?\n  push:\r?\n    branches:\r?\n      - main(?:\r?\n|$)/m', $yaml) !== 1) {
        $problems[] = 'pull_request and exact main push triggers required';
    }
    if (str_contains($yaml, 'workflow_dispatch:')) {
        $problems[] = 'manual dispatch must not replace ordinary code CI';
    }
    if (preg_match('/^permissions:\r?\n  contents: read(?:\r?\n|$)/m', $yaml) !== 1) {
        $problems[] = 'read-only workflow permission changed';
    }

    $headers = [];
    preg_match_all('/^  code-qualification:\s*$/m', $yaml, $headers, PREG_OFFSET_CAPTURE);
    if (count($headers[0] ?? []) !== 1) {
        $problems[] = 'exactly one code-qualification job required';
        return $problems;
    }
    $start = (int) $headers[0][0][1];
    $after = substr($yaml, $start);
    $next = [];
    preg_match('/^  [a-z][a-z0-9-]*:\s*$/m', substr($after, strlen($headers[0][0][0])), $next, PREG_OFFSET_CAPTURE);
    $job = $next === [] ? $after : substr($after, 0, strlen($headers[0][0][0]) + (int) $next[0][1]);

    if (preg_match('/^    name: code qualification\s*$/m', $job) !== 1) {
        $problems[] = 'stable required status name changed';
    }
    if (preg_match('/^    needs: \[foundation, mariadb, package-integrity\]\s*$/m', $job) !== 1) {
        $problems[] = 'all foundation, real MariaDB and package dependencies required';
    }
    if (preg_match('/^    if: always\(\)\s*$/m', $job) !== 1) {
        $problems[] = 'aggregate job must evaluate cancelled, failed and skipped dependencies';
    }
    if (!str_contains($job, 'for result in "$foundation" "$mariadb" "$package_integrity"; do')
        || !str_contains($job, 'if [ "$result" != "success" ]; then')
        || !str_contains($job, 'if [ "$blocked" -ne 0 ]; then')
        || !str_contains($job, 'exit 1')) {
        $problems[] = 'only success can pass; absent/skipped/failed/cancelled dependencies hard fail';
    }
    if (!str_contains($job, 'run: bash ops/ci/qualify-release.sh step verify-lanes')) {
        $problems[] = 'strict lane-artifact verification missing';
    }
    if (!str_contains($job, 'run: bash ops/ci/qualify-release.sh code-ci-receipt')
        || !str_contains($job, 'name: code-qualification-receipt')) {
        $problems[] = 'code-only receipt or artifact missing';
    }
    if (str_contains($yaml, 'run: bash ops/ci/qualify-release.sh step preflight')
        || str_contains($yaml, 'run: bash ops/ci/qualify-release.sh step verify-preflight')
        || str_contains($yaml, 'run: bash ops/ci/qualify-release.sh step deploy')
        || preg_match('/^\s*run:.*\b(?:railway|ssh|scp|curl|wget)\b/m', $yaml) === 1) {
        $problems[] = 'qualification workflow attempts deployment or external action';
    }
    return $problems;
};

Assert::same([], $promotionProblems($workflow),
    'ordinary CI exposes exactly the qualified source gate, not a deployment-authorizing check');
Assert::contains('+ /.github/workflows/ci.yml', $excludes,
    'only the source CI workflow is present in the deployment-shaped foundation copy');
Assert::contains('/.github/***', $excludes,
    'all other GitHub workflow files are excluded from deployment ownership');

// Negative mutations must change the verdict. These never run the modified workflow.
$mutants = [
    'no main-push trigger' => str_replace('      - main', '      - development', $workflow),
    'no PR trigger' => str_replace('  pull_request:', '  workflow_call:', $workflow),
    'unsafe manual trigger' => str_replace("  pull_request:\n", "  pull_request:\n  workflow_dispatch:\n", $workflow),
    'wrong required status' => str_replace('    name: code qualification', '    name: release qualification', $workflow),
    'required MariaDB dependency removed' => str_replace(
        '    needs: [foundation, mariadb, package-integrity]',
        '    needs: [foundation, package-integrity]', $workflow,
    ),
    'skipped upstream job may evade aggregate' => str_replace('    if: always()', '    if: success()', $workflow),
    'non-success treated as acceptable' => str_replace(
        'if [ "$result" != "success" ]; then',
        'if [ "$result" = "failure" ]; then', $workflow,
    ),
    'missing strict artifact verification' => str_replace(
        'run: bash ops/ci/qualify-release.sh step verify-lanes',
        'run: echo not-checking-lanes', $workflow,
    ),
    'missing code receipt' => str_replace(
        'run: bash ops/ci/qualify-release.sh code-ci-receipt',
        'run: echo not-writing-receipt', $workflow,
    ),
    'automatic preflight added' => $workflow . "\n      - name: Bad deployment preflight\n        run: bash ops/ci/qualify-release.sh step preflight\n",
    'insecure workflow token' => str_replace('permissions:' . "\n" . '  contents: read', 'permissions:' . "\n" . '  contents: write', $workflow),
];
foreach ($mutants as $reason => $variant) {
    Assert::false($variant === $workflow, "mutation for {$reason} actually changed the fixture");
    Assert::true($promotionProblems($variant) !== [], "promotion check rejects {$reason}");
}
