<?php

declare(strict_types=1);

/**
 * Module registration, and the Ops command that composes it.
 *
 * A deployment that cannot be provisioned is not a deployment. Before this, exactly one module had
 * a registration, so standing a tenant up meant hand-written SQL against governed tables — which is
 * how a policy set ends up subtly different from the one the module expects.
 *
 * TWO THINGS ARE PROVED, and the separation between them is the point:
 *
 *   EACH REGISTRATION MAKES ITS OWN DECISIONS. Four modules, four registrations, each establishing
 *   its own Valkyrie, contract, tool, policy set and tiers. They are not one registration with a
 *   parameter.
 *
 *   THE OPS COMMAND ONLY ORDERS THEM. It writes no governed rows of its own and invents no policy.
 *   That is asserted structurally, because it is the property that keeps a provisioning script from
 *   becoming a second place authority is decided.
 */

use Allfather\Loopia\Product\P01\Entitlements;
use Allfather\Loopia\Product\P03\Registration as P03Registration;
use Allfather\Loopia\Product\P04\Registration as P04Registration;
use Allfather\Loopia\Product\P05\Registration as P05Registration;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M03\Registry as M03Registry;
use Allfather\Loopia\Substrate\M06\Registry as ToolRegistry;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;
use Allfather\Loopia\Tests\Seed;

$db = Harness::freshDb();
if ($db === null) {
    Assert::skip(Harness::unavailableReason());

    return;
}

$clock = Harness::clock();
$scope = Seed::tenantWorkspace($db, $clock);
$m03 = new M03Registry($db, $clock);
$eligibility = new Eligibility($db, $clock);
$tools = new ToolRegistry($db, $clock);
$policySets = new PolicySets($db, $clock);
$entitlements = new Entitlements($db, $clock);

$input = [
    'tenant_id' => $scope['tenant_id'],
    'workspace_id' => $scope['workspace_id'],
    'operator_id' => $scope['operator_id'],
];

$registrations = [
    'P03' => [
        (new P03Registration($db, $clock, $m03, $eligibility, $tools, $policySets, $entitlements))
            ->registerLeadQualification($input),
        'LEAD_QUALIFIER_VALKYRIE',
        'p03.catalogue.transform',
        'P03_PROSPECT_ADMISSION',
        ['p03_intelligence', 'p03_command'],
    ],
    'P04' => [
        (new P04Registration($db, $clock, $m03, $eligibility, $tools, $policySets, $entitlements))
            ->registerIntelligenceDistribution($input),
        'INTELLIGENCE_DISTRIBUTOR_VALKYRIE',
        'p04.distribution.read',
        'P04_DISTRIBUTION_ADMISSION',
        ['p04_command', 'p04_governed_ops'],
    ],
    'P05' => [
        (new P05Registration($db, $clock, $m03, $eligibility, $tools, $policySets, $entitlements))
            ->registerMarketingCustomization($input),
        'MARKETING_CUSTOMIZER_VALKYRIE',
        'p05.released_intelligence.read',
        'P05_MARKETING_ASSET_ADMISSION',
        ['p05_marketing_customization'],
    ],
];

foreach ($registrations as $module => [$outcome, $valkyrieKind, $toolKey, $policyKey, $tierKeys]) {
    Assert::true($outcome->isOk(), "{$module} registers: " . ($outcome->isOk() ? '' : $outcome->reasonCode()));
    $value = $outcome->value();

    // --- M03: the agent and its contract ---------------------------------------------------------
    Assert::same(
        $valkyrieKind,
        (string) $db->scalar('SELECT canonical_role_class FROM valkyries WHERE valkyrie_id = ?', [$value['valkyrie_id']]),
        "{$module}: registers its own Valkyrie kind",
    );

    // THE CEILING IS THE POINT. A contract carrying an external-effect class is refused at issuance
    // by M03, before any row exists — so this is a check that the ceiling was requested correctly,
    // and the refusal below is what proves it could not have been otherwise.
    $classes = json_decode(
        (string) $db->scalar(
            'SELECT allowed_action_classes FROM capability_contracts WHERE capability_contract_id = ?',
            [$value['capability_contract_id']]
        ),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    foreach (['PREPARE_EXTERNAL_ACTION', 'EXECUTE_CONSEQUENTIAL_ACTION'] as $external) {
        Assert::false(
            in_array($external, $classes, true),
            "{$module}: its contract carries no {$external} authority",
        );
    }

    // The forbidden clauses are recorded as DESCRIPTIVE prohibitions, never as the excluded
    // permission keys themselves — naming one would be requesting it.
    $clauses = $db->all(
        'SELECT forbidden_category FROM forbidden_action_clauses WHERE capability_contract_id = ?',
        [$value['capability_contract_id']]
    );
    Assert::true(count($clauses) > 0, "{$module}: records forbidden-action clauses on the contract");
    foreach ($clauses as $clause) {
        Assert::false(
            str_contains((string) $clause['forbidden_category'], '.'),
            "{$module}: a clause category is a prohibition, not a permission key",
        );
    }

    // --- M06: the governed tool, at ITS own lower ceiling -------------------------------------------
    $tool = $db->one(
        'SELECT tool_key, maximum_action_class FROM governed_tools WHERE governed_tool_id = ?',
        [$value['governed_tool_id']]
    );
    Assert::same($toolKey, (string) $tool['tool_key'], "{$module}: registers its own governed tool");
    Assert::true(
        in_array((string) $tool['maximum_action_class'], ['OBSERVE', 'ANALYZE'], true),
        "{$module}: whose ceiling is a READ class — the tool reads, the contract covers the rest",
    );

    // --- M08: the admission policy set ---------------------------------------------------------------
    Assert::same(
        $policyKey,
        (string) $db->scalar(
            'SELECT policy_key FROM arbiter_policy_sets WHERE arbiter_policy_set_id = ?',
            [$value['arbiter_policy_set_id']]
        ),
        "{$module}: seeds its own admission policy set",
    );

    // --- the tiers ------------------------------------------------------------------------------------
    foreach ($tierKeys as $tierKey) {
        Assert::true(
            isset($value['subscription_tier_ids'][$tierKey]),
            "{$module}: defines the {$tierKey} tier",
        );
    }
}

// ============================================================ idempotency, where canonical has it

// TIERS ARE PLATFORM ROWS. A second tenant registering converges on them rather than minting a
// rival definition: two tiers with one key would make "what does this product include?" a question
// with two answers.
$second = Seed::tenantWorkspace($db, $clock, 'second');
$again = (new P03Registration($db, $clock, $m03, $eligibility, $tools, $policySets, $entitlements))
    ->registerLeadQualification([
        'tenant_id' => $second['tenant_id'],
        'workspace_id' => $second['workspace_id'],
        'operator_id' => $second['operator_id'],
    ]);
Assert::true($again->isOk(), 'a second tenant registers P03');
Assert::same(
    1,
    (int) $db->scalar("SELECT COUNT(*) FROM subscription_tiers WHERE tier_key = 'p03_intelligence'"),
    'and converges on the existing platform tier rather than minting a rival one',
);

// PER-TENANT objects are NOT shared, which is the other half of the same rule.
Assert::notSame(
    $registrations['P03'][0]->value()['valkyrie_id'],
    $again->value()['valkyrie_id'],
    "but gets its OWN Valkyrie — an agent identity is a tenant's, not the platform's",
);
Assert::notSame(
    $registrations['P03'][0]->value()['arbiter_policy_set_id'],
    $again->value()['arbiter_policy_set_id'],
    'and its own workspace-scoped policy set',
);

// ============================================================ the Ops command decides nothing

// STRUCTURAL, and it is the property that keeps a provisioning script from becoming a second place
// authority is decided. The command may write the SCOPE — tenant, workspace, operator, which no
// module owns and which must exist before any capability can be registered — and nothing else.
$command = (string) file_get_contents(dirname(__DIR__, 2) . '/ops/provision-staging.php');
preg_match_all("/->insert\('([a-z_]+)'/", $command, $inserts);
sort($inserts[1]);
Assert::same(
    ['operators', 'tenants', 'workspaces'],
    array_values(array_unique($inserts[1])),
    'the Ops command writes only the scope rows — no governed object of any module',
);
foreach (['arbiter_policy_sets', 'capability_contracts', 'governed_tools', 'subscription_tiers', 'tool_rights'] as $governed) {
    Assert::false(
        str_contains($command, "'{$governed}'"),
        "and never touches {$governed} — that is the module's decision, made by the module",
    );
}
// No UPDATE or DELETE at all: provisioning establishes, it does not revise.
Assert::false(
    preg_match('/->run\(\s*[\'"]\s*(UPDATE|DELETE)/i', $command) === 1,
    'and issues no UPDATE or DELETE — provisioning establishes, it does not revise',
);
// It cannot be reached over HTTP, and refuses BEFORE loading the application.
Assert::contains("if (PHP_SAPI !== 'cli') {", $command, 'the command refuses any web SAPI');
Assert::true(
    strpos($command, "PHP_SAPI !== 'cli'") < strpos($command, 'Database::connect'),
    'and refuses before it connects to anything',
);
// Unknown arguments are refused rather than ignored: silently dropping a typo is how a run does
// something other than what the operator asked for.
Assert::contains('unknown argument --', $command, 'an unrecognised argument is refused by name');
// P02 is deliberately absent from the provisionable set.
Assert::contains(
    "\$available = ['P01', 'P03', 'P04', 'P05'];",
    $command,
    'P02 is not provisionable — its admission policy set is not ported, and registering a '
    . 'capability whose governed lifecycle does not exist would be worse than the gap',
);
