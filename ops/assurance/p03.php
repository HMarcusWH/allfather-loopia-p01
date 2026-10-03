<?php
declare(strict_types=1);

/**
 * P03 post-outcome assurance — the operator surface for the three canonical P03 audits (P08).
 *
 * Usage:
 *   seed-profiles  --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *   status         --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *   sweep          --env=... --tenant-id=... --workspace-id=... --operator-id=...
 *                  --signal-window-days=N [--max-targets=N]
 *
 *   seed-profiles  needs `tenant.administer`. Idempotent; never reactivates a suspended profile.
 *   status         needs `incident.read`. Read-only. Per category, over the targets a sweep would
 *                  audit now: each one's latest recorded result, the failing ones listed, and a
 *                  posture of FAILING, INCOMPLETE (a live target never audited), PASSING or
 *                  NO_TARGETS (NOT_AUDITED for the buyer-intent scan before its first run).
 *   sweep          needs `incident.manage`, because a failing audit OPENS an incident. It runs
 *                  CATALOGUE_FRESHNESS over each tenant-level or this-workspace catalogue (a RETIRED
 *                  one only while a live hypothesis still cites it), SIGNAL_PAIRING_FRESHNESS over each
 *                  live fit hypothesis, and the BUYER_INTENT_INVARIANT scan once over every queue item.
 *                  It refuses, before auditing anything, a scope whose catalogues plus hypotheses
 *                  exceed --max-targets (1..1000, default 200) rather than auditing a prefix, and
 *                  refuses while another sweep of the same scope runs. Each audit is judged at its own
 *                  now, catalogues under the card's own capture window, pairings under
 *                  --signal-window-days (1..365; canonical has no default). There is deliberately no
 *                  --as-of and no catalogue-window override (UF-005). Each sweep-run audit records
 *                  the instant and window it was judged under in its run summary.
 *
 * Exit codes: 0 done (for sweep: complete); 1 refused; 2 usage error; 3 database unreachable;
 * 4 refused by the maintenance gate; 5 the sweep ran but was INCOMPLETE — a category had no ACTIVE
 * profile, or an audit was refused or errored — so the scope must not be read as clean.
 *
 * Every audit only READS the audited rows and records its own append-only run/check/finding. None of
 * this is release authority, a substitute for M08, or a fetch: nothing here reaches the network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Product\P03\Assurance as P03Assurance;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M11\Assurance as M11Assurance;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'p03-assurance: ' . $message . PHP_EOL);
    exit($code);
};

$command = $argv[1] ?? '';
$args = BetaCli::parseArgs(
    [$argv[0], ...array_slice($argv, 2)],
    ['env', 'tenant-id', 'workspace-id', 'operator-id', 'signal-window-days', 'max-targets'],
    $fail,
);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '') {
        $fail("--{$name} is required");
    }

    return $args[$name];
};
$days = static function (string $name, bool $required) use ($args, $fail): ?int {
    $value = $args[$name] ?? '';
    if ($value === '') {
        if ($required) {
            $fail("--{$name} is required");
        }

        return null;
    }
    if (preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > 365) {
        $fail("--{$name} must be a whole number of days between 1 and 365");
    }

    return (int) $value * 86_400_000;
};
$usage = 'usage: p03.php <seed-profiles|status|sweep> --env=... --tenant-id=... --workspace-id=... --operator-id=... '
    . '[--signal-window-days=N] [--max-targets=N]';
if (!in_array($command, ['seed-profiles', 'status', 'sweep'], true)) {
    $fail($usage);
}

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$clock = new Clock();
$assurance = new P03Assurance($db, $clock, new M11Assurance($db, $clock, new DecisionLedger($db, $clock)));
$scope = [
    'tenant_id' => $need('tenant-id'),
    'workspace_id' => $need('workspace-id'),
    'operator_id' => $need('operator-id'),
];

$result = match ($command) {
    'seed-profiles' => $assurance->operatorSeedProfiles($scope),
    'status' => $assurance->operatorStatus($scope),
    'sweep' => $assurance->operatorSweep($scope, array_filter([
        'signal_pairing_freshness_window_ms' => $days('signal-window-days', true),
        'max_targets' => ($args['max-targets'] ?? '') === ''
            ? null
            : (preg_match('/^[1-9][0-9]{0,3}$/D', $args['max-targets']) === 1 && (int) $args['max-targets'] <= 1000 ? (int) $args['max-targets'] : $fail('--max-targets must be 1..1000')),
    ], static fn (mixed $v): bool => $v !== null)),
};

$incomplete = $result->isOk() && $command === 'sweep' && ($result->value()['complete'] ?? false) !== true;
BetaCli::emit(
    $result->isOk() ? ['ok' => true, 'command' => $command, 'result' => $result->value()] : ['ok' => false, 'command' => $command, 'reason_code' => $result->reasonCode()],
    $result->isOk() ? ($incomplete ? 5 : 0) : 1,
);
