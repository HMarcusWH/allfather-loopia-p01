<?php
declare(strict_types=1);
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\PromptOps\PromptBundleRegistry;
use Allfather\Loopia\PromptOps\PromptSelectionResolver;
use Allfather\Loopia\PromptOps\CandidateChecks;
use Allfather\Loopia\Provider\PreparedModelRequest;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Harness;

$db=Harness::freshDb();
if ($db===null) { Assert::skip(Harness::unavailableReason()); return; }
$oldPrompt=ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER');
$newPrompt=ProductPromptPack::resolveVersion('P01_SIGNAL_CLASSIFIER',PromptBundleRegistry::OBSERVATION_V2_PACK);
Assert::true($oldPrompt->isOk() && $newPrompt->isOk(),'both frozen versions resolve without SQL mutation');
if (!$oldPrompt->isOk() || !$newPrompt->isOk()) return;
$before=(int)$db->scalar('SELECT COUNT(*) FROM monitor_prompt_approvals');
$beforeCalls=(int)$db->scalar('SELECT COUNT(*) FROM provider_call_records');
$resolver=PromptSelectionResolver::inert();
Assert::same('MODEL_PROPOSED_OBSERVATION_V2_INERT',
    PreparedModelRequest::prepare($newPrompt->value(),[],[], $resolver)->reasonCode(),
    'v2 cannot create a model request even in the database-backed environment');
Assert::false($resolver->verify($newPrompt->value()),'proposed content never verifies as executable selection');
Assert::true($resolver->verify($oldPrompt->value()),'legacy v1 exact inert content still verifies');
Assert::same($before,(int)$db->scalar('SELECT COUNT(*) FROM monitor_prompt_approvals'),'v2 inspection writes no prompt approvals');
Assert::same($beforeCalls,(int)$db->scalar('SELECT COUNT(*) FROM provider_call_records'),'v2 inspection makes no provider call');
