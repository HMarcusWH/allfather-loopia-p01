<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Http\ReadAdapters\ConsoleContextAdapter;
use Allfather\Loopia\Http\ReadAdapters\DecisionLedgerAdapter;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance;
use Allfather\Loopia\Substrate\M12\Console;

/** Server-rendered governance fallback, backed by the same read adapters as the React API. */
final class ConsoleController
{
    private readonly ConsoleContextAdapter $context;
    private readonly DecisionLedgerAdapter $decisions;
    private readonly View $view;

    /**
     * The seven-argument form is retained for existing callers/tests while the new composition root
     * injects the two shared adapters directly. Both forms land on the same adapter implementations.
     */
    public function __construct(
        Database|ConsoleContextAdapter $first,
        Clock|DecisionLedgerAdapter $second,
        AccessGuard|View $third,
        ?Console $console = null,
        ?Assurance $assurance = null,
        ?DecisionLedger $ledger = null,
        ?View $view = null,
    ) {
        if ($first instanceof ConsoleContextAdapter) {
            if (!$second instanceof DecisionLedgerAdapter || !$third instanceof View) {
                throw new \InvalidArgumentException('adapter constructor requires context, decisions and view');
            }
            $this->context = $first;
            $this->decisions = $second;
            $this->view = $third;
            return;
        }

        if (!$second instanceof Clock || !$third instanceof AccessGuard || $console === null || $assurance === null || $ledger === null || $view === null) {
            throw new \InvalidArgumentException('legacy constructor requires database, clock, access, console, assurance, ledger and view');
        }
        $this->context = new ConsoleContextAdapter($first, $console, $assurance);
        $this->decisions = new DecisionLedgerAdapter($first, $second, $third, $ledger);
        $this->view = $view;
    }

    public function workspace(Request $request, ?array $session): Response
    {
        $read = $this->context->read($session);
        if (($read['decision'] ?? null) === 'NO_ACTIVE_WORKSPACE') {
            return $this->noWorkspace($session);
        }
        if (($read['decision'] ?? null) === 'DENIED') {
            return Response::html($this->view->render('surface', [
                'title' => 'Workspace', 'session' => $session, 'nav' => ['current' => 'console'],
                'heading' => 'Workspace', 'lede' => 'Current workspace membership no longer permits this context.',
                'columns' => [], 'roleNote' => null,
                'result' => ['decision' => 'DENIED', 'required_permission' => null, 'reason_codes' => $read['reason_codes'] ?? [], 'rows' => []],
            ]), 403);
        }
        if (($read['decision'] ?? null) !== 'VISIBLE') {
            return $this->unavailable($session, 'Workspace');
        }
        $data = (array) ($read['data'] ?? []);

        return Response::html($this->view->render('workspace', [
            'title' => 'Workspace',
            'session' => $session,
            'nav' => ['current' => 'console'],
            'context' => [
                'roles' => $data['roles'] ?? [],
                'permissions' => $data['permissions'] ?? [],
                'generated_at' => $read['as_of'] ?? null,
            ],
            'gaps' => $data['gaps'] ?? [],
        ]));
    }

    public function decisions(Request $request, ?array $session): Response
    {
        $read = $this->decisions->read($session);
        if (($read['decision'] ?? null) === 'NO_ACTIVE_WORKSPACE') {
            return $this->noWorkspace($session);
        }
        if (($read['decision'] ?? null) === 'DENIED') {
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
                    'reason_codes' => $read['reason_codes'] ?? [],
                    'rows' => [],
                ],
            ]), 403);
        }
        if (($read['decision'] ?? null) !== 'VISIBLE') {
            return $this->unavailable($session, 'Decision ledger');
        }
        $data = (array) ($read['data'] ?? []);

        return Response::html($this->view->render('decisions', [
            'title' => 'Decision ledger',
            'session' => $session,
            'nav' => ['current' => 'decisions'],
            'entries' => $data['rows'] ?? [],
            'verification' => $data['verification'] ?? null,
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
                'required_permission' => null,
                'reason_codes' => ['NO_ACTIVE_WORKSPACE'],
                'rows' => [],
            ],
        ]), 409);
    }

    /** @param array<string, mixed>|null $session */
    private function unavailable(?array $session, string $heading): Response
    {
        return Response::html($this->view->render('surface', [
            'title' => $heading,
            'session' => $session,
            'nav' => ['current' => 'console'],
            'heading' => $heading,
            'lede' => 'This governed read is temporarily unavailable.',
            'columns' => [],
            'roleNote' => null,
            'result' => [
                'decision' => 'DENIED',
                'required_permission' => null,
                'reason_codes' => ['READ_UNAVAILABLE'],
                'rows' => [],
            ],
        ]), 503);
    }
}
