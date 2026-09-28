<?php
declare(strict_types=1);

/**
 * Private CLI for the Monitor model evaluation lane.
 *
 * Usage (every command takes --env=<private env> --tenant-id --workspace-id --operator-id):
 *   start    --grant-id --selection=<DEVELOPMENT|HELD_OUT|ALL> --governance=<file.json> --credential-id
 *   preview  --run-id --governance=<file.json>          exact requests; sends nothing
 *   execute  --run-id --governance=<file.json> --credential-id
 *   assess   --result-id --dimension=<GROUNDING|USEFULNESS> --verdict=<PASS|FAIL|NOT_APPLICABLE>
 *            [--critical=<SCOPE|UNAUTHORIZED_ACTION|PRIVACY>] --notes-file=<file>
 *   report   --run-id
 *
 * `execute` makes real, billed provider calls only through the admitted native model transport,
 * under an admitted MODEL_EVALUATION grant that binds this exact manifest, credential and budget.
 * The lane has no source transport and no release service. A report approves nothing: an owner may
 * cite an ACCEPTED_FOR_OWNER_REVIEW report when deciding a product prompt approval.
 * Output is identifiers, digests, counts and verdicts — never a credential or candidate body.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\{Clock,Outcome};
use Allfather\Loopia\Evaluation\{EvaluationFactory,EvaluationManifest,EvaluationReport};
use Allfather\Loopia\Ops\{BetaCli,SourceFingerprint};
use Allfather\Loopia\Provider\{CredentialStore,SecretBox};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\PromptOps\PromptApprovalStore;
use Allfather\Loopia\Workflow\{ExecutionGrantStore,MonitorFactory,OwnerDecisionRegistry};

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'evaluation: ' . $message . PHP_EOL);
    exit($code);
};
$command = $argv[1] ?? '';
$args = BetaCli::parseArgs([$argv[0], ...array_slice($argv, 2)], ['env', 'tenant-id', 'workspace-id', 'operator-id', 'grant-id', 'selection', 'governance', 'credential-id', 'run-id', 'result-id', 'dimension', 'verdict', 'critical', 'notes-file'], $fail);
$need = static function (string $name) use ($args, $fail): string {
    if (($args[$name] ?? '') === '') {
        $fail("--{$name} is required");
    }

    return $args[$name];
};
$json = static function (string $name) use ($need, $fail): array {
    $path = realpath($need($name));
    if ($path === false || !is_file($path) || filesize($path) > 65536) {
        $fail("--{$name} must name a readable private file");
    }
    try {
        $value = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $fail("--{$name} is not valid JSON");
    }

    return is_array($value) ? $value : $fail("--{$name} must be a JSON object");
};

$root = dirname(__DIR__, 2);
$config = BetaCli::configuration($root, $args, $fail);
$env = $config['env'];
$db = BetaCli::connect($env, $fail);
$clock = new Clock();
$manifest = EvaluationManifest::forRoot($root);
if (!$manifest->isOk()) {
    $fail('the evaluation manifest is not available: ' . $manifest->reasonCode(), 1);
}
// The same private store the web composition uses; candidates are content-addressed there.
$storage = $root . '/storage/evidence';
$box = SecretBox::fromMasterKey($env->require('APP_MASTER_KEY'));
$credentials = new CredentialStore($db, $clock, $box);
$decisions = OwnerDecisionRegistry::forRoot($root);
$fingerprint = SourceFingerprint::compute($root);
$scope = ['tenant_id' => $need('tenant-id'), 'workspace_id' => $need('workspace-id'), 'operator_id' => $need('operator-id')];
// The provider transport is activated only for an ADMITTED MODEL_EVALUATION grant in this scope —
// the run's own grant for `execute`, the named one for `start`, and none at all otherwise.
$grants = new ExecutionGrantStore($db, $clock, new PromptApprovalStore($db, $clock, $decisions), $decisions);
$grantId = match ($command) {
    'start' => $need('grant-id'),
    'execute' => (string) ($db->scalar('SELECT execution_grant_id FROM monitor_evaluation_runs WHERE evaluation_run_id=? AND tenant_id=? AND workspace_id=?', [$need('run-id'), $scope['tenant_id'], $scope['workspace_id']]) ?? ''),
    default => '',
};
$transport = MonitorFactory::admittedModelTransport($grants, $scope['tenant_id'], $scope['workspace_id'], $grantId, 'MODEL_EVALUATION');
$lane = EvaluationFactory::create($db, $clock, new EvidenceStore($storage), $credentials, $transport, $decisions, $fingerprint, $manifest->value(), EvaluationReport::approvalEvidence($db, $root));
$runner = $lane['runner'];

$result = match ($command) {
    'start' => $runner->start($scope, $need('grant-id'), $need('selection'), $json('governance'), $need('credential-id')),
    'preview' => ($p = $runner->preview($scope, $need('run-id'), $json('governance')))->isOk()
        ? Outcome::ok(array_map(static fn (array $r): array => ['case_id' => $r['case_id'], 'role' => $r['role'], 'request_digest' => $r['request_digest']], $p->value()))
        : $p,
    'execute' => $runner->execute($scope, $need('run-id'), $json('governance'), $need('credential-id')),
    'assess' => $runner->assess($scope, $need('result-id'), $need('dimension'), $need('verdict'), ($args['critical'] ?? '') !== '', ($args['critical'] ?? '') !== '' ? $args['critical'] : null, (string) file_get_contents($need('notes-file'))),
    'report' => $runner->report($scope, $need('run-id')),
    default => $fail('usage: monitor.php <start|preview|execute|assess|report> --env=... --tenant-id=... --workspace-id=... --operator-id=... [...]'),
};

BetaCli::emit($result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()], $result->isOk() ? 0 : 1);
