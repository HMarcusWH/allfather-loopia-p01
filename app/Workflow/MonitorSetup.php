<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,OperatorScope,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\P01\{CompanyContext,Entitlements,Registration,Registry as P01Registry,SourceCards,SourceReadRegistration};
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\Provider\ModelRegistration;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\{Eligibility,Registry as M03Registry};
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;

/**
 * Repeatable setup of one real Monitor mission, without hand-written SQL or copied fixture ids.
 *
 * `plan()` is read-only and answers, for every object the mission needs, REUSE (a compatible object
 * already exists), CREATE (setup would provision it through its existing owner) or CONFLICT/REFUSE
 * (something exists that setup must not overwrite, widen or re-grant). `apply()` accepts only the
 * digest of a plan the operator reviewed, recomputes it, and performs exactly those CREATE steps in
 * one transaction through M03/M04/M06/P01/P04/M08 — never by writing their tables here.
 *
 * What setup never does: grant an M02 role (it requires them), subscribe a tenant, invent source
 * approval (the review is supplied, operator-bound evidence), import or edit a profile's bytes (see
 * importProfile), rotate a
 * credential, rewrite a frozen configuration, or admit native execution. A changed request appends a
 * successor mission and configuration; the old one, its grants and its runs keep their bindings.
 */
final class MonitorSetup
{
    /** What the operator must already hold. Setup checks these; it never makes them true. */
    public const PERMISSIONS = ['mission.create', 'mission.activate', 'distribution.configure_targets', 'tenant.administer', 'runtime_host_binding.administer', 'valkyrie.register', 'valkyrie.administer_contract', 'evidence.submit'];
    public const PROFILE_LOCATION = 'internal://monitor/company-profile';
    public const MAX_PROFILE_BYTES = 16384;
    public const CRITIC_NAME = 'Monitor Review Critic';
    private const CRITIC_CONTRACT_KEY = 'monitor.review_critic';
    private const MISSION_ACTION_CLASSES = ['OBSERVE', 'ANALYZE', 'RELEASE_INTELLIGENCE'];
    private const ENTITLEMENT = 'competition_monitoring.read';
    private const P01_TIER_PERMISSIONS = ['battlecard.read', 'briefing.read', 'company_signal.review', 'competition_monitoring.read'];

    public function __construct(private readonly Database $db, private readonly Clock $clock, private readonly EvidenceStore $store) {}

    /**
     * Import an owner-provided company profile as TENANT_PROVIDED evidence through EvidenceStore and
     * M05. The exact bytes are kept content-addressed and their SHA-256 is the evidence identity, so
     * a later profile is a NEW evidence row: it cannot change what an existing configuration selected
     * or what a historical prepared request contained. Re-importing identical bytes reuses the row.
     */
    public function importProfile(array $scope, string $bytes): Outcome
    {
        $scope = OperatorScope::normalize($scope);
        if ($scope === null || !$this->holds($scope, ['tenant.administer', 'evidence.submit'])) {
            return Outcome::refuse('MONITOR_PROFILE_IMPORT_DENIED');
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_PROFILE_BYTES || !mb_check_encoding($bytes, 'UTF-8') || str_contains($bytes, "\0")) {
            return Outcome::refuse('MONITOR_PROFILE_INVALID', ['max_bytes' => self::MAX_PROFILE_BYTES]);
        }
        $digest = hash('sha256', $bytes);

        return $this->db->transaction(function (Database $tx) use ($scope, $bytes, $digest): Outcome {
            $tx->one('SELECT workspace_id FROM workspaces WHERE workspace_id=? AND tenant_id=? FOR UPDATE', [$scope['workspace_id'], $scope['tenant_id']]);
            $source = $this->profileSource($scope, $tx);
            $spine = new EvidenceSpine($tx, $this->clock);
            if (($source['ambiguous'] ?? false) === true) {
                return Outcome::refuse('MONITOR_PROFILE_SOURCE_AMBIGUOUS');
            }
            if ($source === null) {
                $sourceId = $spine->registerSource($scope['tenant_id'], $scope['workspace_id'], 'UPLOADED_ARTIFACT', self::PROFILE_LOCATION, 'INTERNAL_ANALYSIS', 'INTERNAL_DEMO', 'ACTIVE_FOR_CAPTURE');
            } elseif ($source['status'] !== 'ACTIVE_FOR_CAPTURE') {
                return Outcome::refuse('MONITOR_PROFILE_SOURCE_NOT_ACTIVE');
            } else {
                $sourceId = $source['source_ref_id'];
            }
            $existing = $tx->scalar("SELECT evidence_id FROM evidence_objects WHERE tenant_id=? AND workspace_id=? AND source_ref_id=? AND evidence_type='UPLOADED_SOURCE_CAPTURE' AND content_hash=? AND evidence_status IN ('CAPTURED','ASSESSED') ORDER BY evidence_id LIMIT 1", [$scope['tenant_id'], $scope['workspace_id'], $sourceId, $digest]);
            $created = $existing === null;
            if ($created) {
                $stored = $this->store->put($bytes);
                if (!$stored->isOk() || $stored->value()['digest'] !== $digest) {
                    return Outcome::failure('MONITOR_PROFILE_STORE_FAILED');
                }
                $captured = $spine->capture(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'source_ref_id' => $sourceId, 'evidence_type' => 'UPLOADED_SOURCE_CAPTURE', 'captured_content_reference_safe' => 'evidence://' . $digest, 'content_hash' => $digest, 'capture_method' => 'OWNER_UPLOAD', 'permitted_use_scope' => 'INTERNAL_ANALYSIS', 'sensitivity_class' => 'INTERNAL_DEMO']);
                if (!$captured->isOk()) {
                    return $captured;
                }
                $existing = $captured->value()['evidence_id'];
            }
            if (CompanyContext::read($tx, (string) $existing, $scope['tenant_id'], $scope['workspace_id']) === null) {
                return Outcome::refuse('MONITOR_PROFILE_NOT_ADMISSIBLE');
            }

            return Outcome::ok(['evidence_id' => $existing, 'source_ref_id' => $sourceId, 'original_sha256' => $digest, 'bytes' => strlen($bytes), 'basis' => 'TENANT_PROVIDED', 'verified_by_platform' => false, 'created' => $created, 'changes_existing_configurations' => false]);
        });
    }

    /**
     * Dry run with no setup/provisioning writes. M02 still records denied access checks by design.
     * The returned `plan_digest` is what `apply()` must be given.
     *
     * @return Outcome<array<string, mixed>>
     */
    public function plan(array $scope, array $request, ?string $successorOf = null): Outcome
    {
        $scope = OperatorScope::normalize($scope);
        if ($scope === null) {
            return Outcome::refuse('MONITOR_SETUP_SCOPE_INVALID');
        }
        $missing = array_values(array_filter(self::PERMISSIONS, fn (string $p): bool => !$this->holds($scope, [$p])));
        if ($missing !== []) {
            return Outcome::refuse('MONITOR_SETUP_DENIED', ['missing_permissions' => $missing]);
        }
        $valid = self::validateRequest($request);
        if (!$valid->isOk()) {
            return $valid;
        }
        $r = $valid->value();
        $s = [];
        $add = static function (string $step, string $action, ?string $id = null, ?string $reason = null) use (&$s): void {
            $s[$step] = array_filter(['step' => $step, 'action' => $action, 'id' => $id, 'reason_code' => $reason], static fn ($v) => $v !== null);
        };

        $add('TENANT_ENTITLEMENT', (new Entitlements($this->db, $this->clock))->tenantTierGrants($scope['tenant_id'], self::ENTITLEMENT) ? 'REUSE' : 'REFUSE', null, null);
        if ($s['TENANT_ENTITLEMENT']['action'] === 'REFUSE') {
            $s['TENANT_ENTITLEMENT']['reason_code'] = 'TENANT_ENTITLEMENT_MISSING';
        }

        $model = (new ModelRegistration($this->db, $this->clock))->current();
        $model === null ? $add('MODEL_DESCRIPTOR', 'CREATE') : ($model->isOk() ? $add('MODEL_DESCRIPTOR', 'REUSE', $model->value()['governed_tool_id']) : $add('MODEL_DESCRIPTOR', 'CONFLICT', null, $model->reasonCode()));
        $binding = (new SourceReadRegistration($this->db, $this->clock))->existing();
        $binding === null ? $add('SOURCE_READ_BINDING', 'CREATE') : ($binding->isOk() ? $add('SOURCE_READ_BINDING', 'REUSE', $binding->value()['binding_id']) : $add('SOURCE_READ_BINDING', 'CONFLICT', null, $binding->reasonCode()));

        $registration = $this->registrationState($scope, $binding?->isOk() ? $binding->value()['binding_id'] : null);
        $add('P01_REGISTRATION', $registration['action'], $registration['ids']['capability_contract_id'] ?? null, $registration['reason'] ?? null);

        $modelId = $model?->isOk() ? $model->value()['governed_tool_id'] : null;
        $author = $this->rightState($registration['ids']['capability_contract_id'] ?? null, $modelId);
        $add('AUTHOR_MODEL_RIGHT', $author['action'], $author['id'] ?? null, $author['reason'] ?? null);
        $critic = $this->criticState($scope);
        $add('CRITIC', $critic['action'], $critic['contract_id'] ?? null, $critic['reason'] ?? null);
        $criticRight = $this->rightState($critic['contract_id'] ?? null, $modelId);
        $add('CRITIC_MODEL_RIGHT', $criticRight['action'], $criticRight['id'] ?? null, $criticRight['reason'] ?? null);

        $competitor = $this->competitorState($scope, $r['competitor']);
        $add('COMPETITOR', $competitor['action'], $competitor['id'] ?? null, $competitor['reason'] ?? null);
        $review = $r['source']['review'];
        $reviewReason = $review['approved_by_operator_id'] !== $scope['operator_id']
            ? 'SOURCE_REVIEW_OPERATOR_MISMATCH'
            : ($review['approved_at'] > $this->clock->now() ? 'SOURCE_REVIEW_TIME_INVALID' : null);
        $card = $this->cardState($scope, $r['source']);
        $reviewAction = $reviewReason !== null
            ? 'REFUSE'
            : ($card['action'] === 'CREATE' ? 'CREATE' : ($card['action'] === 'REUSE' ? 'REUSE' : 'REFUSE'));
        $reviewStepReason = $reviewReason ?? (in_array($card['action'], ['CREATE','REUSE'], true) ? null : ($card['reason'] ?? 'SOURCE_REVIEW_UNRESOLVED'));
        $add('SOURCE_REVIEW_ATTESTATION', $reviewAction, $review['approved_by_operator_id'], $reviewStepReason);
        $add('SOURCE_CARD', $card['action'], $card['id'] ?? null, $card['reason'] ?? null);
        $monitored = $this->monitoredState($scope, $competitor['id'] ?? null, $card['source_ref_id'] ?? null);
        $add('MONITORED_SOURCE', $monitored['action'], $monitored['id'] ?? null, $monitored['reason'] ?? null);

        $profileSource = $this->profileSource($scope);
        $profiles = 'REUSE';
        $profileReason = null;
        if (($profileSource['ambiguous'] ?? false) === true) {
            $profiles = 'REFUSE';
            $profileReason = 'MONITOR_PROFILE_SOURCE_AMBIGUOUS';
        }
        foreach ($r['profile_evidence_ids'] as $id) {
            $row = CompanyContext::read($this->db, $id, $scope['tenant_id'], $scope['workspace_id']);
            if ($row === null || $profileSource === null || ($profileSource['ambiguous'] ?? false) === true || $row['source_ref_id'] !== ($profileSource['source_ref_id'] ?? null)) {
                $profiles = 'REFUSE';
                $profileReason ??= 'MONITOR_PROFILE_NOT_AVAILABLE';
            }
        }
        $add('COMPANY_PROFILE', $profiles, is_string($profileSource['source_ref_id'] ?? null) ? $profileSource['source_ref_id'] : null, $profiles === 'REFUSE' ? $profileReason : null);

        $p01 = $this->db->scalar("SELECT arbiter_policy_set_id FROM arbiter_policy_sets WHERE policy_key='P01_COMPETITION_RELEVANCE' AND tenant_id=? AND workspace_id=? AND status='ACTIVE'", [$scope['tenant_id'], $scope['workspace_id']]);
        $add('P01_POLICY', $p01 === null ? 'CREATE' : 'REUSE', $p01 === null ? null : (string) $p01);
        $p04 = $this->db->scalar("SELECT arbiter_policy_set_id FROM arbiter_policy_sets WHERE policy_key='P04_DISTRIBUTION_ADMISSION' AND tenant_id=? AND workspace_id=? AND status='ACTIVE'", [$scope['tenant_id'], $scope['workspace_id']]);
        $add('P04_POLICY', $p04 === null ? 'CREATE' : 'REUSE', $p04 === null ? null : (string) $p04);

        $target = $this->db->one('SELECT redaction_rules_json FROM console_redaction_policy_projections WHERE tenant_id=? AND workspace_id=? AND target_org_role=?', [$scope['tenant_id'], $scope['workspace_id'], $r['target']['target_org_role']]);
        if ($target === null) {
            $add('DISTRIBUTION_TARGET', 'CREATE', $r['target']['target_org_role']);
        } elseif (Digest::canonicalDigest(json_decode($target['redaction_rules_json'], true, 32, JSON_THROW_ON_ERROR)) === Digest::canonicalDigest($r['target']['redaction_rules'])) {
            $add('DISTRIBUTION_TARGET', 'REUSE', $r['target']['target_org_role']);
        } else {
            // A live routing policy is replaced only by an explicit P04 act, never as a side effect.
            $add('DISTRIBUTION_TARGET', 'CONFLICT', $r['target']['target_org_role'], 'DISTRIBUTION_TARGET_POLICY_DIFFERS');
        }

        $credential = $this->db->one("SELECT 1 FROM provider_credentials WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'", [$r['credential_id'], $scope['tenant_id']]);
        $add('PROVIDER_CREDENTIAL', $credential === null ? 'REFUSE' : 'REUSE', $r['credential_id'], $credential === null ? 'MONITOR_CREDENTIAL_NOT_AVAILABLE' : null);

        $requestDigest = Digest::canonicalDigest($r);
        $mission = $this->missionState($scope, $r['setup_key'], $requestDigest, $successorOf);
        $add('MISSION_CONFIGURATION', $mission['action'], $mission['mission_id'] ?? null, $mission['reason'] ?? null);

        $steps = array_values($s);
        $blocked = array_values(array_filter($steps, static fn (array $x): bool => in_array($x['action'], ['CONFLICT', 'REFUSE'], true)));

        return Outcome::ok([
            'setup_key' => $r['setup_key'], 'request_digest' => $requestDigest, 'successor_of' => $successorOf,
            'revision' => $mission['revision'], 'steps' => $steps, 'blocked_by' => $blocked,
            'plan_digest' => Digest::canonicalDigest(['request_digest' => $requestDigest, 'successor_of' => $successorOf, 'steps' => $steps]),
            'grants_roles' => false, 'grants_native_execution' => false, 'setup_writes_performed' => 0,
        ]);
    }

    /**
     * Perform a reviewed plan. Refuses unless the recomputed plan has exactly the given digest and
     * nothing in it conflicts, so a plan that changed after review is re-reviewed, not applied.
     */
    public function apply(array $scope, array $request, string $planDigest, ?string $successorOf = null): Outcome
    {
        $scope = OperatorScope::normalize($scope);
        if ($scope === null) {
            return Outcome::refuse('MONITOR_SETUP_SCOPE_INVALID');
        }
        $planned = $this->plan($scope, $request, $successorOf);
        if (!$planned->isOk()) {
            return $planned;
        }
        $plan = $planned->value();
        if (!hash_equals($plan['plan_digest'], $planDigest)) {
            return Outcome::refuse('MONITOR_SETUP_PLAN_CHANGED', ['plan_digest' => $plan['plan_digest']]);
        }
        if ($plan['blocked_by'] !== []) {
            return Outcome::refuse('MONITOR_SETUP_BLOCKED', ['blocked_by' => $plan['blocked_by']]);
        }
        $r = self::validateRequest($request)->value();

        try {
            return $this->db->transaction(function (Database $tx) use ($scope, $r, $request, $planDigest, $successorOf): Outcome {
                $tx->one('SELECT workspace_id FROM workspaces WHERE workspace_id=? AND tenant_id=? FOR UPDATE', [$scope['workspace_id'], $scope['tenant_id']]);
                // Source cards are tenant-wide. Serialize their resolve/create decision across this
                // tenant as well, so two workspaces cannot both create a rival card for one review.
                $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$scope['tenant_id']]);
                // Re-plan after the serialization point. The first plan is the operator's
                // reviewed intent; this second one proves no concurrent setup changed any resolved
                // identity between review and the first write.
                $lockedPlan = $this->plan($scope, $request, $successorOf);
                if (!$lockedPlan->isOk()) { return $lockedPlan; }
                $plan = $lockedPlan->value();
                if (!hash_equals($plan['plan_digest'], $planDigest)) {
                    return Outcome::refuse('MONITOR_SETUP_PLAN_CHANGED', ['plan_digest' => $plan['plan_digest']]);
                }
                if ($plan['blocked_by'] !== []) {
                    return Outcome::refuse('MONITOR_SETUP_BLOCKED', ['blocked_by' => $plan['blocked_by']]);
                }
                $steps = array_column($plan['steps'], null, 'step');
                if ($steps['MISSION_CONFIGURATION']['action'] === 'REUSE') {
                    $receipt = $tx->one('SELECT * FROM monitor_setup_receipts WHERE mission_id=? AND tenant_id=? AND workspace_id=?', [
                        $steps['MISSION_CONFIGURATION']['id'], $scope['tenant_id'], $scope['workspace_id'],
                    ]);
                    return $receipt === null
                        ? Outcome::refuse('MONITOR_SETUP_STATE_CHANGED')
                        : Outcome::ok($this->summary($receipt, true));
                }
                $ledger = new DecisionLedger($tx, $this->clock);
                $m03 = new M03Registry($tx, $this->clock);
                $eligibility = new Eligibility($tx, $this->clock);
                $m04 = new Proposals($tx, $this->clock);
                $ids = [];

                $model = (new ModelRegistration($tx, $this->clock))->ensure($scope);
                if (!$model->isOk()) { return $model; }
                $ids['model'] = $model->value();
                $sourceRead = new SourceReadRegistration($tx, $this->clock);
                $binding = $sourceRead->ensureBinding($scope);
                if (!$binding->isOk()) { return $binding; }
                $ids['binding_id'] = $binding->value()['binding_id'];

                if ($steps['P01_REGISTRATION']['action'] === 'REUSE') {
                    $registration = $this->registrationState($scope, $ids['binding_id'])['ids'];
                } else {
                    $registered = (new Registration($tx, $this->clock, $m03, $eligibility, new ToolRegistry($tx, $this->clock), new PolicySets($tx, $this->clock), new ReleaseOutput($tx, $this->clock, $ledger), new Entitlements($tx, $this->clock)))
                        ->registerCompetitionMonitor(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'operator_id' => $scope['operator_id'], 'runtime_binding_id' => $ids['binding_id'], 'runtime_host_tool_ref' => SourceReadRegistration::TOOL_REF]);
                    if (!$registered->isOk()) { return $registered; }
                    $registration = $registered->value();
                }
                $bound = $sourceRead->bindTool($ids['binding_id'], $registration['governed_tool_id']);
                if (!$bound->isOk()) { return $bound; }

                $modelTool = $ids['model']['governed_tool_id'];
                if ($this->rightState($registration['capability_contract_id'], $modelTool, $tx)['action'] === 'CREATE') {
                    $eligibility->grantToolRight($registration['capability_contract_id'], $modelTool, [ModelRegistration::OPERATION_KEY], 'INTERNAL_TRANSFORM', 'ANALYZE');
                }
                $critic = $this->criticState($scope, $tx);
                if ($critic['action'] === 'CREATE') {
                    $criticId = $m03->registerValkyrie($scope['tenant_id'], $scope['workspace_id'], self::CRITIC_NAME, 'REVIEW_CRITIC_VALKYRIE', 'Advisory review of Monitor drafts; no release or external authority.', $scope['operator_id'], 'ACTIVE');
                    $contract = $m03->issueContract($criticId, $scope['tenant_id'], $scope['workspace_id'], self::CRITIC_CONTRACT_KEY, 'REVIEW_CRITIC_VALKYRIE', ['ANALYZE']);
                    if (!$contract->isOk()) { return $contract; }
                    $critic = ['valkyrie_id' => $criticId, 'contract_id' => $contract->value()['capability_contract_id']];
                }
                if ($this->rightState($critic['contract_id'], $modelTool, $tx)['action'] === 'CREATE') {
                    $eligibility->grantToolRight($critic['contract_id'], $modelTool, [ModelRegistration::OPERATION_KEY], 'INTERNAL_TRANSFORM', 'ANALYZE');
                }

                $registry = new P01Registry($tx, $this->clock);
                $competitor = $this->competitorState($scope, $r['competitor'], $tx)['id'] ?? null;
                if ($competitor === null) {
                    $made = $registry->registerCompetitor($scope['tenant_id'], $scope['workspace_id'], $r['competitor']['display_name'], $r['competitor']['competitor_class'], 'OPERATOR', $scope['operator_id'], $r['competitor']['organization_number']);
                    if (!$made->isOk()) { return $made; }
                    $competitor = $made->value()['competitor_id'];
                }
                $card = $this->cardState($scope, $r['source'], $tx);
                if ($card['action'] === 'CREATE') {
                    $cards = new SourceCards($tx, $this->clock);
                    $projected = $cards->project(self::card($scope, $r['source']));
                    if ($projected['outcome'] !== 'PROJECTED') {
                        return Outcome::refuse('MONITOR_SOURCE_CARD_NOT_INGESTABLE', ['reason_code' => $projected['reason_code'] ?? null]);
                    }
                    $card = ['id' => $projected['source_license_card_id'], 'source_ref_id' => $projected['source_ref_id']];
                    $review = $r['source']['review'];
                    $reviewed = $cards->recordTermsReview([
                        'tenant_id' => $scope['tenant_id'],
                        'source_license_card_id' => $card['id'],
                        'reviewed_by' => $scope['operator_id'],
                        'reviewed_at' => $review['approved_at'],
                        'robots_policy_checked' => true,
                        'terms_policy_checked' => true,
                        // The private review-note digest belongs to the setup receipt. It is not
                        // a hash of source terms bytes, which setup does not possess.
                        'terms_snapshot_hash' => null,
                    ]);
                    if (!$reviewed->isOk()) { return $reviewed; }
                }
                $monitored = $this->monitoredState($scope, $competitor, $card['source_ref_id'], $tx)['id'] ?? null;
                if ($monitored === null) {
                    $made = $registry->registerMonitoredSource($scope['tenant_id'], $scope['workspace_id'], $competitor, $card['source_ref_id'], $card['id'], 'WEBSITE');
                    if (!$made->isOk()) { return $made; }
                    $monitored = $made->value()['monitored_source_id'];
                }

                $policies = new PolicySets($tx, $this->clock);
                $p01 = $policies->seed(PolicySets::P01_COMPETITION_RELEVANCE, $scope['tenant_id'], $scope['workspace_id']);
                $p04 = $policies->seed(PolicySets::P04_DISTRIBUTION_ADMISSION, $scope['tenant_id'], $scope['workspace_id']);
                if (!$p01->isOk() || !$p04->isOk()) { return Outcome::failure('MONITOR_SETUP_POLICY_SEED_FAILED'); }
                if ($steps['DISTRIBUTION_TARGET']['action'] === 'CREATE') {
                    $configured = (new Distribution($tx, $this->clock, $ledger))->configureRoleRedactionPolicy(['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'target_org_role' => $r['target']['target_org_role'], 'redaction_rules' => $r['target']['redaction_rules']]);
                    if (!$configured->isOk()) { return $configured; }
                }

                $profileSourceRow = $this->profileSource($scope, $tx);
                if ($profileSourceRow === null || ($profileSourceRow['ambiguous'] ?? false) === true || $profileSourceRow['status'] !== 'ACTIVE_FOR_CAPTURE') {
                    return Outcome::refuse('MONITOR_PROFILE_SOURCE_NOT_CURRENT');
                }
                $profileSource = $profileSourceRow['source_ref_id'];
                $sources = [$profileSource, $card['source_ref_id']];
                $predecessor = $successorOf === null ? null : $tx->one('SELECT setup_receipt_id,mission_id FROM monitor_setup_receipts WHERE setup_receipt_id=? AND tenant_id=? AND workspace_id=?', [$successorOf, $scope['tenant_id'], $scope['workspace_id']]);
                $missionId = $m04->createMission(
                    $scope['tenant_id'], $scope['workspace_id'], 'COMPETITION_MONITORING',
                    $r['mission']['title'], $r['mission']['objective'], self::MISSION_ACTION_CLASSES,
                    'OPERATOR', $scope['operator_id'], 'DRAFT', ['INTERNAL_INFORMATIONAL'], $sources,
                    [$registration['governed_tool_id'], $modelTool], $predecessor['mission_id'] ?? null,
                );
                $activated = $m04->activateMission($missionId, $scope['operator_id']);
                if (!$activated->isOk()) { return $activated; }
                $capture = $m04->assignToMissionNarrowed($missionId, $registration['valkyrie_id'], $registration['capability_contract_id'], 'SOURCE_READER', [$registration['governed_tool_id']], ['OBSERVE'], $scope['operator_id']);
                $authorAssignment = $m04->assignToMissionNarrowed($missionId, $registration['valkyrie_id'], $registration['capability_contract_id'], 'MODEL_AUTHOR', [$modelTool], ['ANALYZE'], $scope['operator_id']);
                $criticAssignment = $m04->assignToMissionNarrowed($missionId, $critic['valkyrie_id'], $critic['contract_id'], 'MODEL_CRITIC', [$modelTool], ['ANALYZE'], $scope['operator_id']);
                foreach ([$capture, $authorAssignment, $criticAssignment] as $assigned) {
                    if (!$assigned->isOk()) { return $assigned; }
                }

                $common = ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'mission_id' => $missionId, 'calling_actor_type' => 'OPERATOR', 'calling_actor_id' => $scope['operator_id'], 'required_entitlement_permission' => self::ENTITLEMENT, 'governed_tool_id' => $modelTool, 'governed_tool_operation_id' => $ids['model']['governed_tool_operation_id'], 'requested_action_class' => 'ANALYZE'];
                $authorGovernance = $common + ['assignment_id' => $authorAssignment->value()['assignment_id'], 'valkyrie_id' => $registration['valkyrie_id'], 'capability_contract_id' => $registration['capability_contract_id']];
                $definition = [
                    'mission_id' => $missionId, 'monitored_source_id' => $monitored, 'capture_url' => $r['source']['url'],
                    'capture_governance' => ['mission_id' => $missionId, 'assignment_id' => $capture->value()['assignment_id'], 'valkyrie_id' => $registration['valkyrie_id'], 'capability_contract_id' => $registration['capability_contract_id'], 'governed_tool_id' => $registration['governed_tool_id'], 'governed_tool_operation_id' => $registration['governed_tool_operation_id'], 'calling_actor_type' => 'OPERATOR', 'calling_actor_id' => $scope['operator_id']],
                    'model_governance' => ['author' => $authorGovernance, 'critic' => $common + ['assignment_id' => $criticAssignment->value()['assignment_id'], 'valkyrie_id' => $critic['valkyrie_id'], 'capability_contract_id' => $critic['contract_id']]],
                    'release_governance' => ['mission_id' => $missionId, 'producing_valkyrie_id' => $registration['valkyrie_id'], 'capability_contract_id' => $registration['capability_contract_id']],
                    'context_selection' => ['mission_id' => $missionId, 'purpose' => $r['mission']['objective'], 'audience' => $r['target']['audience'], 'selected_target_org_role' => $r['target']['target_org_role'], 'limits' => $r['context_limits'], 'profile_evidence_ids' => $r['profile_evidence_ids'], 'source_ids' => $sources],
                    'credential_id' => $r['credential_id'], 'target_org_role' => $r['target']['target_org_role'],
                    'p01_policy_id' => $p01->value()['arbiter_policy_set_id'], 'p04_policy_id' => $p04->value()['arbiter_policy_set_id'],
                ];
                $configured = (new MissionConfiguration($tx, $this->clock))->configure($scope, $definition, 'NATIVE');
                if (!$configured->isOk()) { return $configured; }

                $outcome = ['steps' => $plan['steps'], 'objects' => [
                    'model_governed_tool_id' => $modelTool, 'source_read_binding_id' => $ids['binding_id'],
                    'monitor_valkyrie_id' => $registration['valkyrie_id'], 'monitor_contract_id' => $registration['capability_contract_id'],
                    'critic_valkyrie_id' => $critic['valkyrie_id'], 'critic_contract_id' => $critic['contract_id'],
                    'competitor_id' => $competitor, 'source_license_card_id' => $card['id'], 'source_ref_id' => $card['source_ref_id'],
                    'monitored_source_id' => $monitored, 'profile_source_ref_id' => $profileSource, 'profile_evidence_ids' => $r['profile_evidence_ids'],
                    'p01_policy_id' => $definition['p01_policy_id'], 'p04_policy_id' => $definition['p04_policy_id'],
                    'target_org_role' => $r['target']['target_org_role'], 'audience' => $r['target']['audience'], 'credential_id' => $r['credential_id'],
                    'source_review_evidence_reference' => $r['source']['review']['evidence_reference'],
                    'source_review_evidence_sha256' => $r['source']['review']['evidence_sha256'],
                    'source_reviewed_by_operator_id' => $r['source']['review']['approved_by_operator_id'],
                ], 'supersedes_mission_id' => $predecessor['mission_id'] ?? null];
                $receiptId = Ids::new();
                $tx->insert('monitor_setup_receipts', ['setup_receipt_id' => $receiptId, 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'setup_key' => $r['setup_key'], 'revision' => $plan['revision'], 'predecessor_receipt_id' => $predecessor['setup_receipt_id'] ?? null, 'request_digest' => $plan['request_digest'], 'plan_digest' => $plan['plan_digest'], 'mission_id' => $missionId, 'definition_digest' => $configured->value()['definition_digest'], 'outcome_json' => json_encode($outcome, JSON_THROW_ON_ERROR), 'applied_by_operator_id' => $scope['operator_id'], 'created_at' => $this->clock->now()]);

                return Outcome::ok($this->summary($tx->one('SELECT * FROM monitor_setup_receipts WHERE setup_receipt_id=?', [$receiptId]), false));
            });
        } catch (\Throwable) {
            return Outcome::failure('MONITOR_SETUP_FAILED');
        }
    }

    /** The latest receipt for a setup key, as the operator summary. */
    public function receipt(array $scope, string $setupKey): Outcome
    {
        $scope = OperatorScope::normalize($scope);
        if ($scope === null || !$this->holds($scope, ['mission.create'])) {
            return Outcome::refuse('MONITOR_SETUP_DENIED');
        }
        $row = $this->db->one('SELECT * FROM monitor_setup_receipts WHERE tenant_id=? AND workspace_id=? AND setup_key=? ORDER BY revision DESC LIMIT 1', [$scope['tenant_id'], $scope['workspace_id'], $setupKey]);

        return $row === null ? Outcome::refuse('MONITOR_SETUP_RECEIPT_NOT_FOUND') : Outcome::ok($this->summary($row, true));
    }

    /** @return Outcome<array<string, mixed>> the normalized request, or the first invalid field */
    public static function validateRequest(array $q): Outcome
    {
        $bad = static fn (string $field): Outcome => Outcome::refuse('MONITOR_SETUP_REQUEST_INVALID', ['field' => $field]);
        $keys = ['setup_key', 'mission', 'competitor', 'source', 'profile_evidence_ids', 'target', 'credential_id', 'context_limits'];
        if (array_diff(array_keys($q), $keys) !== [] || array_diff($keys, array_keys($q)) !== []) {
            return $bad('keys');
        }
        $text = static fn ($v, int $max): bool => is_string($v) && trim($v) !== '' && mb_strlen($v) <= $max && !preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $v);
        $uuid = static fn ($v): bool => is_string($v) && preg_match('/^[a-f0-9-]{36}$/D', $v) === 1;
        if (!is_string($q['setup_key']) || preg_match('/^[a-z0-9][a-z0-9-]{2,62}$/D', $q['setup_key']) !== 1) { return $bad('setup_key'); }
        $m = $q['mission'];
        if (!is_array($m) || array_keys($m) !== ['title', 'objective'] || !$text($m['title'], 160) || !$text($m['objective'], 600)) { return $bad('mission'); }
        $c = $q['competitor'];
        if (!is_array($c) || array_diff(array_keys($c), ['display_name', 'competitor_class', 'organization_number']) !== [] || !$text($c['display_name'] ?? null, 160)
            || !in_array($c['competitor_class'] ?? null, ['DIRECT', 'INDIRECT', 'ADJACENT', 'MARKET_REFERENCE', 'OTHER'], true)
            || (($c['organization_number'] ?? null) !== null && !$text($c['organization_number'], 32))) { return $bad('competitor'); }
        $c += ['organization_number' => null];
        $src = $q['source'];
        $sourceKeys = ['url', 'source_name', 'license_class', 'allowed_use_class', 'customer_output_allowed', 'derived_summary_allowed', 'caching_policy', 'retention_class', 'review'];
        if (!is_array($src) || array_diff(array_keys($src), $sourceKeys) !== [] || array_diff($sourceKeys, array_keys($src)) !== []) { return $bad('source'); }
        $url = $src['url'];
        if (!is_string($url) || strlen($url) > 2048 || !str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) { return $bad('source.url'); }
        if (!$text($src['source_name'], 160) || !in_array($src['license_class'], ['PUBLIC_WEB_ALLOWED', 'PUBLIC_WEB_RESTRICTED'], true) || !in_array($src['allowed_use_class'], ['observe_only', 'evidence_capture', 'derived_summary_allowed'], true)
            || !is_bool($src['customer_output_allowed']) || !is_bool($src['derived_summary_allowed']) || !in_array($src['caching_policy'], ['no_cache', 'short_cache', 'standard_cache'], true) || !in_array($src['retention_class'], ['short', 'standard'], true)) { return $bad('source.card'); }
        // The terms/robots review is SUPPLIED evidence from a named reviewer. Setup never infers it
        // from a reachable URL and never fetches anything.
        $rv = $src['review'];
        $reviewKeys = ['approved_by_operator_id', 'approved_at', 'evidence_reference', 'evidence_sha256', 'robots_policy_checked', 'terms_policy_checked'];
        if (!is_array($rv) || array_diff(array_keys($rv), $reviewKeys) !== [] || array_diff($reviewKeys, array_keys($rv)) !== []
            || !$uuid($rv['approved_by_operator_id'] ?? null) || !$text($rv['evidence_reference'], 300)
            || !is_string($rv['evidence_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D',$rv['evidence_sha256'])!==1
            || !is_string($rv['approved_at']) || !Clock::isCanonical($rv['approved_at'])
            || $rv['robots_policy_checked'] !== true || $rv['terms_policy_checked'] !== true) { return $bad('source.review'); }
        $p = $q['profile_evidence_ids'];
        if (!is_array($p) || !array_is_list($p) || $p === [] || count($p) > 4 || count(array_unique($p)) !== count($p)) { return $bad('profile_evidence_ids'); }
        foreach ($p as $id) { if (!$uuid($id)) { return $bad('profile_evidence_ids'); } }
        $t = $q['target'];
        $targetKeys=['target_org_role','redaction_rules','audience'];
        if (!is_array($t) || array_diff(array_keys($t),$targetKeys)!==[] || array_diff(['target_org_role','redaction_rules'],array_keys($t))!==[]
            || !in_array($t['target_org_role']??null,Distribution::TARGET_ORG_ROLES,true) || !is_array($t['redaction_rules']??null)
            || !array_is_list($t['redaction_rules']) || count($t['redaction_rules'])>32) { return $bad('target'); }
        foreach($t['redaction_rules'] as $rule)if(!is_string($rule)||mb_strlen($rule)>500)return $bad('target.redaction_rules');
        $audience=$t['audience']??(($t['target_org_role']??null)==='SALES'?'SALES':null);
        if(!is_string($audience)||!in_array($audience,ContextEnvelope::AUDIENCES,true))return $bad('target.audience');
        $t=['target_org_role'=>$t['target_org_role'],'redaction_rules'=>$t['redaction_rules'],'audience'=>$audience];
        if (!$uuid($q['credential_id'])) { return $bad('credential_id'); }
        $l = $q['context_limits'];
        $limits = ['max_input_bytes' => 65536, 'max_output_tokens' => 2048, 'max_model_calls' => 4, 'max_observation_age_seconds' => 31536000];
        if (!is_array($l) || array_diff(array_keys($l), array_keys($limits)) !== [] || array_diff(array_keys($limits), array_keys($l)) !== []) { return $bad('context_limits'); }
        foreach ($limits as $k => $max) { if (!is_int($l[$k]) || $l[$k] < 1 || $l[$k] > $max) { return $bad('context_limits.' . $k); } }
        if ($l['max_output_tokens'] < 16) { return $bad('context_limits.max_output_tokens'); }

        return Outcome::ok(['setup_key' => $q['setup_key'], 'mission' => $m, 'competitor' => ['display_name' => $c['display_name'], 'competitor_class' => $c['competitor_class'], 'organization_number' => $c['organization_number']], 'source' => $src, 'profile_evidence_ids' => $p, 'target' => $t, 'credential_id' => $q['credential_id'], 'context_limits' => $l]);
    }

    // ---------------------------------------------------------------- read-only state probes

    private function holds(array $scope, array $permissions): bool
    {
        $guard = new AccessGuard($this->db, $this->clock);
        foreach ($permissions as $permission) {
            if ($guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], $permission, 'MONITOR_SETUP')['decision'] !== 'ALLOW') {
                return false;
            }
        }

        return true;
    }

    private function profileSource(array $scope, ?Database $db = null): ?array
    {
        $rows = ($db ?? $this->db)->all(
            "SELECT source_ref_id,status FROM source_references WHERE tenant_id=? AND workspace_id=? AND source_type='UPLOADED_ARTIFACT' AND source_location_safe=? ORDER BY source_ref_id",
            [$scope['tenant_id'], $scope['workspace_id'], self::PROFILE_LOCATION]
        );
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            return ['source_ref_id' => null, 'status' => null, 'ambiguous' => true];
        }

        return $rows[0] + ['ambiguous' => false];
    }

    /** Mirrors P01 Registration's own reuse checks, so a revoked, narrowed or widened registration refuses. */
    private function registrationState(array $scope, ?string $bindingId): array
    {
        $prior = $this->db->one("SELECT outcome_json FROM p01_registration_receipts WHERE tenant_id=? AND workspace_id=? AND tool_key='p01.source.read'", [$scope['tenant_id'], $scope['workspace_id']]);
        $tool = $this->db->one("SELECT * FROM governed_tools WHERE tool_key='p01.source.read' AND descriptor_version='1.0.0'");
        if ($tool !== null) {
            $expectedTool = [
                'display_name' => 'Competitor source read',
                'purpose_summary' => 'Read an approved public competitor source page.',
                'tool_class' => 'READ_ONLY_SOURCE',
                'risk_level' => 'T1',
                'maximum_action_class' => 'OBSERVE',
                'runtime_mapping_ref_id' => $bindingId,
                'evidence_capture_required_by_default' => 1,
                'approval_required' => 0,
                'frozen_artifact_required' => 0,
                'status' => 'ACTIVE',
            ];
            foreach ($expectedTool as $key => $value) {
                if (($value === null && $tool[$key] !== null) || ($value !== null && (string) $tool[$key] !== (string) $value)) {
                    return ['action' => 'CONFLICT', 'reason' => 'P01_SHARED_TOOL_CONFLICT'];
                }
            }
            $operations = $this->db->all("SELECT * FROM governed_tool_operations WHERE governed_tool_id=? AND operation_key='source.read'", [$tool['governed_tool_id']]);
            $operation = $operations[0] ?? null;
            $expectedOperation = [
                'operation_summary' => 'Fetch one approved source URL.',
                'operation_class' => 'READ',
                'classified_action_class' => 'OBSERVE',
                'requires_mission' => 1,
                'requires_valkyrie_contract' => 1,
                'requires_evidence_capture' => 1,
                'requires_approval' => 0,
                'requires_frozen_artifact' => 0,
                'status' => 'ACTIVE',
            ];
            if (count($operations) !== 1 || $operation === null) {
                return ['action' => 'CONFLICT', 'reason' => 'P01_SHARED_TOOL_CONFLICT'];
            }
            foreach ($expectedOperation as $key => $value) {
                if ((string) $operation[$key] !== (string) $value) {
                    return ['action' => 'CONFLICT', 'reason' => 'P01_SHARED_TOOL_CONFLICT'];
                }
            }
        }
        if ($prior === null) {
            return ['action' => 'CREATE'];
        }

        $saved = json_decode((string) $prior['outcome_json'], true, 32, JSON_THROW_ON_ERROR);
        foreach (['valkyrie_id','capability_contract_id','tool_right_id','governed_tool_id','governed_tool_operation_id','arbiter_policy_set_id','subscription_tier_id'] as $required) {
            if (!is_string($saved[$required] ?? null) || $saved[$required] === '') {
                return ['action' => 'REFUSE', 'reason' => 'P01_REGISTRATION_STATE_CHANGED'];
            }
        }
        $live = $this->db->one("SELECT 1 FROM valkyries v JOIN capability_contracts c ON c.capability_contract_id=v.active_capability_contract_id JOIN arbiter_policy_sets ps ON ps.arbiter_policy_set_id=? WHERE v.valkyrie_id=? AND v.tenant_id=? AND v.workspace_id=? AND v.status='ACTIVE' AND c.capability_contract_id=? AND c.status='ACTIVE' AND ps.status='ACTIVE'", [$saved['arbiter_policy_set_id'], $saved['valkyrie_id'], $scope['tenant_id'], $scope['workspace_id'], $saved['capability_contract_id']]);

        $rights = $this->db->all('SELECT * FROM tool_rights WHERE capability_contract_id=? AND governed_tool_id=?', [$saved['capability_contract_id'], $saved['governed_tool_id']]);
        $right = $rights[0] ?? null;
        $rightsCurrent = count($rights) === 1 && $right !== null
            && $right['tool_right_id'] === $saved['tool_right_id'] && $right['status'] === 'ACTIVE'
            && $right['tool_class'] === 'READ_ONLY_SOURCE' && $right['maximum_action_class'] === 'OBSERVE'
            && (int) $right['evidence_capture_required'] === 1 && (int) $right['approval_required'] === 0
            && (int) $right['frozen_artifact_required'] === 0
            && json_decode((string) $right['allowed_operations'], true, 32, JSON_THROW_ON_ERROR) === ['source.read'];

        $contract = $this->db->one('SELECT tenant_id,workspace_id,allowed_action_classes,role_class,human_approval_or_execution_authority FROM capability_contracts WHERE capability_contract_id=?', [$saved['capability_contract_id']]);
        $actions = $contract === null ? [] : json_decode((string) $contract['allowed_action_classes'], true, 32, JSON_THROW_ON_ERROR);
        sort($actions, SORT_STRING);
        $contractCurrent = $contract !== null && $contract['tenant_id'] === $scope['tenant_id'] && $contract['workspace_id'] === $scope['workspace_id']
            && $contract['role_class'] === 'COMPETITION_MONITOR_VALKYRIE' && (int) $contract['human_approval_or_execution_authority'] === 0
            && $actions === ['ANALYZE','OBSERVE','RELEASE_INTELLIGENCE'];

        $tier = $this->db->one('SELECT * FROM subscription_tiers WHERE subscription_tier_id=?', [$saved['subscription_tier_id']]);
        $tierPermissions = $tier === null ? [] : array_column($this->db->all('SELECT permission_key FROM subscription_tier_permissions WHERE subscription_tier_id=? ORDER BY permission_key', [$saved['subscription_tier_id']]), 'permission_key');
        $tierCurrent = $tier !== null && $tier['tier_key'] === 'p01_monitor' && $tier['tier_version'] === '1'
            && $tier['display_name'] === 'P01 Competition Monitor' && (int) $tier['monthly_fee_minor_units'] === 249900
            && $tier['currency'] === 'SEK' && $tier['billing_period'] === 'MONTHLY' && $tier['status'] === 'ACTIVE'
            && $tierPermissions === self::P01_TIER_PERMISSIONS;

        $toolCurrent = $tool !== null && $saved['governed_tool_id'] === $tool['governed_tool_id']
            && $saved['governed_tool_operation_id'] === ($operation['governed_tool_operation_id'] ?? null);

        if ($live === null || !$rightsCurrent || !$contractCurrent || !$tierCurrent || !$toolCurrent) {
            return ['action' => 'REFUSE', 'reason' => 'P01_REGISTRATION_STATE_CHANGED'];
        }

        return ['action' => 'REUSE', 'ids' => $saved];
    }

    /** An ACTIVE exact model right is reused; any other right on the pair is never re-granted. */
    private function rightState(?string $contractId, ?string $toolId, ?Database $db = null): array
    {
        if ($contractId === null || $toolId === null) {
            return ['action' => 'CREATE'];
        }
        $rows = ($db ?? $this->db)->all(
            'SELECT tool_right_id,status,allowed_operations,tool_class,maximum_action_class,approval_required,frozen_artifact_required,evidence_capture_required
             FROM tool_rights WHERE capability_contract_id=? AND governed_tool_id=?',
            [$contractId, $toolId]
        );
        if ($rows === []) {
            return ['action' => 'CREATE'];
        }
        $r = $rows[0];
        if (count($rows) === 1 && $r['status'] === 'ACTIVE'
            && json_decode($r['allowed_operations'], true) === [ModelRegistration::OPERATION_KEY]
            && $r['tool_class'] === 'INTERNAL_TRANSFORM' && $r['maximum_action_class'] === 'ANALYZE'
            && (int) $r['approval_required'] === 0 && (int) $r['frozen_artifact_required'] === 0
            && (int) $r['evidence_capture_required'] === 1) {
            return ['action' => 'REUSE', 'id' => $r['tool_right_id']];
        }

        return ['action' => 'REFUSE', 'reason' => 'MODEL_TOOL_RIGHT_NOT_CURRENT'];
    }

    private function criticState(array $scope, ?Database $db = null): array
    {
        $rows = ($db ?? $this->db)->all("SELECT v.valkyrie_id,v.status,v.active_capability_contract_id,c.status AS contract_status,c.allowed_action_classes,c.purpose_summary FROM valkyries v LEFT JOIN capability_contracts c ON c.capability_contract_id=v.active_capability_contract_id WHERE v.tenant_id=? AND v.workspace_id=? AND v.display_name=? AND v.canonical_role_class='REVIEW_CRITIC_VALKYRIE'", [$scope['tenant_id'], $scope['workspace_id'], self::CRITIC_NAME]);
        if ($rows === []) {
            return ['action' => 'CREATE'];
        }
        $v = $rows[0];
        if (count($rows) === 1 && $v['status'] === 'ACTIVE' && $v['contract_status'] === 'ACTIVE' && $v['purpose_summary'] === self::CRITIC_CONTRACT_KEY && json_decode((string) $v['allowed_action_classes'], true) === ['ANALYZE']) {
            return ['action' => 'REUSE', 'valkyrie_id' => $v['valkyrie_id'], 'contract_id' => $v['active_capability_contract_id']];
        }

        return ['action' => 'REFUSE', 'reason' => 'CRITIC_NOT_CURRENT'];
    }

    private function competitorState(array $scope, array $c, ?Database $db = null): array
    {
        $rows = ($db ?? $this->db)->all('SELECT competitor_id,status,competitor_class,organization_number FROM competitors WHERE tenant_id=? AND workspace_id=? AND display_name=?', [$scope['tenant_id'], $scope['workspace_id'], $c['display_name']]);
        if ($rows === []) {
            return ['action' => 'CREATE'];
        }
        if (count($rows) > 1) {
            return ['action' => 'CONFLICT', 'reason' => 'COMPETITOR_AMBIGUOUS'];
        }
        if ($rows[0]['status'] !== 'ACTIVE') {
            return ['action' => 'REFUSE', 'reason' => 'COMPETITOR_NOT_ACTIVE'];
        }
        if ($rows[0]['competitor_class'] !== $c['competitor_class']) {
            return ['action' => 'CONFLICT', 'reason' => 'COMPETITOR_CLASS_DIFFERS'];
        }
        if (($rows[0]['organization_number'] ?? null) !== $c['organization_number']) {
            return ['action' => 'CONFLICT', 'reason' => 'COMPETITOR_ORGANIZATION_NUMBER_DIFFERS'];
        }

        return ['action' => 'REUSE', 'id' => $rows[0]['competitor_id']];
    }

    private static function card(array $scope, array $s): array
    {
        return ['tenant_id' => $scope['tenant_id'], 'source_name' => $s['source_name'], 'source_url_safe' => $s['url'], 'source_type' => 'website', 'allowed_use_class' => $s['allowed_use_class'], 'license_class' => $s['license_class'], 'customer_output_allowed' => $s['customer_output_allowed'], 'derived_summary_allowed' => $s['derived_summary_allowed'], 'robots_policy_checked' => true, 'terms_policy_checked' => true, 'review_status' => 'approved', 'approved_by' => $s['review']['approved_by_operator_id'], 'approved_at' => $s['review']['approved_at'], 'contract_reference_id' => null, 'caching_policy' => $s['caching_policy'], 'retention_class' => $s['retention_class'], 'blocked_reason' => null];
    }

    private function cardState(array $scope, array $s, ?Database $db = null): array
    {
        $want = self::card($scope, $s);
        $check = SourceCards::evaluateAdmissibility($want);
        if (!$check['admissible']) {
            return ['action' => 'REFUSE', 'reason' => $check['reason_code']];
        }
        $rows = ($db ?? $this->db)->all('SELECT c.*,r.status AS reference_status FROM source_license_cards c JOIN source_references r ON r.source_ref_id=c.source_ref_id WHERE c.tenant_id=? AND c.source_url_safe=? ORDER BY c.created_at,c.source_license_card_id', [$scope['tenant_id'], $s['url']]);
        if ($rows === []) {
            return ['action' => 'CREATE'];
        }
        // Source cards have no canonical "latest active row" selector. More than one card for the
        // same tenant+URL is therefore ambiguous authority; setup must not pick an older convenient
        // match and ignore a different review that also exists.
        if (count($rows) !== 1) {
            return ['action' => 'CONFLICT', 'reason' => 'SOURCE_CARD_AMBIGUOUS'];
        }
        foreach ($rows as $row) {
            $same = $row['source_name'] === $want['source_name'] && $row['source_type'] === 'website' && $row['license_class'] === $want['license_class'] && $row['allowed_use_class'] === $want['allowed_use_class']
                && (int) $row['customer_output_allowed'] === (int) $want['customer_output_allowed'] && (int) $row['derived_summary_allowed'] === (int) $want['derived_summary_allowed']
                && (int) $row['robots_policy_checked'] === 1 && (int) $row['terms_policy_checked'] === 1 && $row['review_status'] === 'approved'
                && $row['approved_by'] === $want['approved_by'] && $row['approved_at'] === $want['approved_at']
                && $row['caching_policy'] === $want['caching_policy'] && $row['retention_class'] === $want['retention_class'];
            if ($same) {
                $review=($db ?? $this->db)->one('SELECT robots_policy_checked,terms_policy_checked,terms_snapshot_hash,reviewed_by,reviewed_at FROM source_terms_reviews WHERE source_license_card_id=? ORDER BY reviewed_at DESC,source_terms_review_id DESC LIMIT 1',[$row['source_license_card_id']]);
                $expectedReview=$s['review'];
                if ($review===null || (int)$review['robots_policy_checked']!==1 || (int)$review['terms_policy_checked']!==1
                    || $review['reviewed_by']!==$expectedReview['approved_by_operator_id']
                    || $review['reviewed_at']!==$expectedReview['approved_at']) {
                    return ['action' => 'CONFLICT', 'reason' => 'SOURCE_REVIEW_DIFFERS'];
                }
                return $row['reference_status'] === 'ACTIVE_FOR_CAPTURE'
                    ? ['action' => 'REUSE', 'id' => $row['source_license_card_id'], 'source_ref_id' => $row['source_ref_id']]
                    : ['action' => 'REFUSE', 'reason' => 'SOURCE_NOT_ACTIVE_FOR_CAPTURE'];
            }
        }

        // A different card for this URL already exists. Its lifecycle belongs to P01 review, not to setup.
        return ['action' => 'CONFLICT', 'reason' => 'SOURCE_CARD_DIFFERS'];
    }

    private function monitoredState(array $scope, ?string $competitorId, ?string $sourceRefId, ?Database $db = null): array
    {
        if ($competitorId === null || $sourceRefId === null) {
            return ['action' => 'CREATE'];
        }
        $rows = ($db ?? $this->db)->all('SELECT monitored_source_id,status FROM monitored_sources WHERE tenant_id=? AND workspace_id=? AND competitor_id=? AND source_ref_id=? ORDER BY created_at,monitored_source_id', [$scope['tenant_id'], $scope['workspace_id'], $competitorId, $sourceRefId]);
        if ($rows === []) {
            return ['action' => 'CREATE'];
        }
        if (count($rows) !== 1) {
            return ['action' => 'CONFLICT', 'reason' => 'MONITORED_SOURCE_AMBIGUOUS'];
        }
        $row = $rows[0];

        return $row['status'] === 'ACTIVE' ? ['action' => 'REUSE', 'id' => $row['monitored_source_id']] : ['action' => 'REFUSE', 'reason' => 'MONITORED_SOURCE_NOT_ACTIVE'];
    }

    private function missionState(array $scope, string $setupKey, string $requestDigest, ?string $successorOf): array
    {
        $latest = $this->db->one('SELECT * FROM monitor_setup_receipts WHERE tenant_id=? AND workspace_id=? AND setup_key=? ORDER BY revision DESC LIMIT 1', [$scope['tenant_id'], $scope['workspace_id'], $setupKey]);
        if ($latest === null) {
            return $successorOf === null ? ['action' => 'CREATE', 'revision' => 1] : ['action' => 'REFUSE', 'revision' => 1, 'reason' => 'MONITOR_SETUP_SUCCESSOR_NOT_LATEST'];
        }
        if ($successorOf !== null) {
            if ($successorOf !== $latest['setup_receipt_id']) {
                return ['action' => 'REFUSE', 'revision' => (int) $latest['revision'] + 1, 'reason' => 'MONITOR_SETUP_SUCCESSOR_NOT_LATEST'];
            }

            return $latest['request_digest'] === $requestDigest
                ? ['action' => 'REFUSE', 'revision' => (int) $latest['revision'] + 1, 'reason' => 'MONITOR_SETUP_SUCCESSOR_UNCHANGED']
                : ['action' => 'CREATE', 'revision' => (int) $latest['revision'] + 1];
        }
        if ($latest['request_digest'] !== $requestDigest) {
            return ['action' => 'CONFLICT', 'revision' => (int) $latest['revision'], 'mission_id' => $latest['mission_id'], 'reason' => 'MONITOR_SETUP_REQUEST_CHANGED'];
        }
        $config = $this->db->one('SELECT m.status,c.definition_digest FROM missions m JOIN monitor_mission_configs c ON c.mission_id=m.mission_id WHERE m.mission_id=? AND m.tenant_id=? AND m.workspace_id=?', [$latest['mission_id'], $scope['tenant_id'], $scope['workspace_id']]);
        if ($config === null || $config['status'] !== 'ACTIVE' || $config['definition_digest'] !== $latest['definition_digest']) {
            return ['action' => 'REFUSE', 'revision' => (int) $latest['revision'], 'mission_id' => $latest['mission_id'], 'reason' => 'MONITOR_SETUP_STATE_CHANGED'];
        }
        $revalidated = (new MissionConfiguration($this->db, $this->clock))->validate($scope, json_decode($this->db->scalar('SELECT definition_json FROM monitor_mission_configs WHERE mission_id=?', [$latest['mission_id']]), true, 64, JSON_THROW_ON_ERROR), 'NATIVE');

        return $revalidated->isOk()
            ? ['action' => 'REUSE', 'revision' => (int) $latest['revision'], 'mission_id' => $latest['mission_id']]
            : ['action' => 'REFUSE', 'revision' => (int) $latest['revision'], 'mission_id' => $latest['mission_id'], 'reason' => 'MONITOR_SETUP_STATE_CHANGED:' . $revalidated->reasonCode()];
    }

    private function summary(array $receipt, bool $reused): array
    {
        $outcome = json_decode($receipt['outcome_json'], true, 64, JSON_THROW_ON_ERROR);
        $objects = $outcome['objects'];
        // The private review reference is retained in the append-only receipt for audit, but it may
        // be a private filesystem/ticket location and is not an operator-report field. The digest is
        // enough to identify the reviewed evidence without echoing its location into terminal logs.
        unset($objects['source_review_evidence_reference']);

        return ['setup_receipt_id' => $receipt['setup_receipt_id'], 'setup_key' => $receipt['setup_key'], 'revision' => (int) $receipt['revision'], 'predecessor_receipt_id' => $receipt['predecessor_receipt_id'], 'supersedes_mission_id' => $outcome['supersedes_mission_id'], 'mission_id' => $receipt['mission_id'], 'definition_digest' => $receipt['definition_digest'], 'request_digest' => $receipt['request_digest'], 'plan_digest' => $receipt['plan_digest'], 'steps' => $outcome['steps'], 'objects' => $objects, 'reused_existing_receipt' => $reused, 'grants_roles' => false, 'grants_native_execution' => false, 'activation_granted' => false];
    }
}
