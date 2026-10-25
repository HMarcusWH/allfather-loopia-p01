<?php

declare(strict_types=1);

/**
 * Mandatory CI-FIX-06 current-tree and, when available, Git-index exposure gate.
 * Safe in a GitHub checkout and in the deployment-shaped rsync rehearsal without .git.
 */
use Allfather\Loopia\Ops\PublicExposureAudit as Exposure;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$report = Exposure::auditTree($root);
Assert::true($report['files'] > 100, 'exposure gate must inspect more than an empty skeleton');
Assert::same(4, $report['decisions'], 'all four reviewed owner decisions remain in the export');
Assert::same([], $report['findings'], 'public source contains no known token or unsafe tracked path');

// A source checkout has a real Git index. The root rsync rehearsal deliberately has only
// a fake .git/config sentinel; don't read host runtime .env or protected storage there.
if (is_file($root . '/.git/HEAD')) {
    $proc = proc_open(['git', '-C', $root, 'ls-files', '-z'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    Assert::true(is_resource($proc), 'the Git index must be independently readable');
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $listing = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]); // do not print paths or matched values from stderr
        fclose($pipes[2]);
        Assert::same(0, proc_close($proc), 'Git index enumeration must complete');
        $hits = [];
        $tracked = 0;
        foreach (explode("\0", is_string($listing) ? $listing : '') as $path) {
            if ($path === '') {
                continue;
            }
            $tracked++;
            $file = $root . '/' . $path;
            $bytes = is_file($file) && !is_link($file) ? file_get_contents($file) : false;
            if ($bytes === false) {
                $hits[] = ['code' => 'GIT_TRACKED_SOURCE_UNREADABLE', 'path' => $path, 'line' => 0];
            } else {
                array_push($hits, ...Exposure::inspect($path, $bytes));
            }
        }
        Assert::true($tracked > 100, 'tracked source inventory is not empty');
        Assert::same([], $hits, 'every Git-tracked path and blob passes public exposure checks');
    }
}

// All negative fixtures are constructed at runtime, not committed as full token literals.
$openai = 'sk-' . 'proj-' . str_repeat('Q', 36);
Assert::true(in_array('OPENAI_API_TOKEN',
    array_column(Exposure::inspect('docs/fixture.txt', $openai), 'code'), true),
    'an OpenAI token cannot enter source');
$github = 'ghp_' . str_repeat('G', 36);
Assert::true(in_array('GITHUB_ACCESS_TOKEN',
    array_column(Exposure::inspect('README.md', $github), 'code'), true),
    'a GitHub access token cannot enter source');
$pem = '-----BEGIN ' . 'PRIVATE KEY-----';
Assert::true(in_array('PRIVATE_KEY_BLOCK',
    array_column(Exposure::inspect('docs/private.md', $pem), 'code'), true),
    'PEM private-key material cannot enter source');
foreach (['.env', '.env.production', '.private-ops/policy.json', 'credentials.json',
          'secret.pem', 'storage/runtime.log', '.npmrc'] as $path) {
    Assert::true(in_array('PRIVATE_MATERIAL_TRACKED_PATH',
        array_column(Exposure::inspect($path, 'sample'), 'code'), true),
        "{$path} must not be tracked as public material");
}
Assert::same([], Exposure::inspect('.env.example', "DB_PASSWORD=\nAPP_MASTER_KEY=\nSCHEDULER_TOKEN=\n"),
    'empty credential fields in the tracked env template are acceptable');
Assert::true(in_array('NONEMPTY_SECRET_IN_ENV_TEMPLATE',
    array_column(Exposure::inspect('.env.example', 'DB_PASSWORD=nonempty'), 'code'), true),
    'a populated example password is refused');
$newDecision = '{"kind":"OWNER_EXECUTION_DECISION","decision_id":"TEST","authorizes":{}}';
Assert::true(in_array('UNREVIEWED_OWNER_DECISION_PATH',
    array_column(Exposure::inspect('docs/decisions/unreviewed.json', $newDecision), 'code'), true),
    'new owner-decision paths require separate review');
Assert::same([], Exposure::inspect('docs/readme.md', 'a public UUID and SHA-256 digest are not API credentials'),
    'ordinary technical metadata remains allowed');
Assert::true(in_array('BINARY_SOURCE_UNINSPECTED',
    array_column(Exposure::inspect('data/source.bin', "\0ABC"), 'code'), true),
    'uninspected binary tracked payload is refused');
Assert::true(in_array('SOURCE_TOO_LARGE_TO_INSPECT',
    array_column(Exposure::inspect('data/oversize.txt', str_repeat('a', Exposure::MAX_FILE_BYTES + 1)), 'code'), true),
    'uninspected large source is refused');
