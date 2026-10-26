# LI-07B — populated logical roundtrip and U04 decision-readiness checkpoint

**Date:** 2026-10-26  
**Scope:** Review-only supplement to PHP PR #86, **NOT_ADOPTED / NOT_DEPLOYED / NOT_AUTHORIZED**.  
**Source:** Existing M17 design (`HMarcusWH/Allfatherbusinessintelligence@1169428f...`) and U04 proposal (#378), plus locally proposed seven-table G21 SQL outside the production migration folder. This is **not** an amendment to the adopted canonical identity law.

## Technical proof added in this revision

The new `tests/integration/m17_candidate_populated_restore.php` intentionally executes the unadopted seven-family schema and its 14 UPDATE/DELETE refusal triggers **inside the disposable CI MariaDB database only**. It creates realistic *synthetic metadata*, including a late-input fork and a second question under the same competitor. It exercises the following with the project's real MariaDB 10.6 and 10.11 matrix:

1. Exact run replay is idempotent. The `P01_COMPETITOR`/question scope remains distinct, and unrelated tenant/question reads are refused.
2. Two distinct late-input children of one parent are retained. The current candidate view reports `AMBIGUOUS_HEAD_HOLD`, never timestamp-based last-write-wins.
3. All seven table families contain rows (including a synthetic non-authorizing profile rule). Every family rejects UPDATE and DELETE through SQL triggers.
4. A **second independent PDO connection** attempts a duplicate derivation identity and a cross-tenant lineage foreign key; server-enforced uniqueness and scoped FK constraints refuse both. This is **not** a simultaneous-process race proof.
5. The ordinary active production migrator performs a no-op without installing migration 280. Re-running the seven candidate `CREATE TABLE IF NOT EXISTS` statements preserves populated data. This does **not** claim that replaying the entire SQL file, including triggers, is idempotent as a production migration.
6. A **test-only logical metadata snapshot** of all seven families is exported to PHP memory, the disposable candidate tables are dropped, the same proposal SQL is reapplied, and rows are reinserted in FK/dependency order. Exact rows, append-only trigger count, fork ambiguity and metadata-only replay are compared before/after. The source memory remains immutable and no source bytes are fetched.

**What this is not:** This test is *not* `mariadb-dump`/physical volume backup, point-in-time recovery, a production G21 migration, transactional DDL rollback, test of a populated old G21 version, true concurrent writer race, restoration of external evidence/artifacts, source-rights revocation propagation, or a recovered task queue with automatic work disabled. The proposal's SQL is still only under `docs/longitudinal/proposals/`. All existing 31 active SQL migrations, table inventories, 222-table ledger, source owners, and four reviewed decision JSON files remain unchanged.

## Decision and implementation gates still open

- **U04 — owner decision**, [draft canonical PR #378](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/378): choose the run/output identity vs alternate input-digest output key, ratify a typed `P01_COMPETITOR` scope and question dimension, define current-head ambiguity/reconciliation, time/TZDB and number serialization, and explicit versioned replay of the original v1 scope. Successful candidate tests **are not ratification**.
- **U03 — retained source use:** M05/P01 source cards, quarantines, known-at clocks, byte availability and rights withdrawals must be checked by the actual owners and revalidated for downstream use. `P01HistoryAdapter` cannot manufacture SHA-256/source access from a safe read. A persisted candidate SHA is **not** authentic source evidence.
- **Adoption/migration:** Only after owner signoff, prepare a separately reviewed additive migration in `database/migrations`, reclassify all table families in the exact schema inventory, and prove new/fresh, preexisting populated upgrade, no-op, interrupted DDL recovery and **real** volume/database populated restore, rather than treating this logical roundtrip as equivalent.
- **Concurrency:** The two-handle sequential uniqueness test does not qualify true overlapping transactions, deadlock/duplicate arbitration, idempotent winner-read after conflict, or persistent fork reconciliation. These need reproducible process-level race tests after identity adoption.
- **Execution/release:** U06 is separate for historical model-assisted analysis. No M17 memory, owner digest, role or run candidate may be used as a grant, a P02 state version, an M05 evidence ID, an M10 approval, an M16 publication, or a customer-visible result.

## Claim ceiling

Even if all CI jobs pass, this branch remains **DRAFT / UNMERGED / NOT_DEPLOYED**. The test suite qualifies the reproducibility of an isolated candidate schema, not any new runtime authorization. Railway remains pinned to the last merged and independently qualified `main` commit.
