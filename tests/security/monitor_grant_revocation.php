<?php
declare(strict_types=1);

/**
 * A NATIVE Monitor mission against a transport that claims to be live: nothing reaches it without
 * an admitted grant, and losing admission at any checkpoint stops progress without rewriting what
 * already happened.
 */

require_once __DIR__ . '/../support/ExecutionFixture.php';

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\Provider\ModelRequestBuilder;
use Allfather\Loopia\Tests\{Assert,ExecutionFixture as X,Harness,Seed};

$db = Harness::freshDb();
if ($db === null) { Assert::skip(Harness::unavailableReason()); return; }
$clock = Harness::clock();
$fx = X::create($db, $clock);
$scope = $fx['scope'];
$service = $fx['service'];
$value = static function (Outcome $r, string $label): mixed {
    Assert::true($r->isOk(), $label . ($r->isOk() ? '' : ': ' . $r->reasonCode()));
    if (!$r->isOk()) { throw new RuntimeException($label); }
    return $r->value();
};

// ---------------------------------------------------------------- no grant: nothing reaches the world
$baseline = $value($service->startConfigured($scope, $fx['mission_id'], 'native-baseline'), 'native run starts (starting grants nothing)');
Assert::same('EXECUTION_GRANT_NOT_ADMITTED', $service->advance($scope, $baseline['monitor_run_id'])->reasonCode(), 'no admitted grant: capture refused');
Assert::same(0, $fx['source']->calls, 'no source fetch without admission');
Assert::same('NEW', $fx['journal_stage'] = $db->scalar('SELECT stage FROM monitor_runs WHERE monitor_run_id=?', [$baseline['monitor_run_id']]), 'refused admission leaves the run waiting, not failed');

// A proposed grant, and a decision that does not name it, still admit nothing.
$approvals = X::approveAll($fx, 'MANUAL_MONITOR');
$definition = X::manualDefinition($db, $clock, $fx, $approvals);
$proposed = X::value($fx['grants']->propose($scope, $definition));
Assert::same('PROPOSED', $fx['grants']->status($proposed['execution_grant_id']), 'proposal records a definition only');
Assert::same(false, $proposed['grants_execution'], 'proposal says it grants nothing');
Assert::same('EXECUTION_GRANT_NOT_ADMITTED', $service->advance($scope, $baseline['monitor_run_id'])->reasonCode(), 'proposed is not admitted');
$wrong = X::decision($fx, ['MANUAL_MONITOR'], [], [str_repeat('0', 64)]);
Assert::same('OWNER_DECISION_DOES_NOT_AUTHORIZE_GRANT', $fx['grants']->admit($scope, $proposed['execution_grant_id'], $wrong['ref'], $wrong['sha256'])->reasonCode(), 'a decision naming another digest cannot admit this grant');
$right = X::decision($fx, ['MANUAL_MONITOR'], [], [$proposed['grant_digest']]);
Assert::same('OWNER_DECISION_CHANGED', $fx['grants']->admit($scope, $proposed['execution_grant_id'], $right['ref'], str_repeat('a', 64))->reasonCode(), 'the decision must be the exact reviewed bytes');
$value($fx['grants']->admit($scope, $proposed['execution_grant_id'], $right['ref'], $right['sha256']), 'owner decision admits the exact grant');
$grantId = $proposed['execution_grant_id'];

// ---------------------------------------------------------------- admitted: capture, diff, inspected request
$run = $value($service->advance($scope, $baseline['monitor_run_id']), 'admitted baseline capture');
Assert::same('BASELINE', $run['stage'], 'first native observation is a baseline');
Assert::same(1, $fx['grants']->consumed($grantId, 'SOURCE_CAPTURE'), 'the capture spent one unit of grant budget');
$clock->advanceDays(1);
$run = $value($service->startConfigured($scope, $fx['mission_id'], 'native-changed'), 'changed run');
$id = $run['monitor_run_id'];
$run = $value($service->advance($scope, $id), 'second capture');
$run = $value($service->advance($scope, $id), 'diff');
Assert::same('DIFFED', $run['stage'], 'diffed');
$detail = $value($service->detail($scope, $id), 'inspect the classifier request');
Assert::same('ACTIVE', $detail['preview_request']['provenance']->activation_status, 'the inspected request is the ACTIVE selection');
Assert::same($grantId, $detail['preview_request']['provenance']->execution_grant_id, 'the inspected request names its grant');
Assert::same(true, $detail['execution_admission']['admitted'], 'the workbench shows the admission in force');
$inspected = $detail['preview_request']['request_digest'];

// A caller-built ACTIVE prompt, or one with a recomputed digest, never becomes executable.
$forged = ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER')->value();
$forged['activation_status'] = 'ACTIVE';
$forged['approval_status'] = 'APPROVED';
$context = $detail['preview_request']['provenance']->context;
$context = json_decode(json_encode($context), true);
Assert::same('MODEL_PROMPT_NOT_CURRENT_APPROVED_BUNDLE', ModelRequestBuilder::prepare($forged, $context, [], $fx['resolver'])->reasonCode(), 'a self-promoted prompt array is refused');
$forged['execution_grant_id'] = $grantId;
$forged['prompt_approval_id'] = $approvals[0];
Assert::same('MODEL_PROMPT_NOT_CURRENT_APPROVED_BUNDLE', ModelRequestBuilder::prepare($forged, $context, [], $fx['resolver'])->reasonCode(), 'naming a real grant does not complete a forged selection');

// ---------------------------------------------------------------- suspension before dispatch spends nothing
$value($fx['grants']->suspend($scope, $grantId, 'TEST_STAND_DOWN'), 'suspend');
$before = $fx['model']->calls;
Assert::same('EXECUTION_GRANT_NOT_ADMITTED', $service->advance($scope, $id, $inspected)->reasonCode(), 'suspended grant: no model stage');
Assert::same($before, $fx['model']->calls, 'no call while suspended');
Assert::same(0, $fx['grants']->consumed($grantId, 'MODEL_ATTEMPT'), 'no attempt spent while suspended');
Assert::same('DIFFED', $db->scalar('SELECT stage FROM monitor_runs WHERE monitor_run_id=?', [$id]), 'the run waits; it is not failed by a suspension');
$value($fx['grants']->admit($scope, $grantId, $right['ref'], $right['sha256']), 're-admission needs the same reviewed decision');

// ---------------------------------------------------------------- a digest the operator did not see is refused
Assert::same('MONITOR_REQUEST_INSPECTION_REQUIRED', $service->advance($scope, $id, str_repeat('b', 64))->reasonCode(), 'unseen request refused');
$run = $value($service->advance($scope, $id, $inspected), 'admitted classifier call');
Assert::same('ANALYZED', $run['stage'], 'classifier candidate recorded');
Assert::same(1, $fx['model']->calls, 'exactly one provider call');
Assert::same(1, $fx['grants']->consumed($grantId, 'MODEL_ATTEMPT'), 'one attempt spent against the grant');

// ---------------------------------------------------------------- revocation during the call withholds the candidate
$detail = $value($service->detail($scope, $id), 'inspect draft request');
$fx['model']->during = static function () use ($fx, $scope, $grantId): void {
    $fx['grants']->revoke($scope, $grantId, 'TEST_REVOKED_MID_CALL');
};
$run = $value($service->advance($scope, $id, $detail['preview_request']['request_digest']), 'draft call made, then refused');
$fx['model']->during = null;
Assert::same('BLOCKED', $run['stage'], 'the run blocks: the response did not become a candidate');
Assert::same('EXECUTION_GRANT_REVOKED', $run['cursor']['reason_code'], 'and says why');
Assert::same(2, $fx['model']->calls, 'the call did happen — revocation cannot unsend it');
Assert::same(2, $fx['grants']->consumed($grantId, 'MODEL_ATTEMPT'), 'and its attempt stays spent');
Assert::false(isset($run['cursor']['draft_artifact']), 'no draft candidate was accepted');
Assert::same(1, (int) $db->scalar("SELECT COUNT(*) FROM provider_model_results WHERE outcome='REFUSED' AND failure_code='EXECUTION_GRANT_REVOKED'"), 'the attempt receipt records the revocation truthfully');
Assert::same('REVOKED', $fx['grants']->status($grantId), 'revocation is terminal');
Assert::same('EXECUTION_GRANT_TRANSITION_INVALID', $fx['grants']->admit($scope, $grantId, $right['ref'], $right['sha256'])->reasonCode(), 'a revoked grant cannot be re-admitted');

// ---------------------------------------------------------------- every bound fact is re-checked
$fresh = X::create($db, $clock);
$freshApprovals = X::approveAll($fresh, 'MANUAL_MONITOR');
$g = X::admitted($fresh, X::manualDefinition($db, $clock, $fresh, $freshApprovals));
$facts = ['tenant_id' => $fresh['scope']['tenant_id'], 'workspace_id' => $fresh['scope']['workspace_id'], 'operator_id' => $fresh['scope']['operator_id'], 'execution_grant_id' => $g['execution_grant_id'], 'purpose' => 'MANUAL_MONITOR', 'mission_id' => $fresh['mission_id'], 'definition_digest' => $fresh['definition_digest'], 'monitored_source_id' => $fresh['monitored_source_id'], 'capture_url' => $fresh['capture_url'], 'target_org_role' => 'SALES'];
Assert::true($fresh['admission']->check('SOURCE_CAPTURE', $facts)->isOk(), 'the exact facts are admitted');
foreach ([
    ['operator_id', Seed::operator($db, $clock, $fresh['scope']['tenant_id'], 'Another operator'), 'EXECUTION_GRANT_PRINCIPAL_MISMATCH'],
    ['definition_digest', str_repeat('c', 64), 'EXECUTION_GRANT_CONFIGURATION_CHANGED'],
    ['capture_url', 'https://sources.example/other', 'EXECUTION_GRANT_SOURCE_MISMATCH'],
    ['workspace_id', $scope['workspace_id'], 'EXECUTION_GRANT_NOT_FOUND'],
    ['purpose', 'MODEL_EVALUATION', 'EXECUTION_GRANT_PURPOSE_MISMATCH'],
] as [$key, $changed, $reason]) {
    Assert::same($reason, $fresh['admission']->check('SOURCE_CAPTURE', [$key => $changed] + $facts)->reasonCode(), "changed {$key} refuses");
}
Assert::same('EXECUTION_GRANT_TARGET_MISMATCH', $fresh['admission']->check('RELEASE_P01', ['target_org_role' => 'MARKETING'] + $facts)->reasonCode(), 'another target refuses a release');
$modelFacts = $facts + ['credential_id' => $fresh['credential_id'], 'prompt_approval_id' => $freshApprovals[0]];
$approvalRow = $fresh['approvals']->find($fresh['scope']['tenant_id'], $fresh['scope']['workspace_id'], $freshApprovals[0]);
$modelFacts += ['prompt_sha256' => $approvalRow['prompt_sha256'], 'schema_sha256' => $approvalRow['schema_sha256']];
Assert::true($fresh['admission']->check('MODEL_BEFORE_RESERVE', $modelFacts)->isOk(), 'model facts admitted');
Assert::same('EXECUTION_GRANT_PROMPT_NOT_ADMITTED', $fresh['admission']->check('MODEL_BEFORE_RESERVE', ['prompt_sha256' => str_repeat('d', 64)] + $modelFacts)->reasonCode(), 'a prompt other than the approved bytes refuses');
$other = new \Allfather\Loopia\Workflow\ExecutionAdmission($db, $clock, $fresh['grants'], $fresh['approvals'], $fresh['decisions'], str_repeat('e', 64));
Assert::same('EXECUTION_GRANT_RELEASE_MISMATCH', $other->check('SOURCE_CAPTURE', $facts)->reasonCode(), 'a different application release is not the admitted one');

// Replacing the provider key (which rotates the old one) invalidates the binding until superseded.
$fresh['credential_store']->save($fresh['scope'] + ['actor_type' => 'OPERATOR', 'actor_id' => $fresh['scope']['operator_id']], 'replacement-synthetic-key');
Assert::same('EXECUTION_GRANT_CREDENTIAL_CHANGED', $fresh['admission']->check('MODEL_BEFORE_RESERVE', $modelFacts)->reasonCode(), 'a replaced credential refuses the frozen binding');

// Editing the reviewed decision withdraws what it authorized, on the next check.
file_put_contents($fresh['decision_dir'] . '/' . basename($g['decision']['ref']), '{"edited":true}');
Assert::same('OWNER_DECISION_CHANGED', $fresh['admission']->check('SOURCE_CAPTURE', $facts)->reasonCode(), 'an edited decision withdraws admission');
unlink($fresh['decision_dir'] . '/' . basename($g['decision']['ref']));
Assert::same('OWNER_DECISION_NOT_FOUND', $fresh['admission']->check('SOURCE_CAPTURE', $facts)->reasonCode(), 'a removed decision withdraws admission');

// Expiry is computed, not stored.
$third = X::create($db, $clock);
$t = X::admitted($third, X::manualDefinition($db, $clock, $third, X::approveAll($third, 'MANUAL_MONITOR')));
$clock->advanceDays(8);
Assert::same('EXPIRED', $third['grants']->status($t['execution_grant_id']), 'the window ends on its own');
Assert::same('EXECUTION_GRANT_EXPIRED', $third['admission']->check('SOURCE_CAPTURE', ['tenant_id' => $third['scope']['tenant_id'], 'workspace_id' => $third['scope']['workspace_id'], 'operator_id' => $third['scope']['operator_id'], 'execution_grant_id' => $t['execution_grant_id']])->reasonCode(), 'an expired grant refuses');

// Every checkpoint left a record, allow or refuse.
Assert::true((int) $db->scalar("SELECT COUNT(*) FROM monitor_admission_decisions WHERE outcome='REFUSE'") > 5, 'refusals are recorded');
Assert::true((int) $db->scalar("SELECT COUNT(*) FROM monitor_admission_decisions WHERE outcome='ALLOW'") > 5, 'allows are recorded');
