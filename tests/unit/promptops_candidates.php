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

 
// Forensic reproduction of all plausible *schema-valid* review critic gate outcomes
// for the Luna DEV7 unsupported-title case. These are deterministic synthetic
// candidates, not a reconstruction of the original provider's unretained bytes.
$criticCase = null;
foreach ($data['cases'] as $one) {
    if ($one['name'] === 'critic_is_only_human_review_recommendation') { $criticCase = $one; break; }
}
Assert::true(is_array($criticCase),'synthetic critic gate fixture is available');
if (is_array($criticCase)) {
    $criticPrompt = ProductPromptPack::resolve('MONITOR_REVIEW_CRITIC')->value();
    $valid = $criticCase['candidate'];
    $valid['verdict'] = 'REVISE';
    $valid['findings'] = [[
        'code' => 'UNSUPPORTED_CLAIM', 'severity' => 'BLOCK',
        'explanation' => 'The title claims an event occurred, while both sources only announce plans.',
        'citations' => ['ev-before','ev-after']
    ]];
    Assert::true(CandidateChecks::validate($valid,$criticPrompt,$criticCase['context'])->isOk(),'review critic can issue a blocked REVISE with original evidence citations');
    $info = $valid;
    $info['findings'][0]['severity'] = 'INFO';
    Assert::same('PROMPT_CANDIDATE_REVIEW_INCONSISTENT', CandidateChecks::validate($info,$criticPrompt,$criticCase['context'])->reasonCode(),'REVISE without a blocking finding is rejected');
    $foreign = $valid;
    $foreign['findings'][0]['citations'] = ['ev-not-in-context'];
    Assert::same('PROMPT_CANDIDATE_REVIEW_CITATION_INVALID', CandidateChecks::validate($foreign,$criticPrompt,$criticCase['context'])->reasonCode(),'critic cannot cite an unsupplied evidence ID');
    $unsafe = $valid;
    $unsafe['source_injection_detected'] = true;
    Assert::same('PROMPT_CANDIDATE_INJECTION_REQUIRES_HOLD', CandidateChecks::validate($unsafe,$criticPrompt,$criticCase['context'])->reasonCode(),'injection-detected critique must HOLD rather than REVISE');
}
$prompt['invalid_float'] = 1.5;
Assert::same('PROMPT_CANDIDATE_UNTRUSTED_BINDING', CandidateChecks::validate($case['candidate'], $prompt, $case['context'])->errorCode, 'malformed record refuses without throwing');
