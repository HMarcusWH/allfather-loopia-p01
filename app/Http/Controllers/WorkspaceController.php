<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Auth\Authenticator;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;

/**
 * Choosing which workspace a session is acting in.
 *
 * Without this surface a signed-in operator with a membership had no way to reach their own
 * workspace: every console page answered 409 NO_ACTIVE_WORKSPACE, and the only thing that could
 * set one was a method no route called.
 *
 * THE LIST AND THE SELECTION ARE TWO DIFFERENT AUTHORITIES, and conflating them is the mistake
 * this surface is shaped to avoid.
 *
 *   THE LIST is a MEMBERSHIP projection. It shows the ACTIVE workspaces of this session's tenant
 *   in which this operator holds an ACTIVE membership — nothing else. Not the tenant's workspaces,
 *   not the ones they used to belong to. A chooser that listed workspaces the caller cannot enter
 *   would be a workspace-enumeration surface wearing a convenience label.
 *
 *   THE SELECTION is re-decided from scratch by `Authenticator::setActiveWorkspace`. The list is
 *   NOT the authority for the POST — a workspace id in a form field is untrusted input whoever
 *   rendered the page before it, and a membership can be revoked between the two requests. So the
 *   POST does not consult the list at all; it re-checks tenant, workspace status and membership,
 *   and a cross-tenant id comes back NOT_FOUND rather than FORBIDDEN so the response cannot be
 *   used to enumerate another tenant's workspaces.
 *
 * WHAT THIS SURFACE DOES NOT DO is grant anything. Selecting a workspace changes which workspace
 * the session's questions are asked about; every surface still re-runs the M02 guard against the
 * permission it needs. An operator who selects a workspace they are a member of and hold no role
 * in sees exactly the denials they held before.
 */
final class WorkspaceController
{
    public function __construct(
        private readonly Database $db,
        private readonly Authenticator $auth,
        private readonly View $view,
    ) {
    }

    /** @param array<string, mixed>|null $session */
    public function choose(Request $request, ?array $session): Response
    {
        return $this->render($session, $request->query['error'] ?? null);
    }

    /** @param array<string, mixed>|null $session */
    public function select(Request $request, ?array $session): Response
    {
        if ($session === null) {
            return Response::redirect('/login');
        }

        $result = $this->auth->setActiveWorkspace(
            (string) $session['session_id'],
            $request->input('workspace_id'),
        );
        if ($result->isRefusal()) {
            // The SPECIFIC reason, rendered on the chooser. "Could not select" would leave an
            // operator unable to tell a revoked membership from a workspace that has been closed,
            // and those need different people to fix them.
            return $this->render($session, $result->reasonCode(), 403);
        }

        return Response::redirect('/console');
    }

    /** @param array<string, mixed>|null $session */
    private function render(?array $session, ?string $error, int $status = 200): Response
    {
        $rows = $session === null ? [] : $this->db->all(
            // The CEILING is listed alongside the name deliberately. Which workspace a session acts
            // in decides which capability ceiling its work is bounded by, and an operator choosing
            // between them should not have to go elsewhere to find that out.
            'SELECT w.workspace_id, w.workspace_key, w.display_name, w.environment_class,
                    w.active_v1_capability_ceiling, w.external_side_effects_allowed
             FROM workspace_memberships m
             JOIN workspaces w ON w.workspace_id = m.workspace_id
             WHERE m.tenant_id = ? AND m.principal_type = ? AND m.principal_id = ? AND m.status = ?
               AND w.tenant_id = ? AND w.status = ?
             ORDER BY w.display_name, w.workspace_id',
            [
                (string) $session['tenant_id'], 'OPERATOR', (string) $session['operator_id'], 'ACTIVE',
                (string) $session['tenant_id'], 'ACTIVE',
            ]
        );

        return Response::html($this->view->render('workspaces', [
            'title' => 'Workspaces',
            'session' => $session,
            'nav' => ['current' => 'workspaces'],
            'workspaces' => $rows,
            'activeWorkspaceId' => $session['active_workspace_id'] ?? null,
            'error' => $error,
        ]), $status);
    }
}
