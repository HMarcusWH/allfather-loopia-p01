<?php
declare(strict_types=1);
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\PromptOps\PromptBundleRegistry;
use Allfather\Loopia\PromptOps\PromptSelectionResolver;
use Allfather\Loopia\Tests\Assert;

$v1=['P01_SIGNAL_CLASSIFIER'=>['5310cb3b488db2a56a322b2a131872c934a3b69bd4edbc4a4822ec42c3ca180f','4b8d4acef7d2938e8d3a159c41d42f5ba9e52c6d5ce6bf3cd0e2e893d79649c9'],
'BATTLECARD_GENERATOR'=>['e14131b69834f91f8a2602e7596c13995a3390359a10cca2c59a185e4907076','7f86e8a44100aa366581e19649802a6e59053a181fcc6f33cd4063467545e50f'],
'MONITOR_REVIEW_CRITIC'=>['46c3b39172a3ca0f1209d8d41df739e17a844702d1ed5519ed86bd9dc50944e6','35f5aec661c42ffafc4921dad7a2d522d0a9bf4c5305a235fbe2ad6eff901130'],
'MONITOR_ROUTING_CRITIC'=>['10cf884c2cebf7be6f6989cfe065d60e576566cef7df558e03a79064a8b513b7','fc1119f7d0e49a0f418bd3e1c476bc756a49a1855535da34550b98a4c5362623']];
foreach ($v1 as $role=>[$promptSha,$schemaSha]) {
  $p=ProductPromptPack::resolve($role);
  Assert::true($p->isOk(),"legacy role {$role} still resolves");
  if (!$p->isOk()) continue;
  Assert::same($promptSha,$p->value()['prompt_sha256'],"{$role} v1 prompt unchanged");
  Assert::same($schemaSha,$p->value()['schema_sha256'],"{$role} v1 schema unchanged");
  Assert::same($p->value(),PromptBundleRegistry::resolve($role,ProductPromptPack::PACK_VERSION)->value(),"{$role} exact v1 replay");
}
$r=PromptBundleRegistry::resolve('P01_SIGNAL_CLASSIFIER',PromptBundleRegistry::OBSERVATION_V2_PACK);
Assert::true($r->isOk(),'v2 draft is independently hash verified and inspectable');
if (!$r->isOk()) return;
$p=$r->value();
Assert::same('NOT_EVALUATED',$p['evaluation_status'],'the new contract is not model-evaluated');
Assert::same(false,$p['model_evaluated'],'model quality must not be fabricated');
Assert::same(false,$p['customer_output_authorized'],'no customer release');
Assert::same(false,$p['external_action_authorized'],'no external action');
Assert::same(false,$p['live_llm_calls_enabled'],'no paid provider dispatch');
Assert::false(PromptSelectionResolver::inert()->verify($p),'v2 cannot be selected under v1 grant path');
$schema=json_decode(json_encode($p['output_schema'],JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
Assert::same('object',$schema['type'],'the v2 schema is a closed object');
Assert::true(\Allfather\Loopia\Provider\StructuredOutput::schemaSupported($schema),'v2 uses the existing bounded schema subset');
