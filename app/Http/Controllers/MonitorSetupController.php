<?php
declare(strict_types=1);
namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Workflow\MonitorSetup;

/**
 * GET/POST /console/monitor/setup — scriptless forms over MonitorSetup (P09).
 *
 * The form builds exactly the request `ops/monitor/setup.php` reads from a file; MonitorSetup owns
 * every rule. `plan` is a dry run that shows REUSE / CREATE / CONFLICT / REFUSE per object and the
 * plan digest; `apply` must carry that digest, and MonitorSetup recomputes the plan and refuses if
 * anything changed. Scope is the session's. The source-review attestation is always the signed-in
 * operator's own: no field can name another reviewer.
 *
 * Kept out of the browser on purpose: importing a company profile's bytes and creating or rotating a
 * provider credential stay private CLI acts. Setup never grants a role, subscribes a tenant or
 * admits native execution, here or anywhere.
 */
final class MonitorSetupController
{
    /** @param MonitorSetup|\Closure(): MonitorSetup $setup */
    public function __construct(private readonly MonitorSetup|\Closure $setup, private readonly View $view) {}

    private function service(): MonitorSetup
    {
        return $this->setup instanceof \Closure ? ($this->setup)() : $this->setup;
    }

    public function show(Request $request, ?array $session): Response
    {
        if ($session === null) { return Response::redirect('/login'); }
        if ($request->method !== 'GET') { return Response::html('Method not allowed', 405); }
        $receipt = null; $error = null; $status = 200;
        $key = (string) ($request->query['setup_key'] ?? '');
        $scope = self::scope($session);
        if ($scope === null) { $error = Outcome::refuse('NO_ACTIVE_WORKSPACE'); $status = 409; }
        elseif ($key !== '') {
            $found = $this->service()->receipt($scope, $key);
            if ($found->isOk()) { $receipt = $found->value(); }
            else { $error = $found; $status = $found->reasonCode() === 'MONITOR_SETUP_RECEIPT_NOT_FOUND' ? 404 : 403; }
        }
        return $this->render($request, $session, [], $error, null, $receipt, $status);
    }

    public function submit(Request $request, ?array $session): Response
    {
        if ($session === null) { return Response::redirect('/login'); }
        if ($request->method !== 'POST') { return Response::html('Method not allowed', 405); }
        $scope = self::scope($session);
        $form = self::fields($request);
        if ($scope === null) { return $this->render($request, $session, $form, Outcome::refuse('NO_ACTIVE_WORKSPACE'), null, null, 409); }
        $action = $request->body('action');
        $successor = $form['successor_of'] === '' ? null : $form['successor_of'];
        $setupRequest = self::request($form, $scope['operator_id']);

        if ($action === 'plan') {
            $planned = $this->service()->plan($scope, $setupRequest, $successor);
            return $planned->isOk()
                ? $this->render($request, $session, $form, null, $planned->value(), null)
                : $this->render($request, $session, $form, $planned, null, null, $planned->isFailure() ? 500 : 409);
        }
        if ($action === 'apply') {
            $digest = $request->body('plan_digest');
            $applied = $digest === ''
                ? Outcome::refuse('MONITOR_SETUP_PLAN_DIGEST_REQUIRED')
                : $this->service()->apply($scope, $setupRequest, $digest, $successor);
            if (!$applied->isOk()) {
                return $this->render($request, $session, $form, $applied, null, null, $applied->isFailure() ? 500 : 409);
            }
            return Response::redirect('/console/monitor/setup?setup_key=' . rawurlencode((string) $applied->value()['setup_key']));
        }
        return $this->render($request, $session, $form, Outcome::refuse('MONITOR_SETUP_ACTION_INVALID'), null, null, 409);
    }

    /** Every form field, as submitted text. The page re-renders exactly these on a plan or a refusal. */
    private const FIELDS = [
        'setup_key', 'successor_of', 'mission_title', 'mission_objective',
        'competitor_display_name', 'competitor_class', 'competitor_organization_number',
        'source_url', 'source_name', 'license_class', 'allowed_use_class', 'customer_output_allowed', 'derived_summary_allowed', 'caching_policy', 'retention_class',
        'review_approved_at', 'review_evidence_reference', 'review_evidence_sha256', 'robots_policy_checked', 'terms_policy_checked',
        'profile_evidence_ids', 'target_org_role', 'audience', 'redaction_rules', 'credential_id',
        'max_input_bytes', 'max_output_tokens', 'max_model_calls', 'max_observation_age_seconds',
    ];

    /** @return array<string, string> */
    private static function fields(Request $request): array
    {
        $out = [];
        foreach (self::FIELDS as $name) { $out[$name] = $request->body($name); }
        return $out;
    }

    /**
     * The MonitorSetup request. Shapes only: a number that is not a plain integer stays text so
     * validation refuses it by name, and the reviewer is always the signed-in operator.
     *
     * @param array<string, string> $f
     * @return array<string, mixed>
     */
    private static function request(array $f, string $operatorId): array
    {
        $int = static fn (string $v): int|string => preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1 ? (int) $v : $v;
        $lines = static fn (string $v): array => array_values(array_filter(array_map('trim', preg_split('/\R/', $v) ?: []), static fn (string $x): bool => $x !== ''));
        $target = ['target_org_role' => $f['target_org_role'], 'redaction_rules' => $lines($f['redaction_rules'])];
        if ($f['audience'] !== '') { $target['audience'] = $f['audience']; }

        return [
            'setup_key' => $f['setup_key'],
            'mission' => ['title' => $f['mission_title'], 'objective' => $f['mission_objective']],
            'competitor' => ['display_name' => $f['competitor_display_name'], 'competitor_class' => $f['competitor_class'], 'organization_number' => $f['competitor_organization_number'] === '' ? null : $f['competitor_organization_number']],
            'source' => [
                'url' => $f['source_url'], 'source_name' => $f['source_name'], 'license_class' => $f['license_class'], 'allowed_use_class' => $f['allowed_use_class'],
                'customer_output_allowed' => $f['customer_output_allowed'] === '1', 'derived_summary_allowed' => $f['derived_summary_allowed'] === '1',
                'caching_policy' => $f['caching_policy'], 'retention_class' => $f['retention_class'],
                'review' => [
                    'approved_by_operator_id' => $operatorId, 'approved_at' => $f['review_approved_at'],
                    'evidence_reference' => $f['review_evidence_reference'], 'evidence_sha256' => strtolower($f['review_evidence_sha256']),
                    'robots_policy_checked' => $f['robots_policy_checked'] === '1', 'terms_policy_checked' => $f['terms_policy_checked'] === '1',
                ],
            ],
            'profile_evidence_ids' => $lines($f['profile_evidence_ids']),
            'target' => $target,
            'credential_id' => $f['credential_id'],
            'context_limits' => [
                'max_input_bytes' => $int($f['max_input_bytes']), 'max_output_tokens' => $int($f['max_output_tokens']),
                'max_model_calls' => $int($f['max_model_calls']), 'max_observation_age_seconds' => $int($f['max_observation_age_seconds']),
            ],
        ];
    }

    /** @return array<string, string>|null */
    private static function scope(array $session): ?array
    {
        if (empty($session['tenant_id']) || empty($session['operator_id']) || empty($session['active_workspace_id'])) { return null; }
        return ['tenant_id' => (string) $session['tenant_id'], 'workspace_id' => (string) $session['active_workspace_id'], 'operator_id' => (string) $session['operator_id']];
    }

    /** @param array<string, string> $form */
    private function render(Request $request, array $session, array $form, ?Outcome $error, ?array $plan, ?array $receipt, int $status = 200): Response
    {
        return Response::html($this->view->render('monitor-setup', [
            'title' => 'Monitor setup', 'session' => $session, 'nav' => ['current' => 'monitor-setup'],
            'form' => $form + array_fill_keys(self::FIELDS, ''), 'fieldNames' => self::FIELDS, 'plan' => $plan, 'receipt' => $receipt,
            'errorCodes' => $error?->reasonCodes ?? [], 'errorDetail' => $error?->detail ?? [], 'requestId' => $request->requestId,
            'roles' => \Allfather\Loopia\Product\P04\Distribution::TARGET_ORG_ROLES,
            'audiences' => \Allfather\Loopia\Workflow\ContextEnvelope::AUDIENCES,
        ]), $status);
    }
}
