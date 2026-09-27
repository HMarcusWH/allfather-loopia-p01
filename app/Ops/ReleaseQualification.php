<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

/**
 * One qualification procedure, whoever runs it.
 *
 * THE PROBLEM IT SOLVES. The workflow in `.github/workflows/ci.yml` was the only complete statement
 * of what "qualified" means for a commit, and it was written as inline shell and Python inside YAML.
 * Hosted runners then stopped being allocated (account billing), and every release since PR13 has
 * been qualified by a person re-typing that YAML by hand on another machine. A hand reproduction is
 * honest when it is complete, and nothing checked that it was: the list of steps lived in a
 * document, the checks lived in the workflow, and the two could drift without either failing.
 *
 * So the steps are declared ONCE, here, and both routes execute them through the same runner:
 *
 *   `ops/ci/qualify-release.sh step <name>`   — what every `run:` in ci.yml calls
 *   `ops/ci/qualify-release.sh local ...`      — the same steps, in the same order, from a fresh
 *                                                `git archive` export of one exact commit
 *
 * and `tests/foundation/qualification_runner_boundary.php` fails the build if the workflow's step
 * invocations, the runner and {@see self::JOBS} disagree.
 *
 * WHAT A RECEIPT IS, AND IS NOT. {@see self::buildReceipt()} binds a commit and tree, the bytes of
 * the workflow/runner/verifier that ran, the execution environment, every step's result, the
 * sanitized lane reports and the prepared-release preflight. It is SELF-REPORTED by the machine that
 * produced it. A LOCAL receipt says so and can never read as hosted CI; a GITHUB_ACTIONS receipt
 * names the run whose own record is the thing to check. Neither is a host record, a deployment or
 * an activation, and the receipt carries that list rather than leaving it to the reader.
 *
 * READ-ONLY. Nothing here writes a file: the CLI prints and the shell redirects. That keeps this
 * class outside the governed-writer tripwire in `tests/foundation/no_external_action_surface.php`
 * by construction rather than by exemption.
 */
final class ReleaseQualification
{
    public const RECEIPT_SCHEMA_VERSION = '1.0';

    public const RECEIPT_KIND = 'RELEASE_QUALIFICATION_RECEIPT';

    /** The schema `tests/run.php --report-json` writes. */
    public const LANE_REPORT_SCHEMA_VERSION = '1.0';

    public const PROJECTION_ID = 'LOOPIA_SME_MVP';

    /**
     * The PHP line the workflow sets up. The drift test holds every `php-version:` in ci.yml to it,
     * so a local lane on another PHP cannot pass for a reproduction of the workflow.
     */
    public const PHP_LINE = '8.2';

    /**
     * Both MariaDB lines that matter: 10.6 is Loopia Företag's floor, 10.11 is Företag Plus. A
     * receipt missing either is not a qualification, whichever one passed.
     *
     * @var list<string>
     */
    public const MARIADB_LANES = ['10.6', '10.11'];

    /**
     * Every qualification job and its steps, in execution order. The ONE declaration: the runner
     * reads it through `verify-qualification.php job-steps`, and ci.yml is asserted against it.
     *
     * Provisioning is deliberately absent — checking out, installing a toolchain, starting a
     * database service, writing that service's connection file, moving artifacts between hosted
     * jobs. Those differ by environment by nature. What must NOT differ is every command whose
     * exit status decides qualification, and that is exactly this list.
     *
     * @var array<string, list<string>>
     */
    public const JOBS = [
        'foundation' => ['frontend', 'runtime', 'lock', 'php-syntax', 'foundation-layer', 'unit-layer'],
        'mariadb' => ['db-version', 'migrate', 'migrate-repeat', 'migrate-status', 'probe', 'evidence', 'full-suite'],
        'release-preparation' => [
            'npm-pin',
            'prepare',
            'verify-prepared',
            'verify-export',
            'rsync-rehearsal',
            'preflight',
            'verify-preflight',
        ],
        'release-qualification' => ['verify-lanes'],
    ];

    public const EXECUTION_LOCAL = 'LOCAL';

    public const EXECUTION_GITHUB_ACTIONS = 'GITHUB_ACTIONS';

    /** A local run reports each step; a hosted run reports each job and GitHub holds the steps. */
    public const GRANULARITY_STEP = 'STEP';

    public const GRANULARITY_JOB = 'JOB';

    public const STATUS_PASS = 'PASS';

    public const STATUS_FAIL = 'FAIL';

    public const STATUS_NOT_RUN = 'NOT_RUN';

    public const PREPARED_RELEASE_STATUS = 'PREPARED_RELEASE';

    public const PREFLIGHT_READY = 'READY_FOR_INERT_STAGING_COPY';

    /**
     * The posture a prepared release must report. These are properties of the code being prepared,
     * and a preflight that reported otherwise would mean the release is not inert.
     *
     * @var list<string>
     */
    public const INERT_PREFLIGHT_KEYS = [
        'live_read',
        'scheduler_handlers',
        'external_action',
        'customer_visible_output',
        'production_launch_authorized',
        'authorizes_deployment',
        'copies_anything',
    ];

    private const CONSOLE_STAGE = 'public_html/assets/operator-console';

    /** @return list<string> */
    public static function jobSteps(string $job): array
    {
        if (!isset(self::JOBS[$job])) {
            throw new \InvalidArgumentException("unknown qualification job: {$job}");
        }

        return self::JOBS[$job];
    }

    /**
     * The jobs an execution must account for. A local run expands the MariaDB job once per lane,
     * because each lane is its own sequence of steps there; a hosted matrix reports one result.
     *
     * @return list<string>
     */
    public static function requiredJobs(string $execution): array
    {
        if ($execution === self::EXECUTION_GITHUB_ACTIONS) {
            return array_keys(self::JOBS);
        }

        $jobs = [];
        foreach (array_keys(self::JOBS) as $job) {
            if ($job === 'mariadb') {
                foreach (self::MARIADB_LANES as $lane) {
                    $jobs[] = 'mariadb-' . $lane;
                }
                continue;
            }
            $jobs[] = $job;
        }

        return $jobs;
    }

    /** `mariadb-10.6` → the MariaDB job's steps; anything else → its own. @return list<string> */
    public static function stepsForExpandedJob(string $job): array
    {
        foreach (self::MARIADB_LANES as $lane) {
            if ($job === 'mariadb-' . $lane) {
                return self::JOBS['mariadb'];
            }
        }

        return self::jobSteps($job);
    }

    /** `10.6.28-MariaDB` → `10.6`; `10.11.14-MariaDB-log` → `10.11`; anything unparseable → null. */
    public static function laneOf(mixed $serverVersion): ?string
    {
        if (!is_string($serverVersion) || preg_match('/^(\d+\.\d+)\./', $serverVersion, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    // ------------------------------------------------------------------------------ lane reports

    /**
     * Problems with one `tests/run.php --report-json` report. The report is read rather than
     * believed: a green job whose report says `skip: 3` was green for the wrong reason, and the
     * report is the artifact somebody will later cite.
     *
     * @return list<string>
     */
    public static function laneReportProblems(mixed $report, ?string $lane = null): array
    {
        if (!is_array($report)) {
            return ['the report is not a JSON object'];
        }

        $problems = [];
        if (($report['schema_version'] ?? null) !== self::LANE_REPORT_SCHEMA_VERSION) {
            $problems[] = 'unexpected schema_version';
        }
        if (($report['projection_id'] ?? null) !== self::PROJECTION_ID) {
            $problems[] = 'the report names another projection';
        }
        if (($report['complete'] ?? null) !== true) {
            $problems[] = 'not a complete run — a layer or file filter narrowed the suite';
        }
        if (($report['strict'] ?? null) !== true) {
            $problems[] = 'not a --strict run, so a skip would not have failed it';
        }
        $total = is_array($report['total'] ?? null) ? $report['total'] : [];
        if (($total['fail'] ?? null) !== 0) {
            $problems[] = 'failures: ' . self::show($total['fail'] ?? null);
        }
        if (($total['skip'] ?? null) !== 0) {
            $problems[] = 'skips: ' . self::show($total['skip'] ?? null);
        }
        if (!is_int($total['pass'] ?? null) || $total['pass'] <= 0) {
            $problems[] = 'no assertions passed';
        }

        $server = $report['database_server_version'] ?? null;
        if (!is_string($server) || $server === '') {
            $problems[] = 'the run never opened a database connection';
        } elseif ($lane !== null && self::laneOf($server) !== $lane) {
            $problems[] = "ran against MariaDB {$server}, not the {$lane} lane";
        }

        $php = $report['php_version'] ?? null;
        if (!is_string($php) || !str_starts_with($php, self::PHP_LINE . '.')) {
            $problems[] = 'ran on PHP ' . self::show($php) . ', not the ' . self::PHP_LINE . ' line the workflow sets up';
        }

        return $problems;
    }

    /**
     * Problems with a SET of lane reports: every one must pass on its own, and together they must
     * cover each required MariaDB line exactly once. Two 10.11 reports are not a 10.6 proof.
     *
     * @param array<string, mixed> $reports label (usually a relative path) => decoded report
     * @return list<string>
     */
    public static function laneSetProblems(array $reports): array
    {
        $problems = [];
        $expected = count(self::MARIADB_LANES);
        if (count($reports) !== $expected) {
            $problems[] = sprintf('expected one qualification report per MariaDB lane (%d), found %d', $expected, count($reports));
        }

        $covered = [];
        foreach ($reports as $label => $report) {
            foreach (self::laneReportProblems($report) as $problem) {
                $problems[] = "{$label}: {$problem}";
            }
            $lane = is_array($report) ? self::laneOf($report['database_server_version'] ?? null) : null;
            if ($lane === null) {
                continue;
            }
            if (!in_array($lane, self::MARIADB_LANES, true)) {
                $problems[] = "{$label}: MariaDB {$lane} is not a required lane";
                continue;
            }
            if (isset($covered[$lane])) {
                $problems[] = "{$label}: a second report for the {$lane} lane (first: {$covered[$lane]})";
                continue;
            }
            $covered[$lane] = (string) $label;
        }
        foreach (self::MARIADB_LANES as $lane) {
            if (!isset($covered[$lane])) {
                $problems[] = "no report covers the MariaDB {$lane} lane";
            }
        }

        return $problems;
    }

    // --------------------------------------------------------------------------- prepared release

    /**
     * Problems with a prepared release: the verdict `prepare-release.sh` printed, and the tree it
     * says it produced.
     *
     * A RELEASE IS AN EXPORT, NOT A COPY OF A CHECKOUT. `.git` would carry the whole history and a
     * checkout's credential configuration; a `.env` would carry a secret. The export is
     * `git archive`, so neither can be present — which is exactly why it is worth asserting.
     *
     * The verdict's `release_ref` is only the argument the script was handed, printed back. That the
     * BYTES are the named object is the separate `verify-export` step's business.
     *
     * @return list<string>
     */
    public static function preparedReleaseProblems(mixed $verdict, string $sha, string $expectedPath, ?int &$resolved = null): array
    {
        $resolved = 0;
        if (!is_array($verdict)) {
            return ['the prepare-release verdict is not a JSON object'];
        }

        $problems = [];
        if (($verdict['status'] ?? null) !== self::PREPARED_RELEASE_STATUS) {
            $problems[] = 'status is ' . self::show($verdict['status'] ?? null) . ', not ' . self::PREPARED_RELEASE_STATUS;
        }
        if (($verdict['release_ref'] ?? null) !== $sha) {
            $problems[] = 'release_ref ' . self::show($verdict['release_ref'] ?? null) . " is not the qualified commit {$sha}";
        }
        if (($verdict['path'] ?? null) !== $expectedPath) {
            $problems[] = 'prepared path ' . self::show($verdict['path'] ?? null) . " is not {$expectedPath}";
        }
        if (!is_dir($expectedPath)) {
            $problems[] = 'the prepared release directory does not exist';

            return $problems;
        }
        if (file_exists($expectedPath . '/.git')) {
            $problems[] = '.git entered the prepared release';
        }

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($expectedPath, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $entry): bool => !($entry->isDir() && $entry->getFilename() === 'node_modules'),
            ),
        );
        $leaked = [];
        foreach ($walk as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isDir()) {
                continue;
            }
            $name = $entry->getFilename();
            if ($name === '.env' || (str_starts_with($name, '.env.') && $name !== '.env.example')) {
                $leaked[] = substr($entry->getPathname(), strlen($expectedPath) + 1);
            }
        }
        sort($leaked, SORT_STRING);
        foreach ($leaked as $relative) {
            $problems[] = "a configuration file entered the prepared release: {$relative}";
        }

        $stage = $expectedPath . '/' . self::CONSOLE_STAGE;
        $manifestPath = $stage . '/manifest.json';
        if (!is_file($manifestPath)) {
            $problems[] = 'the Operator Console manifest is absent from the prepared release';

            return $problems;
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            $problems[] = 'the Operator Console manifest is not a JSON object';

            return $problems;
        }
        $entry = is_array($manifest['entry'] ?? null) ? $manifest['entry'] : [];
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
        foreach ([$entry['script'] ?? null, $entry['style'] ?? null, ...$assets] as $ref) {
            if (!is_string($ref) || $ref === '' || str_starts_with($ref, '/') || in_array('..', explode('/', $ref), true)) {
                $problems[] = 'manifest carries a non-path reference: ' . self::show($ref);
                continue;
            }
            if (!is_file($stage . '/' . $ref)) {
                $problems[] = "manifest references a file that is not staged: {$ref}";
                continue;
            }
            $resolved++;
        }

        return $problems;
    }

    /**
     * Problems with the prepared tree's preflight report.
     *
     * @return list<string>
     */
    public static function preflightProblems(mixed $report, string $sha): array
    {
        if (!is_array($report)) {
            return ['the preflight report is not a JSON object'];
        }

        $problems = [];
        if (($report['status'] ?? null) !== self::PREFLIGHT_READY) {
            $problems[] = 'status is ' . self::show($report['status'] ?? null);
        }
        foreach (is_array($report['problems'] ?? null) ? $report['problems'] : [] as $problem) {
            $problems[] = 'preflight problem: ' . (is_string($problem) ? $problem : self::show($problem));
        }
        if (($report['release_ref'] ?? null) !== $sha) {
            $problems[] = 'preflight reported a different release ref';
        }
        foreach (self::INERT_PREFLIGHT_KEYS as $key) {
            if (($report[$key] ?? null) !== false) {
                $problems[] = "{$key} is " . self::show($report[$key] ?? null) . ', expected false';
            }
        }

        return $problems;
    }

    // ----------------------------------------------------------------------------------- receipts

    /**
     * Assemble a receipt from what an execution recorded, and judge it.
     *
     * The result is COMPUTED here from the evidence in the receipt, never passed in: a caller that
     * could hand over `result: PASS` would make the receipt a statement about the caller's opinion.
     *
     * @param array{
     *     execution: string,
     *     commit: string,
     *     tree: string,
     *     requested_ref?: ?string,
     *     materialized_from: string,
     *     invoking_checkout_dirty?: ?bool,
     *     file_sha256: array<string, ?string>,
     *     started_at?: ?string,
     *     finished_at?: ?string,
     *     host_os?: ?string,
     *     toolchain?: array<string, ?string>,
     *     steps: list<array<string, mixed>>,
     *     lane_reports: array<string, array{sha256: string, report: mixed}>,
     *     preflight?: ?array{sha256: string, report: mixed},
     *     github?: ?array<string, ?string>,
     * } $in
     * @return array<string, mixed>
     */
    public static function buildReceipt(array $in): array
    {
        $execution = (string) $in['execution'];
        $granularity = $execution === self::EXECUTION_GITHUB_ACTIONS ? self::GRANULARITY_JOB : self::GRANULARITY_STEP;

        $local = $execution === self::EXECUTION_LOCAL;
        $jobs = [];
        foreach ($in['steps'] as $row) {
            $job = (string) ($row['job'] ?? '');
            $jobs[$job] ??= ['job' => $job, 'result' => null, 'steps' => []];
            $jobs[$job]['steps'][] = [
                'step' => (string) ($row['step'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'exit' => isset($row['exit']) && is_int($row['exit']) ? $row['exit'] : null,
                'duration_ms' => isset($row['duration_ms']) && is_int($row['duration_ms']) ? $row['duration_ms'] : null,
                'note' => isset($row['note']) && is_string($row['note']) && $row['note'] !== '' ? $row['note'] : null,
            ];
        }
        foreach ($jobs as $job => $record) {
            $jobs[$job]['result'] = self::jobPassed($record['steps'], $local) ? self::STATUS_PASS : self::STATUS_FAIL;
        }

        $lanes = [];
        foreach ($in['lane_reports'] as $label => $lane) {
            $lanes[] = ['report' => (string) $label, 'sha256' => $lane['sha256'], 'content' => $lane['report']];
        }

        $receipt = [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'kind' => self::RECEIPT_KIND,
            'projection_id' => self::PROJECTION_ID,
            'result' => null,
            'problems' => [],
            'source' => [
                'commit' => $in['commit'],
                'tree' => $in['tree'],
                'requested_ref' => $in['requested_ref'] ?? null,
                'materialized_from' => $in['materialized_from'],
                // Whether the checkout the run was STARTED from had uncommitted changes. They are
                // not part of what was qualified — the commit was exported — and saying so here is
                // cheaper than a reviewer wondering.
                'invoking_checkout_dirty' => $in['invoking_checkout_dirty'] ?? null,
                'file_sha256' => $in['file_sha256'],
            ],
            'execution' => [
                'kind' => $execution,
                'granularity' => $granularity,
                'self_reported' => true,
                'hosted_ci' => !$local,
                'github_run' => $local ? null : ($in['github'] ?? null),
                'started_at' => $in['started_at'] ?? null,
                'finished_at' => $in['finished_at'] ?? null,
                'host_os' => $in['host_os'] ?? null,
            ],
            'toolchain' => $in['toolchain'] ?? [],
            'required' => [
                'php_line' => self::PHP_LINE,
                'mariadb_lanes' => self::MARIADB_LANES,
                'jobs' => self::JOBS,
            ],
            'jobs' => array_values($jobs),
            'lanes' => $lanes,
            'preflight' => isset($in['preflight']) ? [
                'sha256' => $in['preflight']['sha256'],
                'status' => is_array($in['preflight']['report']) ? ($in['preflight']['report']['status'] ?? null) : null,
                'source_fingerprint' => is_array($in['preflight']['report']) ? ($in['preflight']['report']['source_fingerprint'] ?? null) : null,
                'content' => $in['preflight']['report'],
            ] : null,
            'establishes' => $local
                ? 'Every qualification step of this commit exited 0 on the recorded machine, from a git-archive export of the commit, and each MariaDB lane report is complete, strict, and has zero failures and zero skips.'
                : 'The named GitHub Actions run reported every qualification job successful for this commit, and each MariaDB lane report is complete, strict, and has zero failures and zero skips.',
            'does_not_establish' => [
                $local
                    ? 'hosted GitHub Actions success — this is a local run, and it says so'
                    : 'that the run record exists as described — the GitHub run itself is the record to check',
                'independent verification — a receipt is self-reported by the machine that produced it',
                'Loopia host qualification, deployment, or any claim about a real host',
                'activation of live read, native model execution or the scheduler',
                'owner GO, customer-visible output, external action or production authority',
            ],
            'filing' => 'If filed, only as `CONFORMANCE` evidence with `environment: "ci"` for exactly this commit. Never as a host record.',
        ];

        $problems = self::receiptEvaluation($receipt);
        $receipt['result'] = $problems === [] ? self::STATUS_PASS : self::STATUS_FAIL;
        $receipt['problems'] = $problems;

        return $receipt;
    }

    /**
     * Problems with a stored receipt: its own evaluation, re-run, and whether the result it states
     * is the result its evidence supports. Consistency is not independence — it proves the receipt
     * was not edited into saying something its own contents contradict, nothing more.
     *
     * @return list<string>
     */
    public static function receiptProblems(mixed $receipt): array
    {
        if (!is_array($receipt)) {
            return ['the receipt is not a JSON object'];
        }

        $evaluation = self::receiptEvaluation($receipt);
        $problems = $evaluation;
        $expected = $evaluation === [] ? self::STATUS_PASS : self::STATUS_FAIL;
        if (($receipt['result'] ?? null) !== $expected) {
            $problems[] = 'the receipt states result ' . self::show($receipt['result'] ?? null) . " but its evidence supports {$expected}";
        }
        if (($receipt['problems'] ?? null) !== $evaluation) {
            $problems[] = 'the receipt\'s recorded problems are not the problems its evidence produces';
        }

        return $problems;
    }

    /** @return list<string> */
    private static function receiptEvaluation(array $receipt): array
    {
        $problems = [];
        if (($receipt['schema_version'] ?? null) !== self::RECEIPT_SCHEMA_VERSION) {
            $problems[] = 'unexpected receipt schema_version';
        }
        if (($receipt['kind'] ?? null) !== self::RECEIPT_KIND) {
            $problems[] = 'not a release qualification receipt';
        }
        if (($receipt['projection_id'] ?? null) !== self::PROJECTION_ID) {
            $problems[] = 'the receipt names another projection';
        }

        $source = is_array($receipt['source'] ?? null) ? $receipt['source'] : [];
        $commit = $source['commit'] ?? null;
        foreach (['commit', 'tree'] as $object) {
            if (!is_string($source[$object] ?? null) || preg_match('/^[0-9a-f]{40}$/', $source[$object]) !== 1) {
                $problems[] = "source.{$object} is not a full 40-character Git object id";
            }
        }

        $execution = is_array($receipt['execution'] ?? null) ? $receipt['execution'] : [];
        $kind = $execution['kind'] ?? null;
        if ($kind !== self::EXECUTION_LOCAL && $kind !== self::EXECUTION_GITHUB_ACTIONS) {
            $problems[] = 'execution.kind is ' . self::show($kind);

            return $problems;
        }
        $local = $kind === self::EXECUTION_LOCAL;
        if (($execution['self_reported'] ?? null) !== true) {
            $problems[] = 'a receipt must declare itself self-reported';
        }
        // THE LINE A LOCAL RUN MUST NEVER CROSS. A local receipt that claimed hosted CI would be the
        // exact substitution the billing deviation forbids.
        if (($execution['hosted_ci'] ?? null) !== !$local) {
            $problems[] = $local ? 'a LOCAL receipt claims hosted CI' : 'a GITHUB_ACTIONS receipt denies being hosted CI';
        }
        if ($local && ($execution['github_run'] ?? null) !== null) {
            $problems[] = 'a LOCAL receipt names a GitHub run';
        }
        if (!$local) {
            $run = is_array($execution['github_run'] ?? null) ? $execution['github_run'] : [];
            if (!is_string($run['run_id'] ?? null) || preg_match('/^\d+$/', $run['run_id']) !== 1) {
                $problems[] = 'a GITHUB_ACTIONS receipt names no run id';
            }
        }
        $expectedMaterialization = $local ? 'GIT_OBJECT_EXPORT' : 'CI_CHECKOUT';
        if (($source['materialized_from'] ?? null) !== $expectedMaterialization) {
            $problems[] = 'source.materialized_from is ' . self::show($source['materialized_from'] ?? null) . ", expected {$expectedMaterialization}";
        }
        $hashes = is_array($source['file_sha256'] ?? null) ? $source['file_sha256'] : [];
        foreach (['workflow', 'runner', 'verifier'] as $file) {
            if (!is_string($hashes[$file] ?? null) || preg_match('/^[0-9a-f]{64}$/', $hashes[$file]) !== 1) {
                $problems[] = "source.file_sha256.{$file} is not a sha256";
            }
        }

        // Every required job, and in a local run every step, in order, each PASS.
        $granularity = $local ? self::GRANULARITY_STEP : self::GRANULARITY_JOB;
        if (($execution['granularity'] ?? null) !== $granularity) {
            $problems[] = 'execution.granularity is ' . self::show($execution['granularity'] ?? null) . ", expected {$granularity}";
        }
        $jobs = [];
        foreach (is_array($receipt['jobs'] ?? null) ? $receipt['jobs'] : [] as $job) {
            if (is_array($job) && is_string($job['job'] ?? null)) {
                $jobs[$job['job']] = $job;
            }
        }
        $required = self::requiredJobs($kind);
        foreach (array_keys($jobs) as $name) {
            if (!in_array($name, $required, true)) {
                $problems[] = "unexpected job {$name}";
            }
        }
        foreach ($required as $name) {
            if (!isset($jobs[$name])) {
                $problems[] = "job {$name} is absent — an omitted job is not a passed one";
                continue;
            }
            $steps = is_array($jobs[$name]['steps'] ?? null) ? $jobs[$name]['steps'] : [];
            $names = array_map(static fn (mixed $s): mixed => is_array($s) ? ($s['step'] ?? null) : null, $steps);
            $expectedSteps = $local ? self::stepsForExpandedJob($name) : ['*'];
            if ($names !== $expectedSteps) {
                $problems[] = "job {$name} ran steps [" . implode(', ', array_map(self::show(...), $names)) . '], expected [' . implode(', ', $expectedSteps) . ']';
            }
            foreach ($steps as $step) {
                $status = is_array($step) ? ($step['status'] ?? null) : null;
                if ($status === self::STATUS_PASS && (!$local || ($step['exit'] ?? null) === 0)) {
                    continue;
                }
                $label = is_array($step) ? self::show($step['step'] ?? null) : 'null';
                $note = is_array($step) && is_string($step['note'] ?? null) ? " ({$step['note']})" : '';
                $problems[] = "job {$name} step {$label}: " . self::show($status) . $note;
            }
            if (($jobs[$name]['result'] ?? null) !== (self::jobPassed($steps, $local) ? self::STATUS_PASS : self::STATUS_FAIL)) {
                $problems[] = "job {$name} states a result its steps do not support";
            }
        }

        // The lane reports themselves, re-judged from their embedded content.
        $reports = [];
        foreach (is_array($receipt['lanes'] ?? null) ? $receipt['lanes'] : [] as $index => $lane) {
            $label = is_array($lane) && is_string($lane['report'] ?? null) ? $lane['report'] : "lane[{$index}]";
            if (!is_array($lane) || !is_string($lane['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $lane['sha256']) !== 1) {
                $problems[] = "{$label}: no sha256 for the report";
            }
            $reports[$label] = is_array($lane) ? ($lane['content'] ?? null) : null;
        }
        foreach (self::laneSetProblems($reports) as $problem) {
            $problems[] = $problem;
        }

        // The prepared tree's preflight, re-judged against the qualified commit.
        $preflight = $receipt['preflight'] ?? null;
        if (!is_array($preflight)) {
            $problems[] = 'no prepared-release preflight report';
        } else {
            if (!is_string($preflight['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $preflight['sha256']) !== 1) {
                $problems[] = 'preflight: no sha256 for the report';
            }
            foreach (self::preflightProblems($preflight['content'] ?? null, is_string($commit) ? $commit : '') as $problem) {
                $problems[] = "preflight: {$problem}";
            }
        }

        // A local machine is arbitrary, so its toolchain is part of what is being claimed. The
        // hosted workflow pins its own through setup-php/setup-node and asserts npm in `npm-pin`.
        if ($local) {
            $toolchain = is_array($receipt['toolchain'] ?? null) ? $receipt['toolchain'] : [];
            $php = $toolchain['php'] ?? null;
            if (!is_string($php) || !str_starts_with($php, self::PHP_LINE . '.')) {
                $problems[] = 'toolchain: PHP ' . self::show($php) . ' is not the ' . self::PHP_LINE . ' line the workflow sets up';
            }
            foreach (['node', 'npm'] as $tool) {
                $actual = $toolchain[$tool] ?? null;
                $pinned = $toolchain[$tool . '_required'] ?? null;
                if (!is_string($pinned) || $pinned === '') {
                    $problems[] = "toolchain: no pinned {$tool} version recorded";
                } elseif ($actual !== $pinned) {
                    $problems[] = "toolchain: {$tool} " . self::show($actual) . " is not the repository pin {$pinned}";
                }
            }
        }

        return $problems;
    }

    /** @param list<mixed> $steps */
    private static function jobPassed(array $steps, bool $local): bool
    {
        if ($steps === []) {
            return false;
        }
        foreach ($steps as $step) {
            if (!is_array($step) || ($step['status'] ?? null) !== self::STATUS_PASS) {
                return false;
            }
            if ($local && ($step['exit'] ?? null) !== 0) {
                return false;
            }
        }

        return true;
    }

    private static function show(mixed $value): string
    {
        return is_string($value) ? "'{$value}'" : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
