# P01 parity repairs — 27 September 2026

These are curated upstream repairs for the bounded Monitor workflow. The authority lock remains `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`. Canonical main was inspected at `32c98a7191b7a4ae7a45d745917f39521f8f5112`; the affected P01/M15/M16 source had no intervening fix relative to the pinned authority. This record does not claim the complete canonical repository is ported.

The upstream changes were prepared first. At this record's creation all four pull requests are draft and unmerged, in this dependency order:

| PR | Exact head | Repair | PHP mapping |
|---|---|---|---|
| [365](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/365) | `a6948a0048664d01baf33020613b052eadc6fca3` | Persist source contract authority; compiler v3 refuses missing external licensed authority | `SourceCards.php`, `Ingestion/SourcePolicy.php`, migration180 |
| [366](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/366) | `2096c7dac38565c78a3e306bea9af19299ffbeb5` | Capture-bound normalized-line proofs; classification/digest/replay integrity | P01 `Capture`, `TextProvenance`, `ChangeDetection`, `Assurance`; migration180 |
| [367](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/367) | `d1ccfbff4eeb19a6dde96d2add6bfe0b74211598` | Preserve owner-profile context and uncertainty through governed release | P01 `CompanyContext`, `CompanySignals`, `Battlecards`, `Release` |
| [368](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/368) | `af5992c58bdb653f6ca8be905a17ddab992c5702` | Atomic, repeatable workspace setup with exact shared-registry checks | P01 `Registration`; migration185 |

`P01_PARITY_REPAIRS_SOURCE_MANIFEST.json` binds the upstream file bytes at each head and the PHP runtime, migration and regression files. The manifest excludes itself; no secret or environment contents are included.

## Behavior and limits

Source-card projection stores an explicit contract reference instead of discarding it. Existing rows remain NULL. Open/public sources do not acquire an invented contract requirement. The existing explicit P03 tenant-owned upload exception remains separate from external licensed authority.

Structural significance derives from fingerprints created at the governed fetch boundary, bound to the capture content hash. Optional caller text must match before replay; a changed threshold or different classification cannot silently reuse a prior diff. Proofs contain sorted line hashes, not raw source text. Retention requires a derived-use grant, standard retention, and standard-cache or explicit-contract cache policy. No-cache, short, audit-minimized or prohibited policy stores no line proof: a changed hash then yields UNKNOWN. Existing history is not rewritten; unbound historical MATERIAL/MINOR classifications are auditable as incomplete provenance.

Context is additive to the two source observations and change reference. It is admitted only for a diff-backed signal, for a scoped M05-admitted `UPLOADED_SOURCE_CAPTURE` over an active `UPLOADED_ARTIFACT` source with matching use scope. A normalized annotation is not silently treated as an upload. Current source/evidence authority is checked again during release. The profile is a separate M16 first-party contribution, retaining sensitivity and denying customer output; it does not inherit the competitor source licence. Caller uncertainty appends to the mandatory interpretation statement and survives into the battlecard and M16 artifact. The existing no-diff candidate path is not expanded by this option.

Registration reuses only exact compatible global read-tool/operation and commercial tier definitions. Each workspace retains its own agent and contract. Shared runtime mappings must be active, read-only and scopeless. An immutable receipt permits exact reruns; changed input, global definitions, contract scope/actions or original tool rights refuse without repair/reactivation. All new workspace writes are transactional. No previous setup is guessed from similar names or backfilled. Loopia's existing tool keys and commercial terms remain unchanged; role memberships are not granted by registration.

## Verification

- Canonical: 945 source-authority regression tests; 1,058 capture-provenance tests; 388 context/P01/M16 tests; 291 registration/P01/database tests. Final combined P01/P03/ingestion/M16/M11/database run: 1201 passed. Later review amendments have focused refusal regressions.
- TypeScript formatting and lint pass. Whole-project typecheck still reports eight existing errors in unchanged `sev/phenotype-host.ts`, with diagnostics byte-identical to the inspected main baseline; a full typecheck pass is not claimed.
- PHP P01 focused run: 429 passed, zero failed/skipped before the final narrow registration-right review amendment. The amendment has a dedicated follow-up test receipt. Actual canonical proof vectors yield 73 PHP assertions, including Unicode trim, duplicate lines, JavaScript rounding ties, very small threshold encoding, and malformed proof refusal.
- Regression fixtures explicitly state when comparison proof is synthetic. Captured proof remains unavailable when source policy forbids retaining it; tests do not pass invented caller text as capture evidence.

Reproduce PHP checks with a dedicated disposable MariaDB database via `TEST_ENV_FILE`, then `php tests/run.php --strict --filter=p01`. The focused run uses PHP8.5.4/MariaDB10.11.19 with external network disabled. Full release qualification and host deployment are separate parent-task checks. No staging, provider, live-read, scheduler or production activation occurs through these changes.
