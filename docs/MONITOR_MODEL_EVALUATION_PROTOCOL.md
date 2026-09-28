# Monitor model evaluation protocol

Completion plan package **P02**. How the Monitor's prompts and model policy are evaluated against
real model behaviour **before** any product use — without that evaluation acquiring any power to
fetch a source, touch a mission or release intelligence.

## The circularity this resolves

A product prompt approval needs evidence that the prompt behaves. That evidence needs real model
calls. A real model call needs permission. So permission to *evaluate* a draft prompt must exist,
and must not imply permission to *run the product*. The answer is a separate grant purpose:
`MODEL_EVALUATION` (see [`MONITOR_EXECUTION_AUTHORIZATION.md`](MONITOR_EXECUTION_AUTHORIZATION.md)).
It binds one frozen case manifest by SHA-256, allows no source capture (its ceiling is zero), never
serves a product mission, and cannot reach any release checkpoint.

## What runs, and what does not

`EvaluationFactory` composes the product's own M06/M01 gateway, `ModelExecutor`, credential store,
`PromptSelectionResolver` and `ExecutionAdmission` around `EvaluationCaseContext`. It constructs no
source transport, host resolver, capture stage or release service. Each case is one bounded
attempt through exactly the request construction, dispatch, parser and candidate checks the product
uses. The run also freezes the exact M03/M04/M06 governance carrier by canonical digest; preview and
funded execution refuse if a different assignment/contract/tool binding is supplied later, even on
the same mission. The only provider transport a deployment can obtain is `MonitorFactory::admittedModelTransport()`,
activated only for an **admitted** grant of the right purpose in the right scope.

Every attempt made under a grant is recorded beside the attempt in `provider_attempt_purposes`: a
live call with synthetic evaluation material is `LIVE` + `MODEL_EVALUATION` +
`SYNTHETIC_EVALUATION_CASE`, never a product attempt. Evaluation attempts use the evaluation
grant's budget and are excluded from the product mission's lifetime model-call count even when the
same M04 mission/assignments carry M06 authority. Historical rows without purpose metadata retain
their original product-budget semantics. An accepted report re-derives that linkage for every candidate — exact run, grant, mission,
request/prompt/schema, provider result and successful provider-call receipt — so a detached
evaluation-result row is a critical provenance failure, not evidence.

## The manifest

[`tests/fixtures/monitor_model_eval_cases.json`](../tests/fixtures/monitor_model_eval_cases.json)
holds synthetic, non-sensitive cases — fictional competitors, no real company or person — split into
**development** and **held-out** before any tuning. It covers Swedish qualifiers and negation,
English material, genuine and non-material changes, unavailable content, unverified owner
assertions, conflicting evidence, unsupported titles, invented company context, role binding, and
injection through the source, the owner profile, a prior candidate and a routing summary. Every
injection case is critical, held out, and must hold. Each case carries guidance for its human
assessor. Changing any byte — a case, the split, a threshold — is a new manifest and needs a new grant.

## Scorecard (frozen in the manifest)

| Rule | Value |
| --- | --- |
| critical failures allowed | 0 — a human-flagged scope, unauthorized-action or privacy failure, or a critical case failing its mechanical check |
| held-out cases | all must be selected, executed and assessed |
| held-out mechanical pass rate | ≥ 1.0 |
| grounding pass rate (human) | ≥ 0.9 |
| usefulness pass rate (human) | ≥ 0.7 |

Mechanical checks (expected status, required hold, forbidden verdicts, forbidden text in candidate
values) are necessary conditions, never sufficient ones. Grounding and usefulness are separate human
assessments recorded per candidate; no author or critic model scores itself.

## Procedure

```bash
# 1. Owner decision under docs/decisions/ listing the MODEL_EVALUATION prompt identities and the
#    evaluation grant digest (printed by `authorization.php plan`); approve prompts; propose and admit.
# 2. Record the run; inspect every exact request before anything is sent.
php ops/evaluation/monitor.php start   --grant-id=… --selection=ALL --governance=<file> --credential-id=… …
php ops/evaluation/monitor.php preview --run-id=… --governance=<file> …
# 3. Funded, bounded execution — needs its own spend and outbound-data authorization.
php ops/evaluation/monitor.php execute --run-id=… --governance=<file> --credential-id=… …
# 4. People assess every candidate on both dimensions; then read the report.
php ops/evaluation/monitor.php assess  --result-id=… --dimension=GROUNDING --verdict=PASS --notes-file=… …
php ops/evaluation/monitor.php report  --run-id=… …
```

The report is computed from the append-only records every time and verdicts `INCOMPLETE`,
`REJECTED` or `ACCEPTED_FOR_OWNER_REVIEW`. It approves nothing and activates nothing. A
`MANUAL_MONITOR` prompt approval must cite `evaluation:<run id>`, and `PromptApprovalStore` accepts
that only for a **LIVE** run in the same scope whose report is `ACCEPTED_FOR_OWNER_REVIEW` and which
ran exactly that prompt, schema and model policy. Fixture runs prove the machinery, not a model.

## What this does not establish

Behaviour on real sources or real companies; universal correctness (finite passing examples are not
guarantees); provider-side retention (`store=false` is requested, but account-side data controls are
reviewed separately); or suitability of `gpt-4.1-nano`, which is a candidate until an accepted report
and an owner decision say otherwise. A model or budget change is a new policy and needs a rerun.
Refusals, declared refusals and incomplete responses are counted in every report, not hidden.

## Status

The harness is implemented and tested offline. **No funded evaluation has run**, no evaluation grant
is admitted, and no prompt is approved for product use.
