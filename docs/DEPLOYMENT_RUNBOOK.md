# DEPLOYMENT RUNBOOK

Mechanics for the inert Loopia staging qualification. `BETA_RUNBOOK.md` is the authoritative
sequence; this document explains the deployment operations behind its gates.

Nothing here grants production/customer authority. The pinned canonical identity vocabulary still
contains no CUSTOMER tenant, production workspace, or PRODUCTION environment class for a public
launch.

## 1. Host/runtime prerequisites

- PHP 8.2+;
- MariaDB 10.6+;
- `pdo`, `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`, `hash`;
- SSH access;
- private domain directory whose only served child is `public_html/`;
- two databases: staging runtime and disposable qualification.

For the **Loopia staging profile**, preflight requires `DB_NAME`, `DB_USER`, `DB_PASSWORD` and a valid
32-byte `APP_MASTER_KEY`. The generic Bootstrap class deliberately allows a passwordless/socket-
authenticated database in principle; that generic possibility is not the staging qualification
profile.

## 2. Host layout

The repository layout is the deployment layout:

```text
<domain>/
  app/ database/ docs/ frontend/ ops/ tests/ worker/
  storage/                 # runtime state, PRIVATE and deployment-preserved
  .env                     # installed only after web-PHP isolation closes
  public_html/             # the only web-served directory
    .htaccess  index.php  scheduler.php  assets/
```

## 3. Prepare one exact release tree

Do not deploy the developer working tree. Git HEAD does not constrain tracked local edits or ignored
runtime/build files. Prepare from the exact Git object:

```bash
SHA=<full-40-char-sha>
RELEASE_DIR=/tmp/allfather-${SHA}
./ops/deploy/prepare-release.sh "$SHA" "$RELEASE_DIR"
(cd "$RELEASE_DIR" && test -f public_html/assets/operator-console/manifest.json)
```

`prepare-release.sh` exports tracked bytes with `git archive`, then runs the pinned frontend clean
install, tests, build and `npm run stage` inside that fresh tree. It never SSHes or rsyncs anywhere.

Run preflight from the prepared tree with a **local untracked** configuration:

```bash
php "$RELEASE_DIR/ops/deploy/preflight.php" \
  --env=/private/local/staging-preflight.env \
  --release-ref="$SHA" \
  --mode=staging
```

Record `source_fingerprint`. Do not upload that local preflight configuration before the web-PHP
cross-site isolation blocker closes.

#### Deploy the application tree

The root application copy is destructive for release-owned code and deliberately non-destructive for
runtime state. The committed exclusion file is authoritative; the repeated explicit frontend/asset
rules below make the critical exclusions visible at the command that uses `--delete`.

```bash
rsync -av --delete \
  --include='/.env.example' \
  --exclude-from="$RELEASE_DIR/ops/deploy/staging-rsync-excludes.txt" \
  --exclude 'frontend/console/node_modules' \
  --exclude 'frontend/console/dist' \
  --exclude 'public_html/assets/operator-console' \
  --exclude 'storage/***' \
  "$RELEASE_DIR/" <user>@<host>:~/<domain>/
```

`ops/deploy/staging-rsync-excludes.txt` also excludes `.git`, `.github`, host `.env`/`.env.*` configuration,
`storage/***`, private frontend dependency/build output, and generated Operator Console assets. The command's
preceding `--include='/.env.example'` is intentionally evaluated first so the tracked non-secret template remains
release-managed while every host runtime/qualification env file stays protected.

The `storage/***` rule is load-bearing. Root `rsync --delete` must never delete runtime-generated
request logs/evidence or future private files. `rsync` does not honor `.gitignore`, so the same rule
also prevents ignored local runtime state from being uploaded.

Create storage and copy only committed policy metadata, without delete:

```bash
ssh <user>@<host> 'mkdir -p ~/<domain>/storage'
rsync -av \
  "$RELEASE_DIR/storage/.htaccess" \
  "$RELEASE_DIR/storage/README.md" \
  <user>@<host>:~/<domain>/storage/
```

#### Publish Operator Console assets manifest-last

Generated console assets are excluded from the root sync. Publish hashes first, retain previous
hashes, then atomically switch the manifest:

```bash
ssh <user>@<host> 'mkdir -p ~/<domain>/public_html/assets/operator-console'

rsync -av --exclude 'manifest.json' \
  "$RELEASE_DIR/public_html/assets/operator-console/" \
  <user>@<host>:~/<domain>/public_html/assets/operator-console/

rsync -av \
  "$RELEASE_DIR/public_html/assets/operator-console/manifest.json" \
  <user>@<host>:~/<domain>/public_html/assets/operator-console/manifest.json.next

ssh <user>@<host> \
  'test -f ~/<domain>/public_html/assets/operator-console/manifest.json.next && \
   mv ~/<domain>/public_html/assets/operator-console/manifest.json.next \
      ~/<domain>/public_html/assets/operator-console/manifest.json'
```

Old content-hashed assets are deliberately retained during the deployment. Do not use `--delete` in
this asset publication or prune old hashes in the same release transaction: an already-served page
may still request them.

`SourceFingerprint` hashes the current manifest and the files it names, not safe retained historical
hashes. `verify-stand-down.php` separately rejects unsafe unmanifested public entries such as source
maps, unhashed JS/CSS, PHP, symlinks or arbitrary filenames. Fingerprint path keys are normalized to
POSIX separators so a tree prepared on Windows compares correctly with the same bytes on Loopia.

**Do not symlink a separate directory into the document root.** The deployment contract is the
literal sibling layout above.

## 4. Verify the public/private boundary before secrets

The initial copy contains no `.env`, DB password, master key or scheduler credential. From outside,
run the mechanically-enforced Gate E.2 check rather than reading a transcript of codes — its own
exit code is the result, require 0:

```bash
ops/deploy/verify-e2-private-path-refusal.sh --base-url=https://<domain>
```

See `BETA_RUNBOOK.md` §E.2 for the full rule the script enforces (403/404 pass; 200 and 503 fail;
one named `/.env`-only redirect exception checked by exact host equality, never a prefix). Run
`ops/deploy/verify-e2-private-path-refusal.sh --self-test` to exercise the classifier with no
network access.

A 503 here means the request reached `index.php` and its configuration bootstrap failed — expected
during this gate, since there is no `.env` yet, but not the refusal this gate proves. It means the
path was rewritten to the front controller instead of being refused by the web server, which is the
gap `public_html/.htaccess` closes: every private path is refused **before** the catch-all rewrite
that hands everything else to `index.php`, matched by request path against the same
private-directory list `tests/foundation/deployment_boundary.php` asserts, not by whether a file
happens to exist there.

Run the SSH/CLI cross-site canary matrix in `ops/loopia-probe/README.md`. A clean matrix proves only
`SSH_CLI` isolation, not sibling-site web-PHP isolation.

`LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER` must be CLOSED before host secrets are installed.
Accepted closure is only written Loopia assurance or an equivalent safe proof of the web-PHP
identity. `WEB_PHP_ISOLATION_REQUIRED_BEFORE_SECRETS` and
`WEB_PHP_ISOLATION_WAIVER_ALLOWED = false` remain the policy. If the blocker is UNVERIFIED, stop.

## 5. Install private staging configuration

Only after the blocker closes, create private `<domain>/.env`:

```text
APP_ENV=staging
APP_BASE_URL=https://staging.allfatherbi.com
DB_HOST=<host>
DB_PORT=3306
DB_NAME=<runtime db>
DB_USER=<runtime user>
DB_PASSWORD=<runtime password>
APP_MASTER_KEY=<base64 32-byte key>
SUPPORT_CONTACT_TEXT=<monitored support route>
APP_RELEASE_REF=<exact release SHA>
SCHEDULER_TOKEN=
SCHEDULER_TOKEN_HASH=
```

Inert staging leaves both scheduler credential fields empty. Re-run host preflight:

```bash
php ops/deploy/preflight.php --env=.env --release-ref=<SHA> --mode=staging
```

## 6. Qualification database and host checks

Create a separate empty database and `.env.qualification`. Never point it at the runtime database;
`tests/support/Harness.php` truncates application tables by design.

Run:

```bash
php ops/loopia-probe/probe.php --env=.env
php ops/loopia-probe/probe.php --env=.env --allow-network --require-live-read --probe-host=example.com

php ops/migrate.php status --env=.env
php ops/migrate.php up --env=.env
php ops/migrate.php status --env=.env
php ops/migrate.php up --env=.env

TEST_ENV_FILE=.env.qualification php tests/run.php --strict --report-json=loopia-qualification.json
```

Require no drift, idempotent second migration apply, fail=0 and skip=0.

After provisioning the exact internal TEST tenant/workspace, run:

```bash
php ops/verify-runtime-invariants.php --env=.env \
  --tenant-key=<tenant> --workspace-key=<workspace>
```

This deterministically proves the exact workspace CHECK plus a real migrated append-only governance
trigger (`access_evaluation_records`) on an exact durable `HOLD_FOR_REVIEW` qualification marker.
It replaces manual `UPDATE ... LIMIT 1` / `DELETE ... LIMIT 1` checks whose zero-row result proved
nothing on a new database. It deliberately does not call or widen M10; M10 integrity is recomputed by
the rollback verifier.

## 7. Request-reference storage

`RequestLog` writes the deterministic support-reference index to `storage/request.log`. The file is
outside `public_html`, appended with a per-write lock, chmodded 0600 on successful write, and contains
only request id, method, path without query string, and response status.

Unexpected runtime exceptions become safe 500 responses retaining the same `X-Request-ID`.
Diagnostics record request id + exception class in PHP's ordinary error log; exception messages,
SQL, stack traces and governed payloads are not returned to the caller or copied to the support index.

End-to-end proof:

```bash
grep -F 'id=<REQUEST-ID> ' storage/request.log
```

and `/storage/request.log` must remain 403/404 over HTTP.

## 8. Rollback/restore

Application rollback restores code and matching current console manifest/assets. It does not delete
runtime storage, reverse migrations, delete governed rows or change authority. Use the same
storage-preserving root sync and manifest-last asset process.

For the first staging release, perform `INITIAL_RELEASE_RESTORE`: retain the exact prepared artifact
and fingerprint, reapply that same frozen artifact, then verify:

```bash
php ops/rollback/verify-stand-down.php \
  --expect-release=<SHA> \
  --expect-fingerprint=<preflight fingerprint> \
  --email=<internal test account> \
  --env=.env
```

This proves reproducible restore of release #1, not cross-version rollback. From release #2 onward,
perform `PREVIOUS_RELEASE_ROLLBACK` against the prior recorded release. See `ops/rollback/README.md`.

## 9. Still not authorized

Even after every staging gate passes:

```text
live_read_activated                = false
road_test_001_executed             = false
external_action_authorized         = false
customer_visible_output_authorized = false
owner_go_granted                    = false
production_launch_authorized        = false
```

Production provisioning remains structurally refused because canonical customer/production identity
vocabulary does not exist at the pinned head.
