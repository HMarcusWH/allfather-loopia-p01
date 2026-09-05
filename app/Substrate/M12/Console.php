<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M12;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance;

/**
 * M12 — operator console read models and controlled command surfaces.
 *
 * The console plane is PRESENTATION plus CONTROLLED INGRESS, with NO authority of its own. It can
 * do exactly two things: project canonical M01–M11 state into REGENERABLE read models, and route
 * an operator's command to the owning module's existing guarded entrypoint.
 *
 *     A visible button is not permission.
 *     A read model is not evidence.
 *     A status shown is not a status earned.
 *
 * Three invariants carry the weight:
 *
 *   INV-017 (no bypass). `routeCommand` re-runs the SAME M02 guard the owner would. A hidden or
 *   hand-crafted route gets the SAME denial as a forbidden button, so the console cannot grant an
 *   authority the actor lacks. `owner_revalidation_required` is CHECK-pinned to 1.
 *
 *   INV-016 (no overclaim). A MATERIAL display requires a real owner basis.
 *   `recordDisplayReference` refuses an internal-release display unless the cited M10 record is
 *   that proposal's own release record, in this scope. "Some release record exists somewhere" is
 *   not a basis.
 *
 *   INV-018 (gaps stay ahead). An unresolved blocking gap holds release readiness BLOCKED even for
 *   an already-released proposal. A blocking gap the console can hide behind a green status is a
 *   gap nobody will act on.
 *
 * This module imports the M02 guard, an M10 read and an M11 read. It imports NO dispatch or
 * execution path, so it cannot reach a live effect — a property the foundation tripwire re-checks
 * structurally rather than taking on trust.
 */
final class Console
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AccessGuard $access,
        private readonly DecisionLedger $ledger,
        private readonly Assurance $assurance,
    ) {
    }

    // ---------------------------------------------------------------- controlled command surfaces

    /**
     * Register a controlled command surface.
     *
     * The surface MAPS a visible interaction to an owner-module command plus the M02 permission
     * that owner will require. It authorizes nothing by existing — registering a button is not
     * granting the right to press it.
     */
    public function registerCommandSurface(
        string $tenantId,
        string $workspaceId,
        string $commandSurfaceKey,
        string $ownerModuleId,
        string $ownerCommandType,
        string $requiredPermission,
        string $activeV1Status = 'ACTIVE',
        string $confirmationLevel = 'NORMAL',
        ?string $effectCeilingSummary = null,
    ): string {
        $surfaceId = Ids::new();
        $this->db->insert('console_command_surface_definitions', [
            'console_command_surface_id' => $surfaceId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'command_surface_key' => $commandSurfaceKey,
            'surface_version' => '1',
            'owner_module_id' => $ownerModuleId,
            'owner_command_type' => $ownerCommandType,
            'required_permission' => $requiredPermission,
            'effect_ceiling_summary' => $effectCeilingSummary,
            'confirmation_level' => $confirmationLevel,
            'active_v1_status' => $activeV1Status,
            // CHECK-pinned to 1. A surface that declared the owner need not revalidate would be a
            // surface claiming the console's own say-so is sufficient — which is the exact claim
            // INV-017 exists to refuse.
            'owner_revalidation_required' => 1,
            'created_at' => $this->clock->now(),
        ]);

        return $surfaceId;
    }

    /**
     * THE controlled-ingress entrypoint.
     *
     * It ALWAYS routes the operator's intent through the owning module's M02 guard. There is no
     * path here that skips it. An actor who lacks the permission is denied here exactly as the
     * owner would deny a crafted out-of-UI request — which is what proves the console grants no
     * authority of its own.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, command_surface_key: string,
     *     actor_type: string, actor_id: string, resource_type: string,
     *     resource_id?: ?string, correlation_id?: ?string, idempotency_key?: ?string
     * } $input
     * @return array<string, mixed>
     */
    public function routeCommand(array $input): array
    {
        // A non-console actor is refused BEFORE any intent row is written. A VALKYRIE is not a
        // console principal, and the actor_type CHECK admits only the three that are — so without
        // this guard the boundary would surface as a constraint violation rather than as the
        // documented rejection it is.
        if (!in_array($input['actor_type'], ['OPERATOR', 'SERVICE', 'SYSTEM'], true)) {
            return ['outcome' => 'REJECTED', 'reason_code' => 'CONSOLE_ACTOR_TYPE_NOT_PERMITTED'];
        }

        $surface = $this->db->one(
            'SELECT console_command_surface_id, owner_module_id, owner_command_type,
                    required_permission, active_v1_status
             FROM console_command_surface_definitions
             WHERE tenant_id = ? AND workspace_id = ? AND command_surface_key = ?',
            [$input['tenant_id'], $input['workspace_id'], $input['command_surface_key']]
        );
        if ($surface === null) {
            return ['outcome' => 'REJECTED', 'reason_code' => 'CONSOLE_COMMAND_SURFACE_NOT_FOUND'];
        }

        // A non-ACTIVE surface — READ_ONLY, NOT_IMPLEMENTED or PROHIBITED — cannot be routed. The
        // ATTEMPT is still recorded: a prohibited control someone tried to use is exactly the event
        // an audit wants, and refusing silently would discard it.
        if ((string) $surface['active_v1_status'] !== 'ACTIVE') {
            $intentId = $this->insertIntent($input, $surface, 'FAILED_TO_ROUTE', null);
            $this->insertResultReference(
                $intentId,
                (string) $surface['owner_module_id'],
                'FAILED',
                ['CONSOLE_COMMAND_SURFACE_NOT_ACTIVE_V1', 'SURFACE_' . (string) $surface['active_v1_status']],
                sprintf(
                    'Command surface %s is %s, not ACTIVE in V1.',
                    $input['command_surface_key'],
                    (string) $surface['active_v1_status'],
                ),
            );

            return [
                'outcome' => 'REJECTED',
                'reason_code' => 'CONSOLE_COMMAND_SURFACE_NOT_ACTIVE_V1',
                'console_command_intent_id' => $intentId,
            ];
        }

        // Delegate authorization to the SAME backend guard the owner module uses. No bypass.
        $access = $this->access->evaluate(
            (string) $input['actor_type'],
            (string) $input['actor_id'],
            (string) $input['tenant_id'],
            (string) $input['workspace_id'],
            (string) $surface['required_permission'],
            (string) $input['resource_type'],
            $input['resource_id'] ?? null,
            $input['correlation_id'] ?? null,
            true,
        );

        if ($access['decision'] === 'ALLOW') {
            // ROUTED_TO_OWNER, never OWNER_ACCEPTED. M12 has only re-run the M02 guard and returned
            // routing metadata; the owning module has not executed or accepted anything yet.
            // Recording it as owner-accepted would mislabel a command the owner may still reject.
            $intentId = $this->insertIntent($input, $surface, 'ROUTED_TO_OWNER', $access['access_evaluation_id']);
            $this->insertResultReference(
                $intentId,
                (string) $surface['owner_module_id'],
                'ROUTED',
                ['M02_ACCESS_GRANTED_ROUTED_TO_OWNER'],
                sprintf(
                    'Routed %s to %s after M02 ALLOW; owner outcome pending.',
                    (string) $surface['owner_command_type'],
                    (string) $surface['owner_module_id'],
                ),
            );

            return [
                'outcome' => 'ROUTED',
                'console_command_intent_id' => $intentId,
                'access_evaluation_id' => $access['access_evaluation_id'],
                'owner_module_id' => (string) $surface['owner_module_id'],
                'owner_command_type' => (string) $surface['owner_command_type'],
            ];
        }

        if ($access['decision'] === 'HOLD_FOR_REVIEW') {
            $intentId = $this->insertIntent($input, $surface, 'OWNER_HELD', $access['access_evaluation_id']);
            $this->insertResultReference(
                $intentId,
                (string) $surface['owner_module_id'],
                'HELD',
                $access['reason_codes'],
                'Owner guard held the command for review.',
            );

            return ['outcome' => 'HELD', 'console_command_intent_id' => $intentId];
        }

        // DENY. The console surfaces the owner's denial FAITHFULLY, including its reason codes: a
        // console that softened or relabelled a denial would be telling the operator something the
        // owner did not decide. An attempt at an EXCLUDED capability is not an ordinary denial —
        // it is a bypass attempt, and it is named as one.
        $isBypass = in_array(AccessGuard::EXCLUDED_PERMISSION_REQUESTED, $access['reason_codes'], true);
        $intentId = $this->insertIntent($input, $surface, 'OWNER_REJECTED', $access['access_evaluation_id']);
        $this->insertResultReference(
            $intentId,
            (string) $surface['owner_module_id'],
            'DENIED',
            $access['reason_codes'],
            'Owner guard denied the command; the console grants no authority of its own.',
        );

        return [
            'outcome' => 'DENIED',
            'console_command_intent_id' => $intentId,
            'reason_code' => $isBypass ? 'CONSOLE_CLIENT_ATTEMPTED_AUTHORITY_BYPASS' : 'CONSOLE_OWNER_COMMAND_DENIED',
            'access_reason_codes' => $access['reason_codes'],
        ];
    }

    // ---------------------------------------------------------------- regenerable projections

    /**
     * Project the operator's M02-scoped visible roles and permissions.
     *
     * This MIRRORS the access guard's own checks exactly, so the read model cannot show a
     * permission the backend would deny. M02 first requires the operator identity itself to be
     * in-tenant and ACTIVE, THEN a currently-valid ACTIVE membership window. Matching on the
     * membership alone would project a suspended operator's roles; matching on the operator alone
     * would project an expired membership's. Either would misrepresent the "M02-scoped" view.
     *
     * Regenerable: a prior projection for the scope is REPLACED, never merged. A projection is a
     * derived view of canonical state, so accumulating history in it would make it a second record.
     */
    public function buildWorkspaceContext(string $tenantId, string $workspaceId, string $operatorId): string
    {
        $now = $this->clock->now();
        $scopeArgs = [$operatorId, $workspaceId, $tenantId, $now, $now];

        $roles = array_column($this->db->all(
            "SELECT r.role_key FROM workspace_memberships m
             JOIN roles r ON r.role_id = m.role_id
             JOIN operators o ON o.operator_id = m.principal_id AND o.tenant_id = m.tenant_id
             WHERE m.principal_id = ? AND m.workspace_id = ? AND m.tenant_id = ?
               AND m.principal_type = 'OPERATOR' AND m.status = 'ACTIVE' AND o.status = 'ACTIVE'
               AND m.valid_from <= ? AND (m.valid_until IS NULL OR m.valid_until > ?)
             ORDER BY r.role_key",
            $scopeArgs
        ), 'role_key');

        $permissions = array_column($this->db->all(
            "SELECT DISTINCT rp.permission_key FROM workspace_memberships m
             JOIN role_permissions rp ON rp.role_id = m.role_id
             JOIN operators o ON o.operator_id = m.principal_id AND o.tenant_id = m.tenant_id
             WHERE m.principal_id = ? AND m.workspace_id = ? AND m.tenant_id = ?
               AND m.principal_type = 'OPERATOR' AND m.status = 'ACTIVE' AND o.status = 'ACTIVE'
               AND m.valid_from <= ? AND (m.valid_until IS NULL OR m.valid_until > ?)
             ORDER BY rp.permission_key",
            $scopeArgs
        ), 'permission_key');

        return $this->db->transaction(function (Database $tx) use (
            $tenantId, $workspaceId, $operatorId, $roles, $permissions, $now
        ): string {
            $tx->run(
                'DELETE FROM console_workspace_context_projections
                 WHERE tenant_id = ? AND workspace_id = ? AND operator_id = ?',
                [$tenantId, $workspaceId, $operatorId]
            );
            $projectionId = Ids::new();
            $tx->insert('console_workspace_context_projections', [
                'console_workspace_context_projection_id' => $projectionId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'operator_id' => $operatorId,
                'visible_role_summaries' => json_encode($roles, JSON_THROW_ON_ERROR),
                'visible_permission_summaries' => json_encode($permissions, JSON_THROW_ON_ERROR),
                'stale_status' => 'CURRENT',
                'source_refs' => json_encode(['m02:workspace_memberships'], JSON_THROW_ON_ERROR),
                'generated_at' => $now,
            ]);

            // Navigation entitlement: a USABILITY filter derived from visible permissions.
            // `is_usability_only` is CHECK-pinned to 1 — hiding a nav item is a convenience, and
            // showing one is not permission. The backend guard is still mandatory either way.
            $tx->run(
                'DELETE FROM console_navigation_entitlement_projections
                 WHERE tenant_id = ? AND workspace_id = ? AND operator_id = ?',
                [$tenantId, $workspaceId, $operatorId]
            );
            $tx->insert('console_navigation_entitlement_projections', [
                'console_navigation_entitlement_projection_id' => Ids::new(),
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'operator_id' => $operatorId,
                'visible_nav_keys' => json_encode(self::navKeysFor($permissions), JSON_THROW_ON_ERROR),
                'is_usability_only' => 1,
                'stale_status' => 'CURRENT',
                'generated_at' => $now,
            ]);

            $this->touchRefresh($tx, $tenantId, $workspaceId, 'WORKSPACE_CONTEXT', 'USER_REQUEST');

            return $projectionId;
        });
    }

    /** @param list<string> $permissionKeys @return list<string> */
    private static function navKeysFor(array $permissionKeys): array
    {
        $nav = ['overview'];
        $has = static fn (string $prefix): bool => (bool) array_filter(
            $permissionKeys,
            static fn (string $p): bool => str_starts_with($p, $prefix),
        );
        if ($has('proposal.')) {
            $nav[] = 'missions';
        }
        if ($has('approval.')) {
            $nav[] = 'approvals';
        }
        if ($has('decision.') || $has('audit.')) {
            $nav[] = 'ledger';
        }
        if ($has('competition_monitoring.') || $has('company_signal.') || $has('battlecard.')) {
            $nav[] = 'competition';
        }

        return $nav;
    }

    /**
     * Project the M10 decision ledger as an ordered, read-only timeline.
     *
     * Regenerable from the canonical records, and it carries the stream's head chain hash so a
     * reader can tell whether the timeline they are looking at still describes the current stream.
     *
     * @return array{projection_id: string, record_count: int}
     */
    public function buildDecisionTimeline(string $ledgerStreamId): array
    {
        $stream = $this->db->one(
            'SELECT tenant_id, workspace_id, current_head_chain_hash FROM decision_ledger_streams
             WHERE decision_ledger_stream_id = ?',
            [$ledgerStreamId]
        );
        if ($stream === null) {
            throw new \InvalidArgumentException("decision ledger stream not found: {$ledgerStreamId}");
        }

        $records = $this->db->all(
            'SELECT record_sequence_number, record_type, primary_outcome_status, recorded_at
             FROM decision_records WHERE ledger_stream_id = ? ORDER BY record_sequence_number ASC',
            [$ledgerStreamId]
        );
        $timeline = array_map(static fn (array $r): array => [
            'sequence' => (int) $r['record_sequence_number'],
            'recordType' => (string) $r['record_type'],
            'primaryOutcomeStatus' => (string) $r['primary_outcome_status'],
            'recordedAt' => (string) $r['recorded_at'],
        ], $records);

        $tenantId = (string) $stream['tenant_id'];
        $workspaceId = (string) $stream['workspace_id'];

        return $this->db->transaction(function (Database $tx) use (
            $tenantId, $workspaceId, $ledgerStreamId, $timeline, $stream
        ): array {
            $tx->run(
                'DELETE FROM console_decision_timeline_projections
                 WHERE tenant_id = ? AND workspace_id = ? AND ledger_stream_id = ?',
                [$tenantId, $workspaceId, $ledgerStreamId]
            );
            $projectionId = Ids::new();
            $tx->insert('console_decision_timeline_projections', [
                'console_decision_timeline_projection_id' => $projectionId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'ledger_stream_id' => $ledgerStreamId,
                'timeline_json' => json_encode($timeline, JSON_THROW_ON_ERROR),
                'record_count' => count($timeline),
                'head_chain_hash' => (string) $stream['current_head_chain_hash'],
                'stale_status' => 'CURRENT',
                'generated_at' => $this->clock->now(),
            ]);
            $this->touchRefresh($tx, $tenantId, $workspaceId, 'DECISION_TIMELINE', 'OWNER_EVENT');

            return ['projection_id' => $projectionId, 'record_count' => count($timeline)];
        });
    }

    /**
     * Project release readiness TRUTHFULLY.
     *
     * The derivation order is the whole point:
     *
     *   1. BLOCKING GAPS FIRST. An unresolved blocking gap holds readiness BLOCKED even for an
     *      already-released proposal. A gap that a green status can hide is a gap nobody acts on.
     *   2. READY requires BOTH a RELEASED envelope AND a LIVE M10 basis. A release record later
     *      superseded through a correction must not prop up a ready state — a released proposal
     *      whose basis is no longer relied upon has been ROLLED BACK, and the projection says so.
     *
     * @return array{
     *     projection_id: string, release_readiness_state: string,
     *     released_basis_decision_record_id: ?string, blocking_gap_count: int
     * }
     */
    public function buildReleaseReadiness(string $proposalId): array
    {
        $proposal = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM proposal_envelopes WHERE proposal_id = ?',
            [$proposalId]
        );
        if ($proposal === null) {
            throw new \InvalidArgumentException("proposal not found: {$proposalId}");
        }
        $tenantId = (string) $proposal['tenant_id'];
        $workspaceId = (string) $proposal['workspace_id'];
        $status = (string) $proposal['status'];

        $releaseRecord = $this->db->one(
            "SELECT decision_record_id FROM decision_records
             WHERE proposal_id = ? AND record_type = 'INTERNAL_INFORMATIONAL_RELEASE'
             ORDER BY recorded_at DESC, record_sequence_number DESC LIMIT 1",
            [$proposalId]
        );
        $blockingGaps = $this->assurance->listBlockingGaps($tenantId, $workspaceId);

        // A release record counts as a LIVE basis only while it is still relied upon.
        $activeBasis = null;
        if ($releaseRecord !== null) {
            $recordId = (string) $releaseRecord['decision_record_id'];
            if ($this->ledger->isCurrentlyRelied($recordId)) {
                $activeBasis = $recordId;
            }
        }

        $releasedBasis = null;
        if ($blockingGaps !== []) {
            $state = 'BLOCKED';
        } elseif ($status === 'RELEASED' && $activeBasis !== null) {
            $state = 'READY_FOR_INTERNAL_DEMO_REVIEW';
            $releasedBasis = $activeBasis;
        } elseif ($status === 'RELEASED') {
            // A RELEASED envelope whose release record is no longer relied upon: the release was
            // rolled back. Showing it as ready would be the console asserting a release the
            // decision plane has withdrawn.
            $state = 'ROLLED_BACK_OR_SUPERSEDED';
        } elseif ($status === 'SUBMITTED') {
            $state = 'INCOMPLETE';
        } elseif ($status === 'SUPERSEDED' || $status === 'WITHDRAWN') {
            $state = 'ROLLED_BACK_OR_SUPERSEDED';
        } else {
            $state = 'NOT_EVALUATED';
        }

        return $this->db->transaction(function (Database $tx) use (
            $tenantId, $workspaceId, $proposalId, $state, $releasedBasis, $blockingGaps
        ): array {
            $tx->run(
                'DELETE FROM console_release_readiness_projections
                 WHERE tenant_id = ? AND workspace_id = ? AND proposal_id = ?',
                [$tenantId, $workspaceId, $proposalId]
            );
            $projectionId = Ids::new();
            $tx->insert('console_release_readiness_projections', [
                'console_release_readiness_projection_id' => $projectionId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'proposal_id' => $proposalId,
                'release_readiness_state' => $state,
                'released_basis_decision_record_id' => $releasedBasis,
                'blocking_gap_count' => count($blockingGaps),
                'blocking_gap_ids' => json_encode(
                    array_column($blockingGaps, 'gap_record_id'),
                    JSON_THROW_ON_ERROR
                ),
                'stale_status' => 'CURRENT',
                'generated_at' => $this->clock->now(),
            ]);
            $this->touchRefresh($tx, $tenantId, $workspaceId, 'RELEASE_READINESS', 'OWNER_EVENT');

            return [
                'projection_id' => $projectionId,
                'release_readiness_state' => $state,
                'released_basis_decision_record_id' => $releasedBasis,
                'blocking_gap_count' => count($blockingGaps),
            ];
        });
    }

    /** @return array{release_readiness_state: string, released_basis_decision_record_id: ?string}|null */
    public function readReleaseReadiness(string $proposalId): ?array
    {
        $row = $this->db->one(
            'SELECT release_readiness_state, released_basis_decision_record_id
             FROM console_release_readiness_projections WHERE proposal_id = ?',
            [$proposalId]
        );

        return $row === null ? null : [
            'release_readiness_state' => (string) $row['release_readiness_state'],
            'released_basis_decision_record_id' => $row['released_basis_decision_record_id'] === null
                ? null
                : (string) $row['released_basis_decision_record_id'],
        ];
    }

    // ---------------------------------------------------------------- material display references

    /**
     * Record, append-only, that a MATERIAL status was shown — but only if it has a true owner basis.
     *
     * The basis must BE the owning record, in THIS scope. "Some release record exists for the
     * proposal" is not enough: once any proposal is released, that weaker test would let a display
     * be recorded against an arbitrary non-empty basis, leaving the audit trail claiming the
     * display was backed by evidence that does not describe it.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, representation_type: string,
     *     displayed_object_type: string, displayed_object_id: string,
     *     owner_basis_ref: string, safe_label: string
     * } $input
     * @return array<string, mixed>
     */
    public function recordDisplayReference(array $input): array
    {
        if (trim($input['owner_basis_ref']) === '') {
            return ['outcome' => 'REJECTED', 'reason_code' => 'CONSOLE_MATERIAL_STATUS_SHOWN_WITHOUT_OWNER_BASIS'];
        }

        if ($input['representation_type'] === 'INTERNAL_RELEASE_DISPLAY') {
            $release = $this->db->one(
                "SELECT decision_record_id FROM decision_records
                 WHERE decision_record_id = ? AND proposal_id = ?
                   AND record_type = 'INTERNAL_INFORMATIONAL_RELEASE'
                   AND tenant_id = ? AND workspace_id = ? LIMIT 1",
                [
                    $input['owner_basis_ref'], $input['displayed_object_id'],
                    $input['tenant_id'], $input['workspace_id'],
                ]
            );
            if ($release === null) {
                return ['outcome' => 'REJECTED', 'reason_code' => 'CONSOLE_RELEASE_SHOWN_WITHOUT_M10_RECORD'];
            }
        }

        if ($input['representation_type'] === 'CUSTOMER_OUTPUT_PROJECTION_DISPLAY') {
            // BOTH M10 anchors must be real: the projection must exist in THIS scope, and its
            // artifact's release decision record must BE the owner basis.
            $projection = $this->db->one(
                "SELECT p.customer_output_projection_id
                 FROM customer_output_projections p
                 JOIN release_artifacts a ON a.release_artifact_id = p.release_artifact_id
                 JOIN decision_records dr ON dr.decision_record_id = a.decision_record_id
                   AND dr.record_type = 'INTERNAL_INFORMATIONAL_RELEASE'
                 WHERE p.customer_output_projection_id = ? AND a.decision_record_id = ?
                   AND p.tenant_id = ? AND p.workspace_id = ? LIMIT 1",
                [
                    $input['displayed_object_id'], $input['owner_basis_ref'],
                    $input['tenant_id'], $input['workspace_id'],
                ]
            );
            if ($projection === null) {
                return ['outcome' => 'REJECTED', 'reason_code' => 'CONSOLE_RELEASE_SHOWN_WITHOUT_M10_RECORD'];
            }
            // AND the visibility must be LIVE — the latest event, by sequence, must be ACTIVATED.
            // A withdrawn or never-activated projection can never be shown as material: the
            // rollback lever would otherwise stop the content being served while leaving the
            // console still asserting it.
            $latest = $this->db->scalar(
                'SELECT event_kind FROM release_visibility_activations
                 WHERE customer_output_projection_id = ? ORDER BY event_sequence DESC LIMIT 1',
                [$input['displayed_object_id']]
            );
            if ($latest === null || (string) $latest !== 'ACTIVATED') {
                return ['outcome' => 'REJECTED', 'reason_code' => 'CONSOLE_PROJECTION_SHOWN_WITHOUT_ACTIVE_VISIBILITY'];
            }
        }

        $referenceId = Ids::new();
        $this->db->insert('console_display_representation_references', [
            'console_display_representation_reference_id' => $referenceId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'representation_type' => $input['representation_type'],
            'displayed_object_type' => $input['displayed_object_type'],
            'displayed_object_id' => $input['displayed_object_id'],
            'owner_basis_ref' => $input['owner_basis_ref'],
            'is_material' => 1,
            'safe_label' => $input['safe_label'],
            'created_at' => $this->clock->now(),
        ]);

        return ['outcome' => 'RECORDED', 'display_reference_id' => $referenceId];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $surface
     */
    private function insertIntent(array $input, array $surface, string $status, ?string $accessEvaluationId): string
    {
        $intentId = Ids::new();
        $this->db->insert('console_command_intents', [
            'console_command_intent_id' => $intentId,
            'schema_version' => '1',
            'console_command_surface_id' => (string) $surface['console_command_surface_id'],
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'actor_type' => $input['actor_type'],
            'actor_id' => $input['actor_id'],
            // Read from the SURFACE, never from the caller. A caller-supplied permission would let
            // the console be asked to check a weaker right than the owner actually requires.
            'requested_permission' => (string) $surface['required_permission'],
            'resource_type' => $input['resource_type'],
            'resource_id' => $input['resource_id'] ?? null,
            'status' => $status,
            'access_evaluation_id' => $accessEvaluationId,
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'correlation_id' => $input['correlation_id'] ?? null,
            'created_at' => $this->clock->now(),
        ]);

        return $intentId;
    }

    /** @param list<string> $reasonCodes */
    private function insertResultReference(
        string $intentId,
        string $ownerModuleId,
        string $ownerDecision,
        array $reasonCodes,
        string $safeSummary,
    ): string {
        $referenceId = Ids::new();
        $this->db->insert('console_command_result_references', [
            'console_command_result_reference_id' => $referenceId,
            'schema_version' => '1',
            'console_command_intent_id' => $intentId,
            'owner_module_id' => $ownerModuleId,
            'owner_decision' => $ownerDecision,
            'owner_object_ref' => null,
            'reason_codes' => json_encode(array_values($reasonCodes), JSON_THROW_ON_ERROR),
            'safe_summary' => $safeSummary,
            'created_at' => $this->clock->now(),
        ]);

        return $referenceId;
    }

    private function touchRefresh(
        Database $db,
        string $tenantId,
        string $workspaceId,
        string $projectionType,
        string $reason,
    ): void {
        $db->insert('console_refresh_statuses', [
            'console_refresh_status_id' => Ids::new(),
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'projection_type' => $projectionType,
            'last_refresh_reason' => $reason,
            'stale_status' => 'CURRENT',
            'last_refreshed_at' => $this->clock->now(),
        ]);
    }
}
