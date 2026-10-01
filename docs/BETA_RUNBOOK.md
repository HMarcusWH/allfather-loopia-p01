# BETA RUNBOOK

**This is the execution sequence for the inert Loopia staging qualification.** It starts from an
exact green Git commit and stops before partner beta, live read, scheduler activation, external
action, customer-visible output, owner GO, or production launch.

Nothing in this runbook grants authority. Read `CLAIM_CEILING.md`, `SECURITY_BOUNDARY.md`, and
`DEPLOYMENT_EVIDENCE_MODEL.md` first.

For the owner-directed 25 September 2026 inert staging continuation, read
[the scoped deviation record](STAGING_OWNER_DIRECTED_DEVIATION_2026-09-25.md) before Gates A and E.
It preserves measured failures/unknowns, excludes WordPress maintenance, and defines the conditional
exact-release local qualification route during the recorded hosted billing failure.

## Two non-negotiable rules

### 1. The qualification database is not the runtime database

`tests/support/Harness.php` truncates every application table except `schema_migrations` between
suites. Never run `tests/run.php` against the staging runtime database.

| Database | Configuration | May the suite destroy it? |
| --- | --- | --- |
| staging runtime | `.env` | **no** |
| qualification | `.env.qualification` | yes |

The host strict suite is always:

```bash
TEST_ENV_FILE=.env.qualification php tests/run.php --strict
```

### 2. No host secret exists before web-PHP isolation is closed

`LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER` must be **CLOSED** before `DB_PASSWORD` or
`APP_MASTER_KEY` is placed on Loopia. SSH/CLI isolation and HTTP document-root refusal do not prove
cross-site isolation of the web PHP identity.

`WEB_PHP_ISOLATION_REQUIRED_BEFORE_SECRETS`

`WEB_PHP_ISOLATION_WAIVER_ALLOWED = false`

There is no owner/operator waiver.

---

## Gate A — Freeze one exact release

Record:

- full 40-character release SHA;
- canonical pin from `upstream.lock.json`;
- exact CI run id — or, while hosted runners are unavailable, the release qualification receipt.

Require on that SHA:

- foundation green;
- MariaDB 10.6 green;
- MariaDB 10.11 green;
- release qualification green;
- fail = 0;
- skip = 0.

The same requirements hold on either route, because both run the same steps. The local route is:

```bash
bash ops/ci/qualify-release.sh local --out=<new-dir> --ref="$SHA" \
  --env-10.6=<disposable 10.6 env> --env-10.11=<disposable 10.11 env>
php ops/ci/verify-qualification.php verify-receipt \
  --receipt=<new-dir>/release-qualification-receipt.json \
  --lanes-dir=<new-dir>/reports \
  --preflight=<new-dir>/jobs/release-preparation/work/preflight.json
```

Require `result: PASS`, `execution.kind: LOCAL`, `hosted_ci: false` and a verifier exit of 0. A local
receipt is filed, if at all, as `CONFORMANCE` with `environment: "ci"` — never as GitHub green or host
evidence — and the [deviation record](STAGING_OWNER_DIRECTED_DEVIATION_2026-09-25.md) §4 still governs
when it may stand in for hosted CI. Record the state in [`MVP_RELEASE_STATE.md`](MVP_RELEASE_STATE.md).

**STOP** on any disagreement.

## Gate B — Prepare the deployable tree from the Git object

Do not deploy a developer checkout. Local tracked edits, ignored files, old frontend output and local
runtime storage must be structurally unable to enter the release.

From a repository checkout:

```bash
SHA=<Gate-A-SHA>
RELEASE_DIR=/tmp/allfather-${SHA}
./ops/deploy/prepare-release.sh "$SHA" "$RELEASE_DIR"
```

The command:

1. verifies the exact commit object;
2. creates a fresh tree with `git archive <SHA>`;
3. runs the pinned frontend `npm ci`, tests, production build and `npm run stage` in that fresh tree;
4. never uses SSH or rsync and therefore cannot deploy anything.

**Pass requires** `PREPARED_RELEASE` for the exact SHA and a valid
`$RELEASE_DIR/public_html/assets/operator-console/manifest.json`.

## Gate C — Local release preflight

Create a **local, untracked operator-side staging configuration** containing the values intended for
Gate F. It is an input to preflight only. **It must not be uploaded to Loopia before Gate E.3 closes.**

Run preflight from the prepared tree:

```bash
php "$RELEASE_DIR/ops/deploy/preflight.php" \
  --env=/private/local/staging-preflight.env \
  --release-ref="$SHA" \
  --mode=staging
```

Require:

```json
{"status":"READY_FOR_INERT_STAGING_COPY"}
```

Record `source_fingerprint`. A passing preflight authorizes neither secret placement nor deployment;
it only says this prepared tree is internally consistent for an inert copy.

For the Loopia staging profile, `DB_PASSWORD` is required by preflight. The generic application
bootstrap still permits a passwordless/socket-authenticated database in principle; that generic
capability is not the Loopia launch profile.

## Gate D — Copy the prepared release to staging, without secrets

The rsync source is **`$RELEASE_DIR/`**, never the developer checkout.

The destructive root sync uses the reviewed filter contract. The explicit include MUST precede the exclusion file:

```bash
rsync -av --delete \
  --include='/.env.example' \
  --exclude-from="$RELEASE_DIR/ops/deploy/staging-rsync-excludes.txt" \
  "$RELEASE_DIR/" <user>@<host>:~/<domain>/
```

The exclusions protect all of these from the root `--delete`:

- host `.env` and `.env.*` runtime/qualification configuration; the tracked `.env.example` is explicitly admitted by the preceding include rule;
- **all runtime `storage/` contents**;
- frontend private dependency/build trees;
- the separately published Operator Console bundle.

`rsync` does not honor `.gitignore`; this filter contract is therefore load-bearing. A deploy must neither
delete host runtime logs/evidence nor upload ignored runtime state from a developer machine.

Immediately after the root sync, prove the tracked template arrived **through that normal path**, not
through a one-off repair copy. This checksum-mode dry run must produce **no output**; any
`.env.example` itemized line means the deployed bytes still differ, and a non-zero rsync exit is a
hard stop:

```bash
if ! ENV_TEMPLATE_DRIFT="$(rsync -nric \
  --include='/.env.example' \
  --exclude='*' \
  "$RELEASE_DIR/" <user>@<host>:~/<domain>/)"; then
  echo "STOP: could not verify deployed .env.example" >&2
  exit 1
fi
if [ -n "$ENV_TEMPLATE_DRIFT" ]; then
  printf '%s\n' "$ENV_TEMPLATE_DRIFT" >&2
  echo "STOP: deployed .env.example is not byte-identical to the prepared release" >&2
  exit 1
fi
```

Do not SCP `.env.example` as a repair. If this check fails, repair the release filter contract and
redeploy the exact prepared artifact.

Create the private runtime directory and copy only its committed policy files, without delete:

```bash
ssh <user>@<host> 'mkdir -p ~/<domain>/storage'
rsync -av \
  "$RELEASE_DIR/storage/.htaccess" \
  "$RELEASE_DIR/storage/README.md" \
  <user>@<host>:~/<domain>/storage/
```

Publish Operator Console files manifest-last:

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

Do not prune old content-hashed assets during this release. The source fingerprint commits to the
**current manifest and its referenced files**; safe historical hashes are deliberately ignored by
the digest but the stand-down verifier rejects arbitrary unmanifested public files.

**Do not upload yet:** `.env`, DB credentials, `APP_MASTER_KEY`, scheduler credentials.

## Gate E — Pre-secret isolation

### E.1 SSH/CLI filesystem canaries

Create a worthless canary in each site's private storage, then probe every sibling directory from
each site identity:

```bash
php ops/loopia-probe/isolation-canary.php --mode=create  --dir=~/<domain>/storage
php ops/loopia-probe/isolation-canary.php --mode=probe   --dir=~/<other-domain>/storage
php ops/loopia-probe/isolation-canary.php --mode=cleanup --dir=~/<domain>/storage
```

Required controls:

- own canary = `READABLE`;
- sibling canary = `NOT_READABLE` or `NOT_FOUND`.

Any cross-site `READABLE` is a hard stop.

This measures `SSH_CLI` only. It does **not** close E.3.

### E.2 HTTP private-path refusal

From outside, run the mechanically-enforced check rather than reading a transcript of codes:

```bash
ops/deploy/verify-e2-private-path-refusal.sh --base-url=https://staging.allfatherbi.com
```

**Require exit code 0.** This is not a loop that prints a status per line for an operator to judge —
the script's own exit code IS the gate result. It classifies every private path independently
(403/404 → pass; anything else → fail), with exactly one named exception scoped to exactly one path
(below), never follows a redirect (`--max-redirs 0`, no `-L` anywhere in the script), and requires
`--base-url` to be `https://` — refused rather than upgraded, so a run against unconfirmed TLS cannot
report a result. Run `ops/deploy/verify-e2-private-path-refusal.sh --self-test` first if you want to
see the classifier itself exercised against 24 cases with no network access at all.

**Never 200, never 503.** A 503 means the request reached `index.php` and its configuration
bootstrap failed — expected during Gate D, since there is no `.env` yet, but not the refusal this
gate exists to prove: it means the web server rewrote a private path to the front controller instead
of refusing it, and the SAME rewrite would 503 for a misconfigured production host too.
`public_html/.htaccess` refuses every probed path **before** the catch-all rewrite, by request path,
and `tests/foundation/deployment_boundary.php` asserts both that the refusal rules exist and that
they cover the full private-directory list, not only the paths probed here.

**The one accepted exception, enforced by the script rather than merely documented here:** `/.env`
alone may answer `302` when `Location` parses as an HTTPS URL whose host is exactly
`roadblock.loopia.com` and whose port is absent or `443`. The path/query portion does not establish
trust; the real observed Loopia response was
`https://roadblock.loopia.com?id=430057&host=staging.allfatherbi.com`, with a query immediately
after the host. The script parses `Location` with PHP `parse_url()` and compares the scheme, host
and port components directly — never by a raw-URL regex or string prefix. Any other host or scheme,
a non-default port, a redirect on any path other than `/.env`, or any 3xx other than `302` is a
**hard fail**. `ops/staging-smoke.php`'s anonymous mode carries the corresponding `/.env`-only WAF
exception for the later gates, and `tests/security/` covers both clients.

### E.3 Web-PHP cross-site isolation — must close before Gate F

There are exactly two accepted closure routes:

1. **written Loopia assurance** that web PHP for one site cannot read another site's private files;
2. **equivalent safe host proof of the web PHP identity** without adding an arbitrary file-read
   endpoint to this application.

Useful support-ticket wording:

> Please confirm whether PHP executed for one website in our Loopia account can read private files
> belonging to another website in the same account outside that site's `public_html`, and whether
> web PHP is confined per site by `open_basedir`, Unix identity, or an equivalent isolation
> mechanism.

Not accepted: SSH/CLI canary PASS, HTTP private-path PASS, owner acceptance, operator judgement,
staging-only credentials, reduced blast radius, or probable equivalence.

If neither accepted closure exists: **STOP. Gate F is forbidden.**

## Gate F — Install private staging configuration

Only after E.3 is CLOSED, place private `~/<domain>/.env`:

```text
APP_ENV=staging
APP_BASE_URL=https://staging.allfatherbi.com
DB_HOST=<host>
DB_PORT=3306
DB_NAME=<runtime database>
DB_USER=<runtime user>
DB_PASSWORD=<runtime password>
APP_MASTER_KEY=<base64 32-byte key>
SUPPORT_CONTACT_TEXT=<monitored route to a human>
APP_RELEASE_REF=<Gate-A SHA>
SCHEDULER_TOKEN=
SCHEDULER_TOKEN_HASH=
```

Then run on the host:

```bash
php ops/deploy/preflight.php --env=.env --release-ref="$SHA" --mode=staging
```

Require `READY_FOR_INERT_STAGING_COPY` again.

## Gate G — Create the disposable qualification database

Create a second empty database and private `.env.qualification`:

```text
APP_ENV=testing
DB_HOST=<host>
DB_PORT=3306
DB_NAME=<qualification database, NOT runtime>
DB_USER=<qualification user>
DB_PASSWORD=<qualification password>
```

By eye:

```bash
grep DB_NAME .env .env.qualification
```

The names must differ.

## Gate H — Real-host capability probe

Baseline:

```bash
php ops/loopia-probe/probe.php --env=.env
```

Require `staging_host_verdict: READY`.

Then separately:

```bash
php ops/loopia-probe/probe.php \
  --env=.env \
  --allow-network \
  --require-live-read \
  --probe-host=example.com
```

This proves capability only. It does not activate live read. Scheduler transport remains
`UNVERIFIED` unless separately established.

## Gate I — Runtime migrations

Against `.env` only:

```bash
php ops/migrate.php status --env=.env
php ops/migrate.php up --env=.env
php ops/migrate.php status --env=.env
php ops/migrate.php up --env=.env
```

Require all migrations applied, zero drift, and the second `up` to be a no-op.

## Gate J — Full strict suite on the qualification database

Never use `.env` here:

```bash
TEST_ENV_FILE=.env.qualification php tests/run.php --strict --report-json=loopia-qualification.json
```

Require fail = 0 and skip = 0.

## Gate K — Provision one internal TEST scope

Dry-run first, then the same command without `--dry-run`:

```bash
php ops/provision-staging.php --env=.env --dry-run \
  --tenant-key=<tenant> --workspace-key=<workspace> \
  --display-name='<Tenant display name>' --operator-name='<Operator>' \
  --modules=P01,P02,P03,P04,P05
```

The runtime identity remains canonical `TEST`; production/customer identity vocabulary is not
invented here.

## Gate L — Deterministic runtime-database invariant proof

The old launch procedure attempted `DELETE ... LIMIT 1` and `UPDATE ... LIMIT 1` before any rows
existed; zero affected rows proved nothing. Gate L instead targets the exact internal TEST scope from
Gate K:

```bash
php ops/verify-runtime-invariants.php --env=.env \
  --tenant-key=<tenant> --workspace-key=<workspace>
```

Require:

```json
{
  "status": "RUNTIME_INVARIANTS_VERIFIED",
  "workspace_external_effect_guard": "PASS",
  "access_evaluation_append_only": "PASS"
}
```

The command:

- proves the real `external_side_effects_allowed = 0` CHECK by attempting the unsafe value on the
  exact TEST workspace and requiring MariaDB's CHECK-constraint refusal;
- appends/reuses one durable `HOLD_FOR_REVIEW` qualification marker in the real migrated
  `access_evaluation_records` governance table;
- attempts to delete that exact marker and requires SQLSTATE `45000` with the
  `access_evaluation_records is append-only` trigger message;
- proves the marker remains after the refused delete.

It deliberately does **not** call M10 or invent M10 input-reference vocabulary; canonical runtime M10
callers remain unchanged. M10 integrity is recomputed later by the rollback/stand-down verifier.
The command is idempotent: reruns reuse the same qualification marker.

## Gate M — Onboard one internal beta login

Dry-run first. Then provide the temporary password through stdin only:

```bash
read -rs PW; printf '%s\n' "$PW" | php ops/onboard-beta-user.php --env=.env \
  --tenant-key=<tenant> --workspace-key=<workspace> \
  --email=<address> --display-name='<Partner name>' --password-stdin
```

Require `must_change_password` to be set.

## Gate N — Staging smoke

```bash
php ops/staging-smoke.php --base-url=https://staging.allfatherbi.com --mode=anonymous

read -rs PW; read -rs NEW; printf '%s\n%s\n' "$PW" "$NEW" | php ops/staging-smoke.php \
  --base-url=https://staging.allfatherbi.com --mode=beta-journey \
  --email=<address> --password-stdin
```

Require `SMOKE_PASS` and zero required responses missing `X-Request-ID`.

For `beta-journey`, that pass must include the actual lifecycle path: first sign-in lands on the
forced-password surface; the pre-change session is replayed and refused after password replacement;
the replacement password signs in to `/console`; a workspace offered by the chooser is selected and
re-read as active session state; governed API responses keep their canonical route/source/status
semantics; caller-supplied tenant/workspace/operator/environment query scope changes nothing; and
the session token captured immediately before logout is replayed and refused afterwards.

Then manually inspect: sign-in → forced password change → workspace chooser → React console → P01 →
P02 → P03 → P04 → P05 → M10 → Help & support → Account → sign out.

## Gate O — Security negatives on the real host

Require correct refusal for:

- cross-tenant read;
- cross-workspace read;
- query attempts to override tenant/workspace/operator/environment scope;
- old session after password change/reset/suspension;
- stale session after reactivation;
- sign-in after retirement.

Owner denial is not an empty result.

## Gate P — Request-ID support proof

Capture an `X-Request-ID` from a real deployed response. Then over SSH:

```bash
grep -F 'id=<REQUEST-ID> ' storage/request.log
```

Require exactly that reference to be present in the deterministic private request index, and reconfirm
`https://staging.allfatherbi.com/storage/request.log` returns 403/404.

The log contains only request id, method, path and response status. It contains no query string,
body, account, tenant or workspace identifiers.

## Gate Q — Rollback/restore drill

There are two honest modes.

### First ever staging deployment: `INITIAL_RELEASE_RESTORE`

There is no previous deployed release yet, so do not invent one. Preserve the exact prepared Gate-B
release directory and Gate-C fingerprint, then re-apply that frozen release using the same storage-
preserving root sync and manifest-last asset procedure. Configuration and runtime database remain in
place.

Verify:

```bash
php ops/rollback/verify-stand-down.php \
  --expect-release="$SHA" \
  --expect-fingerprint=<Gate-C source_fingerprint> \
  --email=<internal test account> \
  --env=.env
```

Then `/healthz`, anonymous smoke, sign-in, request-ID lookup, and M10 integrity.

This proves reproducible restore of release #1. It does **not** claim cross-version rollback.
Record the `ROLLBACK_DRILL` note as `drill_mode=INITIAL_RELEASE_RESTORE`.

### Second and later deployments: `PREVIOUS_RELEASE_ROLLBACK`

Restore the previous recorded staging release using its prepared artifact/fingerprint, verify it,
then restore the current release if this was only a drill. Record
`drill_mode=PREVIOUS_RELEASE_ROLLBACK`.

Rollback never deletes governed history, never runs down-migrations, and never lets root rsync
delete runtime `storage/`.

## Gate R — Record reviewed evidence

Save outputs outside the repository as appropriate, hash them, then add reviewed pointer records to
`deployment-evidence.json` and run:

```bash
php ops/evidence/validate.php
```

Expected kinds from this sequence:

| Activity | Kind |
| --- | --- |
| host strict suite | `CONFORMANCE` |
| deployment | `STAGING_DEPLOYMENT` |
| host probe | `LOOPIA_PROBE_001` |
| isolation | `CROSS_SITE_ISOLATION` |
| smoke | `STAGING_SMOKE` |
| restore/rollback | `ROLLBACK_DRILL` |

Only after supporting records exist may the relevant evidence-backed booleans be changed in a
separate reviewed edit. Authority locks do not move.

## Gate S — STOP

With real evidence, you may say the inert staging application is deployed and qualified on Loopia.

Still false:

```text
live_read_activated                = false
road_test_001_executed             = false
external_action_authorized         = false
customer_visible_output_authorized = false
owner_go_granted                    = false
production_launch_authorized        = false
```

`no_external_charge_executed` remains true. Scheduler handlers remain empty. No partner has been
invited. No production/customer identity has been invented.

The next phase, after independent review of the exact merged SHA, is a separately reviewed
`ROAD_TEST_001` activation change — not part of this runbook.

## P06 — ROAD_TEST_001 remains a separate manual phase

Gate S closes the inert staging deployment only. P06 code may be present on main without making
ROAD_TEST_001 executed.

Before the first manual native Monitor mission:

- complete exact-head qualification and inert deployment for that candidate;
- pass ordinary staging smoke and ops/staging-monitor-smoke.php;
- complete the real P10 backup/off-host/clean-restore proof on the intended Loopia path;
- run the bounded synthetic MODEL_EVALUATION lane;
- separately approve the exact four MANUAL_MONITOR prompt identities;
- configure the mission through ops/monitor/setup.php;
- admit one bounded MANUAL_MONITOR grant through the reviewed owner-decision path.

Follow docs/ROAD_TEST_001_MONITOR.md for the mission itself. Both release decisions remain explicit
human reviews in /console/monitor; a model critic is not a substitute.

When the run ends, stand execution down immediately, verify M10 plus every Monitor journal, produce
the sanitized ROAD_TEST_001 summary only after stand-down, then rerun P10 over the populated
history. Only after those observations
exist should a separate reviewed evidence PR file LIVE_READ_ACTIVATION, ROAD_TEST_001,
LIVE_READ_STAND_DOWN or related records.

No scheduler, external action, customer-visible output or production authority is implied.

