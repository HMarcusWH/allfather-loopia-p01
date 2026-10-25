# Allfather Loopia Monitor — Engineering Handover to the Next Implementing Assistant

**Snapshot date:** 25 October 2026 (Europe/Stockholm)  
**Purpose:** Execute the previously designed longitudinal Monitor work in small, reviewable, evidence-backed changes.  
**Target repository:** https://github.com/HMarcusWH/allfather-loopia-p01  
**Canonical architecture repository:** https://github.com/HMarcusWH/Allfatherbusinessintelligence  
**Status:** Engineering handover / proposed work. **No code changes, merge, deployment, payment, source crawl, activation, or owner approval occur by issuing this document.**

> **Operating instruction to the next assistant:** Treat this as the implementation charter, not as implementation authority. Independently fetch the latest branch, issues, open PRs, current deployed release and adopted owner contracts before altering code. Work through the gates and one small PR at a time. Do not recreate completed P00–P12 machinery or silently port the research architecture. Do not promote an old test failure to PASS. Record uncertainty and missing evidence instead of inventing completion.

## 0 — What to achieve

Build a Monitor that can **record an observation without treating it as an actionable finding**, preserve it in the correct competitor’s historical record, and decide **when accumulated evidence across time deserves a fresh look**. It should examine a frozen, permission-checked historical evidence basis, challenge possible patterns, and choose whether an authorized internal user needs immediate attention, an ordinary digest, or no interruption.

The principal invariants are:

- **Observation ≠ business materiality ≠ notification priority ≠ release authority.** A menu reorder can be factually observed, retained and judged nonmaterial. A one-character price change may matter commercially despite low structural distance.
- **Evidence ≠ derived memory ≠ P02 admitted state ≠ released artifact.** M05 owns evidence; P01 owns competitor/diff objects; M17 produces only governed derived context; P02 and M10/M16 keep their canonical state/release authority.
- **A review being due ≠ a permission to fetch, pay for a model call, alter state, notify or publish.** Every such operation follows its own approved contract and current scope.
- **History is append-only or supersession-based.** Never rewrite historic snapshots, previous model requests/candidates, closed evaluation manifests, consumed attempt budgets, or completed journal/decision records to make a new narrative appear better.

The outcome should be a **PHP projection of adopted canonical semantics**, not an independent second Allfather runtime or a full MCM/FFBBP/EOM/Forecasting port.

## 1 — Verified starting snapshot and new priority

| Surface | Verified position | What it does *not* prove |
|---|---|---|
| PHP GitHub `main` | `d4fa09d6de924bb18b99fd229383e5d4992017ab` | Full CI, customer launch, active execution |
| Canonical `main` inspected for the existing plan | `1169428f8163ba7ec81e4c739fea855b4b246cba` | Automatic adoption by PHP |
| PHP adopted global canonical authority pin | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | Permission to update pin unreviewed |
| Railway staging PHP | Deployment `4810dcc3-ec21-452d-b19f-c3b2a17d85ac`, `SUCCESS`, same source SHA; Docker image uses PHP 8.5.12 | Full end-to-end acceptance or source/DB safety |
| Staging dependencies | MariaDB 10.11 present; app volume `/srv/allfather/storage`, DB volume `/var/lib/mysql` present; canonical adapter not deployed; no PHP cron configured | Consistent full-stack disaster recovery, job authorization |
| Current-main GitHub CI | Run `40131656219`, five jobs failed **without recorded steps** | A failing application test, or a qualified release |
| PR #64 | `CLOSED`, **not merged**; head `9a001370c7c3973d4edd6f279c958acafe71df3d` | Diagnostics/metering fix present in `main` |
| Existing MVP issue set | #35, #43, #44, #50, #53 updated with dated current-state overlays | Each item's full acceptance complete |
| New security issue | **#65**, `OPEN`: source-card path restriction is not enforced on every redirect hop | Live exploitation or an SSRF/private-host bypass |
| Open PR queue when checked | Zero | Issue #65 fixed |

**IMPORTANT: address Issue #65 before feature integration.** New defect surfaced after the original 22-package plan and issue reconciliations:

https://github.com/HMarcusWH/allfather-loopia-p01/issues/65

It reports an offline, deterministic reproduction: an approved source card scoped to `https://example.com/pricing` can follow a same-origin `302 Location: /outside`, because the P01 caller supplies `allowedOrigin` to the egress guard but does not carry the full current `allowed_url_scope` into the per-hop pre-dispatch check. The reproduction demonstrates an authorization-boundary gap, not a known live incident.

**P0 security fix (standalone first PR, not part of the 22 original LI packages):**

1. Inspect current `app/Ingestion/SourcePolicy.php`, `app/Ingestion/EgressGuard.php`, `app/Product/P01/Capture.php` and the actual source-policy/capture/redirect tests at the latest SHA. Confirm reproduction using `FixtureTransport` and `FixedHostResolver` with **no network**.
2. Carry the **full compiled URL path scope** through the actual pre-dispatch boundary and enforce `SourcePolicy::urlWithinScope(scope, eachCurrentHopUrl)` before **every** transport send, including each redirect. Preserve all existing HTTPS, origin, allowlisted host, DNS/IP pinning, redirect count, content type, byte and timeout checks.
3. Reconcile dot segments, percent-encoding/encoded separators, explicit ports and path-segment semantics with the established SourcePolicy contract; do not create a divergent path parser. Fail closed on ambiguity.
4. Test initial approved request → `302 /outside` **refused without sending `/outside`**; absolute and relative redirect targets; `/pricing-admin` false prefix; `/pricing/subpage` permitted; invalid/ambiguous URLs; port variations; multi-hop chain; positive capture path; P01 caller required to provide full scope. Assert the recorded attempted-URL list.
5. Run PHP lint, deterministic unit/security/integration tests and appropriate SSRF/capture parity checks on the reviewed exact code and a **disposable** MariaDB environment. Open a dedicated reviewable PR citing #65. No runtime fetch or production incident claim. Merge/deploy only under separately reviewed staging procedure. Until fixed, do not expand live capture/recurrence permissions.

**This is a newly identified prerequisite, not a retroactive edit to the original plan.** Update the continuation ledger after the security PR to avoid treating #65 as silently resolved.

## 2 — Source-of-truth reading order

Before coding, inspect the *actual latest branch* and run through the following, in order:

1. **Scope and authority:** `PROJECT_BOUNDARY.md`, `UPSTREAM_AUTHORITY.md`, `upstream.lock.json`, `docs/PORT_SCOPE_MATRIX.md`, `docs/SCHEMA_REACHABILITY.md`.
2. **Build/operations truth:** `docs/MVP_RELEASE_STATE.md`, `docs/POST_MERGE_TEST_BACKLOG.md`, `docs/MONITOR_COMPLETION_PLAN.md`, `docs/MONITOR_SCHEDULING.md`, `docs/MONITOR_MODEL_EVALUATION_PROTOCOL.md`, `docs/RAILWAY_STAGING_ADAPTER.md`, latest deployment/qualification receipts and the dated overlays on issues #35/#43/#44/#50/#53/#65.
3. **Current PHP paths:** `app/Workflow/{MonitorService,SourceCaptureStage,ContextEnvelope,RunJournal,RunArtifacts,RunReconciliation,ScheduleService,ScheduleDispatcher,ExecutionGrantStore,ExecutionAdmission,ReleaseStages}.php`; `app/Product/P01/{Capture,ChangeDetection,CompanySignals,WatchlistRead}.php`; `app/PromptOps/{ProductPromptPack,PromptSelectionResolver,CandidateChecks}.php`; `app/Provider/{ModelExecutor,PreparedModelRequest}.php`; M05/M07/M08/M10/M16 and existing HTTP/console adapters.
4. **Current canonical owner contracts:** `docs/allfather-bi/implementation/temporal_intelligence_compression_{architecture,adr,interface_contracts,implementation_plan}.md`; `docs/allfather-bi/implementation/mcm-hmwh/{03_current_implementation_gap_analysis_mcm_hmwh,07_recursive_ooda_federation_mcm_hmwh}.md`; P01 and GSMC source implementations. The primer and MCM research paper are explanatory lineage; deployed code/current owner contracts outrank narrative.
5. **Prepared specification:** `Allfather_PHP_Longitudinal_Intelligence_Plan_2026-10-25.md` and `Allfather_PHP_Intelligence_Work_Packages_2026-10-25.json`. These contain LI-00..LI-21, proposed paths, dependencies, decision packets U01..U10, and acceptance tests. **Correct one stale historical field:** those files recorded PR #64 as open; it is now closed without merge.

Never assume the user's old ZIP snapshots are today's repository head. A GitHub issue, PR merge or architectural plan can support an inspection claim, but is not independent evidence of an operating deployment or an approved execution grant.

## 3 — Reuse instead of rebuild

- **P01 Capture / ChangeDetection:** existing governed, scoped source capture; immutable snapshots, source-diffs and M05 references. The structural diff classifier deliberately does **not** claim commercial relevance.
- **EvidenceStore / SourceCaptureStage:** approved source bodies are already retained privately and bound to M05 through `monitor_capture_payloads`; do not build a second raw-content database.
- **MonitorService / RunArtifacts:** accepted candidates are stored before an accepted `NO_MATERIAL_CHANGE` run goes to `NO_CHANGE`; do not say all quiet output has been discarded. Model evaluation artifacts are not real competitor history.
- **P01 CompanySignals / M07/M08/M10/M16/P04:** existing proposed-signal, human review, verdict, release and internal role distribution spines. Current `ReleaseStages::releaseP01()` binds one diff: multi-source longitudinal findings need a **new reviewed owner contract**, not a fake source diff.
- **ContextEnvelope:** current context is deliberately bounded (up to 8 observations, 65,536 input bytes and four calls under existing limits); do not use it as an arbitrary cross-month archive entry point.
- **ScheduleService / ScheduleDispatcher / JobMachine:** recurrence already exists as `OBSERVE_AND_QUEUE` with `SCHEDULED_OBSERVATION` permitting **zero** model calls. A service can capture/compare; it cannot impersonate human inspection/review. Three unread changed runs can stop new reservations; coalescing requires separate designed, gated work.
- **Recovery/operation:** source includes P10/P11 backup and rotation logic, P07 observe/queue, P09 read/UIs, P03 assurance and P12 inert scaffolding. Their existence is not complete Railway populated-recovery or acceptance evidence. Extend them; do not restart P00–P12 as another wholesale port.

## 4 — Delivery order: small PRs, not one rollout

| Priority | Work packages | Deliverable / stopping boundary |
|---|---|---|
| **P0** | NEW #65 | Fix redirect path-scope authorization at pre-dispatch, regression tests and reviewed exact-head qualification. No broadened capture. |
| **Foundation** | LI-00, LI-01; begin LI-18, LI-19 | Dated source/deployment/authority ledger; safe diagnostics/metering repair reintroduced **selectively**, not automatic reopening of PR #64; canonical contracts U01..U10 proposed and resolved in small upstream decisions; freeze new longitudinal evaluation cases before tuning. |
| **R1 — Quiet history** | LI-02, LI-03, LI-04, LI-16 (R1 slice) | Versioned `NO_MATERIAL_CHANGE` observation semantics; competitor timeline, descriptive annotations, semantic deltas, source-origin groups, coverage and read-only UI. No autonomous model calls or notifications. |
| **R2 — Evidence across time** | LI-05, LI-06, LI-07, LI-08, LI-17 (+ LI-16 UI slice) | Frozen historical evidence basis and knowledge cutoff; pure deterministic M17 windows/profile; replayable rollups/successors; configured review-due policy and due queue (no model spending). |
| **R3 — Governed analysis** | LI-10, LI-11, LI-12, LI-14, LI-15 (+ UI) | New exact historical-analysis purpose; bounded analyst/critic with alternatives, contradiction, hold/no-insight; human review; **legitimate** multi-evidence internal finding route; outcomes/invalidation. No unattended inference. |
| **R4 — Operations and attention** | LI-09, LI-13; LI-20 gates | Coalesced bounded review work; deterministic due/rollup jobs under separately admitted service authority; authorized internal digest vs immediate attention with dedup and role/visibility checks. No email/SMS/CRM/customer push. |
| **Future, not MVP prerequisite** | LI-21 | Optional fixture-first meta attention/meta-meta health evaluators, proposal-only; not full autonomous MCM. |

**Cross-cutting:** LI-18 adds frozen sequence evaluation and true human scoring; LI-19 adds migration/recovery/stand-down coverage; LI-20 insists on exact-release staged proof before each rollout. Keep those active during every feature PR, not at the end.

## 5 — Required canonical decisions (do not assume granted)

| Decision | Owner question to resolve before behavior depends on it |
|---|---|
| **U01** | Permit cited descriptive observations with `NO_MATERIAL_CHANGE` and separately label commercial materiality, release, and attention. Version prompt/schema/checker v1→v2; do not rewrite old prompts. |
| **U02** | Define P01 competitor identity/scope and any P02 twin relation; require evidence-backed common-origin assertions. |
| **U03** | Specify legal/source-use eligibility for historical reads, event/observed/known-at clocks and provider disclosure; rights withdrawal and quarantine still bind. |
| **U04** | Approve deterministic M17 subset and repair same-period late-evidence successor uniqueness; declare P01 identity mapping, numerical/time serialization and profile precedence before SQL. |
| **U05** | Establish the owner and versioned policy for review cadence, unique-event thresholds, max-wait, clock-only due, event-triggered due, coalescing, fairness and backpressure. |
| **U06** | Define and approve distinct manual `HISTORICAL_REVIEW` execution purpose, exact evidence-basis identity, parent/per-review budget, roles, prompts and outputs. |
| **U07** | Define the multi-evidence P01 finding proposal, provenance, independent review, current reliance and release adapter; model judgment and an M17 ID cannot authorize release. |
| **U08** | Decide any new deterministic service jobs and, later, separately approved unattended model inference. Preserve the narrow scheduler-observer v1 seat. |
| **U09** | Define role-scoped internal attention/digest policy, dedup/suppression and visibility limits. External channels remain excluded. |
| **U10** | Define outcome/supersession/invalidation rules and later meta evaluator limits; correction proposals cannot silently change learned weights, policy or rollbacks. |

Offline documentation, fixtures, pure functions and tests can be prepared before every decision is complete, but **no unauthorized new persistent semantic owner, purpose, job authority, prompt/model activation or release family** should be quietly invented in PHP. Distinguish source merge from run authorization.

## 6 — Immediate implementation PR sequence after #65

### PR A: LI-00 baseline and safe forensics (documentation/fixture-first)

- Re-read exact main and Railway deployment state and publish a dated ledger: source SHA, currently qualified SHA, deployed SHA/image, current admitted execution (only if independently verified), actual model-quality status, current browser/customer-access status and production gates. Do not claim the current CI is a code failure or successful qualification: zero recorded steps ran.
- Reconcile currentness of `docs/MVP_RELEASE_STATE.md`, `docs/POST_MERGE_TEST_BACKLOG.md`, `docs/MONITOR_COMPLETION_PLAN.md` and their existing truth assertions. Preserve historical failure logs and prior release claims with as-of labels.
- Treat closed PR64 as **archived candidate source** for a new small vetted metering/allowlisted-code PR (or include that in a separate narrowly scoped PR). Preserve rejection of unsafe candidate text; known token usage must not vanish on post-schema refusal; unknown usage is `UNKNOWN`, not zero. Report DEV7 as incomplete and preserve the accepted menu candidate digest record.
- Deliver targeted tests and conformance evidence; no new paid test.

### PR B: LI-01 and U01 architecture contract first

- Submit the **small canonical** observation-vs-materiality semantics change upstream (separate decision and PR as required). Do not change PHP’s global canonical pin without a reviewed selective adoption record.
- Freeze the successor output schema, type names and references. Keep v1 bundles and all original digests replayable. A `NO_MATERIAL_CHANGE` may contain observation descriptions with citations, but not a material-release claim. A `FINDING` is still not a notification grant.
- Add held-out longitudinal negative/positive cases before prompt tuning; record contamination limitations.

### PR C: LI-02 new versioned PromptOps implementation (inactive)

- Proposed files: `app/PromptOps/PromptBundleRegistry.php`, `app/PromptOps/ObservationCandidateChecksV2.php`, a versioned successor prompt bundle and `tests/fixtures/monitor_observation_v2_eval_cases.json`.
- Integrate **by exact contract identity** into `ProductPromptPack.php`, `PromptSelectionResolver.php`, `CandidateChecks.php`, `PreparedModelRequest.php`, and `MonitorService.php`. Do not patch the existing frozen v1 schema in place or make “latest” reinterpret historic artifacts.
- Evaluate both menu-only reorder (record without finding), single-character price/negation changes (do not suppress), unknown evidence refs, injection HOLD, critic `REVISE`/`BLOCK` agreement and all old bundle replay checks.
- This PR is source/test implementation only until new model-policy/prompt/grant decisions and evaluation acceptance.

### PR D: LI-03/LI-04 owner-safe P01 history and features

- Start with **read-only joins** over `competitors`, `monitored_sources`, `source_snapshots`, `source_diffs`, `monitor_capture_payloads`, Monitor journals and already-retained candidate artifacts. Preserve strict tenant/workspace and source use. Separate `MODEL_EVALUATION` synthetic material from real competitor history.
- Add the smallest immutable descriptive assessment tables **only for information not already representable**; keep body bytes in current EvidenceStore. Proposed `app/Product/P01/ObservationHistory.php`, `ObservationAssessment.php`, `CompetitorScope.php`, later `ObservationFeatures.php`, `SourceOriginGroups.php`, `ObservationCoverage.php`.
- Distinguish baseline, unchanged capture, nonmaterial change, material-candidate, held, refused, unknown/missing bytes. Do not call an AI to backfill refused candidates or rewrite old run journals. Capture counts ≠ distinct event counts ≠ independent origin counts.
- Test privacy, current permissions, source/competitor identity, multi-workspace isolation, append-only replay, same-origin syndication, observation-rate denominator changes, and monitor outage gaps.

### PR E: LI-16 R1 UI

- Extend existing `WatchlistRead` and owner-authorized read adapters; **do not rebuild competitor or P02/P03 screens**. Add timeline, source/coverage status, quiet/no-change assessment, exact evidence drill-down where independently permitted and explicit unreleased/unreviewed labels.
- React GETs do not fetch, call models, start jobs, advance journal, approve output or alter state. Preserve existing metadata-only contract; private source/candidate text requires its own appropriate authorized read path. Test escaping, cross-tenant responses, pagination, CSRF separation, mobile and desktop.

**R1 is demonstrable without a single new live model attempt.** If owner-contract approval, full qualification or historical data is absent, show an honest empty/held state and stop at the proven boundary.

## 7 — R2/R3/R4 implementation rules most likely to prevent regressions

1. **Four separate cadences:** source capture schedule, historical review schedule, temporal bucket/compression resolution, and notification/digest schedule. None grants another.
2. **Four evidence timestamps:** source-claimed event time, actually captured/observed time, recorded/known-at time and analysis time. Use a frozen knowledge cutoff. Late evidence creates a successor basis; do not backdate what the system knew.
3. **Time-window policy:** explicit half-open intervals `[start,end)`, pinned calendar/timezone rules (Stockholm DST/month-boundary cases), declared lookback and comparison period. M17 memory is deterministic/LLM-free; its summaries link to owner-native primary evidence; no raw bytes copied or deleted.
4. **M17 uniqueness issue:** the proposed `temporal_memories` unique key omits input/run identity while successors must support new same-period evidence. Close U04 before any `280_*.sql` implementation. An architecture target does not authorize M17 data tables automatically.
5. **Review due ≠ model invocation:** independent event and clock-tick checks, including quiet streams; supported reasons must correspond to implemented detectors. A density change alone does not prove competitor strategic change; monitor outages and changed fetch frequency must adjust coverage/denominators.
6. **Single-diff release must not be forged:** current P01 path attaches one source diff. Historical multi-source synthesis needs the adopted U07 contract over actual primary evidence and existing M07/M08/M10/M16/P04 owners. Until then, keep the result provisional and unreleased.
7. **Service principal remains narrow:** `SCHEDULED_OBSERVATION` has zero model budget. No automatic historical model call, human-approval impersonation, arbitrary job callback, email, SMS, webhook, CRM mutation or source re-fetch on replay.
8. **Coalescing preserves backlog truth:** separate observed, queued, analyzed and genuinely human-reviewed watermarks. Three unread scheduled changes currently impose backpressure; any new coalescing mode needs bounded maximum age/volume/risk, forward-only identities and explicit admission. Do not simply increase the queue limit or mark items reviewed.
9. **Replay is not a new attempt:** if provider outcome is unknown after timeout/crash, stop and reconcile; never silently retry paid calls or assume missing usage equals zero. Every run binds exact prompt, model, basis, grant, credential and source fingerprints.
10. **MCM is optional:** future domain/meta/meta-meta layers are evaluator/router proposals only. Implementing one historical review workflow does **not** satisfy the full eleven-gate MCM architecture or confer self-tuning, learning, release or execution authority.

## 8 — Qualification and security gates for every PR

**Before every PR:** inspect exact base SHA, current open PRs, issue changes, owner pin and any adopted selective contracts; write a small scope table with `EXISTING / NEW / EXCLUDED / REQUIRES_OWNER_DECISION`. List new source effects separately from read-only changes.

**For code and schema:** syntax check every affected PHP file; run focused no-network unit/foundation/security tests, followed by appropriate full strict lanes on disposable MariaDB 10.6 and 10.11 and target PHP 8.5.12. Use the repository’s current `ops/ci/qualify-release.sh` and receipt verifier; do not guess success commands, reuse a mutable checkout as an exact release, or run destructive `Harness::freshDb()` against staging runtime/retained restore targets. GitHub CI with zero steps is infrastructure-not-executed, neither PASS nor demonstrated application failure.

**Migrations:** 31 existing migration files at the inspected baseline; `250_monitor_schedule_control.sql` present and historical `900_deferred_foreign_keys.sql` already applied. Proposed `260+` names in the plan are **not reserved**. Add new migrations without altering existing checksums. Prove fresh install, upgrade from 31 migrations, second no-op, intact triggers/rows/journal digests and schema equivalence in supported MariaDB lanes.

**Before merge:** consider Railway following `main`; a merge may cause automatic staging deployment. Review exact staged source/fingerprint and currently admitted operations before merge/rollout, and obtain separate owner approval where necessary. Never assume a GitHub merge is only an inert documentation action when the deployed service tracks that branch.

**Before operational activation:** exact owner decision, current identity/role, source rights, approved prompt/model/credential, tenant/workspace, budget/validity, target/retention/disclosure policy, current release fingerprint and explicit stand-down procedure. Customer-output and production authority remain **closed** unless separately adopted.

**After any staged deployment:** confirm actual SHA/runtime, migration ledger, volume preservation, authorization counters where independently accessible, anonymous/private HTTP refusals, authenticated read behavior, security boundaries and rollback/recovery readiness. Product quality needs real held-out and independent human-scored evidence; container smoke or schema validation does not supply it.

## 9 — Frozen acceptance scenarios (minimum)

| Scenario | Must demonstrate |
|---|---|
| `/pricing` redirects outside approved source-card path | Refused **before** second request; no transport call to forbidden path (#65) |
| Menu reordered | Evidence-backed observation survives on proper competitor history; `NO_MATERIAL_CHANGE`; **no** immediate attention |
| One price or negation changed | Important change not discarded as cosmetic solely because structural distance is tiny |
| Twenty identical fetches / ten syndicated copies | Coverage count rises, unique business-event corroboration does not inflate |
| Slow drift across separate weeks | Review policy can make a bounded historical question due; old observation assessments unchanged |
| Source outage/missing history/late-backdated evidence | Missing coverage and known-at cutoff are explicit, with no hindsight or silent repair |
| Contradictory or adverse evidence | Remains available in basis, summary and critic; insufficient basis/hold/no-new-insight are legitimate outcomes |
| Same review selected twice | Idempotent result / explicit successor; no unintended duplicate provider spend |
| Held/rejected or timed-out model candidate | No unsafe candidate promotion, preserve known usage, unknown spend stays unknown, no automatic retry |
| Source rights revoked / evidence redacted | New consumption refuses, dependency impact is recorded, old audit remains with appropriate access/retention handling |
| Cross-tenant / wrong competitor / synthetic fixtures | Fail closed; no foreign existence leakage and no fixture promotion into real history |
| Multiple individually weak observations support a later interpretation | Valid multi-evidence review/release only under U07; memory ID alone never evidence/release authority |
| Routine result / paraphrase of old result | No redundant internal interruption; review/attention decisions independently inspectable |
| Scheduler saturation | Bounded coalescing or visible backpressure without deleting evidence or faking human review |
| Clean migration / upgrade / populated restore / stand-down | No old history changes, exact digests, stored private artifacts restored, future operations remain inactive |

**Quality acceptance:** Preserve the original 18-case manifest and DEV7 history. Commission new frozen, genuinely held-out *time-series* cases before tuning. Measure distinct observation retention, review timing/false-review rate, claim grounding, contradictions, alert precision/recall/duplication, independent human usefulness, cost, and incomplete usage. One pass of static checks cannot establish strategic-truth accuracy.

## 10 — Explicit non-goals / stop conditions

Do not rebuild P00–P12 wholesale; do not create a parallel evidence database, generalized agent runner or new world-state owner. Do not independently enable M17 model summaries, full MCM-RACR-FFBBP agents, EOM, Forecasting, M13/M14 self-adaptation, cross-tenant learning, customer/production releases, outbound messaging or payment. Do not infer an authorized fetch, model call, schedule, role assignment or release merely because a task is due or a fixture passes.

Stop and record `BLOCKED` with the exact missing owner decision, unsupported test host, source-rights issue or evidence gap whenever a requested behavior would exceed current authority. Continue with independently safe code/test/docs work rather than weakening a gate to “finish.”

## 11 — First-session execution checklist for the next assistant

1. Re-fetch main HEAD, open PRs, issues #35/#43/#44/#50/#53/#65, relevant Railway staging runtime and source pin. Correct the baseline table if anything changed. **Do not blindly trust this dated snapshot.**
2. Reproduce #65 offline, patch its redirect path-scope enforcement in a dedicated branch, add unit + P01 caller regressions, and produce a PR with exact evidence. This is the first feature-independent security gate.
3. Prepare LI-00 current-state ledger and decide whether to reintroduce only the closed PR64’s safe diagnostic/metering logic in a fresh scoped PR. Do not mark its prior failed CI PASS.
4. Prepare canonical U01 observation/materiality contract and new frozen case manifest **before** changing the live classifier’s semantic rules. Do not edit the original v1 prompt schema or old evaluation rows.
5. Implement R1 in the order LI-02 → LI-03 → LI-04 → first LI-16 UI slice, with LI-18/LI-19 tests. Stop at a truthful operator history view before attempting automatic historical inference.
6. Only then continue R2/R3/R4 under their respective U02..U10 decisions, tests and rollout gates.

At the close of each work session, write a 10-field continuation receipt: exact branch/base/head; files changed; owner contracts relied upon; migrations added/applied; tests actually run and failures/skips; model/source spending; current Railway release (if observed); issue/PR links; unresolved blockers; **one precise next smallest action**. No background-work claims.

## 12 — Existing issue tracker and documents

- **#65 (new P0):** https://github.com/HMarcusWH/allfather-loopia-p01/issues/65 — approved path scope across redirects; regression and pre-dispatch fix not merged.
- **#35:** https://github.com/HMarcusWH/allfather-loopia-p01/issues/35 — operational road test, browser, recurrence/recovery acceptance.
- **#43:** https://github.com/HMarcusWH/allfather-loopia-p01/issues/43 — complete Railway DB + app-storage recovery, not just DB backup.
- **#44:** https://github.com/HMarcusWH/allfather-loopia-p01/issues/44 — model-quality evaluation; two historical test attempts and DEV7 incomplete results.
- **#50:** https://github.com/HMarcusWH/allfather-loopia-p01/issues/50 — existing Plus46 TEST accounts/read-model access and authenticated acceptance.
- **#53:** https://github.com/HMarcusWH/allfather-loopia-p01/issues/53 — current broader MVP completion gate.
- **#64 (PR, closed-unmerged):** https://github.com/HMarcusWH/allfather-loopia-p01/pull/64 — safe diagnostics and metering proposed but absent from main.

Accompanying files in this handover bundle: `Allfather_PHP_Longitudinal_Intelligence_Plan_2026-10-25.md` (full design/specification) and `Allfather_PHP_Intelligence_Work_Packages_2026-10-25.json` (machine-readable LI package definitions, dependencies, decision IDs, proposed files and tests). **Their PR64 state field is historical and superseded by this handover.**

---

## Appendix A — All work packages: implementation file index

The following file paths are **proposed additions** or existing integration paths from the original plan, not proof the file now exists. Before editing, check the latest tree, owner contract and migration numbering.


### LI-00 — Reconcile currentness and qualify the diagnostic foundation

**Release:** `FOUNDATION`; **depends on:** None; **owner decision(s):** Existing scope/operations; no automatic activation.

**Add (proposed):** `docs/LONGITUDINAL_INTELLIGENCE_STATE.md`; `docs/LONGITUDINAL_INTELLIGENCE_PLAN.md`; `docs/LONGITUDINAL_INTELLIGENCE_WORK_PACKAGES.json`.

**Integrate/inspect:** `docs/MVP_RELEASE_STATE.md`; `docs/MONITOR_COMPLETION_PLAN.md`; `docs/POST_MERGE_TEST_BACKLOG.md`; `docs/MONITOR_MODEL_EVALUATION_PROTOCOL.md`; `app/Provider/ModelExecutor.php`; `ops/ci/qualify-release.sh`; `ops/ci/verify-qualification.php`.

**Focused tests:** `tests/foundation/longitudinal_state_currentness.php`; `tests/unit/provider_usage_completeness.php`.

**Acceptance:** A reader can distinguish current source, old evidence and unverified host state without chat history. Exact-head qualification receipts identify environment and incomplete/skipped lanes honestly. Rejected or uncertain attempts remain spent; no invented zero usage, refunds or retrospective raw critic output.


### LI-01 — Close the canonical contracts before expanding PHP semantics

**Release:** `FOUNDATION`; **depends on:** LI-00; **owner decision(s):** U01, U02, U03, U04, U05, U06, U07, U08, U09, U10.

**Add (proposed):** `docs/LONGITUDINAL_AUTHORITY_DECISIONS.md`; `docs/reconciliation/longitudinal-intelligence-2026-10-25.json`; `tests/fixtures/longitudinal_owner_contract_vectors.json`.

**Integrate/inspect:** `PROJECT_BOUNDARY.md`; `UPSTREAM_AUTHORITY.md`; `docs/PORT_SCOPE_MATRIX.md`; `docs/SCHEMA_REACHABILITY.md`.

**Focused tests:** `tests/foundation/longitudinal_owner_boundaries.php`; `tests/conformance/longitudinal_owner_contracts.php`.

**Acceptance:** Each new behavior has a semantic owner, an explicit implementation disposition and a separate activation condition. No memory ID is accepted as evidence, approval, source-use right or release authority. The M17 same-period successor and P01/P02 scope questions are resolved before dependent migrations.


### LI-02 — Version the observation/materiality model contract without rewriting v1

**Release:** `R1`; **depends on:** LI-01; **owner decision(s):** U01.

**Add (proposed):** `app/PromptOps/PromptBundleRegistry.php`; `app/PromptOps/ObservationCandidateChecksV2.php`; `app/PromptOps/bundles/monitor-observation-v2.json`; `docs/OBSERVATION_MATERIALITY_CONTRACT.md`; `tests/fixtures/monitor_observation_v2_eval_cases.json`.

**Integrate/inspect:** `app/PromptOps/ProductPromptPack.php`; `app/PromptOps/PromptSelectionResolver.php`; `app/PromptOps/PromptApprovalStore.php`; `app/PromptOps/CandidateChecks.php`; `app/Provider/PreparedModelRequest.php`; `app/Workflow/MonitorService.php`.

**Focused tests:** `tests/unit/observation_candidate_v2.php`; `tests/unit/prompt_bundle_versioning.php`; `tests/integration/monitor_observation_contract.php`.

**Acceptance:** Menu reorder: described and retained; no material finding, product release or notification. One-word negation or one-price change remains detectable; cosmetic normalization cannot suppress it. All old request/candidate digests still verify with their original bundle; unknown versions refuse.


### LI-03 — Expose competitor history and persist bounded observation assessments

**Release:** `R1`; **depends on:** LI-01, LI-02; **owner decision(s):** U01, U02.

**Add (proposed):** `app/Product/P01/ObservationHistory.php`; `app/Product/P01/ObservationAssessment.php`; `app/Product/P01/CompetitorScope.php`; `ops/history/backfill.php`; `database/migrations/260_competitor_observation_assessments.sql`.

**Integrate/inspect:** `app/Workflow/MonitorService.php`; `app/Workflow/RunArtifacts.php`; `app/Workflow/RunReconciliation.php`; `app/Product/P01/Registry.php`.

**Focused tests:** `tests/integration/competitor_observation_history.php`; `tests/security/competitor_history_scope.php`; `tests/integration/observation_history_backfill.php`.

**Acceptance:** A nonmaterial change is visible on the correct competitor’s timeline with exact evidence drill-down and no release row. Backfill is repeatable and leaves all prior source/run/provider/evaluation rows byte-identical. A foreign tenant/workspace, unbound competitor ID, or synthetic evaluation cannot enter product history.


### LI-04 — Preserve semantic deltas, provenance groups and observation coverage

**Release:** `R1`; **depends on:** LI-03; **owner decision(s):** U02, U03.

**Add (proposed):** `app/Product/P01/ObservationFeatures.php`; `app/Product/P01/SourceOriginGroups.php`; `app/Product/P01/ObservationCoverage.php`; `database/migrations/265_observation_features_and_origins.sql`.

**Integrate/inspect:** `app/Workflow/SourceCaptureStage.php`; `app/Product/P01/ObservationHistory.php`.

**Focused tests:** `tests/unit/observation_features.php`; `tests/integration/source_origin_grouping.php`; `tests/unit/observation_coverage.php`.

**Acceptance:** Equivalent navigation renderings produce a bounded descriptive change without changing the archived structural verdict. Repeated captures and syndicated copies do not inflate unique-event or corroboration counts. Increased crawl frequency, failed captures and LATEST_ONLY gaps remain visible in trend denominators.


### LI-05 — Freeze point-in-time, permission-checked historical evidence bases

**Release:** `R2`; **depends on:** LI-01, LI-03, LI-04; **owner decision(s):** U03, U06.

**Add (proposed):** `app/Workflow/HistoryReviewBasis.php`; `app/Workflow/HistoricalEvidenceEligibility.php`; `app/Workflow/HistoryReviewContext.php`; `app/Workflow/HistoricalEvidenceSelector.php`; `database/migrations/270_history_review_bases.sql`; `ops/history/preview.php`.

**Integrate/inspect:** `app/Workflow/ModelContextAuthority.php`; `app/Workflow/ContextEnvelope.php`.

**Focused tests:** `tests/unit/history_review_time_cut.php`; `tests/security/historical_evidence_eligibility.php`; `tests/integration/history_review_basis.php`.

**Acceptance:** Late-arriving evidence is excluded from the old knowledge cut and creates a new basis when legitimately considered. Expired currentness, forbidden reuse, quarantined evidence and genuinely missing bytes have different explicit outcomes. Cross-tenant joins and silent dropping of contrary evidence fail; input overflow does not produce a complete-looking conclusion.


### LI-06 — Implement the pure M17 window and profile laws

**Release:** `R2`; **depends on:** LI-01, LI-05; **owner decision(s):** U04.

**Add (proposed):** `app/Substrate/M17/Vocabulary.php`; `app/Substrate/M17/WindowEngine.php`; `app/Substrate/M17/ProfileResolver.php`; `app/Substrate/M17/DynamicMeasures.php`; `app/Substrate/M17/InputSet.php`; `tests/fixtures/temporal_contract_vectors.json`; `docs/M17_PROJECTION_CONTRACT.md`.

**Integrate/inspect:** `docs/PORT_SCOPE_MATRIX.md`; `docs/CONFORMANCE_MATRIX.md`.

**Focused tests:** `tests/unit/temporal_windows.php`; `tests/unit/temporal_profiles.php`; `tests/conformance/temporal_vectors.php`.

**Acceptance:** Identical inputs/configuration produce identical windows and profile digests across repeated runs. DST, month-end, unknown class and conflicting profile cases have explicit reproducible outcomes. No model call, fetch, state admission, deletion or release is reachable from M17 pure engines.


### LI-07 — Persist M17 rollups, memory lineage, supersession and replay

**Release:** `R2`; **depends on:** LI-06; **owner decision(s):** U04.

**Add (proposed):** `app/Substrate/M17/MemoryStore.php`; `app/Substrate/M17/RollupEngine.php`; `app/Substrate/M17/Lineage.php`; `app/Substrate/M17/Replay.php`; `app/Substrate/M17/P01HistoryAdapter.php`; `database/migrations/280_m17_temporal_memory.sql`; `ops/temporal/run.php`; `ops/temporal/replay.php`.

**Integrate/inspect:** `tests/fixtures/schema_table_inventory.json`; `docs/SCHEMA_REACHABILITY.md`.

**Focused tests:** `tests/integration/temporal_memory.php`; `tests/integration/temporal_successors.php`; `tests/security/temporal_owner_boundaries.php`.

**Acceptance:** Every memory resolves to exact allowed owner-native inputs; a memory ID cannot satisfy evidence or release checks. A same-period late event can produce a successor without violating uniqueness or altering old digests. Repeated rollups and replay make zero provider/source calls and preserve contrary observations.


### LI-08 — Add an explicit historical-review policy and due detector

**Release:** `R2`; **depends on:** LI-01, LI-04, LI-05; **owner decision(s):** U05.

**Add (proposed):** `app/Workflow/HistoryReviewPolicy.php`; `app/Workflow/HistoryReviewDue.php`; `app/Workflow/HistoryReviewPolicyStore.php`; `database/migrations/290_history_review_policy.sql`; `ops/history/policy.php`; `ops/history/due.php`.

**Integrate/inspect:** `app/Workflow/OwnerDecisionRegistry.php`.

**Focused tests:** `tests/unit/history_review_due.php`; `tests/integration/history_review_policy.php`; `tests/security/history_review_policy_scope.php`.

**Acceptance:** A time-only tick can request review even without a new capture; repeated calls are idempotent. Observed coverage, unique changes and origin groups are not collapsed into one raw observation count. Changing a policy creates a new version; a due result cannot call a model or admit its own policy.


### LI-09 — Coalesce review work and extend the existing scheduler safely

**Release:** `R4`; **depends on:** LI-01, LI-03, LI-08; **owner decision(s):** U05, U08.

**Add (proposed):** `app/Workflow/HistoryReviewQueue.php`; `app/Jobs/HistoryReviewDueHandler.php`; `app/Jobs/TemporalRollupHandler.php`; `database/migrations/300_history_review_queue_jobs.sql`.

**Integrate/inspect:** `app/Workflow/ScheduleService.php`; `app/Workflow/ScheduleDispatcher.php`; `app/Workflow/MonitorPrincipal.php`; `app/Workflow/MonitorService.php`; `app/Jobs/JobMachine.php`; `app/Jobs/SchedulerRunner.php`; `worker/tick.php`; `public_html/scheduler.php`; `ops/monitor/stand-down.php`.

**Focused tests:** `tests/integration/history_review_queue.php`; `tests/security/history_review_queue_concurrency.php`; `tests/integration/history_review_job_recovery.php`.

**Acceptance:** A long sequence of routine changes does not silently stop observation under the new admitted mode, and all unreviewed evidence remains traceable. Duplicate ticks create no duplicate work/spending; a crash neither skips evidence nor pretends a human review occurred. The original scheduler role/mode still cannot infer, release or send; unknown job types refuse.


### LI-10 — Add exact historical-analysis admission, budgets and durable provider bindings

**Release:** `R3`; **depends on:** LI-01, LI-02, LI-05; **owner decision(s):** U06, U08.

**Add (proposed):** `app/Workflow/HistoryReviewAdmission.php`; `app/Workflow/HistoryReviewReconciliation.php`; `database/migrations/310_history_review_execution.sql`; `ops/history/authorization.php`; `ops/history/reconcile.php`.

**Integrate/inspect:** `app/Workflow/ExecutionGrantStore.php`; `app/Workflow/ExecutionAdmission.php`; `app/Workflow/OwnerDecisionRegistry.php`; `app/Provider/PreparedModelRequest.php`; `app/Provider/ModelExecutor.php`; `app/Provider/ModelArtifactStore.php`; `app/PromptOps/PromptApprovalStore.php`; `app/PromptOps/PromptSelectionResolver.php`.

**Focused tests:** `tests/security/history_review_admission.php`; `tests/security/history_review_budget_races.php`; `tests/integration/history_review_attempt_recovery.php`.

**Acceptance:** A review runs only under its own exact admitted purpose, basis, prompt, principal and resource scope. Concurrent reviews cannot exceed parent limits; expired/revoked grants stop before subsequent effects. Crash after provider return can adopt a verified retained result once, while an unknown result is not re-created with another call.


### LI-11 — Build a bounded historical-analysis and challenge workflow

**Release:** `R3`; **depends on:** LI-05, LI-07, LI-10; **owner decision(s):** U06.

**Add (proposed):** `app/Workflow/HistoryReviewService.php`; `app/Workflow/HistoryReviewJournal.php`; `app/Workflow/HistoryReviewFactory.php`; `app/PromptOps/HistoryReviewCandidateChecks.php`; `app/PromptOps/bundles/history-review-v1.json`; `database/migrations/320_history_review_runs.sql`; `ops/history/review.php`; `ops/history/replay.php`.

**Integrate/inspect:** `app/PromptOps/PromptBundleRegistry.php`; `app/Provider/ModelRequestBuilder.php`.

**Focused tests:** `tests/integration/history_review_workflow.php`; `tests/security/history_review_untrusted_inputs.php`; `tests/unit/history_review_candidates.php`.

**Acceptance:** A combined pattern can be considered without revising the original nonmaterial assessments or inventing evidence. Ordinary-maintenance and contrary-evidence cases can end in NO_NEW_INSIGHT/HOLD rather than always finding a strategy. The analyst cannot fetch, widen scope, authorize another attempt, approve itself or notify recipients.


### LI-12 — Add legitimate multi-evidence proposal and release adapters

**Release:** `R3`; **depends on:** LI-01, LI-11; **owner decision(s):** U07.

**Add (proposed):** `app/Workflow/HistoryReviewRelease.php`; `app/Product/P01/HistoricalFindingAdapter.php`; `database/migrations/330_historical_finding_bindings.sql`.

**Integrate/inspect:** `app/Product/P01/CompanySignals.php`; `app/Product/P01/Battlecards.php`; `app/Product/P01/Release.php`; `app/Substrate/M08/PolicySets.php`; `app/Substrate/M08/RuleEngine.php`; `app/Workflow/ReleaseStages.php`.

**Focused tests:** `tests/conformance/historical_finding_admission.php`; `tests/security/historical_finding_release.php`; `tests/integration/history_review_to_internal_release.php`.

**Acceptance:** Every released temporal finding has actual multi-evidence support, a bound review and valid M10/current-reliance lineage. Missing authority, unknown reuse rights or a memory-only basis block release even when the prose is persuasive. No P02 state, P04 recipient set or customer visibility changes merely because a review completed.


### LI-13 — Separate internal attention, digest inclusion and immediate interruption

**Release:** `R4`; **depends on:** LI-03, LI-08, LI-12; **owner decision(s):** U09.

**Add (proposed):** `app/Product/P04/AttentionPolicy.php`; `app/Product/P04/InternalAttention.php`; `app/Product/P04/InternalDigest.php`; `database/migrations/340_internal_attention.sql`; `ops/history/attention.php`.

**Integrate/inspect:** `app/Product/P04/Distribution.php`; `app/Product/P04/Release.php`; `app/Http/ReadAdapters/MonitorReadAdapter.php`.

**Focused tests:** `tests/unit/internal_attention_policy.php`; `tests/security/internal_attention_visibility.php`; `tests/integration/internal_digest_deduplication.php`.

**Acceptance:** A navigation reorder can stay in history forever without producing an attention event. An admitted multi-event finding can be queued for the correct configured role without external sending. Reworded/replayed results, withdrawn releases and foreign-role evidence cannot create duplicate or unauthorized visibility.


### LI-14 — Record follow-up conditions, outcomes and changed assessments

**Release:** `R3`; **depends on:** LI-11; **owner decision(s):** U06, U10.

**Add (proposed):** `app/Workflow/HistoryReviewOutcomes.php`; `app/Workflow/HistoryReviewFollowUp.php`; `database/migrations/345_history_review_outcomes.sql`; `ops/history/outcomes.php`.

**Integrate/inspect:** `app/Workflow/HistoryReviewService.php`.

**Focused tests:** `tests/integration/history_review_outcomes.php`; `tests/security/history_review_no_self_adaptation.php`.

**Acceptance:** Later evidence creates a linked new disposition while the original result and knowledge cut stay intact. A follow-up request cannot collect data or spend budget without its separate authority. Unresolved and false-positive outcomes remain in evaluation denominators.


### LI-15 — Propagate evidence invalidation through histories, memories and releases

**Release:** `R3`; **depends on:** LI-05, LI-07, LI-12; **owner decision(s):** U03, U10.

**Add (proposed):** `app/Workflow/HistoryDependencyIndex.php`; `app/Workflow/HistoryInvalidation.php`; `database/migrations/350_history_dependencies.sql`; `ops/history/invalidation.php`.

**Integrate/inspect:** `app/Substrate/M17/Lineage.php`; `app/Substrate/M17/MemoryStore.php`; `app/Workflow/HistoricalEvidenceEligibility.php`; `app/Product/P04/InternalAttention.php`.

**Focused tests:** `tests/integration/history_invalidation.php`; `tests/security/history_redaction_propagation.php`; `tests/security/history_release_reliance.php`.

**Acceptance:** Quarantining one load-bearing source identifies all dependent results without modifying the original history. A revoked source cannot be reused through a cached summary, replay screen or already-queued review. Release withdrawal/replacement remains M10-owned and source erasure remains separately authorized.


### LI-16 — Add an operator history and review workbench without widening metadata reads

**Release:** `R1_INCREMENTAL`; **depends on:** LI-03; **owner decision(s):** U01, U02, U09.

**Add (proposed):** `app/Http/ReadAdapters/CompetitorHistoryAdapter.php`; `app/Http/ReadAdapters/TemporalMemoryAdapter.php`; `app/Http/ReadAdapters/HistoryReviewAdapter.php`; `app/Http/Controllers/HistoryReviewController.php`; `app/Http/templates/history-review.php`; `frontend/console/src/views/p01/competitor-history.jsx`; `frontend/console/src/views/p01/history-reviews.jsx`.

**Integrate/inspect:** `app/Http/Controllers/ConsoleReadController.php`; `public_html/index.php`; `frontend/console/src/data/http-console-data-source.js`; `frontend/console/src/data/live-read-adapters.js`; `frontend/console/src/data/navigation.js`; `app/Http/templates/monitor.php`; `app/Product/P01/WatchlistRead.php`.

**Focused tests:** `tests/security/history_http_boundaries.php`; `tests/integration/history_read_models.php`; `frontend/console/tests/history-views.test.js`.

**Acceptance:** R1 history displays nonmaterial observations without presenting them as released intelligence. Every later pane identifies its period/basis/reliance and shows missing/unsupported states honestly. Authorization, no-prefetch/no-GET-work, CSRF, escaping, stable pagination and mobile/desktop inspection pass.


### LI-17 — Extend M11 assurance and operational observability

**Release:** `CROSS_CUTTING`; **depends on:** LI-03, LI-05, LI-07, LI-08; **owner decision(s):** U04, U05.

**Add (proposed):** `app/Substrate/M17/Assurance.php`; `app/Workflow/HistoryReviewAssurance.php`; `ops/assurance/history.php`; `ops/assurance/temporal.php`; `docs/HISTORY_OPERATIONS_SCORECARD.md`.

**Integrate/inspect:** `app/Ops/RuntimeInvariantProbe.php`; `app/Ops/StandDownPosture.php`; `app/Workflow/ScheduleDispatcher.php`.

**Focused tests:** `tests/integration/temporal_assurance.php`; `tests/security/history_telemetry_minimization.php`; `tests/integration/history_assurance_negative_controls.php`.

**Acceptance:** Each audit fails on a planted violation and does not change the audited subject to make itself pass. Missing evidence, stalled review and incomplete metering are visible without private content leakage. No model-quality or production claim is inferred from passing schema/digest audits.


### LI-18 — Build longitudinal evaluation before tuning the new model behavior

**Release:** `CROSS_CUTTING`; **depends on:** LI-01; **owner decision(s):** U01, U06.

**Add (proposed):** `app/Evaluation/HistoricalSequenceEvaluationRunner.php`; `app/Evaluation/HistoricalSequenceReport.php`; `tests/fixtures/historical_review_development_cases.json`; `tests/fixtures/historical_review_heldout_manifest.json`; `ops/evaluation/history.php`; `docs/HISTORICAL_REVIEW_EVALUATION_PROTOCOL.md`.

**Integrate/inspect:** `app/Evaluation/EvaluationFactory.php`; `app/Evaluation/EvaluationReport.php`.

**Focused tests:** `tests/integration/historical_sequence_evaluation.php`; `tests/security/historical_evaluation_separation.php`; `tests/unit/historical_sequence_scorecard.php`.

**Acceptance:** Offline cases make zero provider calls; synthetic evaluation cannot write real competitor history or product releases. Held-out and independent human scoring cover chronology, unsupported patterns, silence and false-positive attention—not just JSON correctness. A failed/missing sequence or incomplete usage produces an honest report and cannot activate prompts.


### LI-19 — Make schema, storage, backup, recovery and stand-down cover the new graph

**Release:** `CROSS_CUTTING`; **depends on:** LI-00, LI-01; **owner decision(s):** Existing scope/operations; no automatic activation.

**Add (proposed):** `docs/HISTORY_SCHEMA_AND_STORAGE.md`; `docs/HISTORY_BACKUP_RESTORE_RUNBOOK.md`; `ops/history/verify-integrity.php`.

**Integrate/inspect:** `app/Ops/BackupManifest.php`; `app/Ops/MaintenanceGate.php`; `app/Ops/StandDownPosture.php`; `app/Ops/RuntimeInvariantProbe.php`; `ops/deploy/preflight.php`; `ops/rollback/verify-stand-down.php`; `tests/fixtures/schema_table_inventory.json`; `ops/ci/qualify-release.sh`; `Dockerfile`.

**Focused tests:** `tests/integration/history_migration_upgrade.php`; `tests/integration/history_populated_restore.php`; `tests/security/history_maintenance_and_standdown.php`; `tests/integration/history_query_budgets.php`.

**Acceptance:** Fresh and upgrade databases converge with all old append-only history and migration hashes preserved. A populated backup restores the complete selected history and refuses missing private artifacts instead of inventing them. Stand-down leaves no admitted work capable of a new source/model effect; historical accounting and digests remain intact.


### LI-20 — Qualify and roll out the capability in separate operational gates

**Release:** `ROLLOUT`; **depends on:** LI-00, LI-01; **owner decision(s):** U06, U07, U08, U09.

**Add (proposed):** `docs/LONGITUDINAL_RELEASE_ACCEPTANCE.md`; `ops/road-test/export-history-review-evidence.php`; `ops/history/shadow-report.php`.

**Integrate/inspect:** `docs/MVP_RELEASE_STATE.md`; `docs/POST_MERGE_TEST_BACKLOG.md`; `docs/BETA_RUNBOOK.md`; `docs/DEPLOYMENT_RUNBOOK.md`; `deployment-evidence.json`.

**Focused tests:** `tests/acceptance/longitudinal_intelligence_journey.php`; `tests/security/longitudinal_default_inert.php`.

**Acceptance:** The selected release has reproducible exact-head code, host, quality, recovery and human-review receipts—not inferred completion. Every optional automation is inactive until its own authority and tests are present. The product can demonstrate quiet retention → due historical review → supported internal finding → no duplicate interruption.


### LI-21 — Later: bounded meta-attention and meta-meta health evaluators

**Release:** `FUTURE_OPTIONAL`; **depends on:** LI-07, LI-08, LI-11, LI-14, LI-17, LI-20; **owner decision(s):** U10.

**Add (proposed):** `docs/META_REVIEW_EXTENSION_PLAN.md`; `app/Analysis/MetaAttentionEvaluator.php`; `app/Analysis/MetaHealthEvaluator.php`; `tests/fixtures/meta_review_evaluator_cases.json`.

**Integrate/inspect:** `app/Workflow/HistoryReviewAssurance.php`; `docs/PORT_SCOPE_MATRIX.md`.

**Focused tests:** `tests/unit/meta_attention_evaluator.php`; `tests/security/meta_review_no_authority_expansion.php`.

**Acceptance:** Evaluators recommend attention/corrections but cannot change policy, spend, state, permissions or schedules. A fourth recursive layer cannot be spawned; all outputs bind recorded scoped outcomes. Fixture performance and actual operating evidence are reported separately; no full-MCM or autonomous-research claim is manufactured.


## Appendix B — Evidence and status caution

This handover is based on the previous audited PHP implementation plan, its machine-readable work packages, later current-state issue updates, and a fresh live repository state and new #65 issue read on 25 October 2026. It is a **handover, not a build receipt**. Some original planning artifacts describe PR64 as open; their exact historical bytes must be preserved, not silently rewritten. No source code, repository issue, staging deployment, owner decision, credential or database has been modified in producing this handover.
