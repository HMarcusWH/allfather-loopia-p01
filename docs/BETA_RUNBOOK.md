# BETA RUNBOOK

**This is the sequence.** Every other document here explains what something is; this one says what to
do, in what order, and what must be true before you go on.

It takes a green release and ends with a staging deployment on the real Loopia host that has been
qualified by running commands and collecting evidence. It does **not** launch a beta, activate live
read, run a road test, or authorize anything. Gate S says exactly where it stops and what is still
false.

Read [`CLAIM_CEILING.md`](./CLAIM_CEILING.md) and
[`DEPLOYMENT_EVIDENCE_MODEL.md`](./DEPLOYMENT_EVIDENCE_MODEL.md) first. Nothing in this file
constitutes launch authority.

---

## The two rules that will cost you the most if you skip them

### 1. The qualification database is NOT the staging runtime database

`tests/support/Harness.php` **truncates every application table except `schema_migrations`** between
suites. That is correct for a test harness and catastrophic against a populated deployment.

> **NEVER run `php tests/run.php` against the staging runtime database.**
> It will empty it. Every tenant, every operator, every login, every governed record.

So the host gets **two databases**, and the runtime configuration never names the one the tests use:

| Database | Named by | May be destroyed |
| --- | --- | --- |
| staging runtime | `.env` → `DB_NAME` | **no, never** |
| qualification | `.env.qualification` → `DB_NAME` | yes, by every run |

and the suite is only ever pointed at the second one, explicitly:

    TEST_ENV_FILE=.env.qualification php tests/run.php --strict

`TEST_ENV_FILE` exists precisely because the harness otherwise resolves its connection from
`.env.testing` and then `.env` — and `.env` on a staging host is the runtime database. The runner
has no `--env` flag of its own; passing one is silently ignored.

### 2. No secret goes on the host until Gate E passes

Cross-site isolation is answered **before** `APP_MASTER_KEY` and the database password are uploaded,
not after. Answering it afterwards means answering it too late: a secret that turns out to be
readable from a sibling site has already been exposed for however long it took to find out.

---

## Gate A — Freeze the release

Pick the exact commit. Record:

| | |
| --- | --- |
| release SHA | full 40 characters, never abbreviated |
| canonical pin | `upstream.lock.json` → `projection_pinned_head` |
| CI run | the run id and its conclusion |

**Pass requires**, on that exact SHA:

- `foundation` green — frontend install, tests, production build, staging; PHP syntax; foundation
  invariants; unit tests
- `mariadb 10.6` green and `mariadb 10.11` green — migrations, idempotency, status, host probe,
  evidence registry, full strict suite
- `release qualification` green — it re-reads both uploaded reports and asserts `complete: true`,
  `strict: true`, `fail: 0`, `skip: 0` and a database version present
- **fail = 0 and skip = 0** in both reports

A skip is not a pass. Under `--strict` it is a failing exit, and the qualification gate rejects a
report that carries one.

**STOP if** anything above is red, or if the reports disagree with the job status.

## Gate B — Build the frontend

    cd frontend/console
    npm ci
    npm test
    npm run build
    npm run stage
    cd ../..

`npm ci` — not `npm install` — because the lockfile is the build input. `stage` validates the build
output against its own manifest and refuses a symlink, an unhashed name, an unmanifested file or a
manifest that does not exactly describe `dist/`.

**Pass requires** `public_html/assets/operator-console/manifest.json` to exist and name a hashed
script and stylesheet.

## Gate C — Release preflight

Write the private staging configuration first (see Gate F for the contents), then:

    php ops/deploy/preflight.php \
      --env=<path to the private staging .env> \
      --release-ref=<the 40-character SHA from Gate A> \
      --mode=staging

**Pass requires** exit 0 and:

    "status": "READY_FOR_INERT_STAGING_COPY"

Anything else prints every problem it found. It copies nothing and opens no connection to the host.
See [`ops/deploy/README.md`](../ops/deploy/README.md) for what each check is for.

**STOP if** `problems` is non-empty. In particular a mismatched `APP_RELEASE_REF` is the commonest
wrong-but-healthy-looking deployment there is.

## Gate D — Copy the release to staging, WITHOUT secrets

Upload the exact release. Follow [`DEPLOYMENT_RUNBOOK.md`](./DEPLOYMENT_RUNBOOK.md) §3 — the
qualification copy profile, the application tree first and the Operator Console manifest last.

**Do NOT upload yet:**

- `.env`
- any database credential
- `APP_MASTER_KEY`
- any scheduler token

The site will not serve anything useful without configuration. That is the point: Gate E runs
against a deployment that holds nothing worth stealing.

## Gate E — Pre-secret cross-site isolation

**This is the gate that protects every secret you are about to place.**

### E.1 Filesystem canaries

On each site in the account, in its own **private** directory (a sibling of `public_html`, never
inside it):

    php ops/loopia-probe/isolation-canary.php --mode=create --dir=~/<domain>/storage

Then, as **each** site, against **every** directory including its own:

    php ops/loopia-probe/isolation-canary.php --mode=probe --dir=~/<other-domain>/storage

| Reader | Directory probed | Required |
| --- | --- | --- |
| staging site | its own private directory | `READABLE` |
| platform site | its own private directory | `READABLE` |
| staging site | platform's private directory | `NOT_READABLE` or `NOT_FOUND` |
| platform site | staging's private directory | `NOT_READABLE` or `NOT_FOUND` |
| presentation/other site | staging's private directory | `NOT_READABLE` or `NOT_FOUND` |

The own-canary rows are the **control**. Without them a `NOT_FOUND` everywhere looks like perfect
isolation and might just mean nothing was ever written.

Clean up afterwards:

    php ops/loopia-probe/isolation-canary.php --mode=cleanup --dir=~/<domain>/storage

### E.2 The HTTP private-path probe

From outside, against the deployed site:

    for path in /.env /app/autoload.php /database/migrations/001_identity_scope.sql \
                /storage/request.log /worker/tick.php /ops/migrate.php \
                /frontend/console/package.json /tests/run.php \
                /composer.json /upstream.lock.json /deployment-evidence.json; do
      printf '%s -> ' "$path"
      curl -sS -o /dev/null -w '%{http_code}\n' "https://staging.allfatherbi.com${path}"
    done

**403 or 404 are both correct. Never 200.**

This is not the same thing as `tests/foundation/deployment_boundary.php`. That proves the *layout*;
this proves *Loopia's behaviour*. Apache does not run in CI, and every `.htaccess` directive here is
`<IfModule>`-guarded and therefore a silent no-op on a host missing the module.

**HARD STOP if** any cross-site private read is `READABLE`, or any private path answers 200. Do not
place `APP_MASTER_KEY` or database credentials on the host. Record the finding as a
`CROSS_SITE_ISOLATION` `FAIL` record and raise it with Loopia.

## Gate F — Configure staging

Private `.env` in `~/<domain>/`, never in `public_html/`, never in git:

    APP_ENV=staging
    APP_BASE_URL=https://staging.allfatherbi.com

    DB_HOST=<host>
    DB_PORT=3306
    DB_NAME=<staging RUNTIME database>
    DB_USER=<staging user>
    DB_PASSWORD=<staging password>

    APP_MASTER_KEY=<32 bytes, base64>
    SUPPORT_CONTACT_TEXT=<a real, monitored route to a human>
    APP_RELEASE_REF=<the 40-character SHA from Gate A>

    # Left BLANK for inert staging. With neither key set the tick endpoint is unreachable
    # rather than open, which is the right posture for a deployment whose handler registry is empty.
    SCHEDULER_TOKEN=
    SCHEDULER_TOKEN_HASH=

Generate the key with:

    php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'

`SUPPORT_CONTACT_TEXT` is free text an operator writes and there is deliberately no default: a
support page naming an address nobody reads is worse than one saying no contact is configured.

**Pass requires** `php ops/deploy/preflight.php --env=.env --release-ref=<SHA> --mode=staging`, run
**on the host**, to answer `READY_FOR_INERT_STAGING_COPY`.

## Gate G — A dedicated qualification database

> Re-read rule 1 at the top of this file before continuing.

Create a **second, empty** database. Then a private `.env.qualification`, beside `.env`:

    APP_ENV=testing
    DB_HOST=<host>
    DB_PORT=3306
    DB_NAME=<qualification database — NOT the runtime one>
    DB_USER=<user>
    DB_PASSWORD=<password>

**Pass requires** that `DB_NAME` in `.env.qualification` is **different** from `DB_NAME` in `.env`.
Check it by eye, out loud, before Gate J:

    grep DB_NAME .env .env.qualification

`APP_ENV=testing` here is deliberate and is not a contradiction with Gate F: this file configures the
test harness, not the deployment. The bootstrap refuses to let a strict environment be configured
from anything but `.env`, so the two cannot be confused by the application.

## Gate H — Host capability probe

Baseline, no network:

    php ops/loopia-probe/probe.php --env=.env

**Pass requires** `"staging_host_verdict": "READY"`. `live_read_host_verdict` will be
`NOT_QUALIFIED` — nothing was demonstrated, which is the honest answer for a run that made no
request.

Then, separately, the egress capability question:

    php ops/loopia-probe/probe.php \
      --env=.env \
      --allow-network \
      --require-live-read \
      --probe-host=example.com

This answers *could this host perform a governed external read, if one were ever authorized*. It is
worth knowing now, because the answer decides whether a future road test is even possible on this
tier.

> **Running the probe does not activate live read.** `--allow-network` makes one request to a host
> you named, through no source card, no egress guard and no evidence spine. Demonstrating that live
> read *could* work is as far from activating it as reading the specification of a lock is from
> opening the door. Live read stays off; nothing in this repository constructs the transport.

`scheduler_transport_verdict` will be `UNVERIFIED`, and that is the finding rather than a gap in the
probe — see [`LOOPIA_HOST_MODEL.md`](./LOOPIA_HOST_MODEL.md) §4.

**Do not run this at the same time as Gate J.** The probe proves foreign keys, row locking and
triggers against disposable tables in the database `--env` names, and briefly holds a row lock; the
test harness truncates every table in its schema. Run them at their own gates, against their own
databases, and neither will ever see the other.

Save both reports. They become `LOOPIA_PROBE_001` evidence at Gate R.

## Gate I — Migrations

Against the **runtime** database:

    php ops/migrate.php status --env=.env
    php ops/migrate.php up     --env=.env
    php ops/migrate.php status --env=.env
    php ops/migrate.php up     --env=.env

**Pass requires**: all migrations applied; `drifted=0`; and the second `up` a clean no-op
(`applied=0`). Drift means the database and the repository disagree about the schema — a migration
was edited after being applied.

## Gate J — The strict suite, on the host

> **Against `.env.qualification`. Never against `.env`.**

    TEST_ENV_FILE=.env.qualification php tests/run.php --strict \
      --report-json=qualification-staging.json

**Pass requires** `fail=0` and `skip=0`, and a report with `"complete": true`.

This is the run that establishes the host can actually execute what CI says it can: shared hosting
differs in PHP configuration, extensions, filesystem behaviour and MariaDB privileges, and each of
those can invalidate an assumption that is green elsewhere.

Save the report. It becomes the `CONFORMANCE` evidence at Gate R.

## Gate K — Storage and filesystem proofs

Mostly covered by Gate H, which demonstrates each of these rather than inferring it from a version
string. Confirm from the probe report that all of these are `PRESENT`:

| Capability | Why it is load-bearing |
| --- | --- |
| `check_constraints_enforced` | a parsed-and-ignored CHECK leaves every storage invariant unenforced |
| `foreign_keys_enforced` | `900_deferred_foreign_keys` is the referential half of the schema |
| `trigger_support` | the append-only tables **are** triggers; without the privilege they import and vanish |
| `row_locking` | the job machine leases work by locking a row |
| `transaction_rollback` | a governed write that half-committed would release without its binding |
| `atomic_rename` | a reader must never observe a partial evidence payload |

Then confirm the append-only guarantee against the **runtime** database by hand, because this is the
one most likely to be lost in a dump/import round-trip:

    -- must FAIL with 'decision_records cannot be deleted'
    DELETE FROM decision_records LIMIT 1;

    -- must FAIL
    UPDATE workspaces SET external_side_effects_allowed = 1 LIMIT 1;

**STOP if** either succeeds. `SECURITY_BOUNDARY.md` §2 and §9 are not true of this deployment.

## Gate L — Provision one internal test tenant

Dry run first — it validates the arguments and the composition and writes nothing:

    php ops/provision-staging.php --env=.env --dry-run \
      --tenant-key=<tenant> --workspace-key=<workspace> \
      --display-name='<Tenant display name>' --operator-name='<Operator>' \
      --modules=P01,P02,P03,P04,P05

Then the same command without `--dry-run`.

`--tenant-key`, `--workspace-key` and `--display-name` are required; an unknown flag is fatal rather
than ignored, because a silently dropped flag is a provisioning decision an operator believes they
made. The provisioner **refuses `APP_ENV=production` before it opens the database**, and
that is not a bug to work around: the pinned canonical identity vocabulary defines no `CUSTOMER`
tenant type and no `PRODUCTION` environment class. Closed beta work is `TEST` identity in canonical
terms. See `LOOPIA_CAPABILITY_REPORT.md` §5.

## Gate M — Onboard one internal beta login

Dry run first. `--tenant-key`, `--workspace-key`, `--email` and `--display-name` are all required:

    php ops/onboard-beta-user.php --env=.env --dry-run \
      --tenant-key=<tenant> --workspace-key=<workspace> \
      --email=<address> --display-name='<Partner name>'

The dry run exits **before any write**, and it uses the read-only tier inspection rather than the
creating one — so a preflight cannot add a row to the platform's commercial catalogue and then
report that nothing was written.

Then for real, with the password **through stdin only**:

    read -rs PW; printf '%s\n' "$PW" | php ops/onboard-beta-user.php --env=.env \
      --tenant-key=<tenant> --workspace-key=<workspace> \
      --email=<address> --display-name='<Partner name>' --password-stdin

A password on argv is in `ps` and in the shell history file. `--password` is refused **by name**,
with the reason, rather than being ignored as an unknown flag.

**Pass requires** the account to exist with `must_change_password` set.

## Gate N — Staging smoke

Machine first:

    php ops/staging-smoke.php --base-url=https://staging.allfatherbi.com --mode=anonymous

    read -rs PW; read -rs NEW; printf '%s\n%s\n' "$PW" "$NEW" | php ops/staging-smoke.php \
      --base-url=https://staging.allfatherbi.com --mode=beta-journey \
      --email=<address> --password-stdin

**Pass requires** `"status": "SMOKE_PASS"` and `responses_without_request_reference: 0`.

Then by hand, in a browser, because a machine cannot tell you whether a screen makes sense:

sign in → forced password change → workspace chooser → React console → P01 → P02 → P03 → P04 → P05
→ M10 decision ledger → Help & support → sign out.

## Gate O — Security negative tests

By hand, against the deployed site. Each must be refused, and refused as the **right kind** of
refusal:

| Attempt | Required |
| --- | --- |
| read another tenant's data | denial, not an empty list |
| read a workspace you hold no membership in | denial |
| supply `?tenant_id=`/`?workspace_id=`/`?operator_id=`/`?environment_class=` | no effect at all on the answer |
| use a session after a password change | anonymous |
| use a session after an operator reset | anonymous, immediately |
| use a session after suspension | anonymous, immediately |
| sign in after reactivation with the old session | refused; only a fresh sign-in works |
| sign in after retirement | refused, terminally |

`tests/acceptance/beta_launch_journey.php` proves every one of these in-process. This gate proves
them through the real host, which is a different claim.

## Gate P — The request-ID support proof

1. Make a request to the deployed site and capture `X-Request-ID` from the response.
2. Over SSH, find **exactly that id** in the private request log.
3. Confirm the request log is **not** reachable over HTTP (Gate E.2 already probed
   `/storage/request.log`).

**Pass requires** all three. A reference a partner can quote and an operator cannot look up is worse
than no reference: it invites somebody to ask for it and leaves the person who receives it with
nothing to do.

## Gate Q — Rollback drill

Perform a real rollback to the previous known-good release and back, following
[`ops/rollback/README.md`](../ops/rollback/README.md). Then:

    php ops/rollback/verify-stand-down.php \
      --expect-release=<the restored SHA> \
      --email=<the internal test account> \
      --env=.env

**Pass requires** `"status": "STAND_DOWN_VERIFIED"`, with `m10_integrity.failed: 0`.

A rollback is never a delete. The governed history survives it, and that is what makes the drill
safe to actually run.

## Gate R — Record the evidence

**Only now** may an evidence-backed operational claim change.

For each activity: save its output, take its digest, add one record to `deployment-evidence.json` by
hand, and validate:

    sha256sum <artifact>
    php ops/evidence/validate.php

The records this sequence produces:

| Gate | Kind | Environment |
| --- | --- | --- |
| J | `CONFORMANCE` | `staging` |
| D+F | `STAGING_DEPLOYMENT` | `staging` |
| H | `LOOPIA_PROBE_001` | `staging` |
| E | `CROSS_SITE_ISOLATION` | `staging` |
| N | `STAGING_SMOKE` | `staging` |
| Q | `ROLLBACK_DRILL` | `staging` |

Then, as a **separate, reviewed** edit, `upstream.lock.json` may set `conformance_complete`,
`staging_deployed` and `loopia_capability_validated` — and only those, and only if
`php ops/evidence/validate.php` says the registry supports them. The foundation suite asserts the
implication on every commit, so a claim without a record fails the build.

See [`DEPLOYMENT_EVIDENCE_MODEL.md`](./DEPLOYMENT_EVIDENCE_MODEL.md) §6 for exactly what supports
what — including why `loopia_capability_validated` needs the deployment and the probe to name **the
same release**.

## Gate S — STOP

At this point, and only with the records to show for it, these may be said:

- staging is deployed on the real Loopia host
- the host is proven for the capabilities the probe demonstrated
- the beta application is qualified on that host

**And all of these remain false:**

    live_read_activated               = false
    road_test_001_executed            = false
    external_action_authorized        = false
    customer_visible_output_authorized = false
    owner_go_granted                  = false
    production_launch_authorized      = false

with `no_external_charge_executed` still true, the scheduler handler registry still empty, and no
partner invited.

**The partner beta has not started.** Inviting one is a decision with its own review, and it comes
after a road test that has not been prepared for here — PR8 prepares the ground for ROAD_TEST_001
and deliberately does not perform or enable one. The first change that constructs
`CurlHttpsTransport` or `DnsHostResolver` should be a small, separately reviewed commit, easy to
inspect precisely because all the qualification tooling already exists.

The strongest claim available after this runbook is:

> The repository is code-qualified and the Loopia staging host is deployed and qualified for inert
> operation.

Not "beta launched". Not "live intelligence active". Not "production ready".
