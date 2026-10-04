<?php

declare(strict_types=1);

/**
 * P12 validation only. This command reports prerequisites; it cannot grant anything.
 *
 * Usage:
 *   php ops/deploy/production-preflight.php --release-ref=<40-hex SHA> --target=<lowercase host>
 *       [--env=<private env>] [--root=<release tree; defaults to this one>]
 *
 * Host evidence is selected for ONE production target and ONE exact release. For each kind,
 * the latest observation on that target controls, including failures and stand-downs. A latest
 * observation for another release cannot qualify this one. Ambiguous timestamps fail closed.
 * Registry records are reviewed pointers, not independent verification of their payload bytes.
 *
 * The current canonical schema permits only internal audience activation. Missing/unrecognized
 * schema is UNKNOWN, not positive customer-audience adoption. No positive production contract is
 * invented here. Even complete host evidence grants neither GO nor customer visibility.
 *
 * No database connection or writes to the lock, evidence registry or owner decisions are made.
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
$args = BetaCli::parseArgs($argv, ['root', 'release-ref', 'target', 'env'], $fail);
$root = realpath($args['root'] ?? dirname(__DIR__, 2));
if ($root === false || !is_dir($root . '/database/migrations')) {
    $fail('--root must name a release tree with database/migrations');
}
$releaseRef = $args['release-ref'] ?? null;
if ($releaseRef !== null && preg_match('/^[0-9a-f]{40}$/D', $releaseRef) !== 1) {
    $fail('--release-ref must be a 40-character lowercase git SHA');
}
$target = $args['target'] ?? null;
if ($target !== null && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $target) !== 1) {
    $fail('--target must be an exact lowercase hostname, without scheme, port, credentials or path');
}

$checks = [];
$check = static function (string $name, bool $pass, string $reason, array $facts = []) use (&$checks): void {
    $checks[] = ['check' => $name, 'status' => $pass ? 'PASS' : 'FAIL', 'reason_code' => $pass ? 'SATISFIED' : $reason] + ($facts === [] ? [] : ['facts' => $facts]);
};

// A textual inventory can recognize the current restrictive contract; absence is not adoption.
$schema = '';
$schemaReadable = true;
$migrationFiles = glob($root . '/database/migrations/*.sql') ?: [];
foreach ($migrationFiles as $file) {
    $bytes = @file_get_contents($file);
    if ($bytes === false) {
        $schemaReadable = false;
    } else {
        $schema .= $bytes . "\n";
    }
}
/** Literals in this release's CHECK (<column> IN (...)) declarations, for diagnostics only. */
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
$check('IDENTITY_VOCABULARY', $schemaReadable && in_array('PRODUCTION', $environments, true),
    'PRODUCTION_IDENTITY_NOT_IN_CANONICAL_VOCABULARY',
    ['environment_classes' => $environments, 'tenant_types' => $tenantTypes]);

$activationGate = $schemaReadable && preg_match("/CHECK\s*\(\s*audience_class\s+IN\s*\(\s*'OPERATOR_ONLY'\s*,\s*'TENANT_INTERNAL'\s*\)\s+OR\s+activation_permitted\s*=\s*0\s*\)/i", $schema) === 1;
// No positive canonical customer-audience contract exists in this projection. Removing a CHECK,
// substituting an unknown schema, or editing a lock is not evidence that such a contract was adopted.
$check('CUSTOMER_AUDIENCE', false,
    $activationGate ? 'CUSTOMER_AUDIENCE_NOT_ADOPTED' : 'CUSTOMER_AUDIENCE_SCHEMA_UNRECOGNIZED',
    ['activatable_audiences' => $activationGate ? ['OPERATOR_ONLY', 'TENANT_INTERNAL'] : 'UNKNOWN']);

// Authority is read, never manufactured from observations.
$lock = is_file($root . '/upstream.lock.json') ? json_decode((string) file_get_contents($root . '/upstream.lock.json'), true) : null;
if (!is_array($lock) || !is_array($lock['claim_boundary'] ?? null)) {
    $check('AUTHORITY_LOCKS', false, 'UPSTREAM_LOCK_UNREADABLE');
    $lock = ['claim_boundary' => [], 'projection_id' => '', 'projection_pinned_head' => ''];
} else {
    $locks = array_intersect_key($lock['claim_boundary'], array_flip(['production_launch_authorized', 'customer_visible_output_authorized', 'owner_go_granted', 'external_action_authorized']));
    $check('AUTHORITY_LOCKS', ($locks['production_launch_authorized'] ?? false) === true && ($locks['owner_go_granted'] ?? false) === true,
        'PRODUCTION_LAUNCH_NOT_AUTHORIZED', ['permanent_authority_locks' => $locks]);
}

$registry = DeploymentEvidence::loadFile($root . '/deployment-evidence.json', [
    'projection_id' => (string) ($lock['projection_id'] ?? ''),
    'canonical_pin' => (string) ($lock['projection_pinned_head'] ?? ''),
]);
$records = $registry->isValid() ? $registry->records() : [];

/**
 * Select the latest observation before asking whether it passed. Filtering PASS first would revive
 * superseded evidence. Compare actual instants, not ISO spelling; tied latest records refuse even
 * if their array order or IDs differ. Records on another target/environment have no authority here.
 */
$production = static function (string $kind) use ($registry, $records, $target, $releaseRef): array {
    $refuse = static fn (string $reason, array $facts = []): array => ['pass' => false, 'reason_code' => $reason] + $facts;
    if (!$registry->isValid()) {
        return $refuse('EVIDENCE_REGISTRY_INVALID');
    }
    if ($target === null) {
        return $refuse('TARGET_REQUIRED');
    }
    if ($releaseRef === null) {
        return $refuse('RELEASE_REF_REQUIRED');
    }
    $latest = null;
    $latestAt = null;
    $tied = false;
    foreach ($records as $record) {
        if ($record['kind'] !== $kind || $record['environment'] !== 'production' || ($record['target'] ?? null) !== $target) {
            continue;
        }
        try {
            $at = new \DateTimeImmutable($record['recorded_at']);
        } catch (\Exception) {
            return $refuse('EVIDENCE_TIMESTAMP_INVALID');
        }
        if ($latestAt === null || $at > $latestAt) {
            $latestAt = $at;
            $latest = $record;
            $tied = false;
        } elseif ($at == $latestAt) {
            $tied = true;
        }
    }
    if ($latest === null) {
        return $refuse('NO_PRODUCTION_OBSERVATION_FOR_TARGET');
    }
    if ($tied) {
        return $refuse('AMBIGUOUS_LATEST_OBSERVATION');
    }
    $facts = ['record_id' => $latest['record_id'], 'result' => $latest['result'], 'release_ref' => $latest['release_ref'], 'target' => $target];
    if ($latest['result'] !== 'PASS') {
        return $refuse('LATEST_OBSERVATION_NOT_PASS', $facts);
    }
    if ($latest['release_ref'] !== $releaseRef) {
        return $refuse('LATEST_OBSERVATION_RELEASE_MISMATCH', $facts);
    }

    return ['pass' => true, 'reason_code' => 'SATISFIED'] + $facts;
};
$isolation = $production('CROSS_SITE_ISOLATION');
$conformance = $production('CONFORMANCE');
$restore = $production('FULL_RESTORE_DRILL');
$rotation = $production('KEY_ROTATION_DRILL');
$check('HOSTING_ISOLATION', $isolation['pass'],
    $registry->isValid() ? 'HOSTING_ISOLATION_NOT_CLOSED' : 'EVIDENCE_REGISTRY_INVALID',
    ['target' => $target, 'release_ref' => $releaseRef, 'observation' => $isolation]);
$check('PRODUCTION_RELEASE', $conformance['pass'],
    $releaseRef === null ? 'RELEASE_REF_REQUIRED' : 'PRODUCTION_RELEASE_NOT_QUALIFIED',
    ['target' => $target, 'release_ref' => $releaseRef, 'observation' => $conformance]);
$recoveryKinds = array_values(array_intersect(['FULL_RESTORE_DRILL', 'KEY_ROTATION_DRILL'], DeploymentEvidence::KINDS));
$check('RECOVERY_READINESS', count($recoveryKinds) === 2 && $restore['pass'] && $rotation['pass'],
    count($recoveryKinds) !== 2 ? 'RECOVERY_EVIDENCE_KIND_NOT_DEFINED' : 'RECOVERY_NOT_EVIDENCED_ON_PRODUCTION',
    ['target' => $target, 'release_ref' => $releaseRef, 'evidence_kinds' => $recoveryKinds, 'restore' => $restore, 'rotation' => $rotation]);

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
    'note' => 'Validation of reviewed registry pointers only, not independent payload verification. Production launch and customer visibility need separate recorded owner decisions; this command cannot create them.',
], $failed === [] ? 0 : 1);
