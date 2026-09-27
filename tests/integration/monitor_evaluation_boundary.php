<?php
declare(strict_types=1);

/**
 * The evaluation lane runs the product's prompts through the product's governed path against the
 * frozen synthetic manifest — and can neither fetch a source, reach a mission, nor release anything.
 * Its report approves nothing; only an accepted, live, complete report can be cited by an owner.
 */

require_once __DIR__ . '/../support/EvaluationFixture.php';

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Evaluation\{EvaluationCaseContext,EvaluationManifest,EvaluationReport};
use Allfather\Loopia\Tests\{Assert,EvaluationFixture as E,ExecutionFixture as X,Harness};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$fx = E::create($db, $clock, E::wellBehaved());
$scope = $fx['scope'];
$runner = $fx['runner'];
$grantId = $fx['eval_grant']['execution_grant_id'];

// ---------------------------------------------------------------- the grant's scope is evaluation only
$captureFacts = ['tenant_id' => $scope['tenant_id'], 'workspace_id' => $scope['workspace_id'], 'operator_id' => $scope['operator_id'], 'execution_grant_id' => $grantId, 'mission_id' => $fx['mission_id'], 'definition_digest' => $fx['definition_digest'], 'monitored_source_id' => $fx['monitored_source_id'], 'capture_url' => $fx['capture_url']];
Assert::same('EXECUTION_GRANT_PURPOSE_MISMATCH', $fx['admission']->check('SOURCE_CAPTURE', $captureFacts)->reasonCode(), 'an evaluation grant cannot admit a source capture');
Assert::same('EXECUTION_GRANT_PURPOSE_MISMATCH', $fx['admission']->check('RELEASE_P01', $captureFacts + ['target_org_role' => 'SALES'])->reasonCode(), 'nor a release');
Assert::same('EXECUTION_GRANT_NOT_ADMITTED', $fx['grants']->currentFor($scope['tenant_id'], $scope['workspace_id'], 'MANUAL_MONITOR', $fx['mission_id'], $fx['definition_digest'])->reasonCode(), 'and it is never the current grant for a product mission');

// ---------------------------------------------------------------- the composition has no source or release path
$factory = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Evaluation/EvaluationFactory.php');
foreach (['FetchTransport', 'HostResolver', 'SourceCaptureStage', 'ReleaseStages', 'CurlHttpsTransport', 'DnsHostResolver', 'Capture('] as $absent) {
    Assert::false(str_contains(preg_replace('#/\*.*?\*/#s', '', $factory), $absent), "the evaluation composition constructs no {$absent}");
}

// ---------------------------------------------------------------- start, inspect, execute
Assert::same('EVALUATION_CREDENTIAL_NOT_GRANTED', $runner->start($scope, $grantId, 'ALL', $fx['governance'], '00000000-0000-0000-0000-000000000000')->reasonCode(), 'only the granted credential');
Assert::same('EVALUATION_SELECTION_INVALID', $runner->start($scope, $grantId, 'EVERYTHING', $fx['governance'], $fx['credential_id'])->reasonCode(), 'a closed selection vocabulary');
$started = X::value($runner->start($scope, $grantId, 'ALL', $fx['governance'], $fx['credential_id']));
Assert::same(false, $started['approves_prompts'], 'starting a run approves nothing');
Assert::same('LIVE', $started['execution_mode'], 'a live-flagged transport records a LIVE run (the fixture never opens a socket)');
$runId = $started['evaluation_run_id'];
$preview = X::value($runner->preview($scope, $runId, $fx['governance']));
Assert::same(count($fx['manifest']->caseIds('ALL')), count($preview), 'every selected case has an inspectable exact request');
$wrongGovernance=$fx['governance'];$wrongGovernance['critic']['mission_id']=Ids::new();
Assert::same('EVALUATION_GOVERNANCE_MISMATCH',$runner->preview($scope,$runId,$wrongGovernance)->reasonCode(),'preview is bound to the run mission even though it sends nothing');
$sameMissionDifferentGovernance=$fx['governance'];$sameMissionDifferentGovernance['critic']['assignment_id']=Ids::new();
Assert::same('EVALUATION_GOVERNANCE_MISMATCH',$runner->preview($scope,$runId,$sameMissionDifferentGovernance)->reasonCode(),'preview cannot swap to a different governance carrier on the same mission');
Assert::same('EVALUATION_GOVERNANCE_MISMATCH',$runner->execute($scope,$runId,$sameMissionDifferentGovernance,$fx['credential_id'])->reasonCode(),'execution must use exactly the governance that was inspected at run creation');
Assert::same(0, $fx['eval_transport']->calls, 'preview sends nothing');
Assert::same('ACTIVE', $preview[0]['request']['provenance']->activation_status, 'requests run the selected prompt, not the draft content');
Assert::same('SYNTHETIC_EVALUATION_CASE', $preview[0]['request']['provenance']->context->evaluation->material_provenance, 'and carry their synthetic provenance inside the inspected digest');

$executed = X::value($runner->execute($scope, $runId, $fx['governance'], $fx['credential_id']));
Assert::same(18, $executed['recorded'], 'every case recorded exactly one result');
Assert::same(18, $fx['eval_transport']->calls, 'one bounded call per case');
Assert::same(0, X::value($runner->execute($scope, $runId, $fx['governance'], $fx['credential_id']))['recorded'], 'executing again records nothing new');
Assert::same(18, $fx['eval_transport']->calls, 'and calls nothing again');
Assert::same(18, $fx['grants']->consumed($grantId, 'MODEL_ATTEMPT'), 'every call spent grant budget');
Assert::same(18, (int) $db->scalar("SELECT COUNT(*) FROM provider_attempt_purposes WHERE execution_purpose='MODEL_EVALUATION' AND material_provenance='SYNTHETIC_EVALUATION_CASE' AND evaluation_run_id=?", [$runId]), 'each attempt is recorded as LIVE + EVALUATION, not a product mission');
Assert::same(0, (int) $db->scalar("SELECT COUNT(*) FROM provider_model_attempts a LEFT JOIN provider_attempt_purposes pp ON pp.model_attempt_id=a.model_attempt_id WHERE a.mission_id=? AND (pp.execution_purpose IS NULL OR pp.execution_purpose<>'MODEL_EVALUATION')", [$fx['mission_id']]), 'synthetic evaluation attempts do not consume the product mission model-call budget');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM source_snapshots'), 'no source was captured');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM released_briefings'), 'nothing was released');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM monitor_runs'), 'no product run exists');

// ---------------------------------------------------------------- the report
$report = X::value(EvaluationReport::build($db, $scope['tenant_id'], $scope['workspace_id'], $runId, $fx['manifest']));
Assert::same(EvaluationReport::INCOMPLETE, $report['verdict'], 'without human assessments the report is INCOMPLETE');
Assert::true(in_array('ASSESSMENT_MISSING:en-source-injection:GROUNDING', $report['verdict_reasons'], true), 'and names what is missing');
Assert::same(false, $report['approves_prompts'], 'a report never approves prompts');
Assert::same(false, $report['activates'], 'or activates anything');
$approval = static fn () => $fx['approvals']->approve($scope, 'P01_SIGNAL_CLASSIFIER', 'MANUAL_MONITOR', ($d = X::decision($fx, ['MANUAL_MONITOR'], X::promptIdentities('MANUAL_MONITOR', ['P01_SIGNAL_CLASSIFIER'])))['ref'], $d['sha256'], 'evaluation:' . $runId);
Assert::same('PROMPT_APPROVAL_EVALUATION_NOT_ACCEPTED', $approval()->reasonCode(), 'an incomplete evaluation cannot back a product approval');
$someResult = $db->scalar("SELECT evaluation_result_id FROM monitor_evaluation_results WHERE evaluation_run_id=? AND outcome='CANDIDATE' LIMIT 1", [$runId]);
Assert::same('EVALUATION_ASSESSMENT_NOTES_REQUIRED', $runner->assess($scope, $someResult, 'GROUNDING', 'PASS', false, null, 'ok')->reasonCode(), 'an assessment explains itself');
Assert::same('EVALUATION_ASSESSMENT_INVALID', $runner->assess($scope, $someResult, 'GROUNDING', 'FAIL', true, null, 'critical without a category')->reasonCode(), 'a critical failure names its category');
E::assessAll($db, $fx, $runId);
$report = X::value(EvaluationReport::build($db, $scope['tenant_id'], $scope['workspace_id'], $runId, $fx['manifest']));
Assert::same(EvaluationReport::ACCEPTED, $report['verdict'], 'a well-behaved, fully assessed run is accepted for owner review: ' . implode(',', $report['verdict_reasons']));
Assert::same(1.0, $report['totals']['held_out_mechanical_pass_rate'], 'every held-out mechanical check passed');
Assert::same(0, $report['totals']['provider_provenance_failures'], 'every accepted candidate is bound to its exact governed provider attempt and call');
Assert::same(true, !in_array(false, array_column($report['cases'], 'provider_provenance_valid'), true), 'no evaluated case carries invalid provider provenance');
Assert::same(7, $report['totals']['held_or_abstained'], 'holds and abstentions are counted, not hidden');
$approved = $approval();
Assert::true($approved->isOk(), 'an accepted live evaluation can back a product approval' . ($approved->isOk() ? '' : ': ' . $approved->reasonCode()));
Assert::same('MANUAL_MONITOR', $approved->value()['purpose'], 'the product approval is its own record');

// ---------------------------------------------------------------- a run that misses held-out cases is incomplete
$dev = X::value($runner->start($scope, $grantId, 'DEVELOPMENT', $fx['governance'], $fx['credential_id']));
X::value($runner->execute($scope, $dev['evaluation_run_id'], $fx['governance'], $fx['credential_id']));
E::assessAll($db, $fx, $dev['evaluation_run_id']);
$devReport = X::value(EvaluationReport::build($db, $scope['tenant_id'], $scope['workspace_id'], $dev['evaluation_run_id'], $fx['manifest']));
Assert::same(EvaluationReport::INCOMPLETE, $devReport['verdict'], 'a development-only run cannot be accepted');
Assert::true(in_array('HELD_OUT_CASE_NOT_SELECTED:en-source-injection', $devReport['verdict_reasons'], true), 'because held-out cases were not run');

// ---------------------------------------------------------------- a naive model is rejected, critically
$naive = E::create($db, $clock, E::naive());
$n = X::value($naive['runner']->start($naive['scope'], $naive['eval_grant']['execution_grant_id'], 'ALL', $naive['governance'], $naive['credential_id']));
X::value($naive['runner']->execute($naive['scope'], $n['evaluation_run_id'], $naive['governance'], $naive['credential_id']));
$naiveUnassessed = X::value(EvaluationReport::build($db, $naive['scope']['tenant_id'], $naive['scope']['workspace_id'], $n['evaluation_run_id'], $naive['manifest']));
Assert::same(EvaluationReport::REJECTED, $naiveUnassessed['verdict'], 'a mechanically proven critical failure is REJECTED even while human assessments are incomplete');
Assert::true((bool) array_filter($naiveUnassessed['verdict_reasons'], static fn (string $reason): bool => str_starts_with($reason, 'ASSESSMENT_MISSING:')), 'the report still preserves its incompleteness reasons');
E::assessAll($db, $naive, $n['evaluation_run_id']);
$naiveReport = X::value(EvaluationReport::build($db, $naive['scope']['tenant_id'], $naive['scope']['workspace_id'], $n['evaluation_run_id'], $naive['manifest']));
Assert::same(EvaluationReport::REJECTED, $naiveReport['verdict'], 'a model that follows injected instructions is rejected even with every human PASS');
Assert::true($naiveReport['totals']['critical_failures'] >= 4, 'each critical injection case it did not hold on is a critical failure');

// ---------------------------------------------------------------- the manifest cannot move under a run
$copy = sys_get_temp_dir() . '/allfather-eval-manifest-' . bin2hex(random_bytes(6)) . '.json';
copy($fx['manifest']->path, $copy);
$moving = EvaluationManifest::load($copy)->value();
$context = new EvaluationCaseContext($moving);
$built = X::value($context->build($scope, $fx['mission_id'], 'en-source-injection'));
Assert::true($context->revalidate($built)->isOk(), 'a freshly built case context revalidates');
file_put_contents($copy, str_replace('Studio day rate', 'Studio hour rate', (string) file_get_contents($copy)));
Assert::same('EVALUATION_MANIFEST_CHANGED', $context->revalidate($built)->reasonCode(), 'editing the manifest mid-run refuses dispatch');
$tampered = $built;
$tampered['envelope']['evidence'][2]['untrusted_text'] = 'swapped';
Assert::same('EVALUATION_CONTEXT_NOT_BOUND', (new EvaluationCaseContext($moving))->revalidate(['evaluation' => ['manifest_sha256' => str_repeat('0', 64), 'case_id' => 'x']] + $tampered)->reasonCode(), 'a context bound to another manifest refuses');

// Append-only records.
foreach (['DELETE FROM monitor_evaluation_results', 'UPDATE monitor_evaluation_assessments SET verdict=\'FAIL\'', 'DELETE FROM provider_attempt_purposes', 'UPDATE monitor_evaluation_runs SET selection=\'ALL\''] as $sql) {
    try { $db->run($sql); Assert::fail('evaluation record mutated: ' . $sql); } catch (\PDOException) { Assert::pass(); }
}
