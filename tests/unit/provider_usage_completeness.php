<?php

declare(strict_types=1);

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Provider\ModelExecutor;
use Allfather\Loopia\Tests\Assert;

// LI-00 / closed PR64 selective repair: all data here is synthetic, no DB/provider access.
$known=ModelExecutor::candidateValidationRefusal(
    Outcome::refuse('PROMPT_CANDIDATE_INJECTION_REQUIRES_HOLD'),
    [
        'kind'=>'GENERATED','input_tokens'=>137,'output_tokens'=>89,
        'candidate'=>['private_body'=>'must never enter call telemetry'],
        'result_digest'=>'synthetic-only',
    ],
);
Assert::same('FAILED',$known['kind'],'post-schema refusal cannot become a candidate');
Assert::same('MODEL_CANDIDATE_REFERENCE_INVALID',$known['reason_code'],'public failure remains generic');
Assert::same('PROMPT_CANDIDATE_INJECTION_REQUIRES_HOLD',$known['validation_reason_code'],'private diagnostic is allowlisted');
Assert::same('FAILED',$known['telemetry_outcome'],'refusal not a successful inference');
Assert::same(137,$known['input_tokens'],'known billed input preserved');
Assert::same(89,$known['output_tokens'],'known billed output preserved');
Assert::false(isset($known['candidate']),'raw candidate never added to telemetry');
Assert::false(isset($known['result_digest']),'candidate digest not copied to call record');

$unknown=ModelExecutor::candidateValidationRefusal(
    Outcome::refuse('TENANT_PROVIDER_MESSAGE:SECRET123'),
    ['kind'=>'GENERATED','candidate'=>['sensitive'=>'no'], 'input_tokens'=>null],
);
Assert::same('MODEL_CANDIDATE_REFERENCE_INVALID',$unknown['validation_reason_code'],'unknown diagnosis is sanitized');
Assert::same(null,$unknown['input_tokens'],'unreported input tokens stay UNKNOWN');
Assert::same(null,$unknown['output_tokens'],'unreported output tokens stay UNKNOWN');
Assert::false(str_contains(json_encode($unknown,JSON_THROW_ON_ERROR),'SECRET123'),'unknown reason never leaks to metering');

$zero=ModelExecutor::candidateValidationRefusal(Outcome::refuse('PROMPT_CANDIDATE_SHAPE_INVALID'),[
    'input_tokens'=>0,'output_tokens'=>0,
]);
Assert::same(0,$zero['input_tokens'],'known zero is not confused with unknown');
Assert::same(0,$zero['output_tokens'],'known zero output retained');

$exception=ModelExecutor::adapterExceptionResult([
    'kind'=>'GENERATED','input_tokens'=>210,'output_tokens'=>420,'candidate'=>['private'=>'omit'],
]);
Assert::same('FAILED',$exception['kind'],'processing exception is failed');
Assert::same('MODEL_ADAPTER_EXCEPTION',$exception['reason_code'],'processing exception named generically');
Assert::same(210,$exception['input_tokens'],'post-return exception retains known input usage');
Assert::same(420,$exception['output_tokens'],'post-return exception retains known output usage');
Assert::false(isset($exception['candidate']),'processing exception never persists candidate bytes');
$beforeNetwork=ModelExecutor::adapterExceptionResult(null);
Assert::same(null,$beforeNetwork['input_tokens'],'pre-response exception cannot invent input usage');
Assert::same(null,$beforeNetwork['output_tokens'],'pre-response exception cannot invent output usage');
