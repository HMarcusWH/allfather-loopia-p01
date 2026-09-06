<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P02;

use Allfather\Loopia\Db\Database;

/**
 * The M10 binding floor for P02's released snapshots.
 *
 * A released snapshot row may not be written M10-UNBOUND. The caller must name a real, same-scope
 * `INTERNAL_INFORMATIONAL_RELEASE` decision record that no other released snapshot already binds.
 *
 * This is deliberately a FLOOR — existence, scope, type, unboundness — and not a correspondence
 * check. Whether the record is the RIGHT one for this particular state change is the governed
 * release path's job (`TwinRelease`: M08 VERDICT_ISSUED → M10 append → gate re-run), which is the
 * only route that reaches release in this port. A floor that tried to be the whole check would
 * duplicate the release path's reasoning in a place that cannot see the proposal.
 *
 * It runs as the LAST gate before the insert and returns a typed refusal, so an unbound row is
 * refused by name rather than by a NOT NULL driver error. The column being NOT NULL is the storage
 * half of the same invariant; neither half is trusted to be the only one.
 *
 * Upstream checks three released-snapshot families for an already-bound record. This port has two
 * of them — offering twins are not ported, so `released_offering_twin_snapshots` does not exist and
 * no row could hide there. If offering twins are ever ported, this query gains that leg.
 */
final class M10BindingFloor
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{ok: bool, reason_code?: string}
     */
    public function check(string $tenantId, string $workspaceId, ?string $decisionRecordId): array
    {
        if ($decisionRecordId === null || $decisionRecordId === '') {
            return ['ok' => false, 'reason_code' => 'DECISION_RECORD_MISSING'];
        }

        $record = $this->db->one(
            'SELECT tenant_id, workspace_id, record_type FROM decision_records WHERE decision_record_id = ?',
            [$decisionRecordId]
        );
        // An out-of-scope record reads exactly like an absent one. A foreign-tenant record id that
        // probed differently would answer "does this record exist" for a tenant not entitled to ask.
        if ($record === null
            || (string) $record['tenant_id'] !== $tenantId
            || (string) $record['workspace_id'] !== $workspaceId
        ) {
            return ['ok' => false, 'reason_code' => 'DECISION_RECORD_NOT_FOUND'];
        }
        if ((string) $record['record_type'] !== 'INTERNAL_INFORMATIONAL_RELEASE') {
            return ['ok' => false, 'reason_code' => 'DECISION_RECORD_WRONG_TYPE'];
        }

        // One record, one released snapshot — checked across every released-snapshot family that
        // exists here, so a record already bound anywhere cannot be replayed onto a second snapshot.
        $bound = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM (
               SELECT decision_record_id FROM released_entity_twin_snapshots WHERE decision_record_id = ?
               UNION ALL
               SELECT decision_record_id FROM released_entity_relationship_snapshots WHERE decision_record_id = ?
             ) bound',
            [$decisionRecordId, $decisionRecordId]
        );
        if ($bound > 0) {
            return ['ok' => false, 'reason_code' => 'DECISION_RECORD_ALREADY_BOUND'];
        }

        return ['ok' => true];
    }
}
