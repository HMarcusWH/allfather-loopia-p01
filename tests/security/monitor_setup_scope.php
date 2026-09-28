<?php
declare(strict_types=1);

/**
 * Setup is scoped to one tenant and workspace, and never handles a secret it could leak.
 *
 * Another workspace's profile, another tenant's key, card or routing target is never reused; a
 * successor can only name this workspace's latest receipt; mismatched operator identities refuse;
 * and the provider key travels only through stdin — never argv, a plan, an error or a report.
 */

require_once __DIR__ . '/../support/ExecutionFixture.php';

use Allfather\Loopia\Core\{Ids,OperatorScope};
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Provider\{CredentialStore,SecretBox};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,Seed};
use Allfather\Loopia\Workflow\{MissionConfiguration,MonitorSetup};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$root = dirname(__DIR__, 2);
$masterKey = base64_encode(random_bytes(32));
$box = SecretBox::fromMasterKey($masterKey);
$store = new EvidenceStore(sys_get_temp_dir() . '/allfather-setup-scope-' . Ids::new());
$setup = new MonitorSetup($db, $clock, $store);
$credentials = new CredentialStore($db, $clock, $box);
$entitlements = new Entitlements($db, $clock);
$tier = null;
$tenant = static function (string $name) use ($db, $clock, $setup, $credentials, $entitlements, &$tier): array {
    $scope = Seed::tenantWorkspace($db, $clock, $name);
    $role = Seed::role($db, $clock, 'setup-scope-' . substr(Ids::new(), 0, 8), [...MonitorSetup::PERMISSIONS, 'tool.invoke', 'competition_monitoring.read']);
    Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
    $tier ??= X::value($entitlements->defineTier('setup_scope_test', 'Setup scope test tier', 0, ['competition_monitoring.read']))['subscription_tier_id'];
    X::value($entitlements->subscribe($scope['tenant_id'], $tier, 'ACTIVE'));

    return $scope + ['profile' => X::value($setup->importProfile($scope, 'Owner profile of ' . $name))['evidence_id'], 'credential' => X::value($credentials->save($scope, 'sk-synthetic-' . $name, 'Monitor OpenAI', []))['provider_credential_id']];
};
$a = $tenant('setup-scope-a');
$b = $tenant('setup-scope-b');
$scopeOf = static fn (array $t): array => ['tenant_id' => $t['tenant_id'], 'workspace_id' => $t['workspace_id'], 'operator_id' => $t['operator_id']];
$request = static fn (string $profile, string $credential, string $reviewer, string $url = 'https://sources.example/shared-page'): array => [
    'setup_key' => 'scope-test', 'mission' => ['title' => 'Scope test', 'objective' => 'Observe one page.'],
    'competitor' => ['display_name' => 'Shared Rival AB', 'competitor_class' => 'DIRECT', 'organization_number' => null],
    'source' => ['url' => $url, 'source_name' => 'Shared page', 'license_class' => 'PUBLIC_WEB_ALLOWED', 'allowed_use_class' => 'derived_summary_allowed', 'customer_output_allowed' => true, 'derived_summary_allowed' => true, 'caching_policy' => 'standard_cache', 'retention_class' => 'standard',
        'review' => ['approved_by_operator_id' => $reviewer, 'approved_at' => '2026-01-01T00:00:00.000Z', 'evidence_reference' => 'offline note', 'evidence_sha256' => hash('sha256', 'scope source review'), 'robots_policy_checked' => true, 'terms_policy_checked' => true]],
    'profile_evidence_ids' => [$profile], 'target' => ['target_org_role' => 'SALES', 'redaction_rules' => []], 'credential_id' => $credential,
    'context_limits' => ['max_input_bytes' => 65536, 'max_output_tokens' => 2048, 'max_model_calls' => 4, 'max_observation_age_seconds' => 172800],
];
$steps = static fn (array $plan): array => array_column($plan['steps'], null, 'step');

// ---------------------------------------------------------------- B's objects exist; A's setup must not see them
$bPlan = X::value($setup->plan($scopeOf($b), $request($b['profile'], $b['credential'], $b['operator_id'])));
X::value($setup->apply($scopeOf($b), $request($b['profile'], $b['credential'], $b['operator_id']), $bPlan['plan_digest']));
(new Distribution($db, $clock, new DecisionLedger($db, $clock)))->configureRoleRedactionPolicy(['tenant_id' => $b['tenant_id'], 'workspace_id' => $b['workspace_id'], 'target_org_role' => 'SALES', 'redaction_rules' => [['field' => 'x', 'action' => 'REDACT']]]);

$foreignProfile = $steps(X::value($setup->plan($scopeOf($a), $request($b['profile'], $a['credential'], $a['operator_id']))));
Assert::same(['REFUSE', 'MONITOR_PROFILE_NOT_AVAILABLE'], [$foreignProfile['COMPANY_PROFILE']['action'], $foreignProfile['COMPANY_PROFILE']['reason_code']], "another tenant's profile evidence is refused");
$foreignKey = $steps(X::value($setup->plan($scopeOf($a), $request($a['profile'], $b['credential'], $a['operator_id']))));
Assert::same(['REFUSE', 'MONITOR_CREDENTIAL_NOT_AVAILABLE'], [$foreignKey['PROVIDER_CREDENTIAL']['action'], $foreignKey['PROVIDER_CREDENTIAL']['reason_code']], "another tenant's provider key is refused");
$foreignReviewer = $steps(X::value($setup->plan($scopeOf($a), $request($a['profile'], $a['credential'], $b['operator_id']))));
Assert::same(['REFUSE', 'SOURCE_REVIEW_OPERATOR_MISMATCH'], [$foreignReviewer['SOURCE_REVIEW_ATTESTATION']['action'], $foreignReviewer['SOURCE_REVIEW_ATTESTATION']['reason_code']], 'a source review cannot be attributed to another operator');
$own = $steps(X::value($setup->plan($scopeOf($a), $request($a['profile'], $a['credential'], $a['operator_id']))));
foreach (['COMPETITOR', 'SOURCE_CARD', 'MONITORED_SOURCE', 'DISTRIBUTION_TARGET', 'CRITIC', 'P01_REGISTRATION', 'MISSION_CONFIGURATION'] as $step) {
    Assert::same('CREATE', $own[$step]['action'], "{$step}: another tenant's object with the same name, URL or key is never reused");
}
Assert::same('REFUSE', $steps(X::value($setup->plan($scopeOf($a), $request($a['profile'], $a['credential'], $a['operator_id']), (string) $db->scalar('SELECT setup_receipt_id FROM monitor_setup_receipts WHERE tenant_id=?', [$b['tenant_id']]))))['MISSION_CONFIGURATION']['action'], "a successor cannot name another workspace's receipt");

// Same tenant, another workspace: the profile, target and receipts are workspace-scoped too.
$workspace2 = Ids::new();
$db->insert('workspaces', ['workspace_id' => $workspace2, 'tenant_id' => $a['tenant_id'], 'workspace_key' => 'ws-' . substr($workspace2, 0, 8), 'schema_version' => '1', 'display_name' => 'Second workspace', 'workspace_type' => 'TEST', 'environment_class' => 'TEST', 'external_side_effects_allowed' => 0, 'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE', 'status' => 'ACTIVE', 'created_at' => $clock->now()]);
$role2 = Seed::role($db, $clock, 'setup-scope-a2-' . substr(Ids::new(), 0, 8), MonitorSetup::PERMISSIONS);
Seed::membership($db, $clock, $a['tenant_id'], $workspace2, $a['operator_id'], $role2['role_id']);
$a2 = ['tenant_id' => $a['tenant_id'], 'workspace_id' => $workspace2, 'operator_id' => $a['operator_id']];
Assert::same('REFUSE', $steps(X::value($setup->plan($a2, $request($a['profile'], $a['credential'], $a['operator_id']))))['COMPANY_PROFILE']['action'], "another workspace's profile is refused even in the same tenant");

// ---------------------------------------------------------------- authority stays where it was granted
Assert::same('MONITOR_SETUP_DENIED', $setup->plan(['tenant_id' => $a['tenant_id'], 'workspace_id' => $a['workspace_id'], 'operator_id' => $b['operator_id']], $request($a['profile'], $a['credential'], $a['operator_id']))->reasonCode(), "B's operator holds no role in A");
Assert::same('MONITOR_SETUP_SCOPE_INVALID', $setup->plan($scopeOf($a) + ['actor_type' => 'OPERATOR', 'actor_id' => $b['operator_id']], $request($a['profile'], $a['credential'], $a['operator_id']))->reasonCode(), 'an operator_id and actor_id that disagree refuse');
Assert::same(null, OperatorScope::normalize($scopeOf($a) + ['actor_type' => 'SERVICE']), 'a non-operator actor is not an operator scope');
Assert::same('MONITOR_CONFIGURATION_SCOPE_INVALID', (new MissionConfiguration($db, $clock))->configure($scopeOf($a) + ['actor_id' => $b['operator_id']], [], 'NATIVE')->reasonCode(), 'configuration normalizes scope at its boundary');
Assert::same('PROVIDER_CREDENTIAL_MANAGEMENT_DENIED', $credentials->save($scopeOf($a) + ['actor_id' => $b['operator_id']], 'sk-never', 'x', [])->reasonCode(), 'and so does the credential store');
$bMission = (string) $db->scalar('SELECT mission_id FROM monitor_setup_receipts WHERE tenant_id=?', [$b['tenant_id']]);
$bDefinition = json_decode((string) $db->scalar('SELECT definition_json FROM monitor_mission_configs WHERE mission_id=?', [$bMission]), true);
Assert::same('MONITOR_MISSION_NOT_ACTIVE', (new MissionConfiguration($db, $clock))->validate($scopeOf($a), $bDefinition, 'NATIVE')->reasonCode(), "a stale or foreign mission binding is refused");

// ---------------------------------------------------------------- the secret never leaves stdin
$env = Harness::childEnvFile(['APP_MASTER_KEY' => $masterKey]);
$secret = 'sk-SECRET-' . bin2hex(random_bytes(12));
$cli = static function (array $args, string $stdin = '') use ($root): array {
    $process = proc_open([PHP_BINARY, $root . '/ops/provider/credential.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'out' => (string) $out, 'err' => (string) $err];
};
$ids = ['--env=' . $env, '--tenant-id=' . $a['tenant_id'], '--workspace-id=' . $a['workspace_id'], '--operator-id=' . $a['operator_id']];
$argv = $cli(['save', ...$ids, '--secret=' . $secret, '--acknowledge-rotation=none']);
Assert::same(2, $argv['exit'], 'a key on the command line is refused');
Assert::false(str_contains($argv['out'] . $argv['err'], $secret), 'and the refusal does not repeat it');
$noStdin = $cli(['save', ...$ids, '--acknowledge-rotation=none']);
Assert::same(2, $noStdin['exit'], 'save without --secret-stdin refuses');
$unacknowledged = $cli(['save', ...$ids, '--secret-stdin', '--acknowledge-rotation=none'], $secret . "\n");
Assert::same(1, $unacknowledged['exit'], 'a save that would rotate an unacknowledged key refuses');
Assert::contains('PROVIDER_CREDENTIAL_ROTATION_NOT_ACKNOWLEDGED', $unacknowledged['out'], 'with the reason');
Assert::contains($a['credential'], $unacknowledged['out'], 'naming the key it would rotate');
Assert::false(str_contains($unacknowledged['out'] . $unacknowledged['err'], $secret), 'and never the new secret');
$saved = $cli(['save', ...$ids, '--secret-stdin', '--acknowledge-rotation=' . $a['credential']], $secret . "\n");
Assert::same(0, $saved['exit'], 'an acknowledged save from stdin succeeds: ' . $saved['err']);
Assert::false(str_contains($saved['out'] . $saved['err'], $secret), 'the report never contains the secret');
Assert::false(str_contains($saved['out'], 'credential_digest'), 'nor the credential digest');
$newId = json_decode($saved['out'], true)['result']['provider_credential_id'];
Assert::same('ROTATED', $db->scalar('SELECT status FROM provider_credentials WHERE provider_credential_id=?', [$a['credential']]), 'the acknowledged key was rotated');
Assert::false(str_contains(json_encode($db->one('SELECT * FROM provider_credentials WHERE provider_credential_id=?', [$newId])), $secret), 'the stored row holds only sealed bytes');
$described = $cli(['describe', ...$ids]);
Assert::same(0, $described['exit'], 'describe works');
Assert::false(str_contains($described['out'], $secret) || str_contains($described['out'], 'ciphertext') || str_contains($described['out'], 'credential_digest'), 'describe reports identities and states only');
$plan = $setup->plan($scopeOf($a), $request($a['profile'], $newId, $a['operator_id']));
Assert::false(str_contains(json_encode($plan), $secret), 'a dry run never carries a secret');
Assert::false(str_contains(json_encode($setup->plan($scopeOf($a), ['credential_id' => $secret])), $secret), 'nor does a refused one echo request values');
foreach (['ops/monitor/setup.php', 'ops/monitor/import-profile.php', 'ops/provider/credential.php'] as $file) {
    $code = (string) file_get_contents($root . '/' . $file);
    Assert::true(str_starts_with(ltrim(substr($code, strpos($code, '*/') + 2)), "if (PHP_SAPI !== 'cli')"), "{$file} refuses to run outside the CLI");
    Assert::false(str_contains($code, '$_GET') || str_contains($code, '$_POST') || str_contains($code, 'getenv('), "{$file} reads no request or environment input");
}
unlink($env);
