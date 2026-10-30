# VA-03 — Full-history recovery and credential-operability runbook (source candidate)

**Prepared 30 October 2026. Status: NO_RESTORE / NOT_HOST_VERIFIED / NO_OPERATOR_CUTOVER.**

This is a plan and verifier for the future complete historical intelligence data graph. It does **not** perform backup, restore, host credential change, model execution, source capture, M10 release or customer publication. P10 v1 BackupManifest and existing encrypted export/restore remain the owners. P11 KeyRing owns versioned decryption/rewrap; R5 Railway inventory is only a read-only provider snapshot listing.

## Exact source manifest and database families

FullRestoreManifest.vNext-candidate binds selected release commit/tree and image digest, original point-in-time knowledge cutoff, P10 v1 manifest digest and SQL dump digest, request-log prefix digest, exact P01 question scope, HS-01 basis, IV-01 dependency graph, AN-02 reviewer journal, AN-01 attempt-before-cursor identity, M17 lineage, source-rights unknown and P11 active key version (never secret bytes).

The **twelve** uninstalled candidate schema families are 260, 265, 270, 280, 290, 300, 310, 320, 330, 340, 345 and 350. Each versioned source SQL file has exact repository-relative path, SHA-256, byte count and installed=false. Current source CI can verify the hashes but does not create tables. In RT-01, approved migration order and source compatibility must be settled before a future backup can claim the new tables existed.

Private references are content-addressed **only by SHA-256**: M05 capture bytes, historical analyst artifact, attempt-before-cursor event and request-log prefix, each with length, type and no embedded source bytes. Any missing, changed or incorrectly permissioned artifact is a hard refusal. The positive byte fixture is synthetic and lives in a separate mode-0700 directory with mode-0600 files. Real private M05 source bytes, permissions/retention, encryption and authorized purpose must be revalidated by genuine owners; this source harness never reads production storage.

The metadata index names public hashes and fixed codes, **never** database passwords, API keys, session cookies, user profiles, source excerpts, raw capture URLs, model prompts, P11 keys or age recovery identities.

## Recovery categories that must remain separate

1. **Existing live-source recovery (R4/R5/P10).** Use actual P10 package for the existing deployed schema and independently encrypted private bytes. R5 volume-listing metadata is neither a valid backup nor a verified consistent app/database pair. Staging restore still requires a separately approved operator, maintenance and independent empty target.
2. **Isolated full-candidate-schema recovery.** After RT-01 qualifies schema families 260–350 and their ordered migrations in a disposable environment, perform an independent-volume restore with all candidate tables, triggers, journal artifacts, exact M17 lineage, and contrary/fork replay. This is not a live schema deployment.
3. **Post-migration populated-live recovery.** Only after separately admitted real schema migration and actual data population can a new backup prove restoration of the migrated data graph. An R5/P10 backup taken before new migration cannot prove this phase.

Keep logical dump/import, physical volume restoration, app private volume and M05 bytes independent. A green CI suite does not establish any host backup, supported backup encryption identity, stable restore time or a populated restore.

## Maintenance, writers and stand-down

Before an approved future export or restore, the real P10 MaintenanceGate must quiesce ordinary runtime requests, scheduler ticks, source capture, historical review, provider attempts, queue/attention generation and every proposed new writer. Confirm exact maintenance marker, operation token, absence of admitted grants, all writer leases drained and current owner permissions. A caller-supplied boolean or fixed synthetic fixture is not an actual lock proof.

The VA-03 read-only preflight distinguishes QUIESCED_FIXTURE from NOT_QUIESCED. Even an entirely positive fixture produces **NO_RESTORE_NOT_HOST_VERIFIED** and records that actual writer stand-down is not proven. Do not call Railway Restore, change volumes, create a live migration or relax maintenance gates from a source-only run.

## Credential cutovers and portability

- **Age backup:** keep recovery identity separate from public recipient and separately verify decryption of a retained package; source manifest contains only recipient fingerprint. Do not store identity in Git, run logs or environment export.
- **P11:** version-aware decrypt/rewrap across all retained ciphertexts requires a separate approved migration and tested recovery. Recording target `APP_MASTER_KEY_V2` does not prove rewrap.
- **Initialized MariaDB SQL account:** a changed deployment `DB_PASSWORD` environment value does not modify an already-created MariaDB user; separately qualify and execute the SQL-user password rotation with rollback.
- **Backup-access rotation:** separate authorized action, distinct from both P11 and MariaDB.
- **Filesystem:** mode 0700/0600 with POSIX owner protection is required for disposable private fixture verification. On Windows, POSIX chmod is not an ACL guarantee; the current verifier refuses that platform rather than fabricating protected storage.

The `HistoryCredentialCutover.v1` dry-run lists four independent operations, all unexecuted and unverified, with no credential values.

## Safe commands (do not use against real volumes)

`php ops/history/verify-integrity.php --status` prints a NO_RESTORE posture without touching DB/storage.

`ALLFATHER_VA03_SYNTHETIC_TEST=1 php ops/history/verify-integrity.php --synthetic-development` hashes only local proposed SQL source and composes a deterministic synthetic source/preflight receipt. It does not test a real P10 package or read real M05 private bytes.

Disposable CI tests check all twelve proposal files, source cutoff, four synthetic private blob hashes, missing/changed bytes, P11/SQL/backup cutover separation, no secrets, no owner grants, and no M10/M11/schema mutations. Hosted CI and exact Git merge tree must pass before the package is counted built.

**Deferred:** actual R5 provider backups and schedules, paired consistency, P10 encrypted export, independent logical/physical restore, source-private retention proof, P11 rewrap and SQL/backups cutovers, real writer maintenance proof, RT-01/RT-02 full integration, V01–V11 operator/host qualification and any production GO.
