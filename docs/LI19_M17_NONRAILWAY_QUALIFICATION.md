# LI-19 — M17 candidate assurance without Railway

Prepared 26 October 2026. Stack: PR #87 proposed over unmerged PR #86 LI-07B. Candidate state: DRAFT / TEST_ONLY / NOT_ADOPTED / NO_RAILWAY_EXECUTION / NO_MIGRATION / NO_PROVIDER / NO_STAGE.

## Rationale

Railway's current project limits prevent provisioning the dedicated QA services. The existing staging MariaDB and evidence volume must never be used for this exercise. PR #86 already checks a second sequential PDO connection and a PHP-memory logical metadata export/rebuild; it does not qualify genuinely overlapping writers, a forced database crash or an encrypted dump restored into an independent volume.

This branch adds a separate GitHub-hosted assurance workflow. It does not change the existing CI release aggregator, the Railway runtime, production entrypoints, source ownership or the proposal-only seven-family G21 SQL.

## Actual tests

- True process race matrix: MariaDB 10.6 and 10.11 on independent GitHub-hosted job VMs, PHP 8.2, one real server per lane. Distinct PHP processes and PDO connection IDs are released together. Disposable-only BEFORE INSERT triggers on the profile and run stages hold an in-flight M17 insert through a named SQL lock; the coordinator observes the *other* writer simultaneously executing prepared SQL before reporting overlap. Cases: identical derivation, incomparable same-parent successors, two questions for one competitor, and distinct tenants. The original losing worker retains its exact safe SQLSTATE/driver reason; only known duplicate-key or InnoDB deadlock/lock-wait codes are eligible for an **explicit, separately recorded test continuation** after transactions have ended. An original failed write never becomes an idempotent success. Recovered forks must retain their original predecessor and report `AMBIGUOUS_HEAD_HOLD`.
- Populated encrypted logical restoration: fresh MariaDB Docker instance/volume, ordinary migrations, seven candidate G21 tables, fourteen append-only triggers, a same-period fork, question/tenant isolation, and a synthetic private evidence-like file. Actual mariadb-dump and age encryption are verified with a checksum. The import target is a different initially empty MariaDB container/volume. The verifier compares each table count and digest, schema, triggers, fork hold, scoped replay and UPDATE refusal.
- Forced storage-engine restart: an InnoDB transaction changes a disposable QA probe without committing. The database container is SIGKILLed and restarted with the original volume; committed data survives and the uncommitted change must not survive. This establishes synthetic InnoDB/container restart behavior, not Railway-managed recovery.
- Actual M17 writer interruption: a disposable-only SQL AFTER INSERT trigger pauses an M17 writer **after inserting its profile/run/rollup, but before transaction commit or memory/status/lineage completion**. The coordinator observes that same database connection executing the trigger, SIGKILLs its PHP CLI process, and verifies exact graph/table digests are unchanged after rollback. Only then a separate, explicitly initiated test continuation can populate the complete candidate lineage. Original interrupted work is neither claimed successful nor automatically retried.
- Controlled interrupted DDL rehearsal: execute the first three candidate CREATE TABLE statements and verify committed partial state, then resume the unadopted proposal and verify all objects and a no-op. This does not prove transactional DDL rollback or approve a production migration.
- Normal CI boundary tests assert read-only workflow permissions, no privileged pull_request_target, no Railway deploy, disposable target checks and no G21 production migration file.

## Safety

The command entrypoint requires a dedicated private TEST_ENV_FILE, explicit APP_ENV=testing, loopback-only MariaDB, exact name m17_candidate_qa, exact low-privilege user m17_qa, and a supported MariaDB 10.6/10.11 server. The actual database and authenticated account are queried and compared. Initialization additionally requires a completely empty database. A unique persisted M17_QA_RUN_ID marker is checked by subsequent workers, recovery and readback. The runner must be GitHub-hosted or explicitly acknowledged local disposable Docker. The QA scripts use no Railway connection, production source data, provider credentials, source fetch or customer records.

Each workflow lane runs with ephemeral synthetic credentials; no secrets or databases from any connected environment are inherited. Database passwords, decrypted archives, encryption keys, raw SQL dump and worker stderr are not uploaded as artifacts. Failing, interrupted or unavailable prerequisites must stay FAIL/NOT_RUN, never PASS.

## Execution

The dedicated workflow is at .github/workflows/m17-candidate-assurance.yml. It runs on pull requests targeting PR #86's branch. Each race lane must pass both `ops/qa/m17/race.php` and `ops/qa/m17/interrupt.php`; the independent MariaDB 10.6/10.11 recovery lanes use `ops/qa/m17/restore.sh`. Receipts are uploaded separately from the normal release/code qualification receipts. Previous passing runs do not qualify an amended head; consult the exact head under review.

On WSL/Linux with a functioning Docker daemon, PHP PDO MySQL and age installed, the same recovery path can run with these explicitly set variables: M17_QA_LOCAL_DOCKER=EXPLICIT_DISPOSABLE_DOCKER_ONLY, M17_QA_RUN_ID=m17-local-001, GITHUB_ACTIONS=false, RUNNER_ENVIRONMENT=local. Invoke bash ops/qa/m17/restore.sh --mariadb=10.11 --receipt=/tmp/m17-local-recovery.json.

This authoring container does not have Docker or pdo_mysql; passing syntax validation alone would not qualify MariaDB. Exact CI results need to be read from an actual GitHub Actions run.

## Explicit claim ceiling

A passing finite synthetic suite would not authorize or prove: U04 owner approval; U03 historical source-use rights; authenticated native M05 lineage; U06 model-assisted historical analysis; production migration 280; Railway managed backups, recovery UI or staging behavior; live runtime authorization; customer release; model calls; source crawling; scheduler activation; or universal deadlock/recovery correctness. A candidate SQL schema is not adopted runtime law.

Retain PR #86 draft/unmerged. Review PR #87 independently and keep it draft/unmerged until its exact tree, matrix results and residual findings have been evaluated.
