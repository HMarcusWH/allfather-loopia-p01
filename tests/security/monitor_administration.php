<?php
declare(strict_types=1);

/**
 * P09 Monitor administration over the real Kernel, offline.
 *
 *   /console/monitor/schedule   plan / propose / suspend / revoke a ScheduleService version
 *   /console/monitor/setup      plan / apply a MonitorSetup request, and read its receipt
 *   /console/api/v1/monitor/runs, /run   metadata-only JSON for the React console
 *
 * What must hold: the Kernel's authentication and CSRF gates come first; scope is the session's and
 * no form or query field can name a tenant, workspace or operator; the owner re-checks M02 for every
 * act; a proposal is bound to the digest the operator reviewed; nothing on the web ADMITS anything;
 * a GET fetches nothing, calls no model and writes no run, schedule or setup; the JSON run read
 * carries no candidate text, prompt, exact request or review assessment; another workspace's run
 * answers exactly like an unknown one.
 */

require_once __DIR__ . '/../support/ScheduleFixture.php';

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Core\{Clock,Ids};
use Allfather\Loopia\Http\{Csrf,Kernel,Request,View};
use Allfather\Loopia\Http\Controllers\{ConsoleReadController,MonitorScheduleController,MonitorSetupController};
use Allfather\Loopia\Http\ReadAdapters\{ConsoleContextAdapter,DecisionLedgerAdapter,MonitorReadAdapter};
use Allfather\Loopia\Product\P01\{Entitlements,Surfacing};
use Allfather\Loopia\Product\P02\{EntityTwins,RelationshipRelease,RelationshipTwins,TwinRelease};
use Allfather\Loopia\Product\P03\Release as P03Release;
use Allfather\Loopia\Product\P04\Release as P04Release;
use Allfather\Loopia\Product\P05\Release as P05Release;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance;
use Allfather\Loopia\Substrate\M12\Console;
use Allfather\Loopia\Substrate\M16\{P02Adapter,ReleaseOutput};
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,ScheduleFixture as S,Seed};
use Allfather\Loopia\Workflow\{MonitorSetup,ScheduleDispatcher};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock('2026-03-02T09:00:00.000Z');
$sx = S::create($db, $clock, ['Seminar in November.', 'Seminar in December.']);
$scope = $sx['scope'];
$schedules = $sx['schedules'];
$other = X::create($db, $clock);

// ---------------------------------------------------------------- composition, as index.php builds it
$access = new AccessGuard($db, $clock);
$ledger = new DecisionLedger($db, $clock);
$entitlements = new Entitlements($db, $clock);
$assurance = new Assurance($db, $clock, $ledger);
$p02Adapter = new P02Adapter($db, new ReleaseOutput($db, $clock, $ledger));
$reads = new ConsoleReadController(
    new ConsoleContextAdapter($db, new Console($db, $clock, $access, $ledger, $assurance), $assurance),
    new DecisionLedgerAdapter($db, $clock, $access, $ledger),
    new Surfacing($db, $access, $ledger, $entitlements),
    new TwinRelease($db, $clock, $ledger, new EntityTwins($db, $clock), $access, $entitlements, $p02Adapter),
    new RelationshipRelease($db, $clock, $ledger, new RelationshipTwins($db, $clock), $access, $entitlements, $p02Adapter),
    new P03Release($db, $clock, $ledger, $access, $entitlements),
    new P04Release($db, $clock, $ledger, $access, $entitlements),
    new P05Release($db, $clock, $ledger, $access, $entitlements),
    new MonitorReadAdapter(static fn (array $s) => $sx['service']),
);
$auth = new Authenticator($db, $clock);
$view = new View(dirname(__DIR__, 2) . '/app/Http/templates');
$kernel = new Kernel($db, $clock, $auth, $view);
$scheduleController = new MonitorScheduleController($schedules, $view);
$setupController = new MonitorSetupController(new MonitorSetup($db, $clock, $sx['evidence_store']), $view);
$kernel->route('GET', '/console/monitor/schedule', $scheduleController->show(...));
$kernel->route('POST', '/console/monitor/schedule', $scheduleController->submit(...));
$kernel->route('GET', '/console/monitor/setup', $setupController->show(...));
$kernel->route('POST', '/console/monitor/setup', $setupController->submit(...));
$kernel->route('GET', '/console/api/v1/monitor/runs', $reads->monitorRuns(...), 'JSON_401');
$kernel->route('GET', '/console/api/v1/monitor/run', $reads->monitorRun(...), 'JSON_401');

$call = static function (string $method, string $path, array $form = [], ?array $session = null, array $query = []) use ($kernel) {
    $cookies = $session === null ? [] : ['af_session' => $session['af_session'], Csrf::COOKIE_NAME => $session['csrf']];
    return $kernel->handle(Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '203.0.113.72'], $query, $form, $cookies));
};
$users = [];
$signIn = static function (string $operatorId) use ($db, $clock, $auth, $scope, &$users): array {
    $user = $users[$operatorId] ??= Seed::platformUser($db, $clock, $scope['tenant_id'], $operatorId, 'admin-' . substr($operatorId, 0, 8) . '@example.test');
    $tokens = $auth->authenticate($user['email'], $user['password'], '203.0.113.72', 'monitor-administration')->value();
    Assert::true($auth->setActiveWorkspace($tokens['session_id'], $scope['workspace_id'])->isOk(), 'the operator selects the workspace through the authenticator');
    return ['af_session' => $tokens['session_token'], 'csrf' => $tokens['csrf_token']];
};
$post = static fn (string $path, array $form, array $session) => $call('POST', $path, $form + ['csrf_token' => $session['csrf']], $session);
$counts = static fn (): array => array_map(static fn (string $t): int => (int) $db->scalar("SELECT COUNT(*) FROM {$t}"), array_combine($t = [
    'monitor_runs', 'monitor_run_events', 'provider_model_attempts', 'released_briefings', 'monitor_schedules', 'monitor_schedule_events', 'monitor_setup_receipts', 'jobs',
], $t));

// ---------------------------------------------------------------- a real run with a model candidate
$approvals = X::approveAll($sx, 'MANUAL_MONITOR');
X::admitted($sx, X::manualDefinition($db, $clock, $sx, $approvals));
$service = $sx['service'];
$baseline = X::value($service->startConfigured($scope, $sx['mission_id'], 'admin-baseline'));
X::value($service->advance($scope, $baseline['monitor_run_id']));
$clock->advanceDays(1);
$runId = X::value($service->startConfigured($scope, $sx['mission_id'], 'admin-changed'))['monitor_run_id'];
X::value($service->advance($scope, $runId));
Assert::same('DIFFED', X::value($service->advance($scope, $runId))['stage'], 'the fixture run reaches the inspection boundary');
$preview = X::value($service->detail($scope, $runId))['preview_request'];
Assert::same('ANALYZED', X::value($service->advance($scope, $runId, $preview['request_digest']))['stage'], 'and one inspected model call records a classifier candidate');
$foreignRun = X::value($other['service']->startConfigured($other['scope'], $other['mission_id'], 'foreign-run'))['monitor_run_id'];

$admin = $signIn($scope['operator_id']);
$before = $counts();

// ================================================================ authentication and CSRF come first

foreach (['/console/monitor/schedule', '/console/monitor/setup'] as $path) {
    $anonymous = $call('GET', $path);
    Assert::same([303, '/login'], [$anonymous->status, $anonymous->headers['Location'] ?? null], "{$path}: anonymous GET is sent to sign in");
    Assert::same(403, $call('POST', $path, ['action' => 'plan'], $admin)->status, "{$path}: a POST without the session CSRF token is refused");
    Assert::same(403, $call('POST', $path, ['action' => 'plan', 'csrf_token' => 'forged'], $admin)->status, "{$path}: and with a forged one");
}
foreach (['/console/api/v1/monitor/runs', '/console/api/v1/monitor/run'] as $path) {
    Assert::same(401, $call('GET', $path, [], null, ['run' => $runId])->status, "{$path}: anonymous JSON read is a 401");
}

// ================================================================ the JSON run reads are metadata only

$runs = json_decode($call('GET', '/console/api/v1/monitor/runs', [], $admin)->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same(['monitor.runs', 'VISIBLE'], [$runs['source'], $runs['owner_state']], 'the runs list is visible to the operator');
Assert::true(in_array($runId, array_column($runs['data']['rows'], 'monitor_run_id'), true), 'and lists the run');
Assert::false(in_array($foreignRun, array_column($runs['data']['rows'], 'monitor_run_id'), true), "and never another workspace's run");
$response = $call('GET', '/console/api/v1/monitor/run', [], $admin, ['run' => $runId, 'tenant_id' => $other['scope']['tenant_id'], 'workspace_id' => $other['scope']['workspace_id']]);
$run = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same([200, 'monitor.run', 'evidence.read', 'ANALYZED', 'REQUEST_INSPECTION', 'PASS'], [$response->status, $run['source'], $run['access']['required_permission'], $run['data']['run']['stage'], $run['data']['awaiting'], $run['data']['integrity']], 'the run read reports stage, what it waits for and journal integrity; query scope is ignored');
Assert::same(['classifier_request', 'classifier'], array_column($run['data']['artifacts'], 'kind'), 'artifacts are listed by kind');
Assert::same(64, strlen((string) $run['data']['artifacts'][1]['digest']), 'and digest');
Assert::same(['OPERATOR'], array_values(array_unique(array_column(array_column($run['data']['events'], 'actor'), 'type'))), 'every recorded act is an operator act here');
Assert::true($run['data']['request_preview_available'], 'it says an exact request is waiting to be inspected');
foreach (['seminar', 'Seminar', 'statement', 'candidate', 'instructions', 'request_digest', '"body"', 'assessment', 'prompt'] as $secret) {
    Assert::false(str_contains($response->body, $secret), "the JSON run read carries no candidate, prompt, request or review text ({$secret})");
}
Assert::same('/console/monitor?run=' . $runId, $run['data']['workbench_href'], 'it links to the scriptless workbench for the rest');

$foreign = $call('GET', '/console/api/v1/monitor/run', [], $admin, ['run' => $foreignRun]);
$unknown = $call('GET', '/console/api/v1/monitor/run', [], $admin, ['run' => Ids::new()]);
Assert::same([404, 404], [$foreign->status, $unknown->status], "another workspace's run and an unknown run are both NOT_FOUND");
Assert::same($unknown->body, $foreign->body, 'with byte-identical answers');
Assert::same(404, $call('GET', '/console/api/v1/monitor/run', [], $admin, ['run' => '../../etc/passwd'])->status, 'a malformed selector names nothing');

$reader = Seed::operator($db, $clock, $scope['tenant_id'], 'Mission reader');
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $reader, Seed::role($db, $clock, 'mission-reader-' . substr(Ids::new(), 0, 8), ['mission.read'])['role_id']);
$readerSession = $signIn($reader);
Assert::same(200, $call('GET', '/console/api/v1/monitor/runs', [], $readerSession)->status, 'mission.read is enough for the runs list');
$denied = json_decode($call('GET', '/console/api/v1/monitor/run', [], $readerSession, ['run' => $runId])->body, true, 512, JSON_THROW_ON_ERROR);
Assert::same(['DENIED', 'evidence.read', null], [$denied['access']['decision'], $denied['access']['required_permission'], $denied['data']], 'but run detail needs evidence.read, and a denial carries no data');

// ================================================================ schedule administration

$page = $call('GET', '/console/monitor/schedule', [], $admin);
Assert::same(200, $page->status, 'the schedule page renders for the operator');
Assert::contains($sx['scheduled_grant']['execution_grant_id'], $page->body, 'and offers the workspace SCHEDULED_OBSERVATION grant');
Assert::contains('Proposing is not admitting', $page->body, 'and says that proposing is not admitting');
foreach (['name="tenant_id"', 'name="workspace_id"', 'name="operator_id"', 'value="admit"', '<script'] as $absent) {
    Assert::false(str_contains($page->body, $absent), "the schedule page has no {$absent}");
}
$outsider = Seed::operator($db, $clock, $scope['tenant_id'], 'Member without Monitor reads');
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $outsider, Seed::role($db, $clock, 'no-monitor-' . substr(Ids::new(), 0, 8), ['decision.read'])['role_id']);
Assert::same(403, $call('GET', '/console/monitor/schedule', [], $signIn($outsider))->status, 'a workspace member without mission.read is refused');

$input = S::input($sx, $clock);
$form = [
    'execution_grant_id' => $input['execution_grant_id'], 'service_identity_id' => $input['service_identity_id'],
    'cadence_seconds' => (string) $input['cadence_seconds'], 'max_occurrences' => (string) $input['max_occurrences'],
    'valid_from' => $input['valid_from'], 'valid_until' => $input['valid_until'], 'monitor_schedule_id' => '',
    // Scope-shaped fields are ignored, not honoured.
    'tenant_id' => $other['scope']['tenant_id'], 'workspace_id' => $other['scope']['workspace_id'], 'operator_id' => $other['scope']['operator_id'],
];
$planned = $post('/console/monitor/schedule', $form + ['action' => 'plan'], $admin);
Assert::same(200, $planned->status, 'planning renders the reviewed definition');
Assert::true(preg_match('/name="schedule_digest" value="([a-f0-9]{64})"/', $planned->body, $m) === 1, 'and binds the propose form to its digest');
$digest = $m[1];
Assert::same(X::value($schedules->plan($scope, $input))['schedule_digest'], $digest, 'the digest shown is the one ScheduleService computes for the session scope');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_schedules'), 'planning wrote nothing');

$stale = $post('/console/monitor/schedule', $form + ['action' => 'propose', 'schedule_digest' => str_repeat('0', 64)], $admin);
Assert::same(409, $stale->status, 'a proposal carrying a digest the operator did not review is refused');
Assert::contains('SCHEDULE_PLAN_CHANGED', $stale->body, 'by name');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_schedules'), 'and records nothing');
$proposed = $post('/console/monitor/schedule', $form + ['action' => 'propose', 'schedule_digest' => $digest], $admin);
Assert::same(303, $proposed->status, 'proposing the reviewed version redirects');
Assert::true(preg_match('#^/console/monitor/schedule\?version=([0-9a-f-]{36})$#', (string) ($proposed->headers['Location'] ?? ''), $v) === 1, 'to the recorded version');
$versionId = $v[1];
$row = $db->one('SELECT tenant_id, workspace_id, proposed_by_operator_id, schedule_digest FROM monitor_schedules WHERE monitor_schedule_version_id = ?', [$versionId]);
Assert::same([$scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $digest], array_values($row), 'in the session scope, by the session operator, with exactly the reviewed digest');
Assert::same('PROPOSED', $schedules->status($versionId), 'it is only PROPOSED');
Assert::false(ScheduleDispatcher::admittedIn($schedules), 'and nothing is admitted');

$admitAttempt = $post('/console/monitor/schedule', ['action' => 'admit', 'schedule_version_id' => $versionId, 'decision' => 'docs/decisions/x.json', 'decision_sha256' => str_repeat('a', 64)], $admin);
Assert::same(409, $admitAttempt->status, 'there is no web admission');
Assert::contains('SCHEDULE_ACTION_INVALID', $admitAttempt->body, 'the action does not exist');
Assert::same('PROPOSED', $schedules->status($versionId), 'and the version is still only PROPOSED');
$selected = $call('GET', '/console/monitor/schedule', [], $admin, ['version' => $versionId]);
Assert::contains('php ops/monitor/schedule.php admit --schedule-version-id=' . $versionId, $selected->body, 'the page prints the private CLI admission command instead');

$mayRead = Seed::operator($db, $clock, $scope['tenant_id'], 'Reader who cannot administer');
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $mayRead, Seed::role($db, $clock, 'reader-only-' . substr(Ids::new(), 0, 8), ['mission.read'])['role_id']);
$readOnly = $signIn($mayRead);
Assert::same(200, $call('GET', '/console/monitor/schedule', [], $readOnly)->status, 'mission.read may look');
$deniedPlan = $post('/console/monitor/schedule', $form + ['action' => 'plan'], $readOnly);
Assert::same(409, $deniedPlan->status, 'but may not plan');
Assert::contains('SCHEDULE_ADMINISTRATION_DENIED', $deniedPlan->body, 'the owner refuses by name');
$deniedRevoke = $post('/console/monitor/schedule', ['action' => 'revoke', 'schedule_version_id' => $versionId, 'reason_code' => 'OPERATOR_STAND_DOWN'], $readOnly);
Assert::same([409, 'PROPOSED'], [$deniedRevoke->status, $schedules->status($versionId)], 'nor stand one down');

Assert::same(409, $post('/console/monitor/schedule', ['action' => 'revoke', 'schedule_version_id' => $versionId, 'reason_code' => 'BECAUSE I SAID SO'], $admin)->status, 'a stand-down reason outside the closed list is refused');
$revoked = $post('/console/monitor/schedule', ['action' => 'revoke', 'schedule_version_id' => $versionId, 'reason_code' => 'OPERATOR_STAND_DOWN'], $admin);
Assert::same([303, 'REVOKED'], [$revoked->status, $schedules->status($versionId)], 'the operator can revoke a version from the console');

// ================================================================ setup administration

$setupPage = $call('GET', '/console/monitor/setup', [], $admin);
Assert::same(200, $setupPage->status, 'the setup page renders');
Assert::contains('Setup grants nothing', $setupPage->body, 'and states its ceiling');
foreach (['name="approved_by_operator_id"', 'name="tenant_id"', 'name="workspace_id"', 'type="file"', 'name="api_key"', '<script'] as $absent) {
    Assert::false(str_contains($setupPage->body, $absent), "the setup page has no {$absent} (reviewer is the session operator; profiles and credentials stay CLI)");
}
$setupForm = [
    'setup_key' => 'admin-web-setup', 'successor_of' => '', 'mission_title' => 'Watch Rival pricing', 'mission_objective' => 'Observe the public pricing page for internal sales context.',
    'competitor_display_name' => 'Rival AB', 'competitor_class' => 'DIRECT', 'competitor_organization_number' => '',
    'source_url' => 'https://rival.example/pricing', 'source_name' => 'Rival pricing', 'license_class' => 'PUBLIC_WEB_ALLOWED', 'allowed_use_class' => 'evidence_capture',
    'derived_summary_allowed' => '1', 'customer_output_allowed' => '', 'caching_policy' => 'short_cache', 'retention_class' => 'standard',
    'review_approved_at' => Clock::format(Clock::parse($clock->now())->modify('-1 day')), 'review_evidence_reference' => 'ticket://review/1', 'review_evidence_sha256' => str_repeat('b', 64),
    'robots_policy_checked' => '1', 'terms_policy_checked' => '1', 'profile_evidence_ids' => Ids::new(), 'target_org_role' => 'SALES', 'audience' => '',
    'redaction_rules' => '', 'credential_id' => Ids::new(), 'max_input_bytes' => '32768', 'max_output_tokens' => '1024', 'max_model_calls' => '4', 'max_observation_age_seconds' => '604800',
    'approved_by_operator_id' => $other['scope']['operator_id'], 'tenant_id' => $other['scope']['tenant_id'],
];
$missing = $post('/console/monitor/setup', $setupForm + ['action' => 'plan'], $readOnly);
Assert::same(409, $missing->status, 'an operator without the setup roles cannot even plan');
Assert::contains('MONITOR_SETUP_DENIED', $missing->body, 'and is told which permissions are missing');
$setupRole = Seed::role($db, $clock, 'setup-' . substr(Ids::new(), 0, 8), MonitorSetup::PERMISSIONS);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $setupRole['role_id']);
$invalid = $post('/console/monitor/setup', ['setup_key' => 'Not A Key'] + $setupForm + ['action' => 'plan'], $admin);
Assert::same(409, $invalid->status, 'an invalid request is refused');
Assert::contains('setup_key', $invalid->body, 'naming the field');
$plan = $post('/console/monitor/setup', $setupForm + ['action' => 'plan'], $admin);
Assert::same(200, $plan->status, 'the form builds exactly the request MonitorSetup validates');
Assert::contains('SOURCE_REVIEW_ATTESTATION', $plan->body, 'and the plan lists each step');
Assert::contains('MONITOR_CREDENTIAL_NOT_AVAILABLE', $plan->body, 'with the reason a step refuses');
Assert::contains('cannot be applied', $plan->body, 'and a blocked plan offers no apply form');
Assert::false(str_contains($plan->body, 'value="apply"'), 'none at all');
$forged = $post('/console/monitor/setup', $setupForm + ['action' => 'apply', 'plan_digest' => str_repeat('c', 64)], $admin);
Assert::same(409, $forged->status, 'applying an unreviewed plan digest is refused');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_setup_receipts'), 'and nothing was set up');
Assert::same(409, $post('/console/monitor/setup', $setupForm + ['action' => 'apply'], $admin)->status, 'apply without a digest is refused');
Assert::same(404, $call('GET', '/console/monitor/setup', [], $admin, ['setup_key' => 'never-applied'])->status, 'an unknown setup key has no receipt');

// ================================================================ nothing above fetched, called or released

$after = $counts();
foreach (['monitor_runs', 'monitor_run_events', 'provider_model_attempts', 'released_briefings', 'monitor_setup_receipts', 'jobs'] as $table) {
    Assert::same($before[$table], $after[$table], "no page or read above wrote {$table}");
}
Assert::same([$before['monitor_schedules'] + 1, $before['monitor_schedule_events'] + 1], [$after['monitor_schedules'], $after['monitor_schedule_events']], 'the only writes were the one reviewed proposal and its one revocation');
