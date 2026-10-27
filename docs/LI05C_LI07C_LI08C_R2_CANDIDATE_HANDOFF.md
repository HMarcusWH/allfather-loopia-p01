# LI-05C / LI-07C / LI-08C — R2 candidate integration handoff

**Prepared:** 2026-10-27. **State:** `TEST_ONLY / U03_NOT_RATIFIED / U05_NOT_RATIFIED / NO_RUNTIME_MIGRATION / NO_PROVIDER / NO_SCHEDULER / NO_STAGING_DEPLOY`.

## What this changes

This is a **three-PR review stack**, designed for the first *synthetic, source-metadata* longitudinal E2E before any real-source historical E2E:

- [PR #89](https://github.com/HMarcusWH/allfather-loopia-p01/pull/89): Reuses the existing LI-05A `HistoryReviewBasis` and `HistoricalEvidenceSelector` unchanged, including its four-clock cutoff law and selected/excluded/contrary counts. Adds `HistoryReviewBasisStore` for immutable, scoped **candidate** basis/ref records and `HistoryReviewContext` whose model dispatch/routing interfaces always refuse.
- [PR #90](https://github.com/HMarcusWH/allfather-loopia-p01/pull/90): Adds `HistoryM17CandidateBridge`, a **pure**, source-unverified mapping from the frozen metadata-only basis to the already qualified M17 `InputSet`, `WindowEngine`, `ProfileResolver`, `RollupEngine` and `MemoryStore` tests. All selected refs are preserved; UNKNOWN magnitude, diagnostic significance and event independence are **not** fabricated.
- [PR #91](https://github.com/HMarcusWH/allfather-loopia-p01/pull/91): Stores immutable candidate policies and due decisions in a **disposable** 290 schema with scope FKs and replay checks; adds a **not-routed** operator-only whitelist presentation adapter. Review-due produces a human-attention *suggestion*, never a job or permission.

### Candidate SQL is not a migration

| Candidate schema | Tables | Append-only UPDATE/DELETE triggers | Installed only where |
| --- | ---: | ---: | --- |
| `docs/longitudinal/proposals/270_history_review_bases_CANDIDATE_NOT_ADOPTED.sql` | 2 | 4 | disposable test MariaDB |
| Existing `docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql` | 7 | 14 | disposable test MariaDB |
| `docs/longitudinal/proposals/290_history_review_policy_CANDIDATE_NOT_ADOPTED.sql` | 3 | 6 | disposable test MariaDB |

**No** `database/migrations/270*`, `280*`, `290*`, `ops/temporal/run.php`, `ops/history/due.php`, HTTP controller/route or cron is introduced. Schema changes must not be made on Railway staging by merging this PR stack.

## The first disposable R2 test

Using existing synthetic P01 metadata vectors and **real temporary MariaDB 10.6/10.11**, run:

1. Freeze a 1-month basis at a mid-period known-at cutoff (historically earlier *event-at* does not make later-known evidence available). Persist two exact references. Verify replay does not write.
2. Freeze a successor cutoff where a third contrary reference becomes known. Retain original memory and append a successor. A second incomparable successor remains a second head, leading to `AMBIGUOUS_HEAD_HOLD` without an LWW overwrite.
3. Store an explicit bounded review policy candidate and immutable due record, repeat the exact clock/basis as an idempotent no-op, and refuse reusing that same clock identity with a changed queue state.
4. On a new basis a human-review due candidate is recorded. If current rights are blocked, record `HOLD_CURRENT_RIGHTS` rather than authorizing a model run.
5. Check cross-tenant basis/memory/due readback refusals, UPDATE/DELETE trigger enforcement, source/context non-admission, and no production migration or execution command.

The R2 operator adapter derives scope from a trusted operator session, delegates **permission checks to an owning reader** and copies only bounded basis identity, counts and due/head statuses to the presentation. Its `HistoryReviewReadAdapter` is deliberately *not* wired to any HTTP route; the source-owner and M02 authorization chain must be completed before activation.

## Outstanding gates

- **U03:** Canonical [proposal #380](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/380) is merged as a **proposal**, not a historical-use approval. `HistoricalCaptureProbe` can verify native bytes but cannot mint a reuse/disclosure permission. Missing M05 lineage, current licence withdrawal, source quarantine and known-at uncertainty must still block.
- **U05:** Canonical [proposal #381](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/381) is merged as a **proposal**, not an adopted review policy. No unattended review, model, budget reservation, email, notification or scheduler is authorized.
- **U04:** Canonical Option A owner decision is recorded, but the full owner-native execution/migration parity and date/numeric law are not automatically satisfied by PHP candidate tests.
- **Operational migration:** A separately reviewed, additive 270/280/290 set, production schema inventory, owner-native positive sources, populated upgrade/restore, private artifact checks and Railway environment GO are still required.
- **Full longitudinal analysis:** U06 historical request/critic and human review, U07 multi-evidence release, U09 internal attention and U01 descriptive materiality successor are **not** prerequisites for the synthetic R2 test, but are prerequisites for the later AI intelligence end-to-end.

**Claim ceiling:** Passing all candidate tests proves deterministic *metadata* and atomic DB behavior on the named source tree in ephemeral infrastructure. It is not a production-ready longitudinal-intelligence release, an owner-rights proof, source authenticity proof or Railway native backup validation.
