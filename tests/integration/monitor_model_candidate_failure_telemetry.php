<?php
declare(strict_types=1);

/**
 * Synthetic fixture only: preserves provider usage and a private fixed gate code when
 * a schema-valid model response fails the real post-schema PromptOps review gate.
 * No HTTP transport or paid provider request is constructed.
 */
require_once __DIR__ . '/../support/EvaluationFixture.php';

use Allfather\Loopia\Provider\PreparedModelRequest;
use Allfather\Loopia\Tests\{Assert,EvaluationFixture as E,ExecutionFixture as X,Harness};

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());
    return;
}
$clock = Harness::clock();
$healthy = E::wellBehaved();
$responder = static function (PreparedModelRequest $request) use ($healthy): \Allfather\Loopia\Provider\ModelResponse {
    $caseId = $request->provenance()['context']['evaluation']['case_id'] ?? '';
    if ($caseId !== 'sv-critic-flags-unsupported') {
        return $healthy($request);
    }
    $candidate = E::candidate('MONITOR_REVIEW_CRITIC', 'REVISE');
    // The shape and citations are allowed by JSON Schema. The independent
    // candidate validator (correctly) requires at least one BLOCK for REVISE.
    $candidate['findings'][0]['severity'] = 'INFO';
    return E::wire($candidate);
};
$fx = E::create($db, $clock, $responder, 7);
$scope = $fx['scope'];
$grantId = $fx['eval_grant']['execution_grant_id'];
$governance = $fx['governance'];
$credentialId = $fx['credential_id'];
$started = $fx['runner']->start($scope, $grantId, 'DEVELOPMENT', $governance, $credentialId);
Assert::true($started->isOk(), 'DEVELOPMENT run starts under synthetic fixture grant');
if (!$started->isOk()) { return; }
$runId = $started->value()['evaluation_run_id'];
$run = $fx['runner']->execute($scope, $runId, $governance, $credentialId);
Assert::true($run->isOk(), 'fixture evaluation executes through the governed callback');
if (!$run->isOk()) { return; }
Assert::same(7, $run->value()['recorded'], 'all seven synthetic development cases record their outcomes');
Assert::same(7, $fx['grants']->consumed($grantId, 'MODEL_ATTEMPT'), 'failed candidate still consumes exactly one model budget unit');

$row = $db->one(
    "SELECT r.outcome,r.reason_code,r.candidate_artifact_digest,
            c.input_tokens,c.output_tokens,c.failure_code AS private_failure_code,c.outcome AS call_outcome
     FROM monitor_evaluation_results r
     JOIN provider_call_records c ON c.provider_call_id=r.provider_call_id
     WHERE r.evaluation_run_id=? AND r.case_id=?",
    [$runId, 'sv-critic-flags-unsupported']
);
Assert::true(is_array($row), 'refused model attempt has a durable private call receipt');
if (!is_array($row)) { return; }
Assert::same('REFUSED', $row['outcome'], 'candidate validation fails closed without promotion');
Assert::same('MODEL_CANDIDATE_REFERENCE_INVALID', $row['reason_code'], 'public evaluation refusal remains unchanged');
Assert::same('PROMPT_CANDIDATE_REVIEW_INCONSISTENT', $row['private_failure_code'], 'private call telemetry records the fixed underlying validation rule');
Assert::same('FAILED', $row['call_outcome'], 'refused candidate never becomes a successful call for product use');
Assert::same(120, (int) $row['input_tokens'], 'usage already returned by the provider is preserved on rejection');
Assert::same(60, (int) $row['output_tokens'], 'provider output tokens are preserved on rejection');
Assert::same(null, $row['candidate_artifact_digest'], 'unsafe candidate bytes are never admitted to artifact store');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM released_briefings'), 'validation failure releases nothing');
Assert::same(0, (int) $db->scalar('SELECT COUNT(*) FROM source_snapshots'), 'evaluation captures no source');
