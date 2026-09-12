# Rollback and stand-down

**Rollback is never a delete.** Application rollback restores code; governed withdrawal appends a
new fact that changes current reliance. Neither erases history.

## 1. Rules that apply to every restore

- no down-migrations;
- never point `tests/run.php` at the runtime database;
- preserve host `.env` unless the release reference itself must change;
- preserve all runtime `storage/` contents;
- publish Operator Console hashes before replacing the manifest;
- retain old safe content hashes during the release transaction;
- verify the restored tree by source fingerprint, not only by `APP_RELEASE_REF`;
- recompute M10 integrity after the restore.

The root copy must use `ops/deploy/staging-rsync-excludes.txt`. In particular `/storage/***` is
excluded from `rsync --delete`; a rollback that deletes request logs or evidence while restoring code
is not a rollback.

## 2. The prepared artifact is the rollback unit

A release is prepared from its exact Git object with:

```bash
./ops/deploy/prepare-release.sh <full-SHA> <output-directory>
```

Do not restore a developer checkout. A Git HEAD label plus a dirty working tree can name one commit
and deploy different bytes. The prepared tree is exported with `git archive`, then the frontend is
built/staged inside that fresh tree.

Record the `source_fingerprint` from that prepared tree's preflight. `APP_RELEASE_REF` is an operator-
maintained configuration value; the fingerprint is the measurement of bytes on disk.

## 3. First ever staging release — INITIAL_RELEASE_RESTORE

Release #1 has no previous deployed release. Requiring one would turn the first launch into an
impossible gate or encourage invented evidence.

The first release therefore performs a **same-release restore drill**:

1. retain the exact prepared release directory and preflight fingerprint;
2. deploy and qualify it normally;
3. preserve the current failure/support evidence before the drill;
4. reapply the same frozen prepared release with the storage-preserving root sync;
5. republish its Operator Console files manifest-last;
6. do not touch the runtime database or private runtime storage;
7. verify:

```bash
php ops/rollback/verify-stand-down.php \
  --expect-release=<same release SHA> \
  --expect-fingerprint=<same prepared fingerprint> \
  --email=<internal test account> \
  --env=.env
```

8. run `/healthz`, anonymous staging smoke, sign-in, request-ID lookup in `storage/request.log`, and
   require M10 integrity to pass.

Record `ROLLBACK_DRILL` with note:

```text
drill_mode=INITIAL_RELEASE_RESTORE
```

This proves the first release is reproducibly restorable from its retained artifact. It deliberately
does **not** claim cross-version rollback compatibility.

## 4. Second and later releases — PREVIOUS_RELEASE_ROLLBACK

From release #2 onward the stronger drill is available:

1. identify the previous release that has a real `STAGING_DEPLOYMENT` record;
2. preserve current failure evidence;
3. restore the previous prepared artifact with the same storage-preserving root sync;
4. restore its Operator Console manifest/assets manifest-last;
5. set `APP_RELEASE_REF` to the restored SHA;
6. verify with that release's recorded fingerprint;
7. run health, smoke, test-account sign-in, request-ID lookup and M10 integrity;
8. restore the current release again if the rollback was only a drill.

Record:

```text
drill_mode=PREVIOUS_RELEASE_ROLLBACK
```

## 5. What verify-stand-down.php proves

The verifier is read-only. It checks:

- `APP_RELEASE_REF` equals the expected release;
- the deployed source fingerprint equals the prepared fingerprint;
- the current Operator Console manifest passes the runtime validator;
- retained historical console hashes do not affect release identity;
- any unsafe unmanifested public console file is a finding;
- scheduler handlers remain empty, endpoint POST-only, credential absent, unauthenticated call denied;
- live read is neither claimed nor supported by evidence;
- the evidence registry is internally consistent;
- one explicitly named test account may be inspected without enumerating all accounts;
- **every** M10 stream still verifies, including non-active historical streams.

Fingerprint semantics matter during manifest-last deployment. The digest commits to the current
manifest and the assets it references; it intentionally ignores additional safe historical hashed
JS/CSS/SVG files retained so an already-served page does not break. Arbitrary extras, symlinks,
source maps and unhashed public assets are separate verifier findings rather than release identity.

## 6. Governed withdrawal

Application rollback does not withdraw a governed claim. Use the existing append-only mechanisms:

- M16 visibility deactivation;
- source revocation;
- authorization revocation where still outstanding;
- M10 supersession/correction;
- M11 incident lifecycle.

The original remains historical and the ledger must verify with the correction included.

## 7. Live-read stand-down

Not yet applicable. Nothing currently constructs `CurlHttpsTransport` or `DnsHostResolver` and
`live_read_activated` remains false. When live read is eventually activated in a separately reviewed
change, stand-down means remove that wiring, deploy the stand-down release, and record
`LIVE_READ_STAND_DOWN`. Never delete the activation evidence.
