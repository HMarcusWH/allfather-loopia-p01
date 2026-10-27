# R3 — U03 owner-review packet and joined 270/280/290 populated-recovery rehearsal

**Date:** 2026-10-27

**Status:** `REVIEW_ONLY / SYNTHETIC / U03_NOT_ADOPTED / U05_NOT_RATIFIED / U06_NOT_AUTHORIZED / NO_LIVE_MIGRATION`.

This package advances the longitudinal-intelligence program after LI-05E owner-native schedule coverage was qualified. It does not implement an admitted historical-use action or operational migration.

## 1. U03 evidence-review handoff, not authorization

`app/Workflow/HistoricalUseReviewPacket.php` produces a stable, versioned **review-readiness packet** only from a previously produced LI-05D owner-source preflight and optional LI-05E exact schedule receipt. It binds immutable basis digest, source-proof digest, the typed P01 scope, four-clock historical cutoff, precise planned schedule provenance when available, selected evidence counts, and six **unapproved, individually required** owner decisions:

| Owner | Evidence/action the owner must actually authorize |
| --- | --- |
| M05/source | Current historical-purpose use, retention, current source-card/terms policy, source-byte availability and redaction |
| P01 | Original capture identity, one-source or multi-source scope, schedule completeness and immutable known-at lineage |
| M02 | Current actor, tenant, workspace and task-specific permission |
| M10 | Current relied-upon state, dependency revocation and prospective invalidation handling |
| Ops | Actual populated upgrade/recovery, current source bytes and deploy/rollback authority |
| Canonical U03 | Adopt a versioned purpose contract, identified owner decision, exact vectors/commit and bounded consumer permission |

The packet reports `READY_FOR_U03_OWNER_REVIEW_NOT_ADMITTED` only if its supplied native-preview metadata has a complete owner-schedule receipt, intact digests and non-empty selected references. This is **not trustworthy authorization by itself**: the caller might fabricate a preview, and the code cannot independently verify the signing owner or source rights. A future operational consumer MUST invoke owner-native M02/P01/M05/current policy checks afresh and obtain a separately ratified U03 purpose decision. `HistoricalUseReviewPacket::admit()` always refuses. No field in the packet is an access token.

Unknown source coverage, missing exact schedule receipts, inconsistent flags, tampered digests, and insufficient proof counts fail closed; purpose/disclosure/model/persistence/scheduler/customer output fields remain `false`.

## 2. R3 full-family populated logical recovery

`tests/integration/m17_r3_candidate_populated_recovery.php` runs on **isolated CI MariaDB 10.6 and 10.11**:

1. Start from the existing **populated P01/P07 application schema**, retaining all previously applied migration digests.
2. Execute only the first DDL statement of candidate **270** to simulate an interrupted, nontransactional MariaDB migration. Verify the partially created first table exists and the reference table does not. Rerun the full candidate DDL **270 (2 tables + 4 triggers), 280 (7 tables + 14 triggers), and 290 (3 tables + 6 triggers)** to repair and finish.
3. Insert synthetic tenant-scoped metadata from the existing exact LI-05A basis vectors through test-only `HistoryReviewBasisStore`, `MemoryStore` and `HistoryReviewDueStore`. Exercise one immutable original, two competing late-input successors, and three review-due candidates. Populate every one of the **12 table families**.
4. Re-run additive `CREATE TABLE IF NOT EXISTS` statements with populated data. Verify **all rows and digests unchanged**; verify **24 database-enforced append-only UPDATE/DELETE triggers**. With a second real database connection, verify duplicate due-clock and foreign-tenant FK attempts are rejected.
5. Perform a **disposable logical export/rebuild** of just these 12 candidate families. Restore in FK/topological order, including same-period memory predecessor edges, with no actual customer data and no dependence on a provider. Assert byte-for-byte row equality, all 24 triggers, exact-input replay, foreign-tenant denial, and `AMBIGUOUS_HEAD_HOLD` after recovery.
6. Compare the original `schema_migrations` ledger before and after. No candidate 270/280/290 entry is written to the live migration ledger.

**Bounded evidence claim:** This is a logical, metadata-only rebuild inside disposable GitHub-hosted MariaDB, **not** a native MariaDB volume/snapshot backup, Railway recovery, production migration or protected real M05 source restoration. It can qualify DDL/data invariants, FK order, replay and interruption handling; it cannot prove platform-volume restore or source licensing.

## 3. Production activation gates still outstanding

- **Canonical U03 adoption** requires actual owner sign-offs, current rights, purpose/disclosure limits and a versioned reviewed commit/vector digest. Merely having this report or passing CI is insufficient.
- **U05** operational review policy and **U06** historical analyst/critic transport must receive independent grants; do not confuse due with permission.
- The candidate SQL stays in `docs/longitudinal/proposals/`; it does **not** enter `database/migrations/`. A release migration must be separately reviewed for fresh and populated schema, interrupted DDL, no-op, transaction boundaries, source rights, disaster recovery and an explicit **staging GO**.
- Nothing in this PR changes Railway service variables, grants, volumes, migrations, cron, HTTP routes or model invocation.

## 4. CI instructions

The ordinary strict root CI checks PHP syntax, foundation and package integrity, and runs the full suite against MariaDB 10.6/10.11. The new unit test `li05f_u03_owner_review_packet`, foundation `m17_r3_u03_no_promotion_boundary` and integration `m17_r3_candidate_populated_recovery` must all pass on the exact PR head.

Keep this PR draft until all five checks pass. Owner ratification and staging authority remain separate even after this PR is merged.
