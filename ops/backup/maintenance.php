<?php
declare(strict_types=1);

/**
 * Deployment-wide maintenance control. Private CLI only.
 *
 * Entering maintenance first takes the exclusive MaintenanceGate lock, which waits for all in-flight
 * web/scheduler shared leases to finish. The marker then prevents new runtime work. This is NOT an
 * M10 release withdrawal and grants no deployment/restore authority.
 *
 * Usage:
 *   status
 *   enter --env=... --tenant-id=... --workspace-id=... --operator-id=... --reason=BACKUP
 *   leave --operator-id=... --maintenance-id=...
 *   begin-operation --operator-id=... --maintenance-id=... --operation=BACKUP_EXPORT
 *   end-operation --operator-id=... --operation-id=...
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\{BetaCli,MaintenanceGate};
use Allfather\Loopia\Substrate\M02\AccessGuard;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'maintenance: ' . $message . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
$args = BetaCli::parseArgs(
    [$argv[0], ...array_slice($argv, 2)],
    ['env','tenant-id','workspace-id','operator-id','reason','maintenance-id','wait-seconds','operation','operation-id'],
    $fail,
);
$root = dirname(__DIR__, 2);
$clock = new Clock();
$gate = new MaintenanceGate($root, $clock);

if ($command === 'status') {
    BetaCli::emit(['ok' => true, 'result' => [
        'maintenance' => $gate->status(),
        'operation' => $gate->operationStatus(),
    ]]);
}

$need = static function (string $name) use ($args, $fail): string {
    $value = $args[$name] ?? '';
    if ($value === '' || $value === '1') {
        $fail("--{$name} is required");
    }
    return $value;
};
$operatorId = $need('operator-id');

// Commands that can only make maintenance stricter do not touch the database. This is load-bearing:
// a failed "leave" attempt during an export must not append an AccessGuard audit row and thereby
// mutate the very snapshot the export is freezing.
if ($command === 'leave') {
    $result = $gate->leave($need('maintenance-id'), $operatorId);
    BetaCli::emit($result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()], $result->isOk() ? 0 : 1);
}
if ($command === 'begin-operation') {
    $result = $gate->beginOperation($need('maintenance-id'), $operatorId, $need('operation'));
    BetaCli::emit($result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()], $result->isOk() ? 0 : 1);
}
if ($command === 'end-operation') {
    $result = $gate->endOperation($need('operation-id'), $operatorId);
    BetaCli::emit($result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()], $result->isOk() ? 0 : 1);
}
if ($command !== 'enter') {
    $fail('usage: maintenance.php <status|enter|leave|begin-operation|end-operation> ...');
}

// Refuse a second enter from the marker alone before AccessGuard can write an audit record.
$existing = $gate->status();
if (($existing['active'] ?? false) === true) {
    BetaCli::emit(['ok' => false, 'reason_code' => ($existing['state'] ?? '') === 'INVALID'
        ? 'MAINTENANCE_STATE_INVALID' : 'MAINTENANCE_ALREADY_ACTIVE'], 1);
}

$config = BetaCli::configuration($root, $args, $fail);
// Authorization itself may append an access-evaluation row. Hold the runtime/shared side while that
// happens, then release it BEFORE trying to take the exclusive maintenance drain lock. Another enter
// cannot publish maintenance in the middle of this write.
$authorizationLease = $gate->acquireRuntimeLease();
if (!$authorizationLease->isOk()) {
    BetaCli::emit(['ok' => false, 'reason_code' => $authorizationLease->reasonCode()], 1);
}
$db = BetaCli::connect($config['env'], $fail, true);
$scope = [
    'tenant_id' => $need('tenant-id'),
    'workspace_id' => $need('workspace-id'),
    'operator_id' => $operatorId,
];

// Enter is the one operation that establishes deployment maintenance, so it is authorized against
// the current tenant/workspace before the marker exists.
$access = (new AccessGuard($db, $clock))->evaluate(
    'OPERATOR',
    $scope['operator_id'],
    $scope['tenant_id'],
    $scope['workspace_id'],
    'tenant.administer',
    'DEPLOYMENT_MAINTENANCE',
);
if (($access['decision'] ?? '') !== 'ALLOW') {
    BetaCli::emit(['ok' => false, 'reason_code' => 'MAINTENANCE_ACCESS_DENIED'], 1);
}
$authorizationLease->value()['lease']->release();

$result = $gate->enter(
    $scope['operator_id'],
    $config['environment'],
    $need('reason'),
    isset($args['wait-seconds']) && preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $args['wait-seconds']) === 1
        ? (int) $args['wait-seconds'] : 30,
);

BetaCli::emit(
    $result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()],
    $result->isOk() ? 0 : 1,
);
