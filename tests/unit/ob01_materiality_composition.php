<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Ob01Fixture.php';

use Allfather\Loopia\PromptOps\{CandidateChecks,ObservationMaterialityV2,ProductPromptPack,PromptBundleRegistry,PromptSelectionResolver};
use Allfather\Loopia\Provider\PreparedModelRequest;
use Allfather\Loopia\Tests\{Assert,Ob01Fixture};

$root = dirname(__DIR__, 2);
$fixtures = Ob01Fixture::loadCases();
Assert::same('ob01.synthetic-materiality-v2-fixtures.v1', $fixtures['schema_version'], 'OB01 fixed schema revision');
Assert::same('SYNTHETIC_DEVELOPMENT_ONLY_NEVER_HELDOUT', $fixtures['status'], 'development evidence cannot be re-labelled heldout');
Assert::same(9, count($fixtures['cases']), 'nine named deterministic synthetic examples');
$resolver = PromptSelectionResolver::inert();
$proposal = $resolver->contentVersioned('P01_SIGNAL_CLASSIFIER', PromptBundleRegistry::OBSERVATION_V2_PACK);
Assert::true($proposal->isOk(), 'only exact proposed v2 content resolves for inspection');
if (!$proposal->isOk()) {
    return;
}
$p = $proposal->value();
Assert::same('PROPOSED_U01_NOT_RATIFIED', $p['contract_status'], 'v2 contract remains pending U01');
Assert::same('e3f5141e2728d751c860f496307708af1843e78f71ca14b93059e1290641b845',
    $p['prompt_sha256'], 'v2 prompt hash exactly historical');
Assert::same('9a6ff980a81631690bf7c8a2c419f13b6fa719019e920b93aab29c8234f3627e',
    $p['schema_sha256'], 'v2 output schema hash exactly historical');

foreach ($fixtures['cases'] as $case) {
    $scenario = Ob01Fixture::scenario($case);
    $context = Ob01Fixture::context($scenario);
    $output = ObservationMaterialityV2::composeFixture($scenario, $context);
    Assert::true($output->isOk(), 'OB01 fixture composes: ' . $case['id']
        . ($output->isOk() ? '' : ' refusal=' . $output->reasonCode()));
    if (!$output->isOk()) {
        continue;
    }
    $result = $output->value();
    $candidate = $result['candidate'];
    Assert::same($case['expected_status'], $candidate['status'], 'explicit candidate disposition: ' . $case['id']);
    Assert::same($case['significance'], $candidate['significance'], 'synthetic-only business materiality proposal: ' . $case['id']);
    Assert::same($case['attention'], $candidate['attention_recommendation'], 'separate attention recommendation: ' . $case['id']);
    Assert::same($case['expected_injection'], $candidate['source_injection_detected'], 'injection HOLD state: ' . $case['id']);
    Assert::same($case['structural'], $result['structural_magnitude_reported_by_fixture'],
        'structural magnitude preserved independently of commercial claim: ' . $case['id']);
    Assert::same($case['expected_kind'], $candidate['observed_changes'][0]['kind'] ?? null,
        'typed observation persists only when admissible: ' . $case['id']);
    Assert::same('ADVISORY_CANDIDATE_NOT_NATIVE_OWNER_RESULT', $result['state'],
        'a validated JSON candidate is not owner release: ' . $case['id']);
    Assert::same(false, $result['semantic_truth_verified'], 'fixture is not model-quality evidence: ' . $case['id']);
    Assert::same(false, $result['current_source_rights_verified'], 'synthetic references are not M05 rights: ' . $case['id']);
    Assert::same(false, $result['provider_dispatch_authorized'], 'no paid AI dispatch: ' . $case['id']);
    Assert::same(false, $result['execution_authorized'], 'no execution: ' . $case['id']);
    Assert::same(false, $result['release_authorized'], 'no release: ' . $case['id']);
    Assert::same(false, $result['notification_authorized'], 'no alert: ' . $case['id']);
    Assert::same(false, $result['customer_output_authorized'], 'no customer output: ' . $case['id']);
    Assert::same(true, $result['human_review_required'], 'independent human review remains mandatory');
    Assert::same(true, $result['citation_checks_passed'], 'exact v2 validator checked evidence IDs');
    Assert::same('U01_U06_NOT_ADOPTED', $result['owner_decision_status'], 'candidate cannot promote U01/U06');

    $checked = CandidateChecks::validate($candidate, $p, $context);
    Assert::true($checked->isOk(), 'existing v2 PromptOps validator also accepts the assembled object: ' . $case['id']);
    Assert::same($result['candidate_digest'], ObservationMaterialityV2::composeFixture($scenario, $context)->value()['candidate_digest'],
        'same digested source and rule revision create the same candidate: ' . $case['id']);
    if ($candidate['status'] === 'HELD') {
        Assert::same([], $candidate['observed_changes'], 'uncertain/injected input never leaks descriptive assertion');
        Assert::same([], $candidate['relevance_hypotheses'], 'uncertain/injected input never leaks a commercial hypothesis');
        Assert::true($candidate['limitations'] !== [], 'HOLD carries a safe limitation');
    } else {
        Assert::same([Ob01Fixture::BEFORE_ID, Ob01Fixture::AFTER_ID],
            $candidate['observed_changes'][0]['citations'], 'both ordered source snapshots cited');
        Assert::same('DESCRIPTIVE_OBSERVATION',
            $candidate['observed_changes'][0]['claim_type'], 'observation not promoted to certified fact');
        if ($candidate['status'] === 'NO_MATERIAL_CHANGE') {
            Assert::same('NONE', $candidate['significance'], 'quiet observations are not business findings');
            Assert::same([], $candidate['relevance_hypotheses'], 'quiet has no promotional impact hypothesis');
            Assert::same('RETAIN_ONLY', $candidate['attention_recommendation'], 'quiet is never alert trigger');
        } else {
            Assert::same(1, count($candidate['relevance_hypotheses']), 'separate synthetic commercial hypothesis');
            Assert::same([Ob01Fixture::TENANT_CONTEXT_ID],
                $candidate['relevance_hypotheses'][0]['tenant_context_refs'], 'tenant context cited separately from public source');
        }
    }
    Assert::same('MODEL_PROPOSED_OBSERVATION_V2_INERT',
        PreparedModelRequest::prepare($p, $context)->reasonCode(), 'even valid v2 fixture cannot become provider request');
}

$quiet = Ob01Fixture::scenario($fixtures['cases'][0]);
$quietCtx = Ob01Fixture::context($quiet);
$quietCandidate = ObservationMaterialityV2::composeFixture($quiet, $quietCtx)->value()['candidate'];
Assert::same('MATERIAL', $quiet['structural_magnitude'], 'fixture structural magnitude can be large');
Assert::same('NO_MATERIAL_CHANGE', $quietCandidate['status'], 'nav order evidence remains quiet under v2');
Assert::same('NONE', $quietCandidate['significance'], 'business materiality never inferred from structural ratio');

$legacy = ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER')->value();
Assert::same('PROMPT_CANDIDATE_STATUS_INCONSISTENT',
    CandidateChecks::validate($quietCandidate, $legacy, $quietCtx)->reasonCode(),
    'v1 still rejects nonempty NO_MATERIAL_CHANGE while v2 allows it');
$oldQuiet = $quietCandidate;
$oldQuiet['observed_changes'] = [];
Assert::true(CandidateChecks::validate($oldQuiet, $legacy, $quietCtx)->isOk(),
    'original v1 quiet-empty rule remains unchanged');
Assert::same('5310cb3b488db2a56a322b2a131872c934a3b69bd4edbc4a4822ec42c3ca180f',
    $legacy['prompt_sha256'], 'old prompt hash remains pinned');
Assert::same('4b8d4acef7d2938e8d3a159c41d42f5ba9e52c6d5ce6bf3cd0e2e893d79649c9',
    $legacy['schema_sha256'], 'old schema hash remains pinned');
Assert::same('ed3c377ea2a91e906f8df2875d337af524b7e70db6f111803cc90c21a22bbcde',
    hash_file('sha256', $root . '/app/PromptOps/bundles/2026-09-27.monitor.v1.json'),
    'v1 full bundle source bytes unchanged since VA-00 freeze');
Assert::same('cd21a4224b4b1c54f4378657b1950d5d399a0a743738739799f2341eeddf7c70',
    hash_file('sha256', $root . '/tests/fixtures/monitor_model_eval_cases.json'),
    'all 18 original eval cases and split/threshold bytes unchanged');
