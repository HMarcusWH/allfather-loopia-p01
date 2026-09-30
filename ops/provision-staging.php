<?php

declare(strict_types=1);

/**
 * Provision one tenant + workspace with the governed capabilities this projection deploys.
 *
 * Usage:
 *   php ops/provision-staging.php --tenant-key=acme --workspace-key=main \
 *       --display-name="Acme AB" --operator-name="Ops" [--env=path] [--modules=P01,P02,P03,P04,P05]
 *
 * OPS COMPOSITION, NOT DOCTRINE. Every governed decision below belongs to a Core module and is made
 * by calling that module's own registration function. This file chooses the ORDER and reports what
 * happened; it decides nothing. Concretely, and these are the rules that keep it Ops:
 *
 *   IT WRITES NO ARBITRARY SQL against governed tables. The only rows it inserts directly are the
 *   tenant, workspace and operator themselves — the scope the modules are registered INTO, which
 *   exists before any capability can be registered and which no module owns.
 *
 *   IT INVENTS NO POLICY. Policy sets, contracts, tool rights, tiers and visibility profiles all
 *   come from the module registrations verbatim. Nothing here supplies a permission, a ceiling or a
 *   forbidden clause of its own.
 *
 *   IT MERGES NO RUNTIME AUTHORITY. Five separate product registrations currently run in sequence;
 *   they do not become one registration. If another module is added later it gets its own
 *   registration rather than becoming a parameter of an existing authority object.
 *
 *   IT FAILS CLOSED. The first refusal stops the run and reports which module refused and why.
 *   A half-provisioned tenant is a real state, and the report says exactly how far it got rather
 *   than implying a rollback that did not happen.
 *
 *   IT IS IDEMPOTENT WHERE THE MODULES ARE. Tiers are platform rows created once; re-running
 *   converges on them. Valkyries and contracts are per-tenant and re-running mints new ones, which
 *   is why the default is to refuse a tenant key that already exists unless --allow-existing is
 *   given.
 *
 * IT PROVISIONS. It does not deploy, activate, or grant launch authority. `LIVE_READ_ACTIVATED`
 * stays false, no live transport is wired, and nothing here can change a claim-boundary flag.
 */

// CLI ONLY, refused BEFORE anything is loaded. Provisioning is an operator act with filesystem
// authority; it must never be reachable over HTTP, and living outside the document root is a
// property of a deployment's configuration rather than of this file.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../app/autoload.php';

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Ops\MaintenanceGate;
use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P01\Registration as P01Registration;
use Allfather\Loopia\Product\P02\Registration as P02Registration;
use Allfather\Loopia\Product\P03\Registration as P03Registration;
use Allfather\Loopia\Product\P04\Registration as P04Registration;
use Allfather\Loopia\Product\P05\Registration as P05Registration;
use Allfather\Loopia\Substrate\M02\Seed as M02Seed;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
use Allfather\Loopia\Support\Bootstrap;
use Allfather\Loopia\Support\Env;

/** @return array<string, string> */
$parseArgs = static function (array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) === 1) {
            $out[$m[1]] = $m[2] ?? '1';
        }
    }

    return $out;
};
$args = $parseArgs($argv);

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, 'provision: ' . $message . PHP_EOL);
    exit($code);
};

foreach (['tenant-key', 'workspace-key', 'display-name'] as $required) {
    if (($args[$required] ?? '') === '') {
        $fail("missing required argument --{$required}");
    }
}

// KNOWN ARGUMENTS ONLY. An unrecognised flag is a typo or a misunderstanding, and silently ignoring
// one is how a provisioning run does something other than what the operator asked for.
$known = ['tenant-key', 'workspace-key', 'display-name', 'operator-name', 'env', 'modules', 'allow-existing', 'dry-run'];
foreach (array_keys($args) as $given) {
    if (!in_array($given, $known, true)) {
        $fail("unknown argument --{$given}; known: " . implode(', ', $known));
    }
}

$root = dirname(__DIR__);
if (isset($args['env'])) {
    if (!is_file($args['env'])) {
        $fail("no such configuration file: {$args['env']}");
    }
    $env = Env::load($args['env']);
    $environment = Bootstrap::resolveEnvironment($env->get('APP_ENV'));
} else {
    try {
        $boot = Bootstrap::load($root);
    } catch (\RuntimeException $error) {
        $fail($error->getMessage());
    }
    $env = $boot['env'];
    $environment = $boot['environment'];
}

// PRODUCTION IDENTITY IS NOT OURS TO INVENT. The pinned canonical schema defines TEST/DEMO/
// LOCAL_DEV identity vocabulary but no CUSTOMER tenant/workspace types and no PRODUCTION
// environment_class. A deployment projection may adapt the host, not silently promote new Core
// identity law. Unknown APP_ENV values also resolve to production in Bootstrap, so a typo reaches
// this same fail-closed gate instead of weakening the posture.
if ($environment === 'production') {
    $fail(
        'production provisioning is not available at the pinned canonical head: '
        . 'the canonical identity vocabulary does not define CUSTOMER tenant/workspace types or a '
        . 'PRODUCTION environment_class. Provision staging/testing only; production requires an '
        . 'explicit canonical promotion first.',
        6
    );
}

// The MODULES to provision, from a closed set. P02 is provisionable now that its admission policy
// set and governed lifecycle are ported (state change -> admission -> M10 release -> surfacing);
// it registers the ENTITY-TWIN capability only. Offering twins remain out of scope and have no
// registration to reach. See docs/PORT_SCOPE_MATRIX.md §3.7.
$available = ['P01', 'P02', 'P03', 'P04', 'P05'];
$modules = isset($args['modules'])
    ? array_values(array_filter(array_map('trim', explode(',', $args['modules']))))
    : $available;
foreach ($modules as $module) {
    if (!in_array($module, $available, true)) {
        $fail("module {$module} is not provisionable; available: " . implode(', ', $available));
    }
}

fwrite(STDERR, sprintf(
    "provision: tenant=%s workspace=%s modules=%s (APP_ENV=%s)%s%s",
    $args['tenant-key'],
    $args['workspace-key'],
    implode(',', $modules),
    $environment,
    isset($args['dry-run']) ? ' DRY RUN' : '',
    PHP_EOL,
));

$maintenance = (new MaintenanceGate($root))->acquireRuntimeLease();
if (!$maintenance->isOk()) {
    $fail('database access refused by maintenance gate: ' . $maintenance->reasonCode(), 4);
}
$maintenanceLease = $maintenance->value()['lease'];

try {
    $db = Database::connect(
        $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
        $env->int('DB_PORT', 3306),
        $env->require('DB_NAME'),
        $env->require('DB_USER'),
        $env->get('DB_PASSWORD', '') ?? '',
        $env->get('DB_SOCKET'),
    );
} catch (\Throwable $error) {
    $fail('database unreachable: ' . $error->getMessage(), 3);
}

$clock = new Clock();
$existingTenant = $db->one('SELECT tenant_id FROM tenants WHERE tenant_key = ?', [$args['tenant-key']]);
if ($existingTenant !== null && !isset($args['allow-existing'])) {
    $fail(
        "tenant key '{$args['tenant-key']}' already exists. Re-running mints NEW per-tenant "
        . 'Valkyries and contracts; pass --allow-existing only if that is what you intend.',
        4
    );
}
if (isset($args['dry-run'])) {
    fwrite(STDERR, 'provision: dry run — arguments and configuration validated, nothing written' . PHP_EOL);
    echo json_encode(['status' => 'DRY_RUN_OK', 'modules' => $modules], JSON_THROW_ON_ERROR), PHP_EOL;

    return;
}

// --- the scope the capabilities are registered INTO ------------------------------------------------
// The only rows this file writes directly. A tenant is not a governed object of any module — it is
// the boundary every module's authority is scoped by, and it has to exist first.
$now = $clock->now();
$tenantId = $existingTenant === null ? Ids::new() : (string) $existingTenant['tenant_id'];
if ($existingTenant === null) {
    $db->insert('tenants', [
        'tenant_id' => $tenantId,
        'tenant_key' => $args['tenant-key'],
        'schema_version' => '1',
        'display_name' => $args['display-name'],
        // Staging/testing deliberately use canonical TEST identity vocabulary. This projection
        // does not invent CUSTOMER; production provisioning is refused above until canonical
        // promotes an appropriate customer identity vocabulary.
        'tenant_type' => 'TEST',
        'status' => 'ACTIVE',
        'created_at' => $now,
    ]);
}

$operatorId = Ids::new();
$db->insert('operators', [
    'operator_id' => $operatorId,
    'tenant_id' => $tenantId,
    'schema_version' => '1',
    'display_name' => $args['operator-name'] ?? 'Provisioning operator',
    'status' => 'ACTIVE',
    'created_at' => $now,
]);

$workspaceId = Ids::new();
$db->insert('workspaces', [
    'workspace_id' => $workspaceId,
    'tenant_id' => $tenantId,
    'workspace_key' => $args['workspace-key'],
    'schema_version' => '1',
    'display_name' => $args['display-name'],
    // Same rule for the workspace: staging/testing exercise the selected graph under canonical TEST
    // vocabulary. CUSTOMER / PRODUCTION are not projection-owned synonyms for "real enough".
    'workspace_type' => 'TEST',
    'environment_class' => 'TEST',
    // CHECK-pinned to 0 in storage. Written explicitly because it is the claim this row makes.
    'external_side_effects_allowed' => 0,
    'active_v1_capability_ceiling' => 'RELEASE_INTELLIGENCE',
    'status' => 'ACTIVE',
    'created_at' => $now,
    'created_by_operator_id' => $operatorId,
]);

// --- the platform authority vocabulary ---------------------------------------------------------------
// Permissions and roles are GLOBAL, not tenant-owned: they are the shared vocabulary every
// membership resolves against. `M02\Seed` is where that vocabulary is defined, ported verbatim from
// canonical, idempotent, and structurally unable to create an excluded permission.
//
// It was written and never wired, which is why a freshly provisioned deployment had no roles at all
// and an operator had only two ways to grant one: write SQL, or invent a role. Both are exactly what
// this projection is built to make unnecessary. Running the canonical seeder here creates no
// authority — a role is inert until a membership grants it, and the set is canonical rather than
// ours — it just materialises the vocabulary the registrations and the onboarding command both
// assume already exists.
$vocabulary = (new M02Seed($db, $clock))->ensureVocabulary();
fwrite(STDERR, sprintf(
    'provision: authority vocabulary — %d permissions and %d roles created (existing ones reused)%s',
    $vocabulary['permissions'],
    $vocabulary['roles'],
    PHP_EOL,
));

// --- the module registrations, each making its own decisions ----------------------------------------
$m03 = new M03Registry($db, $clock);
$eligibility = new Eligibility($db, $clock);
$tools = new ToolRegistry($db, $clock);
$policySets = new PolicySets($db, $clock);
$entitlements = new Entitlements($db, $clock);
$m16 = new ReleaseOutput($db, $clock, new DecisionLedger($db, $clock));

$scope = ['tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'operator_id' => $operatorId];
$registrars = [
    'P01' => static fn (): mixed => (new P01Registration(
        $db, $clock, $m03, $eligibility, $tools, $policySets, $m16, $entitlements
    ))->registerCompetitionMonitor($scope),
    'P02' => static fn (): mixed => (new P02Registration(
        $db, $clock, $m03, $eligibility, $tools, $policySets, $entitlements
    ))->registerEntityTwinCurator($scope),
    'P03' => static fn (): mixed => (new P03Registration(
        $db, $clock, $m03, $eligibility, $tools, $policySets, $entitlements
    ))->registerLeadQualification($scope),
    'P04' => static fn (): mixed => (new P04Registration(
        $db, $clock, $m03, $eligibility, $tools, $policySets, $entitlements
    ))->registerIntelligenceDistribution($scope),
    'P05' => static fn (): mixed => (new P05Registration(
        $db, $clock, $m03, $eligibility, $tools, $policySets, $entitlements
    ))->registerMarketingCustomization($scope),
];

$results = [];
foreach ($modules as $module) {
    $outcome = $registrars[$module]();
    if ($outcome->isRefusal()) {
        // FAIL CLOSED, and say how far it got. A half-provisioned tenant is a real state, and
        // implying a rollback that did not happen would be worse than reporting the truth.
        fwrite(STDERR, sprintf(
            'provision: %s REFUSED (%s). Provisioned so far: %s. Tenant %s exists and is NOT complete.%s',
            $module,
            $outcome->reasonCode(),
            $results === [] ? 'nothing' : implode(', ', array_keys($results)),
            $args['tenant-key'],
            PHP_EOL,
        ));
        echo json_encode([
            'status' => 'REFUSED',
            'module' => $module,
            'reason_code' => $outcome->reasonCode(),
            'provisioned' => array_keys($results),
        ], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(5);
    }
    $results[$module] = $outcome->value();
}

// SANITIZED. Identifiers an operator needs to wire the next step, and nothing that is a credential.
echo json_encode([
    'status' => 'PROVISIONED',
    'environment' => $environment,
    'tenant_id' => $tenantId,
    'workspace_id' => $workspaceId,
    'operator_id' => $operatorId,
    'authority_vocabulary' => [
        'permissions_created' => $vocabulary['permissions'],
        'roles_created' => $vocabulary['roles'],
        'role_keys' => array_keys(\Allfather\Loopia\Substrate\M02\Permissions::ROLES),
    ],
    'modules' => array_map(
        static fn (array $r): array => [
            'valkyrie_id' => $r['valkyrie_id'] ?? null,
            'capability_contract_id' => $r['capability_contract_id'] ?? null,
            'governed_tool_id' => $r['governed_tool_id'] ?? null,
            'arbiter_policy_set_id' => $r['arbiter_policy_set_id'] ?? null,
        ],
        $results
    ),
    // Restated on every successful run. Provisioning establishes capability, never authority to use
    // it against the outside world.
    'live_read_activated' => false,
    'external_action_authorized' => false,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
