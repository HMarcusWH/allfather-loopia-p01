# Canonical → Loopia capability matrix

Baseline: canonical `32c98a7191b7a4ae7a45d745917f39521f8f5112`; Loopia main `079047598f477edf9d92c531edb744d75923ecc5`; upstream pin `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`; running staging application remains PR16 `392f48de…`.

**Currentness result:** tracked `packages/`, `tools/`, `scripts/` and `tests/` have zero diff between the pin and current canonical main. There are no later runtime improvements in those trees to import. The work is owner-local defect repair, selected porting and missing composition—not a blind upstream upgrade.

**Requested completion:** bounded product prompts, provider integration and an agent-driven Monitor runner are required. The existing manual authored service path is a diagnostic baseline, not completion. Full adaptive M13/M14 and Universal Fetch/Port remain distinct future architecture.

Maturity describes the named component, not activation or complete product readiness. `IMPLEMENTED_INACTIVE` means executable machinery exists but the selected live route is inactive; `FIXTURE_ONLY` means simulator/synthetic proof; `SPEC_ONLY` means an owned design; `ABSENT` means no runtime in that projection.

| Capability | Canonical | Loopia | Finding / required action |
|---|---|---|---|
| P01 Competition monitoring and briefings | IMPLEMENTED | IMPLEMENTED | SHARED_UPSTREAM_DEFECT: Repair capture-text/significance binding, contract-reference persistence and shared-tool registration upstream and in PHP; connect approved-source intake and governed agent workflow. |
| P02 Entity and relationship twins | IMPLEMENTED | IMPLEMENTED | BOUNDED_PROJECTION_AND_DEFECT: Repair affected scope validation before exposing writes. Keep cardless external content held; broader external-source lineage support requires an explicit governed extension. |
| P03 Catalogue, ICP and fit qualification | IMPLEMENTED | IMPLEMENTED | PARTIAL_PORT_AND_INPUT_GAP: Repair structured ICP inputs and truthful owner-upload catalogue mapping when enabling P03; port its three assurance profiles before P03 customer launch. |
| P04 Internal role distribution | IMPLEMENTED | IMPLEMENTED | WIRING_GAP: Connect reviewed P01 findings to configured internal roles and explicit summaries; retain existing admission/M10/acknowledgement gates. |
| P05 Internal marketing drafts | IMPLEMENTED | IMPLEMENTED | CORRECTNESS_AND_WIRING_GAP: Repair guarded title/body and citation evaluation before enabling generated drafts; generated output stays internal, reviewed and cited. |
| M01 Runtime invocation | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Compose only explicit governed read and model routes; do not introduce a second egress boundary. |
| M02 Tenant/workspace access | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Reuse session-bound server scope throughout workflow and authoring. |
| M03 Capability contracts | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Register scoped agent capabilities without external action grants. |
| M04 Missions and proposals | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Compose the product mission through existing proposals and persisted outcomes. |
| M05 Evidence and provenance | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Bind every model input/output to scoped evidence and captured bytes; retain policy-constrained storage. |
| M06 Governed tool gateway | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Use existing authorization and budgets for any added provider operation. |
| M07 Independent Council review | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Wire distinct reviewer role/output; model criticism cannot self-approve release. |
| M08 Arbiter | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Preserve owner-local policy/refusal semantics; model output does not grant authority. |
| M10 Decision ledger | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Bind the composed workflow to actual M10 writer; no direct seeded release truth. |
| M11 Audit and replay | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Add connected mission replay with no model/network re-execution and preserve actual limits. |
| M12 Authorized surfaces | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Use these projections for product UI; do not expose raw model results outside release gates. |
| M09 Approval and frozen action | IMPLEMENTED | IMPLEMENTED | BOUNDED_PROJECTION: No new external-action path needed for Monitor; retain synthetic-only boundary. |
| M15 Governed ingestion services | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Reuse the appropriate existing route; never require an invented connector hop for P01 direct capture. |
| LIVE_READ HTTPS transport and actual source capture | IMPLEMENTED_INACTIVE | IMPLEMENTED_INACTIVE | ACTIVATION_AND_COMPOSITION_GAP: Build bounded source allowlist/manual runner, safe status/bytes/retention handling and stand-down. Actual approved-source execution remains a later explicit gate. |
| M16 Internal release-output spine | IMPLEMENTED | IMPLEMENTED | BOUNDED_PORT: Preserve truthful source ceilings separately from deployment activation. P02 cardless external-content HOLD is deliberate and cannot be relabelled READY content. |
| UF Universal Fetch architecture | SPEC_ONLY | ABSENT | SPEC_NOT_PORTABLE_RUNTIME: Do not claim a ready upstream runtime to port; bounded P01 approved-source acquisition serves the requested first loop without inventing unrestricted Fetch. |
| UP Universal Port architecture | SPEC_ONLY | ABSENT | SPEC_NOT_PORTABLE_RUNTIME: Do not build or activate arbitrary connectors as a dependency of the first Monitor loop; any selected extension needs its own owner-local contracts. |
| PROMPT_CORE PromptOps pure contracts and resolver | IMPLEMENTED | ABSENT | REQUIRED_SELECTIVE_PORT: Port bounded PHP contracts with canonical hash/refusal golden vectors. Keep approval, evaluation, binding and actual invocation separate. |
| PROMPT_CATALOGUE Canonical prompt catalogue/store | FIXTURE_ONLY | ABSENT | FIXTURE_NOT_PRODUCT: Do not copy fixture templates as completed agents; immutable versioned deployment bundle is an explicit bounded implementation choice. |
| PRODUCT_AGENTS P01 interpretation/relevance/battlecard/critic prompts | ABSENT | ABSENT | REQUIRED_NEW_BOUNDED_IMPLEMENTATION: Author deployment-specific roles explicitly, with input/output schemas, citations, uncertainty, injection controls, role separation and offline evaluation. Do not falsely describe roles as an existing canonical port. |
| M13 Tenant adaptation and adaptive prompt binding | SPEC_ONLY | ABSENT | FUTURE_ARCHITECTURE: Use explicit tenant context as input to bounded agents; do not claim adaptive learning or promotion. |
| M14 Performance learning and prompt optimization | SPEC_ONLY | ABSENT | FUTURE_ARCHITECTURE: Offline evaluations and immutable approved prompt versions are required now; automatic optimization remains separate. |
| PROVIDER Product model provider dispatch | ABSENT | ABSENT | REQUIRED_NEW_BOUNDED_IMPLEMENTATION: Build a scoped adapter, strict payload/output validation, timeout/budget/idempotency and sanitized evidence. Inactive default; do not read/use secrets during offline construction. |
| SCHEDULER Recurring Monitor execution | ABSENT | IMPLEMENTED_INACTIVE | WIRING_AND_HOST_QUALIFICATION_GAP: Prefer manual first mission. Then wire explicit cadence/idempotency/stand-down handlers and qualify host transport if recurring monitoring is enabled. |
| REPLAY Connected product replay | IMPLEMENTED | IMPLEMENTED | COMPOSITION_PROOF_GAP: Persist prompt/model/input/output/review/release identities and replay deterministically with network/provider disabled. |
| CONSOLE Operator console and authoring | IMPLEMENTED | IMPLEMENTED | USER_JOURNEY_GAP: Build required Monitor controls and honest progress/refusal surfaces over existing authorization; additional feature panels are not automatically launch requirements. |

## Source and scope details

### P01 — Competition monitoring and briefings
Registry, source cards, guarded capture, deterministic change detection, signals, reviewed battlecards and M10 release exist. Product agent composition is absent.

Canonical: `packages/allfather-control-substrate/src/p01`, `docs/allfather-bi/implementation/p01_promotion_record.md`. Loopia: `app/Product/P01`, `docs/PORT_SCOPE_MATRIX.md`.

### P02 — Entity and relationship twins
Entity/relationship services and read APIs exist; offering twin and EOM are not this projection. Foreign-scope working-state writes were reproduced, not an unauthorized release. External evidence without a P01 card is deliberately held by M16 upstream and here.

Canonical: `packages/allfather-control-substrate/src/p02`, `packages/allfather-control-substrate/src/release-output/p02-adapter.ts`, `docs/allfather-bi/implementation/p02_promotion_record.md`. Loopia: `app/Product/P02`, `app/Substrate/M16`, `PROJECT_BOUNDARY.md`.

### P03 — Catalogue, ICP and fit qualification
Governed catalogue/fit/watch-list/priority/release paths exist; native structured size discrimination and owner-upload licence mapping need correction. Three canonical assurance profiles are not ported.

Canonical: `packages/allfather-control-substrate/src/p03`, `docs/allfather-bi/implementation/p03_promotion_record.md`. Loopia: `app/Product/P03`, `docs/PORT_SCOPE_MATRIX.md`.

### P04 — Internal role distribution
Role-class observations, target/redaction policy, reviewed internal package release and acknowledgement exist. Text is caller authored; no external sending.

Canonical: `packages/allfather-control-substrate/src/p04`, `docs/allfather-bi/implementation/p04_promotion_record.md`. Loopia: `app/Product/P04`.

### P05 — Internal marketing drafts
Draft/variant/review/release/audit services exist; text is caller authored. Title scan, Swedish lexical coverage and comparative semantic-support shortcomings were reproduced.

Canonical: `packages/allfather-control-substrate/src/p05`, `docs/allfather-bi/implementation/p05_promotion_record.md`. Loopia: `app/Product/P05`.

### M01 — Runtime invocation
PHP includes synthetic and guarded source-capture invocation, not the generic host/agent runtime.

Canonical: `packages/allfather-control-substrate/src/m01`. Loopia: `app/Substrate/M01`, `docs/PORT_SCOPE_MATRIX.md`.

### M02 — Tenant/workspace access
Tenant/workspace/role/data-scope evaluation is implemented.

Canonical: `packages/allfather-control-substrate/src/m02`. Loopia: `app/Substrate/M02`, `docs/PORT_SCOPE_MATRIX.md`.

### M03 — Capability contracts
Identity, active contracts, tool rights and forbidden clauses exist.

Canonical: `packages/allfather-control-substrate/src/m03`. Loopia: `app/Substrate/M03`, `docs/PORT_SCOPE_MATRIX.md`.

### M04 — Missions and proposals
Proposal envelopes, transitions and review paths exist; no complete autonomous product mission runner.

Canonical: `packages/allfather-control-substrate/src/m04`. Loopia: `app/Substrate/M04`, `docs/PORT_SCOPE_MATRIX.md`.

### M05 — Evidence and provenance
Source references, evidence, raw artifacts, bundles and missingness exist. Append-only lifecycle transition reconstruction remains canonical debt.

Canonical: `packages/allfather-control-substrate/src/m05`. Loopia: `app/Substrate/M05`, `docs/PORT_SCOPE_MATRIX.md`.

### M06 — Governed tool gateway
Registry, read dispatch, tier checks and idempotency exist.

Canonical: `packages/allfather-control-substrate/src/m06`. Loopia: `app/Substrate/M06`, `docs/PORT_SCOPE_MATRIX.md`.

### M07 — Independent Council review
Eligibility, producer-self-review refusal and frozen results exist. Fixture reviewer assessments were literal inputs, not actual AI judgement.

Canonical: `packages/allfather-control-substrate/src/m07`. Loopia: `app/Substrate/M07`, `docs/PORT_SCOPE_MATRIX.md`.

### M08 — Arbiter
Selected P01–P05 policy sets are ported and gate release.

Canonical: `packages/allfather-control-substrate/src/m08`. Loopia: `app/Substrate/M08`, `docs/PORT_SCOPE_MATRIX.md`.

### M10 — Decision ledger
Append-only release decisions, streams, supersession and reliance exist.

Canonical: `packages/allfather-control-substrate/src/m10`. Loopia: `app/Substrate/M10`, `docs/PORT_SCOPE_MATRIX.md`.

### M11 — Audit and replay
Core audit/incidents/no-live-effect replay exist; product audit coverage is selectively ported.

Canonical: `packages/allfather-control-substrate/src/m11`. Loopia: `app/Substrate/M11`, `docs/PORT_SCOPE_MATRIX.md`.

### M12 — Authorized surfaces
Governed read projections/readiness/redaction exist.

Canonical: `packages/allfather-control-substrate/src/m12`. Loopia: `app/Substrate/M12`, `docs/PORT_SCOPE_MATRIX.md`.

### M09 — Approval and frozen action
Only P07-A synthetic approval/frozen-artifact/short-lived authorization is included. General live consequential dispatch is excluded.

Canonical: `packages/allfather-control-substrate/src/m09`. Loopia: `app/Product/P07A`, `docs/PORT_SCOPE_MATRIX.md`.

### M15 — Governed ingestion services
Policy, egress, raw capture, normalize, candidate/admission, adapters, audits and replay exist. P01 direct capture and generic M15 ingestion are separate compositions.

Canonical: `packages/allfather-control-substrate/src/ingestion`, `docs/allfather-bi/implementation/m15_promotion_record.md`. Loopia: `app/Ingestion`, `docs/PORT_SCOPE_MATRIX.md`.

### LIVE_READ — HTTPS transport and actual source capture
Production transport leaf exists; Loopia roots do not construct it for active monitoring. Prior fixture and host capability proof is not live activation.

Canonical: `packages/allfather-control-substrate/src/ingestion/https-transport.ts`, `packages/allfather-control-substrate/src/m01`. Loopia: `app/Ingestion`, `app/Support/Bootstrap.php`, `worker/tick.php`.

### M16 — Internal release-output spine
Source-use profile → artifact → projection → visibility; P01/P02 integration exists. P03/P04/P05 use their own M10 projections. Customer/export/channel audiences are inactive.

Canonical: `packages/allfather-control-substrate/src/release-output`, `docs/allfather-bi/implementation/m16_promotion_record.md`. Loopia: `app/Substrate/M16`, `PROJECT_BOUNDARY.md`.

### UF — Universal Fetch architecture
Universal Fetch is docs-only, with no crawler/search/browser runtime. Existing M15 transport does not implement Universal Fetch.

Canonical: `docs/allfather-bi/implementation/universal-fetch/19_runtime_readiness_plan.md`. Loopia: `docs/PORT_SCOPE_MATRIX.md`.

### UP — Universal Port architecture
Connected-system/artifact reconstruction, OAuth/connector automation and twin interpretation are docs-only targets.

Canonical: `docs/allfather-bi/implementation/universal-port/19_runtime_readiness_plan.md`. Loopia: `docs/PORT_SCOPE_MATRIX.md`.

### PROMPT_CORE — PromptOps pure contracts and resolver
Canonical records, approval/window/digest resolver and promotion checks exist as pure runtime-inert components.

Canonical: `packages/allfather-control-substrate/src/promptops/records.ts`, `packages/allfather-control-substrate/src/promptops/resolver.ts`, `packages/allfather-control-substrate/src/promptops/promotion-check.ts`. Loopia: `docs/PORT_SCOPE_MATRIX.md`.

### PROMPT_CATALOGUE — Canonical prompt catalogue/store
Six advisory roles use generic runtime-inert templates and an in-memory/durable-store simulator, not a production DB or product reasoning prompts.

Canonical: `packages/allfather-control-substrate/src/promptops/in-memory-prompt-store.ts`. Loopia: `docs/PORT_SCOPE_MATRIX.md`.

### PRODUCT_AGENTS — P01 interpretation/relevance/battlecard/critic prompts
No evaluated product prompt suite or connected model-driven Monitor loop in either runtime. Human-authored service E2E proof does not fill this gap.

Canonical: `packages/allfather-control-substrate/src/promptops/in-memory-prompt-store.ts`, `README.md`. Loopia: `app/Product/P01`, `PROJECT_BOUNDARY.md`.

### M13 — Tenant adaptation and adaptive prompt binding
Full adaptive tenant/prompt/code adaptation modules are specifications, not the small pure PromptOps scaffold.

Canonical: `docs/allfather-bi/implementation/m13_adaptive_prompt_binding_spec.md`, `docs/allfather-bi/implementation/m13_m14_x01_promotion_record.md`. Loopia: `docs/PORT_SCOPE_MATRIX.md`.

### M14 — Performance learning and prompt optimization
Full performance-learning/automatic prompt mutation is not implemented or promoted.

Canonical: `docs/allfather-bi/implementation/m14_promptops_performance_learning_spec.md`, `docs/allfather-bi/implementation/m13_m14_x01_promotion_record.md`. Loopia: `docs/PORT_SCOPE_MATRIX.md`.

### PROVIDER — Product model provider dispatch
No connected product inference client/runner. Loopia SecretBox and credential schema are infrastructure only. Private earlier diagnostic is not app integration.

Canonical: `packages/allfather-control-substrate/src/promptops`, `README.md`. Loopia: `app/Provider/SecretBox.php`, `app/Support/Bootstrap.php`.

### SCHEDULER — Recurring Monitor execution
Loopia scheduler auth/job machine/runner exist, but product handlers are empty. No working recurring Monitor mission demonstrated.

Canonical: `README.md`. Loopia: `app/Jobs`, `worker/tick.php`, `app/Support/Bootstrap.php`.

### REPLAY — Connected product replay
Core and product service replay exists; connected synthetic service/HTTP proof passes. No model-generated mission trace yet.

Canonical: `packages/allfather-control-substrate/src/m11`, `packages/allfather-control-substrate/src/p01/assurance.ts`. Loopia: `app/Substrate/M11`, `app/Product/P01`.

### CONSOLE — Operator console and authoring
Loopia authenticated React reads support P01–P05/M10; numerous architecture descriptors are intentionally deferred. No complete onboarding/source/mission/review authoring journey yet.

Canonical: `packages/allfather-control-substrate/src/m12`. Loopia: `frontend`, `app/Http`, `docs/LOOPIA_CAPABILITY_REPORT.md`.

## Proposed dependency order

1. Freeze currentness and this matrix; runtime trees have no changes between pin and current canonical main. No blanket pin advance is needed to obtain runtime fixes.
2. Repair the shared P01 capture-text/significance binding, contract persistence and multi-workspace registration at the owning canonical source; port exact behavior and negative vectors to PHP.
3. Port bounded PromptOps pure contracts; add explicitly deployment-authored product prompt roles and offline evaluated inactive versions. Preserve full M13/M14 as future architecture.
4. Add provider adapter and bounded manual mission runner through existing M02/M03/M06/M01, M05 evidence, M04 proposals and review/release boundaries. Configure no live authority by construction.
5. Complete tenant/source intake, review and Monitor console controls; connect P01 to P04 internal preparation. Enable P02/P03/P05 only with their affected correctness/input gaps resolved.
6. Verify connected synthetic happy/negative/replay scenarios on the supported local DB; run owning/full checks and independent review. Distinguish fixtures from real model execution.
7. Prepare exact release, deploy inert staging, requalify host/UI/security/rollback and file current evidence. Bounded real-source/model activation remains explicit, separately evidenced; customer/production authority remains separate.

## Currentness and evidence limits

- Git main, not dated promotion headings, controls code existence. Promotion records retain historical statuses and must be read with implementation and exact scope.
- Current graph is composed overlay v24. Read graph.md descriptor as navigation, not a flat overlay registry or runtime promotion.
- State of Build retains TF7-08 lifecycle history, TF7-09 total-order review and documentary/activation debt. No blanket repair claim.
- Canonical runtime did not advance between Loopia pin and current main in packages/tools/scripts/tests. Missing Loopia capabilities are selective port/composition gaps or new requested implementation, not newer runtime commits awaiting sync.
- The user-requested agent-driven MVP requires bounded product prompts, provider and mission runner. Manual authored service execution does not complete it.
- Existing target-local strict5552/0/0, service27/0 and HTTP70/0/readback7 are reused proof of stated old baseline only; no fresh test or model/Loopia run is claimed.

The accompanying JSON records exact identities, validated source paths, file hashes, finding categories and dependency order. This first matrix records the pre-implementation baseline; new fixes must add fresh test and release evidence. Staging risk deviation remains E.1 FAIL_READABLE / E.3 UNVERIFIED. Nothing here authorizes external effects or production.
