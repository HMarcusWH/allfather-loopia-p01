<?php
declare(strict_types=1);
namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Workflow\ScheduleService;

/**
 * GET/POST /console/monitor/schedule — scriptless forms over ScheduleService (P09).
 *
 * The page does what `ops/monitor/schedule.php` does for plan, propose, suspend and revoke, with the
 * same owner and the same checks: the Kernel owns authentication and CSRF, and ScheduleService
 * re-evaluates M02 for every act. Scope is the session's; no form field can name a tenant,
 * workspace or operator. A proposal must carry the digest of the plan the operator saw, so a
 * definition that changed between review and submit is re-reviewed rather than recorded.
 *
 * ADMISSION IS NOT HERE. Admitting a schedule needs a reviewed owner decision file in the deployed
 * tree and its SHA-256; that stays a private CLI act. This page only prints the command.
 */
final class MonitorScheduleController
{
    /** Stand-down reasons offered by the form. ScheduleService accepts only reason-code shaped values anyway. */
    public const REASONS = ['OPERATOR_STAND_DOWN', 'SCHEDULE_NO_LONGER_NEEDED', 'SOURCE_POLICY_CHANGED', 'INCIDENT_RESPONSE'];
    public const CADENCES = [3600 => 'Hourly', 21600 => 'Every 6 hours', 43200 => 'Every 12 hours', 86400 => 'Daily', 604800 => 'Weekly'];

    /** @param ScheduleService|\Closure(): ScheduleService $schedules */
    public function __construct(private readonly ScheduleService|\Closure $schedules, private readonly View $view) {}

    private function service(): ScheduleService
    {
        return $this->schedules instanceof \Closure ? ($this->schedules)() : $this->schedules;
    }

    public function show(Request $request, ?array $session): Response
    {
        if ($session === null) { return Response::redirect('/login'); }
        if ($request->method !== 'GET') { return Response::html('Method not allowed', 405); }
        return $this->render($request, $session, null, null, 200, (string) ($request->query['version'] ?? ''));
    }

    public function submit(Request $request, ?array $session): Response
    {
        if ($session === null) { return Response::redirect('/login'); }
        if ($request->method !== 'POST') { return Response::html('Method not allowed', 405); }
        $scope = self::scope($session);
        if ($scope === null) { return $this->render($request, $session, Outcome::refuse('NO_ACTIVE_WORKSPACE'), null, 409); }
        $action = $request->body('action');

        if ($action === 'plan' || $action === 'propose') {
            $input = $this->definition($request, $scope);
            $planned = $this->service()->plan($scope, $input);
            if (!$planned->isOk()) {
                return $this->render($request, $session, $planned, null, $planned->isFailure() ? 500 : 409);
            }
            if ($action === 'plan') {
                return $this->render($request, $session, null, ['input' => $input] + $planned->value());
            }
            $reviewed = $request->body('schedule_digest');
            if ($reviewed === '' || !hash_equals((string) $planned->value()['schedule_digest'], $reviewed)) {
                return $this->render($request, $session, Outcome::refuse('SCHEDULE_PLAN_CHANGED'), ['input' => $input] + $planned->value(), 409);
            }
            $out = $this->service()->propose($scope, $input);
        } elseif ($action === 'suspend' || $action === 'revoke') {
            $version = $request->body('schedule_version_id');
            $reason = $request->body('reason_code');
            $out = $version === '' || !in_array($reason, self::REASONS, true)
                ? Outcome::refuse('SCHEDULE_STAND_DOWN_FIELDS_REQUIRED')
                : ($action === 'suspend' ? $this->service()->suspend($scope, $version, $reason) : $this->service()->revoke($scope, $version, $reason));
        } else {
            $out = Outcome::refuse('SCHEDULE_ACTION_INVALID');
        }

        if (!$out->isOk()) {
            return $this->render($request, $session, $out, null, $out->isFailure() ? 500 : 409);
        }
        $id = (string) ($out->value()['monitor_schedule_version_id'] ?? $request->body('schedule_version_id'));
        return Response::redirect('/console/monitor/schedule' . ($id === '' ? '' : '?version=' . rawurlencode($id)));
    }

    /**
     * The form, as the exact ScheduleService definition. The mission is the one the chosen grant
     * binds, looked up in this workspace's own overview; ScheduleService re-checks the pairing.
     *
     * @return array<string, mixed>
     */
    private function definition(Request $request, array $scope): array
    {
        $grantId = $request->body('execution_grant_id');
        $missionId = '';
        $overview = $this->service()->overview($scope);
        if ($overview->isOk()) {
            foreach ($overview->value()['grants'] as $grant) {
                if ($grant['execution_grant_id'] === $grantId) { $missionId = $grant['mission_id']; }
            }
        }
        $int = static fn (string $v): int|string => preg_match('/^[1-9][0-9]{0,8}$/D', $v) === 1 ? (int) $v : $v;
        $series = $request->body('monitor_schedule_id');

        return [
            'monitor_schedule_id' => $series === '' ? null : $series,
            'mission_id' => $missionId,
            'execution_grant_id' => $grantId,
            'service_identity_id' => $request->body('service_identity_id'),
            'cadence_seconds' => $int($request->body('cadence_seconds')),
            'max_occurrences' => $int($request->body('max_occurrences')),
            'valid_from' => $request->body('valid_from'),
            'valid_until' => $request->body('valid_until'),
        ];
    }

    /** @return array<string, string>|null */
    private static function scope(array $session): ?array
    {
        if (empty($session['tenant_id']) || empty($session['operator_id']) || empty($session['active_workspace_id'])) { return null; }
        return ['tenant_id' => (string) $session['tenant_id'], 'workspace_id' => (string) $session['active_workspace_id'], 'operator_id' => (string) $session['operator_id']];
    }

    private function render(Request $request, array $session, ?Outcome $error, ?array $plan, int $status = 200, string $selected = ''): Response
    {
        $scope = self::scope($session);
        $overview = ['schedules' => [], 'grants' => []];
        if ($scope === null) { $error ??= Outcome::refuse('NO_ACTIVE_WORKSPACE'); $status = 409; }
        else {
            $read = $this->service()->overview($scope);
            if ($read->isOk()) { $overview = $read->value(); }
            else { $error ??= $read; $status = $status === 200 ? 403 : $status; }
        }
        return Response::html($this->view->render('monitor-schedule', [
            'title' => 'Monitor schedules', 'session' => $session, 'nav' => ['current' => 'monitor-schedule'],
            'overview' => $overview, 'plan' => $plan, 'selected' => $selected,
            'errorCodes' => $error?->reasonCodes ?? [], 'errorDetail' => $error?->detail ?? [], 'requestId' => $request->requestId,
            'reasons' => self::REASONS, 'cadences' => self::CADENCES,
        ]), $status);
    }
}
