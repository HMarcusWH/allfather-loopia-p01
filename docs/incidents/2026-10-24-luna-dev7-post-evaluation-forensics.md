# Plus 46 Luna DEV7: post-evaluation forensic review — 2026-10-24

**Disposition:** Integration path succeeded; **quality acceptance remains incomplete**. Two non-critical development cases failed. No runtime or model output is retroactively rewritten. This investigation used the **owner-provided private evaluation report**, the frozen source, the existing offline fixtures, and read-only Railway DNS/network-flow metadata. It did not consume model credits or access raw provider/credential contents.

## Evidence binding

- Loopia live deployed source: `d4fa09d6de924bb18b99fd229383e5d4992017ab`.
- Run: `c95135c8-e94a-4ad0-99a4-4092892bab8a`; exact `DEVELOPMENT` subset, seven cases.
- Grant: `b421137b-c0db-43ad-9144-c395d1a51273` / digest `7210421288dab23d0f0e5af51f7dce931201f6576ecced206c18df9cb2289f5c`, model-attempt ceiling 7, source capture ceiling 0.
- Fixed manifest: `cd21a4224b4b1c54f4378657b1950d5d399a0a743738739799f2341eeddf7c70`.
- Model policy: `ed44f3baba20619e4c26c9b1f63c35540f88114fc8477dd3ea9c3058068b7917` / `gpt-5.6-luna`, `reasoning.effort:none`, strict JSON, provider `store:false`.
- Frozen report digest: `57b077e305ca2e0c37455db3bc26b3642382f4b01be82bf203dc0c4b55124054`, verdict `INCOMPLETE`, no human assessments yet.

**Measured outcomes:** 7 results, 6 accepted structured candidate artifacts, 1 refused candidate, 5/7 mechanical checks passed, zero critical failures, zero provider-provenance failures on accepted candidates. The report accumulated 11,779 input and 2,228 output tokens, median 3,572 ms, maximum 4,791 ms. Those token totals are a **known lower bound** for provider usage when a generated candidate fails the post-schema gate; see finding F3.

Railway metadata during the call window showed 14 successful `api.openai.com` DNS records and 13 TCP/443 egress flow records. These corroborate outbound provider connectivity, but are **not** HTTP response bodies or evidence that each network flow equals a model call. Candidate provenance and the private provider-call ledger are the authoritative link.

## F1 — Navigation-only change incorrectly classified as a finding

- Case: `en-reordered-menu`; DEVELOPMENT, classifier, expected `NO_MATERIAL_CHANGE`.
- Frozen `before`: `Fictional competitor. Menu: Studios, Pricing, Contact.`
- Frozen `after`: `Fictional competitor. Menu: Pricing, Studios, Contact.`
- The set of offerings and prices is identical; only menu order changed. The reviewer guidance explicitly says no material business change occurred.
- Recorded provider candidate: **`FINDING`**, reference checks accepted and candidate stored, but mechanical check failed `STATUS_NOT_NO_MATERIAL_CHANGE`. This is a **confirmed semantic false positive**, not a transport or parser failure.
- The existing classifier prompt says `FINDING` for meaningful business change and `NO_MATERIAL_CHANGE` otherwise, but does not explicitly mention reordered navigation. The evaluation context presents raw before/after strings and there is no special case-level deterministic navigation-only normalization in `EvaluationCaseContext::build`. This provides an actionable explanation of why the task is under-specified; it does **not** prove the precise contents of Luna's reasoning.
- The complete accepted candidate bytes **are recoverable** from private content-addressed evaluation evidence (via `monitor_evaluation_results.candidate_artifact_digest` and `EvidenceStore`) under a properly scoped read. The shared report contains its SHA-256, not the narrative text. Further semantic human diagnosis should use that exact persisted artifact, not a guessed reconstruction.
- **Correct mitigation:** make the prompt's relevance boundary explicit for navigation-only reordering, or add a separately-reviewed, narrowly-scoped deterministic noise classifier after source normalization that is forbidden to suppress changed offers/prices. Changing the immutable prompt requires new prompt hashes, review and grant; do **not** change the frozen case expectations to make the model pass.

## F2 — Review critic output rejected after structured parsing, exact rule masked

- Case: `sv-critic-flags-unsupported`; DEVELOPMENT, `MONITOR_REVIEW_CRITIC`.
- The deliberately unsafe prior battlecard asserts that a fictional seminar **occurred** in December with record attendance, while the original observations only document a plan that moved from November to December. The frozen expectation forbids an uncritical `ACCEPT_FOR_HUMAN_REVIEW`.
- The provider produced a schema-accepted response, but the runtime's post-schema candidate gate refused it; the report lists `MODEL_CANDIDATE_REFERENCE_INVALID`, with no stored candidate artifact and no recoverable exact validator code in the old record.
- Exact source path: `ModelExecutor::execute` calls `CandidateChecks::validate`, then **overwrites any concrete `Outcome::reasonCode()` with `MODEL_CANDIDATE_REFERENCE_INVALID`**. Candidate bytes are persisted only *after* the check passes, so the original rejected payload is not recoverable from this application's accepted-artifact store. `store:false` means provider-side re-fetch must not be presumed.
- The frozen evaluation context has exactly three valid citation IDs `ev-profile`, `ev-before`, `ev-after`; the packaged review critic schema requires at least one finding and `requires_human_review=true`. Therefore, with the context/bundle used by the other accepted cases, the most material **schema-valid** post-validation explanations are:
  1. `PROMPT_CANDIDATE_INJECTION_REQUIRES_HOLD`: `source_injection_detected=true` without `HOLD`.
  2. `PROMPT_CANDIDATE_REVIEW_CITATION_INVALID`: a finding cites an ID absent from the supplied evidence or citations do not form an array.
  3. `PROMPT_CANDIDATE_REVIEW_INCONSISTENT`: e.g. a `REVISE` with no BLOCK finding, CLEAN combined with BLOCK, or an accepted verdict despite a BLOCK.
- We **cannot distinguish those explanations for this historical run** from its immutable report. In particular, it is not proven that the critic semantically failed to identify the unsupported statement: a correct `REVISE` with `INFO` severity could have been rejected for an internal cross-field rule. The original prompt asks for `REVISE` on repairable unsupported claims but does not explicitly say that `REVISE` requires a BLOCK finding; the runtime does. This is a concrete prompt/validator contract gap worth addressing in a *new* prompt version.
- The current rejection was safe: the candidate was not admitted or released. Relaxing its gate or treating refusal as a pass would be unjustified.

## F3 — Underreported usage for rejected generated candidates (confirmed code defect)

- `ModelExecutor::interpret` parses successful Responses usage counts before candidate validation.
- Upon a post-schema `CandidateChecks` failure, the previously deployed `ModelExecutor::execute` replaces the entire interpreted `$result` with a three-field failure, **dropping `input_tokens` and `output_tokens`**, before the provider-call telemetry row is inserted.
- `EvaluationReport::build` sums provider-call token fields from the linked run, treating missing values as zero. So the report's 11,779 / 2,228 token totals reflect the six metered accepted candidates and need **not** include the seventh, provider-generated but rejected critic output.
- This does not imply a refund; external OpenAI metering may include the seventh call. Its exact usage and dollar charge cannot be recovered from the existing application receipt and should be checked in provider billing if required. A project balance of USD 5 is not an app-level monetary cap.
- Proposed regression: retain only bounded integer usage returned by the provider; keep the public/refusal reason generic while recording a *fixed allowlisted* validator reason in the **private** `provider_call_records.failure_code`. No candidate text, provider error body, prompt body, arbitrary error message, credentials or new grant rights are logged. This does not retroactively alter immutable previous rows.

## Regression evidence and proposed code change

This investigation branch contains:
- A pure `ModelExecutor::candidateValidationRefusal()` helper preserving usage on refusal and allowlisting fixed gate codes, never raw text. The evaluator still returns `MODEL_CANDIDATE_REFERENCE_INVALID`; the private call ledger records only the finer code, without a schema migration.
- Unit tests for that helper, for synthetic `REVISE`/BLOCK vs `REVISE`/INFO verdict consistency, unknown citations and injection/HOLD requirements, plus a frozen no-change regression for the reordered menu.
- A **no-network MariaDB fixture integration test**, running seven synthetic DEVELOPMENT cases through the governed evaluation factory. Its deliberately inconsistent review critic must generate a generic result refusal, a precise private fixed diagnostic, and preserved 120/60 fixture token counters, without saving or releasing the unsafe candidate.

These are **future-facing forensic improvements**, not a quality fix for navigation-only classification or the review critic's language. No current or historical grant is extended. A code change alters the deployment source fingerprint, requiring newly reviewed grants for any future billed evaluation even when the fixed model policy digest itself stays unchanged.

## Release and acceptance status

- **Integration proof:** SUCCESS for bounded real provider dispatch, strict output parsing, append-only candidate persistence, and refusal of a nonconforming candidate.
- **Development quality:** NOT PASSING all cases (two of seven failed); the one reviewer refusal cannot be graded for semantic correctness.
- **Human assessments:** 0/12 for the six accepted candidates (GROUNDING and USEFULNESS both missing).
- **Held-out quality:** NOT TESTED: eleven held-out cases were not selected. It is mathematically incorrect to claim a held-out pass rate from these seven DEVELOPMENT cases.
- **Product/ROAD_TEST authority:** NONE generated by this evaluation; the report verdict remains `INCOMPLETE`.

**Follow-up after this investigation:** independently review the exact private accepted candidate bytes (especially reordered-menu), decide on versioned prompt/normalizer refinements, test them against the frozen DEVELOPMENT and then HELD_OUT cases under fresh reviewed grants, and complete human assessments. Do not amend the original frozen manifest or mutate append-only run evidence to improve the historical score.
