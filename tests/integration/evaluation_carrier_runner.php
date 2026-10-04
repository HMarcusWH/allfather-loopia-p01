<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\{AccountService,Authenticator};
use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Evaluation\{EvaluationCarrier,EvaluationFactory,EvaluationManifest,EvaluationReport};
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Provider\{CredentialStore,ModelPolicy,SecretBox};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M02\Seed as Vocabulary;
use Allfather\Loopia\Tests\{Assert,EvaluationFixture,ExecutionFixture as X,Harness,LiveFlaggedFixtureTransport,Seed};
use Allfather\Loopia\Workflow\OwnerDecisionRegistry;

require_once __DIR__ . '/../support/EvaluationFixture.php';
$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'carrier-real-runner');
(new Vocabulary($db, $clock))->ensureVocabulary();
$accounts = new AccountService($db, $clock, new Authenticator($db, $clock));
$key = (string) $db->scalar('SELECT workspace_key FROM workspaces WHERE workspace_id=?', [$scope['workspace_id']]);
foreach (['ROLE_TENANT_ADMIN' => 2, 'ROLE_WORKSPACE_OPERATOR' => 1, 'ROLE_COMPETITION_MONITOR' => 1] as $role => $version) {
    X::value($accounts->addRoleMembership($scope['tenant_id'], $key, $scope['operator_id'], $role, $version));
}

Assert::case('carrier runner: supported preparation grants no execution or product output');
$carrier = new EvaluationCarrier($db, $clock, [
    'target' => 'staging.invalid', 'release_ref' => str_repeat('c', 40), 'source_fingerprint' => X::FINGERPRINT,
]);
$plan = X::value($carrier->plan($scope));
$configured = X::value($carrier->apply($scope, $plan['plan_digest']));
$governance = $configured['governance'];
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_execution_grants'), 'carrier alone creates no grant');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM output_class_bindings'), 'carrier has no product-output contract');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM provider_credentials'), 'carrier supplies no credential');

// The following separate test-only acts stand in for explicit operator preparation and reviewed
// authority. They use real owners and a PRIVATE TEMP decision directory, never docs/decisions.
// This fake live-flagged transport exercises admission and M06/M01 but never opens a socket. None
// of these test observations is real model quality, live activation or ROAD_TEST evidence.
$entitlements = new Entitlements($db, $clock);
$tier = X::value($entitlements->defineTier('evaluation-test-' . Ids::new(), 'Synthetic evaluation test', 0, ['competition_monitoring.read']));
X::value($entitlements->subscribe($scope['tenant_id'], $tier['subscription_tier_id']));
$credentials = new CredentialStore($db, $clock, SecretBox::fromMasterKey(base64_encode(random_bytes(32))));
$credential = X::value($credentials->save($scope, 'synthetic-carrier-test-credential-not-a-real-api-key'))['provider_credential_id'];
$dir = sys_get_temp_dir() . '/carrier-runner-test-' . Ids::new();
mkdir($dir, 0700, true);
mkdir($dir . '/decisions', 0700);
$store = new EvidenceStore($dir . '/storage');
$manifest = X::value(EvaluationManifest::forRoot(dirname(__DIR__, 2)));
$transport = new LiveFlaggedFixtureTransport(EvaluationFixture::wellBehaved());
$decisions = new OwnerDecisionRegistry($dir . '/decisions');
$lane = EvaluationFactory::create($db, $clock, $store, $credentials, $transport, $decisions, X::FINGERPRINT, $manifest);
$fx = $lane + ['scope' => $scope, 'decision_dir' => $dir . '/decisions'];
try {
    $approvals = X::approveAll($fx, 'MODEL_EVALUATION');
    $from = Clock::parse($clock->now());
    $definition = [
        'purpose' => 'MODEL_EVALUATION', 'mission_id' => null, 'definition_digest' => null,
        'evaluation_manifest_sha256' => $manifest->sha256, 'principal_operator_id' => $scope['operator_id'],
        'prompt_approval_ids' => $approvals, 'model_policy_id' => ModelPolicy::ID, 'model_policy_digest' => ModelPolicy::digest(),
        'credential_id' => $credential,
        'credential_digest' => $db->scalar('SELECT credential_digest FROM provider_credentials WHERE provider_credential_id=?', [$credential]),
        'source_scope' => null, 'target_org_role' => null,
        'limits' => ['max_model_attempts' => 18, 'max_source_captures' => 0],
        'valid_from' => Clock::format($from->modify('-1 minute')), 'valid_until' => Clock::format($from->modify('+1 day')),
        'disclosure_scope' => 'Frozen synthetic manifest only, fake transport, no network.',
        'retention_scope' => 'Disposable CI database and temporary test files only.',
        'application_fingerprint' => X::FINGERPRINT,
    ];
    $grant = X::admitted($fx, $definition);
    $grantId = $grant['execution_grant_id'];
    $runner = $lane['runner'];
    $run = X::value($runner->start($scope, $grantId, 'ALL', $governance, $credential));
    $runId = $run['evaluation_run_id'];
    $preview = X::value($runner->preview($scope, $runId, $governance));
    Assert::same(18, count($preview), 'all frozen cases have actual governed request previews');
    Assert::same(0, $transport->calls, 'preparation, admission and preview send nothing');
    Assert::same(0, $lane['grants']->consumed($grantId, 'MODEL_ATTEMPT'), 'preview consumes no attempt budget');
    foreach ($preview as $case) {
        Assert::same(64, strlen($case['request_digest']), 'each actual request has its digest');
        Assert::same('SYNTHETIC_EVALUATION_CASE', $case['request']['provenance']->context->evaluation->material_provenance, 'request provenance remains synthetic');
    }
    $executed = X::value($runner->execute($scope, $runId, $governance, $credential));
    Assert::same(18, $executed['recorded'], 'the model-only carrier executes every case through real owners');
    Assert::same(18, $transport->calls, 'one fake transport call per case');
    Assert::same(18, $lane['grants']->consumed($grantId, 'MODEL_ATTEMPT'), 'every fake live attempt consumes exact granted budget');
    Assert::same(18, (int) $db->scalar("SELECT COUNT(*) FROM monitor_evaluation_results WHERE evaluation_run_id=? AND outcome='CANDIDATE'", [$runId]), 'all case candidates pass transport and parser governance');
    Assert::same(0, X::value($runner->execute($scope, $runId, $governance, $credential))['recorded'], 'repeat execution records no duplicate result');
    Assert::same(18, $transport->calls, 'repeat execution does not spend again');
    $report = X::value($runner->report($scope, $runId));
    Assert::same(EvaluationReport::INCOMPLETE, $report['verdict'], 'no human assessments are invented to accept a run');
    Assert::same(false, $report['approves_prompts'], 'mechanical execution does not approve product prompts');
    foreach (['output_class_bindings', 'source_license_cards', 'source_snapshots', 'monitor_runs', 'released_briefings', 'monitor_setup_receipts'] as $table) {
        Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM ' . $table), 'model-only evaluation creates no ' . $table);
    }
    X::value($lane['grants']->revoke($scope, $grantId, 'SYNTHETIC_TEST_COMPLETE'));
    Assert::same('REVOKED', $lane['grants']->status($grantId), 'the synthetic test grant is stood down');
    Assert::same('EXECUTION_GRANT_NOT_ADMITTED', $runner->start($scope, $grantId, 'ALL', $governance, $credential)->reasonCode(), 'preparation does not override revoked execution authority');
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') { $remove($path . '/' . $entry); }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    };
    $remove($dir);
}
