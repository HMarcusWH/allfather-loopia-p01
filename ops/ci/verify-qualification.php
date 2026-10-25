<?php

declare(strict_types=1);

/**
 * The checks and the receipt behind `ops/ci/qualify-release.sh`.
 *
 * Usage:
 *   php ops/ci/verify-qualification.php job-steps <job>
 *   php ops/ci/verify-qualification.php local-jobs
 *   php ops/ci/verify-qualification.php db-version --env=<file> --lane=<10.6|10.11>
 *   php ops/ci/verify-qualification.php lanes --dir=<reports-dir>
 *   php ops/ci/verify-qualification.php prepared-release --verdict=<file> --sha=<sha> --dir=<prepared>
 *   php ops/ci/verify-qualification.php preflight --report=<file> --sha=<sha>
 *   php ops/ci/verify-qualification.php receipt --execution=<local|github-actions> --steps=<tsv> ...
 *   php ops/ci/verify-qualification.php verify-receipt --receipt=<file> [--lanes-dir=<dir>] [--preflight=<file>]
 *
 * These were inline Python inside `.github/workflows/ci.yml`, which meant a local reproduction had
 * to re-type them and nothing checked that it had. They are now PHP — the one runtime every lane
 * already has — and the logic lives in `Allfather\Loopia\Ops\ReleaseQualification`, where the unit
 * layer tests it without spawning anything.
 *
 * READ-ONLY. Every subcommand reads files and prints. `receipt` prints JSON to STDOUT and the shell
 * decides where it goes, so this command never holds a writable path — and cannot be pointed at a
 * governed file.
 *
 * Exit status: 0 when the thing checked is sound, 1 when it is not, 2 on a usage error. `receipt`
 * prints its JSON either way and exits 1 for a FAIL receipt, because the run whose receipt matters
 * most is the one that failed.
 */

// CLI ONLY, refused BEFORE anything is loaded. `ops/` sits outside the served tree; this guard is
// the second layer, for the deployment that mapped the wrong directory.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\ReleaseQualification as Q;
use Allfather\Loopia\Ops\CodeQualification as CodeQ;
use Allfather\Loopia\Support\Env;

$root = dirname(__DIR__, 2);

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'verify-qualification: ' . $message . PHP_EOL);
    exit($code);
};

/** @return array<string, string> */
$flags = static function (array $known) use ($argv, $fail): array {
    return BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)], $known, $fail);
};

$required = static function (array $args, string $name) use ($fail): string {
    $value = $args[$name] ?? '';
    if ($value === '') {
        $fail("--{$name} is required");
    }

    return $value;
};

$readJson = static function (string $path) use ($fail): mixed {
    if (!is_file($path)) {
        $fail("no such file: {$path}", 1);
    }
    try {
        return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (\JsonException $error) {
        $fail("{$path} is not valid JSON: " . $error->getMessage(), 1);
    }
};

$verdict = static function (array $problems, string $what, string $ok): never {
    if ($problems === []) {
        fwrite(STDOUT, "  {$ok}" . PHP_EOL);
        exit(0);
    }
    foreach ($problems as $problem) {
        fwrite(STDERR, "  {$what}: {$problem}" . PHP_EOL);
    }
    exit(1);
};

/**
 * Lane reports under a directory, by the exact name `tests/run.php --report-json` is given in each
 * lane. The receipt itself is named differently so it can never be read back as a lane.
 *
 * @return array<string, array{sha256: string, report: mixed}>
 */
$laneReports = static function (string $dir): array {
    // An absent directory is zero reports, not a crash: a FAIL receipt is still a receipt.
    if (!is_dir($dir)) {
        return [];
    }
    $found = [];
    $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        if ($entry instanceof \SplFileInfo && $entry->isFile() && preg_match('/^qualification-\d+\.\d+\.json$/', $entry->getFilename()) === 1) {
            $bytes = (string) file_get_contents($entry->getPathname());
            $found[substr($entry->getPathname(), strlen(rtrim($dir, '/')) + 1)] = [
                'sha256' => hash('sha256', $bytes),
                'report' => json_decode($bytes, true),
            ];
        }
    }
    ksort($found, SORT_STRING);

    return $found;
};

$command = $argv[1] ?? '';

switch ($command) {
    case 'code-job-steps':
        try { fwrite(STDOUT, implode(' ', CodeQ::steps($argv[2] ?? '')) . PHP_EOL); exit(0); }
        catch (\InvalidArgumentException) { $fail('unknown code qualification job',1); }
    case 'code-local-jobs':
        fwrite(STDOUT, implode(PHP_EOL, CodeQ::jobs(Q::EXECUTION_LOCAL)) . PHP_EOL);
        exit(0);
    case 'job-steps':
        $job = $argv[2] ?? '';
        if (!isset(Q::JOBS[$job])) {
            $fail("unknown job '{$job}'; known: " . implode(', ', array_keys(Q::JOBS)));
        }
        fwrite(STDOUT, implode(' ', Q::jobSteps($job)) . PHP_EOL);
        exit(0);

    case 'local-jobs':
        fwrite(STDOUT, implode(PHP_EOL, Q::requiredJobs(Q::EXECUTION_LOCAL)) . PHP_EOL);
        exit(0);

    case 'db-version':
        // The version is RECORDED from the server, not assumed from the lane name: a lane that
        // reported the version it asked for rather than the one it got could claim 10.6 parity while
        // running on anything.
        $args = $flags(['env', 'lane']);
        $envPath = $required($args, 'env');
        $lane = $required($args, 'lane');
        if (!is_file($envPath)) {
            $fail("no such configuration file: {$envPath}", 1);
        }
        $env = Env::load($envPath);
        try {
            $db = Database::connect(
                $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
                $env->int('DB_PORT', 3306),
                $env->require('DB_NAME'),
                $env->require('DB_USER'),
                $env->get('DB_PASSWORD', '') ?? '',
                $env->get('DB_SOCKET'),
            );
        } catch (\Throwable $error) {
            // The exception class only: a PDO message can quote the DSN.
            $fail('database unreachable (' . $error::class . ')', 1);
        }
        $version = $db->serverVersion();
        fwrite(STDOUT, "MariaDB under test: {$version}" . PHP_EOL);
        if (Q::laneOf($version) !== $lane) {
            $fail("server version does not match the lane {$lane}", 1);
        }
        exit(0);

    case 'lanes':
        $args = $flags(['dir']);
        $dir = $required($args, 'dir');
        if (!is_dir($dir)) {
            fwrite(STDERR, "  NOT QUALIFIED: no report directory at {$dir}" . PHP_EOL);
        }
        $reports = $laneReports($dir);
        foreach ($reports as $label => $lane) {
            $report = is_array($lane['report']) ? $lane['report'] : [];
            $total = is_array($report['total'] ?? null) ? $report['total'] : [];
            fwrite(STDOUT, sprintf(
                "--- %s\n  php=%s mariadb=%s pass=%s fail=%s skip=%s\n",
                $label,
                json_encode($report['php_version'] ?? null),
                json_encode($report['database_server_version'] ?? null),
                json_encode($total['pass'] ?? null),
                json_encode($total['fail'] ?? null),
                json_encode($total['skip'] ?? null),
            ));
        }
        $verdict(
            Q::laneSetProblems(array_map(static fn (array $lane): mixed => $lane['report'], $reports)),
            'NOT QUALIFIED',
            'release qualification met: the code is qualified. Nothing is deployed and nothing is activated.',
        );

    case 'prepared-release':
        $args = $flags(['verdict', 'sha', 'dir']);
        $resolved = 0;
        $problems = Q::preparedReleaseProblems(
            $readJson($required($args, 'verdict')),
            $required($args, 'sha'),
            $required($args, 'dir'),
            $resolved,
        );
        fwrite(STDOUT, "  manifest references resolved: {$resolved}" . PHP_EOL);
        $verdict($problems, 'NOT A VALID PREPARED RELEASE', 'report names this commit; layout is closed and the console manifest resolves');

    case 'preflight':
        $args = $flags(['report', 'sha']);
        $report = $readJson($required($args, 'report'));
        if (is_array($report)) {
            fwrite(STDOUT, '  status             : ' . json_encode($report['status'] ?? null) . PHP_EOL);
            fwrite(STDOUT, '  source_fingerprint : ' . json_encode($report['source_fingerprint'] ?? null) . PHP_EOL);
            fwrite(STDOUT, '  operator_console   : ' . json_encode($report['operator_console'] ?? null, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            fwrite(STDOUT, '  copies_anything    : ' . json_encode($report['copies_anything'] ?? null) . PHP_EOL);
        }
        $verdict(Q::preflightProblems($report, $required($args, 'sha')), 'PREFLIGHT NOT READY', 'the prepared release is inert and ready for an inert staging copy');

    case 'code-receipt':
    case 'receipt':
        $codeMode = $command === 'code-receipt';
        $args = $flags([
            'execution', 'steps', 'lanes-dir', 'preflight', 'package-verdict', 'commit', 'tree', 'requested-ref', 'dirty',
            'started-at', 'finished-at', 'host-os', 'php', 'node', 'npm', 'node-required', 'npm-required',
        ]);
        $execution = match ($required($args, 'execution')) {
            'local' => Q::EXECUTION_LOCAL,
            'github-actions' => Q::EXECUTION_GITHUB_ACTIONS,
            default => $fail('--execution must be local or github-actions'),
        };

        $github = null;
        if ($execution === Q::EXECUTION_GITHUB_ACTIONS) {
            // Refused rather than defaulted: a receipt naming a hosted run is only ever produced on
            // one. The variables are still self-reported — which the receipt says — so the run URL
            // is recorded for a reviewer to check against GitHub's own record.
            if (getenv('GITHUB_ACTIONS') !== 'true' || (string) getenv('GITHUB_RUN_ID') === '') {
                $fail('a github-actions receipt is produced only inside a GitHub Actions run; a local run uses --execution=local');
            }
            $server = (string) (getenv('GITHUB_SERVER_URL') ?: 'https://github.com');
            $repository = (string) getenv('GITHUB_REPOSITORY');
            $runId = (string) getenv('GITHUB_RUN_ID');
            $github = [
                'repository' => $repository,
                'run_id' => $runId,
                'run_attempt' => (string) getenv('GITHUB_RUN_ATTEMPT'),
                'checkout_sha' => (string) getenv('GITHUB_SHA'),
                'head_ref' => (string) getenv('GITHUB_HEAD_REF'),
                'event_name' => (string) getenv('GITHUB_EVENT_NAME'),
                'url' => "{$server}/{$repository}/actions/runs/{$runId}",
            ];
        }

        $steps = [];
        $tsv = $required($args, 'steps');
        if (!is_file($tsv)) {
            $fail("no such steps file: {$tsv}", 1);
        }
        foreach (file($tsv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $cells = explode("\t", $line);
            if (count($cells) !== 6) {
                $fail('malformed steps row: expected 6 tab-separated cells', 1);
            }
            [$job, $step, $status, $exit, $duration, $note] = $cells;
            $steps[] = [
                'job' => $job,
                'step' => $step,
                'status' => $status,
                'exit' => preg_match('/^\d+$/', $exit) === 1 ? (int) $exit : null,
                'duration_ms' => preg_match('/^\d+$/', $duration) === 1 ? (int) $duration : null,
                'note' => $note,
            ];
        }

        $preflight = null;
        if (($args['preflight'] ?? '') !== '' && is_file($args['preflight'])) {
            $bytes = (string) file_get_contents($args['preflight']);
            $preflight = ['sha256' => hash('sha256', $bytes), 'report' => json_decode($bytes, true)];
        }

        $packageReport=null;
        if ($codeMode && ($args['package-verdict'] ?? '') !== '' && is_file($args['package-verdict'])) {
            $bytes=(string)file_get_contents($args['package-verdict']);
            $packageReport=['sha256'=>hash('sha256',$bytes),'report'=>json_decode($bytes,true)];
        }
        $optional = static fn (string $name): ?string => ($args[$name] ?? '') !== '' ? $args[$name] : null;
        $digest = static fn (string $path): ?string => is_file($path) ? hash_file('sha256', $path) : null;

        $input = [
            'execution' => $execution,
            'commit' => $required($args, 'commit'),
            'tree' => $required($args, 'tree'),
            'requested_ref' => $optional('requested-ref'),
            'materialized_from' => $execution === Q::EXECUTION_LOCAL ? 'GIT_OBJECT_EXPORT' : 'CI_CHECKOUT',
            'invoking_checkout_dirty' => match ($args['dirty'] ?? '') {
                'true' => true,
                'false' => false,
                default => null,
            },
            // The bytes that RAN: this tree's workflow, runner and verifier. In a local run this
            // tree is the export of the commit, so the hashes are the commit's own.
            'file_sha256' => [
                'workflow' => $digest($root . '/.github/workflows/ci.yml'),
                'runner' => $digest($root . '/ops/ci/qualify-release.sh'),
                'verifier' => $digest(__FILE__),
            ],
            'started_at' => $optional('started-at'),
            'finished_at' => $optional('finished-at'),
            'host_os' => $optional('host-os'),
            'toolchain' => $execution === Q::EXECUTION_LOCAL ? [
                'php' => $optional('php'),
                'node' => $optional('node'),
                'node_required' => $optional('node-required'),
                'npm' => $optional('npm'),
                'npm_required' => $optional('npm-required'),
            ] : [],
            'steps' => $steps,
            'lane_reports' => $laneReports($required($args, 'lanes-dir')),
            'preflight' => $preflight,
            'package' => $packageReport,
            'github' => $github,
        ];
        $input['file_sha256']['code_contract'] = $digest($root . '/app/Ops/CodeQualification.php');
        $receipt = $codeMode ? CodeQ::build($input) : Q::buildReceipt($input);

        fwrite(STDOUT, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        fwrite(STDERR, "receipt: {$receipt['result']} ({$execution}, commit {$receipt['source']['commit']})" . PHP_EOL);
        foreach ($receipt['problems'] as $problem) {
            fwrite(STDERR, "  x {$problem}" . PHP_EOL);
        }
        exit($receipt['result'] === Q::STATUS_PASS ? 0 : 1);

    case 'verify-code-receipt':
        $args=$flags(['receipt','lanes-dir','package-verdict']);
        $receipt=$readJson($required($args,'receipt'));
        $problems=CodeQ::verify($receipt);
        if (!is_array($receipt)||($receipt['kind']??null)!==CodeQ::KIND) {
            $problems[]='not a v2 code qualification receipt';
        }
        if (($args['lanes-dir']??'')!=='') {
            $onDisk=$laneReports($args['lanes-dir']);
            $embedded=[];
            foreach (($receipt['lanes']??[]) as $item) {
                if (is_array($item)&&is_string($item['report']??null)) $embedded[$item['report']]=$item;
            }
            if (array_keys($onDisk)!==array_keys($embedded)) $problems[]='retained lane inventory differs';
            foreach ($onDisk as $label=>$data) {
                if (!isset($embedded[$label])||($embedded[$label]['sha256']??null)!==$data['sha256']
                    ||($embedded[$label]['content']??null)!==$data['report'])
                    $problems[]="{$label}: retained lane artifact mismatch";
            }
        }
        if (($args['package-verdict']??'')!=='') {
            $path=$args['package-verdict'];
            $hash=is_file($path)?hash_file('sha256',$path):null;
            $decoded=is_file($path)?json_decode((string)file_get_contents($path),true):null;
            if (($receipt['package']['sha256']??null)!==$hash
                ||($receipt['package']['content']??null)!==$decoded||$hash===null) {
                $problems[]='retained prepared-release verdict mismatch';
            }
        }
        if ($problems!==[]||($receipt['result']??null)!=='PASS') {
            foreach ($problems as $problem) fwrite(STDERR,"code qualification FAIL: {$problem}\n");
            exit(1);
        }
        fwrite(STDOUT,"code qualification PASS, deployment readiness NOT_ASSESSED\n");
        exit(0);

    case 'verify-receipt':
        // CONSISTENCY, NOT INDEPENDENCE. This proves a receipt was not edited into claiming what
        // its own contents contradict, and — given the retained artifacts — that the reports it
        // embeds are the files beside it. It cannot prove the steps really ran; nothing a machine
        // says about itself can.
        $args = $flags(['receipt', 'lanes-dir', 'preflight']);
        $receipt = $readJson($required($args, 'receipt'));
        $problems = Q::receiptProblems($receipt);

        if (is_array($receipt) && ($args['lanes-dir'] ?? '') !== '') {
            $onDisk = $laneReports($args['lanes-dir']);
            $embedded = [];
            foreach (is_array($receipt['lanes'] ?? null) ? $receipt['lanes'] : [] as $lane) {
                if (is_array($lane) && is_string($lane['report'] ?? null)) {
                    $embedded[$lane['report']] = $lane;
                }
            }
            if (array_keys($onDisk) !== array_keys($embedded)) {
                $problems[] = 'the retained lane reports are not the reports the receipt names';
            }
            foreach ($onDisk as $label => $file) {
                if (isset($embedded[$label]) && (($embedded[$label]['sha256'] ?? null) !== $file['sha256'] || ($embedded[$label]['content'] ?? null) !== $file['report'])) {
                    $problems[] = "{$label}: the retained file is not the report the receipt embeds";
                }
            }
        }
        if (is_array($receipt) && ($args['preflight'] ?? '') !== '') {
            $path = $args['preflight'];
            $recorded = is_array($receipt['preflight'] ?? null) ? ($receipt['preflight']['sha256'] ?? null) : null;
            if (!is_file($path) || hash_file('sha256', $path) !== $recorded) {
                $problems[] = 'the retained preflight report is not the one the receipt records';
            }
        }

        if (is_array($receipt)) {
            fwrite(STDOUT, sprintf(
                "  %s receipt for %s: states %s\n",
                json_encode($receipt['execution']['kind'] ?? null),
                json_encode($receipt['source']['commit'] ?? null),
                json_encode($receipt['result'] ?? null),
            ));
        }
        if ($problems === [] && is_array($receipt) && ($receipt['result'] ?? null) !== Q::STATUS_PASS) {
            // Internally consistent, and consistently a failure.
            foreach (is_array($receipt['problems'] ?? null) ? $receipt['problems'] : [] as $problem) {
                fwrite(STDERR, '  FAIL: ' . (is_string($problem) ? $problem : json_encode($problem)) . PHP_EOL);
            }
            exit(1);
        }
        $verdict($problems, 'RECEIPT NOT CONSISTENT', 'the receipt is internally consistent and states PASS — self-reported, not independently verified');

    default:
        $fail('usage: verify-qualification.php <code-job-steps|code-local-jobs|code-receipt|verify-code-receipt|job-steps|local-jobs|db-version|lanes|prepared-release|preflight|receipt|verify-receipt> [--flags]');
}
