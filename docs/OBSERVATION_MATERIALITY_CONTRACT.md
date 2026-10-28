# LI-02 — proposed Observation/Materiality v2 contract (OFFLINE INERT)

**Date:** 2026-10-25. **Status:** U01 PROPOSAL, NOT CANONICALLY RATIFIED. The canonical draft [Allfatherbusinessintelligence PR #379](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/379) and original 22-work-package plan are proposal sources. Neither a passing fixture nor this PHP code changes source authority, evaluation status or model invocation grants.

## Semantic separation

- **Structural diff:** Existing P01 `ChangeDetection` records NONE/MINOR/MATERIAL/UNKNOWN based on bounded text difference, NOT economic relevance.
- **Descriptive observation:** Exact evidence-backed descriptions of what changed. An in-scope menu reorder can now appear in `observed_changes` while `status=NO_MATERIAL_CHANGE`; no observation becomes a certified M05 fact merely by model extraction.
- **Commercial materiality:** v2 `significance` is the *candidate assessment*, not the structural ratio. `FINDING` requires cited observations, `MINOR` or `MAJOR`. Small text changes to price digits, negation, dates or contract terms must not be filtered out by structural distance.
- **Attention:** `RETAIN_ONLY/REVIEW_LATER/REVIEW_NOW/HOLD` are solely recommendations. No scheduling, notification, customer publication or release follows from any value. Nonmaterial quiet candidates are `RETAIN_ONLY` only.
- **HELD:** injection or unrecoverable ambiguity requires no observations/hypotheses, `UNDETERMINED`, `HOLD`, safe limitations. Independent critic REVISE/BLOCK and human review are later gates, not present here.
- **Evidence:** every observation cites existing `CAPTURED_SOURCE_OBSERVATION` IDs; relevance hypotheses are separately typed and require exact source evidence and `TENANT_PROVIDED` context citations. Unknown/foreign/reused IDs refuse. The mechanical checker **does not** independently revalidate source rights or verify natural-language truth.

## Exact implementation

- `app/PromptOps/bundles/monitor-observation-v2.json`: standalone frozen proposed v2 content and schema with canonical SHA-256 prompt/schema hashes. No v1 file rewritten.
- `app/PromptOps/PromptBundleRegistry.php`: explicit version resolution. Legacy `ProductPromptPack::resolve(role)` and every governed approval, selection, critic and execution path still bind the original `2026-09-27.monitor.v1` pack.
- `app/PromptOps/ObservationCandidateChecksV2.php`: strict schema, evidence class, citation, injection/HOLD and status/attention consistency checks; calls through `CandidateChecks` only when **exact** proposed pack identity is supplied.
- `PreparedModelRequest::prepare` **refuses** all proposed v2 prompts with `MODEL_PROPOSED_OBSERVATION_V2_INERT`. This is an explicit permanent non-execution gate in the current source until a future reviewed authorization project; self-hashed/relabelled objects cannot bypass trusted selection.
- Canonical proposal vectors: `tests/fixtures/monitor_observation_v2_eval_cases.json` (12 proposed cases; **not a heldout set**, not model quality). Unit/integration/foundation suites cover v1 digests, v2 quiet/price/negation, source/tenant citation refusal, malicious historical content, forbidden action fields, advisory-only attention and no provider writes.

## Explicitly not delivered

No U01 adoption record; no new `monitor_prompt_approvals`, model grants or schema migrations; no cost or model-quality acceptance; no durable v2 observation records, competitor UI, live capture, history analysis, M17, internal alert or customer visibility. The legacy `NO_MATERIAL_CHANGE` v1 rule remains **empty observed_changes** and all historic DEV7/18-case identities remain untouched.

**Next owner-gated work:** U01 review (canonical P01/PromptOps), LI-18 independent genuinely frozen heldout manifest/human scorecard before prompt tuning or model approval, then versioned `MODEL_EVALUATION` and human review. LI-03 persistent assessments additionally require U02; U03 current source-use historic rights must be resolved before exposing earlier content.


---

## BUILD-06 / OB-01 — executable v2 candidate composition, no paid/live admission

**Build date:** 2026-10-28. **Integration scope:** build-first Wave 02; BF-01 and VA-00 dependencies merged. **Source-build status: BUILT_INERT**, not a ratified U01 contract. Canonical source remains proposal [Allfatherbusinessintelligence PR #379](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/379); U06 and independent held-out/human evaluation are pending. This addendum preserves the 2026-10-25 LI-02 proposal above, original v1 and DEV7 evidence.

### New executable offline surface

The new **ObservationMaterialityV2** class implements deterministic typed synthetic-development composition. It verifies distinct before/after evidence IDs, strictly increasing snapshot sequences for the same source, per-snapshot SHA-256 of exact supplied text, scoped tenant/workspace IDs and a canonical context digest. It uses the existing **PromptSelectionResolver.inert().contentVersioned()** to obtain only the original, independently hash-checked inactive v2 prompt. Every actual generated JSON candidate passes through **ObservationCandidateChecksV2.validate()**. Passing this checker proves schema/reference consistency, not semantic truth or current source-use rights.

**PromptSelectionResolver.selectVersioned(role, version, grant)** refuses unadopted v2 with **PROMPT_OBSERVATION_V2_U01_NOT_ADOPTED** even if the caller forges a grant. An unknown version refuses without fallback. Explicit v1 selects via the existing select method, unchanged. The runtime PromptApprovalStore, MonitorFactory, MonitorService, ModelExecutor and PreparedModelRequest remain unchanged; its prior **MODEL_PROPOSED_OBSERVATION_V2_INERT** provider-request refusal remains mandatory.

The fixture origin is declarative and **not authentication**. Even a forged synthetic flag cannot unlock provider access, scheduling, real source use, customer-facing notification or release: the composer creates no network/DB/provider object and has no native factory binding.

### Typed contract and semantic separation

**Input: ob01.observation-composition.synthetic.v1.** Synthetic scope, exact tenant/workspace, ordered before/after source evidence IDs, bounded UTF-8 text, source-consistency state, separate reported structural magnitude, proposed business significance, attention suggestion, and optional synthetic tenant-context hypothesis. All source text is checked against digested fixture evidence; a new grant, URL, secret or release field is refused.

**Output:** A complete proposed v2 JSON candidate plus prompt/schema hashes, candidate/context/source-text digests, explicit reported structural magnitude, distinct proposed significance, attention recommendation, and true independent-human-review requirement. All execution, paid provider-dispatch, release, notification and customer-output authority fields are FALSE. Semantic truth and current source rights are NOT VERIFIED. Owner status is U01_U06_NOT_ADOPTED.

Nine development-only synthetic cases are frozen in the OB01 fixture bundle. A menu reorder (even when structural magnitude is MATERIAL) or copyright-year change returns **NO_MATERIAL_CHANGE**, significance NONE, RETAIN_ONLY, exact source citations, and zero commercial hypotheses. Explicit synthetic price, negation or date changes can produce **FINDING** candidates only with a separately stated unverified minor/major business-impact hypothesis citing both captured-source evidence and tenant-provided context. Missing, contradictory or injection-suspected evidence produces **HELD**, empty observations/hypotheses, explicit limitations and advisory HOLD. Neither a tiny text distance nor a large text distance decides commercial significance.

The source-injection string tripwire is deliberately not a comprehensive model or source-security detector. These tests are deterministic fixture computations, **not** model quality, historical M05 source-use permission, hidden held-out success or authenticated human judgment.

### Frozen prior evidence, deferred adoption and QA

The legacy 2026-09-27.monitor.v1 prompt bundle and 18-case Monitor evaluation manifest retain the exact SHA-256 commitments established by VA-00. Prior DEV7 5/7 mechanical development performance and independent held-out/human-quality gaps are not revised. No v1 candidate is reinterpreted as v2, no new prompt/approval table is created, and no live request can reach v2.

Focused tests: run tests/run.php with --layer=foundation --filter=ob01_materiality_boundary; --layer=unit --filter=ob01_materiality_composition; --layer=security --filter=ob01_materiality_refusals; and --layer=integration --filter=ob01_materiality_db_inertness. Add --strict in qualification. All five required GitHub checks on the exact final PR head/merge ref must succeed before an isolated branch merge.

**Remaining:** U01 semantic ratification, U06 model execution contract, U02/U03 rights where applicable, OB-02 persistent evidence, independent VA-01/V05 human quality, RT-02 composition and final V01–V11 operational review. No paid provider, live scraper, real historical source use, migration, customer output or production GO is authorized by the build.
