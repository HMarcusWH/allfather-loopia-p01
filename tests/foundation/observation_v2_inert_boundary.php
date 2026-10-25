<?php
declare(strict_types=1);
use Allfather\Loopia\PromptOps\PromptBundleRegistry;
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$path=$root.'/app/PromptOps/bundles/monitor-observation-v2.json';
$bundle=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
Assert::same(PromptBundleRegistry::OBSERVATION_V2_PACK,$bundle['pack_version'],'v2 bundle exact identity');
Assert::same('INACTIVE',$bundle['activation_status'],'v2 is never auto-activated');
Assert::same('NOT_EVALUATED',$bundle['evaluation_status'],'new model quality unmeasured');
Assert::same(false,$bundle['model_evaluated'],'no fabricated model evaluation');
Assert::same(1,count($bundle['prompts']),'one exact new prompt, not a shadow critic');
Assert::same('P01_SIGNAL_CLASSIFIER',$bundle['prompts'][0]['role'],'P01 owner only');
Assert::same('PROPOSED_NOT_IMPLEMENTED',
    json_decode((string)file_get_contents($root.'/tests/fixtures/monitor_observation_v2_eval_cases.json'),true,64,JSON_THROW_ON_ERROR)['status'],
    'canonical proposed cases are not executed outcomes');
Assert::same('2026-09-27.monitor.v1',ProductPromptPack::PACK_VERSION,'existing product execution version is pinned');
$approval=(string)file_get_contents($root.'/app/PromptOps/PromptApprovalStore.php');
$run=(string)file_get_contents($root.'/app/Workflow/MonitorService.php');
$prepared=(string)file_get_contents($root.'/app/Provider/PreparedModelRequest.php');
Assert::contains('ProductPromptPack::resolve($role)', $approval,'approval store still selects ONLY v1 content');
Assert::contains('self::ROLES', $run,'Monitor run stays under existing role map');
Assert::contains('MODEL_PROPOSED_OBSERVATION_V2_INERT',$prepared,'provider request rejects proposed v2');
Assert::false(str_contains((string)file_get_contents($root.'/app/PromptOps/bundles/2026-09-27.monitor.v1.json'),'PROPOSED_U01_NOT_RATIFIED'),'old bundle bytes remain v1');
