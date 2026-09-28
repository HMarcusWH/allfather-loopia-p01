<?php

declare(strict_types=1);

/**
 * The qualification verdicts, exercised without spawning anything.
 *
 * The property that matters most is the negative one: a failed, skipped, filtered, missing or
 * duplicated lane — or a step that failed or never ran — can never assemble into a PASS receipt,
 * and a LOCAL receipt can never read as hosted CI.
 */

use Allfather\Loopia\Ops\ReleaseQualification as Q;
use Allfather\Loopia\Tests\Assert;

$sha = str_repeat('a', 40);
$tree = str_repeat('b', 40);

$laneReport = static function (string $server, array $overrides = []): array {
    return array_replace_recursive([
        'schema_version' => '1.0',
        'projection_id' => 'LOOPIA_SME_MVP',
        'php_version' => '8.2.34',
        'database_server_version' => $server,
        'complete' => true,
        'strict' => true,
        'layer_filter' => null,
        'file_filter' => null,
        'layers' => [],
        'total' => ['pass' => 6000, 'fail' => 0, 'skip' => 0, 'duration_ms' => 1],
        'skips_are_failures' => true,
        'failure_count' => 0,
        'skip_count' => 0,
    ], $overrides);
};

$preflight = static function (array $overrides = []) use ($sha): array {
    return array_replace([
        'status' => 'READY_FOR_INERT_STAGING_COPY',
        'problems' => [],
        'release_ref' => $sha,
        'source_fingerprint' => str_repeat('c', 64),
        'live_read' => false,
        'scheduler_handlers' => false,
        'external_action' => false,
        'customer_visible_output' => false,
        'production_launch_authorized' => false,
        'authorizes_deployment' => false,
        'copies_anything' => false,
    ], $overrides);
};

// ---------------------------------------------------------------- the declared procedure

Assert::equalsList(
    ['foundation', 'mariadb-10.6', 'mariadb-10.11', 'release-preparation', 'release-qualification'],
    Q::requiredJobs(Q::EXECUTION_LOCAL),
    'a local run accounts for the MariaDB job once per lane',
);
Assert::equalsList(
    ['foundation', 'mariadb', 'release-preparation', 'release-qualification'],
    Q::requiredJobs(Q::EXECUTION_GITHUB_ACTIONS),
    'a hosted run accounts for each workflow job',
);
Assert::same(Q::JOBS['mariadb'], Q::stepsForExpandedJob('mariadb-10.11'), 'a lane job runs the MariaDB steps');
Assert::throws(static fn () => Q::jobSteps('deploy'), \InvalidArgumentException::class, 'an undeclared job is refused, not treated as empty');
Assert::same('10.6', Q::laneOf('10.6.28-MariaDB'), 'the lane is read from the server version');
Assert::same('10.11', Q::laneOf('10.11.19-MariaDB-log'), 'including a two-digit minor');
Assert::same(null, Q::laneOf(null), 'no version is no lane');

// ---------------------------------------------------------------- one lane report

Assert::equalsList([], Q::laneReportProblems($laneReport('10.6.28-MariaDB'), '10.6'), 'a complete strict clean report on its lane passes');
foreach ([
    'a failure' => [['total' => ['fail' => 1]], 'failures: 1'],
    'a skip' => [['total' => ['skip' => 2]], 'skips: 2'],
    'a filtered run' => [['complete' => false], 'not a complete run — a layer or file filter narrowed the suite'],
    'a non-strict run' => [['strict' => false], 'not a --strict run, so a skip would not have failed it'],
    'no passes' => [['total' => ['pass' => 0]], 'no assertions passed'],
    'another PHP line' => [['php_version' => '8.4.19'], "ran on PHP '8.4.19', not the 8.2 line the workflow sets up"],
    'another projection' => [['projection_id' => 'LOOPIA_P01'], 'the report names another projection'],
] as $case => [$override, $expected]) {
    Assert::true(
        in_array($expected, Q::laneReportProblems($laneReport('10.6.28-MariaDB', $override), '10.6'), true),
        "{$case} is not qualified: {$expected}",
    );
}
Assert::true(
    in_array('the run never opened a database connection', Q::laneReportProblems($laneReport('10.6.28-MariaDB', ['database_server_version' => null])), true),
    'a run that never reached a database is not a lane',
);
Assert::true(
    in_array('ran against MariaDB 10.11.14-MariaDB, not the 10.6 lane', Q::laneReportProblems($laneReport('10.11.14-MariaDB'), '10.6'), true),
    'a report is held to the lane it was run for',
);
Assert::equalsList(['the report is not a JSON object'], Q::laneReportProblems('PASS'), 'a report that is not an object is refused');

// ---------------------------------------------------------------- the set of lanes

$both = ['a/qualification-10.6.json' => $laneReport('10.6.28-MariaDB'), 'b/qualification-10.11.json' => $laneReport('10.11.14-MariaDB')];
Assert::equalsList([], Q::laneSetProblems($both), 'one clean report per required lane passes');

$onlyOne = Q::laneSetProblems(['b/qualification-10.11.json' => $laneReport('10.11.14-MariaDB')]);
Assert::true(in_array('no report covers the MariaDB 10.6 lane', $onlyOne, true), 'a missing 10.6 lane fails the set, however clean 10.11 is');
Assert::true(in_array('expected one qualification report per MariaDB lane (2), found 1', $onlyOne, true), 'and the count says so');

$twice = Q::laneSetProblems(['a/qualification-10.11.json' => $laneReport('10.11.14-MariaDB'), 'b/qualification-10.11.json' => $laneReport('10.11.14-MariaDB')]);
Assert::true(in_array('no report covers the MariaDB 10.6 lane', $twice, true), 'two 10.11 reports are not a 10.6 proof');
Assert::true(in_array('b/qualification-10.11.json: a second report for the 10.11 lane (first: a/qualification-10.11.json)', $twice, true), 'and the duplicate is named');

$skipped = Q::laneSetProblems(['a/qualification-10.6.json' => $laneReport('10.6.28-MariaDB', ['total' => ['skip' => 1]]), 'b/qualification-10.11.json' => $laneReport('10.11.14-MariaDB')]);
Assert::true(in_array('a/qualification-10.6.json: skips: 1', $skipped, true), 'a skipped test in one lane fails the whole set');

Assert::true(
    in_array('expected one qualification report per MariaDB lane (2), found 0', Q::laneSetProblems([]), true),
    'no reports at all is a failure, never a vacuous pass',
);

// ---------------------------------------------------------------- the prepared-release preflight

Assert::equalsList([], Q::preflightProblems($preflight(), $sha), 'a ready, inert preflight for this commit passes');
Assert::true(in_array("status is 'NOT_READY'", Q::preflightProblems($preflight(['status' => 'NOT_READY']), $sha), true), 'a not-ready status fails');
Assert::true(in_array('live_read is true, expected false', Q::preflightProblems($preflight(['live_read' => true]), $sha), true), 'a live-read posture fails');
Assert::true(in_array('copies_anything is null, expected false', Q::preflightProblems($preflight(['copies_anything' => null]), $sha), true), 'an absent inertness key fails — false must be stated');
Assert::true(in_array('preflight reported a different release ref', Q::preflightProblems($preflight(['release_ref' => $tree]), $sha), true), 'another commit\'s preflight fails');
Assert::true(in_array('preflight problem: MASTER_KEY_ABSENT', Q::preflightProblems($preflight(['problems' => ['MASTER_KEY_ABSENT']]), $sha), true), 'every preflight problem is carried');

// ---------------------------------------------------------------- the prepared release on disk

$prepared = sys_get_temp_dir() . '/allfather-prepared-' . bin2hex(random_bytes(6));
$stage = $prepared . '/public_html/assets/operator-console';
mkdir($stage, 0700, true);
mkdir($prepared . '/frontend/console/node_modules/pkg', 0700, true);
file_put_contents($stage . '/console.ABC.js', '//');
file_put_contents($stage . '/console.ABC.css', '/**/');
file_put_contents($stage . '/manifest.json', json_encode(['entry' => ['script' => 'console.ABC.js', 'style' => 'console.ABC.css'], 'assets' => []]));
file_put_contents($prepared . '/.env.example', 'APP_ENV=');
// Dependency trees carry their own dotfiles; the release check skips node_modules as CI always did.
file_put_contents($prepared . '/frontend/console/node_modules/pkg/.env.defaults', 'X=1');
$verdict = ['status' => 'PREPARED_RELEASE', 'release_ref' => $sha, 'path' => $prepared];

$resolved = 0;
Assert::equalsList([], Q::preparedReleaseProblems($verdict, $sha, $prepared, $resolved), 'a clean export with a resolving manifest passes');
Assert::same(2, $resolved, 'and every manifest reference was resolved, not just counted');
Assert::true(
    in_array("release_ref '{$tree}' is not the qualified commit {$sha}", Q::preparedReleaseProblems(['release_ref' => $tree] + $verdict, $sha, $prepared), true),
    'a verdict for another commit fails',
);
file_put_contents($prepared . '/.env', 'DB_PASSWORD=x');
Assert::true(
    in_array('a configuration file entered the prepared release: .env', Q::preparedReleaseProblems($verdict, $sha, $prepared), true),
    'a configuration file in the release fails',
);
unlink($prepared . '/.env');
mkdir($prepared . '/.git');
Assert::true(in_array('.git entered the prepared release', Q::preparedReleaseProblems($verdict, $sha, $prepared), true), 'repository metadata in the release fails');
rmdir($prepared . '/.git');
file_put_contents($stage . '/manifest.json', json_encode(['entry' => ['script' => '../../../../ops/migrate.php', 'style' => 'console.MISSING.css'], 'assets' => []]));
$manifestProblems = Q::preparedReleaseProblems($verdict, $sha, $prepared);
Assert::true(in_array("manifest carries a non-path reference: '../../../../ops/migrate.php'", $manifestProblems, true), 'a manifest reference cannot climb out of the staged directory');
Assert::true(in_array('manifest references a file that is not staged: console.MISSING.css', $manifestProblems, true), 'a manifest reference must resolve to a staged file');

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        rmdir($path);

        return;
    }
    unlink($path);
};
$remove($prepared);
Assert::true(in_array('the prepared release directory does not exist', Q::preparedReleaseProblems($verdict, $sha, $prepared), true), 'no prepared tree is no prepared release');

// ---------------------------------------------------------------- receipts

$steps = static function (array $failing = [], array $notRun = []): array {
    $rows = [];
    foreach (Q::requiredJobs(Q::EXECUTION_LOCAL) as $job) {
        foreach (Q::stepsForExpandedJob($job) as $step) {
            $key = "{$job}/{$step}";
            if (isset($notRun[$key])) {
                $rows[] = ['job' => $job, 'step' => $step, 'status' => 'NOT_RUN', 'exit' => null, 'duration_ms' => null, 'note' => $notRun[$key]];
                continue;
            }
            $failed = in_array($key, $failing, true);
            $rows[] = ['job' => $job, 'step' => $step, 'status' => $failed ? 'FAIL' : 'PASS', 'exit' => $failed ? 1 : 0, 'duration_ms' => 10, 'note' => ''];
        }
    }

    return $rows;
};
$localInput = static function (array $overrides = []) use ($sha, $tree, $steps, $laneReport, $preflight): array {
    return array_replace([
        'execution' => Q::EXECUTION_LOCAL,
        'commit' => $sha,
        'tree' => $tree,
        'requested_ref' => 'HEAD',
        'materialized_from' => 'GIT_OBJECT_EXPORT',
        'invoking_checkout_dirty' => false,
        'file_sha256' => ['workflow' => str_repeat('1', 64), 'runner' => str_repeat('2', 64), 'verifier' => str_repeat('3', 64)],
        'toolchain' => ['php' => '8.2.34', 'node' => '22.16.0', 'node_required' => '22.16.0', 'npm' => '10.9.2', 'npm_required' => '10.9.2'],
        'steps' => $steps(),
        'lane_reports' => [
            'qualification-10.11.json' => ['sha256' => str_repeat('4', 64), 'report' => $laneReport('10.11.14-MariaDB')],
            'qualification-10.6.json' => ['sha256' => str_repeat('5', 64), 'report' => $laneReport('10.6.28-MariaDB')],
        ],
        'preflight' => ['sha256' => str_repeat('6', 64), 'report' => $preflight()],
        'github' => null,
    ], $overrides);
};

$pass = Q::buildReceipt($localInput());
Assert::same('PASS', $pass['result'], 'every step passed, both lanes are clean and the toolchain matches: PASS');
Assert::equalsList([], $pass['problems'], 'with no problems');
Assert::same(false, $pass['execution']['hosted_ci'], 'a local receipt says it is not hosted CI');
Assert::same(true, $pass['execution']['self_reported'], 'and that it is self-reported');
Assert::same(str_repeat('c', 64), $pass['preflight']['source_fingerprint'], 'the prepared source fingerprint is bound');
Assert::equalsList([], Q::receiptProblems($pass), 'a freshly built receipt is internally consistent');

$failedStep = Q::buildReceipt($localInput(['steps' => $steps(['release-preparation/verify-export'])]));
Assert::same('FAIL', $failedStep['result'], 'one failed step fails the receipt');
Assert::true(in_array("job release-preparation step 'verify-export': 'FAIL'", $failedStep['problems'], true), 'and names it');
Assert::same('FAIL', $failedStep['jobs'][3]['result'], 'and its job');

$noLane = Q::buildReceipt($localInput([
    'steps' => $steps([], array_fill_keys(array_map(static fn (string $s): string => "mariadb-10.6/{$s}", Q::JOBS['mariadb']), 'no --env-10.6 database was supplied')),
    'lane_reports' => ['qualification-10.11.json' => ['sha256' => str_repeat('4', 64), 'report' => $laneReport('10.11.14-MariaDB')]],
]));
Assert::same('FAIL', $noLane['result'], 'a lane that never ran fails the receipt, however clean the other lane is');
Assert::true(in_array("job mariadb-10.6 step 'db-version': 'NOT_RUN' (no --env-10.6 database was supplied)", $noLane['problems'], true), 'and says why it never ran');
Assert::true(in_array('no report covers the MariaDB 10.6 lane', $noLane['problems'], true), 'and that its report is missing');

$omitted = Q::buildReceipt($localInput(['steps' => array_values(array_filter($steps(), static fn (array $r): bool => $r['job'] !== 'release-qualification'))]));
Assert::true(in_array('job release-qualification is absent — an omitted job is not a passed one', $omitted['problems'], true), 'an omitted job is a failure');

$shortened = Q::buildReceipt($localInput(['steps' => array_values(array_filter($steps(), static fn (array $r): bool => $r['step'] !== 'rsync-rehearsal'))]));
Assert::same('FAIL', $shortened['result'], 'a job that skipped a declared step fails even though every step it did run passed');

$wrongPhp = Q::buildReceipt($localInput(['toolchain' => ['php' => '8.4.19'] + $localInput()['toolchain']]));
Assert::true(in_array("toolchain: PHP '8.4.19' is not the 8.2 line the workflow sets up", $wrongPhp['problems'], true), 'a local run on another PHP line is not a reproduction of the workflow');
$wrongNode = Q::buildReceipt($localInput(['toolchain' => ['node' => '22.22.2'] + $localInput()['toolchain']]));
Assert::true(in_array("toolchain: node '22.22.2' is not the repository pin 22.16.0", $wrongNode['problems'], true), 'nor on another Node');

$noPreflight = Q::buildReceipt($localInput(['preflight' => null]));
Assert::true(in_array('no prepared-release preflight report', $noPreflight['problems'], true), 'a receipt without the prepared-release preflight fails');

// Tampering: the stored result and the evidence must agree.
$forged = $failedStep;
$forged['result'] = 'PASS';
$forged['problems'] = [];
$forgedProblems = Q::receiptProblems($forged);
Assert::true(in_array("the receipt states result 'PASS' but its evidence supports FAIL", $forgedProblems, true), 'a FAIL receipt edited to PASS is caught');

$masquerade = $pass;
$masquerade['execution']['hosted_ci'] = true;
Assert::true(in_array('a LOCAL receipt claims hosted CI', Q::receiptProblems($masquerade), true), 'a LOCAL receipt edited to claim hosted CI is caught');

$renamed = $pass;
$renamed['execution']['kind'] = Q::EXECUTION_GITHUB_ACTIONS;
Assert::true(Q::receiptProblems($renamed) !== [], 'a LOCAL receipt relabelled GITHUB_ACTIONS is caught');

$editedLane = $pass;
$editedLane['lanes'][0]['content']['total']['skip'] = 4;
Assert::true(in_array('qualification-10.11.json: skips: 4', Q::receiptProblems($editedLane), true), 'lane content is re-judged, not trusted from the stored result');

// Hosted receipts report jobs; GitHub's run record holds the steps.
$hostedSteps = [];
foreach (Q::requiredJobs(Q::EXECUTION_GITHUB_ACTIONS) as $job) {
    $hostedSteps[] = ['job' => $job, 'step' => '*', 'status' => 'PASS', 'exit' => null, 'duration_ms' => null, 'note' => 'github result: success'];
}
$hosted = Q::buildReceipt($localInput([
    'execution' => Q::EXECUTION_GITHUB_ACTIONS,
    'materialized_from' => 'CI_CHECKOUT',
    'toolchain' => [],
    'steps' => $hostedSteps,
    'github' => ['repository' => 'o/r', 'run_id' => '123', 'run_attempt' => '1', 'url' => 'https://github.com/o/r/actions/runs/123'],
]));
Assert::same('PASS', $hosted['result'], 'a hosted run whose jobs all succeeded, with clean lanes, passes');
Assert::same(true, $hosted['execution']['hosted_ci'], 'and says it is hosted');
Assert::same('123', $hosted['execution']['github_run']['run_id'], 'and names the run to check it against');

$hostedFailed = $hostedSteps;
$hostedFailed[1]['status'] = 'FAIL';
Assert::same('FAIL', Q::buildReceipt($localInput([
    'execution' => Q::EXECUTION_GITHUB_ACTIONS,
    'materialized_from' => 'CI_CHECKOUT',
    'steps' => $hostedFailed,
    'github' => ['repository' => 'o/r', 'run_id' => '123', 'run_attempt' => '1', 'url' => 'x'],
]))['result'], 'a hosted run with a failed job fails');
Assert::true(in_array('a GITHUB_ACTIONS receipt names no run id', Q::buildReceipt($localInput([
    'execution' => Q::EXECUTION_GITHUB_ACTIONS,
    'materialized_from' => 'CI_CHECKOUT',
    'steps' => $hostedSteps,
    'github' => null,
]))['problems'], true), 'a hosted receipt must name its run');
