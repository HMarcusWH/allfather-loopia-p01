<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\ModelPolicy;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * Immutable, reviewed execution scopes and their append-only lifecycle.
 *
 * A grant says exactly what a deployment may do with the existing machinery: which purpose, in
 * which tenant and workspace, for which frozen mission configuration or evaluation-case manifest,
 * by which human principal, with which approved prompts, model policy and credential, against which
 * source and target, inside which window and budget, on which application release. It can only
 * NARROW what M02/M03/M04/M06/M01 already allow; it never widens them.
 *
 * Proposing a grant writes its definition and nothing else. Admission is a separate event that
 * must cite a reviewed owner decision naming this grant's exact digest (see OwnerDecisionRegistry).
 * Suspension and revocation are further events; the definition row never changes. There is no
 * browser path to any of this — only the private CLI in `ops/monitor/authorization.php`.
 */
final class ExecutionGrantStore
{
    public const PURPOSES = ['MODEL_EVALUATION', 'MANUAL_MONITOR', 'SCHEDULED_OBSERVATION'];

    /** Budget and window ceilings per purpose. A grant may ask for less, never more. */
    public const CEILINGS = [
        'MANUAL_MONITOR' => ['max_model_attempts' => 8, 'max_source_captures' => 4, 'max_window_days' => 31],
        'MODEL_EVALUATION' => ['max_model_attempts' => 400, 'max_source_captures' => 0, 'max_window_days' => 31],
        // Scheduled observation captures and compares. It makes no unattended model call: that
        // would need its own disclosure/inspection policy, which no grant here can express.
        'SCHEDULED_OBSERVATION' => ['max_model_attempts' => 0, 'max_source_captures' => 500, 'max_window_days' => 92],
    ];

    private const KEYS = [
        'purpose', 'mission_id', 'definition_digest', 'evaluation_manifest_sha256', 'principal_operator_id',
        'prompt_approval_ids', 'model_policy_id', 'model_policy_digest', 'credential_id', 'credential_digest',
        'source_scope', 'target_org_role', 'limits', 'valid_from', 'valid_until', 'disclosure_scope',
        'retention_scope', 'application_fingerprint',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PromptApprovalStore $approvals,
        private readonly OwnerDecisionRegistry $decisions,
    ) {}

    /** Validate and normalize a definition, and report the digest an owner decision must name. */
    public function plan(array $scope, array $definition): Outcome
    {
        if (!$this->may($scope, ['tenant.administer', 'mission.activate'])) {
            return Outcome::refuse('EXECUTION_GRANT_ADMINISTRATION_DENIED');
        }
        $normalized = $this->normalize($scope, $definition);
        if (!$normalized->isOk()) {
            return $normalized;
        }
        $d = $normalized->value();

        return Outcome::ok(['definition' => $d, 'grant_digest' => self::digest($scope['tenant_id'], $scope['workspace_id'], $d), 'grants_execution' => false]);
    }

    public function propose(array $scope, array $definition): Outcome
    {
        $plan = $this->plan($scope, $definition);
        if (!$plan->isOk()) {
            return $plan;
        }
        ['definition' => $d, 'grant_digest' => $digest] = $plan->value();
        $existing = $this->db->one('SELECT execution_grant_id FROM monitor_execution_grants WHERE grant_digest=?', [$digest]);
        if ($existing !== null) {
            return Outcome::ok(['execution_grant_id' => $existing['execution_grant_id'], 'grant_digest' => $digest, 'status' => $this->status($existing['execution_grant_id']), 'grants_execution' => false]);
        }
        $id = Ids::new();
        try {
            $this->db->insert('monitor_execution_grants', [
                'execution_grant_id' => $id, 'tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'],
                'purpose' => $d['purpose'], 'mission_id' => $d['mission_id'], 'definition_digest' => $d['definition_digest'],
                'evaluation_manifest_sha256' => $d['evaluation_manifest_sha256'], 'principal_operator_id' => $d['principal_operator_id'],
                'definition_json' => json_encode($d, JSON_THROW_ON_ERROR), 'grant_digest' => $digest,
                'valid_from' => $d['valid_from'], 'valid_until' => $d['valid_until'],
                'proposed_by_operator_id' => $scope['operator_id'], 'created_at' => $this->clock->now(),
            ]);
        } catch (\PDOException $error) {
            return Database::classify($error, 'EXECUTION_GRANT_ALREADY_PROPOSED');
        }

        return Outcome::ok(['execution_grant_id' => $id, 'grant_digest' => $digest, 'status' => 'PROPOSED', 'grants_execution' => false]);
    }

    /** Admission is an owner decision recorded against the exact digest, never a flag an operator sets. */
    public function admit(array $scope, string $grantId, string $decisionRef, string $decisionSha256): Outcome
    {
        if (!$this->may($scope, ['tenant.administer', 'mission.activate'])) {
            return Outcome::refuse('EXECUTION_GRANT_ADMINISTRATION_DENIED');
        }

        // Resolve the immutable human decision before holding DB locks. The exact grant digest is
        // rechecked again inside the tenant-locked transaction below.
        $grant = $this->find($scope['tenant_id'], $scope['workspace_id'], $grantId);
        if ($grant === null) {
            return Outcome::refuse('EXECUTION_GRANT_NOT_FOUND');
        }
        $decision = $this->decisions->resolve($decisionRef, $decisionSha256);
        if (!$decision->isOk()) {
            return $decision;
        }
        $decisionValue = $decision->value();
        if (!OwnerDecisionRegistry::authorizesGrant(
            $decisionValue,
            $grant['tenant_id'],
            $grant['workspace_id'],
            $grant['purpose'],
            $grant['grant_digest'],
        )) {
            return Outcome::refuse('OWNER_DECISION_DOES_NOT_AUTHORIZE_GRANT');
        }

        try {
            return $this->db->transaction(function (Database $tx) use (
                $scope,
                $grantId,
                $decisionRef,
                $decisionSha256,
                $decisionValue,
            ): Outcome {
                // Shared credential/admission mutex. CredentialStore::save and P11 rewrap take the
                // same tenant row before changing credential identity, so admission cannot slip
                // between "rotation saw no live grant" and "rotation changed credential_digest".
                $tenant = $tx->one(
                    'SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE',
                    [$scope['tenant_id']],
                );
                if ($tenant === null) {
                    return Outcome::refuse('EXECUTION_GRANT_TENANT_NOT_FOUND');
                }
                $locked = $tx->one(
                    'SELECT execution_grant_id
                     FROM monitor_execution_grants
                     WHERE execution_grant_id=? AND tenant_id=? AND workspace_id=?
                     FOR UPDATE',
                    [$grantId, $scope['tenant_id'], $scope['workspace_id']],
                );
                if ($locked === null) {
                    return Outcome::refuse('EXECUTION_GRANT_NOT_FOUND');
                }

                $current = $this->find($scope['tenant_id'], $scope['workspace_id'], $grantId);
                if ($current === null) {
                    return Outcome::refuse('EXECUTION_GRANT_NOT_FOUND');
                }
                if ($this->clock->now() >= $current['valid_until']) {
                    return Outcome::refuse('EXECUTION_GRANT_EXPIRED');
                }
                if (!OwnerDecisionRegistry::authorizesGrant(
                    $decisionValue,
                    $current['tenant_id'],
                    $current['workspace_id'],
                    $current['purpose'],
                    $current['grant_digest'],
                )) {
                    return Outcome::refuse('OWNER_DECISION_DOES_NOT_AUTHORIZE_GRANT');
                }

                // What was reviewed must still be true while the shared tenant mutex is held:
                // especially the current credential id/digest that P11 rewrap or provider save may
                // otherwise change concurrently.
                $still = $this->normalize($scope, $current['definition']);
                if (!$still->isOk()) {
                    return $still;
                }

                return $this->eventLocked(
                    $tx,
                    $scope,
                    $grantId,
                    'ADMITTED',
                    ['PROPOSED', 'SUSPENDED'],
                    'OWNER_DECISION_ADMITTED',
                    $decisionRef,
                    $decisionSha256,
                    false,
                );
            });
        } catch (\PDOException $error) {
            return Database::classify($error, 'EXECUTION_GRANT_TRANSITION_RACED');
        }
    }

    /** Stand-down: stops further use. It never refunds consumed budget or rewrites history. */
    public function suspend(array $scope, string $grantId, string $reasonCode): Outcome
    {
        return $this->standDown($scope, $grantId, 'SUSPENDED', ['ADMITTED', 'NOT_YET_VALID'], $reasonCode);
    }

    public function revoke(array $scope, string $grantId, string $reasonCode): Outcome
    {
        return $this->standDown($scope, $grantId, 'REVOKED', ['PROPOSED', 'ADMITTED', 'SUSPENDED', 'EXPIRED', 'NOT_YET_VALID'], $reasonCode);
    }

    public function find(string $tenantId, string $workspaceId, string $grantId): ?array
    {
        $row = $this->findById($grantId);

        return $row !== null && $row['tenant_id'] === $tenantId && $row['workspace_id'] === $workspaceId ? $row : null;
    }

    /** Identity lookup for re-deriving a selected prompt. Scope is the admission check's business. */
    public function findById(string $grantId): ?array
    {
        $row = $this->db->one('SELECT * FROM monitor_execution_grants WHERE execution_grant_id=?', [$grantId]);
        if ($row === null) {
            return null;
        }
        $row['definition'] = json_decode($row['definition_json'], true, 32, JSON_THROW_ON_ERROR);
        unset($row['definition_json']);

        return $row;
    }

    /** PROPOSED, ADMITTED, SUSPENDED, REVOKED, EXPIRED or NOT_YET_VALID — computed, never stored. */
    public function status(string $grantId): string
    {
        $grant = $this->db->one('SELECT valid_from,valid_until FROM monitor_execution_grants WHERE execution_grant_id=?', [$grantId]);
        if ($grant === null) {
            return 'NOT_FOUND';
        }
        $last = $this->db->one('SELECT event_kind FROM monitor_execution_grant_events WHERE execution_grant_id=? ORDER BY sequence_no DESC LIMIT 1', [$grantId]);
        $kind = $last['event_kind'] ?? 'PROPOSED';
        if ($kind === 'REVOKED' || $kind === 'SUSPENDED') {
            return $kind;
        }
        $now = $this->clock->now();
        if ($now >= $grant['valid_until']) {
            return 'EXPIRED';
        }
        if ($kind === 'ADMITTED' && $now < $grant['valid_from']) {
            return 'NOT_YET_VALID';
        }

        return $kind;
    }

    /** The admission event currently in force, with the decision it cites. */
    public function admission(string $grantId): ?array
    {
        return $this->db->one("SELECT * FROM monitor_execution_grant_events WHERE execution_grant_id=? AND event_kind='ADMITTED' ORDER BY sequence_no DESC LIMIT 1", [$grantId]);
    }

    /** Exactly one admitted grant for this purpose and frozen configuration, or a named refusal. */
    public function currentFor(string $tenantId, string $workspaceId, string $purpose, string $missionId, string $definitionDigest): Outcome
    {
        $ids = array_column($this->db->all('SELECT execution_grant_id FROM monitor_execution_grants WHERE tenant_id=? AND workspace_id=? AND purpose=? AND mission_id=? AND definition_digest=? ORDER BY created_at, execution_grant_id', [$tenantId, $workspaceId, $purpose, $missionId, $definitionDigest]), 'execution_grant_id');
        $admitted = array_values(array_filter($ids, fn (string $id): bool => $this->status($id) === 'ADMITTED'));
        if ($admitted === []) {
            return Outcome::refuse('EXECUTION_GRANT_NOT_ADMITTED');
        }
        if (count($admitted) > 1) {
            return Outcome::refuse('EXECUTION_GRANT_AMBIGUOUS');
        }

        return Outcome::ok($this->find($tenantId, $workspaceId, $admitted[0]));
    }

    /** @return list<string> grants of this purpose that are ADMITTED right now in this workspace */
    public function admittedIn(string $tenantId, string $workspaceId, string $purpose): array
    {
        $ids = array_column($this->db->all('SELECT execution_grant_id FROM monitor_execution_grants WHERE tenant_id=? AND workspace_id=? AND purpose=? ORDER BY created_at, execution_grant_id', [$tenantId, $workspaceId, $purpose]), 'execution_grant_id');

        return array_values(array_filter($ids, fn (string $id): bool => $this->status($id) === 'ADMITTED'));
    }

    public function consumed(string $grantId, string $kind): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM monitor_grant_consumptions WHERE execution_grant_id=? AND consumption_kind=?', [$grantId, $kind]);
    }

    public static function digest(string $tenantId, string $workspaceId, array $definition): string
    {
        return Digest::canonicalDigest(['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'definition' => $definition]);
    }

    private function standDown(array $scope, string $grantId, string $kind, array $from, string $reasonCode): Outcome
    {
        if (!$this->may($scope, ['tenant.administer']) && !$this->may($scope, ['mission.transition'])) {
            return Outcome::refuse('EXECUTION_GRANT_STAND_DOWN_DENIED');
        }
        if (preg_match('/^[A-Z][A-Z0-9_]{2,95}$/D', $reasonCode) !== 1) {
            return Outcome::refuse('EXECUTION_GRANT_REASON_REQUIRED');
        }
        if ($this->find($scope['tenant_id'], $scope['workspace_id'], $grantId) === null) {
            return Outcome::refuse('EXECUTION_GRANT_NOT_FOUND');
        }

        return $this->event($scope, $grantId, $kind, $from, $reasonCode, null, null);
    }

    private function event(array $scope, string $grantId, string $kind, array $from, string $reason, ?string $decisionRef, ?string $decisionSha256): Outcome
    {
        try {
            return $this->db->transaction(fn (Database $tx): Outcome => $this->eventLocked(
                $tx,
                $scope,
                $grantId,
                $kind,
                $from,
                $reason,
                $decisionRef,
                $decisionSha256,
                true,
            ));
        } catch (\PDOException $error) {
            return Database::classify($error, 'EXECUTION_GRANT_TRANSITION_RACED');
        }
    }

    /**
     * Append one lifecycle event inside the caller's transaction.
     *
     * @param list<string> $from
     */
    private function eventLocked(
        Database $tx,
        array $scope,
        string $grantId,
        string $kind,
        array $from,
        string $reason,
        ?string $decisionRef,
        ?string $decisionSha256,
        bool $lockGrant,
    ): Outcome {
        if ($lockGrant) {
            // Serialize lifecycle changes on the immutable grant row.
            $tx->one(
                'SELECT execution_grant_id
                 FROM monitor_execution_grants
                 WHERE execution_grant_id=? FOR UPDATE',
                [$grantId],
            );
        }

        $status = $this->status($grantId);
        if ($kind === 'REVOKED' && $status === 'REVOKED') {
            return Outcome::ok(['execution_grant_id' => $grantId, 'status' => 'REVOKED']);
        }
        if (!in_array($status, $from, true)) {
            return Outcome::refuse('EXECUTION_GRANT_TRANSITION_INVALID', ['status' => $status]);
        }

        $sequence = 1 + (int) $tx->scalar(
            'SELECT COALESCE(MAX(sequence_no),0)
             FROM monitor_execution_grant_events
             WHERE execution_grant_id=?',
            [$grantId],
        );
        $tx->insert('monitor_execution_grant_events', [
            'execution_grant_event_id' => Ids::new(),
            'execution_grant_id' => $grantId,
            'sequence_no' => $sequence,
            'event_kind' => $kind,
            'owner_decision_ref' => $decisionRef,
            'owner_decision_sha256' => $decisionSha256,
            'reason_code' => $reason,
            'recorded_by_operator_id' => $scope['operator_id'],
            'recorded_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'execution_grant_id' => $grantId,
            'status' => $this->status($grantId),
        ]);
    }

    private function normalize(array $scope, array $d): Outcome
    {
        if (array_diff(array_keys($d), self::KEYS) !== [] || array_diff(self::KEYS, array_keys($d)) !== []) {
            return Outcome::refuse('EXECUTION_GRANT_DEFINITION_INVALID');
        }
        $purpose = $d['purpose'];
        if (!in_array($purpose, self::PURPOSES, true)) {
            return Outcome::refuse('EXECUTION_GRANT_DEFINITION_INVALID');
        }
        $ceiling = self::CEILINGS[$purpose];
        $hex = static fn (mixed $v): bool => is_string($v) && preg_match('/^[a-f0-9]{64}$/D', $v) === 1;
        $uuid = static fn (mixed $v): bool => is_string($v) && preg_match('/^[a-f0-9-]{36}$/D', $v) === 1;
        if (!$uuid($d['principal_operator_id']) || !$hex($d['application_fingerprint'])) {
            return Outcome::refuse('EXECUTION_GRANT_DEFINITION_INVALID');
        }
        if ($d['model_policy_id'] !== ModelPolicy::ID || $d['model_policy_digest'] !== ModelPolicy::digest()) {
            return Outcome::refuse('EXECUTION_GRANT_MODEL_POLICY_NOT_CURRENT');
        }
        foreach (['disclosure_scope', 'retention_scope'] as $key) {
            if (!is_string($d[$key]) || trim($d[$key]) === '' || strlen($d[$key]) > 500) {
                return Outcome::refuse('EXECUTION_GRANT_DEFINITION_INVALID');
            }
        }
        $limits = $d['limits'];
        if (!is_array($limits) || array_keys($limits) !== ['max_model_attempts', 'max_source_captures']) {
            return Outcome::refuse('EXECUTION_GRANT_DEFINITION_INVALID');
        }
        foreach (['max_model_attempts', 'max_source_captures'] as $key) {
            if (!is_int($limits[$key]) || $limits[$key] < 0 || $limits[$key] > $ceiling[$key]) {
                return Outcome::refuse('EXECUTION_GRANT_LIMIT_EXCEEDS_CEILING');
            }
        }
        $from = \Allfather\Loopia\Core\Clock::parse((string) $d['valid_from']);
        $until = \Allfather\Loopia\Core\Clock::parse((string) $d['valid_until']);
        if ($from === null || $until === null || !\Allfather\Loopia\Core\Clock::isCanonical($d['valid_from']) || !\Allfather\Loopia\Core\Clock::isCanonical($d['valid_until'])
            || $until <= $from || $until->getTimestamp() - $from->getTimestamp() > $ceiling['max_window_days'] * 86400) {
            return Outcome::refuse('EXECUTION_GRANT_WINDOW_INVALID');
        }
        $principal = $this->db->one("SELECT 1 FROM operators WHERE operator_id=? AND tenant_id=? AND status='ACTIVE'", [$d['principal_operator_id'], $scope['tenant_id']]);
        if ($principal === null) {
            return Outcome::refuse('EXECUTION_GRANT_PRINCIPAL_NOT_ACTIVE');
        }

        if ($purpose === 'MODEL_EVALUATION') {
            if ($d['mission_id'] !== null || $d['definition_digest'] !== null || $d['source_scope'] !== null || $d['target_org_role'] !== null || !$hex($d['evaluation_manifest_sha256'])) {
                return Outcome::refuse('EXECUTION_GRANT_DEFINITION_INVALID');
            }
        } else {
            if (!$uuid($d['mission_id']) || !$hex($d['definition_digest']) || $d['evaluation_manifest_sha256'] !== null) {
                return Outcome::refuse('EXECUTION_GRANT_DEFINITION_INVALID');
            }
            $config = $this->db->one('SELECT definition_json,definition_digest,execution_mode FROM monitor_mission_configs WHERE mission_id=? AND tenant_id=? AND workspace_id=?', [$d['mission_id'], $scope['tenant_id'], $scope['workspace_id']]);
            if ($config === null || $config['execution_mode'] !== 'NATIVE' || !hash_equals($config['definition_digest'], $d['definition_digest'])) {
                return Outcome::refuse('EXECUTION_GRANT_CONFIGURATION_MISMATCH');
            }
            $frozen = json_decode($config['definition_json'], true, 64, JSON_THROW_ON_ERROR);
            if ($d['source_scope'] !== ['monitored_source_id' => $frozen['monitored_source_id'], 'capture_url' => $frozen['capture_url']]
                || $d['target_org_role'] !== $frozen['target_org_role']) {
                return Outcome::refuse('EXECUTION_GRANT_CONFIGURATION_MISMATCH');
            }
            if ($purpose === 'MANUAL_MONITOR' && $d['credential_id'] !== $frozen['credential_id']) {
                return Outcome::refuse('EXECUTION_GRANT_CONFIGURATION_MISMATCH');
            }
        }

        if ($ceiling['max_model_attempts'] === 0) {
            if ($d['prompt_approval_ids'] !== [] || $d['credential_id'] !== null || $d['credential_digest'] !== null) {
                return Outcome::refuse('EXECUTION_GRANT_DEFINITION_INVALID');
            }
        } else {
            $credential = is_string($d['credential_id']) ? $this->db->one("SELECT credential_digest FROM provider_credentials WHERE provider_credential_id=? AND tenant_id=? AND provider='OPENAI' AND status='ACTIVE'", [$d['credential_id'], $scope['tenant_id']]) : null;
            if ($credential === null || !is_string($d['credential_digest']) || !hash_equals($credential['credential_digest'], $d['credential_digest'])) {
                return Outcome::refuse('EXECUTION_GRANT_CREDENTIAL_NOT_CURRENT');
            }
            $ids = $d['prompt_approval_ids'];
            if (!is_array($ids) || $ids === [] || array_values(array_unique($ids)) !== $ids) {
                return Outcome::refuse('EXECUTION_GRANT_PROMPTS_INVALID');
            }
            $sorted = $ids;
            sort($sorted, SORT_STRING);
            if ($sorted !== $ids) {
                return Outcome::refuse('EXECUTION_GRANT_PROMPTS_INVALID');
            }
            $roles = [];
            foreach ($ids as $id) {
                $approval = is_string($id) ? $this->approvals->current($scope['tenant_id'], $scope['workspace_id'], $id) : Outcome::refuse('PROMPT_APPROVAL_NOT_FOUND');
                if (!$approval->isOk()) {
                    return $approval;
                }
                if ($approval->value()['purpose'] !== $purpose) {
                    return Outcome::refuse('EXECUTION_GRANT_PROMPT_PURPOSE_MISMATCH');
                }
                $roles[] = $approval->value()['role'];
            }
            if (count(array_unique($roles)) !== count($roles)) {
                return Outcome::refuse('EXECUTION_GRANT_PROMPTS_INVALID');
            }
            if ($purpose === 'MANUAL_MONITOR') {
                $required = \Allfather\Loopia\PromptOps\ProductPromptPack::ROLES;
                sort($required);
                sort($roles);
                if ($roles !== $required) {
                    return Outcome::refuse('EXECUTION_GRANT_PROMPTS_INCOMPLETE');
                }
            }
        }

        return Outcome::ok($d);
    }

    private function may(array $scope, array $permissions): bool
    {
        foreach (['tenant_id', 'workspace_id', 'operator_id'] as $key) {
            if (!is_string($scope[$key] ?? null) || $scope[$key] === '') {
                return false;
            }
        }
        $guard = new AccessGuard($this->db, $this->clock);
        foreach ($permissions as $permission) {
            if ($guard->evaluate('OPERATOR', $scope['operator_id'], $scope['tenant_id'], $scope['workspace_id'], $permission, 'EXECUTION_GRANT', null, null, true)['decision'] !== 'ALLOW') {
                return false;
            }
        }

        return true;
    }
}
