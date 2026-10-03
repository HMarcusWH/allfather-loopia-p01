<?php

declare(strict_types=1);

/**
 * Production preflight (completion plan P12). It VALIDATES and REPORTS; it cannot grant anything.
 *
 * Usage:
 *   php ops/deploy/production-preflight.php [--release-ref=<40-hex git SHA>] [--env=<private env>]
 *                                           [--root=<release tree; defaults to this one>]
 *
 * What it checks, all of them every time (an operator fixing a release wants the whole list):
 *
 *   IDENTITY_VOCABULARY     the release's own schema admits a production identity class. The pinned
 *                           canonical vocabulary is LOCAL_DEV / TEST / DEMO; this projection must
 *                           not invent CUSTOMER or PRODUCTION synonyms, so today this FAILS.
 *   CUSTOMER_AUDIENCE       the release's M16 storage lets a wider-than-internal audience be
 *                           activated. Upstream keeps every such audience designed-inert
 *                           (V1-GAP-093), so today this FAILS.
 *   AUTHORITY_LOCKS         upstream.lock.json's permanent authority locks. They are owner acts
 *                           recorded upstream; this command READS them and never edits the lock.
 *   HOSTING_ISOLATION       a PASS CROSS_SITE_ISOLATION record for a production host in
 *                           deployment-evidence.json. The staging deviation is not one.
 *   PRODUCTION_RELEASE      a PASS CONFORMANCE record on a production host for --release-ref.
 *   RECOVERY_READINESS      evidence of a production full restore and key rotation. Both evidence
 *                           kinds exist, but only reviewed production PASS records can satisfy it.
 *   RUNTIME_POSTURE         (with --env) APP_ENV=production. A strict posture, NOT a grant.
 *
 * Even if every check passed, the verdict would be READY_FOR_OWNER_DECISION and `grants_go` would be
 * false: only a separate recorded owner decision can authorize production launch or customer
 * visibility. Nothing here writes upstream.lock.json, deployment-evidence.json, docs/decisions or
 * the database — it opens no connection at all. Output is check names, statuses, reason codes and
 * non-secret facts; an env file's values are never printed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../app/autoload.php';

use Allfather\Loopia\Ops\BetaCli;
use Allfather\Loopia\Ops\DeploymentEvidence;
use Allfather\Loopia\Support\Env;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'production-preflight: ' . $message . PHP_EOL);
    exit($code);
};
$args = BetaCli::parseArgs($argv, ['root', 'release-ref', 'env'], $fail);
$root = realpath($args['root'] ?? dirname(__DIR__, 2));
if ($root === false || !is_dir($root . '/database/migrations')) {
    $fail('--root must name a release tree with database/migrations');
}
$releaseRef = $args['release-ref'] ?? null;
if ($releaseRef !== null && preg_match('/^[0-9a-f]{40}$/D', $releaseRef) !== 1) {
    $fail('--release-ref must be a 40-character lowercase git SHA');
}

$checks = [];
$check = static function (string $name, bool $pass, string $reason, array $facts = []) use (&$checks): void {
    $checks[] = ['check' => $name, 'status' => $pass ? 'PASS' : 'FAIL', 'reason_code' => $pass ? 'SATISFIED' : $reason] + ($facts === [] ? [] : ['facts' => $facts]);
};

// ---------------------------------------------------------------- the release's own schema
$schema = '';
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $file) {
    $schema .= (string) file_get_contents($file) . "\n";
}
/** Every literal admitted by `CHECK (<column> IN (...))` across the release's migrations. */
$vocabulary = static function (string $column) use ($schema): array {
    preg_match_all('/CHECK\s*\(\s*' . preg_quote($column, '/') . '\s+IN\s*\(([^)]*)\)\s*\)/i', $schema, $m);
    $values = [];
    foreach ($m[1] as $list) {
        preg_match_all("/'([A-Z_]+)'/", $list, $literals);
        $values = array_merge($values, $literals[1]);
    }
    $values = array_values(array_unique($values));
    sort($values);

    return $values;
};
$environments = $vocabulary('environment_class');
$tenantTypes = $vocabulary('tenant_type');
$check('IDENTITY_VOCABULARY', in_array('PRODUCTION', $environments, true),
    'PRODUCTION_IDENTITY_NOT_IN_CANONICAL_VOCABULARY',
    ['environment_classes' => $environments, 'tenant_types' => $tenantTypes]);

$activationGate = preg_match("/CHECK\s*\(\s*audience_class\s+IN\s*\(\s*'OPERATOR_ONLY'\s*,\s*'TENANT_INTERNAL'\s*\)\s+OR\s+activation_permitted\s*=\s*0\s*\)/i", $schema) === 1;
$check('CUSTOMER_AUDIENCE', !$activationGate, 'CUSTOMER_AUDIENCE_NOT_ADOPTED',
    ['activatable_audiences' => $activationGate ? ['OPERATOR_ONLY', 'TENANT_INTERNAL'] : 'NOT_RESTRICTED_BY_SCHEMA']);

// ---------------------------------------------------------------- authority, read never written
$lock = is_file($root . '/upstream.lock.json') ? json_decode((string) file_get_contents($root . '/upstream.lock.json'), true) : null;
if (!is_array($lock) || !is_array($lock['claim_boundary'] ?? null)) {
    $check('AUTHORITY_LOCKS', false, 'UPSTREAM_LOCK_UNREADABLE');
    $lock = ['claim_boundary' => [], 'projection_id' => '', 'projection_pinned_head' => ''];
} else {
    $locks = array_intersect_key($lock['claim_boundary'], array_flip(['production_launch_authorized', 'customer_visible_output_authorized', 'owner_go_granted', 'external_action_authorized']));
    $check('AUTHORITY_LOCKS', ($locks['production_launch_authorized'] ?? false) === true && ($locks['owner_go_granted'] ?? false) === true,
        'PRODUCTION_LAUNCH_NOT_AUTHORIZED', ['permanent_authority_locks' => $locks]);
}

// ---------------------------------------------------------------- host evidence
$registry = DeploymentEvidence::loadFile($root . '/deployment-evidence.json', [
    'projection_id' => (string) ($lock['projection_id'] ?? ''),
    'canonical_pin' => (string) ($lock['projection_pinned_head'] ?? ''),
]);
$records = $registry->isValid() ? $registry->records() : [];
$production = static fn (string $kind, ?string $ref = null): array => array_values(array_filter(
    $records,
    static fn (array $r): bool => $r['kind'] === $kind && $r['environment'] === 'production' && $r['result'] === 'PASS' && ($ref === null || $r['release_ref'] === $ref),
));
$latestIsolation = null;
foreach ($records as $r) {
    if ($r['kind'] === 'CROSS_SITE_ISOLATION' && ($latestIsolation === null || $r['recorded_at'] > $latestIsolation['recorded_at'])) {
        $latestIsolation = $r;
    }
}
if (!$registry->isValid()) {
    $check('HOSTING_ISOLATION', false, 'EVIDENCE_REGISTRY_INVALID', ['problems' => count($registry->problems())]);
} else {
    $check('HOSTING_ISOLATION', $production('CROSS_SITE_ISOLATION') !== [], 'HOSTING_ISOLATION_NOT_CLOSED',
        ['latest_isolation_record' => $latestIsolation === null ? null : ['record_id' => $latestIsolation['record_id'], 'environment' => $latestIsolation['environment'], 'result' => $latestIsolation['result']]]);
}
$check('PRODUCTION_RELEASE', $releaseRef !== null && $production('CONFORMANCE', $releaseRef) !== [],
    $releaseRef === null ? 'RELEASE_REF_REQUIRED' : 'PRODUCTION_RELEASE_NOT_QUALIFIED', ['release_ref' => $releaseRef]);
$recoveryKinds = array_values(array_intersect(['FULL_RESTORE_DRILL', 'KEY_ROTATION_DRILL'], DeploymentEvidence::KINDS));
$check('RECOVERY_READINESS', count($recoveryKinds) === 2 && $production('FULL_RESTORE_DRILL') !== [] && $production('KEY_ROTATION_DRILL') !== [],
    $recoveryKinds === [] ? 'RECOVERY_EVIDENCE_KIND_NOT_DEFINED' : 'RECOVERY_NOT_EVIDENCED_ON_PRODUCTION', ['evidence_kinds' => $recoveryKinds]);

// ---------------------------------------------------------------- runtime posture (optional)
if (isset($args['env'])) {
    if (!is_file($args['env'])) {
        $fail("no such configuration file: {$args['env']}");
    }
    $appEnv = Env::load($args['env'])->get('APP_ENV', '') ?? '';
    $check('RUNTIME_POSTURE', $appEnv === 'production', 'RUNTIME_NOT_PRODUCTION_POSTURE', ['app_env' => preg_match('/^[a-z]{1,16}$/D', $appEnv) === 1 ? $appEnv : 'UNRECOGNIZED']);
}

$failed = array_values(array_filter($checks, static fn (array $c): bool => $c['status'] !== 'PASS'));
BetaCli::emit([
    'command' => 'production-preflight',
    'verdict' => $failed === [] ? 'READY_FOR_OWNER_DECISION' : 'NOT_READY',
    'grants_go' => false,
    'customer_visibility_granted' => false,
    'failed' => array_column($failed, 'reason_code'),
    'checks' => $checks,
    'note' => 'Validation only. Production launch and customer visibility need a separate recorded owner decision; this command cannot create one.',
], $failed === [] ? 0 : 1);
