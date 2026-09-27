<?php
declare(strict_types=1);

/**
 * Native Monitor posture for one workspace: INERT_STAGING, MANUAL_PILOT_READY or BLOCKED, with the
 * exact reasons. READ-ONLY, and a DESCRIPTION rather than permission — the admitted grant and its
 * reviewed owner decision are the permission, and every capture, model call and release re-checks
 * them. `ops/deploy/preflight.php` remains the release-copy check; this one asks about a deployment.
 *
 * Usage: php ops/monitor/preflight.php --env=<private env> --tenant-id=… --workspace-id=… [--mission-id=…]
 * Exit status: 0 for INERT_STAGING or MANUAL_PILOT_READY, 1 for BLOCKED.
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
use Allfather\Loopia\Workflow\{ExecutionGrantStore,NativeOperations,OwnerDecisionRegistry};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'monitor-preflight: ' . $message . PHP_EOL);
    exit($code);
};
$args = BetaCli::parseArgs($argv, ['env', 'tenant-id', 'workspace-id', 'mission-id'], $fail);
foreach (['tenant-id', 'workspace-id'] as $required) {
    if (($args[$required] ?? '') === '') {
        $fail("--{$required} is required");
    }
}
$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$clock = new Clock();
$decisions = OwnerDecisionRegistry::forRoot($root);
$evidence = EvaluationReport::approvalEvidence($db, $root);
$approvals = new PromptApprovalStore($db, $clock, $decisions, $evidence);
$grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
$native = new NativeOperations($db, $clock, $grants, $approvals, $decisions, SourceFingerprint::compute($root), $root . '/docs/decisions', $evidence);
$report = $native->readiness($args['tenant-id'], $args['workspace-id'], ($args['mission-id'] ?? '') !== '' ? $args['mission-id'] : null);
BetaCli::emit($report, $report['posture'] === NativeOperations::BLOCKED ? 1 : 0);
