<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Ob01Fixture.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\PromptOps\{ObservationMaterialityV2,PromptSelectionResolver,PromptBundleRegistry,ProductPromptPack};
use Allfather\Loopia\Provider\PreparedModelRequest;
use Allfather\Loopia\Tests\{Assert,Ob01Fixture};

$cases = Ob01Fixture::loadCases()['cases'];
$base = Ob01Fixture::scenario($cases[0]);
$context = Ob01Fixture::context($base);
$compose = static fn (array $case, array $ctx)
    => ObservationMaterialityV2::composeFixture($case, $ctx);
$refuse = static function (array $case, array $ctx, string $code, string $message) use ($compose): void {
    $out = $compose($case, $ctx);
    Assert::true($out->isRefusal(), $message . ': refusal not success');
    if ($out->isRefusal()) {
        Assert::same($code, $out->reasonCode(), $message);
    }
};

$wrong = $base;
$wrong['execution_grant_id'] = 'fake';
$refuse($wrong, $context, 'OB01_FIXTURE_CONTRACT_INVALID', 'candidate cannot smuggle grant field');
$wrong = $base;
$wrong['synthetic_scope'] = 'PRODUCTION';
$refuse($wrong, $context, 'OB01_NOT_AN_ADMITTED_FIXTURE_CASE', 'fixture cannot claim production scope');
$wrong = $base;
$wrong['tenant_id'] = 'another-tenant';
$refuse($wrong, $context, 'OB01_CONTEXT_SCOPE_INVALID', 'foreign tenant cannot read fixture evidence');
$wrong = $base;
$wrong['workspace_id'] = 'wrong-workspace';
$refuse($wrong, $context, 'OB01_CONTEXT_SCOPE_INVALID', 'foreign workspace cannot read fixture evidence');
$wrong = $base;
$wrong['after_evidence_id'] = $wrong['before_evidence_id'];
$refuse($wrong, $context, 'OB01_AMBIGUOUS_FIXTURE_IDENTITY', 'one snapshot cannot act as its own successor');
$wrong = $base;
$wrong['after_text'] .= ' a changed source byte';
$refuse($wrong, $context, 'OB01_SNAPSHOT_TEXT_PROOF_MISMATCH', 'modified text fails frozen per-source digest check');
$wrong = $base;
$wrong['before_text'] = $wrong['after_text'];
$ctx = Ob01Fixture::context($wrong);
$refuse($wrong, $ctx, 'OB01_NO_SOURCE_CHANGE', 'identical pages cannot become a change');
$wrong = $base;
$wrong['case_kind'] = 'UNKNOWN_CHANGE_TYPE';
$refuse($wrong, $context, 'OB01_NOT_AN_ADMITTED_FIXTURE_CASE', 'unknown semantic branch cannot infer materiality');

$badContext = $context;
$badContext['digest'] = str_repeat('0', 64);
$refuse($base, $badContext, 'OB01_CONTEXT_DIGEST_MISMATCH', 'context must be exact digest-bound');
$badContext = $context;
$badContext['envelope']['evidence'][0]['tenant_id'] = 'foreign';
$badContext['digest'] = Digest::canonicalDigest($badContext['envelope']);
$refuse($base, $badContext, 'OB01_CONTEXT_EVIDENCE_INVALID', 'foreign evidence cannot be cited');
$badContext = $context;
$badContext['envelope']['evidence'][1]['binding']['snapshot_sequence'] = 1;
$badContext['digest'] = Digest::canonicalDigest($badContext['envelope']);
$refuse($base, $badContext, 'OB01_SNAPSHOT_ORDER_OR_SOURCE_MISMATCH', 'snapshots must be chronologically ordered');
$badContext = $context;
$badContext['envelope']['evidence'][1]['binding']['monitored_source_id'] = 'foreign-source';
$badContext['digest'] = Digest::canonicalDigest($badContext['envelope']);
$refuse($base, $badContext, 'OB01_SNAPSHOT_ORDER_OR_SOURCE_MISMATCH', 'cross-source before/after pairs denied');
$badContext = $context;
$badContext['envelope']['evidence'][] = $badContext['envelope']['evidence'][0];
$badContext['digest'] = Digest::canonicalDigest($badContext['envelope']);
$refuse($base, $badContext, 'OB01_CONTEXT_EVIDENCE_INVALID', 'duplicate evidence IDs denied');

$badQuiet = $base;
$badQuiet['proposed_significance'] = 'MAJOR';
$refuse($badQuiet, $context, 'OB01_QUIET_CANNOT_ESCALATE_MATERIALITY', 'no-material quiet signal cannot claim MAJOR');
$badQuiet = $base;
$badQuiet['attention'] = 'REVIEW_NOW';
$refuse($badQuiet, $context, 'OB01_QUIET_CANNOT_ESCALATE_MATERIALITY', 'quiet change cannot request notification');
$badQuiet = $base;
$badQuiet['after_text'] = 'Studios, Pricing, Another Product';
$ctx = Ob01Fixture::context($badQuiet);
$refuse($badQuiet, $ctx, 'OB01_CHANGE_KIND_NOT_PROVED_BY_FIXTURE', 'menu content changed is not a pure order change');

$price = Ob01Fixture::scenario($cases[2]);
$priceContext = Ob01Fixture::context($price);
$missing = $price;
$missing['tenant_context_ref_id'] = null;
$refuse($missing, $priceContext, 'OB01_REVIEW_HYPOTHESIS_INCOMPLETE', 'commercial hypothesis requires tenant-context evidence');
$missing = $price;
$missing['assessment_reason'] = '';
$refuse($missing, $priceContext, 'OB01_REVIEW_HYPOTHESIS_INCOMPLETE', 'commercial significance requires stated uncertainty');
$missing = $price;
$missing['proposed_significance'] = 'NONE';
$refuse($missing, $priceContext, 'OB01_REVIEW_HYPOTHESIS_INCOMPLETE', 'price change cannot skip independent materiality review');
$missing = $price;
$missing['after_text'] = '499 USD';
$ctx = Ob01Fixture::context($missing);
$refuse($missing, $ctx, 'OB01_CHANGE_KIND_NOT_PROVED_BY_FIXTURE', 'currency change is not a same-unit price change');
$missing = $price;
$missing['tenant_context_ref_id'] = 'foreign-context';
$refuse($missing, $priceContext, 'OB01_TENANT_CONTEXT_NOT_BOUND', 'foreign tenant context cannot justify a finding');

$resolver = PromptSelectionResolver::inert();
$v2 = PromptBundleRegistry::OBSERVATION_V2_PACK;
$v1 = ProductPromptPack::PACK_VERSION;
Assert::same('PROMPT_OBSERVATION_V2_U01_NOT_ADOPTED',
    $resolver->selectVersioned('P01_SIGNAL_CLASSIFIER', $v2, null)->reasonCode(),
    'unadopted U01 is explicit hard refusal even with no grant');
Assert::same('PROMPT_OBSERVATION_V2_U01_NOT_ADOPTED',
    $resolver->selectVersioned('P01_SIGNAL_CLASSIFIER', $v2,
        ['tenant_id' => $base['tenant_id'], 'execution_grant_id' => 'fake'])->reasonCode(),
    'an apparently funded grant cannot select v2 until owner change');
Assert::same('PROMPT_BUNDLE_VERSION_NOT_REGISTERED',
    $resolver->selectVersioned('P01_SIGNAL_CLASSIFIER', 'latest', null)->reasonCode(),
    'no latest implicit downgrade to v1');
Assert::same(ProductPromptPack::resolve('P01_SIGNAL_CLASSIFIER')->value()['prompt_sha256'],
    $resolver->selectVersioned('P01_SIGNAL_CLASSIFIER', $v1, null)->value()['prompt_sha256'],
    'legacy exact version selection still emits v1');
Assert::same('MODEL_PROPOSED_OBSERVATION_V2_INERT',
    PreparedModelRequest::prepare($resolver->contentVersioned('P01_SIGNAL_CLASSIFIER', $v2)->value(), $context)->reasonCode(),
    'proposed v2 prompt remains outside the real provider request factory');
