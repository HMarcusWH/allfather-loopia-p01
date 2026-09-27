<?php
declare(strict_types=1);

/**
 * One real mission set up through the existing owners, repeatably: a dry run first, then exactly the
 * reviewed plan. Repeating a compatible setup changes nothing; a changed request is a successor that
 * leaves the old mission, its configuration and its profile bytes as they were; a revoked or
 * narrowed right stays a refusal instead of being re-granted; and setup never admits execution.
 */

require_once __DIR__ . '/../support/ExecutionFixture.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Ingestion\{FetchRequest,FetchResponse,FetchTransport};
use Allfather\Loopia\Product\P01\{Entitlements,SourceReadRegistration};
use Allfather\Loopia\PromptOps\{PromptApprovalStore,PromptSelectionResolver};
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Provider\{CredentialStore,SecretBox};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,LiveFlaggedFixtureTransport,MonitorModelFixture,Seed};
use Allfather\Loopia\Workflow\{ExecutionAdmission,ExecutionGrantStore,MissionConfiguration,MonitorSetup,OwnerDecisionRegistry};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock, 'monitor-setup');
$store = new EvidenceStore(sys_get_temp_dir() . '/allfather-setup-' . Ids::new());
$setup = new MonitorSetup($db, $clock, $store);
$count = static fn (string $table): int => (int) $db->scalar('SELECT COUNT(*) FROM ' . $table);
$tables = ['missions', 'mission_assignments', 'valkyries', 'capability_contracts', 'tool_rights', 'governed_tools', 'runtime_host_bindings', 'competitors', 'source_license_cards', 'monitored_sources', 'monitor_mission_configs', 'monitor_setup_receipts', 'console_redaction_policy_projections', 'arbiter_policy_sets', 'evidence_objects'];
$snapshot = static fn (): array => array_combine($tables, array_map($count, $tables));
$request = static fn (array $profiles, string $credential, array $over = []): array => array_replace_recursive([
    'setup_key' => 'acme-pricing',
    'mission' => ['title' => 'Watch Acme pricing', 'objective' => 'Notice material changes on the Acme public pricing page for the sales team.'],
    'competitor' => ['display_name' => 'Acme Events AB', 'competitor_class' => 'DIRECT', 'organization_number' => null],
    'source' => ['url' => 'https://sources.example/acme-pricing', 'source_name' => 'Acme public pricing', 'license_class' => 'PUBLIC_WEB_ALLOWED', 'allowed_use_class' => 'derived_summary_allowed', 'customer_output_allowed' => true, 'derived_summary_allowed' => true, 'caching_policy' => 'standard_cache', 'retention_class' => 'standard',
        'review' => ['approved_by_operator_id' => $scope['operator_id'], 'approved_at' => '2026-01-01T00:00:00.000Z', 'evidence_reference' => 'review note stored outside the repository', 'evidence_sha256' => hash('sha256', 'synthetic source review'), 'robots_policy_checked' => true, 'terms_policy_checked' => true]],
    'profile_evidence_ids' => $profiles,
    'target' => ['target_org_role' => 'SALES', 'redaction_rules' => []],
    'credential_id' => $credential,
    'context_limits' => ['max_input_bytes' => 65536, 'max_output_tokens' => 2048, 'max_model_calls' => 4, 'max_observation_age_seconds' => 172800],
], $over);
$steps = static fn (array $plan): array => array_column($plan['steps'], 'action', 'step');

// ---------------------------------------------------------------- setup has no implicit authority
$denied = $setup->plan($scope, $request([Ids::new()], Ids::new()));
Assert::same('MONITOR_SETUP_DENIED', $denied->reasonCode(), 'an operator without the roles cannot even plan');
Assert::equalsList(MonitorSetup::PERMISSIONS, $denied->detail['missing_permissions'], 'and is told exactly which roles are missing');
Assert::same('MONITOR_PROFILE_IMPORT_DENIED', $setup->importProfile($scope, 'Owner profile')->reasonCode(), 'profile import needs the roles too');
Assert::same(0, $count('evidence_objects'), 'a denied import wrote nothing');

// The explicit, authorized membership act — performed outside setup, never by it.
$role = Seed::role($db, $clock, 'setup-operator-' . substr(Ids::new(), 0, 8), [...MonitorSetup::PERMISSIONS, 'tool.invoke', 'evidence.read', 'mission.read', 'mission.transition', 'company_signal.review', 'distribution.route', 'competition_monitoring.read']);
Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
$futureReview=$request([Ids::new()],Ids::new(),['source'=>['review'=>['approved_at'=>'2026-01-02T00:00:00.000Z']]]);
$futurePlan=X::value($setup->plan($scope,$futureReview));
Assert::same('SOURCE_REVIEW_TIME_INVALID',array_column($futurePlan['steps'],null,'step')['SOURCE_REVIEW_ATTESTATION']['reason_code'],'a future-dated source review is not current authority');

// ---------------------------------------------------------------- profile import
$profileA = 'Owner-provided, unverified: we run event studios in Stockholm and sell day packages.';
Assert::same('MONITOR_PROFILE_INVALID', $setup->importProfile($scope, str_repeat('x', MonitorSetup::MAX_PROFILE_BYTES + 1))->reasonCode(), 'an oversized profile is refused');
Assert::same('MONITOR_PROFILE_INVALID', $setup->importProfile($scope, "\xff\xfe")->reasonCode(), 'a non-UTF-8 profile is refused');
$a = X::value($setup->importProfile($scope, $profileA));
Assert::same(hash('sha256', $profileA), $a['original_sha256'], 'the original byte digest is the evidence identity');
Assert::same('TENANT_PROVIDED', $a['basis'], 'owner material stays labelled as owner-provided');
Assert::same(false, $a['verified_by_platform'], 'and unverified');
Assert::same(true, $a['created'], 'a first import creates evidence');
$again = X::value($setup->importProfile($scope, $profileA));
Assert::same([$a['evidence_id'], false], [$again['evidence_id'], $again['created']], 'identical bytes reuse the same evidence');
Assert::same($profileA, $store->get($a['original_sha256'])->value(), 'the exact bytes are retrievable by digest');

$box = SecretBox::fromMasterKey(base64_encode(random_bytes(32)));
$credentials = new CredentialStore($db, $clock, $box);
$credential = X::value($credentials->save($scope, 'sk-synthetic-setup-key-0001', 'Monitor OpenAI', []));
Assert::same([], $credential['rotated_credential_ids'], 'the first key rotates nothing');

// ---------------------------------------------------------------- the tenant's entitlement is not setup's to grant
$before = $snapshot();
$plan = X::value($setup->plan($scope, $request([$a['evidence_id']], $credential['provider_credential_id'])));
Assert::same('REFUSE', $steps($plan)['TENANT_ENTITLEMENT'], 'a tenant without the monitoring entitlement is refused');
Assert::same($before, $snapshot(), 'a plan writes nothing');
Assert::same('MONITOR_SETUP_BLOCKED', $setup->apply($scope, $request([$a['evidence_id']], $credential['provider_credential_id']), $plan['plan_digest'])->reasonCode(), 'a blocked plan cannot be applied');
Assert::same($before, $snapshot(), 'and applying it wrote nothing');

// The commercial act, performed outside setup (as a test stand-in for the owner's subscription).
$entitlements = new Entitlements($db, $clock);
$tier = X::value($entitlements->defineTier('monitor_setup_test', 'Monitor setup test tier', 0, ['competition_monitoring.read']));
X::value($entitlements->subscribe($scope['tenant_id'], $tier['subscription_tier_id'], 'ACTIVE'));

// ---------------------------------------------------------------- dry run, then exactly the reviewed plan
$req = $request([$a['evidence_id']], $credential['provider_credential_id']);
$plan = X::value($setup->plan($scope, $req));
Assert::same([], $plan['blocked_by'], 'a fresh workspace plans without conflicts');
Assert::same(['TENANT_ENTITLEMENT' => 'REUSE', 'MODEL_DESCRIPTOR' => 'CREATE', 'SOURCE_READ_BINDING' => 'CREATE', 'P01_REGISTRATION' => 'CREATE', 'AUTHOR_MODEL_RIGHT' => 'CREATE', 'CRITIC' => 'CREATE', 'CRITIC_MODEL_RIGHT' => 'CREATE', 'COMPETITOR' => 'CREATE', 'SOURCE_REVIEW_ATTESTATION' => 'REUSE', 'SOURCE_CARD' => 'CREATE', 'MONITORED_SOURCE' => 'CREATE', 'COMPANY_PROFILE' => 'REUSE', 'P01_POLICY' => 'CREATE', 'P04_POLICY' => 'CREATE', 'DISTRIBUTION_TARGET' => 'CREATE', 'PROVIDER_CREDENTIAL' => 'REUSE', 'MISSION_CONFIGURATION' => 'CREATE'], $steps($plan), 'the plan names every object and what setup would do with it');
Assert::same(false, $plan['grants_native_execution'], 'a plan grants no execution');
Assert::same(0, $plan['setup_writes_performed'], 'the dry run performs no setup/provisioning write');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM valkyrie_authority_evaluations'), 'the dry-run plan appends no M03 authority evaluation');
Assert::same('MONITOR_SETUP_PLAN_CHANGED', $setup->apply($scope, $req, str_repeat('0', 64))->reasonCode(), 'apply needs the digest of the reviewed plan');
$applied = X::value($setup->apply($scope, $req, $plan['plan_digest']));
Assert::same(1, $applied['revision'], 'the first setup is revision 1');
Assert::same([false, false, false], [$applied['grants_roles'], $applied['grants_native_execution'], $applied['activation_granted']], 'setup grants no role, execution or activation');
Assert::same(0, $count('monitor_execution_grants'), 'no execution grant exists after setup');
$config = $db->one('SELECT * FROM monitor_mission_configs WHERE mission_id=?', [$applied['mission_id']]);
Assert::same('NATIVE', $config['execution_mode'], 'the configuration is native, inert until a grant is admitted');
$definition = json_decode($config['definition_json'], true, 64, JSON_THROW_ON_ERROR);
Assert::true((new MissionConfiguration($db, $clock))->validate($scope, $definition, 'NATIVE')->isOk(), 'the frozen definition validates against live owners');
Assert::same([$a['evidence_id']], $definition['context_selection']['profile_evidence_ids'], 'it selects the imported profile');
Assert::same(1, (int) $db->scalar('SELECT COUNT(*) FROM source_terms_reviews WHERE source_license_card_id=?', [$applied['objects']['source_license_card_id']]), 'the supplied review event is stored on the canonical append-only source review ledger');
$reviewRow=$db->one('SELECT terms_snapshot_hash,reviewed_by,reviewed_at FROM source_terms_reviews WHERE source_license_card_id=?',[$applied['objects']['source_license_card_id']]);
Assert::same([null,$scope['operator_id'],'2026-01-01T00:00:00.000Z'],[$reviewRow['terms_snapshot_hash'],$reviewRow['reviewed_by'],$reviewRow['reviewed_at']],'a review-note digest is not mislabeled as a hash of source terms bytes');
Assert::same(hash('sha256','synthetic source review'),$applied['objects']['source_review_evidence_sha256'],'the private review evidence digest stays bound in the append-only setup receipt');
Assert::same(SourceReadRegistration::TOOL_REF, $db->scalar('SELECT runtime_host_tool_ref FROM runtime_host_tool_refs WHERE governed_tool_id=?', [$definition['capture_governance']['governed_tool_id']]), 'the read tool is mapped onto the shared read-only binding');
Assert::same('SAFE_READ_ONLY', $db->scalar('SELECT safety_classification FROM runtime_host_bindings WHERE binding_id=?', [$applied['objects']['source_read_binding_id']]), 'which can only observe');
Assert::true($definition['model_governance']['author']['valkyrie_id'] !== $definition['model_governance']['critic']['valkyrie_id'], 'author and critic are separate agents');
Assert::equalsList([$applied['objects']['profile_source_ref_id'], $applied['objects']['source_ref_id']], json_decode((string) $db->scalar('SELECT allowed_source_scope_ids FROM missions WHERE mission_id=?', [$applied['mission_id']]), true), 'the mission may read exactly the profile and the reviewed source');
$missionRow=$db->one('SELECT mission_version,status,allowed_tool_ids,supersedes_mission_id FROM missions WHERE mission_id=?',[$applied['mission_id']]);
Assert::same([1,'ACTIVE',null],[(int)$missionRow['mission_version'],$missionRow['status'],$missionRow['supersedes_mission_id']],'the first mission followed DRAFT -> ACTIVE and has no predecessor');
Assert::equalsList([$definition['capture_governance']['governed_tool_id'],$definition['model_governance']['author']['governed_tool_id']],json_decode($missionRow['allowed_tool_ids'],true),'both tools were declared on the mission before narrowed assignments');
$wrongEntitlement=$definition;$wrongEntitlement['model_governance']['author']['required_entitlement_permission']='distribution.read';
Assert::same('MONITOR_ENTITLEMENT_BINDING_INVALID',(new MissionConfiguration($db,$clock))->validate($scope,$wrongEntitlement,'NATIVE')->reasonCode(),'a model command cannot substitute a different commercial entitlement');
$wrongPolicy=$definition;$wrongPolicy['p01_policy_id']=$definition['p04_policy_id'];
Assert::same('MONITOR_POLICY_NOT_CURRENT',(new MissionConfiguration($db,$clock))->validate($scope,$wrongPolicy,'NATIVE')->reasonCode(),'a P04 policy cannot masquerade as the P01 policy binding');
$wrongUrl=$definition;$wrongUrl['capture_url']='https://sources.example/not-authorized';
Assert::same('MONITOR_CAPTURE_URL_OUTSIDE_SOURCE_SCOPE',(new MissionConfiguration($db,$clock))->validate($scope,$wrongUrl,'NATIVE')->reasonCode(),'the frozen capture URL must stay inside the reviewed source-card scope');

// ---------------------------------------------------------------- the set-up mission runs, once a grant is admitted
// Everything below the setup is the P01/P03 path: an owner decision, approvals, an admitted grant.
$dir = sys_get_temp_dir() . '/allfather-setup-decisions-' . Ids::new();
mkdir($dir, 0700, true);
$decisions = new OwnerDecisionRegistry($dir);
$approvals = new PromptApprovalStore($db, $clock, $decisions);
$grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
$fx = ['scope' => $scope, 'decision_dir' => $dir, 'approvals' => $approvals, 'grants' => $grants, 'mission_id' => $applied['mission_id'], 'definition_digest' => $applied['definition_digest'], 'credential_id' => $credential['provider_credential_id'], 'monitored_source_id' => $definition['monitored_source_id'], 'capture_url' => $definition['capture_url'], 'evidence_store' => $store, 'credential_store' => $credentials];
X::admitted($fx, X::manualDefinition($db, $clock, $fx, X::approveAll($fx, 'MANUAL_MONITOR')));
$pages = new class(['The planned seminar is November.', 'The planned seminar is December.']) implements FetchTransport {
    public int $calls = 0;
    public function __construct(private array $pages) {}
    public function send(FetchRequest $request): FetchResponse { return new FetchResponse(200, 'text/plain; charset=utf-8', null, $this->pages[$this->calls++] ?? 'Unexpected extra capture'); }
};
$model = new LiveFlaggedFixtureTransport(MonitorModelFixture::response(...));
$service = X::service($db, $clock, $fx, $pages, $model, PromptSelectionResolver::governed($approvals, $grants), new ExecutionAdmission($db, $clock, $grants, $approvals, $decisions, X::FINGERPRINT));
$run = X::value($service->startConfigured($scope, $applied['mission_id'], 'setup-baseline'));
Assert::same('BASELINE', X::value($service->advance($scope, $run['monitor_run_id']))['stage'], 'the set-up source is captured through the registered read tool');
$clock->advanceDays(1);
$id = X::value($service->startConfigured($scope, $applied['mission_id'], 'setup-changed'))['monitor_run_id'];
X::value($service->advance($scope, $id));
Assert::same('DIFFED', X::value($service->advance($scope, $id))['stage'], 'a second capture produces a real diff');
foreach (['ANALYZED', 'DRAFTED', 'REVIEW_REQUIRED'] as $expected) {
    $detail = X::value($service->detail($scope, $id));
    Assert::same($expected, X::value($service->advance($scope, $id, $detail['preview_request']['request_digest']))['stage'], "the set-up author/critic reach {$expected}");
}
$detail = X::value($service->detail($scope, $id));
Assert::same('P01_RELEASED', X::value($service->review($scope, $id, ['decision' => 'APPROVE', 'assessment' => 'Synthetic setup review: both pages and the owner profile compared; internal hypothesis only.', 'candidate_digest' => $detail['review_digest']]))['stage'], 'a human review releases through the set-up P01 policy');
X::value($service->advance($scope, $id));
$detail = X::value($service->detail($scope, $id));
X::value($service->advance($scope, $id, $detail['preview_request']['request_digest']));
$detail = X::value($service->detail($scope, $id));
Assert::same('COMPLETE', X::value($service->review($scope, $id, ['decision' => 'APPROVE', 'assessment' => 'Synthetic setup routing review: suitable for the configured SALES role.', 'candidate_digest' => $detail['review_digest']]))['stage'], 'and routes to the set-up internal target');
Assert::same([2, 4], [$pages->calls, $model->calls], 'two captures and four bounded model calls, under the admitted grant');

// ---------------------------------------------------------------- a repeated compatible setup is idempotent
$before = $snapshot();
$replan = X::value($setup->plan($scope, $req));
Assert::same([], array_filter($steps($replan), static fn (string $a): bool => $a !== 'REUSE'), 'everything is reused the second time');
$reapplied = X::value($setup->apply($scope, $req, $replan['plan_digest']));
Assert::same([$applied['setup_receipt_id'], true], [$reapplied['setup_receipt_id'], $reapplied['reused_existing_receipt']], 'the same receipt answers');
Assert::same($before, $snapshot(), 'and nothing was written');
Assert::same($applied['setup_receipt_id'], X::value($setup->receipt($scope, 'acme-pricing'))['setup_receipt_id'], 'the receipt can be read back');

// ---------------------------------------------------------------- a changed request is a successor, never a rewrite
$profileB = 'Owner-provided, unverified: we now also sell evening packages.';
$b = X::value($setup->importProfile($scope, $profileB));
Assert::true($b['evidence_id'] !== $a['evidence_id'], 'a new profile is new evidence');
Assert::same($config, $db->one('SELECT * FROM monitor_mission_configs WHERE mission_id=?', [$applied['mission_id']]), 'importing it changed no configuration');
Assert::same($profileA, $store->get($a['original_sha256'])->value(), 'and the bytes an earlier request bound to are unchanged');
$reqB = $request([$b['evidence_id']], $credential['provider_credential_id']);
$conflict = X::value($setup->plan($scope, $reqB));
Assert::same('CONFLICT', $steps($conflict)['MISSION_CONFIGURATION'], 'a changed request without --successor-of conflicts');
Assert::same('MONITOR_SETUP_REQUEST_CHANGED', $conflict['blocked_by'][0]['reason_code'], 'with a reason');
$successorPlan = X::value($setup->plan($scope, $reqB, $applied['setup_receipt_id']));
Assert::same([], $successorPlan['blocked_by'], 'an explicit successor plans cleanly');
$successor = X::value($setup->apply($scope, $reqB, $successorPlan['plan_digest'], $applied['setup_receipt_id']));
Assert::same([2, $applied['setup_receipt_id'], $applied['mission_id']], [$successor['revision'], $successor['predecessor_receipt_id'], $successor['supersedes_mission_id']], 'the successor names what it supersedes');
Assert::true($successor['mission_id'] !== $applied['mission_id'], 'on a new mission');
$successorMission=$db->one('SELECT mission_version,supersedes_mission_id FROM missions WHERE mission_id=?',[$successor['mission_id']]);
Assert::same([2,$applied['mission_id']],[(int)$successorMission['mission_version'],$successorMission['supersedes_mission_id']],'the M04 mission row itself carries successor lineage, not only the receipt');
foreach (['monitor_valkyrie_id', 'critic_valkyrie_id', 'competitor_id', 'source_license_card_id', 'monitored_source_id', 'source_read_binding_id'] as $reused) {
    Assert::same($applied['objects'][$reused], $successor['objects'][$reused], "the successor reuses {$reused}");
}
Assert::same($config, $db->one('SELECT * FROM monitor_mission_configs WHERE mission_id=?', [$applied['mission_id']]), 'the old configuration is exactly as it was');
Assert::same('MONITOR_SETUP_SUCCESSOR_NOT_LATEST', X::value($setup->plan($scope, $request([$a['evidence_id']], $credential['provider_credential_id'], ['mission' => ['title' => 'Another']]), $applied['setup_receipt_id']))['blocked_by'][0]['reason_code'], 'only the latest revision can be succeeded');

// ---------------------------------------------------------------- a new key does not retarget frozen missions
Assert::same('PROVIDER_CREDENTIAL_ROTATION_NOT_ACKNOWLEDGED', $credentials->save($scope, 'sk-synthetic-setup-key-0002', 'Monitor OpenAI', [])->reasonCode(), 'a save that would rotate an unacknowledged key refuses');
Assert::same('PROVIDER_CREDENTIAL_ROTATION_NOT_ACKNOWLEDGED',$credentials->save($scope,'sk-synthetic-setup-key-0002','Monitor OpenAI')->reasonCode(),'omitting the acknowledgement also refuses at the service boundary');
$described = X::value($credentials->describe($scope));
Assert::same([$credential['provider_credential_id']], $described['save_would_rotate'], 'describe says what a save would rotate');
Assert::equalsList([$applied['mission_id'], $successor['mission_id']], $described['credentials'][0]['bound_mission_ids'], 'and which frozen missions still name each key');
$rotated = X::value($credentials->save($scope, 'sk-synthetic-setup-key-0002', 'Monitor OpenAI', $described['save_would_rotate']));
Assert::same([$credential['provider_credential_id']], $rotated['rotated_credential_ids'], 'the acknowledged key is rotated');
Assert::same($credential['provider_credential_id'], json_decode((string) $db->scalar('SELECT definition_json FROM monitor_mission_configs WHERE mission_id=?', [$successor['mission_id']]), true)['credential_id'], 'the frozen definition still names the old key');
Assert::same('MONITOR_CREDENTIAL_NOT_AVAILABLE', (new MissionConfiguration($db, $clock))->validate($scope, json_decode((string) $db->scalar('SELECT definition_json FROM monitor_mission_configs WHERE mission_id=?', [$successor['mission_id']]), true), 'NATIVE')->reasonCode(), 'so it refuses until a successor names the new key');
$afterRotation = X::value($setup->plan($scope, $reqB));
Assert::same('REFUSE', $steps($afterRotation)['PROVIDER_CREDENTIAL'], 'setup does not quietly carry the old key forward');

// ---------------------------------------------------------------- revoked or narrowed rights stay refusals
$reqC = $request([$b['evidence_id']], $rotated['provider_credential_id']);
$db->run("UPDATE tool_rights SET status='REVOKED' WHERE capability_contract_id=?", [$successor['objects']['critic_contract_id']]);
$rights = $count('tool_rights');
$revoked = X::value($setup->plan($scope, $reqC, $successor['setup_receipt_id']));
Assert::same('REFUSE', $steps($revoked)['CRITIC_MODEL_RIGHT'], 'a revoked model right is a refusal');
Assert::same('MONITOR_SETUP_BLOCKED', $setup->apply($scope, $reqC, $revoked['plan_digest'], $successor['setup_receipt_id'])->reasonCode(), 'and setup will not re-grant it');
Assert::same($rights, $count('tool_rights'), 'no right was added');
$db->run("UPDATE tool_rights SET status='REVOKED' WHERE governed_tool_id=?", [$definition['capture_governance']['governed_tool_id']]);
Assert::same('REFUSE', $steps(X::value($setup->plan($scope, $reqC, $successor['setup_receipt_id'])))['P01_REGISTRATION'], 'a revoked source-read right refuses the registration too');

// ---------------------------------------------------------------- a live routing policy is never replaced as a side effect
(new Distribution($db, $clock, new DecisionLedger($db, $clock)))->configureRoleRedactionPolicy(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'target_org_role' => 'SALES', 'redaction_rules' => [['field' => 'price', 'action' => 'REDACT']]]);
Assert::same('CONFLICT', $steps(X::value($setup->plan($scope, $reqC, $successor['setup_receipt_id'])))['DISTRIBUTION_TARGET'], 'a different target policy is a conflict');

// ---------------------------------------------------------------- receipts are append-only
foreach (['UPDATE monitor_setup_receipts SET revision=9', 'DELETE FROM monitor_setup_receipts'] as $sql) {
    try { $db->run($sql); Assert::fail('setup receipt mutated: ' . $sql); } catch (\PDOException) { Assert::pass(); }
}
