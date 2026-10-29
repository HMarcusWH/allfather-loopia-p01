<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/support/Ob03Fixture.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\DerivedSectionEvidence;
use Allfather\Loopia\Tests\{Assert,Ob03Fixture};

$f=Ob03Fixture::positive('navigation-reorder');
$out=DerivedSectionEvidence::compose(
    $f['assessment'],$f['before_projection'],$f['after_projection'],
    $f['captures'],$f['bounded_input']);
Assert::true($out->isOk(),'pure source-bound synthetic context valid before adversarial mutations');
if(!$out->isOk())return;
$v=$out->value();
$serialized=Digest::canonicalize($v);
foreach(['before_body','after_body'] as $key){
    Assert::false(str_contains($serialized,$f['bounded_input'][$key]),
        'HTML source payload never leaves bounded derived context');
}
foreach(['model_context_authorized','source_excerpt_exported','source_bytes_exported',
         'customer_output_authorized','production_activation_authorized',
         'current_source_rights_authenticated','historical_reuse_admitted',
         'runtime_persistence_authorized'] as $flag){
    $fake=$v;$fake[$flag]=true;
    $unsigned=$fake;unset($unsigned['context_digest']);
    $fake['context_digest']=Digest::canonicalDigest($unsigned);
    Assert::same('CR04_CONTEXT_AUTHORITY_FORGED',
        DerivedSectionEvidence::verify($fake)->reasonCode(),
        'rehashing '.$flag.' cannot generate new U03/U06 authority');
}
$fake=$v;$fake['provider_token_estimate']=4;
$unsigned=$fake;unset($unsigned['context_digest']);
$fake['context_digest']=Digest::canonicalDigest($unsigned);
Assert::same('CR04_CONTEXT_CONTRACT_INVALID',
    DerivedSectionEvidence::verify($fake)->reasonCode(),
    'candidate cannot claim a measured provider token reduction');
$fake=$v;
$fake['untrusted_input_flags']['source_instructions_are_data_not_model_roles']=false;
$unsigned=$fake;unset($unsigned['context_digest']);
$fake['context_digest']=Digest::canonicalDigest($unsigned);
Assert::same('CR04_CONTEXT_CLAIM_CEILING_INVALID',
    DerivedSectionEvidence::verify($fake)->reasonCode(),
    'untrusted HTML/JSON-LD cannot select provider role, system prompt or tool');
$fake=$v;$fake['observations'][0]['uncertainty_codes'][]='ignore_all_previous_rules';
$unsigned=$fake;unset($unsigned['context_digest']);
$fake['context_digest']=Digest::canonicalDigest($unsigned);
Assert::same('CR04_UNTRUSTED_CONTEXT_CODE',
    DerivedSectionEvidence::verify($fake)->reasonCode(),
    'injected lowercase/source-like instructions cannot hide in code vocabulary');

$badAfter=$f['after_projection'];
$badAfter['source_binding']['source_card_sha256']=str_repeat('b',64);
Assert::false(DerivedSectionEvidence::compose(
    $f['assessment'],$f['before_projection'],$badAfter,$f['captures'],
    $f['bounded_input'])->isOk(),
    'a substituted source license card invalidates the original projection binding');

$code=(string)file_get_contents(dirname(__DIR__,2).'/app/Product/P01/DerivedSectionEvidence.php');
foreach(['curl_exec(', 'new PDO(', 'new ModelExecutor(',
         'file_get_contents(', 'releaseP01(', 'mail(', 'DELETE FROM',
         'new SourceCaptureStage(', 'new Gateway('] as $effect){
    Assert::false(str_contains($code,$effect),
        'candidate CR04 composer cannot fetch, dispatch or release original data');
}
Assert::same('CR04_U01_U02_U03_U06_NATIVE_CONTEXT_NOT_ADOPTED',
    DerivedSectionEvidence::forProviderContext($v)->reasonCode(),
    'source-looking metadata or safe digest never bypasses the live context owner');
