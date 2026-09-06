<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;

/**
 * The P01 competitor and monitored-source registry.
 *
 * A competitor is the watched company a signal attaches to. A monitored source binds that
 * competitor to an M05 source reference and its approved licence card.
 *
 * NEITHER ROW GRANTS ANYTHING. The authority is always the underlying governance records: the
 * card's approval, the reference's capture status, the competitor's lifecycle. Registering a
 * monitored source is a statement of intent to watch, and it is refused unless all three
 * authorities already permit it — which is why the refusal reasons name WHICH one did not.
 */
final class Registry
{
    /**
     * Forward-only lifecycle. ARCHIVED is TERMINAL: there is no transition out of it, so an
     * archived competitor cannot be quietly revived and have its history read as continuous.
     *
     * @var array<string, list<string>>
     */
    private const COMPETITOR_TRANSITIONS = [
        'DRAFT' => ['ACTIVE', 'ARCHIVED'],
        'ACTIVE' => ['PAUSED', 'ARCHIVED'],
        'PAUSED' => ['ACTIVE', 'ARCHIVED'],
        'ARCHIVED' => [],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {
    }

    public function registerCompetitor(
        string $tenantId,
        string $workspaceId,
        string $displayName,
        string $competitorClass,
        string $actorType,
        string $actorId,
        ?string $organizationNumber = null,
        string $initialStatus = 'ACTIVE',
    ): Outcome {
        $workspace = $this->db->one('SELECT tenant_id FROM workspaces WHERE workspace_id = ?', [$workspaceId]);
        if ($workspace === null || (string) $workspace['tenant_id'] !== $tenantId) {
            return Outcome::refuse('TENANT_OR_WORKSPACE_NOT_FOUND', [], 'SCOPE', 'S4');
        }

        $competitorId = Ids::new();
        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $competitorId, $tenantId, $workspaceId, $displayName, $organizationNumber,
            $competitorClass, $initialStatus, $actorType, $actorId, $now
        ): Outcome {
            $tx->insert('competitors', [
                'competitor_id' => $competitorId,
                'schema_version' => '1',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
                'display_name' => $displayName,
                'organization_number' => $organizationNumber,
                'competitor_class' => $competitorClass,
                'status' => $initialStatus,
                'created_at' => $now,
            ]);
            // The FIRST transition has a null from-status. Registration is a lifecycle event like
            // any other, and recording it as one means the transition log is a complete history
            // rather than a history that starts wherever someone first changed something.
            $this->appendCompetitorTransition($tx, $competitorId, null, $initialStatus, $actorType, $actorId, 'registered');

            return Outcome::ok(['competitor_id' => $competitorId, 'status' => $initialStatus]);
        });
    }

    public function changeCompetitorStatus(
        string $competitorId,
        string $tenantId,
        string $workspaceId,
        string $toStatus,
        string $actorType,
        string $actorId,
        ?string $reasonSummarySafe = null,
    ): Outcome {
        $row = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM competitors WHERE competitor_id = ?',
            [$competitorId]
        );
        if ($row === null) {
            return Outcome::refuse('COMPETITOR_NOT_FOUND', [], 'PROVENANCE');
        }
        // Competitors are WORKSPACE-scoped, so the command that changes their status must prove the
        // caller is in that workspace. A tenant-only check would let one workspace archive
        // another's competitor inside the same tenant.
        if ((string) $row['tenant_id'] !== $tenantId || (string) $row['workspace_id'] !== $workspaceId) {
            return Outcome::refuse('TENANT_SCOPE_MISMATCH', [], 'SCOPE', 'S4');
        }
        $from = (string) $row['status'];
        if (!in_array($toStatus, self::COMPETITOR_TRANSITIONS[$from] ?? [], true)) {
            return Outcome::refuse('ILLEGAL_TRANSITION', ['from' => $from, 'to' => $toStatus], 'POLICY');
        }

        $now = $this->clock->now();

        return $this->db->transaction(function (Database $tx) use (
            $competitorId, $from, $toStatus, $actorType, $actorId, $reasonSummarySafe, $now
        ): Outcome {
            $tx->run('UPDATE competitors SET status = ? WHERE competitor_id = ? AND status = ?', [
                $toStatus, $competitorId, $from,
            ]);
            $this->appendCompetitorTransition(
                $tx, $competitorId, $from, $toStatus, $actorType, $actorId, $reasonSummarySafe
            );

            return Outcome::ok(['from_status' => $from, 'to_status' => $toStatus]);
        });
    }

    /**
     * Register a monitored source.
     *
     * Three independent authorities must ALL permit it, and each refusal names which one did not:
     *
     *   1. the competitor exists, is in THIS tenant AND workspace, and is live;
     *   2. the licence card exists, is in this tenant, is APPROVED, and describes THIS reference;
     *   3. the source reference exists and is ACTIVE_FOR_CAPTURE.
     *
     * The card-to-reference binding (2's last clause) is not redundant with 3: without it, an
     * approved card for source A could be presented as the authority for capturing source B.
     */
    public function registerMonitoredSource(
        string $tenantId,
        string $workspaceId,
        string $competitorId,
        string $sourceRefId,
        string $sourceLicenseCardId,
        string $sourceKind,
    ): Outcome {
        $competitor = $this->db->one(
            'SELECT tenant_id, workspace_id, status FROM competitors WHERE competitor_id = ?',
            [$competitorId]
        );
        if ($competitor === null) {
            return Outcome::refuse('COMPETITOR_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $competitor['tenant_id'] !== $tenantId) {
            return Outcome::refuse('COMPETITOR_TENANT_MISMATCH', [], 'SCOPE', 'S4');
        }
        // A same-tenant cross-workspace binding — a competitor in W1 monitored under W2 — is a real
        // scope breach even though the tenant matches, so the workspace is bound too.
        if ((string) $competitor['workspace_id'] !== $workspaceId) {
            return Outcome::refuse('COMPETITOR_WORKSPACE_MISMATCH', [], 'SCOPE', 'S4');
        }
        if (!in_array((string) $competitor['status'], ['ACTIVE', 'PAUSED'], true)) {
            return Outcome::refuse('COMPETITOR_NOT_LIVE', [
                'status' => (string) $competitor['status'],
            ], 'POLICY');
        }

        $card = $this->db->one(
            'SELECT tenant_id, source_ref_id, review_status FROM source_license_cards
             WHERE source_license_card_id = ?',
            [$sourceLicenseCardId]
        );
        if ($card === null) {
            return Outcome::refuse('SOURCE_CARD_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $card['tenant_id'] !== $tenantId) {
            return Outcome::refuse('SOURCE_CARD_TENANT_MISMATCH', [], 'SCOPE', 'S4');
        }
        if ((string) $card['review_status'] !== 'approved') {
            return Outcome::refuse('SOURCE_CARD_NOT_APPROVED', [
                'review_status' => (string) $card['review_status'],
            ], 'POLICY');
        }
        if ((string) $card['source_ref_id'] !== $sourceRefId) {
            return Outcome::refuse('SOURCE_CARD_REFERENCE_MISMATCH', [], 'PROVENANCE', 'S4');
        }

        $reference = $this->db->one(
            'SELECT status FROM source_references WHERE source_ref_id = ?',
            [$sourceRefId]
        );
        if ($reference === null) {
            return Outcome::refuse('SOURCE_REFERENCE_NOT_FOUND', [], 'PROVENANCE');
        }
        if ((string) $reference['status'] !== 'ACTIVE_FOR_CAPTURE') {
            return Outcome::refuse('SOURCE_REFERENCE_NOT_ACTIVE_FOR_CAPTURE', [
                'status' => (string) $reference['status'],
            ], 'POLICY');
        }

        $monitoredSourceId = Ids::new();
        $this->db->insert('monitored_sources', [
            'monitored_source_id' => $monitoredSourceId,
            'schema_version' => '1',
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
            'competitor_id' => $competitorId,
            'source_ref_id' => $sourceRefId,
            'source_license_card_id' => $sourceLicenseCardId,
            'source_kind' => $sourceKind,
            'status' => 'ACTIVE',
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok(['monitored_source_id' => $monitoredSourceId]);
    }

    private function appendCompetitorTransition(
        Database $db,
        string $competitorId,
        ?string $fromStatus,
        string $toStatus,
        string $actorType,
        string $actorId,
        ?string $reasonSummarySafe,
    ): void {
        $db->insert('competitor_status_transitions', [
            'transition_id' => Ids::new(),
            'schema_version' => '1',
            'competitor_id' => $competitorId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'initiated_by_actor_type' => $actorType,
            'initiated_by_actor_id' => $actorId,
            'reason_summary_safe' => $reasonSummarySafe,
            'transitioned_at' => $this->clock->now(),
        ]);
    }
}
