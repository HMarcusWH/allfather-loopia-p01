# Allfather PHP — Longitudinal Intelligence Implementation Plan

**Prepared for:** Marcus Hermansson  
**Date:** 25 October 2026  
**Version:** 1.0  
**Status:** proposed engineering plan; no implementation, merge, deployment, prompt approval, execution grant or product launch is performed by this document.

## Executive decision

Extend the existing PHP Monitor with a **longitudinal intelligence layer**, not a wholesale second port. The required capability is:

> Preserve observations even when they are not immediately important; decide when accumulated evidence deserves another look; review a frozen, permission-checked historical basis; and interrupt a user only through justified, governed internal attention.

The first useful release does **not** require full MCM-HMWH, a research-agent cluster or a new “world model”. It needs usable competitor history, a correct observation/materiality contract and a bounded way to request historical review. M17 supplies the proposed deterministic temporal-memory semantics. Existing governance, provider, scheduling, storage and review owners remain the foundation.

This plan covers all identified additions needed for that discussed capability and its operational dependencies. It is **not** a claim to inventory or port every unrelated file in the canonical research repository. New names, tables, thresholds and workflow states below are proposals unless explicitly identified as existing source or adopted architecture.

## 1. Audit basis and limits

| Surface | Exact audited basis | Meaning |
|---|---|---|
| Canonical main | `1169428f8163ba7ec81e4c739fea855b4b246cba` | Current repository contracts/source inspected; not automatically adopted PHP authority |
| PHP main | `d4fa09d6de924bb18b99fd229383e5d4992017ab` | Current code baseline for the gap analysis |
| PHP global canonical pin | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | Existing authority pin; selective newer imports are separate |
| PHP PR64 | `9a001370c7c3973d4edd6f279c958acafe71df3d` | Open, unmerged diagnostics/metering proposal |
| Architecture primer | v1.6.1, 29 August 2026 | Explanatory historical map; relevant observation, M17, MCM and release pages |
| MCM source paper | v2.0 public-review candidate | Theory/source lineage, not a replacement for current v2.1-draft repository contracts |

The relevant source and interface paths were read through the connected GitHub repository. Selected canonical documents available in the uploaded archive were read locally only where their Git blob identity matched the fresh repository response. Older ZIP snapshots were not substituted for current branch state. Source citations use the register in Appendix A.

This was a **static planning investigation**. No repository code was changed, no test suite was executed, no host database/volume was inspected, no model call was made and no live activation was verified. Source comments saying “not deployed” and older runbooks naming a Loopia release are historical statements, not proof of the current Railway host. Reconcile those statements against exact runtime receipts before any rollout. [S12–S14, S21–S24]

### 1.1 What changes from the earlier discussion

PHP already retains approved capture bytes in private storage and binds them to M05. It also saves accepted classifier candidates before a `NO_MATERIAL_CHANGE` result ends the run as `NO_CHANGE`. Therefore **do not build a replacement evidence store or assume every nonmaterial candidate is lost**. What is missing is the richer descriptive contract, competitor-centric query/index layer, and explicit reuse of accumulated history. [S01–S05]

Scheduling is also already implemented. Its present authority is intentionally limited to capture/compare/queue, and its three-unread-run backpressure can stop further observations. The work is to extend and qualify those semantics, not to add an unrelated cron system. [S04, S07]

## 2. Current capability and gap matrix

| Capability | Current PHP/main position | Required disposition |
|---|---|---|
| P01 registry, source cards, governed capture | Present in PHP | Reuse; preserve scope and source-use checks |
| Retained source bodies | Private EvidenceStore + capture bindings present | Reuse; verify availability at historical read |
| Append-only snapshot/diff history | Present | Reuse and index; do not rewrite structural classifications |
| Accepted candidate history | Present, including nonmaterial results | Project into competitor history with exact provenance |
| Descriptive nonmaterial model output | v1 requires empty observation arrays | Introduce evaluated, immutable successor contract |
| Competitor-wide historical read model | Existing WatchlistRead lists competitor/readiness; Monitor exposes run metadata, not a longitudinal evidence view | Extend existing navigation with owner-safe history/readback and bounded indexing |
| Multi-source point-in-time model context | Current workflow uses two snapshots; context is bounded/current-use | Add an explicit historical basis and eligibility contract |
| Scheduling | Observe-and-queue implemented; zero service model calls | Extend only under new exact authority and queue policy |
| Review-due logic | GSMC fixture count/time example; not a cross-time semantic controller | Add admitted review policy and deterministic due work |
| M17 memory | Canonical specification, no implemented spine | Implement an authorized deterministic subset with parity vectors |
| Historical synthesis and challenge | No end-to-end PHP historical review composition | Add bounded manual-first workflow using existing provider machinery |
| Historical finding release | Current Monitor composition binds a single diff | Adopt legitimate multi-evidence proposal/release contract |
| Internal attention/digest policy | Governed P04 distribution exists; not this distinct attention policy | Add internal policy over eligible outputs, not arbitrary raw observations |
| P02/P03 UI and P03 assurance | Already present in source | Reuse; close actual operational evidence, not duplicate screens/audits |
| Backup, recovery, rotation, stand-down | Existing source machinery | Extend to new graph and prove populated restore |
| Full MCM/meta-meta/RACR/forecasting/EOM | Larger architectures are not a ready-made live runtime | Separate optional roadmap with upstream decisions |

Basis: [S01–S20, S23–S28]. “Present” means source support, not a new assertion of deployment, operational qualification or current grants.

### 2.1 Highest-impact code findings

**F01 — PHP already retains source bytes.** SourceCaptureStage writes policy-checked payloads to EvidenceStore and binds them through monitor_capture_payloads. A new raw competitor-memory store would duplicate an existing owner. [S01]

**F02 — Accepted nonmaterial candidates already survive.** MonitorService saves the accepted candidate artifact before switching NO_MATERIAL_CHANGE to NO_CHANGE. The gap is descriptive schema/history projection and reuse, not wholesale loss of the accepted output. [S02, S05]

**F03 — The old completion backlog is not a list of missing source.** P00–P12 largely landed; scheduling, setup, recovery, P03 assurance, P02/P03 detail views, backup and key rotation must be reused and operationally requalified. [S11, S12, S13, S14]

**F04 — Historical model context is not wired.** The Monitor constructs its context from exactly the before/after snapshots. ContextEnvelope also has bounded observation/byte/call caps and current-use semantics. A historical basis needs an explicit separate contract, not arbitrary appended JSON. [S02, S03]

**F05 — Scheduled observation deliberately cannot infer.** SCHEDULED_OBSERVATION permits zero model attempts, and SERVICE advances only capture/compare. Cron activation does not authorize historical reasoning. [S04, S07]

**F06 — The human-queue cap can stop accumulation.** At three unread changed runs the schedule refuses further occurrence reservation. Sustained routine noise can stop observation unless an operator drains the queue or a new reviewed coalescing policy is adopted. [S07]

**F07 — Single-diff release does not represent a historical synthesis.** ReleaseStages.releaseP01 builds a signal from one source/diff. A multi-evidence inference needs an explicit admitted route or must stay provisional; selecting an arbitrary diff is not a fix. [S06, S10]

**F08 — Structural magnitude and commercial importance are already separate.** ChangeDetection explicitly says structural significance is not relevance. Keep its archived result unchanged and fix the semantic/materiality contract above it. [S09]

**F09 — M17 and full MCM are not ready-made runtime ports.** M17 is architecture-only; canonical MCM documents distinguish the fixture skeleton from the larger target. A PHP projection needs authorization and tests, not optimistic claims that those runtimes already exist. [S15, S17, S18, S19]

**F10 — M17 successor uniqueness needs reconciliation.** M17 runs include input digest in identity, while the proposed memory unique key lacks run/input identity. On its straightforward reading this conflicts with same-period/same-engine late-input successors. Resolve upstream before SQL; this is not an observed production failure. [S15, S16]

**F11 — Observation counts are not corroboration counts.** The new review lane must distinguish repeated captures, unique changes and uncertain common-origin groups. A threshold is only a work trigger, not evidence that a hypothesis is true. [S15, S18, S20]

**F12 — Known model-validation metering repair remains pending.** PR64 is open/unmerged at the inspected head. It targets diagnostics and usage on rejection, not materiality quality or historical critic reconstruction. [S21]

## 3. Target composition and ownership

```text
Approved capture / existing Monitor schedule
  -> P01 snapshots + source_diffs + M05 private retained bytes
  -> competitor observation history + versioned assessment references
       | no business significance required for lawful retention
       |
       +-> deterministic coverage / origin-group / feature views
       +-> M17 windows, profiles, rollups, lineage and replay [new, owner-gated]
       |
       +-> review policy + cheap due checks [new]
              -> bounded coalesced review queue [new]
              -> immutable historical evidence basis [new]
              -> human-inspected, admitted model request
              -> bounded historical analysis + challenge
              -> human review
              -> supported multi-evidence proposal [upstream contract required]
              -> existing M07/M08/M10 + applicable M16 representation
              -> existing P04 internal routing + attention policy
              -> outcome annotations / current-reliance review

M11 audits every relevant layer; it does not become the domain truth owner.
MCM meta/meta-meta evaluation is a later proposal-only extension, not a prerequisite.
```

The persistent “competitor record” is a **joined, scoped view of owner-native history**, not one mutable narrative field. A source fact, a model-extracted observation, a commercial hypothesis, an admitted P02 value and a released briefing must remain distinguishable. “FINDING” has never been a direct notification grant; the new policy makes the remaining materiality/attention choices explicit. [S05–S11]

### 3.1 Four clocks, four decisions

| Clock | Question | Proposed implementation home |
|---|---|---|
| Capture cadence | When may we observe this approved source again? | Existing source policy + ScheduleService |
| Analysis/review cadence | When should this competitor/question’s accumulated basis be reconsidered? | New admitted review policy + due queue |
| Temporal resolution / lookback | What period is selected, and how much detail should a derived memory retain? | Historical basis + M17 profiles/windows |
| Attention/digest cadence | When and how should an authorized person be interrupted or shown a digest? | Internal product attention policy + existing visibility/routing owners |

A daily crawl does not imply a daily model call. A 90-day lookback is not a 90-day grant. A 14-day compression transition is not a timer that authorizes analysis. None of these clocks establishes truth or source-use rights. The separation follows the existing capture/release boundaries; the explicit review/attention contracts are new proposals. [S04, S07, S15–S17]

### 3.2 The bounded review loop

1. Capture and record through existing owners. Keep identical/no-change observations for coverage; do not count them as new independent business events.
2. On a new permitted reference or a clock tick, evaluate the adopted review policy. Record a due/deferred/no-new-basis disposition without invoking a model.
3. Coalesce compatible pending work without deleting members or changing their review status. Maximum wait, risk, byte/work caps and grants remain binding.
4. Freeze the period, knowledge cutoff, baseline, eligibility, primary references, selection omissions and current reuse policy. A generated summary is context, not an evidence replacement.
5. Let the operator inspect the exact model request. Reserve budget atomically; execute through M06/M01 and the existing provider transport. Preserve known usage even if output is refused.
6. Challenge the candidate against supporting and contrary evidence. Preserve insufficient-basis/no-insight outcomes. Any further retrieval or specialist invocation is a proposal, not an implicit action.
7. Human review and the correct multi-evidence owner route determine release. Attention policy then determines internal urgency/digest treatment within configured roles and visibility.
8. Record the last **actually considered basis**, not just the latest captured timestamp. Evidence arriving during the review stays pending for the next successor.

This is a proposed bounded control loop, not a description of current automatic behavior. Its implementation is split below so observation history and deterministic summaries can ship before paid historical reasoning.

## 4. Data and identity design

### 4.1 Reuse existing facts; add only new decisions and derived references

Existing authoritative material remains in `competitors`, `monitored_sources`, `source_snapshots`, `source_diffs`, M05 evidence/raw artifacts, `monitor_capture_payloads`, provider attempt/result/artifact records, Monitor journals, P02 versions and M10/release records. New tables must not recopy raw source content or silently admit model claims. [S01, S02, S09–S11]

| Proposed object | Minimum content | What it must not mean |
|---|---|---|
| Observation assessment | Scope, exact capture/diff/candidate refs, description, producer, version, materiality disposition, assessed-at, supersession | A newly verified M05 fact or P02 state |
| Review policy | Question/scope, cadence, maximum wait, lookback/baseline, supported triggers, cooldown, work/budget limits, owner decision | A provider credential or execution grant |
| Review basis | Immutable period + knowledge cutoff, ordered eligible primary references, digests, coverage, exclusions, origin uncertainty, selector version | “Everything in the database now” or unrestricted retrieval |
| Review work item | Trigger reason, policy/basis refs, immutable members, priority recommendation, due/deferred state, cursors | A notification or human approval |
| Historical review run | Frozen basis, exact prompts/model/request, attempts, artifacts, critic and human decisions, prior-review relation | A mutable replacement for the old Monitor run |
| Internal attention event | Eligible released object, audience, policy, reason, suppression/escalation, lineage key | Permission to email/push or expose unreviewed evidence |
| Outcome/invalidation record | Original assessment, later evidence/rights change, author/time, scoped consequence and supersession | A retrospective edit of what was known earlier |

The initial history view can be largely read-only. The new assessment store exists only for semantic decisions that are not already expressible in the existing immutable artifacts; the projection must point to those artifacts instead of duplicating their content.

### 4.2 Time, source scope and uncertainty

Keep four timestamps distinct where available: the source’s claimed event date, actual observation/capture time, when the system recorded/knew the item, and when analysis ran. A source saying “December” is not proof the event happened in December. Unknown event dates remain unknown. Stable ordering must include a deterministic ID tie-break; event time alone cannot order simultaneous records.

A historical cutoff includes only information known by that cutoff. New or corrected evidence creates a successor basis. Current permission to reuse/disclose source material is checked separately from the date at which it was originally collected. The proposal must define exactly when historical-but-not-current evidence is eligible; never remove quarantine/current source gates as a shortcut. [S03, S15, S16]

P01 competitor identity and P02 entity-twin identity are separate namespaces. Bind them only through an explicit scoped owner relationship. No automatic merge from similar company names or domains. M17’s declared entity-oriented CompressionScope needs an approved representation of the P01-only case rather than a forged twin ID. [S10, S16]

### 4.3 M17 persistence and the contract repair

Adopt the seven proposed M17 families: profiles, profile rules, runs, rollups, memories, memory status transitions and lineage links. Do not turn every conceptual name in the architecture into its own table. M17 v1 remains deterministic and LLM-free; raw evidence and canonical state remain elsewhere. [S15–S17]

**Resolve before migration:** the architecture requires late-input successor memories, but its proposed `temporal_memories` unique key includes scope/class/period/engine without input/run identity. The run key includes input digest. On a straightforward reading, a new same-period/same-engine memory would collide. Resolve whether memory identity binds a run/input/version, then separately define current-view selection. Test old content, new input, new profile, new engine and redaction successors. This is a static specification inconsistency to close, not an observed deployed defect. [S15 §9 and §16]

### 4.4 Dependency and recovery laws

Each completed analysis records exactly which inputs it considered. Queue coalescing must not move a human-review watermark. A model call with unknown outcome does not get repeated because a job lease expired. Retained known output may be reconciled once with matching scope/digests/current authority; unavailable bytes cannot be rebuilt from a hash.

Source corrections/rights changes propagate through typed dependency links to affected memories/reviews/representations. New use is blocked by current rights. Existing release reliance is handled by M10’s owner path; a scan does not silently withdraw, alter or replace someone else’s record. Compression itself never deletes evidence. [S01–S07, S15–S18]

## 5. Canonical decision packets

The following decisions are part of the plan. They are not assumed to have been granted. They can be split into small upstream PRs; there is no need to wait for a wholesale research-stack migration. The PHP implementation may proceed in approved slices with explicit selective adoption.

### U01 — Observation assessment and materiality v2

**Owner:** P01 + PromptOps.

Permit cited descriptive observations with NO_MATERIAL_CHANGE; distinguish structural magnitude, business materiality, attention and release. Version old and new prompt/validator contracts.

**Does not grant:** No real model call or historical report rewrite.

### U02 — Competitor scope, source identity and origin assertions

**Owner:** P01/P02 + M05.

Define P01 competitor scope in historical/M17 references and any explicit mapping to P02 twins. Define provenance-bearing common-origin assertions; do not infer independence or identity from UUID/domain similarity.

**Does not grant:** No automatic entity merge or P02 state admission.

### U03 — Historical evidence eligibility and clocks

**Owner:** M05/source owners + historical review consumer.

Specify historical-use permission versus currentness, event/observed/known-at clocks, point-in-time selection, provider disclosure, quarantine, redaction and rights withdrawal.

**Does not grant:** No bypass of current source restrictions by requesting old evidence.

### U04 — M17 implementation and successor identity

**Owner:** M17 canonical owner.

Authorize the exact deterministic subset; settle same-period successor uniqueness, P01 scope support, numerical/time serialization and narrow-only profiles; adopt seven table families or a reviewed equivalent.

**Does not grant:** No model-assisted memory, deletion, state mutation or customer output.

### U05 — Historical review coordination and policy ownership

**Owner:** Canonical M04/GSMC/config owners with P01 and M17.

Declare who owns competitor-wide review policy; reconcile configured cadence with fixture constants. Define supported triggers, quiet-stream clock checks, fairness, cooldown, coalescing and bounded backlog semantics.

**Does not grant:** Being due does not grant analysis spending, new source fetches or notifications.

### U06 — Manual historical-analysis execution and outputs

**Owner:** M02/M03/M04/M06/M01 + provider/PromptOps owners.

Add a narrow registered historical-analysis purpose, basis identity, roles, exact request inspection, result provenance and parent/per-review resource limits. Register a bounded non-MCM product analysis contract explicitly.

**Does not grant:** No repurposing evaluation grants or source-observer budgets; no claim of full MCM implementation.

### U07 — Multi-evidence finding admission and representation

**Owner:** P01 + M04/M05/M07/M08/M10; applicable M16/P04.

Define a legitimate historical finding over an evidence bundle, not a fabricated single diff. Specify claim supports, review, current reliance, product projection and optional P02 proposal boundary.

**Does not grant:** No release from a model verdict or M17 memory ID; no automatic P02 update.

### U08 — New service operations and optional unattended inference

**Owner:** M02/service identity + execution/schedule owners.

Separately decide deterministic review/rollup job authority and any later bounded model inference authority. Preserve scheduler-observer v1 unchanged. Bind disclosure policy, budgets, validity, exact prompt/model policy and stand-down.

**Does not grant:** No automatic upgrade of a service seat; human review and release remain human unless separately changed.

### U09 — Internal attention and digest projection

**Owner:** Product/P04/M16/M12.

Define attention/digest policy only over authorized surfaces, recipient roles, source-use rights, current reliance, suppression/dedup and escalation. Unreleased history remains an operator surface.

**Does not grant:** No email, SMS, external push, webhook, CRM write or customer-visible release.

### U10 — Outcome, invalidation and later meta-evaluator limits

**Owner:** Domain review owner + M11/M10 + canonical MCM/SEV where relevant.

Specify outcome/supersession and invalidation effects; later meta/meta-meta evaluators propose only. Keep reliability observations separate from admitted weights and corrections separate from rollback execution.

**Does not grant:** No self-tuning, new recursive layers, policy mutation or action authority.

## 6. Delivery sequence

| Release | User-visible result | Principal packages | What remains off |
|---|---|---|---|
| Foundation | Truthful baseline, qualified diagnostics, owner decisions, frozen tests | LI-00/01; begin LI-18/19 | New paid work and activation |
| R1 — Quiet competitor memory | Nonmaterial observations are retained, explained and inspectable | LI-02/03/04 + first LI-16 slice | Automatic historical inference and new notifications |
| R2 — Deterministic historical review readiness | Scoped windows/coverage, M17 summaries, visible “review due” queue | LI-05/06/07/08/17 + UI slice | Model-assisted M17 memory and autonomous analysis |
| R3 — Governed manual historical intelligence | Exact historical basis, bounded model/challenge, human review, valid internal finding | LI-10/11/12/14/15 + UI; fresh evaluations | Unattended model work, automatic P02 mutation, external send |
| R4 — Admitted recurring operation and attention | Bounded queue coalescing, deterministic jobs, internal digests/priority | LI-09/13 + LI-20 gates | Unattended inference unless U08 is separately approved; production/customer entitlement |
| Later — Meta-evaluation | Proposal-only cross-review attention and loop-health evaluation | Optional LI-21 | Full autonomous MCM, self-promotion and dynamic recursion |

LI-16 is delivered incrementally, not as a reason to delay all UI until R4. LI-18 freezes the relevant cases before model tuning and executes them as code becomes available. LI-19 applies to every new migration and writer, not merely the final release. LI-20 has per-release acceptance dependencies; R1 does not wait for all later work.

### 6.1 First implementation PR sequence

Start with (a) baseline/PR64 qualification and source-currentness, (b) the small canonical observation/materiality contract, (c) exact-version prompt/validator support with no activation, (d) competitor-history read model and assessment bindings, and (e) the first history UI and no-change retention tests. Design the sequence benchmark in parallel before changing semantic prompts.

Then close the historical-use/M17 contracts and implement pure basis/window/profile functions before storage and jobs. Add the manual historical-analysis lane before enabling any autonomous inference. The multi-evidence release adapter must precede any claim that the full historical intelligence workflow is complete.

Package IDs below are planning IDs, **not reserved GitHub PR numbers**. ADD filenames and numeric migration slots are proposed. Recheck the branch at implementation time; stale naming is not permission to overwrite a new file.

## 7. File-level work packages

### LI-00 — Reconcile currentness and qualify the diagnostic foundation

**Release:** FOUNDATION · **Depends on:** None · **Risk:** HIGH

**Owner:** Projection Ops / source authority; provider metering retains its existing owner.

**Existing basis:** P00–P12 source packages and release tooling exist. PR64 is open and unmerged. Some “current” documentation still describes the older Loopia release rather than later Railway/Luna evidence.

**Implementation**

Create a dated capability ledger that independently names source SHA, qualified SHA, deployed host/release, runtime admission, quality acceptance and customer visibility. Reconcile historical backlog entries against receipts; never declare an old failure passed or infer current deployment from Git main. Record the inspected canonical head without changing upstream.lock.json’s global authority pin.

Use PR64 as the existing diagnostics/metering candidate rather than creating a competing patch. Qualify its exact head in disposable lanes. Preserve the generic candidate refusal, allowlist private codes, and retain known usage after rejection. Add coverage for parsed responses followed by persistence/revalidation exceptions; unknown provider usage must stay UNKNOWN rather than become zero. Do not store rejected raw candidate bodies for convenience.

Preserve the original DEV7 report, prompts and manifest. Any recovered candidate is an evidence attachment with its own digest, not a rewritten evaluation. The refused critic’s missing original payload remains unresolved. Test/cost claims distinguish deterministic fixtures, live synthetic evaluations and real product missions.

**ADD — proposed**

```text
docs/LONGITUDINAL_INTELLIGENCE_STATE.md
docs/LONGITUDINAL_INTELLIGENCE_PLAN.md
docs/LONGITUDINAL_INTELLIGENCE_WORK_PACKAGES.json
```

**CHANGE / integrate**

```text
docs/MVP_RELEASE_STATE.md
docs/MONITOR_COMPLETION_PLAN.md
docs/POST_MERGE_TEST_BACKLOG.md
docs/MONITOR_MODEL_EVALUATION_PROTOCOL.md
app/Provider/ModelExecutor.php
ops/ci/qualify-release.sh
ops/ci/verify-qualification.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/foundation/longitudinal_state_currentness.php
tests/unit/provider_usage_completeness.php
```

**Finished when**

- A reader can distinguish current source, old evidence and unverified host state without chat history.
- Exact-head qualification receipts identify environment and incomplete/skipped lanes honestly.
- Rejected or uncertain attempts remain spent; no invented zero usage, refunds or retrospective raw critic output.

**Authority gate:** Read-only planning now. Review/qualification, merge and deployment are separate acts; this package grants no paid execution.

**Source basis:** S12, S13, S14, S21, S22.

### LI-01 — Close the canonical contracts before expanding PHP semantics

**Release:** FOUNDATION · **Depends on:** LI-00 · **Risk:** HIGH

**Owner:** Canonical P01/M04/M05/M17/M02/M06 and product release owners; PHP is the adopted projection.

**Existing basis:** M17 is an accepted architecture target, not implemented authority; full MCM remains a target with unresolved implementation gates. PHP grants and release paths are deliberately narrower.

**Implementation**

Prepare an upstream decision packet for observation versus materiality, competitor scope and identity, historical evidence use, M17 implementation scope, review coordination, analysis-purpose authorization, multi-evidence release, internal attention and optional unattended inference. Record which contracts are already inherited, which are direct ports, and which are newly proposed. Do not assign a new universal “meta-analysis” owner over M05/P02/M17/MCM.

Resolve the M17 late-input successor identity tension before writing SQL: runs are input-digest-specific, but the proposed memories unique key omits input identity while requiring same-period successor memories. Specify one content/version identity and one separately derived current pointer. Prove same-basis replay, late-input successor, profile change and redaction successor in canonical vectors. Also define how a P01 competitor maps to M17 CompressionScope without pretending its UUID is a P02 entity-twin UUID.

Use selective canonical adoption records and exact hashes. A bounded PHP-first implementation is possible only under a reviewed projection contract; do not require a wholesale TypeScript application port, but do require canonical semantics and cross-language reference vectors for shared pure laws. Any authority-bearing service role, job, purpose, evidence basis or release family must be reviewed upstream before PHP enables it.

**ADD — proposed**

```text
docs/LONGITUDINAL_AUTHORITY_DECISIONS.md
docs/reconciliation/longitudinal-intelligence-2026-10-25.json
tests/fixtures/longitudinal_owner_contract_vectors.json
```

**CHANGE / integrate**

```text
PROJECT_BOUNDARY.md
UPSTREAM_AUTHORITY.md
docs/PORT_SCOPE_MATRIX.md
docs/SCHEMA_REACHABILITY.md
```

**ADD acceptance and regression tests — proposed**

```text
tests/foundation/longitudinal_owner_boundaries.php
tests/conformance/longitudinal_owner_contracts.php
```

**Finished when**

- Each new behavior has a semantic owner, an explicit implementation disposition and a separate activation condition.
- No memory ID is accepted as evidence, approval, source-use right or release authority.
- The M17 same-period successor and P01/P02 scope questions are resolved before dependent migrations.

**Authority gate:** Obtain narrowly scoped canonical decisions for each extension. A planning document or new enum is not a grant.

**Source basis:** S04, S05, S06, S15, S16, S17, S18, S19.

### LI-02 — Version the observation/materiality model contract without rewriting v1

**Release:** R1 · **Depends on:** LI-01 · **Risk:** HIGH

**Owner:** P01 observation interpretation; PromptOps owns immutable content and approved bindings.

**Existing basis:** Current v1 requires NO_MATERIAL_CHANGE to contain no observed_changes. Candidate artifacts are already preserved; the missing piece is a compatible, versioned descriptive contract.

**Implementation**

Add a reviewed successor bundle whose classifier can describe an evidence-backed change while concluding that material business change is not established. Keep structural change significance separate from business relevance and attention. A navigation observation must not need FINDING just to be recorded; a changed price must not disappear because the structural delta is tiny. Proposed triage values such as RETAIN_ONLY/REVIEW_LATER/REVIEW_NOW are recommendations, not notification instructions or authority.

Introduce exact bundle/version resolution and validator dispatch. ProductPromptPack, PromptSelectionResolver, approvals, prepared requests and candidate validation must agree on the same immutable content identity. Keep v1 bytes, hashes, schemas and validators replayable; never resolve a historical run by “latest role”. Unknown roles or schema versions fail closed rather than falling through to the current generic critic branch.

State the critic’s REVISE/BLOCK and injection/HOLD constraints explicitly in the successor instructions, preserving independent validation. Keep facts, model-extracted descriptions and hypotheses visibly different. Leave the original DEV7 oracle untouched; add a new versioned evaluation manifest for the extended output contract and retain old-suite regression coverage.

**ADD — proposed**

```text
app/PromptOps/PromptBundleRegistry.php
app/PromptOps/ObservationCandidateChecksV2.php
app/PromptOps/bundles/monitor-observation-v2.json
docs/OBSERVATION_MATERIALITY_CONTRACT.md
tests/fixtures/monitor_observation_v2_eval_cases.json
```

**CHANGE / integrate**

```text
app/PromptOps/ProductPromptPack.php
app/PromptOps/PromptSelectionResolver.php
app/PromptOps/PromptApprovalStore.php
app/PromptOps/CandidateChecks.php
app/Provider/PreparedModelRequest.php
app/Workflow/MonitorService.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/unit/observation_candidate_v2.php
tests/unit/prompt_bundle_versioning.php
tests/integration/monitor_observation_contract.php
```

**Finished when**

- Menu reorder: described and retained; no material finding, product release or notification.
- One-word negation or one-price change remains detectable; cosmetic normalization cannot suppress it.
- All old request/candidate digests still verify with their original bundle; unknown versions refuse.

**Authority gate:** U01 contract approval; fresh prompt/model evaluation and exact approvals before real use.

**Source basis:** S02, S05, S09.

### LI-03 — Expose competitor history and persist bounded observation assessments

**Release:** R1 · **Depends on:** LI-01, LI-02 · **Risk:** MEDIUM

**Owner:** P01 owns competitor observation associations; M05 remains evidence owner.

**Existing basis:** source_snapshots, source_diffs, monitor_capture_payloads, run journals and accepted candidate artifacts already retain source/run history.

**Implementation**

Build an owner-safe competitor-history projection over existing source, snapshot, diff, run and candidate references. Start with reads; do not copy raw evidence into a second competitor-memory database. Index by tenant/workspace/competitor/source/time with stable pagination. Show BASELINE, identical capture, changed-but-nonmaterial, material candidate, held/refused and unavailable-capture states distinctly.

Add only the missing immutable assessment record: exact source/run/artifact references, descriptive observations, producer type, prompt/schema/model version where relevant, materiality disposition, assessed_at and supersedes_id. Model-extracted prose remains a candidate interpretation, never a newly certified M05 fact. Store safe text only under applicable source-use rules; keep full source bytes in EvidenceStore. Historical human annotations need author identity and a separate recorded-at clock.

Backfill metadata and links from verified existing rows. Do not regenerate missing narrative with an LLM, fabricate an assessment for a rejected candidate, or mix MODEL_EVALUATION cases into real competitor history. Existing terminal NO_CHANGE runs remain terminal. New assessment/relationship records reference them rather than editing their journal or inferring that someone read or approved them.

**ADD — proposed**

```text
app/Product/P01/ObservationHistory.php
app/Product/P01/ObservationAssessment.php
app/Product/P01/CompetitorScope.php
ops/history/backfill.php
database/migrations/260_competitor_observation_assessments.sql
```

**CHANGE / integrate**

```text
app/Workflow/MonitorService.php
app/Workflow/RunArtifacts.php
app/Workflow/RunReconciliation.php
app/Product/P01/Registry.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/competitor_observation_history.php
tests/security/competitor_history_scope.php
tests/integration/observation_history_backfill.php
```

**Proposed tables:** `p01_observation_assessments`, `p01_observation_assessment_refs`.

**Finished when**

- A nonmaterial change is visible on the correct competitor’s timeline with exact evidence drill-down and no release row.
- Backfill is repeatable and leaves all prior source/run/provider/evaluation rows byte-identical.
- A foreign tenant/workspace, unbound competitor ID, or synthetic evaluation cannot enter product history.

**Authority gate:** U01/U02 for new durable assessment semantics. A read model alone does not admit new facts.

**Source basis:** S01, S02, S08, S09, S10.

### LI-04 — Preserve semantic deltas, provenance groups and observation coverage

**Release:** R1 · **Depends on:** LI-03 · **Risk:** MEDIUM

**Owner:** P01 derived feature adapters; source/evidence ownership remains M05.

**Existing basis:** Structural line-set deltas are present; they are not semantic change descriptions and are sensitive to representation. Capture/scheduler records can expose failures and gaps.

**Implementation**

Add versioned, bounded feature extraction over retained bytes: navigation order when reliably extractable, explicit price/unit/date changes, source-declared event type and unsupported/unknown extraction outcomes. Bind every feature to source digest, extractor version and evidence references. Preserve original bytes and the original structural classifier result; a parser must not rewrite source truth or silently strip a meaningful price/negation change.

Track repeated capture, unique source change and corroborating origin as different counts. Exact-byte repeats can be deduplicated deterministically; syndication/common-origin groups are separate, provenance-bearing assertions with uncertainty. A domain name, hash or model statement does not prove statistical independence. No positive business conclusion may rely on “20 pages said it” when all copied one press release.

Derive coverage from planned observation slots, actual captures, refusals, skipped slots and last successful observation. Mark an outage or unavailable body as missing coverage, not no change. Expose rate denominators and monitoring-intensity changes before comparing activity between periods. Baseline and no-change observations are useful coverage data, not 100 independent business events.

**ADD — proposed**

```text
app/Product/P01/ObservationFeatures.php
app/Product/P01/SourceOriginGroups.php
app/Product/P01/ObservationCoverage.php
database/migrations/265_observation_features_and_origins.sql
```

**CHANGE / integrate**

```text
app/Workflow/SourceCaptureStage.php
app/Product/P01/ObservationHistory.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/unit/observation_features.php
tests/integration/source_origin_grouping.php
tests/unit/observation_coverage.php
```

**Proposed tables:** `p01_observation_features`, `p01_source_origin_assertions`.

**Finished when**

- Equivalent navigation renderings produce a bounded descriptive change without changing the archived structural verdict.
- Repeated captures and syndicated copies do not inflate unique-event or corroboration counts.
- Increased crawl frequency, failed captures and LATEST_ONLY gaps remain visible in trend denominators.

**Authority gate:** U02/U03 for source-origin assertions and historical feature use. No inference is promoted by a dedup key.

**Source basis:** S01, S09, S15, S16.

### LI-05 — Freeze point-in-time, permission-checked historical evidence bases

**Release:** R2 · **Depends on:** LI-01, LI-03, LI-04 · **Risk:** HIGH

**Owner:** Historical review composition references P01/M05/P02 owners; it does not own their state.

**Existing basis:** Current ContextEnvelope handles bounded current observations; MonitorService supplies one before/after pair. It is not a historical multi-source context API.

**Implementation**

Build an immutable evidence-basis manifest for one competitor and question: explicit scope, [start,end) period, knowledge cutoff, baseline period, source/evidence/snapshot/assessment IDs and digests, current reuse decisions, exclusions, gaps and selection version. Distinguish claimed business-event date, observed/captured time, recorded/known-at time and analysis time. Select only information actually available at the frozen cutoff; later-discovered evidence creates a successor basis.

Introduce HistoricalEvidenceEligibility and a HistoryReviewContext implementing ModelContextAuthority. Check current permission to read/retain/disclose the older evidence while preserving capture-time provenance. Being too old to support a CURRENT claim is not automatically a ban on historical analysis, but neither is historical analysis a way around a withdrawn licence, quarantine or inaccessible bytes. Do not make those distinctions by weakening ContextEnvelope’s existing gates.

Bound selection and preserve its omissions: choose evidence using versioned deterministic rules, include disconfirming and rare high-consequence items, and expose excluded counts/reasons without leaking foreign-scope data. Respect input/token limits. Use permission-checked excerpts or deterministic summaries with drill-down; summary IDs never replace primary evidence IDs. If essential contrary evidence does not fit, return insufficient basis rather than silent truncation. Revalidate before dispatch, after return and before any release.

**ADD — proposed**

```text
app/Workflow/HistoryReviewBasis.php
app/Workflow/HistoricalEvidenceEligibility.php
app/Workflow/HistoryReviewContext.php
app/Workflow/HistoricalEvidenceSelector.php
database/migrations/270_history_review_bases.sql
ops/history/preview.php
```

**CHANGE / integrate**

```text
app/Workflow/ModelContextAuthority.php
app/Workflow/ContextEnvelope.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/unit/history_review_time_cut.php
tests/security/historical_evidence_eligibility.php
tests/integration/history_review_basis.php
```

**Proposed tables:** `history_review_bases`, `history_review_basis_refs`.

**Finished when**

- Late-arriving evidence is excluded from the old knowledge cut and creates a new basis when legitimately considered.
- Expired currentness, forbidden reuse, quarantined evidence and genuinely missing bytes have different explicit outcomes.
- Cross-tenant joins and silent dropping of contrary evidence fail; input overflow does not produce a complete-looking conclusion.

**Authority gate:** U03/U06 before model disclosure. Historical eligibility must be defined, not obtained by disabling current-use checks.

**Source basis:** S01, S03, S15, S16, S18.

### LI-06 — Implement the pure M17 window and profile laws

**Release:** R2 · **Depends on:** LI-01, LI-05 · **Risk:** MEDIUM

**Owner:** M17 derived temporal memory; tenant calendars remain declared configuration.

**Existing basis:** Canonical M17 supplies contracts and profile law but no runtime to port. Shared pure semantics need reference vectors, not claimed parity with nonexistent production code.

**Implementation**

Implement closed vocabulary, deterministic window assignment, profile resolution and density/volatility/importance calculations under the adopted M17 contracts. Start with supported P01 SOURCE_DIFF and COMPANY_SIGNAL inputs plus permitted entity/release history. Unknown or unavailable families fail conservatively; do not pretend M13 modality defaults or full EOM objects exist.

Keep capture cadence, review cadence, compression resolution and notification cadence separate. The architecture’s DAY→WEEK@14d→MONTH@90d ladder changes memory resolution; it does not schedule analysis at those dates. Use half-open intervals, declared timezone/calendar configuration, explicit UTC fallback, reproducible month boundaries and recorded timezone-rule assumptions. Include Stockholm DST and late-data fixtures.

The narrow-only resolver must record provenance, rejected widenings, risk/audit floors and same-level conflicts. Resolve numeric units, precision and canonical serialization upstream and test identical PHP/reference-vector results. Do not turn a source-diff magnitude into commercial importance; missing importance is explicit. Preserve conflict flags and high-diagnosticity observations across resolution changes.

**ADD — proposed**

```text
app/Substrate/M17/Vocabulary.php
app/Substrate/M17/WindowEngine.php
app/Substrate/M17/ProfileResolver.php
app/Substrate/M17/DynamicMeasures.php
app/Substrate/M17/InputSet.php
tests/fixtures/temporal_contract_vectors.json
docs/M17_PROJECTION_CONTRACT.md
```

**CHANGE / integrate**

```text
docs/PORT_SCOPE_MATRIX.md
docs/CONFORMANCE_MATRIX.md
```

**ADD acceptance and regression tests — proposed**

```text
tests/unit/temporal_windows.php
tests/unit/temporal_profiles.php
tests/conformance/temporal_vectors.php
```

**Finished when**

- Identical inputs/configuration produce identical windows and profile digests across repeated runs.
- DST, month-end, unknown class and conflicting profile cases have explicit reproducible outcomes.
- No model call, fetch, state admission, deletion or release is reachable from M17 pure engines.

**Authority gate:** U04 implementation authorization and selective projection adoption; architecture acceptance alone is insufficient.

**Source basis:** S15, S16, S17, S25.

### LI-07 — Persist M17 rollups, memory lineage, supersession and replay

**Release:** R2 · **Depends on:** LI-06 · **Risk:** HIGH

**Owner:** M17 stores only derived memory; original owners retain evidence/state/releases.

**Existing basis:** Seven canonical table families are proposed. Period/trend memory and compression lineage do not exist as an implemented PHP capability.

**Implementation**

Add the adopted seven M17 table families with immutable content, append-only transitions, scoped foreign keys and reference integrity checks. Runs freeze input set, profile snapshot, engine version, outputs and all relevant digests. Same basis is idempotent; late inputs, profile/engine revisions and redactions produce linked successors, never UPDATE of old summary content. A current-view index is rebuildable and is not the source of truth.

Implement only deterministic statistics, closed-template period summaries, change counts and supported trend-direction calculations. Human strategic notes remain labeled and attributable. MODEL_ASSISTED memory is inert under the current M17 design. A separate later historical-analysis workflow must not be used to sneak model prose into an M17 deterministic summary.

Expose read-only owner adapters to P01/P02/release histories. Carry uncertainty, conflicts, coverage, source-use limits and drill-down references. For this competitor lane, use full input lineage where feasible and never discard rare disconfirming references to keep only “top” examples. Replay verifies stored inputs and derivation without live fetching or fresh model judgment; unavailable inputs produce a scoped replay limitation rather than reconstruction.

**ADD — proposed**

```text
app/Substrate/M17/MemoryStore.php
app/Substrate/M17/RollupEngine.php
app/Substrate/M17/Lineage.php
app/Substrate/M17/Replay.php
app/Substrate/M17/P01HistoryAdapter.php
database/migrations/280_m17_temporal_memory.sql
ops/temporal/run.php
ops/temporal/replay.php
```

**CHANGE / integrate**

```text
tests/fixtures/schema_table_inventory.json
docs/SCHEMA_REACHABILITY.md
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/temporal_memory.php
tests/integration/temporal_successors.php
tests/security/temporal_owner_boundaries.php
```

**Proposed tables:** `temporal_compression_profiles`, `temporal_compression_profile_rules`, `temporal_compression_runs`, `temporal_rollups`, `temporal_memories`, `temporal_memory_status_transitions`, `compression_lineage_links`.

**Finished when**

- Every memory resolves to exact allowed owner-native inputs; a memory ID cannot satisfy evidence or release checks.
- A same-period late event can produce a successor without violating uniqueness or altering old digests.
- Repeated rollups and replay make zero provider/source calls and preserve contrary observations.

**Authority gate:** U04, including resolved successor uniqueness; no MODEL_ASSISTED activation.

**Source basis:** S15, S16, S17.

### LI-08 — Add an explicit historical-review policy and due detector

**Release:** R2 · **Depends on:** LI-01, LI-04, LI-05 · **Risk:** HIGH

**Owner:** New bounded review-coordination contract over M04 work policy; not M17 truth or general MCM authority.

**Existing basis:** Canonical GSMC has a fixture 1000-observation/30-day review-due mechanism. It does not semantically analyze a competitor’s history or guarantee a clock-driven revisit.

**Implementation**

Introduce versioned per-competitor/question ReviewPolicy: review interval, maximum unreviewed age, rolling/lookback/baseline windows, new-unique-event threshold, relevant source scope, explicit high-consequence/contradiction conditions, cooldown, maximum pending work and resource budget. Keep this distinct from source-monitoring frequency and M17 compression profiles. Do not ship 1000/30 days as a universal rule.

Evaluate cheap due conditions on new admitted observation references AND on explicit clock ticks so quiet streams do not starve. Supported v1 reasons are interval/max-wait due, new unique-event threshold, operator request, evidence/reliance invalidation and specifically defined condition matches. Semantic contradiction/pattern-change triggers require an existing evidence-backed detection result; an enum is not a detector. New detector families remain disabled until implemented and evaluated.

Persist why a review is due, its policy version, evaluated cutoff and proposed basis. Review due means a work recommendation, not spend or a conclusion. If the basis has not changed, record NO_NEW_EVIDENCE or a freshness/coverage review rather than rerunning the same model. A budget shortage creates visible deferred work, not a reset, an invisible skip or an urgency claim.

**ADD — proposed**

```text
app/Workflow/HistoryReviewPolicy.php
app/Workflow/HistoryReviewDue.php
app/Workflow/HistoryReviewPolicyStore.php
database/migrations/290_history_review_policy.sql
ops/history/policy.php
ops/history/due.php
```

**CHANGE / integrate**

```text
app/Workflow/OwnerDecisionRegistry.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/unit/history_review_due.php
tests/integration/history_review_policy.php
tests/security/history_review_policy_scope.php
```

**Proposed tables:** `history_review_policies`, `history_review_policy_events`, `history_review_due_checks`.

**Finished when**

- A time-only tick can request review even without a new capture; repeated calls are idempotent.
- Observed coverage, unique changes and origin groups are not collapsed into one raw observation count.
- Changing a policy creates a new version; a due result cannot call a model or admit its own policy.

**Authority gate:** U05 defines owner, thresholds and trigger semantics before admission; numbers are policy, not imported fixture constants.

**Source basis:** S07, S15, S17, S20.

### LI-09 — Coalesce review work and extend the existing scheduler safely

**Release:** R4 · **Depends on:** LI-01, LI-03, LI-08 · **Risk:** HIGH

**Owner:** Existing Jobs/Schedule owners execute admitted work; review coordination owns backlog membership.

**Existing basis:** PHP already implements ScheduleService, ScheduleDispatcher, typed MONITOR_STEP jobs and observe-and-queue. Three unread changed runs currently stop further scheduling.

**Implementation**

Add a separate immutable review-work identity and membership log that can coalesce pending observations for the same competitor/question/policy. The display queue can be compact while every observation and trigger remains retained. Maintain separate observed, queued, analyzed and human-reviewed watermarks; reading or folding a queue card is not review. Leave existing terminal Monitor runs and occurrence outcomes unchanged.

Preserve existing schedule v1 behavior. A new adopted accumulation mode may count bounded pending review groups rather than individual changed runs, but must also cap retained unreviewed work, byte volume, age and risk items. Do not simply raise the three-run limit, mark old runs “reviewed”, or allow indefinite accumulation. High-consequence/invalidation events cannot be buried by coalescing. Backpressure, maximum age and budget exhaustion must be visible.

Use the existing JobMachine/SchedulerRunner entry points for exact typed deterministic review-due and temporal-rollup jobs. Admit those operations through an explicit service contract, not the existing source observer seat by implication. Job payloads contain fixed IDs, expected revisions and basis/policy references—no callback, arbitrary URL, SQL or prompt. Apply non-overlap, idempotency, leases, bounded catch-up, starvation limits and crash recovery; execute no model call until separately authorized under U08.

**ADD — proposed**

```text
app/Workflow/HistoryReviewQueue.php
app/Jobs/HistoryReviewDueHandler.php
app/Jobs/TemporalRollupHandler.php
database/migrations/300_history_review_queue_jobs.sql
```

**CHANGE / integrate**

```text
app/Workflow/ScheduleService.php
app/Workflow/ScheduleDispatcher.php
app/Workflow/MonitorPrincipal.php
app/Workflow/MonitorService.php
app/Jobs/JobMachine.php
app/Jobs/SchedulerRunner.php
worker/tick.php
public_html/scheduler.php
ops/monitor/stand-down.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/history_review_queue.php
tests/security/history_review_queue_concurrency.php
tests/integration/history_review_job_recovery.php
```

**Proposed tables:** `history_review_work_items`, `history_review_work_memberships`, `history_review_work_events`.

**Finished when**

- A long sequence of routine changes does not silently stop observation under the new admitted mode, and all unreviewed evidence remains traceable.
- Duplicate ticks create no duplicate work/spending; a crash neither skips evidence nor pretends a human review occurred.
- The original scheduler role/mode still cannot infer, release or send; unknown job types refuse.

**Authority gate:** U05/U08 for changed queue semantics and deterministic review jobs. Existing scheduler-observer v1 rights remain unchanged.

**Source basis:** S02, S04, S07, S23.

### LI-10 — Add exact historical-analysis admission, budgets and durable provider bindings

**Release:** R3 · **Depends on:** LI-01, LI-02, LI-05 · **Risk:** HIGH

**Owner:** Existing M02/M03/M04/M06/M01 and execution-admission owners.

**Existing basis:** The existing provider pipeline already supplies guarded execution, immutable requests, result artifacts and recovery. Its closed purposes and mission limits do not describe repeated historical reviews.

**Implementation**

Define a reviewed historical-analysis purpose (working name HISTORICAL_REVIEW; final name follows U06) and bind the exact frozen review basis, configured question, model policy, prompt versions, credential identity, application fingerprint, target/disclosure scope and budget. A lookback horizon is not a grant-validity window. Old MODEL_EVALUATION or MANUAL_MONITOR grants must not silently authorize this new task.

Separate long-lived review-policy limits, parent spending authorization and per-review reservations. Count attempted calls, input/output limits and known/unknown provider usage across concurrent runs; successor policy versions do not refill budgets. Do not create fresh mission IDs merely to bypass the existing four-call mission context cap. Any new delegation/lifetime budget law is an explicit canonical change with race tests.

Reuse PreparedModelRequest, ModelExecutor, ModelArtifactStore and credential/transport boundaries with a dedicated context authority and trusted history-role bundle. Link durable attempts/results to history-review run and basis identities through an additive purpose/provenance relation. Persist recoverable outputs before advancing review state. Unknown network outcomes stop for reconciliation; no automatic paid retry. Recheck permissions and basis eligibility after return and at release. Current source-observer service rights stay model-free.

**ADD — proposed**

```text
app/Workflow/HistoryReviewAdmission.php
app/Workflow/HistoryReviewReconciliation.php
database/migrations/310_history_review_execution.sql
ops/history/authorization.php
ops/history/reconcile.php
```

**CHANGE / integrate**

```text
app/Workflow/ExecutionGrantStore.php
app/Workflow/ExecutionAdmission.php
app/Workflow/OwnerDecisionRegistry.php
app/Provider/PreparedModelRequest.php
app/Provider/ModelExecutor.php
app/Provider/ModelArtifactStore.php
app/PromptOps/PromptApprovalStore.php
app/PromptOps/PromptSelectionResolver.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/security/history_review_admission.php
tests/security/history_review_budget_races.php
tests/integration/history_review_attempt_recovery.php
```

**Proposed tables:** `history_review_attempt_bindings`.

**Finished when**

- A review runs only under its own exact admitted purpose, basis, prompt, principal and resource scope.
- Concurrent reviews cannot exceed parent limits; expired/revoked grants stop before subsequent effects.
- Crash after provider return can adopt a verified retained result once, while an unknown result is not re-created with another call.

**Authority gate:** U06 for manual historical analysis; U08 separately for any unattended inference. No current grant is extended.

**Source basis:** S03, S04, S05, S21, S23.

### LI-11 — Build a bounded historical-analysis and challenge workflow

**Release:** R3 · **Depends on:** LI-05, LI-07, LI-10 · **Risk:** HIGH

**Owner:** Adopted bounded historical-review composition; specialist diagnosis semantics remain with MCM where invoked.

**Existing basis:** Current MonitorService is a per-source before/after pipeline. Full canonical MCM is not an implemented general investigation engine.

**Implementation**

Add HistoryReviewService/Journal/Factory as an owner of a distinct review lifecycle, not as a second owner of Monitor runs. Compose existing evidence, provider and governance services. The new run freezes its basis and references previous reviews; it never reopens a terminal Monitor run. Initial states should distinguish basis-ready, request-inspection-required, attempted, candidate-retained, challenge-required, human-review-required, held, no-new-insight and complete; final names are contract-controlled.

Create versioned historical analyst and critic roles. Their bounded output contains: question and timeframe; observed sequence; supported changes; alternative explanations including routine maintenance/no meaningful shift; primary evidence references; contradictory evidence and coverage limitations; hypothesis versus directly supported claim; what would change the assessment; and a proposed next check. A citation to a real source ID is necessary but not proof that the sentence follows. A second LLM critic is not an independent human or empirical validation.

Use M17 statistics as labeled context, not replacement evidence. When the original material is required for a claim, include it under the frozen bounded selection. Do not convert a narrative synthesis into M17 MODEL_ASSISTED memory by naming it a review. Add a new comparison result only when the evidence or permitted question changes; preserve NO_NEW_INSIGHT/HOLD/ABSTAIN as real outcomes.

More evidence collection, wider windows and specialist calls are proposed follow-up work, not self-granted tools. A bounded review cannot turn a few website edits into a certified causal diagnosis, buying intent or a formally established Permansson regime.

**ADD — proposed**

```text
app/Workflow/HistoryReviewService.php
app/Workflow/HistoryReviewJournal.php
app/Workflow/HistoryReviewFactory.php
app/PromptOps/HistoryReviewCandidateChecks.php
app/PromptOps/bundles/history-review-v1.json
database/migrations/320_history_review_runs.sql
ops/history/review.php
ops/history/replay.php
```

**CHANGE / integrate**

```text
app/PromptOps/PromptBundleRegistry.php
app/Provider/ModelRequestBuilder.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/history_review_workflow.php
tests/security/history_review_untrusted_inputs.php
tests/unit/history_review_candidates.php
```

**Proposed tables:** `history_review_runs`, `history_review_events`, `history_review_artifact_refs`.

**Finished when**

- A combined pattern can be considered without revising the original nonmaterial assessments or inventing evidence.
- Ordinary-maintenance and contrary-evidence cases can end in NO_NEW_INSIGHT/HOLD rather than always finding a strategy.
- The analyst cannot fetch, widen scope, authorize another attempt, approve itself or notify recipients.

**Authority gate:** U06 and frozen quality criteria. Initial use requires human request inspection and human review; this is not full MCM or autonomous research.

**Source basis:** S02, S03, S15, S18, S19, S26.

### LI-12 — Add legitimate multi-evidence proposal and release adapters

**Release:** R3 · **Depends on:** LI-01, LI-11 · **Risk:** HIGH

**Owner:** P01 owns product findings; M04/M05/M07/M08/M10 and applicable M16/P04 paths retain their roles.

**Existing basis:** ReleaseStages.releaseP01 currently constructs a CompanySignal from one monitored source and one source_diff. That is not a valid general representation of a multi-source temporal conclusion.

**Implementation**

Add an explicit canonical route for a historical finding to cite a submitted multi-evidence basis. Extend the existing CompanySignal/proposal owner only under that contract, or adopt a separately named native analytical object where appropriate. Never choose one arbitrary diff as the fake origin of the entire inference; never mint synthetic source captures or treat memory IDs as evidence. Preserve all direct claim-support and contrary references.

Compose independent review, admissibility and M10 recording, then the applicable P01 M16 representation. P04 remains a separate internal routing lifecycle over released intelligence. A full MCM diagnosis, if introduced later, must use its own diagnostic admission route before any product representation; do not force it through the wrong CompanySignal semantics or make M09 a universal information gate.

Link the actual human review to the exact historical candidate/basis, preserving model-critic identity separately. Recheck current evidence rights, source status and decision reliance at release. Any proposal to update the competitor’s P02 twin remains a separate governed state-change proposal; storing a historical observation never silently mutates the twin.

**ADD — proposed**

```text
app/Workflow/HistoryReviewRelease.php
app/Product/P01/HistoricalFindingAdapter.php
database/migrations/330_historical_finding_bindings.sql
```

**CHANGE / integrate**

```text
app/Product/P01/CompanySignals.php
app/Product/P01/Battlecards.php
app/Product/P01/Release.php
app/Substrate/M08/PolicySets.php
app/Substrate/M08/RuleEngine.php
app/Workflow/ReleaseStages.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/conformance/historical_finding_admission.php
tests/security/historical_finding_release.php
tests/integration/history_review_to_internal_release.php
```

**Proposed tables:** `historical_finding_basis_bindings`.

**Finished when**

- Every released temporal finding has actual multi-evidence support, a bound review and valid M10/current-reliance lineage.
- Missing authority, unknown reuse rights or a memory-only basis block release even when the prose is persuasive.
- No P02 state, P04 recipient set or customer visibility changes merely because a review completed.

**Authority gate:** U07 must specify the canonical multi-evidence contract. Until then the historical result remains provisional/internal and unreleased.

**Source basis:** S06, S10, S11, S18.

### LI-13 — Separate internal attention, digest inclusion and immediate interruption

**Release:** R4 · **Depends on:** LI-03, LI-08, LI-12 · **Risk:** HIGH

**Owner:** Product attention policy composes existing P04/M16/M12 release and visibility owners.

**Existing basis:** Current P04 is governed in-console role distribution. FINDING is not itself a notification grant, and unreleased history must not leak into released briefing surfaces.

**Implementation**

Add an admitted attention policy over already eligible internal outputs: retain-only, ordinary digest, immediate internal attention, or hold for review. These are product routing dispositions with a reason and target role, not a new truth grade. Preserve observation, business materiality, confidence, urgency and visibility as distinct fields. The analyst may recommend but cannot set effective delivery policy.

Deduplicate attention by finding lineage, materially changed conclusion, audience and policy—not just identical text. Record suppression reasons, cooldown, prior notification reference and escalation of a genuinely new contrary/material result. A new hash or paraphrase is not a new alert. Risk-sensitive maxima prevent important work being buried in a low-priority digest.

Build internal digest membership from permitted released artifacts and current reliance. Unreleased observations can appear only on their authorized operator history/work queues, clearly separate from a customer/internal-release briefing. Read/seen/acknowledged UI state is not M07 review or M10 admission. Do not implement external delivery as a side effect of opening a page or completing a model call.

**ADD — proposed**

```text
app/Product/P04/AttentionPolicy.php
app/Product/P04/InternalAttention.php
app/Product/P04/InternalDigest.php
database/migrations/340_internal_attention.sql
ops/history/attention.php
```

**CHANGE / integrate**

```text
app/Product/P04/Distribution.php
app/Product/P04/Release.php
app/Http/ReadAdapters/MonitorReadAdapter.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/unit/internal_attention_policy.php
tests/security/internal_attention_visibility.php
tests/integration/internal_digest_deduplication.php
```

**Proposed tables:** `internal_attention_policies`, `internal_attention_events`, `internal_digest_memberships`.

**Finished when**

- A navigation reorder can stay in history forever without producing an attention event.
- An admitted multi-event finding can be queued for the correct configured role without external sending.
- Reworded/replayed results, withdrawn releases and foreign-role evidence cannot create duplicate or unauthorized visibility.

**Authority gate:** U09 internal attention semantics; external email/SMS/push/webhooks remain outside this scope.

**Source basis:** S06, S08, S11, S25.

### LI-14 — Record follow-up conditions, outcomes and changed assessments

**Release:** R3 · **Depends on:** LI-11 · **Risk:** MEDIUM

**Owner:** Historical-analysis owner records its own outcomes; M11 assures process, not universal outcome truth.

**Existing basis:** Run/release history exists; the new longitudinal question needs its own explicit successor and follow-up linkage.

**Implementation**

Persist the frozen question, current hypothesis disposition, disconfirming indicators, expected next evidence, review deadline and reliance status. A proposed follow-up is not a source-fetch or provider grant. Link later observations to the earlier assessment through append-only outcome annotations with who/when/how-established provenance.

Support confirmed-in-scope, contradicted, still unresolved, coverage insufficient and superseded dispositions without inventing event probability or a causal outcome. Keep what was knowable then separate from what became known later. Past nonmaterial observations can later become useful in a new conclusion without rewriting their original date-time assessment.

Derive bounded performance/utility signals from genuinely adjudicated outcomes, including no-insight and held cases. These may support an M11 finding or a governed improvement proposal, never automatic prompt edits, weight updates, notification thresholds or schedule changes. Do not label an evaluator’s self-score as business success.

**ADD — proposed**

```text
app/Workflow/HistoryReviewOutcomes.php
app/Workflow/HistoryReviewFollowUp.php
database/migrations/345_history_review_outcomes.sql
ops/history/outcomes.php
```

**CHANGE / integrate**

```text
app/Workflow/HistoryReviewService.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/history_review_outcomes.php
tests/security/history_review_no_self_adaptation.php
```

**Proposed tables:** `history_review_outcome_annotations`, `history_review_followup_conditions`.

**Finished when**

- Later evidence creates a linked new disposition while the original result and knowledge cut stay intact.
- A follow-up request cannot collect data or spend budget without its separate authority.
- Unresolved and false-positive outcomes remain in evaluation denominators.

**Authority gate:** U06/U10 outcome semantics; learned prompts/weights/cadences require separate evolution approval.

**Source basis:** S18, S19, S25, S26.

### LI-15 — Propagate evidence invalidation through histories, memories and releases

**Release:** R3 · **Depends on:** LI-05, LI-07, LI-12 · **Risk:** HIGH

**Owner:** Source/evidence owners decide source status; each derived owner records consequences; M10 owns release reliance.

**Existing basis:** Current request/release code revalidates authority. A longitudinal dependency graph needs explicit treatment when older supporting evidence is quarantined, corrected, redacted or no longer reusable.

**Implementation**

Maintain typed dependency references from assessment → basis → rollup/memory → historical candidate → released representation/attention. Invalidation creates explicit pending-review, unavailable, superseded or redaction-affected events under each owner’s status vocabulary. Do not silently edit old content or make an old invalidated source look newly verified.

A scheduled scan or owner lifecycle event may identify affected derivatives, but the update of canonical evidence/state/current release reliance goes through its own owner path. Block new use immediately where current permission fails; preserve audit history subject to access/retention policy. Distinguish content no longer supported, source rights no longer allowing reuse and historical content merely not current.

Privacy/redaction propagation must cover safe summaries, model-input manifests, private candidate artifacts, indexes and UI projections as well as raw bytes. M17 itself has no delete path. Any actual erasure/disposal is a separate approved owner operation, not the compression engine. Re-derivation creates a successor and reports missing lineage honestly.

**ADD — proposed**

```text
app/Workflow/HistoryDependencyIndex.php
app/Workflow/HistoryInvalidation.php
database/migrations/350_history_dependencies.sql
ops/history/invalidation.php
```

**CHANGE / integrate**

```text
app/Substrate/M17/Lineage.php
app/Substrate/M17/MemoryStore.php
app/Workflow/HistoricalEvidenceEligibility.php
app/Product/P04/InternalAttention.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/history_invalidation.php
tests/security/history_redaction_propagation.php
tests/security/history_release_reliance.php
```

**Proposed tables:** `history_dependency_links`, `history_invalidation_events`.

**Finished when**

- Quarantining one load-bearing source identifies all dependent results without modifying the original history.
- A revoked source cannot be reused through a cached summary, replay screen or already-queued review.
- Release withdrawal/replacement remains M10-owned and source erasure remains separately authorized.

**Authority gate:** U03/U10 define dependency effects. No auto-delete, auto-heal or automatic M10 withdrawal outside an adopted policy.

**Source basis:** S03, S06, S15, S16, S17.

### LI-16 — Add an operator history and review workbench without widening metadata reads

**Release:** R1_INCREMENTAL · **Depends on:** LI-03 · **Risk:** MEDIUM

**Owner:** P01/M17/history owners supply read models; M12/HTTP provides authorized presentation.

**Existing basis:** React run metadata and SSR request/review controls already exist. P02/P03 index and detail reads are already wired.

**Implementation**

Deliver in slices: R1 competitor/source history and assessment explanation; R2 period/coverage/rollup views and review-due queue; R3 frozen historical basis, candidate/challenge and human review; R4 internal digest/attention log. Gate each slice on actual owner support instead of rendering invented data. Do not rebuild existing P02/P03 detail screens or show a fabricated relationship graph.

Read-only pages should answer: what was observed; which period and knowledge cut this view represents; why review is due or deferred; what was excluded; how many captures versus distinct changes/origins; what contradicts the current interpretation; and why an alert was suppressed. Include drill-down to exact permitted evidence and prior dispositions. Use stable cursor pagination, capped page sizes and indexes rather than reading a competitor’s entire archive on every request.

Keep the existing metadata adapter contract metadata-only. Private source/candidate text requires a separate owner-authorized route, preferably the existing scriptless workbench pattern. All mutations are explicit body-only CSRF-protected commands with exact basis/revision digests. Escape model/source text; no GET inference, auto-prefetch, silent retries, tenant selector injection or raw prompt leakage.

Reuse the existing WatchlistRead competitor/readiness list as the entry point; do not create a duplicate competitor registry screen. Add only permission-aware history navigation. Keep its existing guarantee of no source URLs, raw evidence, credentials or capture capability; a watchlist read permission alone does not imply historical evidence-read permission.

**ADD — proposed**

```text
app/Http/ReadAdapters/CompetitorHistoryAdapter.php
app/Http/ReadAdapters/TemporalMemoryAdapter.php
app/Http/ReadAdapters/HistoryReviewAdapter.php
app/Http/Controllers/HistoryReviewController.php
app/Http/templates/history-review.php
frontend/console/src/views/p01/competitor-history.jsx
frontend/console/src/views/p01/history-reviews.jsx
```

**CHANGE / integrate**

```text
app/Http/Controllers/ConsoleReadController.php
public_html/index.php
frontend/console/src/data/http-console-data-source.js
frontend/console/src/data/live-read-adapters.js
frontend/console/src/data/navigation.js
app/Http/templates/monitor.php
app/Product/P01/WatchlistRead.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/security/history_http_boundaries.php
tests/integration/history_read_models.php
frontend/console/tests/history-views.test.js
```

**Finished when**

- R1 history displays nonmaterial observations without presenting them as released intelligence.
- Every later pane identifies its period/basis/reliance and shows missing/unsupported states honestly.
- Authorization, no-prefetch/no-GET-work, CSRF, escaping, stable pagination and mobile/desktop inspection pass.

**Authority gate:** U01/U02 for history fields; later panes require their own completed packages and permissions. GET never starts work.

**Source basis:** S08, S11, S23, S28.

### LI-17 — Extend M11 assurance and operational observability

**Release:** CROSS_CUTTING · **Depends on:** LI-03, LI-05, LI-07, LI-08 · **Risk:** MEDIUM

**Owner:** M11 assurance owns audit findings; feature owners retain their semantics.

**Existing basis:** P01/P02 assurance and P03 assurance producers exist. The new historical graph introduces new invariants and runtime failure modes.

**Implementation**

Add the six M17 audit families defined by the contracts: lineage discipline, conflict preservation, hold discipline, isolation, replay divergence and redaction propagation. Use existing M11 audit/incident/replay infrastructure, with domain-specific readers, not a parallel audit database. Maintain the distinction between process integrity and the natural-language truth of a claim.

Expose source coverage lag, oldest unreviewed item, due-to-start latency, coalesced pending members, refused/unknown attempts, spent/reserved model budget, usage-accounting completeness, memory staleness, invalidated dependencies and attention suppression. Operational counters may be rebuilt; authoritative work history and spending reservations may not disappear. Record WAITING_FOR_OPERATOR/DEFERRED_BUDGET as explicit states, not a retry storm.

Keep logs to fixed codes, identities and digests; do not log raw prompts, source bodies, rejected candidates or secrets. Alerts about broken machinery go to authorized operator surfaces. Run existing P03 and other owner audits on an admitted schedule or explicit operator procedure; code presence is not an operating assurance program.

**ADD — proposed**

```text
app/Substrate/M17/Assurance.php
app/Workflow/HistoryReviewAssurance.php
ops/assurance/history.php
ops/assurance/temporal.php
docs/HISTORY_OPERATIONS_SCORECARD.md
```

**CHANGE / integrate**

```text
app/Ops/RuntimeInvariantProbe.php
app/Ops/StandDownPosture.php
app/Workflow/ScheduleDispatcher.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/temporal_assurance.php
tests/security/history_telemetry_minimization.php
tests/integration/history_assurance_negative_controls.php
```

**Finished when**

- Each audit fails on a planted violation and does not change the audited subject to make itself pass.
- Missing evidence, stalled review and incomplete metering are visible without private content leakage.
- No model-quality or production claim is inferred from passing schema/digest audits.

**Authority gate:** U04/U05 for audit subjects and separately admitted recurring audit work; an audit is not authority to repair.

**Source basis:** S11, S15, S16, S18, S24.

### LI-18 — Build longitudinal evaluation before tuning the new model behavior

**Release:** CROSS_CUTTING · **Depends on:** LI-01 · **Risk:** HIGH

**Owner:** Evaluation owners freeze cases and scoring; independent people adjudicate grounding and usefulness.

**Existing basis:** The existing evaluation lane is valuable but its independent cases do not test a multi-week evidence accumulation loop.

**Implementation**

Commission a frozen sequence benchmark before tuning successor prompts. Each case includes timed captures, true event identity, publication/known-at cut, origin/correlation groups, source-rights changes, expected review triggers, allowable claim strength, expected attention disposition and permitted costs. Preserve the original 18-case manifest and DEV7 history; label the extended benchmark as a new version. Keep genuinely held-out sequences out of prompt tuning and report any contamination.

Exercise negative controls and positive cases: menu-only noise; tiny true price change; many copies of one event; slow drift; a weak observation that later matters; an apparent trend later contradicted; missing coverage; delayed evidence; withdrawal/redaction; rare high-consequence facts; incomplete basis; malicious old source text; repeated alerts; busy queues; and human hold/no-insight decisions. Test the full journey from collection through history, due checks, basis, analysis, review and internal routing.

Report distinct metrics rather than one score: observation retention/retrieval, coverage awareness, review timing/recall, false review rate, claim grounding, contradiction preservation, alert precision/recall/duplication, latency, human burden, cost and unknown usage. Fix thresholds before qualification; every failed/held/missing case stays in the appropriate denominator. Compare the proposed system against the current per-diff workflow and a simple periodic-review baseline. A finite pass never means universal safety or correct strategy inference.

**ADD — proposed**

```text
app/Evaluation/HistoricalSequenceEvaluationRunner.php
app/Evaluation/HistoricalSequenceReport.php
tests/fixtures/historical_review_development_cases.json
tests/fixtures/historical_review_heldout_manifest.json
ops/evaluation/history.php
docs/HISTORICAL_REVIEW_EVALUATION_PROTOCOL.md
```

**CHANGE / integrate**

```text
app/Evaluation/EvaluationFactory.php
app/Evaluation/EvaluationReport.php
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/historical_sequence_evaluation.php
tests/security/historical_evaluation_separation.php
tests/unit/historical_sequence_scorecard.php
```

**Finished when**

- Offline cases make zero provider calls; synthetic evaluation cannot write real competitor history or product releases.
- Held-out and independent human scoring cover chronology, unsupported patterns, silence and false-positive attention—not just JSON correctness.
- A failed/missing sequence or incomplete usage produces an honest report and cannot activate prompts.

**Authority gate:** Offline sequence fixtures first; funded synthetic evaluations and real shadow analysis each need separate grants.

**Source basis:** S05, S12, S14, S15, S18, S19, S21.

### LI-19 — Make schema, storage, backup, recovery and stand-down cover the new graph

**Release:** CROSS_CUTTING · **Depends on:** LI-00, LI-01 · **Risk:** HIGH

**Owner:** Projection database/Ops owners; retention and source owners retain disposal decisions.

**Existing basis:** 31 migrations, maintenance fencing, BackupManifest, private EvidenceStore and qualification tooling exist. Historical 900 has already run on upgrade targets.

**Implementation**

Treat every proposed migration number as tentative until checked at implementation head. Add migrations; never edit an applied file’s checksum or assume 900 will run again after 260+. Prove empty installation, old 31-migration upgrade, second-run no-op, immutable old rows, trigger enforcement and exact schema equivalence on supported MariaDB lanes. Use composite scope constraints and indexes for competitor/time/basis/status queries; define numeric/time serialization once.

Extend BackupManifest and consistency verification to new immutable tables, history references, retained candidate artifacts and M17 lineage. A populated restore must prove old and new journal digests, evidence/artifact availability, current-reliance reconstruction and budget totals, with no scheduler automatically starting. Derived indexes can be rebuilt; immutable history and consumed attempts cannot. MaintenanceGate must fence the new writers as well as existing Monitor work.

Include payload/index size budgets, query plans, capped pagination, bounded time-window scans and chunked processing for long histories. Exercise source lock/review lock ordering and short DB transactions; no network call while holding a long write transaction. Stand-down revokes/suspends new review grants and job handlers, stops new work and preserves audit history. Source erasure or disposal remains a separately authorized operation, not a performance optimization.

The audited Railway Dockerfile pins php:8.5.12-apache-bookworm and runs selected lint/foundation/unit checks, with DB-backed tests explicitly separate. Qualify the selected release on the actual target PHP/runtime and disposable MariaDB, in addition to supported baseline lanes. A successful image build is not a full DB-backed application qualification. Do not silently replace an existing supported PHP lane when adding target-runtime coverage.

**ADD — proposed**

```text
docs/HISTORY_SCHEMA_AND_STORAGE.md
docs/HISTORY_BACKUP_RESTORE_RUNBOOK.md
ops/history/verify-integrity.php
```

**CHANGE / integrate**

```text
app/Ops/BackupManifest.php
app/Ops/MaintenanceGate.php
app/Ops/StandDownPosture.php
app/Ops/RuntimeInvariantProbe.php
ops/deploy/preflight.php
ops/rollback/verify-stand-down.php
tests/fixtures/schema_table_inventory.json
ops/ci/qualify-release.sh
Dockerfile
```

**ADD acceptance and regression tests — proposed**

```text
tests/integration/history_migration_upgrade.php
tests/integration/history_populated_restore.php
tests/security/history_maintenance_and_standdown.php
tests/integration/history_query_budgets.php
```

**Finished when**

- Fresh and upgrade databases converge with all old append-only history and migration hashes preserved.
- A populated backup restores the complete selected history and refuses missing private artifacts instead of inventing them.
- Stand-down leaves no admitted work capable of a new source/model effect; historical accounting and digests remain intact.

**Authority gate:** Additive migrations and offline qualification only; deployment/restore operations need their normal explicit approval.

**Source basis:** S01, S07, S13, S14, S22, S24, S27.

### LI-20 — Qualify and roll out the capability in separate operational gates

**Release:** ROLLOUT · **Depends on:** LI-00, LI-01 · **Risk:** HIGH

**Owner:** Owner/operator release authority, projection Ops and quality reviewers.

**Existing basis:** The old operational backlog remains relevant but has stale SHAs and partly superseded evaluation history; reconcile it instead of running it verbatim.

**Implementation**

Gate R1 independently: exact source qualification, protected inert deployment and an authorized fixture/retained-history demonstration that quiet observations remain inspectable. Gate R2 on deterministic window/profile parity, genuine due-queue behavior, late-data successors, invalidation and populated recovery. No funded model is required to demonstrate these deterministic capabilities.

Gate R3 on fresh bounded synthetic historical evaluations, independent human scoring, then one explicitly authorized real-source manual historical review. Inspect exact requests and record actual human review. If a multi-evidence release contract is absent, stop at the permitted provisional artifact rather than faking full completion. Export sanitized provenance, not raw private material. Immediately exercise stand-down and replay.

Gate R4 separately on service identity, admitted deterministic review/rollup jobs, coalescing/backpressure, periodic clock-only reviews and internal digest attention. Any unattended model inference needs its own reviewed disclosure/request policy, grant budget and service authority; leave it absent by default. Verify the actual Railway deployment/volume/job trigger path; do not assume older Loopia SSH instructions apply unchanged.

Retain all still-open MVP requirements: actual-host DB/runtime compatibility, authentication/negative surface smokes, desktop/mobile review UX, source rights, full quality acceptance, populated backup/restore, key recovery and production preflight. Customer-visible output, outbound channels and production remain separate gates. No feature merge proves a real schedule or deployment exists.

**ADD — proposed**

```text
docs/LONGITUDINAL_RELEASE_ACCEPTANCE.md
ops/road-test/export-history-review-evidence.php
ops/history/shadow-report.php
```

**CHANGE / integrate**

```text
docs/MVP_RELEASE_STATE.md
docs/POST_MERGE_TEST_BACKLOG.md
docs/BETA_RUNBOOK.md
docs/DEPLOYMENT_RUNBOOK.md
deployment-evidence.json
```

**ADD acceptance and regression tests — proposed**

```text
tests/acceptance/longitudinal_intelligence_journey.php
tests/security/longitudinal_default_inert.php
```

**Finished when**

- The selected release has reproducible exact-head code, host, quality, recovery and human-review receipts—not inferred completion.
- Every optional automation is inactive until its own authority and tests are present.
- The product can demonstrate quiet retention → due historical review → supported internal finding → no duplicate interruption.

**Authority gate:** Separate exact-release qualification, deployment, funded evaluation, manual pilot, recurrence and customer-output decisions.

**Source basis:** S07, S13, S14, S21, S25, S27.

### LI-21 — Later: bounded meta-attention and meta-meta health evaluators

**Release:** FUTURE_OPTIONAL · **Depends on:** LI-07, LI-08, LI-11, LI-14, LI-17, LI-20 · **Risk:** HIGH · **Optional later extension**

**Owner:** Canonical MCM evaluator/router semantics; M11 records assurance; owners decide corrections.

**Existing basis:** Full recursive MCM runtime is not available. The current diagnostic MVP is a fixture skeleton; the architecture expressly separates higher-level evaluators from state/action owners.

**Implementation**

Only after the basic review loop has real outcome evidence, add fixture-first evaluators over recorded review/work outcomes. Meta attention can recommend which competing question deserves budget or independent review; meta-meta health can flag starvation, repeated unsupported narratives, alert churn, stale evidence, deadlocks or budgets repeatedly spent without new information.

Pin one meta evaluator and one meta-meta sentinel per governed scope. Their outputs are typed attention/review/correction proposals, never direct prompt edits, weight changes, new permissions, schedule mutations, state admission or recursive spawning. Reuse M11 records and existing review/decision owners. A correction is not a rollback command, and runtime reliability observations do not automatically become admitted scoring weights.

Do not claim full MCM from a port of its six-fixture skeleton. Soft association, explicit diagnostic gates, source trust/authority views, residual-aware branching, specialist reliability, validated cold-start policies and causal/first-break claims require their own upstream phases and evidence. FFBBP, RACR, Forecasting, Strategic Core, EOM, AEC and broader research tools remain optional owner-native integrations, not mandatory steps for every website review.

**ADD — proposed**

```text
docs/META_REVIEW_EXTENSION_PLAN.md
app/Analysis/MetaAttentionEvaluator.php
app/Analysis/MetaHealthEvaluator.php
tests/fixtures/meta_review_evaluator_cases.json
```

**CHANGE / integrate**

```text
app/Workflow/HistoryReviewAssurance.php
docs/PORT_SCOPE_MATRIX.md
```

**ADD acceptance and regression tests — proposed**

```text
tests/unit/meta_attention_evaluator.php
tests/security/meta_review_no_authority_expansion.php
```

**Finished when**

- Evaluators recommend attention/corrections but cannot change policy, spend, state, permissions or schedules.
- A fourth recursive layer cannot be spawned; all outputs bind recorded scoped outcomes.
- Fixture performance and actual operating evidence are reported separately; no full-MCM or autonomous-research claim is manufactured.

**Authority gate:** U10 and the relevant canonical MCM implementation/activation gates. Not a prerequisite for the first longitudinal Monitor release.

**Source basis:** S18, S19, S26.

## 8. Migration and file ownership rules

The numbered SQL files in this plan use currently unused planning slots beginning at 260. They are **not reserved**, and file names may change after exact-head reconciliation. The schema at the audited baseline has 31 migrations, including `250_monitor_schedule_control.sql` and the already-applied historical `900_deferred_foreign_keys.sql`. An upgrade must not require replaying 900 or editing the digest of an old migration. [S22]

| Proposed migration slot | Main subject | Prerequisite decision |
|---|---|---|
| 260 / 265 | Observation assessments, derived features and origin assertions | U01/U02/U03 |
| 270 | Frozen historical review bases and references | U03/U06 |
| 280 | Seven M17 memory families with repaired successor identity | U04 |
| 290 | Review policy and due-check records | U05 |
| 300 | Coalesced review work and typed deterministic jobs | U05/U08 |
| 310 / 320 | Review-purpose attempt binding and historical run lifecycle | U06; U08 only for later service inference |
| 330 | Legitimate multi-evidence finding/release bindings | U07 |
| 340 | Internal attention policy, events and digest membership | U09 |
| 345 / 350 | Outcomes, follow-up conditions and invalidation dependencies | U03/U10 |

Scope keys and ownership constraints must cover tenant **and** workspace, not only a convenient UUID foreign key. Cross-owner typed refs need owner-side validation at write/read and integrity audits where the database cannot express the full relation. Index history by scope/competitor/time/stable ID; pending work by scoped state/due time; dependencies by target/ref type/ref ID. Plan query budgets on populated data, not only empty fixtures.

Use transactionally consistent event/reference writes or a reviewed idempotent reconciliation mechanism at boundaries. Do not silently add a generic event bus with independent truth ownership. Materialized indexes are rebuildable; authoritative history, model attempts, budget consumption and admitted decisions are not disposable caches. [S01, S04, S07, S15]

## 9. Acceptance scenarios and quality gates

Every feature package has unit/integration/security tests above. The release gate additionally needs these end-to-end cases. Expected outcomes are proposed acceptance criteria; none has been run during this planning pass.

| Scenario | Required behavior |
|---|---|
| Navigation reordered, nothing commercial changed | Describe/retain under the right competitor, no material finding or immediate attention |
| One small price or negation change | Do not lose a material business fact because the structural distance is small |
| Twenty identical captures | Coverage grows; distinct business-event evidence does not |
| Ten articles copying one release | Origin dependence is visible; no invented ten-source corroboration |
| Several weak observations combine later | New review may consider the sequence; old weak assessments remain unchanged |
| Routine redesign + unrelated hiring | Rival/no-change explanation can win; no compulsory strategic narrative |
| Contradictory source after apparent trend | Contrary evidence survives selection, compression, critic and release review |
| Rare high-consequence event in a quiet period | Maximum-wait/risk policy prevents indefinite starvation or lossy omission |
| Monitoring frequency doubles | Raw event density is not automatically a business acceleration claim |
| Source outage / skipped scheduler slots | Report coverage gap; no “competitor inactive” conclusion |
| Backdated event arrives late | Old knowledge cut excludes it; new basis is an explicit successor |
| Timezone/DST/month boundary | Stable half-open window assignment and reproducible profile/basis digests |
| Current source status changes during a model call | Spent attempt remains spent; downstream acceptance/release rechecks current permission |
| Historical source is no longer current | Use only under an explicit historical-use policy, never as current fact by implication |
| Evidence quarantined, rights revoked or content redacted | Affected downstream artifacts are identified; new use fails; owner-governed reliance/disposal follows |
| Model invents citation or mixes a summary with primary evidence | Reference/type checks refuse; summary cannot launder itself into evidence |
| True citation but unsupported narrative | Semantic/independent human quality checks fail despite structural validity |
| Model refuses or output fails candidate checks | Preserve attempt, known usage and safe code; do not store unsafe text or count as success |
| Model timeout after possible spend | No automatic replay as a fresh paid call; explicit reconciliation |
| Parallel scheduler/review attempts | One work identity/reservation; no duplicate provider call, artifact or alert |
| Four routine changed runs without human draining | New admitted accumulation policy coalesces bounded work without deleting evidence or falsifying review |
| Queue/budget saturation | Visible defer/backpressure with maximum-age/risk reporting, not unlimited accumulation |
| Same basis reviewed twice | Deterministic replay/no-op or explicit policy-justified successor; no accidental repeat spend |
| Same finding paraphrased | No new alert merely from different wording |
| Source or release from a sibling workspace | Default-deny with no existence/text leakage |
| Synthetic sequence evaluation | Never becomes a real competitor observation or product release |
| Upgrade after historical migration 900 | Apply only new migration files; old hashes/events remain identical |
| Populated restore | Complete evidence/artifact/lineage/journal/budget verification; automatic work remains off |
| Stand-down during queued/running work | No subsequent authorized effect; historical attempts and results remain inspectable |

### 9.1 Separate measurement questions

Measure retention/retrieval completeness, review timing, unsupported-pattern rate, contradiction preservation, useful finding rate, attention precision/recall/duplication, coverage awareness, latency, operator burden and resource use separately. Set actual numeric acceptance thresholds before the new live evaluation; no threshold here is disguised as an established product fact.

Compare at least the current per-diff workflow, a simple periodic-review baseline and the proposed event-plus-time coordinator on the same frozen sequences. Preserve denominator details: not-run cases, refusals, held cases, no-insight outcomes, missing human assessments and unknown token usage. Passing deterministic tests proves machinery, not model reasoning or production readiness. [S05, S14, S18, S19, S21]

## 10. Worked target behavior (illustrative, not current runtime)

Assume an owner has explicitly configured lawful daily capture, a weekly review checkpoint and a 30-day lookback, plus a narrowly defined earlier-review condition. These numbers are examples, not repository defaults or a recommended universal cadence.

| Time | What is observed | What the proposed system does |
|---|---|---|
| Day 1 | Pricing moves before Studios in navigation | Retain source/diff and descriptive nonmaterial assessment; no attention event |
| Day 6 | Enterprise wording appears | Retain as a cited observation; assess uncertainty; update review basis/coverage |
| Day 7 | Periodic review checkpoint | Queue one bounded historical review even if no individual event was urgent |
| Day 11 | Enterprise-sales vacancy appears | If the configured evidence-based trigger applies, propose an earlier review of the relevant window |
| Day 18 | New enterprise tier appears | Review original evidence plus prior observations; test commercial-shift versus routine-maintenance explanations |
| After human review | A supported finding is admitted | Release through the proper owner route; apply internal attention policy to configured recipients |
| Day 25 | Vacancy withdrawn; source clarification | Append new observation and successor assessment if warranted; do not rewrite what was known on Day 18 |

A correct outcome could still be “insufficient evidence” or “no new insight”. The capability is successful when the evidence, timing, uncertainty and attention decision are inspectable—not when every review finds a strategy.

## 11. Deliberate exclusions and future imports

| Family | Disposition for this PHP effort |
|---|---|
| Full MCM-HMWH diagnostic upgrade | Separate canonical phase program; the bounded historical review is not advertised as this runtime |
| Meta/meta-meta OODA | LI-21 optional, evaluator-only first, no self-scheduling or correction execution |
| RACR generic residual routing | Future specialist integration after its owner contract; not a replacement for the first deterministic due policy |
| FFBBP / latent association | Optional question-specific diagnostic service; no automatic identity/coordination claim from correlation |
| AEC proof/composition | Future typed service; simple current reference checks are not proof-grade composition |
| EOM and semantic operating-model compression | Remain gated; do not create EOM tables or assert P02 offering-twin promotion |
| Forecasting / Strategic Core | Separate source-defined contracts; historical trend is not automatically forecast or strategy admission |
| Full adaptive M13/M14/SEV | No automatic learned cadence, weights, prompts or code promotion |
| Cross-tenant/network learning | Excluded; source/evidence/competitor histories remain scoped |
| External email/SMS/push/webhooks/CRM changes | Excluded; internal attention does not activate P07-B or another channel |
| Production/customer-visible output | Existing independent production/readiness path remains required |
| Unrelated P02 stakeholder producers or duplicate P03 screens | Not dependencies merely because another schema table exists |

These exclusions are not declarations that the future work is unnecessary. They prevent a practical longitudinal Monitor release from being mislabeled as the complete research architecture or delayed by unrelated modules. [S11, S15, S17–S19, S25–S26]

## 12. Completion definition

The capability is complete for its **declared release and scope** only when a real operator can:

- inspect a retained nonmaterial competitor observation with its exact evidence and original assessment;
- see why historical review is due, deferred or unnecessary, across an explicit timeframe and knowledge cutoff;
- inspect a bounded analysis basis that preserves contrary evidence and coverage limitations;
- run only the exact admitted analysis, review its result, and trace any released conclusion to primary evidence;
- receive justified internal attention without duplicate alerts or a widened audience;
- observe corrections/withdrawals without historical rewriting; and
- stop, restore and replay the complete chain without new unauthorized source/model effects.

The recommended first coding move is **not** “turn on meta-analysis”. It is: qualify the existing diagnostic repair, close the observation/materiality successor contract, expose the history already retained, and then build the historical-basis and deterministic review-coordination layer. The later model and automation steps have explicit dependencies rather than being inferred from their names.

## Appendix A — Source register

Each repository URL is pinned to the audited commit. A source entry records what was inspected, not proof that its code is deployed or has passed tests in this planning session. Historical PDFs supply architectural context; current owner contracts and source take precedence.

### S01

`HMarcusWH/allfather-loopia-p01 / app/Workflow/SourceCaptureStage.php`

Private retained capture bytes, M05 binding, source lock, missing-byte refusal.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Workflow/SourceCaptureStage.php

Git blob: `7787e1f223fd573d63e746b4f609c6a3cafda396`.

### S02

`HMarcusWH/allfather-loopia-p01 / app/Workflow/MonitorService.php`

Two-snapshot context; accepted candidate saved before NO_CHANGE; service stops before inference.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Workflow/MonitorService.php

Git blob: `49e73657948e84d19beab5933d30386cf8528475`.

### S03

`HMarcusWH/allfather-loopia-p01 / app/Workflow/ContextEnvelope.php`

Current-use checks, 8 observation limit, 64 KiB input limit, 4 model-call context ceiling.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Workflow/ContextEnvelope.php

Git blob: `fe931e9adbe5f83fb1ac8092b6d579242ccc6f30`.

### S04

`HMarcusWH/allfather-loopia-p01 / app/Workflow/ExecutionGrantStore.php`

Closed purposes, bounded budgets, immutable grants and owner admission.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Workflow/ExecutionGrantStore.php

Git blob: `cc9277b310f899887fb57ef2bb6c9ac6d47a38ef`.

### S05

`HMarcusWH/allfather-loopia-p01 / app/PromptOps/CandidateChecks.php`

NO_MATERIAL_CHANGE empties observations; strict evidence/reference/critic gates.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/PromptOps/CandidateChecks.php

Git blob: `2684106264d5385c4ee18fb304f1e6303991e7c4`.

### S06

`HMarcusWH/allfather-loopia-p01 / app/Workflow/ReleaseStages.php`

Current single-diff P01 composition, human review, separate P04 route.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Workflow/ReleaseStages.php

Git blob: `c9f04286b55cc75466597bdd1218f1df3ac6d1d6`.

### S07

`HMarcusWH/allfather-loopia-p01 / docs/MONITOR_SCHEDULING.md`

Existing observe-and-queue scheduling, three-unread-run cap, occurrence identities, role v1.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/docs/MONITOR_SCHEDULING.md

Git blob: `fb9b827884b65f220d72f52d8891e5918a0dfbe8`.

### S08

`HMarcusWH/allfather-loopia-p01 / app/Http/ReadAdapters/MonitorReadAdapter.php`

Existing React metadata-only reads; private text remains in SSR inspection.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Http/ReadAdapters/MonitorReadAdapter.php

Git blob: `a12706c7283fc048f8eb750d915cd151c91c3164`.

### S09

`HMarcusWH/allfather-loopia-p01 / app/Product/P01/ChangeDetection.php`

Structural significance is not relevance; immutable capture-bound diffs.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Product/P01/ChangeDetection.php

Git blob: `ebcda9228642d8444387bc099cfa1ba5fd428da7`.

### S10

`HMarcusWH/allfather-loopia-p01 / app/Product/P01/CompanySignals.php`

Scoped competitor/source signal creation and FACT/HYPOTHESIS boundary.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Product/P01/CompanySignals.php

Git blob: `48417f03620f2c1c122085dcd04c9b07016e0fd3`.

### S11

`HMarcusWH/allfather-loopia-p01 / docs/PORT_SCOPE_MATRIX.md`

Included product owners; P02/P03 detail views and P03 assurance already present; explicit exclusions.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/docs/PORT_SCOPE_MATRIX.md

Git blob: `41f005b5533e36bc2fed3405e4bd01e754f921d2`.

### S12

`HMarcusWH/allfather-loopia-p01 / docs/MONITOR_COMPLETION_PLAN.md`

September packages P00–P12 largely landed as source; do not rebuild their infrastructure.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/docs/MONITOR_COMPLETION_PLAN.md

Git blob: `6d1cf3c1fb244c758e54e2cd962bbc189c5acbf5`.

### S13

`HMarcusWH/allfather-loopia-p01 / docs/MVP_RELEASE_STATE.md`

Qualification procedure and dated source/deployment records; contains stale current-state prose.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/docs/MVP_RELEASE_STATE.md

### S14

`HMarcusWH/allfather-loopia-p01 / docs/POST_MERGE_TEST_BACKLOG.md`

Existing host/road-test/recurrence/browser/recovery acceptance obligations; reconcile to later evidence.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/docs/POST_MERGE_TEST_BACKLOG.md

Git blob: `cb37b47438522ebb64eb2ed3a6f3f781918b20a0`.

### S15

`HMarcusWH/Allfatherbusinessintelligence / docs/allfather-bi/implementation/temporal_intelligence_compression_architecture.md`

M17 owner boundaries, windows, profiles, replay, seven proposed tables, successor identity tension.

Source: https://github.com/HMarcusWH/Allfatherbusinessintelligence/blob/1169428f8163ba7ec81e4c739fea855b4b246cba/docs/allfather-bi/implementation/temporal_intelligence_compression_architecture.md

Git blob: `271e3226dbb359f4fbfb8fe2c6179cec91d02c28`.

### S16

`HMarcusWH/Allfatherbusinessintelligence / docs/allfather-bi/implementation/temporal_intelligence_compression_interface_contracts.md`

M17 closed vocabulary, reference-only input sets, scope, caveats, deterministic outputs.

Source: https://github.com/HMarcusWH/Allfatherbusinessintelligence/blob/1169428f8163ba7ec81e4c739fea855b4b246cba/docs/allfather-bi/implementation/temporal_intelligence_compression_interface_contracts.md

Git blob: `9e57eaef5a88c90451f13cf4ba7fe6afe5a922f5`.

### S17

`HMarcusWH/Allfatherbusinessintelligence / docs/allfather-bi/implementation/temporal_intelligence_compression_adr.md`

Architecture acceptance is not implementation authorization; manual/service invocation first.

Source: https://github.com/HMarcusWH/Allfatherbusinessintelligence/blob/1169428f8163ba7ec81e4c739fea855b4b246cba/docs/allfather-bi/implementation/temporal_intelligence_compression_adr.md

Git blob: `8daec8d855eb63c0c3fee03017dd7cb8bdefc6fe`.

### S18

`HMarcusWH/Allfatherbusinessintelligence / docs/allfather-bi/implementation/mcm-hmwh/07_recursive_ooda_federation_mcm_hmwh.md`

Domain/meta/meta-meta ownership, evaluator-only higher levels, no self-scheduling or self-promotion.

Source: https://github.com/HMarcusWH/Allfatherbusinessintelligence/blob/1169428f8163ba7ec81e4c739fea855b4b246cba/docs/allfather-bi/implementation/mcm-hmwh/07_recursive_ooda_federation_mcm_hmwh.md

Git blob: `ad60bd77262f4cf923e70fc7d2a47533662a6752`.

### S19

`HMarcusWH/Allfatherbusinessintelligence / docs/allfather-bi/implementation/mcm-hmwh/03_current_implementation_gap_analysis_mcm_hmwh.md`

Built fixture skeleton versus proposed MCM upgrade; missing routing, gates and evidence-view work.

Source: https://github.com/HMarcusWH/Allfatherbusinessintelligence/blob/1169428f8163ba7ec81e4c739fea855b4b246cba/docs/allfather-bi/implementation/mcm-hmwh/03_current_implementation_gap_analysis_mcm_hmwh.md

Git blob: `461992af56178ff408bfd974543ea96c49f298aa`.

### S20

`HMarcusWH/Allfatherbusinessintelligence / packages/allfather-control-substrate/src/gsmc/trigger-stats.ts`

1000/30-day fixture review-due mechanism; not general historical reasoning.

Source: https://github.com/HMarcusWH/Allfatherbusinessintelligence/blob/1169428f8163ba7ec81e4c739fea855b4b246cba/packages/allfather-control-substrate/src/gsmc/trigger-stats.ts

Git blob: `cefb07c48d2121732b24a6b5b2ef27f5aef9b16d`.

### S21

`HMarcusWH/allfather-loopia-p01 PR #64` — OPEN_UNMERGED.

Proposed diagnostics/metering regression repair, not a materiality fix.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/pull/64

### S22

`HMarcusWH/allfather-loopia-p01 / database/migrations`

31 existing migrations; 250 scheduling and 900 historical deferred FKs; proposed 260+ numbers not reserved.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/tree/d4fa09d6de924bb18b99fd229383e5d4992017ab/database/migrations

### S23

`HMarcusWH/allfather-loopia-p01 / app/Workflow`

Existing composition, journal, recovery, scheduler and provisioning filenames.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/tree/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Workflow

### S24

`HMarcusWH/allfather-loopia-p01 / app/Ops`

Reuse BackupManifest, MaintenanceGate, StandDownPosture and qualification machinery.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/tree/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Ops

### S25

`Allfather_BI_Architecture_Primer_v1_6_1_2026-08-29(2).pdf`

Architecture/source lineage; exact current repository contracts outrank this historical explanatory source.

Attached-file SHA-256: `10cec2ddbe3e7ef98339388fd012c6478668449dbccfbae6da16ef0370ff2013`.

### S26

`MCM-HMWH_Recursive_Governed_OODA_Architecture_v2_0_PUBLIC_REVIEW_CANDIDATE_FINAL.pdf`

Architecture/source lineage; exact current repository contracts outrank this historical explanatory source.

Attached-file SHA-256: `0b5ad2bbcc79395366ebe8a855589e151dd79532e24e5d48b3d5b7d77b13c797`.

### S27

`HMarcusWH/allfather-loopia-p01 / Dockerfile`

Railway image pins PHP 8.5.12; selected build checks are not the full DB-backed qualification.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/Dockerfile

Git blob: `1733748e903e4be0a5ebb2bfbcfee9c222bd276a`.

### S28

`HMarcusWH/allfather-loopia-p01 / app/Product/P01/WatchlistRead.php`

Existing competitor/readiness list; reuse it as history navigation, without leaking evidence through its narrow response.

Source: https://github.com/HMarcusWH/allfather-loopia-p01/blob/d4fa09d6de924bb18b99fd229383e5d4992017ab/app/Product/P01/WatchlistRead.php

Git blob: `57fa288b7fc81290255154a1f7b67c3a4f72a8d1`.

## Appendix B — Artifact validation

The JSON companion is the structured source for the work-package sections. The artifact generator checks unique IDs, valid dependencies, acyclicity, referenced source IDs, upstream decision IDs, unique proposed file ownership and JSON round-trip parsing. These checks validate this planning package only; they do not execute application tests or certify any implementation.
