<?php
declare(strict_types=1);

/**
 * Private CLI: import an owner-provided company profile as TENANT_PROVIDED evidence.
 *
 * Usage: php ops/monitor/import-profile.php --env=<private env> --tenant-id --workspace-id --operator-id
 *            --profile=<private UTF-8 text file, at most 16 KiB>
 *
 * The exact bytes are stored content-addressed and their SHA-256 becomes the evidence identity. A
 * new profile is new evidence: it changes no existing configuration or historical request, and a
 * mission uses it only through an explicit successor setup that names its evidence id. The platform
 * does not verify the profile's claims; they stay labelled TENANT_PROVIDED. The body is never echoed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Workflow\MonitorSetup;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'import-profile: ' . $message . PHP_EOL);
    exit($code);
};
$args = BetaCli::parseArgs($argv, ['env', 'tenant-id', 'workspace-id', 'operator-id', 'profile'], $fail);
foreach (['tenant-id', 'workspace-id', 'operator-id', 'profile'] as $name) {
    if (($args[$name] ?? '') === '' || ($args[$name] ?? '') === '1') {
        $fail("--{$name} is required");
    }
}
$root = dirname(__DIR__, 2);
$path = realpath($args['profile']);
$public = realpath($root . '/public_html');
if ($path === false || !is_file($path) || filesize($path) > MonitorSetup::MAX_PROFILE_BYTES || ($public !== false && str_starts_with($path, $public . DIRECTORY_SEPARATOR))) {
    $fail('--profile must name a readable private file outside public_html, at most ' . MonitorSetup::MAX_PROFILE_BYTES . ' bytes');
}
$config = BetaCli::configuration($root, $args, $fail);
$db = BetaCli::connect($config['env'], $fail);
$result = (new MonitorSetup($db, new Clock(), new EvidenceStore($root . '/storage/evidence')))
    ->importProfile(['tenant_id' => $args['tenant-id'], 'workspace_id' => $args['workspace-id'], 'operator_id' => $args['operator-id']], (string) file_get_contents($path));

BetaCli::emit($result->isOk() ? ['ok' => true, 'result' => $result->value()] : ['ok' => false, 'reason_code' => $result->reasonCode()], $result->isOk() ? 0 : 1);
