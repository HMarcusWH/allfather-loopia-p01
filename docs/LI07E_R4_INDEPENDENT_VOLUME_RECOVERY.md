# R4 — encrypted independent-volume candidate recovery (270/280/290 + synthetic M05 bytes)

**Prepared:** 2026-10-27. **State:** `DISPOSABLE_CI_ONLY / SOURCE_RIGHTS_HOLD / U03_NOT_RATIFIED / U05_NOT_ADOPTED / NO_RAILWAY_DB_CHANGE`.

## What changes from R3

R3 qualified an in-process logical rebuild of all 12 proposed longitudinal tables and 24 immutable triggers in disposable MariaDB. R4 reuses the older M17 **real Docker recovery harness** (its existing forced `SIGKILL`, InnoDB uncommitted rollback, `mariadb-dump`, `age` encryption and completely independent Docker volume) and extends its archived data to include:

- **270**: two history-basis/ref tables, four append-only triggers
- **280**: seven M17 profile/run/rollup/memory/lineage tables, 14 append-only triggers, multiple incomparable successor heads and deterministic replay
- **290**: three review-policy/event/due tables, six append-only triggers, three immutable due observations under one versioned **not-adopted** policy
- **M05 synthetic private bytes**: a real `EvidenceSpine` owner-issued `UPLOADED_SOURCE_CAPTURE` and the corresponding content-addressed `EvidenceStore` bytes, packaged inside the same encrypted archive and read back from the independent restored filesystem

The combined backup contains the normal pre-existing P01/M05/P07 application schema as well as the proposed candidate tables; no customer or real external-source content is used.

## Exact execution and failure protections

- `.github/workflows/m17-r4-candidate-restore.yml` is an isolated GitHub-hosted MariaDB **10.6 and 10.11** matrix, using only ephemeral synthetic credentials. `contents: read`; no `pull_request_target`, production secrets, Railway, remote host or deployment.
- The existing `ops/qa/m17/restore.sh` defaults to its unchanged 280-only mode. R4 runs only with explicit `--r4-candidate` and its existing Docker-host trust check (`github-hosted` or explicit disposable-local acknowledgement), loopback QA-only database and per-run DB marker.
- The R4 helper `ops/qa/m17/r4.php` **rechecks** the same QA database marker and exact `m17_candidate_qa`/ `m17_qa` principal. It rehearses an interrupted 270 DDL, idempotent resume, inserts synthetic frozen historical bases and due records, and verifies 12 populated families + 24 DB-enforced append-only triggers. None are added to `database/migrations` or its ledger.
- Before backup, each branch and due policy is replayed, synthetic M05 source content is saved under a private temporary path, and a source-wide, all-table-and-trigger manifest digest is frozen.
- The database is exported with **real `mariadb-dump`** and the private source bytes in the archive. An ephemeral `age` key encrypts everything. **Neither plaintext archive nor key leaves the runner**; only a sanitized small receipt is uploaded.
- The original database container is forcibly killed during a deliberately uncommitted InnoDB transaction. The original volume is restarted and rechecked: the uncommitted update must be rolled back; the complete 12-family metadata manifest, successor ambiguity and M05 original bytes must still verify.
- The encrypted archive is decrypted into a different temporary directory and imported into a **new empty independent Docker volume/database container**. Every original table, original applied-migration ledger and trigger is compared with the pre-backup manifest. Native SQL immutability, cross-tenant denial, exact frozen-basis replay, identical M17 replay and due records must hold. The synthetic M05 evidence's original source ID and content SHA-256 must match its restored private bytes.

## Exact claim ceiling

This demonstrates an **encrypted MariaDB logical dump restored into an independent Docker volume**, coupled with actual application-private filesystem bytes and forced original-container crash recovery. It is **stronger than R3's in-process logical rebuild**, but **NOT** a physical/raw InnoDB datadir image backup and **NOT** an actual Railway volume snapshot/restore.

Only the `R4_DISPOSABLE_VOLUME_RESTORE_QUALIFIED_WITH_NO_AUTHORITY` check may pass in the new workflow. It **cannot grant** any of:

- U03 historical-purpose source retention, analysis or external provider disclosure;
- U05 review-policy adoption, cron/notification/model budget;
- U06 historical analyst/critic inference or M10/customer-facing release;
- an active `database/migrations/270*`, `280*` or `290*` migration;
- Railway/Loopia deployment, operational backup, or production disaster-recovery qualification.

The earlier test-only R3 and real P01/P07 LI-05D/E source-coverage receipts still matter independently. Actual adoption will require explicit source-owner, P01, M02, M10 and Ops decisions; current source-rights revalidation; a reviewed rollout sequence and **a separately approved host-native backup/restore** before staging migration.

## Validation artifacts

GitHub Actions uploads only `r4-recovery-receipt.json` for each MariaDB version and keeps it for 14 days. The manifest, encrypted archive, decryption key, synthetic source bytes and database dump are deleted with the runner. Each successful receipt must explicitly declare `12` candidate table families, `24` immutable triggers, `U03=false`, `U05=false`, `Railway=NOT_TESTED` and successful independent-volume restoration.
