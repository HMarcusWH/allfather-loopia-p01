# Backup and full-restore runbook

P10 adds one operational capability: **take an independently encrypted backup and prove that it can
be reconstructed on an empty disposable target**.

It does not deploy code, activate a Monitor, re-admit a grant, authorize production, or turn a code
rollback into a data restore.

## 1. Preconditions

Before touching runtime data:

1. Use the exact merged release you intend to protect.
2. Use both the runtime env file and MariaDB client option file outside every `public_html/`
   directory, mode `0600` or `0400`. They may contain credentials; they never appear on argv.
3. Keep the age recovery identity in a separate protected store. The export machine needs only the
   public `age1...` recipient.
4. Retain application master-key recovery material separately under the chosen retention policy.
   P10 preserves provider credential ciphertext/key_version exactly but deliberately packages **no**
   `APP_MASTER_KEY` / `APP_MASTER_KEY_V<n>` value. P11 owns version-aware decrypt/rewrap and recovery.
5. Choose an off-host output path. `export.sh` refuses output under a served `public_html/`.
6. The operator entering maintenance must currently hold `tenant.administer` in the named
   workspace. This is the narrow MVP deployment-operator boundary; it is not a future production
   platform-admin model.

Actual dump/import remains an operator act. No HTTP restore endpoint exists.

## 2. Enter quiesced maintenance

```sh
php ops/backup/maintenance.php enter \
  --env=/private/staging.env \
  --tenant-id=<tenant-id> \
  --workspace-id=<workspace-id> \
  --operator-id=<operator-id> \
  --reason=BACKUP
```

Record the returned `maintenance_id`.

The gate uses `flock` on `storage/maintenance.lock`:

- every ordinary web request, HTTP scheduler tick, CLI scheduler tick and ordinary `BetaCli`
  database command holds a shared lease for its process lifetime;
- `enter` takes the exclusive lease first, so it cannot publish the marker until those in-flight
  operations have ended;
- once `storage/maintenance.json` exists, new runtime leases fail closed before authentication,
  session `last_seen`, database composition, source fetch or scheduler dispatch.

The marker contains only identifiers, environment, reason and timestamp. It is not a semantic
release withdrawal. Existing M10 history and execution-grant history remain unchanged.

Legacy direct mutation commands (`migrate`, provisioning and legacy Monitor configure) are also
wired to the same lease. P10 backup/verification commands can read during maintenance by explicit
reviewed bypass; the Monitor stand-down command can write only to narrow/revoke execution authority.

An invalid marker is a fail-closed condition. Repair it manually; do not make the runtime interpret
unknown marker bytes as "maintenance off".

Before dumping an execution-capable database, suspend or revoke every admitted Monitor execution
grant while the maintenance gate is active:

```sh
php ops/monitor/stand-down.php \
  --env=/private/staging.env \
  --tenant-id=<tenant-id> \
  --workspace-id=<workspace-id> \
  --operator-id=<operator-id> \
  --reason=BACKUP_QUIESCENCE \
  --mode=suspend
```

`stand-down.php` is the one authority-narrowing operator mutation allowed through the P10 maintenance
window. It takes its own `AUTHORITY_STAND_DOWN` maintenance operation marker, so it cannot race the
`BACKUP_EXPORT` snapshot operation. It cannot widen a grant. The backup builder independently refuses
`BACKUP_EXECUTION_GRANTS_STILL_ADMITTED` if any grant whose latest lifecycle event is `ADMITTED`
and whose validity window has not ended remains anywhere in the runtime database. That includes
`NOT_YET_VALID` future grants: otherwise a restored database could become executable later without
any new owner act. The exporter then creates a maintenance-scoped `BACKUP_EXPORT` operation marker
before the native dump. `leave` refuses while that marker exists, so the runtime cannot reopen to
writers between the dump snapshot and the manifest's post-dump database reads. A hard-killed export
may strand the operation marker; that is intentionally fail-closed and must be cleared with the exact
operation identity before maintenance can end. This is what makes a restored database execution-inert
by state rather than merely by the fact that the rehearsal did not start a web process.


## 3. Create the encrypted backup

Example private native client file:

```ini
[client]
host=mysql.example.invalid
user=<same-database-principal-as-the-runtime-env>
password=REDACTED
```

Do not commit or print it. P10 deliberately requires the native client and PHP runtime env to
resolve to the same authenticated MariaDB principal; this keeps trigger DEFINER behavior explicit
instead of making a separate backup account silently change the restore contract.

Run:

```sh
bash ops/backup/export.sh \
  --env=/private/staging.env \
  --client-options=/private/mariadb-backup.cnf \
  --database=<runtime-database> \
  --recipient=age1... \
  --out=/off-host/allfather-backup-YYYYMMDD-HHMM.tar.age
```

The exporter:

1. requires an already-QUIESCED maintenance marker;
2. first refuses any non-InnoDB application table, then runs native
   `mariadb-dump --single-transaction --quick --hex-blob --triggers`;
3. binds the dump to the exact host+port+DB **and authenticated database principal** named by
   `--env` and the native client;
4. copies every private content-addressed blob referenced by governed records, including historical
   Monitor journal artifact references **and P05 attempt-bound request artifacts that may exist only
   because a crash occurred before the run cursor advanced**;
5. freezes the current request-log prefix;
6. records the exact maintenance + `BACKUP_EXPORT` operation identities, exact `APP_RELEASE_REF`,
   runtime source fingerprint, a separate digest of the P10 operator tooling, a **validated** current
   console-manifest digest, table/schema digests,
   the **exact migration-ledger rows and trigger definitions** plus their digests,
   **per-table row count+content digests for the complete database**, semantic M10/Monitor summaries,
   a count+digest of read-only retained-output render models, and the source database
   **host+port+database identity** hash;
7. verifies the plaintext package;
8. tars it and encrypts it to the supplied age public recipient;
9. verifies the ciphertext is non-empty and hashes it;
10. closes the exact `BACKUP_EXPORT` operation marker before reporting success;
11. deletes the temporary plaintext working directory.

If encryption completed but the operation marker cannot be closed, the encrypted archive is retained
but the command exits non-zero and runtime remains fail-closed. Do not treat that run as completed
until the operation state is explicitly reconciled.

The manifest never carries `.env`, DB passwords, provider-key plaintext, scheduler tokens, SSH
keys or the application master key.

Keep the encrypted archive off-host and record the printed sha256 **separately** with your trusted
backup inventory. Restore requires that independently recorded digest before it will decrypt the
archive; age encryption alone identifies a recipient, not who produced a backup.

## 4. Leave source maintenance

Only after the encrypted archive exists and has been copied to its independent location:

```sh
php ops/backup/maintenance.php leave \
  --operator-id=<operator-id> \
  --maintenance-id=<exact-id-returned-by-enter>
```

A stale/wrong maintenance ID cannot reopen runtime.

## 5. Restore rehearsal

Prepare a disposable **empty** target database. Its target env must resolve to `APP_ENV=testing` or
`development`; the rehearsal command refuses staging/production configuration. Its host+port+database
identity must differ from the source. Do not point this at the runtime database.

Use a target env whose `DB_NAME` is exactly the `--database` supplied below and whose
`APP_RELEASE_REF` is the exact release named by the backup. Run the rehearsal from that release's
protected tree; the verifier also rechecks its source fingerprint and current Operator Console
manifest. Use a private native client option file for the target:

```sh
bash ops/backup/restore-rehearsal.sh \
  --archive=/off-host/allfather-backup-....tar.age \
  --expected-sha256=<digest-recorded-when-exported> \
  --identity=/private/age-recovery.key \
  --env=/private/restore-target.env \
  --client-options=/private/mariadb-restore.cnf \
  --database=<empty-restore-database> \
  --operator-id=<operator-id-that-entered-the-backup-window>
```

Before import the rehearsal:

- verifies the encrypted archive against the independently recorded sha256 **before decryption**;
- decrypts into a private temporary directory; an explicit `--work-dir` chooses that private
  location but is still deleted on exit;
- rejects unsafe archive paths and link entries;
- verifies the package's exact declared file inventory and every digest;
- proves the rehearsal is running the exact release/source fingerprint/operator-console manifest named by the backup;
- requires the explicit restore operator id to match the operator who entered the backed-up maintenance window;
- proves the target DB identity differs from the source;
- requires the target to run the same MariaDB major.minor line as the source backup;
- proves the target contains zero tables/views/triggers/routines/events;
- if the backed-up schema contains triggers, requires the target's authenticated database principal
  to match the source principal hash. P10 does not parse/rewrite dump definers or silently change
  trigger ownership.

Only then does it import the native SQL.

After import it restores the packaged evidence tree into the disposable rehearsal area and verifies:

- exact table/schema definition digest;
- exact trigger definitions and their digest;
- exact migration-ledger rows and their digest;
- exact application table inventory;
- exact row count/content digest for every restored database table;
- every referenced private artifact;
- the exact frozen request-log prefix;
- every M10 stream with `DecisionLedger::verifyStream()`;
- zero currently admitted Monitor execution grants;
- current-reliance summary;
- every Monitor journal with `RunJournal::replay()`;
- **retained-output renderability** without invoking M02 or writing new audit rows: M16 artifacts and
  projections are JSON-decoded, their artifact/projection digests are re-derived, current visibility
  and M10 reliance are re-read, P04 released role/routing outputs are materialized, and the resulting
  safe read models are JSON-encoded and digested;
- populated semantic counts/digests for retained M10/Monitor/output history.

The rehearsal **does not start a web runtime or scheduler**. Restored data may contain historical
grant records; history is not activation. A later runtime requires its separately reviewed
re-admission/activation path.

## 5.1 P11 credential-key recovery check

P10 proves the database/artifact snapshot. P11 separately proves that encrypted credential rows in an
older retained backup remain decryptable by the appropriate retained master-key version.

Under one exact P11 release:

1. keep a versioned keyring with v1 active and create a populated P10 backup;
2. change the configured active write version to v2 and use the reviewed P11 rewrap procedure on the
   live database;
3. restore the **pre-rewrap** backup to the ordinary empty disposable P10 target;
4. using the restored row's exact `key_version=1`, prove the credential opens with retained v1
   recovery material;
5. prove it refuses when only v2 is available.

Removing v1 from the live runtime ring after all live rows are on v2 is **not** permission to destroy
v1 recovery material while any retained backup still contains v1 ciphertext.

Neither P10 nor P11 stores a recovery key in the archive, manifest, repository or deployment evidence.

## 6. Failure handling

Any of these is a failed restore rehearsal:

- target identity equals source;
- target is not empty;
- archive carries an undeclared file/symlink/unsafe path;
- dump, request-log prefix or artifact digest mismatch;
- schema, trigger or migration fingerprint mismatch;
- any source/restored application table is non-InnoDB, which would make `--single-transaction` an invalid consistency claim;
- M10 chain failure;
- Monitor event-chain failure;
- semantic state mismatch.

Do not "repair" a failed rehearsal by deleting historical rows or editing an old migration. A
failed native import leaves the disposable target **tainted**: drop/recreate that target before the
next rehearsal rather than importing over the partial state. Diagnose the mismatch, preserve the
failed evidence, and roll forward.

## 7. Code rollback is not restore

A rollback changes application code. A restore reconstructs the database and retained private
artifacts.

No P10 command:

- runs a down-migration;
- merges an old dump into the live append-only database;
- deletes governed history;
- changes `deployment-evidence.json` or `upstream.lock.json`;
- claims production/customer GO.

Running old code against a newer schema requires a separate explicit compatibility proof. Without
one, stand down and roll forward to a repaired release.

## 8. Host qualification still required

The implementation is not proof that Loopia grants the necessary native dump/import behavior,
trigger/DEFINER handling, filesystem locking or external tool availability.

Before P06, exercise P10 on the actual intended operator/host path and retain:

- native client/server versions and the source/target MariaDB major.minor line;
- dump/import exit status;
- trigger behavior after restore and source/target principal identity for trigger ownership;
- package/restore verifier output;
- off-host encrypted archive digest;
- evidence that the disposable target was different and initially empty.

Those observations do not grant authority. They establish whether backup and restore actually work
on the selected environment.

After a real drill exists, a separate reviewed evidence filing may point to its sanitized verifier
output with kind `FULL_RESTORE_DRILL`. The record itself grants no execution or production authority,
and a production-readiness check counts it only when it names the exact production host/release.
