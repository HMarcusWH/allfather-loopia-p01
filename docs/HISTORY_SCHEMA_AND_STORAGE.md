# RT-01 — Full candidate schema and recovery

Prepared 30 October 2026. **BUILD-INERT SOURCE CANDIDATE; NOT A LIVE MIGRATION.** This document is subordinate to the frozen 27 October v2 build manifest and actual source SQL. No U01–U10 owner ratification, P10 host restore, M10 release, main promotion or customer visibility follows from CI.

## Physical reality versus initial registry allocations

The provisional SchemaFamilyRegistry defined early candidate table allocations. Successive built packages created **12 existing SQL files with 38 physical tables and 76 append-only UPDATE/DELETE triggers**. Several names changed as package-local implementation evolved. In particular, 260 adds an append-only backfill cursor ledger; 300, 310, 330, 340 and 350 diverge from the original provisional table names. RT-01 preserves the original registry as historical design evidence and publishes a separate versioned physical-schema install manifest with all differences. Do not silently treat the registry's old table lists as deployed truth.

Every physical family is source-hashed, delimiter-aware, and its CREATE TABLE/CREATE TRIGGER/REFERENCES clauses are examined to determine actual FK edges. The install plan refuses unknown parents, duplicate tables/triggers, destructive source DDL, non-matching statement counts and cycles. The expected 38 tables/76 triggers are an independent completeness floor; every new source change must produce a fresh immutable plan and qualification.

## Candidate source files and FK topological order

The twelve candidate files, in docs/longitudinal/proposals, are families 260, 265, 270, 280, 290, 300, 310, 320, 330, 340, 345 and 350. Physical source dependencies include **260 before 265**, **270 before 290** and **330 before 345**. Other candidate FKs resolve within their same family or against existing native owners (tenants, workspaces, competitors, monitored sources, snapshots, evidence, source diffs and source references). Do not install by naive filename or table-name order without this graph.

The original immutable migration 900_deferred_foreign_keys retains its exact Git blob SHA-1 and database ledger digest. **Never edit, rerun, or expect 900 to apply foreign keys for newly proposed tables.** A fresh base migration and an upgraded existing database must instead be reconciled against the exact new candidate manifest and its own later approved native migration IDs.

## Non-transactional MariaDB DDL and interrupted state

MariaDB DDL commits independently. RT-01's fixture installer uses a **named test marker, PHP CLI and a test-named database** and requires the native 900 ledger record. It runs only on a disposable database with *zero* candidate objects, in the derived FK topological order. It never writes schema_migrations and never activates source/model/customer permissions.

An interruption after one CREATE TABLE or one missing UPDATE/DELETE trigger is an explicit HOLD_PARTIAL_NONTRANSACTIONAL_DDL; the installer refuses an automatic retry and does not pretend rollback succeeded. Recovery means inspecting object identity, source digests and triggers, then manual cleanup/reconstruction **on the disposable QA database only**. For a later real migration, a separate reviewed owner change window and recovery plan are required.

## Runtime composition

Existing HistoryReviewFactory still rejects production inference and human release. RT-01 adds a source-level typed composition that verifies the exact schema plan and linked AN-03 finding, AN-04 outcome and AQ-02 attention, and confirms fourteen typed module boundaries. That fixture requires CLI test admission and returns **COMPLETE_SYNTHETIC_SOURCE_GRAPH_NO_RUNTIME_PERMISSION**, not a running scheduled service or human approval.

RuntimeInvariantProbe's existing database test is untouched. A separate source-only schema-status reader reports expected candidate physical objects while declaring actual runtime installation, U01–U10 rights, provider/source execution and customer visibility false. This preserves an uneventful normal startup with no candidate tables installed.

## Operator command and boundaries

    php ops/history/migration-plan.php --status

This prints safe source file hashes, family order, physical table/trigger counts, registry differences and no-live-grants. It opens no database, requires no env, has no install flag and refuses web SAPI before bootstrap. The positive database installer is exercised only from the disposable MariaDB test harness, never this CLI.

No candidate DDL is copied into database/migrations or automatically applied by startup, Railway deploy or the source merge. RT-02 must adopt all exact source paths and runnable fixture contracts; V01–V11 must separately establish migration interruption recovery, security, source science/model quality, host recovery, staging and owner permission. An acknowledged candidate package is not an approved migration or production-ready system.
