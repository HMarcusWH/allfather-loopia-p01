<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Tests\Assert;

// Real CLI, synthetic registry, no database or provider. These are not host observations.
$root = dirname(__DIR__, 2);
$tree = sys_get_temp_dir() . '/allfather-preflight-scope-' . Ids::new();
mkdir($tree . '/database/migrations', 0700, true);
$lock = (string) file_get_contents($root . '/upstream.lock.json');
file_put_contents($tree . '/upstream.lock.json', $lock);
$registry = json_decode((string) file_get_contents($root . '/deployment-evidence.json'), true, 512, JSON_THROW_ON_ERROR);
$registry['records'] = [];
$schema = "CHECK (environment_class IN ('TEST', 'DEMO'));\nCHECK (audience_class IN ('OPERATOR_ONLY', 'TENANT_INTERNAL') OR activation_permitted = 0);\n";
file_put_contents($tree . '/database/migrations/001_fixture.sql', $schema);
$release = str_repeat('c', 40);
$target = 'prod.example.test';
$kinds = ['CROSS_SITE_ISOLATION', 'CONFORMANCE', 'FULL_RESTORE_DRILL', 'KEY_ROTATION_DRILL'];
$record = static function (string $kind, array $overrides = []) use ($release, $target): array {
    return array_replace([
        'record_id' => 'BASE_' . $kind,
        'kind' => $kind,
        'result' => 'PASS',
        'environment' => 'production',
        'target' => $target,
        'release_ref' => $release,
        'recorded_at' => '2026-09-01T12:00:00.000Z',
        'evidence_ref' => 'docs/deployment-evidence/synthetic.json',
        'evidence_sha256' => str_repeat('d', 64),
    ], $overrides);
};
$base = array_map($record, $kinds);
$run = static function (array $records, array $selectors = []) use ($root, $tree, $registry, $lock): array {
    $r = $registry;
    $r['records'] = $records;
    $bytes = json_encode($r, JSON_THROW_ON_ERROR);
    file_put_contents($tree . '/deployment-evidence.json', $bytes);
    $beforeSchema = hash_file('sha256', $tree . '/database/migrations/001_fixture.sql');
    $process = proc_open([PHP_BINARY, $root . '/ops/deploy/production-preflight.php', '--root=' . $tree, ...$selectors], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start production-preflight fixture');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    Assert::same($bytes, (string) file_get_contents($tree . '/deployment-evidence.json'), 'preflight never writes the registry');
    Assert::same($lock, (string) file_get_contents($tree . '/upstream.lock.json'), 'preflight never writes the authority lock');
    Assert::same($beforeSchema, hash_file('sha256', $tree . '/database/migrations/001_fixture.sql'), 'preflight never changes schema');
    $out = $stdout === '' ? [] : json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    if ($out !== []) {
        Assert::same(false, $out['grants_go'], 'preflight never grants GO');
        Assert::same(false, $out['customer_visibility_granted'], 'preflight never grants customer visibility');
        Assert::same('NOT_READY', $out['verdict'], 'host records cannot adopt customer identity or audience');
    }

    return [$code, array_column($out['checks'] ?? [], null, 'check'), $stderr];
};
$selectors = ['--release-ref=' . $release, '--target=' . $target];
$observation = static function (array $checks, string $kind): array {
    return match ($kind) {
        'CROSS_SITE_ISOLATION' => $checks['HOSTING_ISOLATION']['facts']['observation'],
        'CONFORMANCE' => $checks['PRODUCTION_RELEASE']['facts']['observation'],
        'FULL_RESTORE_DRILL' => $checks['RECOVERY_READINESS']['facts']['restore'],
        'KEY_ROTATION_DRILL' => $checks['RECOVERY_READINESS']['facts']['rotation'],
    };
};

try {
    [$code, $checks] = $run($base, $selectors);
    Assert::same(1, $code, 'even all matching host prerequisites cannot finish production');
    foreach (['HOSTING_ISOLATION', 'PRODUCTION_RELEASE', 'RECOVERY_READINESS'] as $name) {
        Assert::same('PASS', $checks[$name]['status'], 'exact host and release can satisfy ' . $name);
    }
    Assert::same('CUSTOMER_AUDIENCE_NOT_ADOPTED', $checks['CUSTOMER_AUDIENCE']['reason_code'], 'recognized internal-only schema remains restrictive');

    foreach ([['--release-ref=' . $release], ['--target=' . $target]] as $missing) {
        [, $checks] = $run($base, $missing);
        $reason = str_starts_with($missing[0], '--release-ref=') ? 'TARGET_REQUIRED' : 'RELEASE_REF_REQUIRED';
        foreach ($kinds as $kind) {
            Assert::same($reason, $observation($checks, $kind)['reason_code'], 'no implicit target or release for ' . $kind);
        }
    }
    [$code, , $stderr] = $run($base, ['--release-ref=' . $release, '--target=https://secret:secret@prod.example.test/path']);
    Assert::same(2, $code, 'a URL is not an exact host selector');
    Assert::false(str_contains($stderr, 'secret'), 'invalid selector diagnostics do not echo credentials');

    foreach ($kinds as $kind) {
        foreach ([['target' => 'other.example.test'], ['release_ref' => str_repeat('e', 40)], ['environment' => 'staging']] as $mismatch) {
            $records = array_map(static fn (array $r): array => $r['kind'] === $kind ? array_replace($r, $mismatch) : $r, $base);
            [, $checks] = $run($records, $selectors);
            Assert::same(false, $observation($checks, $kind)['pass'], 'mismatched host/release/environment cannot qualify ' . $kind);
        }
        foreach (['FAIL', 'STAND_DOWN'] as $result) {
            $newer = $record($kind, ['record_id' => 'NEW_' . $kind, 'result' => $result, 'recorded_at' => '2026-09-02T12:00:00.000Z']);
            [, $checks] = $run([...$base, $newer], $selectors);
            Assert::same('LATEST_OBSERVATION_NOT_PASS', $observation($checks, $kind)['reason_code'], 'later ' . $result . ' supersedes old PASS for ' . $kind);
        }
        $otherRelease = $record($kind, ['record_id' => 'NEW_' . $kind, 'release_ref' => str_repeat('e', 40), 'recorded_at' => '2026-09-02T12:00:00.000Z']);
        [, $checks] = $run([...$base, $otherRelease], $selectors);
        Assert::same('LATEST_OBSERVATION_RELEASE_MISMATCH', $observation($checks, $kind)['reason_code'], 'cannot revive a stale release observation for ' . $kind);

        $tie = $record($kind, ['record_id' => 'TIE_' . $kind]);
        foreach ([[...$base, $tie], array_reverse([...$base, $tie])] as $records) {
            [, $checks] = $run($records, $selectors);
            Assert::same('AMBIGUOUS_LATEST_OBSERVATION', $observation($checks, $kind)['reason_code'], 'tied latest observations refuse independently of input order');
        }
        $newer = $record($kind, ['record_id' => 'LATER_' . $kind, 'recorded_at' => '2026-09-03T12:00:00.000Z']);
        [, $checks] = $run([...$base, $tie, $newer], $selectors);
        Assert::same(true, $observation($checks, $kind)['pass'], 'an unambiguous later observation supersedes an older tie');

        foreach ([['target' => 'other.example.test'], ['environment' => 'staging']] as $unrelated) {
            $newer = $record($kind, $unrelated + ['record_id' => 'OTHER_' . $kind, 'result' => 'FAIL', 'recorded_at' => '2026-09-02T12:00:00.000Z']);
            [, $checks] = $run([...$base, $newer], $selectors);
            Assert::same(true, $observation($checks, $kind)['pass'], 'another host/environment cannot change the selected production observation');
        }
    }

    [, $checks] = $run([...$base, $record('CONFORMANCE', ['record_id' => 'BAD_TIME', 'recorded_at' => 'not-a-time'])], $selectors);
    foreach ($kinds as $kind) {
        Assert::same('EVIDENCE_REGISTRY_INVALID', $observation($checks, $kind)['reason_code'], 'invalid registry cannot satisfy any host prerequisite');
    }
    foreach (['', "CHECK (environment_class IN ('PRODUCTION'));\n", '-- unknown schema contract'] as $unknown) {
        file_put_contents($tree . '/database/migrations/001_fixture.sql', $unknown);
        [, $checks] = $run($base, $selectors);
        Assert::same('FAIL', $checks['CUSTOMER_AUDIENCE']['status'], 'missing restriction is not customer-audience adoption');
        Assert::same('CUSTOMER_AUDIENCE_SCHEMA_UNRECOGNIZED', $checks['CUSTOMER_AUDIENCE']['reason_code'], 'unknown schema reports unknown explicitly');
        Assert::same('UNKNOWN', $checks['CUSTOMER_AUDIENCE']['facts']['activatable_audiences'], 'no inferred unrestricted audience');
    }
} finally {
    @unlink($tree . '/upstream.lock.json');
    @unlink($tree . '/deployment-evidence.json');
    @unlink($tree . '/database/migrations/001_fixture.sql');
    @rmdir($tree . '/database/migrations');
    @rmdir($tree . '/database');
    @rmdir($tree);
}
