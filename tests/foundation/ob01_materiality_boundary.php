<?php

declare(strict_types=1);

use Allfather\Loopia\PromptOps\{ObservationMaterialityV2,PromptBundleRegistry,ProductPromptPack};
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
Assert::same('ob01.observation-composition.synthetic.v1', ObservationMaterialityV2::VERSION,
    'OB01 exact composition version');
$paths = [
    'app/PromptOps/ObservationMaterialityV2.php',
    'app/PromptOps/ObservationCandidateChecksV2.php',
];
foreach ($paths as $path) {
    $source = (string) file_get_contents($root . '/' . $path);
    foreach (['curl_exec(', 'CurlHttpsTransport', 'OpenAIResponsesTransport',
        'new \\PDO(', 'new ModelExecutor(', 'mail(', 'shell_exec(', 'proc_open('] as $forbidden) {
        Assert::false(str_contains($source, $forbidden),
            'candidate composition cannot reach source/provider/DB/side effects: ' . $path . ':' . $forbidden);
    }
}
$selection = (string) file_get_contents($root . '/app/PromptOps/PromptSelectionResolver.php');
Assert::contains("PROMPT_OBSERVATION_V2_U01_NOT_ADOPTED", $selection,
    'versioned resolver has independent hard U01 refusal before v1 fallback');
$prepared = (string) file_get_contents($root . '/app/Provider/PreparedModelRequest.php');
Assert::contains('MODEL_PROPOSED_OBSERVATION_V2_INERT', $prepared,
    'live provider request remains separate from offline v2 composition');
$monitor = (string) file_get_contents($root . '/app/Workflow/MonitorService.php');
Assert::contains('self::ROLES', $monitor, 'native MonitorService stays on existing role map');
Assert::false(str_contains($monitor, 'ObservationMaterialityV2'),
    'native Monitor runtime cannot auto-wire unadopted synthetic composer');
$approval = (string) file_get_contents($root . '/app/PromptOps/PromptApprovalStore.php');
Assert::contains('ProductPromptPack::resolve($role)', $approval, 'native prompt approval remains v1 only');
$cap = json_decode((string) file_get_contents(
    $root . '/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'), true, 64, JSON_THROW_ON_ERROR);
$rows = array_column($cap['build_packages'], null, 'id');
Assert::same('BUILT_INERT', $rows['OB-01']['build_status'],
    'OB01 is candidate-built, not adopted or live');
foreach (['native_permission','customer_visibility','production_activation','live_migration','external_effects'] as $field) {
    Assert::same(false, $rows['OB-01'][$field], 'OB01 cannot mint authority: ' . $field);
}
$decision = json_decode((string) file_get_contents(
    $root . '/docs/longitudinal/BUILD_FIRST_DECISION_MATRIX.json'), true, 64, JSON_THROW_ON_ERROR);
$byId = array_column($decision['decisions'], null, 'id');
foreach (['U01','U06'] as $id) {
    Assert::same(false, $byId[$id]['php_adopted'], $id . ' owner still unratified');
    Assert::same(false, $byId[$id]['execution_authorized'], $id . ' cannot authorize provider');
}
Assert::same('2026-09-27.monitor.v1', ProductPromptPack::PACK_VERSION, 'legacy exact pack stays active only under normal grants');
Assert::same('2026-10-25.monitor.observation.v2', PromptBundleRegistry::OBSERVATION_V2_PACK,
    'versioned v2 content remains proposal');
$protocol = (string) file_get_contents($root . '/docs/OBSERVATION_MATERIALITY_CONTRACT.md');
foreach (['U01', 'U06', 'BUILT_INERT', 'no paid', 'NO_MATERIAL_CHANGE'] as $needle) {
    Assert::contains($needle, $protocol, 'OB01 ADR preserves boundary: ' . $needle);
}
