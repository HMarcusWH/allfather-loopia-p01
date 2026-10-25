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
