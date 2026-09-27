<?php
declare(strict_types=1);

/**
 * Stand native execution down in one workspace.
 *
 * Usage: php ops/monitor/stand-down.php --env=<private env> --tenant-id=… --workspace-id=… --operator-id=…
 *            --reason=<REASON_CODE> [--mode=suspend|revoke] [--grant-id=…] [--stop-runs]
 *
 * Suspends (default) or revokes every admitted execution grant in the workspace — or the one named —
 * so no further capture, model call or release is admitted. `--stop-runs` also stops native runs that
 * have not finished, through the ordinary journal, so their history records the stop.
 *
 * It does NOT delete artifacts, alter recorded reviews, refund consumed budget or retract released
 * intelligence: retraction is the explicit M10 correction/withdrawal operation. A call already in
 * flight cannot be unsent; its response will not become an accepted candidate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\{BetaCli,SourceFingerprint};
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Provider\SecretBox;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Workflow\{ExecutionGrantStore,MonitorFactory,NativeOperations,OwnerDecisionRegistry};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'stand-down: ' . $message . PHP_EOL);
    exit($code);
};
$args = BetaCli::parseArgs($argv, ['env', 'tenant-id', 'workspace-id', 'operator-id', 'reason', 'mode', 'grant-id', 'stop-runs'], $fail);
foreach (['tenant-id', 'workspace-id', 'operator-id', 'reason'] as $required) {
    if (($args[$required] ?? '') === '') {
        $fail("--{$required} is required");
    }
}
$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$env = $config['env'];
$db = BetaCli::connect($env, $fail);
$clock = new Clock();
$decisions = OwnerDecisionRegistry::forRoot($root);
$approvals = new PromptApprovalStore($db, $clock, $decisions);
$grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
$native = new NativeOperations($db, $clock, $grants, $approvals, $decisions, SourceFingerprint::compute($root), $root . '/docs/decisions');
$scope = ['tenant_id' => $args['tenant-id'], 'workspace_id' => $args['workspace-id'], 'operator_id' => $args['operator-id']];
// Stopping a run needs no live leaf: the inactive composition records the stop in the journal.
$stopper = isset($args['stop-runs'])
    ? MonitorFactory::inactive($db, $clock, new EvidenceStore($root . '/storage/evidence'), SecretBox::fromMasterKey($env->require('APP_MASTER_KEY')))
    : null;
$out = $native->standDown($scope, $args['mode'] ?? 'suspend', $args['reason'], ($args['grant-id'] ?? '') !== '' ? $args['grant-id'] : null, $stopper);
BetaCli::emit($out->isOk() ? ['ok' => true, 'result' => $out->value()] : ['ok' => false, 'reason_code' => $out->reasonCode()], $out->isOk() ? 0 : 1);
