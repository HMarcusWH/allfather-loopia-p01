<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/**
 * P01 surfacing — the read model that shows released briefings to authorized callers.
 *
 * VISIBILITY IS A DEFAULT-DENY COMPOSITION, and every gate must say yes:
 *
 *   1. M02 grants the read permission to this caller — an ACCESS question, decided server-side by
 *      the same guard every other path uses.
 *   2. The tenant's live subscription tier includes that permission — a COMMERCIAL question. The
 *      tier adds no authority of its own; it only narrows what an already-authorized caller sees.
 *   3. The release is STILL LIVE: the frozen artifact is released, its internal projection's latest
 *      visibility event is ACTIVATED, and the release decision record is still relied upon.
 *
 * Any gate denying makes the briefing invisible, and a denial returns an EMPTY list — never a
 * partial one. A read model that leaked a summary line to a caller it had just denied would be a
 * denial in name only.
 *
 * THE SURFACE RENDERS FROM THE FROZEN M16 ARTIFACT, not from a live join across the domain tables.
 * That is what makes what a viewer saw on a given day reconstructable: the projection is
 * digest-pinned, so the content is the content that was frozen, not whatever the underlying rows
 * have since become. It also gives the rollback lever real force — withdrawing reliance on the
 * release, or deactivating its visibility, removes the briefing from the surface without editing
 * anything.
 *
 * It reads SAFE DERIVED FIELDS ONLY. Never raw captured content, never anything pre-release.
 */
final class Surfacing
{
    /**
     * The read permission each surfaced kind requires — in BOTH gates.
     *
     * The same key on both sides is deliberate: a tenant that bought battlecard access and a role
     * that grants battlecard access are talking about the same thing, and using different keys
     * would let the two drift into permitting different sets.
     *
     * @var array<string, string>
     */
    private const REQUIRED_PERMISSION = [
        'BATTLECARD_BRIEF' => 'battlecard.read',
        'COMPANY_SIGNAL_BRIEF' => 'briefing.read',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AccessGuard $access,
        private readonly DecisionLedger $ledger,
        private readonly Entitlements $entitlements,
    ) {
    }

    /**
     * @param array{
     *     tenant_id: string, workspace_id: string, actor_type: string, actor_id: string,
     *     briefing_kind?: string, correlation_id?: ?string
     * } $input
     * @return array{decision: string, required_permission: string, reason_codes: list<string>, briefings: list<array<string, mixed>>}
     */
    public function listVisibleReleasedBriefings(array $input): array
    {
        $kind = $input['briefing_kind'] ?? 'BATTLECARD_BRIEF';
        $requiredPermission = self::REQUIRED_PERMISSION[$kind] ?? null;
        if ($requiredPermission === null) {
            // An unknown kind has no permission mapped to it, so nothing can authorize reading it.
            // Fail closed rather than defaulting to a permission somebody might hold.
            return [
                'decision' => 'DENIED',
                'required_permission' => '',
                'reason_codes' => ['SURFACED_KIND_NOT_RECOGNIZED'],
                'briefings' => [],
            ];
        }

        // --- gate 1: M02 access -------------------------------------------------------------------
        $access = $this->access->evaluate(
            $input['actor_type'],
            $input['actor_id'],
            $input['tenant_id'],
            $input['workspace_id'],
            $requiredPermission,
            'released_briefing',
            null,
            $input['correlation_id'] ?? null,
        );
        if ($access['decision'] !== 'ALLOW') {
            return [
                'decision' => 'DENIED',
                'required_permission' => $requiredPermission,
                // The guard's OWN reason codes, passed through. Replacing them with a generic
                // "denied" would hide from the caller which of several conditions refused.
                'reason_codes' => $access['reason_codes'],
                'briefings' => [],
            ];
        }

        // --- gate 2: the commercial entitlement -----------------------------------------------------
        if (!$this->entitlements->tenantTierGrants($input['tenant_id'], $requiredPermission)) {
            return [
                'decision' => 'DENIED',
                'required_permission' => $requiredPermission,
                'reason_codes' => ['TIER_DOES_NOT_GRANT_PERMISSION'],
                'briefings' => [],
            ];
        }

        // --- gate 3: the release is still live --------------------------------------------------------
        // The join itself is part of the gate: only a RELEASED_INTERNAL artifact with a PROJECTED
        // TENANT_INTERNAL projection can appear at all. The two per-row checks below are the parts
        // that cannot be expressed as a join — the LATEST activation event and the CURRENT reliance.
        $rows = $this->db->all(
            "SELECT rb.released_briefing_id, rb.briefing_kind, rb.company_signal_id,
                    rb.battlecard_brief_id, rb.decision_record_id, rb.released_at,
                    cop.customer_output_projection_id, cop.content_elements_safe_json,
                    ra.status AS artifact_status
             FROM released_briefings rb
             JOIN release_artifacts ra ON ra.released_briefing_id = rb.released_briefing_id
             JOIN customer_output_projections cop
               ON cop.release_artifact_id = ra.release_artifact_id
              AND cop.audience_class = 'TENANT_INTERNAL' AND cop.status = 'PROJECTED'
             WHERE rb.tenant_id = ? AND rb.workspace_id = ? AND rb.briefing_kind = ?
             ORDER BY rb.released_at, rb.released_briefing_id",
            [$input['tenant_id'], $input['workspace_id'], $kind]
        );

        $briefings = [];
        foreach ($rows as $row) {
            if ((string) $row['artifact_status'] !== 'RELEASED_INTERNAL') {
                continue;
            }
            // The LATEST visibility event decides. A projection that was activated and later
            // withdrawn has a DEACTIVATED latest event, and disappears from the surface — which is
            // what makes the rollback lever real rather than advisory.
            $latest = $this->db->scalar(
                'SELECT event_kind FROM release_visibility_activations
                 WHERE customer_output_projection_id = ? ORDER BY event_sequence DESC LIMIT 1',
                [(string) $row['customer_output_projection_id']]
            );
            if ($latest === null || (string) $latest !== 'ACTIVATED') {
                continue;
            }
            // A release whose decision record is no longer relied upon — superseded or corrected —
            // stops rendering. The briefing row still exists as history; it is simply no longer
            // something the product asserts.
            if (!$this->ledger->isCurrentlyRelied((string) $row['decision_record_id'])) {
                continue;
            }

            $elements = json_decode((string) $row['content_elements_safe_json'], true, 512, JSON_THROW_ON_ERROR);
            $claim = null;
            foreach ($elements as $element) {
                if (($element['elementClass'] ?? null) === 'CLAIM') {
                    $claim = $element;
                    break;
                }
            }

            $briefings[] = [
                'released_briefing_id' => (string) $row['released_briefing_id'],
                'briefing_kind' => (string) $row['briefing_kind'],
                'company_signal_id' => $row['company_signal_id'],
                'battlecard_brief_id' => $row['battlecard_brief_id'],
                // Rendered from the FROZEN projection, not from the live battlecard row. What the
                // viewer sees is what was frozen at release.
                'signal_summary_safe' => $claim['textSafe'] ?? null,
                'confidence_band' => $claim['confidence'] ?? null,
                'released_at' => (string) $row['released_at'],
                'decision_record_id' => (string) $row['decision_record_id'],
            ];
        }

        return [
            'decision' => 'VISIBLE',
            'required_permission' => $requiredPermission,
            'reason_codes' => ['GRANTED'],
            'briefings' => $briefings,
        ];
    }
}
