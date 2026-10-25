<?php

declare(strict_types=1);

/**
 * Operator-only read-only exposure audit. No network or state mutation.
 * Usage: php ops/security/audit-public-repository.php --scope=tree|index|history
 * History checks every reachable blob from locally available refs; a shallow or incomplete
 * clone cannot establish a complete remote/public history.
 * Output contains ONLY finding codes, paths and line numbers (never credential bytes).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/app/autoload.php';
use Allfather\Loopia\Ops\PublicExposureAudit as Guard;

$root = dirname(__DIR__, 2);
$args = getopt('', ['scope:']);
$scope = $args['scope'] ?? null;
if (!is_string($scope) || !in_array($scope, ['tree', 'index', 'history'], true)) {
    fwrite(STDERR, "usage: php ops/security/audit-public-repository.php --scope=tree|index|history\n");
    exit(2);
}

/** Git stdout is held privately in-process. Git stderr and blob data are never echoed. */
$git = static function (array $argv) use ($root): array {
    $pipes = [];
    $process = proc_open(['git', '-C', $root, ...$argv],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'data' => ''];
    }
    fclose($pipes[0]);
    $data = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return ['ok' => proc_close($process) === 0, 'data' => is_string($data) ? $data : ''];
};

$issues = [];
$total = 0;
$publicDecisions = 0;
$complete = true;
$fail = static function (string $code, string $path = '(git)', int $line = 0)
    use (&$issues, &$complete): void {
    $issues[] = ['code' => $code, 'path' => $path, 'line' => $line];
    if (in_array($code, ['GIT_INVENTORY_UNAVAILABLE', 'HISTORY_OBJECT_UNREADABLE',
        'HISTORY_INCOMPLETE'], true)) {
        $complete = false;
    }
};
$inspect = static function (string $path, ?string $bytes)
    use (&$total, &$publicDecisions, &$issues, $fail): void {
    $total++;
    if (in_array($path, Guard::REVIEWED_DECISIONS, true)) {
        $publicDecisions++;
    }
    if ($bytes === null) {
        $fail('TRACKED_BLOB_UNREADABLE', $path);
    } else {
        array_push($issues, ...Guard::inspect($path, $bytes));
    }
};

if ($scope === 'tree') {
    $result = Guard::auditTree($root);
    $total = $result['files'];
    $publicDecisions = $result['decisions'];
    $issues = $result['findings'];
} elseif ($scope === 'index') {
    $list = $git(['ls-files', '-z']);
    if (!$list['ok'] || $list['data'] === '') {
        $fail('GIT_INVENTORY_UNAVAILABLE');
    } else {
        foreach (explode("\0", $list['data']) as $path) {
            if ($path === '') {
                continue;
            }
            $file = $root . '/' . $path;
            $bytes = is_file($file) && !is_link($file) ? file_get_contents($file) : false;
            $inspect($path, $bytes === false ? null : $bytes);
        }
    }
} else {
    // Unlike HEAD-only CI, history includes previous versions of now-deleted credentials.
    // This is an OPT-IN operator audit: commit history can be large, and all refs must be fetched.
    $list = $git(['rev-list', '--objects', '--all']);
    if (!$list['ok'] || $list['data'] === '') {
        $fail('GIT_INVENTORY_UNAVAILABLE', '(history)');
    } else {
        $seen = [];
        $examined = 0;
        foreach (explode("\n", $list['data']) as $record) {
            if (preg_match('/^([0-9a-f]{40}) (.+)$/D', trim($record), $m) !== 1) {
                continue; // commit and tag objects are not source blobs
            }
            [, $sha, $path] = $m;
            $id = $sha . ':' . $path;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (++$examined > 50000) {
                $fail('HISTORY_INCOMPLETE', '(object limit)');
                break;
            }
            $type = $git(['cat-file', '-t', $sha]);
            if (!$type['ok']) {
                $fail('HISTORY_OBJECT_UNREADABLE', $path);
                continue;
            }
            if (trim($type['data']) !== 'blob') {
                continue; // tree object path, not content
            }
            $size = $git(['cat-file', '-s', $sha]);
            if (!$size['ok'] || !ctype_digit(trim($size['data']))) {
                $fail('HISTORY_OBJECT_UNREADABLE', $path);
                continue;
            }
            if ((int) trim($size['data']) > Guard::MAX_FILE_BYTES) {
                $inspect($path, str_repeat('x', Guard::MAX_FILE_BYTES + 1));
                continue;
            }
            $bytes = $git(['cat-file', 'blob', $sha]);
            if (!$bytes['ok']) {
                $fail('HISTORY_OBJECT_UNREADABLE', $path);
                continue;
            }
            $inspect($path, $bytes['data']);
        }
    }
}

usort($issues, static fn (array $a, array $b): int =>
    [$a['path'], $a['code']] <=> [$b['path'], $b['code']]);
$report = [
    'schema_version' => Guard::VERSION,
    'scope' => $scope,
    'status' => $complete && $issues === [] ? 'PASS' : 'FAIL',
    'complete' => $complete,
    'files_inspected' => $total,
    'known_owner_decisions_public' => $scope === 'history' ? 'HISTORICAL_BLOBS' : $publicDecisions,
    'finding_count' => count($issues),
    'findings' => array_slice($issues, 0, 64),
    'findings_truncated' => count($issues) > 64,
    'credential_values_emitted' => false,
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($report['status'] === 'PASS' ? 0 : 1);
