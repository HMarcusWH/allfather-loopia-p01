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
  app/
  database/
  docs/
  frontend/
  ops/
  tests/
  worker/
  storage/                 # runtime state, PRIVATE and deployment-preserved
  .env                     # private configuration; installed only after isolation closes
  public_html/             # the only web-served directory
    .htaccess
    index.php
    scheduler.php
    assets/
```

Do not point a domain at the repository root and do not symlink a different application root into
`public_html`.

## 3. Prepare one exact release tree

Do not deploy the developer working tree. A clean Git HEAD alone does not prove the bytes being
copied are the commit: tracked local changes and ignored runtime/build files are separate state.

Prepare from the exact Git object:

```bash
SHA=<full-40-char-sha>
RELEASE_DIR=/tmp/allfather-${SHA}
./ops/deploy/prepare-release.sh "$SHA" "$RELEASE_DIR"
```

The script exports with `git archive`, then runs the pinned frontend clean install, tests, build and
stage inside that fresh exported tree. It never SSHes or rsyncs anywhere.

Run preflight **from the prepared tree** with a local untracked configuration:

```bash
php "$RELEASE_DIR/ops/deploy/preflight.php" \
  --env=/private/local/staging-preflight.env \
  --release-ref="$SHA" \
  --mode=staging
```

Record the returned `source_fingerprint`.

The preflight configuration is local operator input only. Do not upload it before the web-PHP
cross-site isolation blocker closes.

## 4. Root application copy: preserve runtime state

The root sync uses the committed exclusion set:

```bash
rsync -av --delete \
  --exclude-from="$RELEASE_DIR/ops/deploy/staging-rsync-excludes.txt" \
  "$RELEASE_DIR/" <user>@<host>:~/<domain>/
```

`ops/deploy/staging-rsync-excludes.txt` excludes:

- `.git` / `.github`;
- all `.env*` host configuration;
- **`storage/***`**;
- private frontend dependencies/build output;
- generated Operator Console public assets.

The `storage/***` rule is load-bearing. Root `rsync --delete` must never delete runtime-generated
request logs, evidence payloads, scheduler output or future private runtime files. `rsync` also does
not honor `.gitignore`, so excluding storage prevents a developer's ignored local runtime files from
being uploaded.

Create storage and copy only its committed policy metadata, without delete:

```bash
ssh <user>@<host> 'mkdir -p ~/<domain>/storage'
rsync -av \
  "$RELEASE_DIR/storage/.htaccess" \
  "$RELEASE_DIR/storage/README.md" \
  <user>@<host>:~/<domain>/storage/
```

## 5. Operator Console publish: manifest last

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

Do not use `--delete` here and do not prune old content hashes in the release transaction. An already
served browser page may still request them.

`SourceFingerprint` therefore hashes the current manifest and the files it names, not safe retained
historical hashes. `verify-stand-down.php` separately rejects unsafe unmanifested public entries such
as source maps, unhashed JS/CSS, PHP, symlinks or arbitrary filenames.

Fingerprint path keys are normalized to POSIX separators so a release preflighted on Windows hashes
identically to the same bytes on Linux/Loopia.

## 6. Verify the public/private boundary before secrets

The initial copy contains no `.env`, DB password, master key or scheduler credential.

From outside, require 403 or 404, never 200:

```bash
for path in /.env /app/autoload.php /database/migrations/001_identity_scope.sql \
            /storage/request.log /worker/tick.php /ops/migrate.php \
            /frontend/console/package.json /tests/run.php \
            /composer.json /upstream.lock.json /deployment-evidence.json; do
  printf '%s -> ' "$path"
  curl -sS -o /dev/null -w '%{http_code}\n' "https://<domain>${path}"
done
```

Also run the SSH/CLI cross-site canary matrix described in `ops/loopia-probe/README.md`.

A clean SSH/CLI matrix proves only `SSH_CLI` isolation. It does not prove sibling-site web-PHP
isolation.

### Non-waivable pre-secret blocker

`LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER` must be CLOSED before host secrets are installed.
Accepted closure is only:

1. written Loopia assurance; or
2. an equivalent safe proof of the web-PHP identity.

`WEB_PHP_ISOLATION_WAIVER_ALLOWED = false`.

If the blocker remains UNVERIFIED, stop here.

## 7. Install private staging configuration

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

Inert staging leaves both scheduler credential fields empty. Both scheduler composition roots still
have an empty handler registry.

Re-run host preflight:

```bash
php ops/deploy/preflight.php --env=.env --release-ref=<SHA> --mode=staging
```

## 8. Qualification database

Create a separate empty database and `.env.qualification`:

```text
APP_ENV=testing
DB_HOST=<host>
DB_PORT=3306
DB_NAME=<qualification db>
DB_USER=<qualification user>
DB_PASSWORD=<qualification password>
```

Never point this file at the runtime database. `tests/support/Harness.php` truncates application
tables by design.

## 9. Host qualification

Run, in order:

```bash
# host capabilities
php ops/loopia-probe/probe.php --env=.env
php ops/loopia-probe/probe.php --env=.env --allow-network --require-live-read --probe-host=example.com

# runtime migrations
php ops/migrate.php status --env=.env
php ops/migrate.php up --env=.env
php ops/migrate.php status --env=.env
php ops/migrate.php up --env=.env

# full suite ONLY on qualification DB
TEST_ENV_FILE=.env.qualification php tests/run.php --strict --report-json=loopia-qualification.json
```

Require no migration drift, idempotent second apply, fail=0 and skip=0.

Then provision the internal TEST tenant/workspace and run the real runtime-schema proof:

```bash
php ops/verify-runtime-invariants.php --env=.env \
  --tenant-key=<tenant> --workspace-key=<workspace>
```

This is the deterministic replacement for manual `DELETE ... LIMIT 1` / `UPDATE ... LIMIT 1`
commands on potentially empty tables. It proves the workspace CHECK and decision-record append-only
trigger on exact rows and verifies M10 afterwards.

## 10. Request-reference storage

`RequestLog` writes the support-reference index to:

```text
storage/request.log
```

The file is outside `public_html`, appended with a per-write lock, chmodded 0600 on successful write,
and contains only:

- request id;
- HTTP method;
- path without query string;
- response status.

Unexpected runtime exceptions become safe 500 responses that retain the same `X-Request-ID`.
Runtime diagnostics record only request id + exception class in PHP's ordinary error log; exception
messages, SQL, stack traces and governed payloads are not returned to the caller or copied into the
support-reference index.

End-to-end support proof:

```bash
grep -F 'id=<REQUEST-ID> ' storage/request.log
```

and `/storage/request.log` must remain 403/404 over HTTP.

## 11. Rollback/restore

Application rollback restores code and matching current console manifest/assets. It does not delete
runtime storage, reverse migrations, delete governed rows or change authority.

Use the same storage-preserving root rsync and manifest-last asset process for rollback.

### First staging release

There is no previous deployed release. Perform an `INITIAL_RELEASE_RESTORE`: retain the exact
prepared release artifact and fingerprint, reapply that same frozen artifact, then verify:

```bash
php ops/rollback/verify-stand-down.php \
  --expect-release=<SHA> \
  --expect-fingerprint=<preflight fingerprint> \
  --email=<internal test account> \
  --env=.env
```

This proves reproducible restore of release #1 but not cross-version rollback.

### Later releases

Perform `PREVIOUS_RELEASE_ROLLBACK`: restore the previously recorded staging release and its
fingerprint, verify, then restore current if this is only a drill.

See `ops/rollback/README.md`.

## 12. What this deployment still does not authorize

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
vocabulary does not exist in the pinned projection.
