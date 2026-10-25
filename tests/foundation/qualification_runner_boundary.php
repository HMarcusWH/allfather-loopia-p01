<?php

declare(strict_types=1);

/**
 * The workflow, the runner and the declared step list describe ONE procedure.
 *
 * Hosted runners have been unavailable since PR13 (account billing), and every release since has
 * been qualified by reproducing `.github/workflows/ci.yml` somewhere else. A reproduction is only
 * worth something if it is COMPLETE, and completeness used to be a person's diligence: the steps
 * lived in YAML, the checks lived in inline Python, and nothing noticed a step one route ran and the
 * other did not.
 *
 * Now every qualifying `run:` in the workflow is `bash ops/ci/qualify-release.sh step <name>`, the
 * local route runs the same named steps, and `ReleaseQualification::JOBS` is the single list of
 * them. This file is what keeps those three from drifting apart. It reads text, spawns nothing, and
 * runs on the deployed host too — the workflow file is deliberately deployed for exactly this kind
 * of check.
 */

use Allfather\Loopia\Ops\ReleaseQualification;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$workflow = (string) file_get_contents($root . '/.github/workflows/ci.yml');
$runner = (string) file_get_contents($root . '/ops/ci/qualify-release.sh');
$verifier = (string) file_get_contents($root . '/ops/ci/verify-qualification.php');

// ---------------------------------------------------------------- a deliberately small YAML reader

// Enough of the workflow's own layout to name each job's steps and their `run:` bodies. The shape
// is asserted as it is read: a step this reader cannot place fails the test rather than vanishing.
/** @var array<string, list<array{name: ?string, run: ?string, uses: ?string, id: ?string, if: ?string}>> $jobs */
$jobs = [];
$inJobs = false;
$job = null;
$block = null;
foreach (explode("\n", $workflow) as $line) {
    if ($line === 'jobs:') {
        $inJobs = true;
        continue;
    }
    if (!$inJobs) {
        continue;
    }
    if ($block !== null) {
        if ($line === '' || str_starts_with($line, str_repeat(' ', 10))) {
            $jobs[$job][count($jobs[$job]) - 1]['run'] .= substr($line, 10) . "\n";
            continue;
        }
        $block = null;
    }
    if (preg_match('/^  ([a-z][a-z0-9-]*):\s*$/', $line, $m) === 1) {
        $job = $m[1];
        $jobs[$job] = [];
        continue;
    }
    if ($job === null) {
        continue;
    }
    $key = null;
    if (preg_match('/^      - ([a-z-]+): ?(.*)$/', $line, $m) === 1) {
        $jobs[$job][] = ['name' => null, 'run' => null, 'uses' => null, 'id' => null, 'if' => null];
        [$key, $value] = [$m[1], $m[2]];
    } elseif (preg_match('/^        ([a-z-]+): ?(.*)$/', $line, $m) === 1 && $jobs[$job] !== []) {
        [$key, $value] = [$m[1], $m[2]];
    }
    if ($key !== null && in_array($key, ['name', 'run', 'uses', 'id', 'if'], true)) {
        if ($key === 'run' && $value === '|') {
            $block = true;
            $value = '';
        }
        $jobs[$job][count($jobs[$job]) - 1][$key] = $value;
    }
}

Assert::equalsList(
    array_keys(ReleaseQualification::CODE_JOBS),
    array_keys($jobs),
    'the workflow has exactly the declared qualification jobs, in the declared order',
);

// ---------------------------------------------------------------- every qualifying run IS a runner step

// Provisioning that can only be hosted: writing the service container's connection file, and
// reading GitHub's own job results. Named exactly, so a new inline check has nowhere to hide.
$hostedOnly = [
    'mariadb' => ['Write the test environment'],
    'code-qualification' => ['Every code qualification job must have succeeded'],
];

foreach (ReleaseQualification::CODE_JOBS as $name => $expectedSteps) {
    $invoked = [];
    $inline = [];
    $receipts = 0;
    foreach ($jobs[$name] ?? [] as $step) {
        if ($step['run'] === null) {
            continue;
        }
        $run = trim($step['run']);
        if (preg_match('/^bash ops\/ci\/qualify-release\.sh step ([a-z-]+)$/', $run, $m) === 1) {
            $invoked[] = $m[1];
            continue;
        }
        if ($run === 'bash ops/ci/qualify-release.sh code-ci-receipt') {
            $receipts++;
            Assert::same('always()', $step['if'], 'the hosted receipt is written even when qualification failed');
            Assert::same(ReleaseQualification::CODE_JOBS['code-qualification'], $invoked, 'the hosted receipt is written after the lane reports are judged');
            continue;
        }
        $inline[] = (string) $step['name'];
        foreach (['php ', 'npm ', 'tests/run.php', 'rsync', 'prepare-release', 'preflight', 'migrate'] as $qualifying) {
            Assert::false(
                str_contains($run, $qualifying),
                "{$name} / {$step['name']} is hosted-only provisioning and runs no qualifying command ({$qualifying})",
            );
        }
    }

    Assert::equalsList(
        $expectedSteps,
        $invoked,
        "the {$name} job runs exactly ReleaseQualification::JOBS['{$name}'], in order, through the shared runner",
    );
    Assert::equalsList(
        $hostedOnly[$name] ?? [],
        $inline,
        "the {$name} job carries no inline run beyond its named hosted-only provisioning",
    );
    Assert::same(
        $name === 'code-qualification' ? 1 : 0,
        $receipts,
        "{$name}: the hosted receipt is written once, by the final gate",
    );
}

// ---------------------------------------------------------------- the runner implements exactly the list

preg_match('/\ncmd_step\(\) \{\n(.*?)\n\}\n/s', $runner, $dispatch);
Assert::same(2, count($dispatch), 'the runner has one step dispatcher');
preg_match_all('/^    ([a-z-]+)\) (step_[a-z_]+) ;;$/m', $dispatch[1] ?? '', $cases, PREG_SET_ORDER);
$declared = array_merge(...array_values(ReleaseQualification::JOBS));
$implemented = array_map(static fn (array $case): string => $case[1], $cases);
sort($declared, SORT_STRING);
sort($implemented, SORT_STRING);
Assert::equalsList($declared, $implemented, 'the runner dispatches every declared step and no undeclared one');
foreach ($cases as [, $step, $function]) {
    Assert::same(str_replace('-', '_', 'step_' . $step), $function, "step {$step} dispatches to its own function");
    Assert::true(
        preg_match('/^' . preg_quote($function, '/') . '\(\) \{$/m', $runner) === 1,
        "{$function} is defined in the runner",
    );
}
Assert::contains('php "$verifier" job-steps "$base"', $runner, 'the local route reads each job\'s steps from the declared list rather than keeping its own copy');
Assert::contains('php "$verifier" local-jobs', $runner, 'and the local jobs from the same place');

// ---------------------------------------------------------------- toolchain and lanes match the workflow

preg_match_all("/^\s+php-version: '([^']+)'$/m", $workflow, $phpVersions);
Assert::true(count($phpVersions[1]) === count(ReleaseQualification::JOBS), 'every qualification job sets up PHP');
Assert::equalsList(
    array_fill(0, count($phpVersions[1]), ReleaseQualification::PHP_LINE),
    $phpVersions[1],
    'every job sets up the PHP line a receipt is held to',
);
preg_match("/^\s+mariadb: \[([^\]]*)\]$/m", $workflow, $matrix);
Assert::equalsList(
    ReleaseQualification::MARIADB_LANES,
    array_map(static fn (string $lane): string => trim($lane, " '"), explode(',', $matrix[1] ?? '')),
    'the workflow matrix is exactly the MariaDB lanes a receipt must cover',
);
Assert::contains("QUALIFY_LANE: \${{ matrix.mariadb }}", $workflow, 'each hosted lane names its lane to the runner');

// ---------------------------------------------------------------- what moves between jobs is what is read

Assert::contains('--report-json="$(report_dir)/qualification-${lane}.json"', $runner, 'a lane writes its report under the name the workflow uploads');
Assert::contains('qualification-${{ matrix.mariadb }}.json', $workflow, 'and the workflow uploads that name');
Assert::contains("'/^qualification-\\d+\\.\\d+\\.json$/'", $verifier, 'and the verifier reads exactly lane-report names, never the receipt');
Assert::contains('dir="${RUNNER_TEMP}/qualification"', $runner, 'a hosted step keeps its files in the runner temp space');
Assert::contains('${{ runner.temp }}/qualification/release-qualification-receipt.json', $workflow, 'where the workflow collects the hosted receipt');
Assert::contains('${{ runner.temp }}/qualification/prepare-release.verdict.json', $workflow, 'and the prepared-package verdict the code receipt binds');

// ---------------------------------------------------------------- the local route runs the commit, not the checkout

Assert::contains('git -C "$root" archive "$sha" | tar -x -C "$out/source"', $runner, 'the local route exports the exact commit');
Assert::contains('bash "$out/source/ops/ci/qualify-release.sh" local-run', $runner, 'and hands over to the COMMITTED runner, so a dirty checkout cannot change the commands');
Assert::contains('git archive "$sha" | tar -x -C "$src"', $runner, 'each local job starts from its own fresh export, as each hosted job starts from a fresh checkout');
Assert::contains('--execution=local', $runner, 'a local run can only write a LOCAL receipt');
Assert::contains('code-local) cmd_code_local', $runner, 'separate exact Git-object code-local route exists');
Assert::contains('code-ci-receipt) cmd_ci_receipt code', $runner, 'hosted code receipts use their own profile');
Assert::contains('preflight) step_preflight', $runner, 'legacy inert preflight remains callable');
Assert::contains('verify-preflight) step_verify_preflight', $runner, 'legacy readiness verification remains callable');
Assert::false(in_array('preflight', ReleaseQualification::CODE_JOBS['package-integrity'], true),
    'code package profile never authorizes deployment');
Assert::equalsList(['preflight','verify-preflight'], array_slice(ReleaseQualification::JOBS['release-preparation'],-2),
    'legacy release profile still requires inert preflight and verification');
Assert::contains('workflow_dispatch:', (string)file_get_contents($root.'/.github/workflows/inert-staging-preflight.yml'),
    'optional manual release gate exists');
Assert::false(str_contains($workflow,'step preflight'), 'normal CI must not invoke deployment preflight');
Assert::contains('[ "${GITHUB_ACTIONS:-}" = "true" ] || die', $runner, 'and the hosted receipt refuses to run anywhere else');

// ---------------------------------------------------------------- no route writes a governed file

// The PHP tripwire in no_external_action_surface.php cannot see a shell redirect, so the runner is
// held to the same rule here: a line that names a governed file may read it, never write it.
foreach (['upstream.lock.json', 'deployment-evidence.json'] as $governed) {
    foreach (explode("\n", $runner) as $number => $line) {
        if (!str_contains($line, $governed)) {
            continue;
        }
        Assert::false(
            str_contains($line, '>') || str_contains($line, 'tee ') || str_contains($line, 'cp ') || str_contains($line, 'mv '),
            sprintf('qualify-release.sh:%d names %s and does not write it', $number + 1, $governed),
        );
    }
}
