<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;

/** One environment-pinned M10 HTTP read shared by SSR and JSON. */
final class DecisionLedgerAdapter
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AccessGuard $access,
        private readonly DecisionLedger $ledger,
    ) {
    }

    /** @param array<string, mixed>|null $session @return array<string, mixed> */
    public function read(?array $session): array
    {
        $workspaceId = $session['active_workspace_id'] ?? null;
        if ($session === null || $workspaceId === null) {
            return ['decision' => 'NO_ACTIVE_WORKSPACE', 'reason_codes' => ['NO_ACTIVE_WORKSPACE']];
        }

        $tenantId = (string) $session['tenant_id'];
        $operatorId = (string) $session['operator_id'];
        $access = $this->access->evaluate(
            'OPERATOR',
            $operatorId,
            $tenantId,
            (string) $workspaceId,
            'decision.read',
            'decision_record',
        );
        if ($access['decision'] !== 'ALLOW') {
            return [
                'decision' => 'DENIED',
                'required_permission' => 'decision.read',
                'reason_codes' => $access['reason_codes'],
                'data' => null,
            ];
        }

        $workspace = $this->db->one(
            "SELECT environment_class FROM workspaces
             WHERE tenant_id = ? AND workspace_id = ? AND status = 'ACTIVE'",
            [$tenantId, (string) $workspaceId]
        );
        if ($workspace === null) {
            return ['decision' => 'NO_ACTIVE_WORKSPACE', 'reason_codes' => ['NO_ACTIVE_WORKSPACE']];
        }
        $environment = (string) $workspace['environment_class'];

        $streamId = $this->db->scalar(
            "SELECT decision_ledger_stream_id FROM decision_ledger_streams
             WHERE tenant_id = ? AND workspace_id = ? AND environment_class = ? AND status = 'ACTIVE'",
            [$tenantId, (string) $workspaceId, $environment]
        );

        $entries = $streamId === null ? [] : $this->db->all(
            'SELECT record_sequence_number, record_type, primary_outcome_status,
                    current_reliance_status, safe_decision_summary, recorded_at,
                    record_payload_digest
             FROM decision_records WHERE ledger_stream_id = ?
             ORDER BY record_sequence_number',
            [(string) $streamId]
        );

        return [
            'decision' => 'VISIBLE',
            'required_permission' => 'decision.read',
            'reason_codes' => ['GRANTED'],
            'as_of' => $this->clock->now(),
            'data' => [
                'environment_class' => $environment,
                'rows' => $entries,
                'verification' => $streamId === null ? null : (string) $this->ledger->verifyStream((string) $streamId)['status'],
            ],
        ];
    }
}
