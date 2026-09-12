<?php

declare(strict_types=1);

use Allfather\Loopia\Ops\RuntimeInvariantProbe;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'runtime-probe');
$keys = $db->one(
    'SELECT t.tenant_key, w.workspace_key
     FROM tenants t JOIN workspaces w ON w.tenant_id = t.tenant_id
     WHERE t.tenant_id = ? AND w.workspace_id = ?',
    [$scope['tenant_id'], $scope['workspace_id']],
);
Assert::true($keys !== null, 'the seeded TEST scope has resolvable operator keys');

$probe = new RuntimeInvariantProbe($db, $clock);
$first = $probe->run((string) $keys['tenant_key'], (string) $keys['workspace_key']);
Assert::same('RUNTIME_INVARIANTS_VERIFIED', $first['status'] ?? null, 'the real migrated guards verify on the exact TEST scope');
Assert::same('PASS', $first['workspace_external_effect_guard'] ?? null, 'workspace external effects remain database-disabled');
Assert::same('PASS', $first['decision_record_append_only'] ?? null, 'the exact probe decision cannot be deleted');
Assert::same('PASS', $first['ledger_integrity'] ?? null, 'the M10 stream still verifies after the refused delete');
Assert::same(false, $first['probe_record_reused'] ?? null, 'the first run appends one durable replay-test finding');

Assert::same(
    0,
    (int) $db->scalar('SELECT external_side_effects_allowed FROM workspaces WHERE workspace_id = ?', [$scope['workspace_id']]),
    'the failed unsafe UPDATE left the workspace value false',
);
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM decision_records WHERE workspace_id = ? AND safe_decision_summary = ?',
        [$scope['workspace_id'], RuntimeInvariantProbe::SUMMARY],
    ),
    'one append-only qualification record remains as evidence of the exercised trigger',
);

$second = $probe->run((string) $keys['tenant_key'], (string) $keys['workspace_key']);
Assert::same('RUNTIME_INVARIANTS_VERIFIED', $second['status'] ?? null, 'the runtime probe is safe to repeat');
Assert::same(true, $second['probe_record_reused'] ?? null, 'a repeat reuses the same qualification record');
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM decision_records WHERE workspace_id = ? AND safe_decision_summary = ?',
        [$scope['workspace_id'], RuntimeInvariantProbe::SUMMARY],
    ),
    'repeat qualification does not grow the ledger with duplicate probe records',
);

$wrong = $probe->run((string) $keys['tenant_key'], 'no-such-workspace');
Assert::same('RUNTIME_INVARIANTS_FAILED', $wrong['status'] ?? null, 'an unknown scope fails closed');
Assert::equalsList(['RUNTIME_PROBE_SCOPE_NOT_FOUND'], $wrong['reason_codes'] ?? [], 'the refusal reveals no tenant data');

$entry = (string) file_get_contents(dirname(__DIR__, 2) . '/ops/verify-runtime-invariants.php');
Assert::true(
    strpos($entry, "PHP_SAPI !== 'cli'") < strpos($entry, "require __DIR__ . '/../app/autoload.php'"),
    'the host invariant command refuses web SAPI before autoload',
);
Assert::false(str_contains($entry, '--password'), 'the host invariant command has no secret-valued CLI flag');
