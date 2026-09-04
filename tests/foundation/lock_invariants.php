<?php

declare(strict_types=1);

/**
 * Foundation invariants over `upstream.lock.json`.
 *
 * These are the projection's own tripwires. They fail the build if the deployment truth is edited
 * into a claim nobody has granted — the one class of change no code review reliably catches,
 * because flipping a boolean in a JSON file looks harmless.
 */

use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
$lock = json_decode((string) file_get_contents($root . '/upstream.lock.json'), true, 512, JSON_THROW_ON_ERROR);

// --- identity and lineage -------------------------------------------------------------------

Assert::same('2.0', $lock['schema_version'] ?? null, 'lock schema version is 2.0');
Assert::same('LOOPIA_SME_MVP', $lock['projection_id'] ?? null, 'projection_id is the widened SME MVP id');
Assert::same(
    'LOOPIA_P01',
    $lock['historical_projection_id'] ?? null,
    'the LOOPIA_P01 lineage is preserved, not deleted',
);
Assert::same(
    'HMarcusWH/Allfatherbusinessintelligence',
    $lock['canonical_repository'] ?? null,
    'canonical repository identity unchanged',
);

// --- the upstream pin -----------------------------------------------------------------------

$pin = 'c6d9cf9a7a1001b001ca6118999e9cb75c831c4d';
Assert::same($pin, $lock['projection_pinned_head'] ?? null, 'the exact upstream SHA pin is kept');
Assert::same($pin, $lock['live_head_at_pin'] ?? null, 'live head at pin recorded');
Assert::same(false, $lock['head_resolution']['canonical_moved'] ?? null, 'canonical head movement recorded');
Assert::same(false, $lock['head_resolution']['projection_moved'] ?? null, 'projection head movement recorded');

// --- the action ceiling ---------------------------------------------------------------------

Assert::equalsList(['A', 'B', 'C'], $lock['action_classes'] ?? null, 'action ceiling remains exactly A/B/C');

// --- the claim boundary ---------------------------------------------------------------------
// Every one of these is an owner act recorded upstream. Finishing the port moves none of them.

$mustBeFalse = [
    'road_test_001_executed',
    'production_launch_authorized',
    'external_action_authorized',
    'live_read_activated',
    'owner_go_granted',
    'customer_visible_output_authorized',
    'staging_deployed',
    'conformance_complete',
    'loopia_capability_validated',
];
foreach ($mustBeFalse as $key) {
    Assert::same(false, $lock['claim_boundary'][$key] ?? true, "claim_boundary.{$key} must remain false");
}
Assert::same(
    true,
    $lock['claim_boundary']['no_external_charge_executed'] ?? false,
    'no_external_charge_executed must remain true',
);

// --- scope ------------------------------------------------------------------------------------

$modules = $lock['scope']['product_modules'] ?? [];
foreach (['P01', 'P02', 'P03', 'P04', 'P05', 'P07-A_SYNTHETIC_ONLY'] as $module) {
    Assert::true(in_array($module, $modules, true), "scope includes {$module}");
}
Assert::true(
    !in_array('P07-B', $modules, true) && !in_array('P07', $modules, true),
    'P07-B live send is NEVER in product scope',
);
Assert::true(
    isset($lock['scope']['excluded_with_authority']['P07-B']),
    'the P07-B exclusion carries its authority',
);
Assert::true(
    isset($lock['scope']['excluded_with_authority']['P02_OFFERING_TWINS']),
    'the P02 offering-twin exclusion carries its authority (V1-GAP-097)',
);

$substrate = $lock['scope']['substrate_dependencies'] ?? [];
foreach (['M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M10', 'M11', 'M12', 'M15', 'M16'] as $m) {
    Assert::true(in_array($m, $substrate, true), "substrate scope includes {$m}");
}

// --- gate authority is recorded, not assumed --------------------------------------------------

foreach (['P01', 'P02', 'P03', 'P04', 'P05', 'P07-A', 'M15', 'M16'] as $module) {
    Assert::true(
        isset($lock['upstream_gate_authority'][$module]['gate']),
        "an upstream gate is recorded for {$module}",
    );
}
Assert::same(
    'NOT_GRANTED',
    $lock['upstream_gate_authority']['P07-B']['gate'] ?? null,
    'P07-B is recorded as NOT_GRANTED upstream',
);
