<?php
declare(strict_types=1);
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Http\Controllers\MonitorController;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Workflow\MonitorOperations;
use Allfather\Loopia\Tests\Assert;

// A recording service proves controller boundaries. It is not a substitute for Kernel CSRF or DB gates.
$service = new class implements MonitorOperations {
    public array $calls = [];
    public array $record = ['run' => ['monitor_run_id' => 'run-1', 'mission_id' => 'mission-1', 'stage' => 'DIFFED', 'execution_mode' => 'FIXTURE'],
        'events' => [], 'integrity' => 'PASS', 'artifacts' => [['kind' => 'candidate', 'text' => '</pre><script>alert(1)</script>']],
        'preview_request' => ['request_digest' => 'digest-reviewed', 'instructions' => 'Exact Swedish åäö instructions', 'body' => ['input' => 'untrusted source']],
        'review_digest' => 'candidate-reviewed'];
    public ?Outcome $failure = null;
    public function list(array $scope): Outcome { $this->calls[] = ['list', $scope]; return Outcome::ok(['missions' => [['mission_id' => 'mission-1', 'title' => 'Configured mission', 'execution_mode' => 'FIXTURE']], 'runs' => [$this->record['run']]]); }
    public function detail(array $scope, string $runId): Outcome { $this->calls[] = ['detail', $scope, $runId]; return Outcome::ok($this->record); }
    public function startConfigured(array $scope, string $missionId, string $key): Outcome { $this->calls[] = ['start', $scope, $missionId, $key]; return $this->failure ?? Outcome::ok($this->record['run']); }
    public function advance(array $scope, string $runId, ?string $digest = null): Outcome { $this->calls[] = ['advance', $scope, $runId, $digest]; return $this->failure ?? Outcome::ok($this->record['run']); }
    public function review(array $scope, string $runId, array $review): Outcome { $this->calls[] = ['review', $scope, $runId, $review]; return $this->failure ?? Outcome::ok($this->record['run']); }
    public function stop(array $scope, string $runId): Outcome { $this->calls[] = ['stop', $scope, $runId]; return $this->failure ?? Outcome::ok($this->record['run']); }
};
$controller = new MonitorController($service, new View(dirname(__DIR__, 2) . '/app/Http/templates'));
$session = ['tenant_id' => 'tenant-real', 'operator_id' => 'operator-real', 'active_workspace_id' => 'workspace-real', 'display_name' => 'Test operator', 'csrf_token' => 'form-fixture-only'];
$scope = ['tenant_id' => 'tenant-real', 'workspace_id' => 'workspace-real', 'operator_id' => 'operator-real'];
$request = static fn ($method, $query = [], $form = []) => Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/console/monitor'], $query, $form, []);

$response = $controller->show($request('GET'), null);
Assert::same(303, $response->status, 'anonymous redirects');
Assert::same('/login', $response->headers['Location'], 'anonymous destination fixed');
Assert::same([], $service->calls, 'anonymous never calls service');
$response = $controller->show($request('GET'), [...$session, 'active_workspace_id' => null]);
Assert::same(409, $response->status, 'missing workspace not empty success');
Assert::same([], $service->calls, 'missing scope never calls service');

$response = $controller->show($request('GET', ['run' => 'run-1', 'tenant_id' => 'foreign', 'workspace_id' => 'foreign']), $session);
Assert::same(['list', $scope], $service->calls[0], 'list scope comes only from session');
Assert::same(['detail', $scope, 'run-1'], $service->calls[1], 'detail scope comes only from session');
Assert::same(200, $response->status, 'detail renders');
Assert::contains('Exact Swedish åäö instructions', $response->body, 'exact prompt inspection');
Assert::contains('value="digest-reviewed"', $response->body, 'reviewed request digest bound to form');
Assert::contains('&lt;/pre&gt;&lt;script&gt;', $response->body, 'untrusted artifact escaped');
Assert::false(str_contains($response->body, '<script>alert'), 'artifact cannot execute');
Assert::contains('Synthetic fixture', $response->body, 'fixture mode clear');
Assert::contains('unreleased requests, drafts', $response->body, 'layout does not label draft as released');
Assert::false(str_contains($response->headers['Content-Security-Policy'], 'script-src'), 'SSR CSP stays scriptless');
Assert::false(str_contains($response->body, 'name="tenant_id"'), 'no scope selector');
Assert::false(str_contains($response->body, 'name="provider"'), 'no provider selector');

$service->calls = [];
$response = $controller->submit($request('POST', ['tenant_id' => 'foreign', 'request_digest' => 'query-digest'], ['action' => 'advance', 'run_id' => 'run-1', 'request_digest' => 'digest-reviewed', 'provider' => 'foreign']), $session);
Assert::same(['advance', $scope, 'run-1', 'digest-reviewed'], $service->calls[0], 'advance forwards exact displayed digest only');
Assert::same(303, $response->status, 'successful POST redirects');
Assert::same('/console/monitor?run=run-1', $response->headers['Location'], 'success destination fixed');
$service->calls = [];
$controller->submit($request('POST', ['request_digest' => 'query-only'], ['action' => 'advance', 'run_id' => 'run-1']), $session);
Assert::same(['advance', $scope, 'run-1', null], $service->calls[0], 'query digest cannot substitute body digest');
$service->calls = [];
$controller->submit($request('POST', ['mission_id' => 'foreign'], ['action' => 'start', 'mission_id' => 'mission-1', 'idempotency_key' => 'repeat-key']), $session);
Assert::same(['start', $scope, 'mission-1', 'repeat-key'], $service->calls[0], 'configured mission and retry key forwarded without options');
$service->calls = [];
$controller->submit($request('POST', [], ['action' => 'review', 'run_id' => 'run-1', 'decision' => 'APPROVE', 'assessment' => ' Human checked the cited change. ', 'candidate_digest' => 'candidate-reviewed', 'operator_id' => 'forged']), $session);
Assert::same(['review', $scope, 'run-1', ['decision' => 'APPROVE', 'assessment' => 'Human checked the cited change.', 'candidate_digest' => 'candidate-reviewed']], $service->calls[0], 'human text and exact candidate bound; actor remains session');
$service->calls = [];
$response = $controller->submit($request('POST', ['assessment' => 'query text'], ['action' => 'review', 'run_id' => 'run-1', 'decision' => 'APPROVE', 'candidate_digest' => 'candidate-reviewed']), $session);
Assert::same(409, $response->status, 'missing human assessment refused');
Assert::false(in_array('review', array_column($service->calls, 0), true), 'query assessment never triggers review');
$service->calls = [];
$response = $controller->submit($request('GET', ['action' => 'stop', 'run_id' => 'run-1']), $session);
Assert::same(405, $response->status, 'GET cannot mutate');
Assert::same([], $service->calls, 'GET mutation never reaches service');

$service->record['run']['stage'] = 'REVIEW_REQUIRED';
$response = $controller->show($request('GET', ['run' => 'run-1']), $session);
Assert::contains('name="candidate_digest" value="candidate-reviewed"', $response->body, 'human review pins candidate');
Assert::contains('Choose your decision', $response->body, 'no preselected approval');
Assert::false(str_contains($response->body, 'selected>Approve'), 'approval not preselected');
Assert::contains('required></textarea>', $response->body, 'assessment starts empty');
$service->record['review_digest'] = '';
$response = $controller->show($request('GET', ['run' => 'run-1']), $session);
Assert::false(str_contains($response->body, 'value="review"'), 'no review form without integrity reference');
$service->record['run']['stage'] = 'DIFFED'; $service->record['preview_request'] = ['body' => ['input' => 'new unbound data']];
$response = $controller->show($request('GET', ['run' => 'run-1']), $session);
Assert::false(str_contains($response->body, 'value="advance"'), 'no model advance without request digest');
$service->failure = Outcome::failure('MONITOR_AMBIGUOUS_ATTEMPT');
$response = $controller->submit($request('POST', [], ['action' => 'start', 'mission_id' => 'mission-1', 'idempotency_key' => 'preserve-on-error']), $session);
Assert::same(500, $response->status, 'system failure distinct');
Assert::contains('value="preserve-on-error"', $response->body, 'ambiguous attempt does not silently mint new retry identity');
Assert::contains('MONITOR_AMBIGUOUS_ATTEMPT', $response->body, 'specific refusal/failure visible');


$service->failure = null;
$service->record['run']['stage'] = 'ROUTING_PREPARED';
$service->record['preview_request'] = ['request_digest' => 'fourth-request', 'role' => 'MONITOR_ROUTING_CRITIC'];
$service->record['artifacts'] = [['kind' => 'routing_critic_request', 'payload' => ['routing_package' => 'exact configured target']], ['kind' => 'routing_review', 'payload' => ['assessment' => 'Human reviewed routing']]];
$service->record['current_reliance'] = ['p01_release' => 'WITHDRAWN'];
$response = $controller->show($request('GET', ['run' => 'run-1']), $session);
Assert::contains('Configured mission', $response->body, 'real service mission title renders');
Assert::contains('MONITOR_ROUTING_CRITIC', $response->body, 'fourth request renders');
Assert::contains('value="fourth-request"', $response->body, 'fourth request exact digest bound');
Assert::contains('routing_critic_request', $response->body, 'request artifacts remain generic');
Assert::contains('routing_review', $response->body, 'human routing artifact remains generic');
Assert::contains('WITHDRAWN', $response->body, 'current ledger reliance is visible');
$service->record['preview_request'] = null;
$service->record['preview_reason_code'] = 'MODEL_ROUTING_POLICY_NOT_CURRENT';
$response = $controller->show($request('GET', ['run' => 'run-1']), $session);
Assert::contains('MODEL_ROUTING_POLICY_NOT_CURRENT', $response->body, 'preview refusal visible');
Assert::false(str_contains($response->body, 'value="advance"'), 'refused preview never appears as ordinary advance');
unset($service->record['preview_reason_code']);
$service->calls = [];
$response = $controller->submit($request('POST', [], ['action' => 'review', 'run_id' => 'run-1', 'decision' => 'APPROVE', 'assessment' => 'yes', 'candidate_digest' => 'candidate-reviewed']), $session);
Assert::same(409, $response->status, 'short human assessment refused');
Assert::false(in_array('review', array_column($service->calls, 0), true), 'short assessment never reaches review');
$service->calls = [];
$response = $controller->submit($request('POST', [], ['action' => 'review', 'run_id' => 'run-1', 'decision' => 'APPROVE', 'assessment' => str_repeat('å', 2001), 'candidate_digest' => 'candidate-reviewed']), $session);
Assert::contains('MONITOR_REVIEW_ASSESSMENT_TOO_LONG', $response->body, 'byte bound applies to Swedish assessment');
Assert::false(in_array('review', array_column($service->calls, 0), true), 'overlong assessment never reaches review');

// P09: the structured sections read the recorded run as TEXT. Model, source and operator strings
// are escaped in every section, the exact request stays one (open) expander away, and the release
// table separates what the run recorded from what the ledger says now.
$hostile = '<img src=x onerror=alert(1)>';
$service->record['run'] = ['monitor_run_id' => 'run-1', 'mission_id' => 'mission-1', 'stage' => 'DIFFED', 'execution_mode' => 'FIXTURE',
    'definition' => ['capture_url' => 'https://sources.example/' . $hostile, 'target_org_role' => 'SALES', 'context_selection' => ['audience' => 'SALES', 'profile_evidence_ids' => ['profile-1'], 'purpose' => $hostile]],
    'cursor' => ['capture' => ['before_snapshot_id' => null, 'after_snapshot_id' => 'snap-2', 'after_evidence_id' => 'ev-2'], 'p01_release' => ['outcome' => 'RELEASED', 'decision_record_id' => 'dr-1']]];
$service->record['preview_request'] = ['request_digest' => 'digest-reviewed', 'method' => 'POST', 'endpoint' => 'https://api.example/v1/responses', 'provenance' => ['binding_id' => 'binding-1', 'context' => ['digest' => 'ctx-1']], 'body' => ['input' => $hostile]];
$service->record['artifacts'] = [
    ['kind' => 'classifier', 'digest' => 'a1', 'payload' => ['candidate' => ['status' => 'FINDING', 'signal_class' => 'MARKET_CONTEXT_SIGNAL', 'significance' => 'MINOR', 'source_injection_detected' => true,
        'observed_changes' => [['statement' => $hostile, 'confidence' => 'HIGH']], 'relevance_hypotheses' => [['hypothesis' => 'Planning may shift.', 'uncertainty' => $hostile]], 'limitations' => [$hostile]]]],
    ['kind' => 'draft', 'digest' => 'a2', 'payload' => ['candidate' => ['claims' => [['kind' => 'OBSERVATION', 'text' => $hostile, 'confidence' => 'HIGH']], 'content' => ['why_it_matters_safe' => $hostile]]]],
    ['kind' => 'critic', 'digest' => 'a3', 'payload' => ['candidate' => ['verdict' => 'ACCEPT_FOR_HUMAN_REVIEW', 'requires_human_review' => true, 'findings' => [['severity' => 'INFO', 'code' => 'CLEAN', 'explanation' => $hostile]]]]],
    ['kind' => 'human_review', 'digest' => 'a4', 'payload' => ['decision' => 'APPROVE', 'operator_id' => 'op-1', 'reviewed_at' => '2026-01-02T00:00:00.000Z', 'assessment' => $hostile]],
];
$service->record['current_reliance'] = ['p01_release' => 'WITHDRAWN'];
$response = $controller->show($request('GET', ['run' => 'run-1']), $session);
Assert::same(200, $response->status, 'a fully populated run renders');
foreach (['Source and company basis', 'Before and after evidence', 'Model findings', 'Draft (unreleased)', 'Critic concerns', 'Review history', 'Release: recorded then, current now'] as $section) {
    Assert::contains($section, $response->body, "the workbench has a structured section: {$section}");
}
Assert::false(str_contains($response->body, '<img src=x'), 'no source, model or operator string becomes markup in any section');
Assert::true(substr_count($response->body, '&lt;img src=x onerror=alert(1)&gt;') >= 8, 'each one is shown escaped as text');
Assert::contains('none — the first observation is a baseline', $response->body, 'a missing before-snapshot is explained, not shown as a change');
Assert::contains('source contained instructions aimed at the model', $response->body, 'detected source injection is called out');
Assert::contains('<details open><summary>Exact request</summary>', $response->body, 'the exact request is still on the page, open by default, behind its expander');
Assert::contains('value="digest-reviewed"', $response->body, 'and the advance form is still bound to its digest');
Assert::true(preg_match('#<td>RELEASED <span class="mono">dr-1</span></td>\s*<td><span class="tag [^"]+">WITHDRAWN</span></td>#', $response->body) === 1, 'recorded RELEASED and current WITHDRAWN appear side by side');
