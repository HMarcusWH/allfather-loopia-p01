<?php
declare(strict_types=1);

/**
 * Private CLI for prompt approvals and execution grants. There is no HTTP route to any of this.
 *
 * Usage (every command also takes --env=<private env> --tenant-id --workspace-id --operator-id):
 *   plan            --grant=<definition.json>      normalize and print the digest a decision must name
 *   propose         --grant=<definition.json>      record the definition as PROPOSED (grants nothing)
 *   admit           --grant-id --decision=docs/decisions/<file>.json --decision-sha256=<hex>
 *   suspend|revoke  --grant-id --reason=<REASON_CODE>
 *   status          --grant-id
 *   approve-prompt  --role --purpose=<MODEL_EVALUATION|MANUAL_MONITOR> --decision --decision-sha256 [--evaluation-ref]
 *   withdraw-prompt --approval-id --reason=<REASON_CODE>
 *
 * AUTHORITY IS NOT CREATED HERE. `admit` and `approve-prompt` succeed only when a reviewed owner
 * decision document, committed under docs/decisions/, names the exact grant digest or prompt
 * identity. This command records that the decision was applied; it cannot stand in for one.
 * Output is identifiers, digests and states — never a credential, prompt body or decision text.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Workflow\{ExecutionGrantStore,OwnerDecisionRegistry};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'authorization: ' . $message . PHP_EOL);
    exit($code);
};

$command = $argv[1] ?? '';
$known = ['env', 'tenant-id', 'workspace-id', 'operator-id', 'grant', 'grant-id', 'decision', 'decision-sha256', 'reason', 'role', 'purpose', 'evaluation-ref', 'approval-id'];
$args = BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)], $known, $fail);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '') {
        $fail("--{$name} is required");
    }

    return $args[$name];
};

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$clock = new Clock();
$decisions = OwnerDecisionRegistry::forRoot($root);
$approvals = new PromptApprovalStore($db, $clock, $decisions);
$grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
$scope = ['tenant_id' => $need('tenant-id'), 'workspace_id' => $need('workspace-id'), 'operator_id' => $need('operator-id')];

$definition = static function () use ($need, $fail, $root): array {
    $path = realpath($need('grant'));
    $public = realpath($root . '/public_html');
    if ($path === false || !is_file($path) || filesize($path) > 65536 || ($public !== false && str_starts_with($path, $public . DIRECTORY_SEPARATOR))) {
        $fail('the grant definition file is missing, too large, or under the served root');
    }
    try {
        $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $fail('the grant definition is not valid JSON');
    }
    if (!is_array($value)) {
        $fail('the grant definition must be a JSON object');
    }

    return $value;
};

$result = match ($command) {
    'plan' => $grants->plan($scope, $definition()),
    'propose' => $grants->propose($scope, $definition()),
    'admit' => $grants->admit($scope, $need('grant-id'), $need('decision'), $need('decision-sha256')),
    'suspend' => $grants->suspend($scope, $need('grant-id'), $need('reason')),
    'revoke' => $grants->revoke($scope, $need('grant-id'), $need('reason')),
    'status' => ($grant = $grants->find($scope['tenant_id'], $scope['workspace_id'], $need('grant-id'))) === null
        ? \Allfather\Loopia\Core\Outcome::refuse('EXECUTION_GRANT_NOT_FOUND')
        : \Allfather\Loopia\Core\Outcome::ok([
            'execution_grant_id' => $grant['execution_grant_id'], 'purpose' => $grant['purpose'], 'grant_digest' => $grant['grant_digest'],
            'status' => $grants->status($grant['execution_grant_id']), 'valid_from' => $grant['valid_from'], 'valid_until' => $grant['valid_until'],
            'model_attempts_used' => $grants->consumed($grant['execution_grant_id'], 'MODEL_ATTEMPT'),
            'source_captures_used' => $grants->consumed($grant['execution_grant_id'], 'SOURCE_CAPTURE'),
            'limits' => $grant['definition']['limits'],
        ]),
    'approve-prompt' => $approvals->approve($scope, $need('role'), $need('purpose'), $need('decision'), $need('decision-sha256'), ($args['evaluation-ref'] ?? '') !== '' ? $args['evaluation-ref'] : null),
    'withdraw-prompt' => $approvals->withdraw($scope, $need('approval-id'), $need('reason')),
    default => $fail('usage: authorization.php <plan|propose|admit|suspend|revoke|status|approve-prompt|withdraw-prompt> --env=... --tenant-id=... --workspace-id=... --operator-id=... [...]'),
};

BetaCli::emit($result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()], $result->isOk() ? 0 : 1);
