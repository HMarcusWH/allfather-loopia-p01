<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P04;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P04 role-distribution composition.
 *
 * DISTRIBUTION ROUTES; IT DOES NOT DERIVE. A briefing composed here says nothing that M10 has not
 * already recorded as released — it selects an audience for an existing claim and attaches the
 * redaction profile that audience's role was configured with. It copies no raw content: the row
 * carries exactly one foreign key to the released source, and the reader follows it.
 *
 * DISTRIBUTION IS INTERNAL. The word means "which of the customer's own people may see this in the
 * console", and nothing in this file, or the tables it writes, can address anyone outside the
 * tenant. There is no channel, no recipient address and no transport. Outbound delivery is a
 * different product plane and is not implemented here.
 *
 * A composed briefing is a DRAFT and confers nothing. Whether it may be shown at all is the M08
 * P04_DISTRIBUTION_ADMISSION verdict's decision, and whether it then becomes visible is M16's.
 */
final class Distribution
{
    /**
     * The org-role views.
     *
     * These are the CUSTOMER'S OWN internal roles, configured by their admin — never inferred from
     * anybody, and never a role at a third-party company. A role outside this closed set is not a
     * narrower target; it is a target the console has no view for.
     *
     * @var list<string>
     */
    public const TARGET_ORG_ROLES = [
        'EXECUTIVE_CEO',
        'EXECUTIVE_CFO',
        'MARKETING_CMO',
        'SALES',
        'CUSTOMER_SUCCESS',
    ];

    /** @var list<string> */
    public const SOURCE_KINDS = ['P01_BRIEFING', 'P03_PROSPECT_OUTPUT'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly DecisionLedger $ledger,
    ) {
    }

    /**
     * Configure a role's redaction policy — the admin act that makes the role a distribution target.
     *
     * The projection is ADVISORY and says so in a CHECK-pinned column. It records what the admin
     * chose; it never grants anything. The enforcement authorities are the M08 admission (which
     * refuses an unconfigured role outright) and the briefing's own frozen redaction profile.
     *
     * @param array{tenant_id: string, workspace_id: string, target_org_role: string, redaction_rules: list<string>} $input
     */
    public function configureRoleRedactionPolicy(array $input): Outcome
    {
        if (!in_array($input['target_org_role'], self::TARGET_ORG_ROLES, true)) {
            return Outcome::refuse('UNKNOWN_TARGET_ROLE', [
                'target_org_role' => $input['target_org_role'],
            ], 'POLICY');
        }

        $rules = array_values($input['redaction_rules']);

        return $this->db->transaction(function (Database $tx) use ($input, $rules): Outcome {
            // Regenerable, so the current row is replaced rather than versioned. Nothing downstream
            // reads history from here — the authority a verdict cites is the briefing's own frozen
            // profile, which was copied at composition time and cannot be changed by a later edit.
            $tx->run(
                'DELETE FROM console_redaction_policy_projections
                 WHERE tenant_id = ? AND workspace_id = ? AND target_org_role = ?',
                [$input['tenant_id'], $input['workspace_id'], $input['target_org_role']]
            );
            $tx->insert('console_redaction_policy_projections', [
                'console_redaction_policy_projection_id' => Ids::new(),
                'schema_version' => '1',
                'tenant_id' => $input['tenant_id'],
                'workspace_id' => $input['workspace_id'],
                'target_org_role' => $input['target_org_role'],
                'redaction_rules_json' => json_encode($rules, JSON_THROW_ON_ERROR),
                'is_advisory_only' => 1,
                'stale_status' => 'CURRENT',
                'generated_at' => $this->clock->now(),
            ]);

            return Outcome::ok([
                'target_org_role' => $input['target_org_role'],
                'redaction_rules' => $rules,
            ]);
        });
    }

    /**
     * Compose a DRAFT per-role briefing over an M10-recorded release.
     *
     * Three gates, and the third is the one that matters: the cited source must be a release the
     * ledger STILL relies on. A superseded or withdrawn record is not a weaker basis for a new
     * distribution — it is a claim the organization has already stopped standing behind, and
     * routing it to a fresh audience would be re-publishing a retraction.
     *
     * @param array{
     *     tenant_id: string, workspace_id: string, target_org_role: string,
     *     source_kind: string, source_id: string, briefing_summary_safe: string
     * } $input
     */
    public function composeRoleDistributionBriefing(array $input): Outcome
    {
        if (!in_array($input['target_org_role'], self::TARGET_ORG_ROLES, true)) {
            return Outcome::refuse('UNKNOWN_TARGET_ROLE', [
                'target_org_role' => $input['target_org_role'],
            ], 'POLICY');
        }
        if (!in_array($input['source_kind'], self::SOURCE_KINDS, true)) {
            return Outcome::refuse('UNKNOWN_SOURCE_KIND', [
                'source_kind' => $input['source_kind'],
            ], 'PROVENANCE');
        }

        // The role must already be a configured distribution target. An unconfigured role has no
        // redaction profile to copy, and composing with an empty one would manufacture a profile
        // the admin never chose.
        $policy = $this->db->one(
            'SELECT redaction_rules_json FROM console_redaction_policy_projections
             WHERE tenant_id = ? AND workspace_id = ? AND target_org_role = ?',
            [$input['tenant_id'], $input['workspace_id'], $input['target_org_role']]
        );
        if ($policy === null) {
            return Outcome::refuse('DISTRIBUTION_TARGET_NOT_CONFIGURED', [
                'target_org_role' => $input['target_org_role'],
            ], 'POLICY');
        }

        $decisionRecordId = $this->sourceDecisionRecord(
            $input['source_kind'],
            $input['source_id'],
            $input['tenant_id'],
            $input['workspace_id'],
        );
        if ($decisionRecordId === null) {
            return Outcome::refuse('SOURCE_NOT_FOUND', [], 'PROVENANCE');
        }
        if (!$this->ledger->isCurrentlyRelied($decisionRecordId)) {
            return Outcome::refuse('SOURCE_NOT_RELEASED', [
                'current_reliance' => $this->ledger->currentReliance($decisionRecordId),
            ], 'PROVENANCE', 'S4');
        }

        $briefingId = Ids::new();
        $this->db->insert('role_distribution_briefings', [
            'role_distribution_briefing_id' => $briefingId,
            'schema_version' => '1',
            'tenant_id' => $input['tenant_id'],
            'workspace_id' => $input['workspace_id'],
            'target_org_role' => $input['target_org_role'],
            // Exactly one is non-null; the storage CHECK enforces it independently of this code.
            'released_briefing_id' => $input['source_kind'] === 'P01_BRIEFING' ? $input['source_id'] : null,
            'released_prospect_output_id' => $input['source_kind'] === 'P03_PROSPECT_OUTPUT' ? $input['source_id'] : null,
            // FROZEN at composition. A later edit of the role's advisory projection cannot loosen
            // the profile a verdict was issued against.
            'redaction_profile' => (string) $policy['redaction_rules_json'],
            'briefing_summary_safe' => $input['briefing_summary_safe'],
            'briefing_status' => 'DRAFT',
            'created_at' => $this->clock->now(),
        ]);

        return Outcome::ok([
            'role_distribution_briefing_id' => $briefingId,
            'redaction_profile' => json_decode((string) $policy['redaction_rules_json'], true, 512, JSON_THROW_ON_ERROR),
            'decision_record_id' => $decisionRecordId,
        ]);
    }

    /**
     * The M10 record behind a cited source, resolved IN SCOPE.
     *
     * The scope predicate is in the lookup itself: a source belonging to another workspace does not
     * resolve, so it can never become the record whose reliance is then checked.
     */
    private function sourceDecisionRecord(
        string $sourceKind,
        string $sourceId,
        string $tenantId,
        string $workspaceId,
    ): ?string {
        $row = $sourceKind === 'P01_BRIEFING'
            ? $this->db->one(
                'SELECT decision_record_id FROM released_briefings
                 WHERE released_briefing_id = ? AND tenant_id = ? AND workspace_id = ?',
                [$sourceId, $tenantId, $workspaceId]
            )
            : $this->db->one(
                'SELECT decision_record_id FROM released_prospect_outputs
                 WHERE released_prospect_output_id = ? AND tenant_id = ? AND workspace_id = ?',
                [$sourceId, $tenantId, $workspaceId]
            );

        return $row === null ? null : (string) $row['decision_record_id'];
    }
}
