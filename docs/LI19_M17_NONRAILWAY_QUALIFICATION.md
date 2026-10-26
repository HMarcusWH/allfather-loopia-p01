# LI-19 — M17 candidate assurance without Railway

Prepared 26 October 2026. Stack: PR #87 proposed over unmerged PR #86 LI-07B. Candidate state: DRAFT / TEST_ONLY / NOT_ADOPTED / NO_RAILWAY_EXECUTION / NO_MIGRATION / NO_PROVIDER / NO_STAGE.

## Rationale

Railway's current project limits prevent provisioning the dedicated QA services. The existing staging MariaDB and evidence volume must never be used for this exercise. PR #86 already checks a second sequential PDO connection and a PHP-memory logical metadata export/rebuild; it does not qualify genuinely overlapping writers, a forced database crash or an encrypted dump restored into an independent volume.

This branch adds a separate GitHub-hosted assurance workflow. It does not change the existing CI release aggregator, the Railway runtime, production entrypoints, source ownership or the proposal-only seven-family G21 SQL.

## Actual tests

- True process race matrix: MariaDB 10.6 and 10.11 on independent GitHub-hosted job VMs, PHP 8.2, one real server per lane. Distinct PHP processes and PDO connection IDs are released together. A disposable-only BEFORE INSERT trigger holds one M17 run insert through a named SQL lock; the coordinator requires the other writer to be simultaneously executing SQL before calling this an observed overlap. Cases: identical derivation, incomparable same-parent successors, two questions for one competitor, and distinct tenants. Database cardinalities and immutable lineage are independently read after commits. A loser that reports a constraint failure is not falsely reported as an idempotent success.
- Populated encrypted logical restoration: fresh MariaDB Docker instance/volume, ordinary migrations, seven candidate G21 tables, fourteen append-only triggers, a same-period fork, question/tenant isolation, and a synthetic private evidence-like file. Actual mariadb-dump and age encryption are verified with a checksum. The import target is a different initially empty MariaDB container/volume. The verifier compares each table count and digest, schema, triggers, fork hold, scoped replay and UPDATE refusal.
- Forced storage-engine restart: an InnoDB transaction changes a disposable QA probe without committing. The database container is SIGKILLed and restarted with the original volume; committed data survives and the uncommitted change must not survive. This proves a database/process crash scenario, not a killed M17 writer or a Railway-managed restore.
- Controlled interrupted DDL rehearsal: execute the first three candidate CREATE TABLE statements and verify committed partial state, then resume the unadopted proposal and verify all objects and a no-op. This does not prove transactional DDL rollback or approve a production migration.
- Normal CI boundary tests assert read-only workflow permissions, no privileged pull_request_target, no Railway deploy, disposable target checks and no G21 production migration file.

## Safety

The command entrypoint requires a dedicated private TEST_ENV_FILE, explicit APP_ENV=testing, loopback-only MariaDB, exact name m17_candidate_qa, exact low-privilege user m17_qa, and a supported MariaDB 10.6/10.11 server. The actual database and authenticated account are queried and compared. Initialization additionally requires a completely empty database. A unique persisted M17_QA_RUN_ID marker is checked by subsequent workers, recovery and readback. The runner must be GitHub-hosted or explicitly acknowledged local disposable Docker. The QA scripts use no Railway connection, production source data, provider credentials, source fetch or customer records.

Each workflow lane runs with ephemeral synthetic credentials; no secrets or databases from any connected environment are inherited. Database passwords, decrypted archives, encryption keys, raw SQL dump and worker stderr are not uploaded as artifacts. Failing, interrupted or unavailable prerequisites must stay FAIL/NOT_RUN, never PASS.

## Execution

The dedicated workflow is at .github/workflows/m17-candidate-assurance.yml. It runs on pull requests targeting PR #86's branch. Race and recovery use independent MariaDB 10.6/10.11 matrix lanes.

On WSL/Linux with a functioning Docker daemon, PHP PDO MySQL and age installed, the same recovery path can run with these explicitly set variables: M17_QA_LOCAL_DOCKER=EXPLICIT_DISPOSABLE_DOCKER_ONLY, M17_QA_RUN_ID=m17-local-001, GITHUB_ACTIONS=false, RUNNER_ENVIRONMENT=local. Invoke bash ops/qa/m17/restore.sh --mariadb=10.11 --receipt=/tmp/m17-local-recovery.json.

This authoring container does not have Docker or pdo_mysql; passing syntax validation alone would not qualify MariaDB. Exact CI results need to be read from an actual GitHub Actions run.

## Explicit claim ceiling

A passing finite synthetic suite would not authorize or prove: U04 owner approval; U03 historical source-use rights; authenticated native M05 lineage; U06 model-assisted historical analysis; production migration 280; Railway managed backups, recovery UI or staging behavior; live runtime authorization; customer release; model calls; source crawling; scheduler activation; or universal deadlock/recovery correctness. A candidate SQL schema is not adopted runtime law.

Retain PR #86 draft/unmerged. Review PR #87 independently and keep it draft/unmerged until its exact tree, matrix results and residual findings have been evaluated.
