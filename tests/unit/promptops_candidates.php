<?php
declare(strict_types=1);
use Allfather\Loopia\PromptOps\CandidateChecks;
use Allfather\Loopia\PromptOps\ProductPromptPack;
use Allfather\Loopia\Tests\Assert;
$data = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/monitor_prompt_offline_cases.json'), true, 128, JSON_THROW_ON_ERROR);
foreach ($data['cases'] as $case) {
    $prompt = ProductPromptPack::resolve($case['role'])->value();
    $r = CandidateChecks::validate($case['candidate'], $prompt, $case['context']);
    if ($case['expected_refusal'] === null) {
        Assert::true($r->isOk(), $case['name']);
        if ($r->isOk()) {
            Assert::false($r->value()['semantic_truth_verified'], 'mechanical checks never claim entailment');
            Assert::true($r->value()['human_review_required'], 'human review remains explicit');
        }
    } else {
        Assert::same($case['expected_refusal'], $r->errorCode, $case['name']);
    }
}
$case = $data['cases'][0]; $prompt = ProductPromptPack::resolve($case['role'])->value();
$prompt['execution_authorized'] = true;
Assert::same('PROMPT_CANDIDATE_UNTRUSTED_BINDING', CandidateChecks::validate($case['candidate'], $prompt, $case['context'])->errorCode, 'self-promoted prompt refused');
$prompt = ProductPromptPack::resolve($case['role'])->value();
$context = $case['context']; $context['envelope']['evidence'][] = $context['envelope']['evidence'][0];
Assert::same('PROMPT_CANDIDATE_EVIDENCE_AMBIGUOUS', CandidateChecks::validate($case['candidate'], $prompt, $context)->errorCode, 'duplicate evidence identity refused');

$prompt = ProductPromptPack::resolve($case['role'])->value();
$prompt['invalid_float'] = 1.5;
Assert::same('PROMPT_CANDIDATE_UNTRUSTED_BINDING', CandidateChecks::validate($case['candidate'], $prompt, $case['context'])->errorCode, 'malformed record refuses without throwing');
