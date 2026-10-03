<?php
declare(strict_types=1);

/**
 * Owner item 10: one SUCCESSFUL Monitor setup through the real web path, end to end and offline.
 *
 *   GET /login -> POST /login -> POST /console/workspace -> GET /console/monitor/setup
 *   -> POST plan -> (read the reviewed plan digest off the rendered page)
 *   -> POST apply carrying every field the page rendered -> 303 -> GET the receipt
 *
 * Every request goes through the Kernel with the cookies the previous response set (a small cookie
 * jar) and the CSRF token read from the page it came from — nothing is handed to a controller.
 *
 * INPUTS ARE PREPARED THE PRIVATE WAY, never through the web and never by SQL: the login through
 * AccountService::createLogin (ops/onboard-beta-user.php), the membership through
 * AccountService::addRoleMembership, the company profile through MonitorSetup::importProfile composed
 * as ops/monitor/import-profile.php composes it, and the provider key through CredentialStore::save
 * composed with KeyRing::fromEnv as ops/provider/credential.php composes it. Two acts have no
 * supported private writer and are stand-ins exactly as tests/integration/monitor_setup.php makes
 * them: the custom role carrying MonitorSetup::PERMISSIONS (roles are deploy-time vocabulary), and
 * the tenant's commercial subscription (Entitlements::defineTier/subscribe, the owner's billing act).
 *
 * WHAT IS PROVED, MECHANICALLY, from before/after row counts AND content digests:
 *   - GET and plan write nothing at all, in any table;
 *   - the tables whose row count changes on apply are EXACTLY those the reviewed plan's CREATE steps
 *     imply ($stepTables documents the mapping), plus the retained M02 checks every apply makes;
 *     a second, reuse-heavy setup proves the expectation follows the plan rather than a constant;
 *   - every new object row is one the receipt names, under a step the reviewed plan said CREATE;
 *   - every authority table is byte-identical: roles, permissions, memberships, operators, logins,
 *     service identities, tenant subscriptions, execution grants and their events/consumptions/
 *     admission decisions, prompt approvals and their events, exact-artifact approvals, provider
 *     credentials and rotation events, schedules, and the owner-provided profile evidence;
 *   - docs/decisions/ is unchanged, no execution purpose is admitted, and the deployed Monitor
 *     composition for this workspace still has no network leaf and no activated model transport;
 *   - the credential secret and the profile bytes appear in no response (body, header or cookie),
 *     in no request-log line, in the PHP error log, or in the stored receipt and configuration.
 *
 * Deployed-browser journeys (desktop and mobile, on staging) remain an operational gate this offline
 * test does not satisfy.
 */

use Allfather\Loopia\Auth\{AccountService,Authenticator};
use Allfather\Loopia\Core\{Ids,Outcome};
use Allfather\Loopia\Http\{Csrf,Kernel,Request,RequestId,RequestLog,Response,View};
use Allfather\Loopia\Http\Controllers\{MonitorSetupController,SessionController,WorkspaceController};
use Allfather\Loopia\Ingestion\{FixedHostResolver,NoNetworkTransport};
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\{CredentialStore,KeyRing};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M02\Seed as M02Seed;
use Allfather\Loopia\Support\Env;
use Allfather\Loopia\Tests\{Assert,Harness,Seed};
use Allfather\Loopia\Workflow\{ExecutionGrantStore,MonitorFactory,MonitorService,MonitorSetup,OwnerDecisionRegistry,ScheduleDispatcher};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$root = dirname(__DIR__, 2);
$clock = Harness::clock('2026-03-02T09:00:00.000Z');
$ok = static function (Outcome $outcome, string $what): array {
    if (!$outcome->isOk()) {
        throw new \RuntimeException("{$what} refused: " . $outcome->reasonCode());
    }
    return $outcome->value();
};

// ================================================================ the plan-step -> table mapping
//
// What each CREATE step writes, through which owner. A step that is REUSE writes nothing; the three
// steps setup can only ever REUSE (it never subscribes a tenant, imports a profile or saves a key)
// map to nothing. `$applyAlways` is the one write every apply makes whatever the plan says: the two
// retained M02 ALLOW records of the global descriptor checks (ModelRegistration::ensure and
// SourceReadRegistration::ensureBinding each record their runtime_host_binding.administer decision).
$stepTables = [
    'TENANT_ENTITLEMENT' => [],
    // ModelRegistration::ensure — the global bounded-model descriptor and its tool.
    'MODEL_DESCRIPTOR' => ['runtime_host_instances', 'runtime_adapter_instances', 'runtime_host_bindings', 'governed_tools', 'governed_tool_operations', 'runtime_host_tool_refs'],
    // SourceReadRegistration::ensureBinding — the global SAFE_READ_ONLY source-read descriptor.
    'SOURCE_READ_BINDING' => ['runtime_host_instances', 'runtime_adapter_instances', 'runtime_host_bindings'],
    // P01 Registration::registerCompetitionMonitor — the monitor Valkyrie, its contract and forbidden
    // clauses, the p01.source.read tool and right, the platform P01 catalogue tier (a tier
    // DEFINITION: it subscribes nobody, and tenant_subscriptions is an authority table below), the
    // registration receipt; then SourceReadRegistration::bindTool maps that tool onto the binding.
    'P01_REGISTRATION' => ['valkyries', 'capability_contracts', 'forbidden_action_clauses', 'governed_tools', 'governed_tool_operations', 'tool_rights', 'subscription_tiers', 'subscription_tier_permissions', 'p01_registration_receipts', 'runtime_host_tool_refs'],
    // M03 Eligibility::grantToolRight — the author's right on the model tool.
    'AUTHOR_MODEL_RIGHT' => ['tool_rights'],
    // M03 Registry::registerValkyrie + issueContract — the review critic.
    'CRITIC' => ['valkyries', 'capability_contracts'],
    'CRITIC_MODEL_RIGHT' => ['tool_rights'],
    // P01 Registry::registerCompetitor.
    'COMPETITOR' => ['competitors', 'competitor_status_transitions'],
    // P01 SourceCards::recordTermsReview — the operator's own attestation, append-only.
    'SOURCE_REVIEW_ATTESTATION' => ['source_terms_reviews'],
    // P01 SourceCards::project — the source reference and its licence card.
    'SOURCE_CARD' => ['source_references', 'source_license_cards'],
    // P01 Registry::registerMonitoredSource.
    'MONITORED_SOURCE' => ['monitored_sources'],
    'COMPANY_PROFILE' => [],
    // M08 PolicySets::seed.
    'P01_POLICY' => ['arbiter_policy_sets', 'arbiter_policy_rules'],
    'P04_POLICY' => ['arbiter_policy_sets', 'arbiter_policy_rules'],
    // P04 Distribution::configureRoleRedactionPolicy.
    'DISTRIBUTION_TARGET' => ['console_redaction_policy_projections'],
    'PROVIDER_CREDENTIAL' => [],
    // M04 createMission/activateMission/assignToMissionNarrowed (each assignment is an M03
    // eligibility evaluation), MissionConfiguration::configure, and the append-only receipt.
    'MISSION_CONFIGURATION' => ['missions', 'mission_status_transitions', 'mission_assignments', 'valkyrie_authority_evaluations', 'monitor_mission_configs', 'monitor_setup_receipts'],
];
$applyAlways = ['access_evaluation_records' => 2];
$expectedTables = static function (array $steps) use ($stepTables, $applyAlways): array {
    $out = array_keys($applyAlways);
    foreach ($steps as $step => $action) {
        Assert::true(array_key_exists($step, $stepTables), "the mapping documents plan step {$step}");
        if ($action === 'CREATE') {
            $out = [...$out, ...$stepTables[$step]];
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
};

// ================================================================ authority, as before/after digests
$authority = [
    'M02 roles and membership' => ['permissions', 'roles', 'role_permissions', 'workspace_memberships', 'data_scopes', 'operators', 'service_identities', 'platform_users'],
    'tenant entitlement' => ['tenant_subscriptions'],
    'execution grants' => ['monitor_execution_grants', 'monitor_execution_grant_events', 'monitor_grant_consumptions', 'monitor_admission_decisions', 'execution_authorizations', 'execution_authorization_consumptions', 'outreach_execution_authorizations'],
    'prompt approvals' => ['monitor_prompt_approvals', 'monitor_prompt_approval_events'],
    'exact-artifact approvals' => ['approval_requests', 'approval_decisions'],
    'provider credentials' => ['provider_credentials', 'provider_credential_rotation_events'],
    'recurrence' => ['monitor_schedules', 'monitor_schedule_events', 'monitor_schedule_occurrences'],
    'owner-provided profile evidence' => ['evidence_objects'],
];
$tables = array_values(array_map(
    static fn (array $r): string => (string) $r['t'],
    $db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name <> 'schema_migrations' ORDER BY table_name"),
));
foreach ($authority as $family => $names) {
    foreach ($names as $table) {
        Assert::true(in_array($table, $tables, true), "authority table {$table} ({$family}) exists in the migrated schema");
    }
}
foreach ($stepTables as $step => $names) {
    foreach ($names as $table) {
        Assert::true(in_array($table, $tables, true), "{$step} maps onto a real table ({$table})");
        foreach ($authority as $family => $authorityTables) {
            Assert::false(in_array($table, $authorityTables, true), "{$step} maps onto no authority table ({$family}: {$table})");
        }
    }
}
// The new object rows, by identity. Each entry is the SQL expression that names a row.
$objectKeys = [
    'valkyries' => 'valkyrie_id', 'capability_contracts' => 'capability_contract_id', 'governed_tools' => 'governed_tool_id',
    'runtime_host_bindings' => 'binding_id', 'tool_rights' => "CONCAT(capability_contract_id, '>', governed_tool_id)",
    'competitors' => 'competitor_id', 'source_references' => 'source_ref_id', 'source_license_cards' => 'source_license_card_id',
    'monitored_sources' => 'monitored_source_id', 'arbiter_policy_sets' => 'arbiter_policy_set_id',
    'console_redaction_policy_projections' => 'target_org_role', 'missions' => 'mission_id', 'mission_assignments' => "CONCAT(mission_id, '>', assignment_role)",
    'monitor_mission_configs' => 'mission_id', 'monitor_setup_receipts' => 'setup_receipt_id',
];
$decisionsDigest = static function () use ($root): string {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs/decisions', FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[str_replace($root . '/', '', $file->getPathname())] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
};
$requestLogState = static fn (): ?string => is_file(RequestLog::path()) ? hash_file('sha256', RequestLog::path()) : null;
$snapshot = static function () use ($db, $tables, $authority, $objectKeys, $decisionsDigest, $requestLogState): array {
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $db->scalar('SELECT COUNT(*) FROM ' . $table);
    }
    $digests = [];
    foreach (array_merge(...array_values($authority)) as $table) {
        $rows = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $db->all('SELECT * FROM ' . $table));
        sort($rows, SORT_STRING);
        $digests[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
    }
    $ids = [];
    foreach ($objectKeys as $table => $expression) {
        $ids[$table] = array_map(static fn (array $r): string => (string) $r['k'], $db->all("SELECT {$expression} AS k FROM {$table}"));
    }
    return ['counts' => $counts, 'authority' => $digests, 'ids' => $ids, 'decisions' => $decisionsDigest(), 'request_log' => $requestLogState()];
};
$changed = static function (array $before, array $after): array {
    $out = [];
    foreach ($after['counts'] as $table => $count) {
        if ($count !== $before['counts'][$table]) {
            $out[$table] = $count - $before['counts'][$table];
        }
    }
    ksort($out);
    return $out;
};
$newRows = static function (array $before, array $after, string $table): array {
    $remaining = $before['ids'][$table];
    $added = [];
    foreach ($after['ids'][$table] as $key) {
        $at = array_search($key, $remaining, true);
        if ($at === false) { $added[] = $key; } else { unset($remaining[$at]); }
    }
    sort($added);
    return $added;
};
$assertAuthorityUnchanged = static function (array $baseline, array $now, string $when) use ($authority): void {
    foreach ($authority as $family => $names) {
        foreach ($names as $table) {
            Assert::same($baseline['authority'][$table], $now['authority'][$table], "{$when}: {$family} unchanged ({$table}: same rows, same bytes)");
        }
    }
    Assert::same($baseline['decisions'], $now['decisions'], "{$when}: docs/decisions/ is byte-for-byte unchanged — no owner decision was written");
    Assert::same($baseline['request_log'], $now['request_log'], "{$when}: the private request log file is untouched");
};

// ================================================================ private preparation (never the web)
$scope = Seed::tenantWorkspace($db, $clock, 'setup-journey');
(new M02Seed($db, $clock))->ensureVocabulary();
$store = new EvidenceStore(sys_get_temp_dir() . '/allfather-setup-journey-' . Ids::new());
$setupRoleKey = 'setup-journey-' . substr(Ids::new(), 0, 8);
Seed::role($db, $clock, $setupRoleKey, MonitorSetup::PERMISSIONS);
$auth = new Authenticator($db, $clock);
$accounts = new AccountService($db, $clock, $auth);
$workspaceKey = (string) $db->scalar('SELECT workspace_key FROM workspaces WHERE workspace_id = ?', [$scope['workspace_id']]);
$ok($accounts->addRoleMembership($scope['tenant_id'], $workspaceKey, $scope['operator_id'], $setupRoleKey), 'the setup membership');
$password = 'journey passphrase ' . bin2hex(random_bytes(6));
$login = $ok($accounts->createLogin($scope['operator_id'], 'setup-journey@example.test', 'Setup Journey', $password, false), 'the operator login');
$entitlements = new Entitlements($db, $clock);
$tier = $ok($entitlements->defineTier('setup_journey', 'Setup journey tier', 0, ['competition_monitoring.read']), 'the monitoring tier');
$ok($entitlements->subscribe($scope['tenant_id'], $tier['subscription_tier_id'], 'ACTIVE'), 'the tenant subscription');

$profileSentinel = 'PROFILE-SENTINEL-' . bin2hex(random_bytes(8));
$profileBytes = "Owner-provided, unverified: we run event studios in Stockholm ({$profileSentinel}) and sell day packages.";
$profile = $ok((new MonitorSetup($db, $clock, $store))->importProfile($scope, $profileBytes), 'the profile import');
$keys = KeyRing::fromEnv(Env::fromArray(['APP_MASTER_KEY' => base64_encode(random_bytes(32))]));
$credentials = new CredentialStore($db, $clock, $keys);
$secret = 'sk-journey-' . bin2hex(random_bytes(20));
$credential = $ok($credentials->save($scope, $secret, 'Monitor OpenAI', []), 'the provider key');
// Positive controls: the needles below are real, so their absence later means something.
Assert::same($profileBytes, $store->get($profile['original_sha256'])->value(), 'the imported profile bytes are retrievable from the private evidence store');
Assert::same(true, $credentials->withCredential($scope['tenant_id'], $credential['provider_credential_id'], static fn (string $plain): bool => hash_equals($secret, $plain))->value(), 'the saved key opens to exactly the secret');
$needles = ['the provider key' => $secret, 'the provider key (base64)' => base64_encode($secret), 'the profile bytes' => $profileBytes, 'the profile sentinel' => $profileSentinel];

// ================================================================ the Kernel, as public_html/index.php composes it
$view = new View($root . '/app/Http/templates');
$kernel = new Kernel($db, $clock, $auth, $view);
$sessions = new SessionController($auth, $view);
$kernel->route('GET', '/login', $sessions->showLogin(...));
$kernel->route('POST', '/login', $sessions->login(...));
$workspaces = new WorkspaceController($db, $auth, $view);
$kernel->route('POST', '/console/workspace', $workspaces->select(...));
$setupController = new MonitorSetupController(static fn (): MonitorSetup => new MonitorSetup($db, $clock, $store), $view);
$kernel->route('GET', '/console/monitor/setup', $setupController->show(...));
$kernel->route('POST', '/console/monitor/setup', $setupController->submit(...));

$jar = [];
$transcript = [];
$send = static function (string $method, string $path, array $form = [], array $query = []) use ($kernel, &$jar, &$transcript): Response {
    $uri = $path . ($query === [] ? '' : '?' . http_build_query($query));
    $request = Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '198.51.100.31', 'HTTP_USER_AGENT' => 'setup-journey'], $query, $form, $jar);
    $response = $kernel->handle($request);
    foreach ([...(isset($response->headers['Set-Cookie']) ? [$response->headers['Set-Cookie']] : []), ...$response->cookies] as $set) {
        if (preg_match('/^([^=;\s]+)=([^;]*)/', $set, $cookie) === 1) {
            if ($cookie[2] === '' || stripos($set, 'Max-Age=0') !== false) { unset($jar[$cookie[1]]); } else { $jar[$cookie[1]] = $cookie[2]; }
        }
    }
    $transcript[] = ['request' => $request, 'response' => $response];
    return $response;
};
$xpath = static function (string $html): DOMXPath {
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($document);
};
/** @return array<string, string> every named control of the one form whose `action` input has this value */
$formFields = static function (DOMXPath $x, string $action): array {
    $forms = $x->query('//form[.//input[@name="action" and @value="' . $action . '"]]');
    Assert::same(1, $forms->length, "the page renders exactly one {$action} form");
    if ($forms->length !== 1) { return []; }
    $form = $forms->item(0);
    Assert::same(['post', '/console/monitor/setup'], [strtolower($form->getAttribute('method')), $form->getAttribute('action')], "the {$action} form posts back to the setup page");
    $fields = [];
    foreach ($x->query('.//input[@name] | .//textarea[@name] | .//select[@name]', $form) as $control) {
        $name = $control->getAttribute('name');
        $fields[$name] = match ($control->nodeName) {
            'textarea' => $control->textContent,
            'select' => (string) ($x->query('.//option[@selected]', $control)->item(0)?->getAttribute('value') ?? ''),
            default => $control->getAttribute('value'),
        };
    }
    return $fields;
};
/** @return array<string, array{action: string, id: string}> */
$stepRows = static function (DOMXPath $x, string $section): array {
    $rows = [];
    foreach ($x->query('//section[@aria-labelledby="' . $section . '"]//tbody/tr') as $tr) {
        $cells = $x->query('./td', $tr);
        $rows[trim($cells->item(0)->textContent)] = ['action' => trim($cells->item(1)->textContent), 'id' => trim($cells->item(2)->textContent)];
    }
    return $rows;
};
$formFor = static fn (array $r): array => [
    'setup_key' => $r['setup_key'], 'successor_of' => '',
    'mission_title' => $r['mission']['title'], 'mission_objective' => $r['mission']['objective'],
    'competitor_display_name' => $r['competitor']['display_name'], 'competitor_class' => $r['competitor']['competitor_class'], 'competitor_organization_number' => (string) ($r['competitor']['organization_number'] ?? ''),
    'source_url' => $r['source']['url'], 'source_name' => $r['source']['source_name'], 'license_class' => $r['source']['license_class'], 'allowed_use_class' => $r['source']['allowed_use_class'],
    'customer_output_allowed' => $r['source']['customer_output_allowed'] ? '1' : '', 'derived_summary_allowed' => $r['source']['derived_summary_allowed'] ? '1' : '',
    'caching_policy' => $r['source']['caching_policy'], 'retention_class' => $r['source']['retention_class'],
    'review_approved_at' => $r['source']['review']['approved_at'], 'review_evidence_reference' => $r['source']['review']['evidence_reference'], 'review_evidence_sha256' => $r['source']['review']['evidence_sha256'],
    'robots_policy_checked' => '1', 'terms_policy_checked' => '1',
    'profile_evidence_ids' => implode("\n", $r['profile_evidence_ids']), 'target_org_role' => $r['target']['target_org_role'], 'audience' => '',
    'redaction_rules' => implode("\n", $r['target']['redaction_rules']), 'credential_id' => $r['credential_id'],
    'max_input_bytes' => (string) $r['context_limits']['max_input_bytes'], 'max_output_tokens' => (string) $r['context_limits']['max_output_tokens'],
    'max_model_calls' => (string) $r['context_limits']['max_model_calls'], 'max_observation_age_seconds' => (string) $r['context_limits']['max_observation_age_seconds'],
];
$requestFor = static fn (string $setupKey, string $competitor, string $url): array => [
    'setup_key' => $setupKey,
    'mission' => ['title' => "Watch {$competitor}", 'objective' => "Notice material changes on {$competitor}'s public page for the sales team."],
    'competitor' => ['display_name' => $competitor, 'competitor_class' => 'DIRECT', 'organization_number' => null],
    'source' => ['url' => $url, 'source_name' => "{$competitor} public page", 'license_class' => 'PUBLIC_WEB_ALLOWED', 'allowed_use_class' => 'derived_summary_allowed', 'customer_output_allowed' => true, 'derived_summary_allowed' => true, 'caching_policy' => 'standard_cache', 'retention_class' => 'standard',
        'review' => ['approved_by_operator_id' => $scope['operator_id'], 'approved_at' => '2026-03-01T09:00:00.000Z', 'evidence_reference' => 'review note kept outside the repository', 'evidence_sha256' => hash('sha256', 'synthetic review of ' . $url), 'robots_policy_checked' => true, 'terms_policy_checked' => true]],
    'profile_evidence_ids' => [$profile['evidence_id']],
    'target' => ['target_org_role' => 'SALES', 'redaction_rules' => []],
    'credential_id' => $credential['provider_credential_id'],
    'context_limits' => ['max_input_bytes' => 65536, 'max_output_tokens' => 2048, 'max_model_calls' => 4, 'max_observation_age_seconds' => 172800],
];
$fieldNames = (new ReflectionClassConstant(MonitorSetupController::class, 'FIELDS'))->getValue();
// PHP's own diagnostic log is captured for the whole journey and read back below; it is restored
// however the journey ends, so a failure here cannot redirect another suite's diagnostics.
$errorLog = tempnam(sys_get_temp_dir(), 'allfather-journey-errors-');
$previousErrorLog = ini_set('error_log', $errorLog);
try {
    // ================================================================ sign in through the front door
    $loginPage = $send('GET', '/login');
    Assert::same(200, $loginPage->status, 'the sign-in page renders');
    $preAuth = $xpath($loginPage->body)->query('//input[@name="csrf_token"]')->item(0)?->getAttribute('value') ?? '';
    Assert::same($jar[Csrf::COOKIE_NAME] ?? null, $preAuth, 'the sign-in form carries the pre-auth CSRF token its cookie holds');
    $signedIn = $send('POST', '/login', ['csrf_token' => $preAuth, 'email' => 'setup-journey@example.test', 'password' => $password]);
    Assert::same([303, '/console'], [$signedIn->status, $signedIn->headers['Location'] ?? null], 'the operator signs in');
    Assert::true(($jar['af_session'] ?? '') !== '' && ($jar[Csrf::COOKIE_NAME] ?? '') !== $preAuth, 'with a session cookie and a rotated CSRF cookie');
    $selected = $send('POST', '/console/workspace', ['csrf_token' => $jar[Csrf::COOKIE_NAME], 'workspace_id' => $scope['workspace_id']]);
    Assert::same([303, '/console'], [$selected->status, $selected->headers['Location'] ?? null], 'and selects the workspace through the console');

    $baseline = $snapshot();

    // ================================================================ journey 1: a fresh workspace
    $page = $send('GET', '/console/monitor/setup');
    Assert::same(200, $page->status, 'the setup page renders for the signed-in operator');
    $pageForm = $formFields($xpath($page->body), 'plan');
    Assert::same($jar[Csrf::COOKIE_NAME], $pageForm['csrf_token'] ?? null, 'the page carries the session CSRF token');
    Assert::same([], array_values(array_diff($fieldNames, array_keys($pageForm))), 'the plan form offers every setup field');

    $request1 = $requestFor('journey-rival', 'Rival Events AB', 'https://rival.example/pricing');
    $planned = $send('POST', '/console/monitor/setup', $formFor($request1) + ['action' => 'plan', 'csrf_token' => $pageForm['csrf_token']]);
    Assert::same(200, $planned->status, 'planning renders the reviewed plan');
    $planPage = $xpath($planned->body);
    $applyForm = $formFields($planPage, 'apply');
    $reviewedDigest = $applyForm['plan_digest'] ?? '';
    Assert::same(1, preg_match('/^[a-f0-9]{64}$/D', $reviewedDigest), 'the apply form is bound to a plan digest');
    Assert::contains('Plan digest ' . $reviewedDigest, preg_replace('/\s+/', ' ', $planPage->query('//section[@aria-labelledby="setup-plan"]')->item(0)?->textContent ?? ''), 'and it is the digest the page shows the operator');
    $expectedFields = ['csrf_token', 'action', 'plan_digest', ...$fieldNames];
    sort($expectedFields);
    $renderedFields = array_keys($applyForm);
    sort($renderedFields);
    Assert::same($expectedFields, $renderedFields, 'the apply form carries every setup field, the digest, the action and the CSRF token — and nothing else');
    Assert::same($formFor($request1), array_intersect_key($applyForm, array_flip($fieldNames)), 'every field round-trips exactly as the operator entered it');
    $planSteps1 = array_map(static fn (array $s): string => $s['action'], $stepRows($planPage, 'setup-plan'));
    $direct = $ok((new MonitorSetup($db, $clock, $store))->plan($scope, $request1), 'the direct plan');
    Assert::same($direct['plan_digest'], $reviewedDigest, 'the page digest is what MonitorSetup computes for the session scope and this exact request');
    Assert::same(array_column($direct['steps'], 'action', 'step'), $planSteps1, 'and the page lists exactly its steps');
    Assert::same([], $direct['blocked_by'], 'nothing blocks the plan');
    Assert::same(array_keys($stepTables), array_keys($planSteps1), 'the mapping above documents every plan step, in plan order');
    foreach (['TENANT_ENTITLEMENT', 'COMPANY_PROFILE', 'PROVIDER_CREDENTIAL'] as $reuseOnly) {
        Assert::same('REUSE', $planSteps1[$reuseOnly], "{$reuseOnly} is reused: setup subscribes no tenant, imports no profile and saves no key");
    }

    $afterPlan = $snapshot();
    Assert::same([], $changed($baseline, $afterPlan), 'GET and plan wrote nothing, in any table');
    $assertAuthorityUnchanged($baseline, $afterPlan, 'after plan');

    $applied = $send('POST', '/console/monitor/setup', $applyForm);
    Assert::same(303, $applied->status, 'applying exactly the reviewed plan succeeds');
    Assert::same('/console/monitor/setup?setup_key=journey-rival', $applied->headers['Location'] ?? null, 'and redirects to its receipt');
    $afterApply = $snapshot();
    $delta = $changed($afterPlan, $afterApply);
    Assert::same($expectedTables($planSteps1), array_keys($delta), 'the tables apply changed are exactly those the reviewed plan\'s CREATE steps imply');
    foreach ($applyAlways as $table => $rows) {
        Assert::same($rows, $delta[$table] ?? 0, "{$table}: exactly the {$rows} retained descriptor checks");
    }
    $assertAuthorityUnchanged($baseline, $afterApply, 'after apply');

    $location = parse_url((string) $applied->headers['Location']);
    parse_str((string) ($location['query'] ?? ''), $receiptQuery);
    $receiptPage = $send('GET', (string) $location['path'], [], $receiptQuery);
    Assert::same(200, $receiptPage->status, 'the receipt page renders');
    $receipt1 = $ok((new MonitorSetup($db, $clock, $store))->receipt($scope, 'journey-rival'), 'the receipt');
    $receiptHtml = $xpath($receiptPage->body);
    $receiptText = preg_replace('/\s+/', ' ', $receiptHtml->query('//section[@aria-labelledby="setup-receipt"]')->item(0)?->textContent ?? '');
    Assert::contains('Setup journey-rival revision 1 configured mission ' . $receipt1['mission_id'], $receiptText, 'the receipt names the configured mission');
    Assert::contains('(configuration ' . $receipt1['definition_digest'] . ')', $receiptText, 'and its frozen configuration');
    Assert::contains('It granted no role and admitted no native execution.', $receiptText, 'and says what it did not do');
    Assert::same($planSteps1, array_map(static fn (array $s): string => $s['action'], $stepRows($receiptHtml, 'setup-receipt')), 'the receipt lists exactly the reviewed steps');
    Assert::same([$reviewedDigest, 1, false, false, false], [$receipt1['plan_digest'], $receipt1['revision'], $receipt1['grants_roles'], $receipt1['grants_native_execution'], $receipt1['activation_granted']], 'the receipt is bound to the reviewed digest and grants nothing');
    Assert::same([], $changed($afterApply, $snapshot()), 'reading the receipt wrote nothing');

    // Every new object row is one the receipt names, under a step the reviewed plan said CREATE.
    $expectedObjects = static function (array $steps, array $receipt) use ($db): array {
        $o = $receipt['objects'];
        $made = static fn (string $step, array $ids): array => $steps[$step] === 'CREATE' ? $ids : [];
        $definition = json_decode((string) $db->scalar('SELECT definition_json FROM monitor_mission_configs WHERE mission_id = ?', [$receipt['mission_id']]), true, 64, JSON_THROW_ON_ERROR);
        $captureTool = $definition['capture_governance']['governed_tool_id'];
        $modelBinding = (string) $db->scalar('SELECT runtime_mapping_ref_id FROM governed_tools WHERE governed_tool_id = ?', [$o['model_governed_tool_id']]);
        $expected = [
            'valkyries' => [...$made('P01_REGISTRATION', [$o['monitor_valkyrie_id']]), ...$made('CRITIC', [$o['critic_valkyrie_id']])],
            'capability_contracts' => [...$made('P01_REGISTRATION', [$o['monitor_contract_id']]), ...$made('CRITIC', [$o['critic_contract_id']])],
            'governed_tools' => [...$made('MODEL_DESCRIPTOR', [$o['model_governed_tool_id']]), ...$made('P01_REGISTRATION', [$captureTool])],
            'runtime_host_bindings' => [...$made('MODEL_DESCRIPTOR', [$modelBinding]), ...$made('SOURCE_READ_BINDING', [$o['source_read_binding_id']])],
            'tool_rights' => [...$made('P01_REGISTRATION', [$o['monitor_contract_id'] . '>' . $captureTool]), ...$made('AUTHOR_MODEL_RIGHT', [$o['monitor_contract_id'] . '>' . $o['model_governed_tool_id']]), ...$made('CRITIC_MODEL_RIGHT', [$o['critic_contract_id'] . '>' . $o['model_governed_tool_id']])],
            'competitors' => $made('COMPETITOR', [$o['competitor_id']]),
            'source_references' => $made('SOURCE_CARD', [$o['source_ref_id']]),
            'source_license_cards' => $made('SOURCE_CARD', [$o['source_license_card_id']]),
            'monitored_sources' => $made('MONITORED_SOURCE', [$o['monitored_source_id']]),
            'arbiter_policy_sets' => [...$made('P01_POLICY', [$o['p01_policy_id']]), ...$made('P04_POLICY', [$o['p04_policy_id']])],
            'console_redaction_policy_projections' => $made('DISTRIBUTION_TARGET', [$o['target_org_role']]),
            'missions' => $made('MISSION_CONFIGURATION', [$receipt['mission_id']]),
            'mission_assignments' => $made('MISSION_CONFIGURATION', [$receipt['mission_id'] . '>MODEL_AUTHOR', $receipt['mission_id'] . '>MODEL_CRITIC', $receipt['mission_id'] . '>SOURCE_READER']),
            'monitor_mission_configs' => $made('MISSION_CONFIGURATION', [$receipt['mission_id']]),
            'monitor_setup_receipts' => [$receipt['setup_receipt_id']],
        ];
        return array_map(static function (array $ids): array { sort($ids); return $ids; }, $expected);
    };
    foreach ($expectedObjects($planSteps1, $receipt1) as $table => $ids) {
        Assert::same($ids, $newRows($afterPlan, $afterApply, $table), "{$table}: the only new rows are the ones the receipt names under CREATE steps");
    }
    Assert::same([$profile['evidence_id']], $receipt1['objects']['profile_evidence_ids'], 'the receipt binds the reviewed profile evidence, which already existed');
    Assert::same($credential['provider_credential_id'], $receipt1['objects']['credential_id'], 'and the existing credential, by id');

    // Replaying the applied form is not a second apply: the plan is no longer the one reviewed.
    $replay = $send('POST', '/console/monitor/setup', $applyForm);
    Assert::same(409, $replay->status, 'replaying the applied form is refused');
    Assert::contains('MONITOR_SETUP_PLAN_CHANGED', $replay->body, 'because the reviewed plan has changed (everything is now REUSE)');
    Assert::same([], $changed($afterApply, $snapshot()), 'and writes nothing');

    // ================================================================ journey 2: the same workspace, mostly REUSE
    $csrf = $formFields($xpath($receiptPage->body), 'plan')['csrf_token'] ?? '';
    $request2 = $requestFor('journey-second', 'Second Rival AB', 'https://second-rival.example/news');
    $planned2 = $send('POST', '/console/monitor/setup', $formFor($request2) + ['action' => 'plan', 'csrf_token' => $csrf]);
    Assert::same(200, $planned2->status, 'a second mission plans in the same workspace');
    $plan2Page = $xpath($planned2->body);
    $applyForm2 = $formFields($plan2Page, 'apply');
    $planRows2 = $stepRows($plan2Page, 'setup-plan');
    $planSteps2 = array_map(static fn (array $s): string => $s['action'], $planRows2);
    Assert::same(['COMPETITOR', 'SOURCE_REVIEW_ATTESTATION', 'SOURCE_CARD', 'MONITORED_SOURCE', 'MISSION_CONFIGURATION'], array_keys(array_filter($planSteps2, static fn (string $a): bool => $a === 'CREATE')), 'it creates only the new competitor, source and mission');
    Assert::same([], array_keys(array_filter($planSteps2, static fn (string $a): bool => !in_array($a, ['CREATE', 'REUSE'], true))), 'and reuses everything else');
    $beforeApply2 = $snapshot();
    Assert::same([], $changed($afterApply, $beforeApply2), 'the second plan wrote nothing');
    $applied2 = $send('POST', '/console/monitor/setup', $applyForm2);
    Assert::same([303, '/console/monitor/setup?setup_key=journey-second'], [$applied2->status, $applied2->headers['Location'] ?? null], 'the second reviewed plan applies');
    $afterApply2 = $snapshot();
    $delta2 = $changed($beforeApply2, $afterApply2);
    Assert::same($expectedTables($planSteps2), array_keys($delta2), 'and changes exactly the tables its own CREATE steps imply — a strictly smaller set');
    Assert::true(count($delta2) < count($delta), 'the expectation follows the reviewed plan, not a constant');
    $assertAuthorityUnchanged($baseline, $afterApply2, 'after the second apply');
    $receipt2 = $ok((new MonitorSetup($db, $clock, $store))->receipt($scope, 'journey-second'), 'the second receipt');
    foreach ($expectedObjects($planSteps2, $receipt2) as $table => $ids) {
        Assert::same($ids, $newRows($beforeApply2, $afterApply2, $table), "second setup, {$table}: the only new rows are the ones its receipt names under CREATE steps");
    }
    foreach (['model_governed_tool_id', 'source_read_binding_id', 'monitor_valkyrie_id', 'monitor_contract_id', 'critic_valkyrie_id', 'critic_contract_id', 'p01_policy_id', 'p04_policy_id'] as $reused) {
        Assert::same($receipt1['objects'][$reused], $receipt2['objects'][$reused], "the second setup reuses {$reused}");
    }
    foreach (['P01_REGISTRATION' => 'monitor_contract_id', 'CRITIC' => 'critic_contract_id', 'P01_POLICY' => 'p01_policy_id', 'P04_POLICY' => 'p04_policy_id', 'SOURCE_READ_BINDING' => 'source_read_binding_id', 'MODEL_DESCRIPTOR' => 'model_governed_tool_id'] as $step => $object) {
        Assert::same($receipt1['objects'][$object], $planRows2[$step]['id'], "the reviewed plan named the reused {$step} object the operator then got");
    }
    $receiptPage2 = $send('GET', '/console/monitor/setup', [], ['setup_key' => 'journey-second']);
    Assert::same(200, $receiptPage2->status, 'the second receipt page renders');
    Assert::contains(
        'Setup journey-second revision 1 configured mission ' . $receipt2['mission_id'],
        preg_replace('/\s+/', ' ', $xpath($receiptPage2->body)->query('//section[@aria-labelledby="setup-receipt"]')->item(0)?->textContent ?? ''),
        'the second receipt names its own mission',
    );
    Assert::true($receipt2['mission_id'] !== $receipt1['mission_id'], 'which is a new mission');

    // ================================================================ still nothing may execute
    $final = $snapshot();
    Assert::same([], $changed($afterApply2, $final), 'reading the second receipt wrote nothing');
    $assertAuthorityUnchanged($baseline, $final, 'at the end of the journey');
    $decisions = OwnerDecisionRegistry::forRoot($root);
    $grants = new ExecutionGrantStore($db, $clock, new PromptApprovalStore($db, $clock, $decisions), $decisions);
    foreach (ExecutionGrantStore::PURPOSES as $purpose) {
        Assert::same([], $grants->admittedIn($scope['tenant_id'], $scope['workspace_id'], $purpose), "no {$purpose} execution grant is admitted in the workspace");
    }
    Assert::false(ScheduleDispatcher::admittedAnywhere($db, $clock, $root), 'no schedule is admitted anywhere');
    $prop = static fn (object $o, string $name): mixed => (new ReflectionProperty($o, $name))->getValue($o);
    $composition = MonitorFactory::forWorkspace($db, $clock, $store, $keys, $decisions, str_repeat('0', 64), $scope['tenant_id'], $scope['workspace_id']);
    Assert::true($composition instanceof MonitorService, 'the deployed Monitor composition builds for the workspace');
    $capture = $prop($composition, 'capture');
    Assert::same(
        ['source' => NoNetworkTransport::class, 'resolver' => FixedHostResolver::class, 'model_activated' => false, 'native' => false],
        ['source' => get_class($prop($capture, 'transport')), 'resolver' => get_class($prop($capture, 'resolver')), 'model_activated' => $prop($prop($prop($composition, 'models'), 'transport'), 'activated'), 'native' => $prop($composition, 'nativeEnabled')],
        'and, after two successful web setups, it still constructs no network leaf, no activated model transport and no native execution',
    );
} finally {
    ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
}

// ================================================================ no secret leaves through the web or a log
$errors = (string) file_get_contents($errorLog);
@unlink($errorLog);
Assert::true(count($transcript) >= 10, 'the transcript holds every request of both journeys');
foreach ($transcript as $i => $exchange) {
    $response = $exchange['response'];
    $request = $exchange['request'];
    $wire = $response->body . "\n" . implode("\n", array_map(static fn ($k, $v): string => "{$k}: {$v}", array_keys($response->headers), $response->headers)) . "\n" . implode("\n", $response->cookies);
    $logLine = RequestLog::format((string) ($response->headers[RequestId::HEADER] ?? $request->requestId), $request->method, $request->path, $response->status);
    foreach ($needles as $what => $needle) {
        Assert::false(str_contains($wire, $needle), "{$request->method} {$request->path} (#{$i}): the response carries no trace of {$what}");
        Assert::false(str_contains($logLine, $needle), "{$request->method} {$request->path} (#{$i}): its request-log line carries no trace of {$what}");
    }
}
foreach ($needles as $what => $needle) {
    Assert::false(str_contains($errors, $needle), "the PHP error log carries no trace of {$what}");
    if (is_file(RequestLog::path())) {
        Assert::false(str_contains((string) file_get_contents(RequestLog::path()), $needle), "the request log file carries no trace of {$what}");
    }
    foreach ($db->all('SELECT outcome_json AS v FROM monitor_setup_receipts UNION ALL SELECT definition_json FROM monitor_mission_configs') as $stored) {
        Assert::false(str_contains((string) $stored['v'], $needle), "the stored receipts and frozen configurations carry no trace of {$what}");
    }
}
