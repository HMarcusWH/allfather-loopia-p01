<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;

/**
 * WHO is acting on a Monitor run: a human OPERATOR, or the scheduler's SERVICE principal acting
 * under a named operator's delegation (completion plan P07).
 *
 * The scope array every Monitor owner receives keeps `operator_id` meaning one thing: the human
 * whose authority the act rests on. For an operator that is the operator; for a scheduled act it is
 * the DELEGATING operator — the principal the SCHEDULED_OBSERVATION grant names and the operator
 * the frozen mission configuration names — so existing owners (mission configuration, admission)
 * keep their single-operator contract. The principal travels beside it under `execution_principal`
 * and decides everything that must not be confused: the M02 identity that is evaluated, the actor
 * recorded on M06 tool requests, and the journal event version. A service act is never recorded as
 * the human, and the human is never presented as having acted.
 *
 * A SERVICE principal is evaluated as itself AND its delegator is evaluated too: delegation cannot be
 * broader than the delegating operator's current authority, and losing either one stops the act.
 */
final class MonitorPrincipal
{
    public const OPERATOR = 'OPERATOR';
    public const SERVICE = 'SERVICE';
    /** The only service class/role that may act on a scheduled Monitor run. */
    public const SERVICE_CLASS = 'SYSTEM_SCHEDULER';
    public const SERVICE_ROLE_KEY = 'ROLE_SYSTEM_SCHEDULER_OBSERVER';
    public const SERVICE_ROLE_PERMISSIONS = ['mission.read', 'mission.transition', 'evidence.read', 'tool.invoke'];

    private function __construct(
        public readonly string $type,
        public readonly string $actorId,
        public readonly string $delegatingOperatorId,
        public readonly ?string $occurrenceId,
    ) {}

    public static function operator(string $operatorId): self
    {
        return new self(self::OPERATOR, $operatorId, $operatorId, null);
    }

    public static function service(string $serviceIdentityId, string $delegatingOperatorId, string $occurrenceId): self
    {
        return new self(self::SERVICE, $serviceIdentityId, $delegatingOperatorId, $occurrenceId);
    }

    /**
     * Read the principal a scope carries. A scope with no `execution_principal` is an operator scope,
     * exactly as before P07. A malformed principal is not silently an operator: it is null, and every
     * caller refuses on null.
     */
    public static function fromScope(array $scope): ?self
    {
        // The operator id keeps exactly the acceptance it had before P07 (OperatorScope's): any
        // non-empty bounded string. Existing owners decide whether that operator exists and may act.
        $operator = $scope['operator_id'] ?? null;
        if (!is_string($operator) || $operator === '' || strlen($operator) > 64) {
            return null;
        }
        $p = $scope['execution_principal'] ?? null;
        if ($p === null) {
            return self::operator($operator);
        }
        if (!is_array($p) || ($p['type'] ?? null) !== self::SERVICE
            || !is_string($p['service_identity_id'] ?? null) || !Ids::isValid($p['service_identity_id'])
            || !is_string($p['occurrence_id'] ?? null) || !Ids::isValid($p['occurrence_id'])
            || array_diff(array_keys($p), ['type', 'service_identity_id', 'occurrence_id']) !== []) {
            return null;
        }

        return self::service($p['service_identity_id'], $operator, $p['occurrence_id']);
    }

    /** The scope a scheduled act uses: the delegator's operator scope plus this principal. */
    public function scope(string $tenantId, string $workspaceId): array
    {
        $scope = ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'operator_id' => $this->delegatingOperatorId];
        if ($this->isService()) {
            $scope['execution_principal'] = ['type' => self::SERVICE, 'service_identity_id' => $this->actorId, 'occurrence_id' => (string) $this->occurrenceId];
        }

        return $scope;
    }

    public function isService(): bool
    {
        return $this->type === self::SERVICE;
    }

    /** The M02 identity that is evaluated, and recorded on tool requests and context reads. */
    public function actorType(): string
    {
        return $this->type;
    }

    /**
     * Current M02 authority for every permission, for the actor AND (for a service) its delegator.
     * A service must also still be an ACTIVE, tenant-bound SYSTEM_SCHEDULER identity: a tenant-wide
     * (NULL-tenant) service could otherwise act in any tenant that granted it a membership.
     *
     * @param list<string> $permissions
     */
    public function authorize(Database $db, Clock $clock, string $tenantId, string $workspaceId, array $permissions, string $resourceType): Outcome
    {
        $guard = new AccessGuard($db, $clock);
        if ($this->isService()) {
            $service = $db->one('SELECT tenant_id, service_class, status FROM service_identities WHERE service_identity_id = ?', [$this->actorId]);
            if ($service === null || $service['tenant_id'] !== $tenantId || $service['service_class'] !== self::SERVICE_CLASS || $service['status'] !== 'ACTIVE') {
                return Outcome::refuse('MONITOR_SERVICE_PRINCIPAL_INVALID');
            }

            // The scheduler identity is a single-purpose service seat. A later role assignment must
            // never silently widen it while this code continues to treat it as the bounded P07
            // observer. Require exactly one CURRENT membership across the tenant, in this workspace,
            // to the canonical scheduler role, and require that role's permission set to remain
            // byte-for-byte the adopted four-key set. Any drift stands scheduling down.
            $memberships = $db->all(
                "SELECT m.workspace_id, m.role_id, r.role_key
                 FROM workspace_memberships m
                 JOIN roles r ON r.role_id = m.role_id
                 WHERE m.tenant_id = ? AND m.principal_type = 'SERVICE' AND m.principal_id = ?
                   AND m.status = 'ACTIVE' AND m.valid_from <= ?
                   AND (m.valid_until IS NULL OR m.valid_until > ?)
                 ORDER BY m.workspace_id, r.role_key, m.membership_id",
                [$tenantId, $this->actorId, $clock->now(), $clock->now()]
            );
            if (count($memberships) !== 1
                || (string) $memberships[0]['workspace_id'] !== $workspaceId
                || (string) $memberships[0]['role_key'] !== self::SERVICE_ROLE_KEY) {
                return Outcome::refuse('MONITOR_SERVICE_ROLE_BOUNDARY_VIOLATION');
            }
            $actual = array_map(
                static fn (array $row): string => (string) $row['permission_key'],
                $db->all(
                    'SELECT permission_key FROM role_permissions WHERE role_id = ? ORDER BY permission_key',
                    [(string) $memberships[0]['role_id']]
                )
            );
            $expected = self::SERVICE_ROLE_PERMISSIONS;
            sort($expected, SORT_STRING);
            if ($actual !== $expected) {
                return Outcome::refuse('MONITOR_SERVICE_ROLE_AUTHORITY_DRIFT');
            }
        }
        foreach ($permissions as $permission) {
            if ($guard->evaluate($this->type, $this->actorId, $tenantId, $workspaceId, $permission, $resourceType)['decision'] !== 'ALLOW') {
                return Outcome::refuse($this->isService() ? 'MONITOR_SERVICE_ACCESS_DENIED' : 'MONITOR_ACCESS_DENIED');
            }
            if ($this->isService() && $guard->evaluate(self::OPERATOR, $this->delegatingOperatorId, $tenantId, $workspaceId, $permission, $resourceType)['decision'] !== 'ALLOW') {
                return Outcome::refuse('MONITOR_DELEGATOR_ACCESS_DENIED');
            }
        }

        return Outcome::ok();
    }

    /**
     * The journal actor columns. Operator events stay v1, byte for byte; only a service act writes v2.
     *
     * @return array{event_version: int, actor_operator_id: ?string, actor_service_identity_id: ?string, delegating_operator_id: ?string}
     */
    public function journalColumns(): array
    {
        return $this->isService()
            ? ['event_version' => 2, 'actor_operator_id' => null, 'actor_service_identity_id' => $this->actorId, 'delegating_operator_id' => $this->delegatingOperatorId]
            : ['event_version' => 1, 'actor_operator_id' => $this->actorId, 'actor_service_identity_id' => null, 'delegating_operator_id' => null];
    }
}
