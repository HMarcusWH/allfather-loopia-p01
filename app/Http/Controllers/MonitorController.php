<?php
declare(strict_types=1);
namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Workflow\MonitorOperations;

/**
 * Thin server-rendered UI. Kernel owns authentication/lifecycle/CSRF; service rechecks authority.
 *
 * The deployed composition is built per workspace — the session's, never a request parameter — so
 * whether live read and the provider wire exist at all is decided by that workspace's admission.
 * Tests and fixtures may still pass a ready service.
 */
final class MonitorController
{
    /** @param MonitorOperations|\Closure(array):MonitorOperations $monitor */
    public function __construct(private readonly MonitorOperations|\Closure $monitor, private readonly View $view) {}

    private function service(array $scope): MonitorOperations
    {
        return $this->monitor instanceof \Closure ? ($this->monitor)($scope) : $this->monitor;
    }

    public function show(Request $request, ?array $session): Response
    {
        if ($session === null) { return Response::redirect('/login'); }
        if ($request->method !== 'GET') { return Response::html('Method not allowed', 405); }
        return $this->render($request, $session, $request->query['run'] ?? '');
    }

    public function submit(Request $request, ?array $session): Response
    {
        if ($session === null) { return Response::redirect('/login'); }
        if ($request->method !== 'POST') { return Response::html('Method not allowed', 405); }
        $scope = $this->scope($session);
        if ($scope === null) { return $this->render($request, $session, '', Outcome::refuse('NO_ACTIVE_WORKSPACE'), 409); }
        $action = $request->body('action');
        $runId = $request->body('run_id');
        if ($action === 'start') {
            $missionId = $request->body('mission_id'); $key = $request->body('idempotency_key');
            $out = $missionId !== '' && $key !== ''
                ? $this->service($scope)->startConfigured($scope, $missionId, $key)
                : Outcome::refuse('MONITOR_START_FIELDS_REQUIRED');
        } elseif ($runId === '' && in_array($action, ['advance', 'review', 'stop'], true)) {
            $out = Outcome::refuse('MONITOR_RUN_REQUIRED');
        } elseif ($action === 'advance') {
            $digest = $request->body('request_digest');
            $out = $this->service($scope)->advance($scope, $runId, $digest === '' ? null : $digest);
        } elseif ($action === 'review') {
            $decision = $request->body('decision'); $assessment = trim($request->body('assessment'));
            $digest = $request->body('candidate_digest');
            if (!in_array($decision, ['APPROVE', 'HOLD', 'REJECT'], true) || mb_strlen($assessment) < 20 || $digest === '') {
                $out = Outcome::refuse('MONITOR_REVIEW_FIELDS_REQUIRED');
            } elseif (strlen($assessment) > 4000) {
                $out = Outcome::refuse('MONITOR_REVIEW_ASSESSMENT_TOO_LONG');
            } else {
                $out = $this->service($scope)->review($scope, $runId, [
                    'decision' => $decision, 'assessment' => $assessment, 'candidate_digest' => $digest,
                ]);
            }
        } elseif ($action === 'stop') {
            $out = $this->service($scope)->stop($scope, $runId);
        } else { $out = Outcome::refuse('MONITOR_ACTION_INVALID'); }

        if (!$out->isOk()) {
            return $this->render($request, $session, $runId, $out, $out->isFailure() ? 500 : 409);
        }
        $value = $out->value();
        $id = is_array($value) ? (string) ($value['monitor_run_id'] ?? $value['run']['monitor_run_id'] ?? $runId) : $runId;
        return Response::redirect('/console/monitor' . ($id === '' ? '' : '?run=' . rawurlencode($id)));
    }

    private function scope(array $session): ?array
    {
        if (empty($session['tenant_id']) || empty($session['operator_id']) || empty($session['active_workspace_id'])) { return null; }
        return ['tenant_id' => (string) $session['tenant_id'], 'workspace_id' => (string) $session['active_workspace_id'],
            'operator_id' => (string) $session['operator_id']];
    }

    private function render(Request $request, array $session, string $runId, ?Outcome $error = null, int $status = 200): Response
    {
        $scope = $this->scope($session); $listing = ['missions' => [], 'runs' => []]; $detail = null;
        if ($scope === null) { $error ??= Outcome::refuse('NO_ACTIVE_WORKSPACE'); $status = 409; }
        else {
            $service = $this->service($scope);
            $list = $service->list($scope);
            if (!$list->isOk()) { $error ??= $list; $status = $list->isFailure() ? 500 : 403; }
            else {
                $listing = $list->value();
                if ($runId !== '') {
                    $found = $service->detail($scope, $runId);
                    if ($found->isOk()) { $detail = $found->value(); }
                    else { $error ??= $found; $status = $found->isFailure() ? 500 : 403; }
                }
            }
        }
        return Response::html($this->view->render('monitor', [
            'title' => 'Monitor workbench', 'session' => $session, 'nav' => ['current' => 'monitor'],
            'listing' => $listing, 'detail' => $detail, 'errorCodes' => $error?->reasonCodes ?? [],
            'requestId' => $request->requestId,
            'startKey' => $request->method === 'POST' && $request->body('action') === 'start' && $request->body('idempotency_key') !== ''
                ? $request->body('idempotency_key') : bin2hex(random_bytes(16)),
        ]), $status);
    }
}
