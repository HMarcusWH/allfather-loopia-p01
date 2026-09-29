<?php
declare(strict_types=1);

/**
 * Inspect and explicitly reconcile an interrupted Monitor model stage.
 *
 * Usage:
 *   inspect     --env=... --tenant-id=... --workspace-id=... --operator-id=... --run-id=...
 *   adopt       ... --expected-revision=N
 *   unresolved  ... --expected-revision=N --reason=REASON_CODE
 *
 * Inspection is read-only. Adoption never calls the provider and never releases anything; it may
 * only consume an exact durable result already bound to the run's request/attempt. UNRESOLVED is an
 * append-only operator acknowledgement and does not advance the run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Evaluation\EvaluationReport;
use Allfather\Loopia\Ops\{BetaCli,SourceFingerprint};
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\ModelArtifactStore;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Workflow\{
    ExecutionAdmission,ExecutionGrantStore,MissionConfiguration,OwnerDecisionRegistry,
    RunArtifacts,RunJournal,RunReconciliation
};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'monitor-reconcile: ' . $message . PHP_EOL);
    exit($code);
};

$command = $argv[1] ?? '';
$args = BetaCli::parseArgs(
    [$argv[0], ...array_slice($argv, 2)],
    ['env','tenant-id','workspace-id','operator-id','run-id','expected-revision','reason'],
    $fail,
);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '') {
        $fail("--{$name} is required");
    }

    return $args[$name];
};
$revision = static function () use ($need, $fail): int {
    $value = $need('expected-revision');
    if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1 || (int) $value > 2147483647) {
        $fail('--expected-revision must be a non-negative integer');
    }

    return (int) $value;
};

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$clock = new Clock();
$store = new EvidenceStore($root . '/storage/evidence');
$decisions = OwnerDecisionRegistry::forRoot($root);
$evidence = EvaluationReport::approvalEvidence($db, $root);
$approvals = new PromptApprovalStore($db, $clock, $decisions, $evidence);
$grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
$admission = new ExecutionAdmission(
    $db,
    $clock,
    $grants,
    $approvals,
    $decisions,
    static fn (): string => SourceFingerprint::compute($root),
);
$reconciliation = new RunReconciliation(
    $db,
    $clock,
    new RunJournal($db, $clock),
    new RunArtifacts($store),
    new ModelArtifactStore($db, $clock, $store),
    new MissionConfiguration($db, $clock),
    $admission,
);
$scope = [
    'tenant_id' => $need('tenant-id'),
    'workspace_id' => $need('workspace-id'),
    'operator_id' => $need('operator-id'),
];
$runId = $need('run-id');

$result = match ($command) {
    'inspect' => $reconciliation->inspect($scope, $runId),
    'adopt' => $reconciliation->adopt($scope, $runId, $revision()),
    'unresolved' => $reconciliation->markUnresolved($scope, $runId, $revision(), $need('reason')),
    default => $fail('usage: reconcile.php <inspect|adopt|unresolved> --env=... --tenant-id=... --workspace-id=... --operator-id=... --run-id=... [--expected-revision=N] [--reason=REASON_CODE]'),
};

BetaCli::emit(
    $result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()],
    $result->isOk() ? 0 : 1,
);
