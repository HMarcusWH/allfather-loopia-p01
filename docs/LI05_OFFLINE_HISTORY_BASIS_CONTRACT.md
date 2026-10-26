# LI-05A — inert, deterministic historical evidence-basis candidates

**Created:** 2026-10-26. **Status:** `PURE / OFFLINE / NOT_PERSISTED / NOT_ADMITTED`. This is an engineering precursor to the LI-05 work package, **not** a canonical U03/U06 decision, source-use permission, or model/release interface. All inputs must be synthetic or supplied by an independently authorized caller. The selector itself reads no P01/M05 rows, captured bytes, evidence store, external source or provider.

## Boundaries

- **U03 pending:** historical reuse, clocks, source rights and revoked/retained-byte eligibility have no adopted historical owner in these classes. `HistoricalEvidenceEligibility` accepts only caller-**reported** informational states, never `allowed=true` or a permission credential. Even a row labelled `UNASSESSED` is **not** authorized for historical reuse. Every selected reference has `rights_disposition=NOT_ASSESSED` and `historical_reuse_admitted=false` on its enclosing manifest. `QUARANTINED`, `REVOKED`, `DENIED`, `UNKNOWN` and `MISSING_BYTES` are excluded from the **offline metadata candidate**; none is a current M05 state assessment.
- **U06 pending:** no historical-analysis model purpose, budget, dispatch, request preview, critic, successor execution, customer route, P04 release, scheduler or notification is admitted. The classes do **not** implement `ModelContextAuthority` and do not relax `ContextEnvelope`.
- **Immutable means hash-bound value, not host storage:** `HistoryReviewBasis::freeze` returns a canonical `manifest_digest` over the entire validated candidate; `verify` refuses tampered bytes or any attempt to change `historical_reuse_admitted`/`rights_disposition` to admission. This is not a digital signature, a current rights check, authentic M05 provenance, or filesystem immutability. No manifest is persisted by this PR.
- **Identity:** tenant, workspace, P01 competitor and question are separate, explicit identifiers. A P01 competitor is **not** automatically mapped to a P02 entity twin by UUID, domain or string similarity. Foreign-scope rows refuse the entire operation without per-row counts or details. `source_ref_id`, `snapshot_id`, `evidence_id`, `assessment_id` and payload SHA-256 are caller-reported owner references, never replaced by a duplicate history store.
- **Source text:** raw body, URL, provider prompt, source excerpt, user-facing description, price/body content or a materiality verdict are forbidden row fields. Polarity `SUPPORT`, `CONTRARY` or `UNKNOWN` is a caller-supplied *descriptive label*, **not** verified business importance or independent corroboration. `EXACT_BYTE_EQUIVALENCE_ONLY` means identical supplied payload hashes at most, not a common real-world origin or independent witness.

## Exact v1 selection contract

`HistoricalEvidenceSelector::select` requires exactly:

```text
scope                      {tenant_id, workspace_id, competitor_id, question_id}
period                     {start_inclusive_utc, end_exclusive_utc}
baseline_period            same shape OR null
baseline_null_reason       null if supplied, otherwise NO_BASELINE_AVAILABLE or NOT_REQUESTED
knowledge_cutoff_utc       fixed YYYY-MM-DDTHH:MM:SSZ
rows[]                     maximum 256 closed-shape metadata records
max_references             integer 1..32
coverage                   {state:UNKNOWN} or closed KNOWN slot accounting
```

Each record requires exact strings: `ref_id`, tenant/workspace/competitor/question, `source_ref_id`, `evidence_id`, `snapshot_id`, SHA-256 `payload_sha256`; nullable `assessment_id` and `event_at`/`analyzed_at`; `observed_at`, `recorded_at`, `known_at`; `semantic_polarity`, `origin_group`, `reported_disposition`, `provenance`. `provenance` is `M05_CAPTURE_METADATA` or `OWNER_SUPPLIED_METADATA` *as reported*, not authenticated. Duplicate primary/evidence/snapshot IDs refuse. All identifiers are bounded ASCII and hashes are lowercase 64-hex. The entire serialized input is bounded to 512 KiB. Invalid Unicode, unknown keys, clocks or counts fail closed.

**Time law:** The historical observation window is half-open `[start_inclusive_utc,end_exclusive_utc)`, selected by `observed_at`, **not** the untrusted `event_at` claim. `known_at <= knowledge_cutoff_utc` is inclusive; a pre-dated event first recorded/known after the cutoff cannot appear in the earlier candidate. Required order: `observed_at <= recorded_at <= known_at`, and, when present, `analyzed_at >= known_at`. Analysis that occurred **after** the cutoff is not retroactively revealed: its timestamp and `assessment_id` are both `null` in the earlier basis. Only normalized second-precision UTC with a literal `Z` is accepted; timezone offsets, local/DST assumptions, leap seconds and invalid dates refuse.

**Ordering / caps:** Order eligible metadata by `observed_at`, then `ref_id`, stable regardless of the caller's array order. Apply the reference cap after clock, scope and descriptive availability checks. Exclusion counts distinguish `BEFORE_WINDOW`, `AFTER_WINDOW`, `NOT_YET_KNOWN`, `REPORTED_NOT_USABLE` and `SELECTION_CAP`; they describe only the supplied input. If a known in-range CONTRARY record is omitted for descriptive unavailability or the cap, set `contra_evidence_not_included=true` and `selection_integrity=INSUFFICIENT_BASIS`. Future-known contrary evidence is **not** smuggled into an earlier basis.

**Coverage:** `KNOWN` contains one reported plan-version denominator with integer `planned_slots`, `captured`, `unchanged`, `failed`, `skipped` and `unreported`. The sum of captured+failed+skipped+unreported must equal the planned denominator; `unchanged <= captured`. `UNKNOWN` returns null counts and an insufficient basis. Even `KNOWN` is `CALLER_SUPPLIED_UNVERIFIED`, not M05 or scheduler confirmation. Twenty byte-identical captures count as twenty captures **but zero independently proven events**; the `independent_event_count` remains `null`, never inferred from byte equivalence. Missing slots are *unreported*, never transformed into `NO_CHANGE`.

**Candidate disposition:**

- `status=OFFLINE_NOT_ADMITTED` with `selection_integrity=COMPLETE_WITHIN_INPUT` only when at least one supplied row is selected, all in-window/as-of reported candidate rows fit, the reported coverage is KNOWN and no reported unusable/contrary record was omitted. The **completeness scope is the caller-supplied metadata only**; it says nothing about the existence of other records, accuracy of rights, completeness of the evidence store or fitness for an LLM.
- Otherwise `status=INSUFFICIENT_BASIS`; the manifest is still `NOT_ADMITTED` for every purpose. All manifests, including a deterministically complete metadata subset, remain **NO EXECUTION AUTHORITY**.
- `manifest_digest` uses the existing `Allfather\Loopia\Core\Digest::canonicalDigest`, including selector/eligibility/schema versions and all normalized fields. A later knowledge cutoff produces a new hash-bound successor and does not mutate the former manifest. The digest is a reproducibility key, not authentication of the inputs.

## Tests and explicit follow-on

Fixtures in `tests/fixtures/li05_offline_basis_vectors.json` contain **synthetic** tenants, P01 IDs, hashes and clocks. Dedicated unit/security/foundation tests cover UTC boundaries, equal cutoff, late-known observation, successor digest, stable tie ordering, cap-dropped contradiction, quarantines/revocations, cross-tenant refusal, gaps, exact-byte repetitions, malformed clocks, invalid UTF-8, unknown fields and manifest integrity. Both MariaDB 10.6/10.11 full strict suites and the exact-source package rehearsal still gate the PR; no new test database or live DB is required for these pure tests.

**Later LI-05B only after U03 and U06 owner ratification and selective adoption:** a scoped historical eligibility owner re-checks current M02/P01/M05 permission, source terms, revoked/quarantined states, retained bytes and all four clocks before selection, again at dispatch and release. A separately reviewed `HistoryReviewContext implements ModelContextAuthority`, private preview, and the *proposed* `database/migrations/270_history_review_bases.sql` then need additive fresh/upgrade/no-op/restore qualification. None exists or is authorized here. U01 materiality and U04 M17 successor uniqueness remain upstream proposals rather than adopted Loopia runtime changes.
