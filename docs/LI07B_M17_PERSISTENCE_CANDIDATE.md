# LI-07B — M17 durable-memory implementation candidate (NOT_ADMITTED)

**Prepared:** 26 October 2026. **State:** `TEST_ONLY / NOT_DEPLOYED / NOT_ADMITTED / OWNER_HOLD`.

This branch is a code-and-disposable-database engineering proposal, **not** ratification of U04, activation of G21, a production migration, or permission for stored historical evidence reuse. The canonical upstream M17 owner decision remains **draft** at [Allfatherbusinessintelligence PR #378](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/378). U03 historical-use eligibility and U06 model/analyst purpose are also undecided. Do not merge/deploy this PR until these gates have an explicit reviewed adoption record for the permitted subset.

## Decisions encoded as candidate behavior, not adopted law

1. Exact candidate derivation identity includes tenant, workspace, **P01_COMPETITOR**, competitor, question, data class, period/window kind and bounds, engine version, input digest and resolved-profile digest. P01 IDs are **not** synthetic P02 twin identities.
2. The proposed physical memory-output uniqueness is `(compression_run_id, memory_class, output_ordinal)` with a real run foreign key; identity is **not** just `(scope,period,engine)` because that would prohibit immutable late-input successors. `PERIOD_SUMMARY/0` is the only fixture output class; arbitrary other output shapes remain inert. The alternative input-digest-based unique memory key in U04 remains open for owner evaluation.
3. `supersedes_memory_id` and preserved immutable rows represent addition-only late-input successors. Two incomparable children of one parent remain **two preserved heads**, and `headCandidates()` reports `AMBIGUOUS_HEAD_HOLD` rather than guessing `MAX(created_at)`.
4. Schema contains the seven proposed G21 families, with append-only triggers on UPDATE/DELETE, scoped relational FKs, strict CHECK/JSON shapes and no raw evidence bodies. The profiles/rules/statuses are **candidate-only**, not operational approval. Source reference IDs in lineage are typed metadata strings, NOT owner-authenticated M05 foreign keys; this is an explicit unresolved U03 gate. No M05/P01/P02 canonical truth writes.
5. Frozen context is full typed LI-06A data, deterministic LI-07A rollup and complete in-window lineage. `replayCandidate()` checks stored input metadata and expected derived digest with no fetching or model calls; `MATCH_METADATA_ONLY` is **not** historical-source authenticity or an authorization to use bytes.

## How the candidate can be tested without becoming a migration

- SQL **only** at `docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql`. There is deliberately **no** file under `database/migrations/280_*` and no change to schema inventory, production entrypoints, grant vocabulary, provider budget, scheduler, source cards, M10 audit or release. `Migrator::up()` therefore does not install the seven candidate tables in Railway staging or any normal database.
- The sole `MemoryStore` factory requires `ALLFATHER_LI07B_DISPOSABLE_SCHEMA_LOADED === true` in a PHP CLI process. It is a **test harness switch**, **not a production authorization mechanism**. No production instantiation exists; the retained migration and runtime wiring are blocked on owner authority and future properly governed admission checks.
- `tests/integration/m17_disposable_persistence_candidate.php` uses a disposable MariaDB QA database, executes the candidate SQL explicitly, tests exact idempotency, full lineage, append-only rejects, scoped reads, non-mutating successors, competing forks, and metadata-only replay, then drops those candidate tables in test cleanup. The proposed SQL itself has **no DROP**. Both MariaDB 10.6 and 10.11 strict hosted suites must run with zero skips.
- `P01HistoryAdapter` delegates to the existing permission/source-checked P01 history reader and returns only a redacted metadata projection. It intentionally **cannot build an InputSet**, because the safe owner read does not disclose payload digests or authorize historical reuse. No HTTP route exposes this adapter.

## Owner ratification and promotion gate

**U04 (M17/P01/P02/M05/M13/Ops):** choose physical memory output identity and head-selection contract; clarify question scope, P01-vs-P02 namespace, time-zone/rules fingerprint, numeric units and canonical serialization. Review forks, profile/engine successors, race behavior, redaction/rights invalidation, backup/restore, idempotency and table ownership. Adopt versioned canonical vectors then implement parity. No decision is silently adopted from the proposal.

**U03 (M05/producer):** define current/historical use eligibility, retained bytes, quarantine, source-card rights, redaction, known-at/capture-time clocks, and revalidation. Without this, no real captured P01 evidence is admitted to stored memory, even if the schema and code pass CI.

**U06 (execution):** only if a separate model-assisted historical-analysis purpose is later approved; *not* necessary for deterministic metadata fixture tests. It remains pending. Absolutely no model/provider calls, automated scheduling, customer output or M16 publication is included in this branch.

**Before merge:** owner-approved U04 and scoped U03 adoption; move/review the candidate SQL into a new additive production migration (not rewriting the fixture proposal), update exact schema inventory and reachability docs, qualify fresh/upgrade/no-op/restore on disposable MariaDB 10.6 and 10.11, replace fixture-only gate with verified current source rights and test principal admission, test true concurrent DB races and populated recovery, and obtain explicit release approval. The tests of this draft alone do not satisfy those gates.
