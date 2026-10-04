<?php

declare(strict_types=1);

/**
 * Private TEST governance preparation only; never a provider call or execution admission.
 * php ops/evaluation/carrier.php <plan|apply> --env=<private env> --tenant-id=<id>
 *     --workspace-id=<id> --operator-id=<id> --release-ref=<exact deployed SHA>
 *     [--plan-digest=<reviewed digest, apply only>]
 * Stdout contains synthetic metadata/digests and, after apply, governance IDs for the evaluation CLI.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Evaluation\EvaluationCarrier;
use Allfather\Loopia\Ops\{BetaCli,SourceFingerprint};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'evaluation-carrier: ' . $message . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
if (!in_array($command, ['plan', 'apply'], true)) {
    $fail('usage: carrier.php <plan|apply> --env=... --tenant-id=... --workspace-id=... --operator-id=... --release-ref=... [--plan-digest=...]');
}
$args = BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)], ['env', 'tenant-id', 'workspace-id', 'operator-id', 'release-ref', 'plan-digest'], $fail);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '') { $fail('--' . $name . ' is required'); }
    return $args[$name];
};
$scope = ['tenant_id' => $need('tenant-id'), 'workspace_id' => $need('workspace-id'), 'operator_id' => $need('operator-id')];
$release = $need('release-ref');
if (preg_match('/^[a-f0-9]{40}$/D', $release) !== 1) { $fail('release-ref must be an exact lowercase Git SHA'); }
if ($command === 'plan' && isset($args['plan-digest'])) { $fail('plan does not accept a plan digest'); }
$digest = $command === 'apply' ? $need('plan-digest') : null;
if ($digest !== null && preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) { $fail('plan-digest must be a lowercase SHA-256'); }
$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
if (!in_array($config['environment'], ['testing', 'staging'], true)) {
    $fail('carrier preparation is restricted to testing/staging; production is refused');
}
$env = $config['env'];
if ($env->get('APP_RELEASE_REF', '') !== $release) { $fail('release-ref does not match configured deployment identity'); }
$url = parse_url($env->get('APP_BASE_URL', '') ?? '');
if (!is_array($url) || ($url['scheme'] ?? null) !== 'https' || !isset($url['host'])
    || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || isset($url['port'])
    || !in_array($url['path'] ?? '', ['', '/'], true)) {
    $fail('the configured target must be a plain HTTPS origin');
}
// connect acquires and retains the normal shared maintenance lease for the complete process.
$db = BetaCli::connect($env, $fail);
try {
    $owner = new EvaluationCarrier($db, new Clock(), [
        'target' => $url['host'], 'release_ref' => $release, 'source_fingerprint' => SourceFingerprint::compute($root),
    ]);
    $outcome = $command === 'plan' ? $owner->plan($scope) : $owner->apply($scope, $digest);
} catch (\Throwable) {
    $fail('preparation failed; no driver details are emitted', 1);
}
BetaCli::emit($outcome->isOk()
    ? ['ok' => true, 'command' => $command, 'result' => $outcome->value()]
    : ['ok' => false, 'command' => $command, 'reason_code' => $outcome->reasonCode()], $outcome->isOk() ? 0 : 1);
