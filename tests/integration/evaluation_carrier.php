<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\{AccountService,Authenticator};
use Allfather\Loopia\Core\{Digest,Ids,Outcome};
use Allfather\Loopia\Evaluation\{EvaluationCarrier,EvaluationFactory,EvaluationManifest};
use Allfather\Loopia\Provider\{CredentialStore,ModelRegistration,ModelResponse,ModelTransport,PreparedModelRequest,SecretBox};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M02\Seed as Vocabulary;
use Allfather\Loopia\Substrate\M03\{Eligibility,Registry};
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\OwnerDecisionRegistry;

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$root = dirname(__DIR__, 2);
$scope = Seed::tenantWorkspace($db, $clock, 'evaluation-carrier');
$vocab = new Vocabulary($db, $clock);
$vocab->ensureVocabulary();
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));
$workspaceKey = (string) $db->scalar('SELECT workspace_key FROM workspaces WHERE workspace_id=?', [$scope['workspace_id']]);
$value = static function (Outcome $outcome): mixed {
    if (!$outcome->isOk()) { throw new RuntimeException('fixture owner refused: ' . $outcome->reasonCode()); }
    return $outcome->value();
};
foreach (['ROLE_TENANT_ADMIN', 'ROLE_WORKSPACE_OPERATOR', 'ROLE_COMPETITION_MONITOR'] as $role) {
    $value($accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $scope['operator_id'], $role));
}
$binding = ['target' => 'staging.invalid', 'release_ref' => str_repeat('c', 40), 'source_fingerprint' => str_repeat('a', 64)];
$carrier = new EvaluationCarrier($db, $clock, $binding);
$tables = array_column($db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name"), 't');
$snapshot = static function () use ($db, $tables): array {
    $result = [];
    foreach ($tables as $table) {
        $rows = array_map(static fn (array $row): string => Digest::canonicalDigest($row), $db->all('SELECT * FROM `' . str_replace('`', '``', $table) . '`'));
        sort($rows, SORT_STRING);
        $result[$table] = Digest::canonicalDigest($rows);
    }
    return $result;
};
$changed = static fn (array $before, array $after): array => array_keys(array_diff_assoc($after, $before));
$count = static fn (string $table): int => (int) $db->scalar('SELECT COUNT(*) FROM ' . $table);

Assert::case('carrier: shipped v1 has no implicit setup authority');
$before = $snapshot();
Assert::same('EVALUATION_CARRIER_SETUP_DENIED', $carrier->plan($scope)->reasonCode(), 'the actual shipped v1 seats cannot plan expanded setup');
Assert::same(['access_evaluation_records'], $changed($before, $snapshot()), 'a denied plan retains normal M02 audit only, not configuration');
$before = $snapshot();
Assert::same('EVALUATION_CARRIER_SCOPE_INVALID', $carrier->plan($scope + ['actor_type' => 'SERVICE'])->reasonCode(), 'a service actor cannot be treated as a human');
Assert::same($before, $snapshot(), 'malformed scope is refused without writes');
$value($accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $scope['operator_id'], 'ROLE_TENANT_ADMIN', 2));

Assert::case('carrier: reviewed plan and atomic existing-owner composition');
$before = $snapshot();
$plan = $value($carrier->plan($scope));
Assert::same('CREATE', $plan['carrier_action'], 'the first carrier is planned explicitly');
Assert::same('CREATE', $plan['descriptor_action'], 'the missing model descriptor is planned');
Assert::same([], $plan['definition']['source_scope'], 'synthetic evaluation invents no source rights');
Assert::same([], $plan['definition']['output_classes'], 'synthetic evaluation invents no product-output authority');
Assert::same(false, $plan['execution_authority_created'], 'a plan grants no native execution');
Assert::same($plan, $value($carrier->plan($scope)), 'allowed plans are stable and have no clock-only digest churn');
Assert::same($before, $snapshot(), 'allowed planning writes no table at all');
Assert::same('EVALUATION_CARRIER_PLAN_CHANGED', $carrier->apply($scope, str_repeat('0', 64))->reasonCode(), 'a forged digest refuses');
Assert::same($before, $snapshot(), 'a forged digest writes nothing');
$rolledBack = Assert::throws(function () use ($db, $carrier, $scope, $plan, $value): void {
    $db->transaction(function () use ($carrier, $scope, $plan, $value): void {
        $value($carrier->apply($scope, $plan['plan_digest']));
        throw new RuntimeException('intentional outer rollback');
    });
}, RuntimeException::class, 'a caller can roll back the whole nested composition');
Assert::same('intentional outer rollback', $rolledBack?->getMessage(), 'composition succeeded before the deliberately forced outer rollback');
Assert::same($before, $snapshot(), 'outer rollback restores every table including owner audit records');
$made = $value($carrier->apply($scope, $plan['plan_digest']));
Assert::same('SYNTHETIC_CARRIER_CONFIGURED', $made['status'], 'existing owners configure the carrier');
Assert::same($plan['plan_digest'], $made['reviewed_plan_digest'], 'the receipt preserves the approved before-state digest');
Assert::same([false, false, 0], [$made['execution_authority_created'], $made['prompt_approval_created'], $made['provider_calls']], 'configuration is not approval or invocation');
$writes = $changed($before, $snapshot());
$permittedWrites = ['access_evaluation_records', 'runtime_host_instances', 'runtime_adapter_instances', 'runtime_host_bindings', 'governed_tools', 'governed_tool_operations', 'runtime_host_tool_refs', 'missions', 'mission_status_transitions', 'valkyries', 'capability_contracts', 'forbidden_action_clauses', 'tool_rights', 'mission_assignments', 'valkyrie_authority_evaluations'];
Assert::same([], array_values(array_diff($writes, $permittedWrites)), 'the complete database diff contains only the fixed owner composition');
$governance = $made['governance'];
Assert::notSame($governance['author']['valkyrie_id'], $governance['critic']['valkyrie_id'], 'author and critic are distinct');
Assert::same($governance['author']['mission_id'], $governance['critic']['mission_id'], 'both assignments belong to the same evaluation mission');
Assert::same(1, $count('missions'), 'only one mission is made');
Assert::same(2, $count('mission_assignments'), 'only two assignments are made');
Assert::same('[]', $db->scalar('SELECT allowed_source_scope_ids FROM missions'), 'the mission has no source scope');
Assert::same('[]', $db->scalar('SELECT permitted_output_classes FROM missions'), 'the mission has no product-output scope');
Assert::same(0, (int) $db->scalar("SELECT COUNT(*) FROM mission_assignments WHERE assigned_output_classes <> '[]'"), 'neither assignment has product-output scope');
Assert::same('["ANALYZE"]', $db->scalar('SELECT requested_action_classes FROM missions'), 'mission ceiling is analyze only');
foreach ($governance as $command) {
    $eligible = (new Eligibility($db, $clock))->evaluateToolRight($command + ['requested_operation' => ModelRegistration::OPERATION_KEY, 'purpose' => 'MODEL_EVALUATION']);
    Assert::same('PERMITTED_BY_CONTRACT', $eligible['decision'], 'actual M03 eligibility accepts only the prepared model tool');
}
foreach (['output_class_bindings', 'provider_credentials', 'provider_model_attempts', 'monitor_execution_grants', 'monitor_runs', 'monitor_evaluation_runs', 'monitor_setup_receipts', 'competitors', 'source_license_cards', 'evidence_objects', 'released_briefings'] as $table) {
    Assert::same(0, $count($table), 'no incidental ' . $table . ' is created');
}

Assert::case('carrier: exact reuse, stale release and revoked authority');
$before = $snapshot();
$reuse = $value($carrier->plan($scope));
Assert::same('REUSE', $reuse['carrier_action'], 'the exact existing carrier is reusable');
Assert::same('ALREADY_CONFIGURED_MATCH', $value($carrier->apply($scope, $reuse['plan_digest']))['status'], 'explicitly reviewed reuse converges');
Assert::same($before, $snapshot(), 'reuse writes nothing');
$otherBinding = new EvaluationCarrier($db, $clock, array_replace($binding, ['source_fingerprint' => str_repeat('b', 64)]));
Assert::same('EVALUATION_CARRIER_PLAN_CHANGED', $otherBinding->apply($scope, $reuse['plan_digest'])->reasonCode(), 'changed source bytes require a new reviewed plan');
$otherTarget = new EvaluationCarrier($db, $clock, array_replace($binding, ['target' => 'other.invalid']));
Assert::same('EVALUATION_CARRIER_PLAN_CHANGED', $otherTarget->apply($scope, $reuse['plan_digest'])->reasonCode(), 'a digest is bound to its target');
$otherRelease = new EvaluationCarrier($db, $clock, array_replace($binding, ['release_ref' => str_repeat('d', 40)]));
Assert::same('EVALUATION_CARRIER_PLAN_CHANGED', $otherRelease->apply($scope, $reuse['plan_digest'])->reasonCode(), 'a digest is bound to its release');
Assert::same($before, $snapshot(), 'stale bindings do not rewrite state');
$v2 = $vocab->roleIdFor('ROLE_TENANT_ADMIN', 2);
$db->run("UPDATE workspace_memberships SET status='REVOKED' WHERE principal_id=? AND role_id=?", [$scope['operator_id'], $v2]);
Assert::same('EVALUATION_CARRIER_SETUP_DENIED', $carrier->apply($scope, $reuse['plan_digest'])->reasonCode(), 'even reuse rechecks current M02 authority');
// Negative fixture restoration only; the carrier contains no role or revival writer.
$db->run("UPDATE workspace_memberships SET status='ACTIVE' WHERE principal_id=? AND role_id=?", [$scope['operator_id'], $v2]);
$sibling = Seed::tenantWorkspace($db, $clock, 'carrier-foreign');
$foreign = array_replace($scope, ['workspace_id' => $sibling['workspace_id']]);
Assert::same('EVALUATION_CARRIER_SETUP_DENIED', $carrier->plan($foreign)->reasonCode(), 'a foreign selector is not a scope grant');

Assert::case('carrier: preparation cannot start model execution');
$transport = new class implements ModelTransport {
    public int $calls = 0;
    public function isLive(): bool { return false; }
    public function send(PreparedModelRequest $request, string $credential): ModelResponse {
        $this->calls++;
        throw new RuntimeException('the preparation test must not reach model transport');
    }
};
$storeRoot = sys_get_temp_dir() . '/carrier-test-' . Ids::new();
$store = new EvidenceStore($storeRoot);
$lane = EvaluationFactory::create($db, $clock, $store,
    new CredentialStore($db, $clock, SecretBox::fromMasterKey(base64_encode(random_bytes(32)))),
    $transport, OwnerDecisionRegistry::forRoot($root), $binding['source_fingerprint'], $value(EvaluationManifest::forRoot($root)));
Assert::same('EVALUATION_GRANT_NOT_FOUND', $lane['runner']->start($scope, Ids::new(), 'ALL', $governance, Ids::new())->reasonCode(), 'the real runner still requires a separately admitted evaluation grant');
Assert::same(0, $transport->calls, 'no provider invocation');
Assert::same(0, $count('monitor_evaluation_runs'), 'no evaluation run without its grant');
@rmdir($storeRoot);

Assert::case('carrier: actual private CLI and release-bound replay');
$envFile = Harness::childEnvFile(['APP_RELEASE_REF' => $binding['release_ref'], 'APP_BASE_URL' => 'https://staging.invalid']);
$cli = static function (string $action, array $extra = []) use ($root, $envFile, $scope, $binding): array {
    $argv = [PHP_BINARY, $root . '/ops/evaluation/carrier.php', $action, '--env=' . $envFile,
        '--tenant-id=' . $scope['tenant_id'], '--workspace-id=' . $scope['workspace_id'], '--operator-id=' . $scope['operator_id'],
        '--release-ref=' . ($extra['release-ref'] ?? $binding['release_ref'])];
    if (isset($extra['plan-digest'])) { $argv[] = '--plan-digest=' . $extra['plan-digest']; }
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('cannot start test CLI'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
try {
    $before = $snapshot();
    [$exit, $stdout] = $cli('plan');
    Assert::same(0, $exit, 'the actual private CLI can plan');
    $cliPlan = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR)['result'];
    Assert::same('REUSE', $cliPlan['carrier_action'], 'CLI resolves the same existing carrier');
    [$exit, $stdout] = $cli('apply', ['plan-digest' => $cliPlan['plan_digest']]);
    Assert::same(0, $exit, 'CLI applies the exact reviewed digest');
    Assert::same('ALREADY_CONFIGURED_MATCH', json_decode($stdout, true, 32, JSON_THROW_ON_ERROR)['result']['status'], 'CLI replay does not create duplicates');
    [$exit] = $cli('apply', ['plan-digest' => str_repeat('0', 64)]);
    Assert::same(1, $exit, 'CLI rejects a forged digest');
    [$exit] = $cli('plan', ['release-ref' => str_repeat('d', 40)]);
    Assert::same(2, $exit, 'CLI rejects a release different from its private configuration');
    Assert::same($before, $snapshot(), 'CLI plan/reuse and stale-refusal paths leave all tables unchanged');
} finally { unlink($envFile); }

Assert::case('carrier: no repair or silent revival of incompatible existing state');
$value((new Registry($db, $clock))->changeStatus($governance['critic']['valkyrie_id'], 'REVOKED', 'SYNTHETIC_REFUSAL_TEST'));
$before = $snapshot();
Assert::same('EVALUATION_CARRIER_STATE_CONFLICT', $carrier->plan($scope)->reasonCode(), 'a revoked critic refuses reuse');
Assert::same('EVALUATION_CARRIER_STATE_CONFLICT', $carrier->apply($scope, $reuse['plan_digest'])->reasonCode(), 'apply cannot recreate or revive it');
Assert::same($before, $snapshot(), 'refusal preserves all revoked state');
$source = (string) file_get_contents($root . '/app/Evaluation/EvaluationCarrier.php');
foreach (["->insert(", "->run(", 'CurlModelTransport', 'FetchTransport', 'SourceReadRegistration', 'PromptApprovalStore', 'ExecutionGrantStore', 'ReleaseStages', 'bindOutputClass'] as $absent) {
    Assert::false(str_contains($source, $absent), 'carrier writes only through existing owners and contains no ' . $absent);
}
$cliSource = (string) file_get_contents($root . '/ops/evaluation/carrier.php');
Assert::contains('BetaCli::connect($env, $fail)', $cliSource, 'CLI uses the normal process-lifetime maintenance lease');
Assert::false(str_contains($cliSource, 'allowDuringMaintenance'), 'CLI has no maintenance bypass');
