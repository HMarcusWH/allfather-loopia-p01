<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$prepare = (string) file_get_contents($root . '/ops/deploy/prepare-release.sh');
$excludes = (string) file_get_contents($root . '/ops/deploy/staging-rsync-excludes.txt');
$workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
$betaRunbook = (string) file_get_contents($root . '/docs/BETA_RUNBOOK.md');
$deploymentRunbook = (string) file_get_contents($root . '/docs/DEPLOYMENT_RUNBOOK.md');

Assert::contains('git archive "$release"', $prepare, 'release preparation exports the exact Git object, not the developer working tree');
Assert::contains('npm ci', $prepare, 'the prepared tree installs the pinned frontend dependency graph');
Assert::contains('npm test', $prepare, 'the prepared tree runs frontend tests before staging');
Assert::contains('npm run build', $prepare, 'the prepared tree builds the production console');
Assert::contains('npm run stage', $prepare, 'the prepared tree stages the reviewed manifest-last console payload');
Assert::contains('actual_node="${actual_node#v}"', $prepare, 'node --version is normalized to the bare .node-version value before comparison');
Assert::contains("require('./package.json').packageManager", $prepare, 'release preparation reads the package-manager pin from package.json rather than assuming the runner npm');
Assert::contains('expected_npm="${package_manager#npm@}"', $prepare, 'only the npm@<version> package-manager form is admitted');
Assert::contains('actual_npm="$(npm --version)"', $prepare, 'the installed npm version is compared before dependency installation');
Assert::false(str_contains($prepare, 'rsync '), 'release preparation cannot deploy anything');
Assert::false(str_contains($prepare, 'ssh '), 'release preparation cannot reach the host');

Assert::contains('/storage/***', $excludes, 'the destructive root rsync never manages runtime storage');
Assert::contains('/public_html/assets/operator-console/***', $excludes, 'the root rsync never deletes manifest-last console assets');
Assert::contains('/.env', $excludes, 'the host runtime .env is protected from the destructive root sync');
Assert::contains('/.env.*', $excludes, 'all host qualification/future .env.* files remain protected too');

// The tracked template is release-owned, but the broad /.env.* rule would also match it. The root
// sync therefore has to admit exactly /.env.example BEFORE the exclusion file is read. First-match
// filter semantics are load-bearing here: reversing these options recreates the real-host incident.
foreach ([
    '.github/workflows/ci.yml' => $workflow,
    'docs/BETA_RUNBOOK.md' => $betaRunbook,
    'docs/DEPLOYMENT_RUNBOOK.md' => $deploymentRunbook,
] as $surface => $source) {
    $include = strpos($source, "--include='/.env.example'");
    $excludeFrom = strpos($source, '--exclude-from=');
    Assert::true(
        $include !== false && $excludeFrom !== false && $include < $excludeFrom,
        "{$surface} admits /.env.example before the broad host-secret exclusion file",
    );
}
foreach ([
    'docs/BETA_RUNBOOK.md' => $betaRunbook,
    'docs/DEPLOYMENT_RUNBOOK.md' => $deploymentRunbook,
] as $surface => $source) {
    Assert::contains(
        'rsync -nric',
        $source,
        "{$surface} mechanically verifies the deployed .env.example by checksum after the root sync",
    );
    Assert::contains(
        'ENV_TEMPLATE_DRIFT',
        $source,
        "{$surface} treats any itemized .env.example difference as a hard stop",
    );
}
Assert::contains('/frontend/console/node_modules/***', $excludes, 'private frontend dependencies are never deployed');
Assert::contains('/frontend/console/dist/***', $excludes, 'private frontend build output is never deployed');
