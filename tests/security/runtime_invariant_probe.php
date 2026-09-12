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
Assert::same('PASS', $first['access_evaluation_append_only'] ?? null, 'the exact qualification audit record cannot be deleted');
Assert::same(false, $first['probe_record_reused'] ?? null, 'the first run appends one durable non-authorizing qualification marker');

Assert::same(
    0,
    (int) $db->scalar('SELECT external_side_effects_allowed FROM workspaces WHERE workspace_id = ?', [$scope['workspace_id']]),
    'the failed unsafe UPDATE left the workspace value false',
);
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM access_evaluation_records WHERE workspace_id = ? AND resource_type = ? AND reason_codes = ?',
        [
            $scope['workspace_id'],
            RuntimeInvariantProbe::RESOURCE_TYPE,
            json_encode([RuntimeInvariantProbe::REASON_CODE], JSON_THROW_ON_ERROR),
        ],
    ),
    'one append-only HOLD_FOR_REVIEW qualification marker remains after the refused delete',
);

$second = $probe->run((string) $keys['tenant_key'], (string) $keys['workspace_key']);
Assert::same('RUNTIME_INVARIANTS_VERIFIED', $second['status'] ?? null, 'the runtime probe is safe to repeat');
Assert::same(true, $second['probe_record_reused'] ?? null, 'a repeat reuses the same qualification marker');
Assert::same(
    1,
    (int) $db->scalar(
        'SELECT COUNT(*) FROM access_evaluation_records WHERE workspace_id = ? AND resource_type = ?',
        [$scope['workspace_id'], RuntimeInvariantProbe::RESOURCE_TYPE],
    ),
    'repeat qualification does not grow append-only storage with duplicate markers',
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

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Ops/RuntimeInvariantProbe.php');
Assert::false(str_contains($source, 'DecisionLedger'), 'Ops qualification does not widen the canonical M10 caller surface');
Assert::false(str_contains($source, "'input_object_type'"), 'Ops qualification invents no M10 input-reference vocabulary');
