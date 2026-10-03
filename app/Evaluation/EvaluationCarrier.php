<?php

declare(strict_types=1);

namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\{Clock,Digest,Ids,OperatorScope,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Provider\ModelRegistration;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\{Eligibility,Registry};
use Allfather\Loopia\Substrate\M04\Proposals;

/**
 * Private composition of existing owners for synthetic MODEL_EVALUATION, not product Monitor setup.
 * No source/profile, subscription, credential, prompt approval, grant, call or release is created.
 * Planning changes no configuration. M02 denied-access audit retention remains the normal policy.
 * A CLI must hold the ordinary maintenance lease and bind the actual release and fingerprint.
 */
final class EvaluationCarrier
{
    public const TITLE = 'Synthetic MODEL_EVALUATION carrier v1';
    public const PURPOSE = 'Synthetic prompt evaluation only; no real source, company profile, product release or execution admission.';
    public const PERMISSIONS = ['tenant.administer', 'runtime_host_binding.administer', 'valkyrie.register', 'valkyrie.administer_contract', 'mission.create', 'mission.activate'];
    public const ROLES = ['author' => 'REPLAY_TEST_VALKYRIE', 'critic' => 'REVIEW_CRITIC_VALKYRIE'];
    public const PROHIBITIONS = ['NO_EXTERNAL_ACTION', 'NO_PUBLICATION', 'NO_CUSTOMER_VISIBLE_OUTPUT', 'NO_SOURCE_CAPTURE', 'NO_PRODUCT_RELEASE'];

    /** @param array{target:string,release_ref:string,source_fingerprint:string} $binding */
    public function __construct(private readonly Database $db, private readonly Clock $clock, private readonly array $binding) {}

    /** A plan is not an approval. It describes fixed model-only objects and current state. */
    public function plan(array $scope): Outcome
    {
        $scope = OperatorScope::normalize($scope);
        if ($scope === null || !Ids::isValid($scope['tenant_id']) || !Ids::isValid($scope['workspace_id']) || !Ids::isValid($scope['operator_id'])) {
            return Outcome::refuse('EVALUATION_CARRIER_SCOPE_INVALID');
        }
        if (!$this->bindingValid()) {
            return Outcome::refuse('EVALUATION_CARRIER_BINDING_INVALID');
        }
        // Use the existing access owner, including its denied-request audit. No private second RBAC law.
        $guard = new AccessGuard($this->db, $this->clock);
        foreach (self::PERMISSIONS as $permission) {
            if ($guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], $permission, 'MODEL_EVALUATION_CARRIER')['decision'] !== 'ALLOW') {
                return Outcome::refuse('EVALUATION_CARRIER_SETUP_DENIED');
            }
        }
        try {
            return Outcome::ok($this->describe($scope));
        } catch (\DomainException $error) {
            return Outcome::refuse($error->getMessage());
        } catch (\JsonException) {
            return Outcome::refuse('EVALUATION_CARRIER_STATE_UNREADABLE');
        }
    }

    /** Apply only the exact reviewed plan, atomically, using M01/M03/M04/M06 owners. */
    public function apply(array $scope, string $planDigest): Outcome
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $planDigest) !== 1) {
            return Outcome::refuse('EVALUATION_CARRIER_PLAN_DIGEST_REQUIRED');
        }
        $initial = $this->plan($scope);
        if (!$initial->isOk()) { return $initial; }
        if (!hash_equals($initial->value()['plan_digest'], $planDigest)) {
            return Outcome::refuse('EVALUATION_CARRIER_PLAN_CHANGED');
        }
        $scope = OperatorScope::normalize($scope);
        try {
            return $this->db->transaction(function (Database $tx) use ($scope, $planDigest): Outcome {
                // Same lock order as MonitorSetup. No provider/network call occurs while locked.
                $tx->one('SELECT workspace_id FROM workspaces WHERE workspace_id=? AND tenant_id=? FOR UPDATE', [$scope['workspace_id'], $scope['tenant_id']]);
                $tx->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$scope['tenant_id']]);
                $locked = $this->plan($scope);
                if (!$locked->isOk()) { return $locked; }
                $p = $locked->value();
                if (!hash_equals($p['plan_digest'], $planDigest)) {
                    return Outcome::refuse('EVALUATION_CARRIER_PLAN_CHANGED');
                }
                if ($p['carrier_action'] === 'REUSE') {
                    return Outcome::ok($this->receipt($p, $planDigest, true));
                }
                $model = self::value((new ModelRegistration($tx, $this->clock))->ensure($scope));
                $m03 = new Registry($tx, $this->clock);
                $m04 = new Proposals($tx, $this->clock);
                $eligibility = new Eligibility($tx, $this->clock);
                // The evaluation runner retains untrusted model candidates itself. It does not
                // require a product output-class binding. Empty output/source scope is deliberate.
                $mission = $m04->createMission($scope['tenant_id'], $scope['workspace_id'], 'TEST', self::TITLE, self::PURPOSE,
                    ['ANALYZE'], 'OPERATOR', $scope['operator_id'], 'DRAFT', [], [], [$model['governed_tool_id']]);
                self::value($m04->activateMission($mission, $scope['operator_id']));
                foreach (self::ROLES as $role => $class) {
                    $agent = $m03->registerValkyrie($scope['tenant_id'], $scope['workspace_id'], self::TITLE . ' ' . $role, $class, self::PURPOSE, $scope['operator_id'], 'DRAFT');
                    $contract = self::value($m03->issueContract($agent, $scope['tenant_id'], $scope['workspace_id'], self::TITLE . ' ' . $role, $class, ['ANALYZE'], self::PROHIBITIONS))['capability_contract_id'];
                    self::value($m03->changeStatus($agent, 'ACTIVE', 'SYNTHETIC_EVALUATION_CARRIER_CREATED'));
                    $eligibility->grantToolRight($contract, $model['governed_tool_id'], [ModelRegistration::OPERATION_KEY], 'INTERNAL_TRANSFORM', 'ANALYZE', false);
                    self::value($m04->assignToMissionNarrowed($mission, $agent, $contract, 'EVALUATION_' . strtoupper($role), [$model['governed_tool_id']], ['ANALYZE'], $scope['operator_id'], assignedOutputClasses: []));
                }
                $verified = $this->plan($scope);
                if (!$verified->isOk()) { return $verified; }
                self::need($verified->value()['carrier_action'] === 'REUSE', 'EVALUATION_CARRIER_POSTCONDITION_FAILED');
                return Outcome::ok($this->receipt($verified->value(), $planDigest, false));
            });
        } catch (\DomainException $error) {
            return Outcome::refuse($error->getMessage());
        } catch (\Throwable) {
            // A refused owner or SQL failure rolls back the complete composition. Never echo driver data.
            return Outcome::failure('EVALUATION_CARRIER_APPLY_FAILED');
        }
    }

    private function describe(array $scope): array
    {
        $tenant = $this->db->one('SELECT tenant_id,tenant_type,status FROM tenants WHERE tenant_id=?', [$scope['tenant_id']]);
        $workspace = $this->db->one('SELECT workspace_id,tenant_id,environment_class,status FROM workspaces WHERE workspace_id=?', [$scope['workspace_id']]);
        self::need($tenant !== null && in_array($tenant['tenant_type'], ['TEST', 'DEMO'], true) && $workspace !== null && $workspace['environment_class'] === 'TEST', 'EVALUATION_CARRIER_TEST_SCOPE_REQUIRED');
        $memberships = $this->db->all(
            "SELECT m.membership_id,m.role_id,m.role_version,m.status,m.valid_from,m.valid_until,r.status AS role_status,rp.permission_key
             FROM workspace_memberships m JOIN roles r ON r.role_id=m.role_id
             JOIN role_permissions rp ON rp.role_id=r.role_id
             WHERE m.tenant_id=? AND m.workspace_id=? AND m.principal_type='OPERATOR' AND m.principal_id=?
             ORDER BY m.membership_id,rp.permission_key",
            [$scope['tenant_id'], $scope['workspace_id'], $scope['operator_id']]
        );
        $current = (new ModelRegistration($this->db, $this->clock))->current();
        $model = $current === null ? null : self::value($current);
        $missions = $this->db->all('SELECT * FROM missions WHERE tenant_id=? AND workspace_id=? AND title=? ORDER BY mission_id', [$scope['tenant_id'], $scope['workspace_id'], self::TITLE]);
        $agents = $this->db->all('SELECT * FROM valkyries WHERE tenant_id=? AND workspace_id=? AND display_name IN (?,?) ORDER BY display_name,valkyrie_id', [$scope['tenant_id'], $scope['workspace_id'], self::TITLE . ' author', self::TITLE . ' critic']);
        $existing = [];
        $governance = null;
        if ($missions === [] && $agents === []) {
            $action = 'CREATE';
        } else {
            self::need(count($missions) === 1 && count($agents) === 2 && $model !== null, 'EVALUATION_CARRIER_PARTIAL_OR_AMBIGUOUS');
            $mission = $missions[0];
            $tool = $model['governed_tool_id'];
            self::exact($mission, ['mission_type' => 'TEST', 'mission_version' => 1, 'status' => 'ACTIVE', 'objective' => self::PURPOSE, 'purpose_summary' => self::PURPOSE, 'target_scope' => 'WORKSPACE_SCOPED', 'created_by_actor_type' => 'OPERATOR', 'created_by_actor_id' => $scope['operator_id'], 'activated_by_operator_id' => $scope['operator_id'], 'supersedes_mission_id' => null, 'expires_at' => null]);
            self::need($mission['valid_from'] <= $this->clock->now(), 'EVALUATION_CARRIER_STATE_NOT_CURRENT');
            foreach (['requested_action_classes' => ['ANALYZE'], 'permitted_output_classes' => [], 'allowed_source_scope_ids' => [], 'allowed_tool_ids' => [$tool], 'explicit_prohibitions' => ['NO_EXTERNAL_ACTION', 'NO_PUBLICATION', 'NO_CUSTOMER_VISIBLE_OUTPUT']] as $key => $value) {
                self::jsonList($mission, $key, $value);
            }
            $assignments = $this->db->all('SELECT * FROM mission_assignments WHERE mission_id=? ORDER BY assignment_role,assignment_id', [$mission['mission_id']]);
            self::need(count($assignments) === 2, 'EVALUATION_CARRIER_ASSIGNMENT_CONFLICT');
            $existing = ['mission' => $mission, 'agents' => []];
            $governance = [];
            foreach (self::ROLES as $role => $class) {
                $matched = array_values(array_filter($agents, static fn (array $a): bool => $a['display_name'] === self::TITLE . ' ' . $role));
                self::need(count($matched) === 1, 'EVALUATION_CARRIER_AGENT_CONFLICT');
                $agent = $matched[0];
                self::exact($agent, ['canonical_role_class' => $class, 'purpose_summary' => self::PURPOSE, 'created_by_operator_id' => $scope['operator_id'], 'status' => 'ACTIVE']);
                $contracts = $this->db->all('SELECT * FROM capability_contracts WHERE valkyrie_id=? ORDER BY capability_contract_id', [$agent['valkyrie_id']]);
                self::need(count($contracts) === 1, 'EVALUATION_CARRIER_CONTRACT_CONFLICT');
                $contract = $contracts[0];
                self::exact($contract, ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'contract_version' => 1, 'role_class' => $class, 'purpose_summary' => self::TITLE . ' ' . $role, 'human_approval_or_execution_authority' => 0, 'status' => 'ACTIVE', 'valid_until' => null]);
                self::need($contract['valid_from'] <= $this->clock->now() && $agent['active_capability_contract_id'] === $contract['capability_contract_id'], 'EVALUATION_CARRIER_STATE_NOT_CURRENT');
                self::jsonList($contract, 'allowed_action_classes', ['ANALYZE']);
                $rights = $this->db->all('SELECT * FROM tool_rights WHERE capability_contract_id=? ORDER BY tool_right_id', [$contract['capability_contract_id']]);
                self::need(count($rights) === 1, 'EVALUATION_CARRIER_TOOL_RIGHT_CONFLICT');
                self::exact($rights[0], ['governed_tool_id' => $tool, 'tool_class' => 'INTERNAL_TRANSFORM', 'maximum_action_class' => 'ANALYZE', 'approval_required' => 0, 'frozen_artifact_required' => 0, 'evidence_capture_required' => 0, 'status' => 'ACTIVE']);
                self::jsonList($rights[0], 'allowed_operations', [ModelRegistration::OPERATION_KEY]);
                $clauses = $this->db->all('SELECT forbidden_category,description,severity_if_attempted,incident_evaluation_required,status FROM forbidden_action_clauses WHERE capability_contract_id=? ORDER BY forbidden_category', [$contract['capability_contract_id']]);
                $expected = self::PROHIBITIONS;
                sort($expected, SORT_STRING);
                self::need(array_column($clauses, 'forbidden_category') === $expected, 'EVALUATION_CARRIER_PROHIBITIONS_CONFLICT');
                foreach ($clauses as $clause) {
                    self::exact($clause, ['description' => $clause['forbidden_category'], 'severity_if_attempted' => 'S5', 'incident_evaluation_required' => 1, 'status' => 'ACTIVE']);
                }
                $outputs = $this->db->all('SELECT * FROM output_class_bindings WHERE capability_contract_id=?', [$contract['capability_contract_id']]);
                self::need($outputs === [], 'EVALUATION_CARRIER_OUTPUT_CONFLICT');
                $matched = array_values(array_filter($assignments, static fn (array $a): bool => $a['assignment_role'] === 'EVALUATION_' . strtoupper($role)));
                self::need(count($matched) === 1, 'EVALUATION_CARRIER_ASSIGNMENT_CONFLICT');
                $assignment = $matched[0];
                self::exact($assignment, ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'valkyrie_id' => $agent['valkyrie_id'], 'capability_contract_id' => $contract['capability_contract_id'], 'capability_contract_version_id' => $contract['capability_contract_id'], 'status' => 'ACTIVE', 'assigned_by_operator_id' => $scope['operator_id'], 'valid_until' => null]);
                self::need($assignment['assigned_at'] <= $this->clock->now(), 'EVALUATION_CARRIER_STATE_NOT_CURRENT');
                foreach (['assigned_action_classes' => ['ANALYZE'], 'assigned_tool_ids' => [$tool], 'assigned_output_classes' => []] as $key => $value) {
                    self::jsonList($assignment, $key, $value);
                }
                $existing['agents'][$role] = compact('agent', 'contract', 'rights', 'clauses', 'outputs', 'assignment');
                $governance[$role] = [
                    'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
                    'mission_id' => $mission['mission_id'], 'assignment_id' => $assignment['assignment_id'],
                    'valkyrie_id' => $agent['valkyrie_id'], 'capability_contract_id' => $contract['capability_contract_id'],
                    'governed_tool_id' => $tool, 'governed_tool_operation_id' => $model['governed_tool_operation_id'],
                    'calling_actor_type' => 'OPERATOR', 'calling_actor_id' => $scope['operator_id'],
                    'required_entitlement_permission' => 'competition_monitoring.read', 'requested_action_class' => 'ANALYZE',
                ];
            }
            $action = 'REUSE';
        }
        $plan = [
            'schema_version' => '1.0', 'kind' => 'MODEL_EVALUATION_CARRIER_PLAN', 'binding' => $this->binding,
            'scope' => array_intersect_key($scope, array_flip(['tenant_id', 'workspace_id', 'operator_id'])),
            'carrier_action' => $action, 'descriptor_action' => $model === null ? 'CREATE' : 'REUSE',
            'state_digest' => Digest::canonicalDigest(compact('tenant', 'workspace', 'memberships', 'model', 'existing')),
            'definition' => ['mission_type' => 'TEST', 'title' => self::TITLE, 'purpose' => self::PURPOSE, 'roles' => self::ROLES,
                'action_classes' => ['ANALYZE'], 'source_scope' => [], 'output_classes' => [], 'prohibitions' => self::PROHIBITIONS],
            'governance' => $governance, 'configuration_writes_performed' => 0, 'execution_authority_created' => false,
        ];
        return $plan + ['plan_digest' => Digest::canonicalDigest($plan)];
    }

    private function bindingValid(): bool
    {
        return count($this->binding) === 3
            && is_string($this->binding['target'] ?? null) && preg_match('/^[a-z0-9][a-z0-9.-]{0,252}$/D', $this->binding['target']) === 1
            && is_string($this->binding['release_ref'] ?? null) && preg_match('/^[a-f0-9]{40}$/D', $this->binding['release_ref']) === 1
            && is_string($this->binding['source_fingerprint'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $this->binding['source_fingerprint']) === 1;
    }

    private function receipt(array $plan, string $reviewed, bool $reused): array
    {
        return ['status' => $reused ? 'ALREADY_CONFIGURED_MATCH' : 'SYNTHETIC_CARRIER_CONFIGURED',
            'reviewed_plan_digest' => $reviewed, 'current_plan_digest' => $plan['plan_digest'],
            'binding' => $this->binding, 'governance' => $plan['governance'], 'reused' => $reused,
            'execution_authority_created' => false, 'prompt_approval_created' => false, 'provider_calls' => 0];
    }

    private static function need(bool $condition, string $reason): void
    {
        if (!$condition) { throw new \DomainException($reason); }
    }

    private static function value(Outcome $outcome): mixed
    {
        self::need($outcome->isOk(), $outcome->reasonCode() ?? 'EVALUATION_CARRIER_OWNER_REFUSED');
        return $outcome->value();
    }

    private static function exact(array $row, array $expected): void
    {
        foreach ($expected as $key => $value) {
            self::need(array_key_exists($key, $row) && ($value === null ? $row[$key] === null : (string) $row[$key] === (string) $value), 'EVALUATION_CARRIER_STATE_CONFLICT');
        }
    }

    private static function jsonList(array $row, string $key, array $expected): void
    {
        self::need(isset($row[$key]) && json_decode((string) $row[$key], true, 32, JSON_THROW_ON_ERROR) === $expected, 'EVALUATION_CARRIER_SCOPE_CONFLICT');
    }
}
