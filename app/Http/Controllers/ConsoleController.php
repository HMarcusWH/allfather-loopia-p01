<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance;
use Allfather\Loopia\Substrate\M12\Console;

/**
 * The governance surfaces: workspace authority and the decision ledger.
 *
 * BOTH RE-RUN THE M02 GUARD. Being signed in is identity, not authority, and the two are different
 * questions with different answers. A surface that trusted the session would be an authority
 * decision made at login and never revisited — which is precisely how a revoked role keeps working.
 */
final class ConsoleController
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AccessGuard $access,
        private readonly Console $console,
        private readonly Assurance $assurance,
        private readonly DecisionLedger $ledger,
        private readonly View $view,
    ) {
    }

    public function workspace(Request $request, ?array $session): Response
    {
        $workspaceId = $session['active_workspace_id'] ?? null;
        if ($workspaceId === null) {
            return $this->noWorkspace($session);
        }

        // Rebuilt on read. The projection is a read model, and a stale one would show an operator
        // authority they no longer hold.
        $this->console->buildWorkspaceContext(
            (string) $session['tenant_id'],
            (string) $workspaceId,
            (string) $session['operator_id'],
        );
        $projection = $this->db->one(
            'SELECT visible_role_summaries, visible_permission_summaries, generated_at
             FROM console_workspace_context_projections
             WHERE tenant_id = ? AND workspace_id = ? AND operator_id = ?',
            [(string) $session['tenant_id'], (string) $workspaceId, (string) $session['operator_id']]
        );

        $gaps = array_map(
            static fn (array $gap): array => [
                'gap_key' => (string) $gap['gap_type'],
                'severity' => (string) $gap['effect_on_v1'],
                'safe_summary' => 'Status: ' . (string) $gap['status'],
            ],
            $this->assurance->listBlockingGaps((string) $session['tenant_id'], (string) $workspaceId)
        );

        return Response::html($this->view->render('workspace', [
            'title' => 'Workspace',
            'session' => $session,
            'nav' => ['current' => 'console'],
            'context' => [
                'roles' => $projection === null ? [] : json_decode((string) $projection['visible_role_summaries'], true, 512, JSON_THROW_ON_ERROR),
                'permissions' => $projection === null ? [] : json_decode((string) $projection['visible_permission_summaries'], true, 512, JSON_THROW_ON_ERROR),
                'generated_at' => $projection === null ? null : (string) $projection['generated_at'],
            ],
            'gaps' => $gaps,
        ]));
    }

    public function decisions(Request $request, ?array $session): Response
    {
        $workspaceId = $session['active_workspace_id'] ?? null;
        if ($workspaceId === null) {
            return $this->noWorkspace($session);
        }

        // THE GUARD, re-run against the permission this surface actually needs. Naming it here
        // rather than inheriting one is what keeps a new surface from acquiring authority by
        // accident.
        $decision = $this->access->evaluate(
            'OPERATOR',
            (string) $session['operator_id'],
            (string) $session['tenant_id'],
            (string) $workspaceId,
            'decision.read',
            'decision_record',
        );
        if ($decision['decision'] !== 'ALLOW') {
            return Response::html($this->view->render('surface', [
                'title' => 'Decision ledger',
                'session' => $session,
                'nav' => ['current' => 'decisions'],
                'heading' => 'Decision ledger',
                'lede' => 'Every governed decision in this workspace.',
                'columns' => [],
                'roleNote' => null,
                'result' => [
                    'decision' => 'DENIED',
                    'required_permission' => 'decision.read',
                    'reason_codes' => $decision['reason_codes'],
                    'rows' => [],
                ],
            ]), 403);
        }

        $streamId = $this->db->scalar(
            "SELECT decision_ledger_stream_id FROM decision_ledger_streams
             WHERE tenant_id = ? AND workspace_id = ? AND status = 'ACTIVE' LIMIT 1",
            [(string) $session['tenant_id'], (string) $workspaceId]
        );

        $entries = $streamId === null ? [] : $this->db->all(
            'SELECT record_sequence_number, record_type, primary_outcome_status,
                    current_reliance_status, safe_decision_summary, recorded_at,
                    record_payload_digest
             FROM decision_records WHERE ledger_stream_id = ?
             ORDER BY record_sequence_number',
            [(string) $streamId]
        );

        return Response::html($this->view->render('decisions', [
            'title' => 'Decision ledger',
            'session' => $session,
            'nav' => ['current' => 'decisions'],
            'entries' => $entries,
            // RECOMPUTED, not read from a status column. A console that reported integrity from a
            // field would report exactly what a tamperer set.
            'verification' => $streamId === null
                ? null
                : (string) $this->ledger->verifyStream((string) $streamId)['status'],
        ]));
    }

    /** @param array<string, mixed>|null $session */
    private function noWorkspace(?array $session): Response
    {
        return Response::html($this->view->render('surface', [
            'title' => 'Workspace',
            'session' => $session,
            'nav' => ['current' => 'console'],
            'heading' => 'Workspace',
            'lede' => 'No active workspace is selected for this session. Choose one at /console/workspaces.',
            'columns' => [],
            'roleNote' => null,
            'result' => [
                'decision' => 'DENIED',
                'required_permission' => 'workspace.read',
                'reason_codes' => ['NO_ACTIVE_WORKSPACE'],
                'rows' => [],
            ],
        ]), 409);
    }
}
