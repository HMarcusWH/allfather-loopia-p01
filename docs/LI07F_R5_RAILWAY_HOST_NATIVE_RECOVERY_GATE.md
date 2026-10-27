# LI-07F / R5 — Railway host-native recovery qualification gate

**Prepared:** 27 October 2026  
**Status:** `REVIEW_ONLY / HOST_INVENTORIED / BACKUP_SNAPSHOT_UNKNOWN / RESTORE_NOT_RUN / U03_PENDING / NO_LIVE_MIGRATION`  
**Source basis:** PHP `main@de7330d8d617bfbd9d27c04fc5f62c22f3e19382`; [R4 independent Docker volume qualification](LI07E_R4_INDEPENDENT_VOLUME_RECOVERY.md); [P10 backup and restore runbook](BACKUP_RESTORE_RUNBOOK.md); [R3 U03 review](LI05F_LI07D_R3_U03_REVIEW_AND_POPULATED_RESTORE.md); [canonical U03 draft ratification PR #382](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/382).

## 1. Verified platform inventory — **not** a backup or restore receipt

A read-only Railway project/environment/service inventory on 27 October 2026 confirmed:

| Resource | Observed deployment role | Status |
| --- | --- | --- |
| `allfather-staging` / `staging` | Railway environment; **not Loopia** | Exists; no staged patch |
| `allfather-php-mvp` | PHP app from this repository `main` | SUCCESS, deployment of exact `de7330d8...` |
| `allfather-staging-app-storage` | App private volume mounted at `/srv/allfather/storage` | Mounted, provisioned 256 MB |
| `allfather-mariadb` | `mariadb:10.11` | Service SUCCESS |
| `allfather-staging-mariadb-data` | MariaDB volume at `/var/lib/mysql` | Mounted, provisioned 480 MB |
| `allfather-staging-encrypted-backups` | Railway bucket | Bucket exists; **contents, encryption, backups and recoverability unverified** |

The sizes are **provisioned capacity, not measured used bytes**. Exact resource UUIDs, private backup IDs, object keys, source contents and credentials belong only in protected operator evidence, not in this public Git repository.

**Do not interpret** Railway's deployment `snapshotId` metadata as proof of a backup for either mounted data volume. The connector's current inventory and deployment tools do not enumerate actual volume-backup objects or carry out a backup/restore operation.

### Railway provider constraints verified from official documentation

Railway [volume backup documentation](https://docs.railway.com/volumes/backups) states that mounted volumes can have scheduled and manual snapshots. A normal Restore operation stages a **replacement volume** at the original mount path, retains the former volume but unmounts it, and redeploys the affected service when the staged patch is deployed. The documented backup is restorable **only within the same Railway project and environment**. Manual backups also have a capacity/usage limit documented by Railway; inspect actual used capacity and eligibility before relying on the backup control.

This means pressing Restore on the **currently running** app/DB is an operational change, not a non-disruptive read-only inspection. Do not do it during this review. Railway's ordinary restore UI is not documented here as a restore-to-arbitrary-service or cross-environment clone operation. Never infer that capability from the existence of an unattached volume.

## 2. Scope of the missing proof

R4 already proves **Docker-hosted logical** encrypted dump, independent new Docker database volume, database SIGKILL recovery, all **12 proposed** 270/280/290 table families and **24 triggers**, and retained **synthetic M05 private bytes**. R4 does not prove Railway-native raw volume snapshots or restore.

R5 must provide separate proof of **both physical Railway-hosted state stores**:

- The current **database volume**, with original application schema, exact migration ledger, trigger definitions, row digests and governed replay state.
- The current **application storage volume**, with every protected original private content-addressed artifact referenced by the selected database snapshot.

A Railway backup of one volume is not a complete application backup. Two snapshots taken at different times do not automatically form a transactionally consistent pair. The existing P10 maintenance/export mechanism helps bound writes, but its scope, other possible database writers and Railway's snapshot consistency must be independently verified. Provider-created crash-consistent snapshots are **not** assumed application-consistent; a successful independent restore and parity check are required.

**Separate candidate obligation:** Since 270/280/290 are **not applied** to the current staging migration ledger, a restore of today's two Railway volumes cannot qualify those candidate tables on Railway. Candidate schema/data must be separately installed and restored **only in an isolated authorized disposable target**, or in a later explicitly approved staging migration/recovery sequence. Never claim `12/24` from the current real staging database unless measured there.

## 3. Proposed execution phases and stop conditions

### R5-0 — Freeze read-only source and host facts (safe now)

1. Recheck exact source commit, CI and Railway deployed commit equality; record service images, mounted-volume identities, database major/minor, active application release and lack of pending Railway config changes.
2. In the Railway dashboard, inspect **Backups** for both mounted volumes: actual scheduled policy, snapshot IDs/times, completion status, retention and restore eligibility. Capture a **sanitized** receipt separately. An empty/unavailable list is `NOT_PROVEN`, not `PASS`.
3. Inspect actual used volume bytes and Railway's manual-backup eligibility. Inspect any claimed off-host bucket inventory using authorized tooling without publishing keys, objects or sensitive paths. Bucket existence alone proves nothing.
4. Confirm exact P10/P11 recovery tooling and the age recipient/recovery-key **availability by attestation only**, never by printing key material. Record which permissioned operator can conduct the drill.

**STOP:** No snapshot ID, only one recoverable store, ineligible volume, absent recovery identity, source/host mismatch or unknown backup semantics.

### R5-1 — Choose a physically safe rehearsal route (**owner decision required**)

Preferred: prove an **isolated recovery target that cannot alter the running app/database**, within Railway's documented same-project+environment constraint. Establish from a provider-supported operation that a given snapshot can be restored there; **do not assume the dashboard can reassign it**. A disposable Railway service/volume rehearsal using only synthetic material can qualify provider snapshot mechanics (`R5A_PROVIDER_MECHANICS_ONLY`), but **not** recovery of the two actual staging volumes.

If the supported Railway restore mechanism necessarily swaps a volume on the original service, defer the actual-source exercise until the Ops owner approves a maintenance window, risk/rollback plan, source/target service changes and expected redeploy impact. Such an intrusive test **must never be executed** under a general “proceed” instruction or by this documentation PR.

**STOP:** No isolated target proven and no specific Ops change-window approval. Do not use `accept-deploy`, restart, change service mounts, alter credentials or restore the live DB as a shortcut.

### R5-2 — Freeze a cross-volume-consistent restore point

Only after R5-1 is authorized:

1. Prove the runtime's P10 maintenance and stand-down prerequisites on the exact running release; identify and stop/deny all other scheduled, web and direct DB writes relevant to the pair. Confirm zero admitted grants, or record `HOLD`.
2. Obtain source-backed, **actual** Railway-managed backup IDs for both volumes while the shared evidence/database state is quiesced, with consistent cutoff and documented DB backup semantics. Take any separate P10 encrypted off-host archive with its independently recorded digest if authorized; it is complementary evidence, not a substitute for Railway snapshots.
3. Record safe-to-publish metadata only: backup source service, volume role, creation/completion times, original migration digest, source release SHA, sanitized source/target identity commitments, and the referenced private artifact manifest digest. Keep sensitive manifest/object details in protected records.
4. Restore service operation only under its reviewed protocol; release maintenance using the original exact maintenance identifier when safe.

**STOP:** In-flight writes, inconsistent timestamps/cutoffs, a failed Railway backup, unsupported hot-InnoDB snapshot semantics, lost evidence, or inability to establish source-to-blob snapshot coherence.

### R5-3 — Restore and verify independent physical state

Run on the separately approved **isolated** or owner-authorized maintenance-window target. Before connecting:

- Verify restore target is correctly isolated from customers and normal staging, has an empty/known expected recovery state, and has a different physical volume identity; never overwrite unreviewed data.
- Prove Railway actually constructed the restored volume from each **named** provider snapshot. Keep the Railway operation and deployed-service evidence; a logical SQL import into a newly created volume alone is **R4-class**, not R5 physical proof.
- Start database using a verified compatible MariaDB release under restricted network and without normal scheduler/HTTP/model execution. Verify automatic InnoDB crash recovery and engine health before readback.
- Restore/attach the matching app-private bytes under that same cutoff, read back **actual** M05 referenced content-addressed bytes and confirm each source identity and digest against the immutable database bindings. Do not log source bodies.
- Compare exact existing migration ledger, all DB objects/triggers, table counts/content digests, M10 current-reliance/integrity, Monitor journal replay, P01 source/provenance links and private artifact availability with the frozen source receipt. Execute selected database-enforced append-only negative checks **only on disposable restored data**, not source.
- Verify execution remains inert: no re-admitted grant, model call, source fetch, automatic scheduling, outward publishing or customer-visible release; no secret is copied to the evidence packet.
- Record source host, recovery target, snapshot identifiers, elapsed downtime (if any), and recovery/rollback observations. Report source/target data integrity, **not** merely HTTP 200.

**STOP:** Any missing original byte, mutated row, bad trigger/DEFINER, wrong cutoff, incomplete replay, new execution authority or unclear source/target identity. Do not repair a damaged original by replaying candidate DDL.

### R5-4 — Independent disposition

Use `docs/longitudinal/LI07F_R5_RAILWAY_HOST_RECOVERY_READINESS.json` only as an **untrusted status/requirements ledger**. File the actual independently reviewable private Railway backup, deployment, source/target, restoration and integrity receipts in the existing evidence process; do not set a bare JSON flag to `PASS` as a substitute.

Classification:

| Disposition | Required demonstrated fact |
| --- | --- |
| `R5_NOT_RUN` | Current state. No provider backup/restore evidence filed. |
| `R5_BACKUP_INVENTORY_ONLY` | Real provider backup objects and completion verified; **no restoration**. |
| `R5A_PROVIDER_MECHANICS_ONLY` | Actual Railway native backup restored on **synthetic isolated** volumes, not live source data. |
| `R5_PARTIAL_DB_ONLY` or `R5_PARTIAL_APP_ONLY` | One restored real volume; no full source-byte/database pair guarantee. |
| `R5_RAILWAY_PAIRED_HOST_RESTORE_VERIFIED` | Both **real** coherent staging snapshots restored physically through Railway and independently checked, with full original byte/database/replay parity and no authority expansion. |
| `R5_FAIL` / `R5_HOLD` | Any mismatch, absent gate, missing permission or inability to recover safely. |

Even a fully evidenced R5 is **Ops recovery evidence only**. It is not U03 sign-off, historical-use source rights, U05/U06, live 270/280/290 migration, production GO or customer visibility.

## 4. U03 ratification remains a separate gate

Canonical [draft U03 PR #382](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/382) requires six independent owner decisions: **M05 source**, **P01 competitor**, **M02 access**, **M10 reliance**, **Ops/recovery**, and **canonical U03 policy**. No owner decisions were submitted as part of R5 preparation. Three uses remain independently false: internal deterministic historical review; internal model-assisted historical analysis; and external provider source disclosure.

The first viable policy choice can be a narrowly scoped **internal deterministic** purpose for specifically licensed references; it must still be explicitly signed/attested by the proper owners and bound to current source cards/known-at/rights. A founder's general go-ahead, GitHub green CI, an empty backup bucket, a synthetic R4 replay or a document merge does **not** grant that choice.

**Next operations action:** complete R5-0 backup inventory and select R5-1 safe restore method with an explicit Ops decision. Keep migrations 270/280/290 absent from active `database/migrations` and keep the source/migration/customer authority holds intact.
