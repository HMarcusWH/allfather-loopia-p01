# Monitor completion plan — three releases

**Adopted as the engineering plan of record:** 27 September 2026. **Status:** **P00–P06, P08, P10 and P11 are
merged** through PR30; merged main `0b8c17e9` is hosted-CI qualified by push run 37127292912
(8,942/0/0 on MariaDB 10.6.28 and 10.11.19), while P06 live operational evidence remains pending.
P07 is implemented on draft PR32 and hosted qualification is being refreshed after the scheduler-principal
provisioning repair; its remaining release gate is P06/ROAD_TEST_001 plus adoption of canonical PR369.
P09 is implemented on stacked draft PR33 with deployed-browser acceptance still operationally pending;
P12 remains conditional. A candidate implementation is not a merge, qualification,
deployment, funded model evaluation, activation or authority grant. The current state of the
application is
[`MVP_RELEASE_STATE.md`](MVP_RELEASE_STATE.md), not this plan.

The machine-readable form of the same work packages is
[`MONITOR_COMPLETION_WORK_PACKAGES.json`](MONITOR_COMPLETION_WORK_PACKAGES.json). Package IDs are not
reserved pull-request numbers. The package table below records the current implementation state;
for unimplemented packages, `ADD` paths remain proposed names rather than claims that files exist.

## The three releases

The completion is built as three releases on top of the existing Monitor — not another wholesale
port. `MonitorService`, `RunJournal`, `SourceCaptureStage`, `ContextEnvelope`, `ModelExecutor`, the
provider transport, the four prompts and the existing M01–M12/M15/M16 and product owners stay.

| Release | Packages | Outcome |
| --- | --- | --- |
| **1. Qualified manual native Monitor** | P00–P06, with P10 and P11 built early | real source capture and real model calls behind one execution-admission contract, both human reviews and the existing release controls, proven on the real host by one approved manual `ROAD_TEST_001` |
| **2. Operational internal beta** | P07–P09 | recurring observation without a second workflow engine or human impersonation, P03 post-outcome assurance, usable administration and richer owner-safe views |
| **3. Separately approved production release** | P12 | production identities, hosting boundary, customer visibility and operating procedures — adopted canonically first, then projected |

## Package status

| Package | Title | Depends on | State |
| --- | --- | --- | --- |
| P00 | One current-state record and one qualification procedure | — | **merged in PR23** |
| P01 | Execution admission and approved prompt selection | P00 | **merged in PR23** |
| P02 | Real-model evaluation lane that cannot release product intelligence | P01 | **merged in PR23; no funded evaluation run** |
| P03 | Native manual Monitor wiring and stand-down | P01, P02 | **merged in PR23; not deployed/admitted** |
| P04 | Repeatable operator-supported mission setup | P01 | **merged in PR23; setup grants no execution** |
| P05 | Recoverable model results and explicit interruption handling | P01, P03 | **merged in PR24; not deployed/activated** |
| P06 | Deploy, requalify and run the first approved manual road test | P00, P02, P03, P04, P05, P10 | **merged in PR26; merged main hosted-CI qualified; no newer Loopia deployment/evaluation/road-test evidence yet** |
| P07 | Recurring observation with bounded per-occurrence authority | P06 | **implemented in draft PR32**: migration 250, recurring observe-and-queue scheduler, canonical narrow service role port, governed scheduler-identity plan/provision/status/stand-down path and end-to-end offline coverage; no schedule admitted/deployed; merge still gated by P06/ROAD_TEST_001 and adoption of canonical PR369 |
| P08 | P03 assurance port and broader product-scope validation | P00 | **merged in PR30 as `0b8c17e9`; merged main hosted-CI qualified by run 37127292912, both lanes 8,942/0/0; not deployed, no audit run or schedule; expansion register rows stay OPEN** |
| P09 | Operator administration and richer views | P04, P05 | **implemented in stacked draft PR33 and hosted-CI qualified before the latest P07 base repair**; exact-head requalification follows P07 synchronization; deployed desktop/mobile browser journeys remain an operational gate |
| P10 | Independent backup and full restore | P00 | **merged in PR25; native/off-host/clean-target operational proof pending** |
| P11 | Version-aware master-key rotation | P00, P01, P10 | **merged in PR28; merged main hosted-CI qualified; no real staging rewrap performed** |
| P12 | Production identity and visibility through the canonical owners | P06, P10, P11 | conditional on canonical decisions not yet taken; inert scaffolding only, preserved on the diverged reference branch `claude/p12-production-preflight` (not in PR #30, not a PR base); **not complete** |

### What P00 delivered, and where it differs from the proposal

- [`MVP_RELEASE_STATE.md`](MVP_RELEASE_STATE.md) — the single current-state entrypoint, with a
  foundation test (`tests/foundation/release_state_currentness.php`) that fails when its canonical
  pin, deployed release, evidence-record count, migration count or lock flags stop matching the
  governed files.
- [`ops/ci/qualify-release.sh`](../ops/ci/qualify-release.sh) — every qualifying workflow step, now
  called by `.github/workflows/ci.yml` and by a `local` route that runs the same steps from a
  `git archive` export of one exact commit and writes a receipt.
- [`ops/ci/verify-qualification.php`](../ops/ci/verify-qualification.php) — the checks that were
  inline Python in the workflow, the receipt, and `verify-receipt`.
- Beyond the proposed file list: the check logic lives in `app/Ops/ReleaseQualification.php` so the
  unit layer can test it without spawning a process (`tests/unit/release_qualification.php`), and
  `tests/foundation/qualification_runner_boundary.php` fails the build when the workflow, the runner
  and the declared step list disagree.
- Semantics deliberately tightened while moving them: a lane must prove a *clean* install (every
  migration applied from empty) and a no-op second application with every migration `APPLIED`; the
  final gate requires the two lane reports to cover 10.6 and 10.11 exactly once each and to have run
  on the PHP line the workflow sets up; the hosted final gate now also writes a receipt. No check was
  loosened.
- Stale narrative repaired in place with dated notes rather than deleted: the P02/P03 web-surface
  classification, the capability report's P02 row, and the host model's pre-deployment blockers.

Branch protection, required checks and GitHub billing are repository settings, not effects of
editing `ci.yml`, and this change does not touch them.

## Audited baseline

- Loopia main when the plan was prepared: `8dbea16b262df7b6d40fe945f34e301eedf2aba6` (PR22).
- Canonical Allfather main inspected: `1c40306c22e15b2ad3049e8d32abff47450c8921`. Inspection does not
  move the projection pin.
- Projection pin: `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`, with selected repairs and imports
  recorded separately under `docs/reconciliation/`.
- Last evidenced deployed application: PR16 `392f48deef11380f6dccfe88f2a47ba1fd94575c`. The plan's
  preparation did not access the Loopia host or remeasure the deployment.

## Corrections that prevent duplicated or unsafe work

1. Basic P02/P03 browser indexes already exist: the PHP entrypoint registers their APIs, and P02's entity-context view renders entity/relationship indexes. Rich detail/read coverage remains incomplete. Do not build duplicate indexes because old scope tables say WEB_SURFACE_NOT_INCLUDED.
2. Native execution has several coordinated gates. `MonitorFactory::inactive()` remains available, while the deployed `forWorkspace()` composition stays inert unless an ADMITTED `MANUAL_MONITOR` grant exists; MonitorService checks nativeEnabled, PreparedModelRequest and ModelExecutor resolve the current approved prompt selection, and the transport rejects an inactive prompt. A constructor boolean or mode name cannot lawfully create execution authority.
3. Prompt evaluation needs its own bounded permission before prompts can be approved for product use. Evaluation is real model network activity, but it must not acquire source-fetch or product-release authority.
4. The current four model attempts are a lifetime mission budget. Recurring operation needs bounded renewal/delegation, not repeated run/mission IDs used as a counter reset.
5. The current manual workflow demands an exact displayed request digest and two human decisions. A scheduler generating and submitting the digest is not human inspection. Default scheduling remains observe/compare/queue until a separate policy authorizes more.
6. M10 is the common release authority. P01 adopts M16; P04 retains its own M10-referencing projection. Do not silently force P04/P03/P05 into an unimplemented universal M16 path.
7. Artifact/attempt/journal ownership stays separate. A valid historical hash chain is not a current permission or proof of useful model reasoning.

## End-state wiring

```text
Existing session + Kernel + CSRF
            |
  MonitorController / private operator CLI
            |
       MonitorService ----------------------> RunJournal + RunArtifacts
            |                                      (history, not authority)
            +--> ExecutionAdmission [new]
            |      external reviewed authority + current M02/M03/M04 scope
            |      exact release/config/prompt/model/credential/source/target/budgets
            |
            +--> SourceCaptureStage --> P01 Capture --> M06 --> M01
            |                                              |
            |                                      guarded source transport
            |                                              |
            |                                  M05 + retained evidence bytes
            +--> ChangeDetection + ContextEnvelope
            |
            +--> PromptSelectionResolver [new]
            |      ProductPromptPack immutable content + exact approval
            +--> PreparedModelRequest --> existing exact inspection
            +--> ModelExecutor --> M06 --> M01 --> CredentialStore --> provider
            |      |                                           one bounded call
            |      +--> durable attempt/result/artifact binding [extended]
            +--> classifier --> draft --> draft critic
            +--> human decision #1 --> ReleaseStages --> M07/M08/M10 --> P01/M16
            +--> P04 proposal --> routing critic
            +--> human decision #2 --> ReleaseStages --> M07/M08/M10 --> P04 projection
            +--> existing authorized console reads + read-only replay

Separate MODEL_EVALUATION grant
   --> EvaluationFactory/Runner [new] --> SAME prompt/request/provider/checks
   --> named synthetic cases + bounded real API calls + human scoring
   --> evaluation receipts, NEVER product release

Later: approved schedule --> occurrence reservation --> typed MONITOR_STEP job
   --> SAME MonitorService with a SERVICE principal, not human impersonation
   --> capture/diff --> wait for request inspection and human reviews
```

## Contracts before convenience

Execution authorization, prompt approval and deployment qualification are distinct records. Permission may narrow by workspace, source, target, time, model and budget; it never becomes broader because an operator saved a configuration or a model returned good JSON. The existing M02/M03/M04/M06/M01/M07/M08/M10 checks remain in force. A new local record must reference an actual adopted owner decision, not invent a new constitutional grant system.

The first native contract should allow only two purposes: evaluation against a frozen case manifest, and one manual Monitor mission against a frozen configuration. Recurring operation is a later scoped extension. External publishing, customer-message sending, CRM mutation, payments and adaptive M13/M14 activation remain excluded.

## Delivery strategy

Build P00/P01 first. P02 (evaluation), P04 (setup), P05 (durability) and P10 (backup) can proceed on dependency-respecting branches. P03's native product path can be implemented/tested while evaluation runs are awaiting authorization, but cannot be activated until actual evaluation and approval are complete. P06's final host/road-test milestone depends on the selected merged control set, not the original PR22 SHA. P11 is early operations work, not a last-minute production checkbox. P07 follows a successful manual road test. P08 and selected P09 details gate broader customer products. P12 starts with canonical decisions, not local enum edits.

Each package should be split further where a migration, authority contract or browser expansion needs separate review. Do not combine production promotion with a runtime convenience fix. Merge permission and live-operation permission are separate.


## P00 — Freeze the implementation baseline, repair currentness and make qualification reproducible

**Dependencies:** audited baseline.

### Add

```text
docs/MVP_RELEASE_STATE.md
docs/MONITOR_COMPLETION_PLAN.md
ops/ci/qualify-release.sh
ops/ci/verify-qualification.php
```

### Change

```text
README.md
docs/PORT_SCOPE_MATRIX.md
docs/LOOPIA_CAPABILITY_REPORT.md
docs/LOOPIA_HOST_MODEL.md
docs/BETA_RUNBOOK.md
docs/DEPLOYMENT_RUNBOOK.md
.github/workflows/ci.yml
```

### Implementation and wiring

Record source head, tested tree, currently evidenced deployed application, exact source manifests and explicit inactive controls independently. Do not promote the old canonical pin just to match a recent commit date. Preserve dated findings but label superseded narrative clearly: basic P02/P03 React indexes already exist; model execution is built but inactive; full TOM/adaptive PromptOps remains excluded.

Factor reusable qualification commands into one local runner that the workflow can also call. It invokes the existing test runner, actual supported MariaDB lanes, exact Git-object release preparation, frontend build, byte comparison and copied-tree checks. Reports bind source SHA/tree, commands, PHP/DB/tool versions, exit codes and external/local execution identity. Do not manufacture hosted PASS statuses or treat a trusted-looking JSON receipt as independent verification.

Repository branch-protection and billing changes are operator/GitHub settings, not effects of editing ci.yml. Configure required reviews/checks only with an actually available validation route; never impose an unavailable hosted check and then normalize bypassing it.

### Acceptance tests

- Re-run all existing qualification paths and compare runner output with current workflow semantics.
- Ensure an intentionally failed/skipped lane prevents aggregate success.
- Prove build-owned files come from the selected Git object, not a dirty checkout.

**Completion:** One current-state entrypoint and one reproducible qualification procedure; no new runtime behavior or false deployment claim.

**Gate:** Implementation and offline testing only; activation is separate.


## P01 — Add one execution-admission contract and separate immutable prompt content from permission to use it

**Dependencies:** P00.

### Add

```text
app/Workflow/ExecutionGrantStore.php
app/PromptOps/PromptSelectionResolver.php
app/PromptOps/PromptApprovalStore.php
app/Provider/ModelPolicy.php
ops/monitor/authorization.php
database/migrations/210_monitor_execution_controls.sql
docs/MONITOR_EXECUTION_AUTHORIZATION.md
tests/unit/monitor_execution_admission.php
tests/unit/monitor_prompt_selection.php
tests/integration/monitor_execution_grants.php
tests/security/monitor_grant_revocation.php
```

### Change

```text
app/PromptOps/ProductPromptPack.php
app/Provider/ModelRequestBuilder.php
app/Provider/PreparedModelRequest.php
app/Provider/ModelExecutor.php
app/Workflow/MonitorService.php
app/Substrate/M06/Gateway.php
app/Substrate/M01/Invocation.php
docs/reconciliation/monitor-scoped-extension-2026-09-27.json
tests/fixtures/schema_table_inventory.json
tests/integration/schema_invariants.php
```

### Implementation and wiring

Introduce deployment-specific, immutable authorization records and append-only lifecycle events. Their authority comes from an explicitly reviewed owner decision under adopted canonical contracts; a database row, CLI command, source document or model cannot mint authority merely by asserting approval. The importer validates the reviewed authority reference/hash and the importing operator's current permissions. Missing canonical authority means a required upstream decision, not a local invented permission. Neither this store nor its resolver writes M10 product releases.

A grant binds purpose (MODEL_EVALUATION or MANUAL_MONITOR initially), tenant/workspace, mission/config digest or approved evaluation-case manifest, authorized principal, source scope, permitted source/model transports, exact prompt/content/schema/binding hashes, fixed model policy, credential identity, target/redaction basis, input/output/attempt limits, validity window, owner decision and disclosure/retention scope. Bind the deployed application release/fingerprint separately from evidence-only documentation commits. Lifecycle: proposed -> admitted -> suspended/revoked/expired, using immutable definition plus append-only status events; no browser enable checkbox.

Keep the existing prompt JSON as immutable content. Resolve an exact approved selection for the operation; never select the latest role match or trust a caller-recomputed digest. Approval/revocation is not encoded by editing historical prompt bytes. A new prompt revision gets new identity and evaluation. Historical replay uses historical identities while fresh execution consults current revocation.

Replace direct static ProductPromptPack lookups in MonitorService, PreparedModelRequest and ModelExecutor with the same injected, trusted resolver. Carry the selected approval/grant identity in local provenance and M06/M01 bindings. PreparedModelRequest still freezes exact serialized body and provenance. ModelPolicy centralizes the existing closed model/endpoint/token choices; initially do not add extra models or raise limits.

ExecutionAdmission is a validator of existing/current authority, not a replacement for M02/M03/M04/M06/M01. Check it before reserving an attempt, immediately before dispatch, after return before accepting a candidate, and before each human release. Revocation during an in-flight call cannot recall bytes already sent; preserve the truthful attempt receipt and block downstream acceptance/release.

### Acceptance tests

- No grant; wrong principal/tenant/workspace/mission; expired/revoked grant; changed prompt/schema/model/credential/source/target/definition.
- A user-constructed ACTIVE prompt array or recomputed digest must not acquire authority.
- Concurrent attempt reservation never exceeds mission AND grant caps.
- Request inspection digest changes whenever the effective approved request/provenance changes.
- Revocation after preview and during the call prevents downstream progression.
- All current fixture and inactive behavior remains available without live transport.

**Completion:** One typed, testable path for validating native/evaluation permission; default remains inert.

**Gate:** Approve the narrow execution contract and its canonical ownership. Importing an actual admitted grant requires a separately recorded owner act; this plan grants none.


## P02 — Build a real-model evaluation lane without activating the product

**Dependencies:** P01.

### Add

```text
app/Evaluation/MonitorEvaluationRunner.php
app/Evaluation/EvaluationReport.php
app/Evaluation/EvaluationFactory.php
ops/evaluation/monitor.php
docs/MONITOR_MODEL_EVALUATION_PROTOCOL.md
tests/fixtures/monitor_model_eval_cases.json
database/migrations/220_monitor_evaluation_records.sql
tests/integration/monitor_evaluation_boundary.php
tests/unit/monitor_evaluation_report.php
```

### Change

```text
app/Provider/ModelExecutor.php
app/Provider/PreparedModelRequest.php
app/Provider/OpenAIResponsesTransport.php
docs/PROMPTOPS_PORT.md
docs/BOUNDED_MODEL_BOUNDARY.md
tests/fixtures/schema_table_inventory.json
tests/integration/schema_invariants.php
```

### Implementation and wiring

Resolve the approval/evaluation dependency explicitly: a short-lived MODEL_EVALUATION grant permits named DRAFT prompts against a frozen synthetic/non-sensitive case set. It does not make those prompts approved for product use. EvaluationFactory supplies no live source transport and no P01/P04 release service. It invokes the same request construction, provider parser, candidate checks and governed M06/M01 path as the product, not a direct curl bypass.

Store actual provider network provenance separately from material provenance: a live API call using synthetic inputs is LIVE + EVALUATION, not a real-world product mission. Add an explicit execution-purpose linkage without rewriting historical LIVE/FIXTURE receipts. Evaluation records reference existing attempts and private content-addressed artifacts, not a parallel evidence truth store.

Freeze development and held-out cases before tuning. Cover Swedish qualifiers/negation, English material, before/after changes, no business change, unavailable content, unverified owner assertions, conflicting evidence, bad/foreign citations, unsupported title/summary claims, role mismatch and source/profile/prior-candidate injection. Grounding and useful reasoning get independent human assessments. Do not use an author or critic model's self-score as the acceptance decision. Record abstention/rejection rates as well as useful outputs, latency, token usage and incomplete responses.

Define the scorecard before native evaluation: zero observed critical scope/unauthorized-action/privacy failures in the declared suite; explicit per-category quality thresholds and required human adjudication. Finite passing examples are not universal guarantees. The current gpt-4.1-nano setting is a test candidate, not an already-qualified quality choice. A model or budget change requires a new approved policy and rerun.

Use the official Responses contract to test supported strict schemas, refusals and incomplete output. Verify native-only transport behavior on the actual PHP versions; a fixture does not execute cURL. Review provider-side data controls separately from store=false.

### Acceptance tests

- Offline tests do not call the network.
- Evaluation grant cannot fetch arbitrary sources or reach product review/release methods.
- Same parser/schema failures and quota/timeout behavior as product execution.
- Missing/failed held-out cases cannot produce an APPROVED report.
- A generated evaluation report never activates prompts or the Monitor.

**Completion:** Frozen evaluation harness first; actual funded run and human-reviewed evidence second; exact prompt/model policy then eligible for manual-pilot approval.

**Gate:** Live evaluations require a separate bounded API-spend and outbound-data authorization; use synthetic/non-sensitive cases first.


## P03 — Wire the native manual Monitor and the stand-down path

**Dependencies:** P01, P02.

### Add

```text
ops/monitor/preflight.php
ops/monitor/stand-down.php
tests/integration/monitor_native_composition.php
tests/security/monitor_native_boundary.php
```

### Change

```text
app/Workflow/MonitorFactory.php
app/Workflow/MonitorService.php
app/Workflow/SourceCaptureStage.php
app/Workflow/MissionConfiguration.php
app/Provider/OpenAIResponsesTransport.php
app/Http/Controllers/MonitorController.php
app/Http/templates/monitor.php
public_html/index.php
app/Ops/StandDownPosture.php
ops/deploy/preflight.php
ops/rollback/verify-stand-down.php
tests/foundation/no_external_action_surface.php
tests/foundation/deployment_boundary.php
```

### Implementation and wiring

Retain MonitorFactory::inactive() and forFixture(). Add a native construction path using CurlHttpsTransport, DnsHostResolver and the existing model adapter, admitted only for an exact approved operation. Do not merely change either constructor boolean to true. Physical transport and semantic authorization must agree.

HTTP/CLI composition can construct an inert inspector without permission to execute; every mutation resolves current admission for its run. Existing Kernel/session/CSRF behavior stays authoritative. Protect action, run ID, request digest and human assessment as body-only inputs. No provider/model/credential/tenant selector is accepted from arbitrary browser parameters.

Recheck native permission at NEW/capture, each model stage, P01 release and P04 routing release. Keep all four exact request inspections and both human decisions. A failed admission returns its reason without spending an attempt or fabricating success. If owner source rights revoke mid-flight, preserve a failed/quarantined receipt and prevent downstream use.

Preflight distinguishes INERT_STAGING from MANUAL_PILOT and later approved recurring profiles. Existing inert preflight must continue to require no live execution. A mode name alone is never permission; check its external reviewed authority and actual composition. Narrow the existing static tripwires to the single admitted constructor boundary, add dynamic negative tests and keep unrelated external actions forbidden.

Stand-down revokes execution permission and stops new reservations/captures. It does not delete artifacts, alter an original review, refund consumed attempts or automatically invalidate all historical releases. Retraction of an already released briefing remains an explicit existing M10 correction/withdrawal operation.

### Acceptance tests

- Default deployment invokes zero source/model network calls.
- Native request without approved prompts/grant is refused at service and dispatch boundaries.
- Browser cannot activate, select a foreign credential or forge scope.
- Two overlapping advances cannot double-call or double-release.
- Stand-down between stages prevents the next external call, without rewriting history.

**Completion:** An authorized manual native path exists, but deployment/GO remain separate.

**Gate:** Implementation and offline testing only; activation is separate.


## P04 — Make real mission setup repeatable and operator-supported

**Dependencies:** P01.

### Add

```text
app/Workflow/MonitorSetup.php
ops/monitor/setup.php
ops/monitor/import-profile.php
ops/provider/credential.php
tests/integration/monitor_setup.php
tests/security/monitor_setup_scope.php
```

### Change

```text
ops/monitor/configure.php
app/Workflow/MissionConfiguration.php
app/Provider/ModelRegistration.php
app/Provider/CredentialStore.php
docs/MONITOR_WORKFLOW.md
```

### Implementation and wiring

Compose existing M02/M03/M04/P01/P04 setup owners rather than writing their tables directly. Resolve/create the explicitly requested scope, admitted company-profile upload, monitored source/card, active mission/assignments, capture/model descriptors and configured internal target. Return a dry-run plan before changes. Roles are only granted by an explicit authorized membership act, never by making an idempotent setup command succeed.

Import profiles through EvidenceStore/M05 and the existing P01 CompanyContext semantics. Preserve original byte digest and TENANT_PROVIDED status. New profile versions append new evidence and require a new frozen mission configuration/approval; do not overwrite the context of an existing run or create a second TOM/company database.

The setup receipt distinguishes compatible reuse, newly provisioned objects and conflicts. A revoked/narrowed old registration refuses instead of regranting itself. Existing configuration is immutable per mission; changes create an explicit successor, never rewrite old definition_json. Normalize operator scope versus actor scope at the service boundary rather than passing mismatched array keys between services.

Provider credential CLI accepts secrets via private stdin, not argv/query/JSON report. Reuse CredentialStore save/revoke authorization. Saving a replacement key currently rotates the tenant's other active OpenAI key; report the affected credential identities and require explicit acknowledgement. A new credential does not silently retarget frozen missions/grants. Source review/terms approval is supplied evidence and an authorized decision, not something setup infers from a reachable URL.

### Acceptance tests

- Repeated compatible setup is idempotent; conflicts and revoked rights remain refusals.
- Foreign profile/source/target/credential and stale mission bindings are refused.
- No secret appears in dry-run, error, command line, report or support log.
- A profile replacement cannot change a historical prepared request.

**Completion:** One ordinary SME can be configured without hand-edited SQL or copied fixture identities; setup still grants no native execution.

**Gate:** Implementation and offline testing only; activation is separate.


## P05 — Persist recoverable model results and make interruption handling explicit

**Dependencies:** P01, P03.

### Add

```text
app/Provider/ModelArtifactStore.php
app/Workflow/RunReconciliation.php
ops/monitor/reconcile.php
database/migrations/230_monitor_attempt_recovery.sql
tests/integration/monitor_crash_recovery.php
tests/security/monitor_reconciliation_scope.php
```

### Change

```text
app/Provider/ModelExecutor.php
app/Workflow/RunArtifacts.php
app/Workflow/RunJournal.php
app/Workflow/MonitorService.php
app/Workflow/SourceCaptureStage.php
tests/fixtures/schema_table_inventory.json
tests/integration/schema_invariants.php
```

### Implementation and wiring

Close the receive-result/persist-candidate gap deliberately. Persist validated candidate bytes privately with content-addressed identity, bind those bytes to the durable provider result/attempt, then advance the run journal. Do not label a response successful if final provenance could not be recorded. Retain existing M06/M01/tool-result receipts as the authoritative dispatch history.

Reconciliation starts read-only: show exact attempt, request, grant, candidate-artifact, tool outcome and run revision. Adopt a completed result only when all stored bytes and digests match the original request and the operator explicitly authorizes the continuation under current scope. Do not regenerate missing candidate text from a digest, call the provider to reconstruct it, or claim success from a generic telemetry row.

If bytes/outcome are unavailable, record UNRESOLVED/stop and require a separately authorized continuation or new mission. Consumed budget remains consumed. Do not reopen a terminal history row: append a reconciliation record and, where supported, a versioned continuation linked to the original event chain and attempt. Keep v1 replay semantics unchanged for old rows.

Exercise both same-run and same-source concurrency. RunJournal already uses a session advisory lock rather than an open SQL transaction across network calls. Preserve that property. If source-baseline selection needs additional serialization, use the same bounded source-scoped lock discipline rather than holding a SQL write transaction through HTTP. Add host proof for advisory-lock availability/ownership/release.

### Acceptance tests

- Crash before dispatch; after reservation; after response; after artifact persistence; after final receipt; after journal transition.
- Each known result is consumed at most once and an unknown attempt is never automatically retried.
- Recovery cannot exceed original budget or cross tenant/workspace/request identities.
- Two runs against one source cannot race into an incorrectly represented before/after pair.
- Old journal events still replay under their original digest format.

**Completion:** Known durable results are recoverable; unknown outcomes stay honestly unresolved without repeat spending.

**Gate:** Implementation and offline testing only; activation is separate.


## P06 — Deploy, requalify, evaluate on-host and execute the first approved manual road test

**Dependencies:** P00, P02, P03, P04, P05, P10.

### Add

```text
ops/staging-monitor-smoke.php
ops/road-test/export-monitor-evidence.php
docs/ROAD_TEST_001_MONITOR.md
tests/security/monitor_deployed_surface.php
```

### Change

```text
ops/staging-smoke.php
database/migrations/230_monitor_attempt_recovery.sql
ops/deploy/preflight.php
ops/rollback/verify-stand-down.php
app/Workflow/NativeOperations.php
app/Ops/StandDownPosture.php
docs/DEPLOYMENT_EVIDENCE_MODEL.md
docs/BETA_RUNBOOK.md
docs/DEPLOYMENT_RUNBOOK.md
docs/MVP_RELEASE_STATE.md
docs/MONITOR_COMPLETION_PLAN.md
docs/MONITOR_COMPLETION_WORK_PACKAGES.json
tests/foundation/release_state_currentness.php
tests/foundation/beta_operations_boundary.php
tests/rollback/stand_down_verifier.php
tests/security/monitor_native_boundary.php
```

PR26 deliberately reuses the existing Loopia probe and existing deployment-evidence vocabulary. It
does not edit `deployment-evidence.json`, `upstream.lock.json` or
`app/Ops/DeploymentEvidence.php` before new observations exist.

Hosted CI resumed during PR26 and exposed two merged qualification blockers outside P06's new
runtime surface. PR26 therefore also repairs them rather than hiding them behind a local-only run:
`230_monitor_attempt_recovery.sql` had a malformed trigger delimiter and could never complete, and
the beta-operations structural test still counted two pre-Kernel refusals after P10 added the third
maintenance refusal. No new migration number is introduced; the 230 edit repairs SQL that cannot be
recorded as successfully applied in its broken form. Any database left in a partial-DDL state by an
earlier failed 230 attempt must be discarded/recreated or explicitly recovered; do not pretend DDL
rolled back.

### Implementation and wiring

PR16 remains the last evidenced inert staging deployment while this source work is reviewed. After
P06 source merges, freeze and deploy that newer exact candidate; do not inherit PR16 host evidence
or cite the old PR22 planning SHA as qualification for changed code.

Preserve private env/storage, prepare from Git, publish generated assets manifest-last, apply the
candidate migration set on the disposable qualification DB first, and prove the second application
is a no-op. Migration 230 is the one exceptional repair in this PR because its merged delimiter
error made successful application impossible; staging PR16 has never applied it. Any database that
experienced a failed partial 230 DDL attempt is not treated as rolled back. Run the strict host suite
against the disposable qualification DB only. Reuse the existing advisory-lock and filesystem-lock
host checks; native/network diagnostics remain separately authorized. Production/main static-site
files remain untouched.

Extend the existing hardened HTTPS smoke with a Monitor-specific negative mode rather than adding a second HTTP client. The deployed smoke proves workbench reachability, session-scoped workspace behavior, body-only mutation identity and CSRF/missing-run refusals without ever holding a valid run that could fetch or call a model. Existing offline security/integration tests continue to pin stale request/review digests, cross-workspace scope, model/source escaping and authority revocation. The actual authorized ROAD_TEST_001 then exercises the four inspected requests and both review forms manually.

Once actual prompt/model evaluation passes and the owner admits the exact manual-pilot grant, run baseline and changed captures, four inspected inference requests, two human decisions, P01/P04 releases and read-only replay. Validate the baseline is retained under the approved policy; do not mistake fixture pages or a provider authentication diagnostic for the full road test.

The exporter reads the run, grant/prompt identities, provider receipts, human-review event/artifact identities, M10 references, M16/P01 release references and P04 release references; it emits a sanitized summary of execution mode, digests, counts and current reliance without emitting source/model/review text. It never auto-files a PASS or changes the upstream lock/evidence registry. Human-reviewed evidence-only commits file observations, including failures and stand-down. Extend the registry vocabulary only through a versioned reviewed schema if evaluation/restore/key-rotation record kinds are added; observed events still grant no authority.

### Acceptance tests

- Real-host strict suite and Monitor HTTPS negatives at the final exact candidate.
- Real browser inspection on desktop/mobile for both review boundaries.
- Backup/restore and code rollback preserve populated Monitor/M10 state.
- The evidence exporter cannot leak profiles, prompts containing tenant data, credentials or arbitrary file paths into a public-safe report.
- A failed road test can establish executed=true without pass=true.

**Completion:** Qualified manual native Monitor mission with actual receipts, two human reviews, internal output, replay and demonstrated stand-down.

**Gate:** Host operations, secrets, funded evaluations and ROAD_TEST_001 each require their applicable explicit operator/owner authorization. None executes as a side effect of merging code.


## P07 — Add recurring observation with bounded per-occurrence authority and honest human pauses

**Dependencies:** P06.

### Add

```text
app/Workflow/MonitorPrincipal.php
app/Workflow/ScheduleService.php
app/Workflow/ScheduleDispatcher.php
app/Jobs/MonitorStepHandler.php
ops/monitor/schedule.php
ops/scheduler/invoke-remote.sh
database/migrations/250_monitor_schedule_control.sql
docs/MONITOR_SCHEDULING.md
tests/integration/monitor_schedule.php
tests/security/monitor_schedule_concurrency.php
tests/integration/monitor_schedule_budget.php
```

### Change

```text
app/Jobs/JobMachine.php
app/Jobs/SchedulerRunner.php
worker/tick.php
public_html/scheduler.php
app/Workflow/MonitorService.php
app/Workflow/MonitorOperations.php
app/Workflow/RunJournal.php
app/Workflow/MissionConfiguration.php
app/Workflow/SourceCaptureStage.php
app/Workflow/ContextEnvelope.php
app/Http/templates/monitor.php
tests/fixtures/schema_table_inventory.json
tests/integration/schema_invariants.php
```

### Implementation and wiring

Do not just populate the empty handler array. JobMachine and RunJournal have different state models and attempt semantics. Add a MONITOR_STEP job type with its own small execution graph, rather than replaying the old monitoring-job graph as a second business pipeline. Journal stages remain owned solely by MonitorService/RunJournal. A job names run ID, expected revision/stage and schedule occurrence; it cannot carry a callback, raw SQL, arbitrary URL or executable workflow definition. Define successful waiting-for-operator separately from job failure or product release.

Reserve each occurrence using a unique (schedule ID, schedule version, scheduled time) identity. Bounded catch-up, non-overlap, source-policy cadence floors, expiry, stand-down and a parent grant cap apply. The occurrence may create its own mission only under explicitly admitted delegation and a total schedule budget; new identities are not an automatic refill of the current four-call lifetime mission budget.

Do not impersonate the initiating human from cron. Add a typed OPERATOR/SERVICE execution principal using current M02/M03 scope and assignments; record the delegating operator separately. Change forced-OPERATOR execution scope only through reviewed owner-compatible integration. Human review accepts OPERATOR only. Journal actor format changes require v2 events and compatible v1 verification, not rewriting historical hashes. If service delegation needs a canonical authority extension, land it upstream first.

Default recurring mode is observation/capture/diff and queueing the exact next request for an operator. It stops at model-request inspection and at both human review stages. Automatically supplying a newly generated digest is not human inspection. Fully unattended model inference would require a distinct owner-approved request-inspection/disclosure policy and must be represented honestly as policy-approved, not human-inspected; it is not enabled by this scheduling work.

Prefer the already documented external SSH scheduler route if selected and qualified. invoke-remote.sh calls the private tick with a dedicated authorized identity; keep the authenticated HTTP alternative intact. The invocation identity must not be represented as site-isolated where SSH is account-wide. The scheduler returns a minimal receipt; operational alerts initially go to an internal console health/inbox surface, not a new unapproved email/SMS channel.

### Acceptance tests

- Duplicate ticks create one occurrence and no double capture.
- Successful stages do not exhaust a generic three-attempt retry counter before workflow completion.
- Waiting for inspection/review releases the lease without repeated calls.
- Expired/revoked parent permission and exhausted grant budget stop new occurrences.
- A scheduler service cannot record a human review or bypass an inspected-request requirement.
- Two schedules targeting one source are serialized or refused before conflicting acquisition.
- Outage catch-up is bounded; no replay storm.

**Completion:** Recurrent bounded observation works, with visible human queues and no silent conversion into autonomous AI/release authority.

**Gate:** Scheduling and service delegation require a separate admitted policy. Unattended model execution is an additional decision, not assumed.


## P08 — Complete P03 assurance and validate broader product scope before expanding use

**Dependencies:** P00.

### Add

```text
app/Product/P03/Assurance.php
ops/assurance/p03.php
tests/conformance/p03_assurance.php
tests/integration/p03_assurance.php
tests/fixtures/p03_assurance_vectors.json
tests/fixtures/p03-assurance-golden-generator.mts
docs/P03_ASSURANCE_PORT.md
```

### Change

```text
app/Product/P03/Registration.php
app/Substrate/M11/Assurance.php
docs/PORT_SCOPE_MATRIX.md
docs/CONFORMANCE_MATRIX.md
docs/CLAIM_CEILING.md
```

### Implementation and wiring

Port the existing canonical p03/assurance.ts profiles: CATALOGUE_FRESHNESS, SIGNAL_PAIRING_FRESHNESS and BUYER_INTENT_INVARIANT. Reuse the existing M11 audit profiles/runs/checks/findings/incidents. These are post-outcome scans, not release authority or substitutes for M08. Add registration/operator sweep and, once scheduling is admitted, bounded audit jobs. Do not introduce a new assurance store.

Generate parity vectors by running the frozen canonical implementation on identical fixtures. Include stale/withdrawn signal, expired/non-approved catalogue/card, unsupported buying-trigger classification, inactive/wrong-category profile, foreign scope and no mutation of audited rows. If PHP/category schema additions are required, add a fresh migration; do not edit an applied one.

For P02/P03/P05 expansion, run the previously named scope/currentness/citation/title/Swedish refusal scenarios against current code and distinguish actual defects from presentation or missing semantic-evaluation support. Repair verified shared defects upstream first, then port. No blanket assertion that every old audit concern is fixed follows from the Monitor delivery. In particular a syntactically present citation does not prove entailment. Do not expose wider P05 authoring without the relevant reviews and truthful claim controls.

### Acceptance tests

- Canonical/PHP golden parity and all three negative audit families.
- Audits append findings/incidents but never change source/proposal/release rows.
- P02/P03/P05 scope/citation/currentness regression suite before feature expansion.

**Completion:** P03 post-outcome assurance is available before launching that product to customers; P01-only manual pilot need not wait for optional expansion.

**Gate:** Implementation and offline testing only; activation is separate.


## P09 — Complete operator administration and richer views without duplicating the workbench

**Dependencies:** P04, P05.

### Add

```text
app/Http/Controllers/MonitorSetupController.php
app/Http/Controllers/MonitorScheduleController.php
app/Http/ReadAdapters/MonitorReadAdapter.php
app/Http/ReadAdapters/P02SnapshotDetailAdapter.php
app/Http/ReadAdapters/P03ProspectDetailAdapter.php
app/Http/templates/monitor-setup.php
app/Http/templates/monitor-schedule.php
frontend/console/src/views/p01/monitor-runs.jsx
frontend/console/tests/monitor-reads.test.js
tests/security/monitor_administration.php
tests/security/product_detail_reads.php
```

### Change

```text
app/Http/Controllers/MonitorController.php
app/Http/Controllers/ConsoleReadController.php
app/Http/templates/monitor.php
app/Http/templates/layout.php
public_html/index.php
frontend/console/src/data/http-console-data-source.js
frontend/console/src/data/live-read-adapters.js
frontend/console/src/data/surface-reads.js
frontend/console/src/data/navigation.js
frontend/console/src/views/p02/entity-context.jsx
frontend/console/src/views/p03/prospect-queue.jsx
frontend/console/src/views/p03/prospect-battlecard.jsx
public_html/assets/console.css
docs/LOOPIA_CAPABILITY_REPORT.md
```

### Implementation and wiring

Basic P02/P03 React index views and API routes already exist. Extend them with actual owner-safe detail, lineage, freshness, confidence/uncertainty and current reliance; do not add a second set of list endpoints or expose a placeholder relationship picture as real entity data. If a detail owner method is absent, add a scoped getter to the owning release service rather than selecting raw rows from a controller. Exact getter changes are determined by that narrow owner-read review.

Keep exact request inspection and both human mutation forms in the current scriptless SSR workbench for the first beta. Add structured sections: company/source basis, before/after evidence, model findings, critic concerns, request disclosure, current permission/refusal, review history and historical-versus-current release state. Keep full exact JSON available behind an expander rather than making it the only readable screen.

Add SSR setup/scheduling forms over MonitorSetup/ScheduleService; do not implement alternative business logic in JavaScript. Register only explicit same-origin routes. Proposed reads: /console/api/v1/monitor/runs, /console/api/v1/monitor/run, /console/api/v1/p02/entity-detail and /console/api/v1/p03/prospect-detail. Proposed mutations: /console/monitor/setup and /console/monitor/schedule. Query identifiers are selectors only; all scope comes from the session and the owner checks each object.

Prompt administration is initially inspection plus explicit reviewed approval/withdrawal workflow, not a free-text live prompt editor. Credential operations stay private CLI until a separately reviewed write-only credential form is explicitly in scope. User membership administration, account self-service and invitation delivery require their own permission/channel decisions; no general public signup is introduced here.

Generate assets through the existing frontend build; never hand-edit content-hashed bundles. Maintain CSP, no automatic retry/prefetch on audited views, empty-versus-denied distinction, mobile usability, keyboard access and escaped source/model text.

### Acceptance tests

- Object-level authorization for every detail read, including cross-workspace references.
- No fetch/model/release on GET/refresh and no automatic mutation retries.
- Exact request/review digests remain mandatory through new layouts.
- Model-supplied HTML/scripts render as text.
- Existing P02/P03 indexes remain functional with no duplicate routes.
- Synthetic visual checks plus actual deployed-browser journeys.

**Completion:** An operator can configure and operate the selected product without SQL, inspect its evidence and distinguish held/withdrawn/history states. Wider self-service SaaS is not implied.

**Gate:** Implementation and offline testing only; activation is separate.


## P10 — Build and prove independent backup/full-restore tooling

**Dependencies:** P00.

### Add

```text
app/Ops/BackupManifest.php
app/Ops/MaintenanceGate.php
ops/backup/export.sh
ops/backup/restore-rehearsal.sh
ops/backup/verify-restore.php
docs/BACKUP_RESTORE_RUNBOOK.md
tests/rollback/full_restore.php
tests/security/backup_boundary.php
```

### Change

```text
app/Http/Kernel.php
ops/rollback/verify-stand-down.php
docs/DEPLOYMENT_RUNBOOK.md
docs/DEPLOYMENT_EVIDENCE_MODEL.md
```

### Implementation and wiring

Add an explicit maintenance/quiescence mechanism for the MVP backup window rather than assuming no writers exist. Prevent new product/auth/database mutations and scheduler dispatch while preserving minimal safe health/request-reference behavior. Authorization to enter maintenance is operator-only and distinct from semantic release withdrawal. Alternatively a later version may prove coordinated DB snapshot plus immutable-object capture; do not assume that proof today.

Use operator-side native MariaDB dump/import tooling, not a PHP web SQL-restore endpoint. Export a consistent database snapshot, referenced private content-addressed blobs, schema/migration digests, trigger definitions, release/asset identity and a request-log prefix manifest. Encrypt the backup and retain it independently from the host. Keep master-key recovery material in a separate protected store; do not include .env or SSH/provider secrets in the evidence report.

Restore to an explicitly empty disposable target whose identity differs from runtime. Do not merge an old dump into the live append-only database. Verify table/schema inventory, triggers and constraints, every referenced artifact hash, populated M10 chain/current reliance, Monitor event chains and ability to render retained outputs with all native execution disabled. Record native dump/import privileges and trigger/definer handling under the actual host, not an assumed local-root profile.

Never call a code rollback a full restore. No down-migration or history deletion is used to reverse a deployment. Old code against an expanded schema requires an explicit compatibility proof; otherwise stand down and roll forward to a repaired release.

### Acceptance tests

- Full clean-target restore with populated M10 and Monitor records, not zero-row checks.
- Missing/corrupt artifact and invalid chain cause verification failure.
- Restore target cannot equal runtime.
- Native execution remains off after restore until separately re-admitted.
- Backup/manifest excludes live secret contents.

**Completion:** Off-host backup and independently verified clean restore, with realistic credentials and populated history.

**Gate:** Actual backup/export/import requires explicit operator authorization and a qualified disposable restore target; no restore runs during plan/code creation.


## P11 — Make master-key rotation version-aware and exercise credential recovery

**Dependencies:** P00, P01, P10.

### Add

```text
app/Provider/KeyRing.php
app/Provider/CredentialRotation.php
ops/provider/rotate-master-key.php
database/migrations/240_provider_rotation_events.sql
docs/CREDENTIAL_ROTATION_RUNBOOK.md
tests/unit/provider_key_ring.php
tests/integration/provider_key_rotation.php
tests/integration/p11_migration_order.php
tests/security/provider_rotation_boundary.php
tests/rollback/provider_key_recovery.php
```

### Change

```text
app/Provider/CredentialStore.php
app/Provider/SecretBox.php
app/Support/Bootstrap.php
app/Support/Env.php
app/Workflow/MonitorFactory.php
app/Workflow/ExecutionGrantStore.php
public_html/index.php
ops/evaluation/monitor.php
ops/provider/credential.php
ops/deploy/preflight.php
.env.example
docs/BACKUP_RESTORE_RUNBOOK.md
docs/SECURITY_BOUNDARY.md
docs/DEPLOYMENT_RUNBOOK.md
docs/SCHEMA_REACHABILITY.md
docs/MVP_RELEASE_STATE.md
docs/MONITOR_COMPLETION_PLAN.md
docs/MONITOR_COMPLETION_WORK_PACKAGES.json
tests/fixtures/schema_table_inventory.json
tests/integration/schema_invariants.php
```

### Implementation and wiring

CredentialStore already saves/replaces/revokes provider credentials. Do not rebuild those operations. Its current dispatch read does not select `key_version` and uses one injected SecretBox; add a version-aware `KeyRing` around the existing AES-256-GCM implementation. Keep legacy `APP_MASTER_KEY` as version 1 for existing deployments. Versioned configuration must be validated by one shared `KeyRing::fromEnv` path used by Bootstrap, HTTP Monitor composition and every provider/evaluation CLI. Unknown key versions refuse; never try keys sequentially until one happens to decrypt.

KeyRing holds only operator-provided private key material and one explicit active write version. SecretBox remains one key/one version and keeps tenant+provider AAD. CredentialStore save seals with the active version; `withCredential` selects `key_version` and `credential_digest`, decrypts under exactly that version, recomputes the keyed fingerprint and refuses if it no longer matches the stored digest.

Master-key rewrap rotates every ciphertext-bearing credential row selected for the tenant, including ACTIVE, ROTATED and REVOKED rows. Before a batch writes, it locks the tenant then credential rows in deterministic order and refuses while any current or future-admitted execution grant names an affected credential. Rewrap preserves `provider_credential_id` and lifecycle status but, because `credential_digest` is keyed by the master key, recomputes `credential_digest` under the target version. Existing immutable grants therefore become stale by design; rotation never rewrites or re-admits them. ExecutionGrantStore admission takes the same tenant-row mutex as credential save/rewrap/revoke and performs its final currentness validation while holding it, closing the grant-admission/credential-mutation races. A later successor grant must carry the new digest. Replacing the provider secret remains a different operation: it creates a new credential identity, so frozen mission configuration and grant bindings require explicit successors.

CredentialRotation uses status → plan → exact plan digest → bounded apply semantics. Rotation is tenant-wide even though tenant.administer is checked through the supplied workspace: every ciphertext-bearing credential and every current/future-admitted grant in the tenant is considered. The configured active version is the only rewrap target; there is no --to-version escape hatch. For each row it verifies old-key availability and old `credential_digest`, seals with the target version, reopens and privately compares plaintext, updates ciphertext/nonce/tag/key_version/credential_digest, and appends an immutable rotation event containing only safe identities/digests/version metadata. A batch commits atomically; repeated invocation of the same completed plan is idempotent and later plans resume remaining old-version rows. The upgrade test explicitly proves migration 240 can be introduced after historical migration 900 is already ledgered on an existing database. No network call occurs inside the transaction and no plaintext/key material appears in argv, logs, exceptions, receipts or database events.

P10 recovery is proved under the same P11 release: take and retain a backup while version 1 is still active, rotate the live database to version 2, then restore the pre-rotation backup into a clean target. The restored v1 ciphertext must open only when the retained v1 recovery key is supplied. Removing v1 from the live runtime ring and destroying the retained v1 recovery key are separate operator acts. Provider replacement/history provenance is never silently rewritten.

### Acceptance tests

- Legacy `APP_MASTER_KEY` remains version 1; versioned key-ring configuration validates one active write version and rejects ambiguous/missing/malformed versions.
- Old/new version decrypt, exact unknown-version refusal, wrong-version refusal, cross-tenant/provider ciphertext refusal, stored-digest mismatch and tamper checks.
- Master-key rewrap includes ACTIVE/ROTATED/REVOKED ciphertext rows, preserves credential identity/status, changes key_version and keyed credential_digest, and is idempotent across repeated bounded batches.
- Current/future-admitted grants referencing selected credentials block rotation; completed rewrap leaves existing immutable grants stale until a separately proposed/admitted successor grant carries the new digest.
- Provider-secret replacement still creates a new credential identity and requires successor mission/grant bindings.
- No plaintext/key material in logs, exceptions, argv, request logs or rotation receipts.
- A P11-release backup taken before rewrap can be restored later and its v1 credential recovered only with retained v1 key material.
- An existing database with historical migration 900 already ledgered can add migration 240 without replaying or drifting 900.
- Real MariaDB contention exercises master-key rewrap, credential revoke and grant admission on the shared tenant mutex. Credential save uses that same mutex in the implementation, but PR28 does not claim a separate two-connection save-contention test.

**Completion:** Credential lifecycle is tested, including recovery; presence of a key is no longer the only proven property.

**Gate:** Implementation and offline testing only; activation is separate.


## P12 — Promote production identity and customer visibility through the canonical owners

**Dependencies:** P06, P10, P11.

### Add

```text
docs/PRODUCTION_PROMOTION_PLAN.md
docs/PRODUCTION_OPERATIONS_RUNBOOK.md
ops/deploy/production-preflight.php
tests/acceptance/production_identity_visibility.php
```

### Change

```text
PROJECT_BOUNDARY.md
UPSTREAM_AUTHORITY.md
upstream.lock.json
app/Ops/BetaCli.php
app/Auth/AccountService.php
app/Substrate/M02/Scope.php
app/Substrate/M02/Permissions.php
app/Substrate/M10/Vocabulary.php
app/Substrate/M10/DecisionLedger.php
app/Substrate/M16/ReleaseOutput.php
ops/deploy/preflight.php
tests/foundation/lock_invariants.php
docs/CLAIM_CEILING.md
docs/SECURITY_BOUNDARY.md
deployment-evidence.json
```

### Implementation and wiring

This is the intentionally conditional work package. The current source does not support treating production/customer identity, visibility and hosting policy as settled. First obtain canonical decisions about identity classes, internal/customer audience semantics, which product modules are sold, source/output rights and the hosting boundary. Full P03 release requires P08; recurring operation requires P07; richer customer UX requires the selected P09 scope. Billing/CRM/email/SMS remain out of scope unless separately authorized.

Prepare canonical changes at the owning M02 identity, M10 environment/reliance and M16 audience contracts and their schema/migration/tests. Exact upstream successor migration names and Loopia production migration numbers must be allocated after those decisions; do not edit existing migration files or predeclare an unapproved enum. Then project only the adopted owner changes with shared conformance vectors and source hashes.

APP_ENV=production is already a strict bootstrap posture; it is not a grant to invent CUSTOMER database identities or wider visibility. Keep that separation. Avoid in-place conversion of historical TEST identities or reinterpreting old M10 streams as production. Prefer distinct production identities, credentials and ledger streams, with any selected data intake explicitly recorded.

Close hosting isolation with actual acceptable evidence or a new qualified boundary. The owner-directed staging exception, account-wide SSH access and prior failed/unverified isolation remain honestly reported. Keep the static public website separate. Produce the source-use/disclosure, support, retention, incident/recovery and product-claim documents required by the selected customer offering, with owner/professional review where needed.

production-preflight.php validates the exact application/schema, adopted authority, host evidence, backup/restore/rotation readiness and allowed audience; it cannot grant GO. Freeze and qualify a distinct production release. Only a separate recorded owner decision can authorize customer visibility or production launch. Application code must never edit the governed lock or deployment-evidence file itself.

### Acceptance tests

- TEST/DEMO/historical ledger identities cannot masquerade as production.
- A missing/expired production authority or wrong host/release refuses.
- Internal-only/licence-limited sources cannot leak through a newly enabled audience.
- Customer-facing reads retain all owner, source-use, current reliance and scope gates.
- Separate release, hosting and rollback tests with production execution initially inert.

**Completion:** An explicitly approved production offering with qualified hosting, identities, visibility, secrets, backups and operating procedures.

**Gate:** Canonical identity/audience decision, source/privacy/commercial review, hosting closure and explicit production/customer GO. Exact migration semantics remain conditional until approved.


## Proposed additive database sequence

These numbers are proposed against the inspected tree, not permanent reservations. Allocate in the order packages are actually implemented and recheck pending branches before creating them. The repository also has the historical `900_deferred_foreign_keys.sql`; do not edit or renumber it. Prove both a clean install in lexical file order and an upgrade where 900 has already run.

| Proposed migration | New ownership / purpose |
|---|---|
| `210_monitor_execution_controls.sql` | Exact deployment execution grants, prompt approvals, append-only status events; authority references, not new M10 release types. |
| `220_monitor_evaluation_records.sql` | Evaluation run/human assessment identities and explicit evaluation-purpose linkage to existing provider attempts. |
| `230_monitor_attempt_recovery.sql` | Durable attempt/artifact references and append-only reconciliation records; versioned continuation semantics where needed. |
| `240_provider_rotation_events.sql` | Append-only credential/key-rotation receipts; private key material never stored here. P11 is the next engineering package, so it takes the next free number. |
| `250_monitor_schedule_control.sql` | Schedule versions, occurrence reservations, bounded delegation and typed Monitor jobs; journal actor-v2 support with v1 compatibility. P07 remains blocked on the operational P06 road test. |
| Production successors | Unallocated until canonical identity/audience/hosting decisions are approved. |

Every schema change updates `tests/fixtures/schema_table_inventory.json`, `tests/integration/schema_invariants.php` and `docs/SCHEMA_REACHABILITY.md`. Do not weaken the exact inventory to make the count pass. Apply a new migration, never edit applied SQL. Prove constraints on direct writes as well as service-level refusal. Record partial-DDL recovery explicitly; an application transaction is not a rollback of MariaDB DDL.

## Common test and release gate for every code PR

1. Unit/refusal tests for the new contract, then integration using the real supported database versions.
2. Shared canonical vectors for every actual canonical port, generated with an exact source manifest.
3. Regression suite on the existing Monitor chain and all selected product owners.
4. Cross-tenant/workspace, source/current-rights, credential/grant/prompt revocation, exact-request and independent-human-review negatives.
5. Concurrent reservation, same-run/same-source race and crash-window tests for every new side-effect path. No network inside a long SQL transaction.
6. Full strict suite: actual MariaDB 10.6 and 10.11, zero unexpected failures/skips. Preserve failures; do not reuse the old 6,374 count as a forecast of new totals.
7. Exact Git-object prepared release, frontend tests/build, asset manifest and copied-tree verification. Native API evals are explicitly opt-in and never funded by default CI.
8. Fresh review on the final exact code SHA. Test-receipt provenance distinguishes local execution, hosted CI, host qualification, fixture transport, actual model evaluation and real product missions.
9. Real-host qualification for a deployed candidate, including actual PHP version, Apache/HTTP boundary, private storage and populated-history rollback/restore.
10. Evidence filing by reviewed commit. No application/helper silently edits `upstream.lock.json` or `deployment-evidence.json`, no observation becomes owner GO, and no empty GitHub status response proves checks ran.

## Non-code work remains part of the plan

- Owner decisions for actual model data disclosure/spend, prompt acceptance, manual mission GO, recurring delegation and eventual production/customer authority.
- Funded provider quota and account-side data/retention controls. `store=false` is not itself proof of zero provider-side retention.
- Qualified external scheduler identity/transport if that route is selected. SSH in this staging account is not a proved per-site sandbox.
- Production hosting isolation or a newly qualified isolated boundary; staging acceptance does not prove production isolation.
- Secret entry, backup key custody, credential-exposure resolution and restore target ownership.
- Support, incident response, retention/disclosure/licensing and customer claims reviewed for the actual chosen offering, rather than generated and labeled compliant automatically.
- GitHub billing/check availability and branch-policy changes through real repository settings and owner authorization.

## Definition of done by milestone

**Manual internal beta:** exact deployed candidate; evaluated and approved prompts/model; scoped/native admission; repeatable setup; actual source/model receipts; two explicit human decisions; P01 and P04 current release evidence; populated-history replay/recovery/stand-down; independent backup.

**Recurring internal Monitor:** everything above plus qualified scheduling/delegation/occurrence budgets, no duplicate work or human impersonation, clear wait states and useful internal operational reporting. It is not automatically unattended model execution.

**Broader SME product:** the selected P02/P03/P05 assurance/currentness/citation requirements and usable owner-safe views, without claiming excluded modules.

**Production:** adopted canonical identity/audience rules, distinct runtime/secret/ledger identities, qualified hosting, tested full restore/rotation, required source/privacy/support material, fresh release qualification and explicit owner GO. Native live reads are not external publishing or customer release authority.

## Source register

The plan extends inspected code; the proposed classes above are new design, not features claimed to exist. Exact baseline files are linked below. Repository links may require the user's access.

- [app/Workflow/MonitorFactory.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Workflow/MonitorFactory.php)

- [app/Workflow/MonitorService.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Workflow/MonitorService.php)

- [app/Workflow/MissionConfiguration.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Workflow/MissionConfiguration.php)

- [app/Workflow/RunJournal.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Workflow/RunJournal.php)

- [app/Workflow/SourceCaptureStage.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Workflow/SourceCaptureStage.php)

- [app/Provider/ModelExecutor.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Provider/ModelExecutor.php)

- [app/Provider/PreparedModelRequest.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Provider/PreparedModelRequest.php)

- [app/Provider/OpenAIResponsesTransport.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Provider/OpenAIResponsesTransport.php)

- [app/Provider/CredentialStore.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Provider/CredentialStore.php)

- [app/Provider/SecretBox.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Provider/SecretBox.php)

- [app/Db/Migrator.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Db/Migrator.php)

- [app/Ops/DeploymentEvidence.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/app/Ops/DeploymentEvidence.php)

- [public_html/index.php](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/public_html/index.php)

- [frontend/console/src/views/p02/entity-context.jsx](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/frontend/console/src/views/p02/entity-context.jsx)

- [docs/MONITOR_WORKFLOW.md](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/docs/MONITOR_WORKFLOW.md)

- [docs/PORT_SCOPE_MATRIX.md](https://github.com/HMarcusWH/allfather-loopia-p01/blob/8dbea16b262df7b6d40fe945f34e301eedf2aba6/docs/PORT_SCOPE_MATRIX.md)

- [Canonical P03 assurance](https://github.com/HMarcusWH/Allfatherbusinessintelligence/blob/1c40306c22e15b2ad3049e8d32abff47450c8921/packages/allfather-control-substrate/src/p03/assurance.ts)

- [OpenAI Structured Outputs documentation](https://developers.openai.com/api/docs/guides/structured-outputs) — verify supported schema and refusal/incomplete-output behavior before native evaluation.
- [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data) — review separately from the application’s store=false field.
