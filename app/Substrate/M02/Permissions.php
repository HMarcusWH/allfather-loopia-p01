<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M02;

/**
 * M02 permission vocabulary and the V1 role set.
 *
 * Ported from `packages/allfather-control-substrate/src/m02/permissions.ts`
 * @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d.
 *
 * Permissions are dotted keys; roles group them. A role NAME is never treated as unlimited
 * authority — every protected operation resolves through an explicit permission key (M02-DEC-008).
 *
 * `EXCLUDED` is the load-bearing list. Requesting any of those is a release-blocking violation
 * (S5) regardless of role state, hard-denied before any role or scope evaluation, and the seed
 * must never create one. It is what makes "this deployment cannot send, publish, trade, or score
 * an employee" a property of the authority model rather than of the absence of a feature.
 */
final class Permissions
{
    /**
     * Grantable permissions.
     *
     * Ported verbatim. The offering-twin, account-twin, supplier-risk and market-context keys are
     * retained because the canonical vocabulary is closed and a missing key would make a seeded
     * upstream role unrepresentable — but no included module grants or consumes them, and the P02
     * offering-twin family is excluded from this projection entirely (V1-GAP-097).
     */
    public const GRANTABLE = [
        // V1 core
        'tenant.read', 'tenant.administer',
        'workspace.read', 'workspace.create', 'workspace.administer',
        'identity.read', 'identity.register', 'identity.suspend_revoke',
        'role.assign', 'role.revoke',
        'runtime_host_binding.read', 'runtime_host_binding.administer',
        'valkyrie.read', 'valkyrie.register', 'valkyrie.administer_contract',
        'mission.read', 'mission.create', 'mission.activate', 'mission.transition',
        'evidence.read', 'evidence.submit', 'evidence.annotate',
        'proposal.read', 'proposal.submit',
        'hmwh.initiate_review', 'hmwh.read',
        'arbiter.read',
        'approval.read', 'approval.decide_exact_artifact',
        'decision.read', 'decision.verify_integrity',
        'replay.read', 'replay.initiate_test_only',
        'incident.read', 'incident.manage',
        'policy_change.propose',
        'internal_release.read',
        'tool.invoke',

        // P01 (G13) competition monitoring
        'competition_monitoring.read', 'company_signal.review', 'briefing.read', 'battlecard.read',

        // P03 (G14) lead qualification
        'customer_catalogue.upload', 'customer_catalogue.read', 'prospect.read',
        'priority_outreach_queue.read', 'priority_outreach_queue.review', 'prospect_battlecard.read',

        // P04 (G15) intelligence distribution
        'distribution.read', 'distribution.route', 'distribution.acknowledge',
        'role_briefing.read', 'stakeholder_role.read', 'distribution.configure_targets',

        // P07 (G16) CRM execution. NONE of these is excluded; the excluded crm.send /
        // external_message.approve_or_send / campaign.modify below stay hard-denied — P07-A never
        // grants them. `outreach.approve` is isolated to the SEPARATE approval seat.
        'outreach.draft', 'outreach.approve', 'outreach.execute',
        'consent.read', 'consent.manage', 'delivery.read',

        // P05 (G17) marketing customization. Read only; no publication key exists.
        'marketing_asset.read',

        // P02 (G18) NARROW entity-twin permissions. The broad `company_twin.promote_or_modify`
        // stays EXCLUDED (INV-P02-010) and is never reused as the implementation permission.
        'entity_twin.read', 'entity_twin.propose_state_change', 'entity_twin.review_state_change',
        'entity_twin.release_snapshot',
        'entity_relationship.read', 'entity_relationship.propose', 'entity_relationship.review',
        'entity_relationship.release_snapshot',
        'offering_twin.read', 'offering_twin.propose_state_change',
        'account_twin.read', 'account_twin.propose_state_change',
        'supplier_risk.read', 'supplier_risk.propose_state_change',
        'market_context.read', 'market_context.propose_snapshot',
        'stakeholder_role_profile.read', 'stakeholder_role_profile.propose_update',
    ];

    /**
     * Permissions that must NEVER be active. Requesting one is a release-blocking violation (S5)
     * regardless of role state.
     *
     * This is the authority-model expression of the projection's action ceiling: no send, no
     * publish, no campaign, no trade, no CRM write, no ledger rewrite, no integrity override, and
     * no psychological, emotional, productivity or loyalty inference about a person.
     */
    public const EXCLUDED = [
        'crm.send',
        'campaign.modify',
        'external_message.approve_or_send',
        'trade.propose_or_execute',
        'company_twin.promote_or_modify',
        'sensitive_inference.access_or_act',
        'maso.access',
        'public_publish',
        'unrestricted_tool.execute',
        'policy.activate_automatically',
        'ledger.modify_history',
        'integrity.override',
        // P02 (G18) forbidden permissions — no P02 surface can directly mutate a released
        // snapshot, dispatch externally, write a live CRM, publish, profile a person
        // psychologically or emotionally, or score an employee.
        'entity_twin.direct_mutate_released_snapshot',
        'entity_twin.external_dispatch',
        'entity_twin.live_crm_write',
        'entity_twin.public_publish',
        'stakeholder_profile.psychological_inference',
        'stakeholder_profile.emotional_inference',
        'employee_profile.productivity_score',
        'employee_profile.loyalty_score',
    ];

    /**
     * The V1 role set.
     *
     * Note the separations that are structural rather than conventional:
     *  - `ROLE_CRM_EXECUTOR` holds draft + execute but NEVER approve — the drafter cannot approve
     *    its own outreach;
     *  - `ROLE_CRM_APPROVER` holds approve but NEVER draft or execute;
     *  - `ROLE_ACTION_APPROVER` isolates `approval.decide_exact_artifact` from the workspace
     *    operator, so operating a workspace does not confer approval authority;
     *  - each product module gets a dedicated role rather than widening
     *    `ROLE_WORKSPACE_OPERATOR`, so a consumer must be explicitly assigned it.
     *
     * @var array<string, array{display_name: string, permissions: list<string>}>
     */
    public const ROLES = [
        'ROLE_TENANT_ADMIN' => [
            'display_name' => 'Tenant Administrator',
            'permissions' => [
                'tenant.read', 'tenant.administer', 'workspace.read', 'workspace.create',
                'workspace.administer', 'identity.read', 'identity.register',
                'identity.suspend_revoke', 'role.assign', 'role.revoke',
            ],
        ],
        'ROLE_WORKSPACE_OPERATOR' => [
            'display_name' => 'Workspace Operator',
            'permissions' => [
                'workspace.read', 'mission.read', 'mission.create', 'mission.activate',
                'mission.transition', 'evidence.read', 'evidence.submit', 'evidence.annotate',
                'proposal.read', 'proposal.submit', 'hmwh.read', 'arbiter.read', 'decision.read',
                'internal_release.read', 'tool.invoke',
            ],
        ],
        'ROLE_COMPETITION_MONITOR' => [
            'display_name' => 'Competition Monitor',
            'permissions' => [
                'workspace.read', 'competition_monitoring.read', 'company_signal.review',
                'briefing.read', 'battlecard.read',
            ],
        ],
        'ROLE_LEAD_QUALIFIER' => [
            'display_name' => 'Lead Qualifier',
            'permissions' => [
                'workspace.read', 'customer_catalogue.upload', 'customer_catalogue.read',
                'prospect.read', 'priority_outreach_queue.read', 'priority_outreach_queue.review',
                'prospect_battlecard.read',
            ],
        ],
        'ROLE_INTELLIGENCE_DISTRIBUTOR' => [
            'display_name' => 'Intelligence Distributor',
            'permissions' => [
                'workspace.read', 'distribution.read', 'distribution.route',
                'distribution.acknowledge', 'role_briefing.read', 'stakeholder_role.read',
                'distribution.configure_targets',
            ],
        ],
        'ROLE_MARKETING_CUSTOMIZER' => [
            'display_name' => 'Marketing Customizer',
            'permissions' => ['workspace.read', 'marketing_asset.read'],
        ],
        'ROLE_CRM_EXECUTOR' => [
            'display_name' => 'CRM Executor',
            'permissions' => [
                'workspace.read', 'outreach.draft', 'outreach.execute',
                'consent.read', 'consent.manage', 'delivery.read',
            ],
        ],
        'ROLE_CRM_APPROVER' => [
            'display_name' => 'CRM Outreach Approver',
            'permissions' => [
                'approval.read', 'approval.decide_exact_artifact', 'outreach.approve', 'decision.read',
            ],
        ],
        'ROLE_ENTITY_TWIN_CURATOR' => [
            'display_name' => 'Entity Twin Curator',
            'permissions' => [
                'workspace.read', 'entity_twin.read', 'entity_twin.propose_state_change',
                'entity_twin.review_state_change', 'entity_twin.release_snapshot',
                'entity_relationship.read', 'entity_relationship.propose',
                'entity_relationship.review', 'entity_relationship.release_snapshot',
                'offering_twin.read', 'account_twin.read', 'supplier_risk.read',
                'market_context.read', 'stakeholder_role_profile.read',
            ],
        ],
        'ROLE_INGESTION_ADMISSION' => [
            'display_name' => 'Ingestion Admission Service',
            'permissions' => [
                'evidence.submit', 'proposal.submit',
                'entity_twin.propose_state_change', 'entity_relationship.propose',
            ],
        ],
        'ROLE_SYSTEM_SCHEDULER_OBSERVER' => [
            'display_name' => 'System Scheduler Observer',
            'permissions' => [
                'mission.read', 'mission.transition', 'evidence.read', 'tool.invoke',
            ],
        ],
        'ROLE_ACTION_APPROVER' => [
            'display_name' => 'Controlled Action Approver',
            'permissions' => ['approval.read', 'approval.decide_exact_artifact', 'decision.read'],
        ],
        'ROLE_AUDITOR' => [
            'display_name' => 'Auditor',
            'permissions' => ['decision.read', 'decision.verify_integrity', 'incident.read', 'replay.read'],
        ],
        'ROLE_INCIDENT_REVIEWER' => [
            'display_name' => 'Incident Reviewer',
            'permissions' => ['incident.read', 'incident.manage', 'decision.read', 'replay.read'],
        ],
        'ROLE_DEMO_VIEWER' => [
            'display_name' => 'Demo Viewer',
            'permissions' => ['workspace.read', 'decision.read', 'internal_release.read'],
        ],
    ];

    /**
     * Additive canonical role successors. The legacy ROLES map remains version 1.
     * TenantAdmin v2 implements the three setup-admin permissions assigned by M02 specification
     * permission-to-role table (doc 11). Defining a successor neither edits v1 nor upgrades any membership.
     *
     * @var list<array{role_key: string, role_version: int, display_name: string, permissions: list<string>}>
     */
    public const VERSIONED_ROLES = [
        // Additive read-only role: never grant the much broader twin curator
        // just to let a TEST customer view its own INTERNAL_DRAFT.
        [
            'role_key' => 'ROLE_ENTITY_TWIN_READER',
            'role_version' => 1,
            'display_name' => 'Entity Twin Reader',
            'permissions' => ['workspace.read', 'entity_twin.read'],
        ],
        [
            'role_key' => 'ROLE_TENANT_ADMIN',
            'role_version' => 2,
            'display_name' => 'Tenant Administrator',
            'permissions' => [
                'tenant.read', 'tenant.administer', 'workspace.read', 'workspace.create',
                'workspace.administer', 'identity.read', 'identity.register',
                'identity.suspend_revoke', 'role.assign', 'role.revoke',
                'runtime_host_binding.administer', 'valkyrie.register', 'valkyrie.administer_contract',
            ],
        ],
    ];

    /** @return list<array{role_key: string, role_version: int, display_name: string, permissions: list<string>}> */
    public static function roleVersionDefinitions(): array
    {
        $definitions = [];
        foreach (self::ROLES as $key => $definition) {
            $definitions[] = ['role_key' => $key, 'role_version' => 1, ...$definition];
        }

        return [...$definitions, ...self::VERSIONED_ROLES];
    }

    public static function isExcluded(string $permission): bool
    {
        return in_array($permission, self::EXCLUDED, true);
    }

    public static function isGrantable(string $permission): bool
    {
        return in_array($permission, self::GRANTABLE, true);
    }

    /**
     * Every permission any seeded role grants. Used by the seed and by the invariant test that
     * proves no role grants an excluded permission.
     *
     * @return list<string>
     */
    public static function allRolePermissions(): array
    {
        $out = [];
        foreach (self::roleVersionDefinitions() as $role) {
            foreach ($role['permissions'] as $permission) {
                $out[$permission] = true;
            }
        }

        return array_keys($out);
    }
}
