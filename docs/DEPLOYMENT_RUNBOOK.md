# DEPLOYMENT RUNBOOK

Staging deployment, verification, and stand-down for the Loopia host.

**Read `LOOPIA_CAPABILITY_REPORT.md` §5 first.** Nothing in this runbook constitutes launch
authority, and the claim/authority flags remain false until their own evidence and owner acts exist.

---

## 1. What you are deploying

A PHP 8.2+ / MariaDB application with no external effect. It captures public web sources through a
guarded egress path and writes governed records. It sends nothing, publishes nothing, and performs
no consequential action — see `SECURITY_BOUNDARY.md` §1–§2 for why those are structural rather than
configured.

## 2. Prerequisites

| Requirement | Why it matters |
| --- | --- |
| PHP 8.2 or later | the code uses readonly promoted properties, enums, and `WeakMap` |
| extensions: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json` | `openssl` for credential encryption; `curl` with `CURLOPT_RESOLVE` for the pinned-address egress guard |
| MariaDB 10.5 or later | CHECK constraints must be ENFORCED, not parsed and ignored. **Loopia Företag is 10.6; 10.11 is Företag Plus only.** BOTH are verified: CI runs the full strict suite against real 10.6 and real 10.11, and the job asserts the server version it actually got rather than the one it asked for |
| `APP_MASTER_KEY` in server-private configuration | provider-credential encryption; never in the repository, never in the database. **Required at boot** in staging and production — a deployment without it refuses to start rather than failing at the first credential it seals |
| `APP_ENV` set correctly | a value outside `development`/`testing`/`staging`/`production` — including unset, empty or misspelled — resolves to **production**. Getting it wrong makes the deployment stricter, never looser |
| DB credentials via environment | `DB_HOST`/`DB_PORT` or `DB_SOCKET`, plus `DB_NAME`, `DB_USER`, `DB_PASSWORD` |

**Verify `CURLOPT_RESOLVE` exists before deploying.** Without it the egress guard cannot pin a
connection to the address it validated, and the guard's central property is gone:

    php -r 'echo defined("CURLOPT_RESOLVE") ? "ok" : "MISSING", PHP_EOL;'

## 3. Deploy

### 3.1 The layout — this is the security boundary

Established from Loopia's own documentation; sources and the open questions are in
[`LOOPIA_HOST_MODEL.md`](./LOOPIA_HOST_MODEL.md).

Loopia serves a domain from **`<domän>/public_html/`** and states it does not support pointing at
any other application folder. Files **above** `public_html/` are reachable over FTP/SSH and not from
the web. So the boundary is not a rule inside the served directory — it is that the private files
are that directory's **siblings**, and `<domain>/` itself is never a document root.

**The repository layout IS the host layout.** The served directory here is named `public_html/` for
exactly that reason: deployment is a copy into `<domain>/`, with no rename, no shim, no symlink.

    <domain>/                     <- the SSH/FTP home for this domain
      app/  database/  ops/  worker/  storage/  vendor/
      .env                        <- credentials, a sibling of the served directory
      public_html/                <- the ONLY web-served directory; the name is fixed by Loopia
        index.php  scheduler.php  assets/  .htaccess

#### Build and stage the Operator Console before copying

`public_html/assets/operator-console/` is generated deployment output and is intentionally
Git-ignored. A fresh checkout therefore does **not** contain the authenticated React bundle until
the frontend build is validated and staged. The qualified frontend toolchain for this PR is
**Node v22.16.0 / npm 10.9.2**.

Run this from the repository checkout **before** `rsync`:

    cd frontend/console
    node --version    # expect v22.16.0
    npm --version     # expect 10.9.2
    npm ci
    npm test
    npm run build
    npm run stage
    cd ../..
    test -f public_html/assets/operator-console/manifest.json

`npm run stage` validates the private build output and publishes only the reviewed content-hashed
JS/CSS/SVG set into `public_html/assets/operator-console/`, writing `manifest.json` last. The staged
directory is then published separately from the whole-tree deployment below so the application's
`rsync --delete` can never remove a still-referenced content hash during a release.

If this stage is absent or invalid, `/console/app` returns **503 by design** rather than guessing a
bundle filename. Do not work around that refusal by committing generated assets or by adding an
unhashed fallback; rebuild and stage the deployment working tree instead.

#### Deploy the application tree

**Deploy over SSH** (available on all three tiers), from a checkout on your own machine. The general
deployment deliberately excludes the Operator Console asset directory; that subtree has its own
manifest-last publish step immediately below.

    rsync -av --delete \
      --exclude '.git' \
      --exclude 'tests' \
      --exclude '.env' \
      --exclude 'frontend/console/node_modules' \
      --exclude 'frontend/console/dist' \
      --exclude 'public_html/assets/operator-console' \
      ./ <user>@<host>:~/<domain>/

`rsync` does not honor `.gitignore`. The private frontend dependency tree and private `dist/` build
therefore stay explicitly excluded. The staged Operator Console subtree is also excluded here for a
different reason: the root `--delete` must not prune the previous content-hashed bundle while an old
manifest or already-served HTML can still refer to it.

#### Publish Operator Console assets manifest-last

Publish the content-hashed files first **without `--delete`**, then publish the manifest to a
same-directory temporary name and replace the live manifest with one remote `mv`:

    ssh <user>@<host> 'mkdir -p ~/<domain>/public_html/assets/operator-console'

    rsync -av \
      --exclude 'manifest.json' \
      public_html/assets/operator-console/ \
      <user>@<host>:~/<domain>/public_html/assets/operator-console/

    rsync -av \
      public_html/assets/operator-console/manifest.json \
      <user>@<host>:~/<domain>/public_html/assets/operator-console/manifest.json.next

    ssh <user>@<host> \
      'test -f ~/<domain>/public_html/assets/operator-console/manifest.json.next && \
       mv ~/<domain>/public_html/assets/operator-console/manifest.json.next \
          ~/<domain>/public_html/assets/operator-console/manifest.json'

This ordering is the deployment invariant: **old manifest + old hashes remain valid; new hashes are
copied; only then does the manifest switch to the new hashes.** The final `mv` is within one remote
directory/filesystem, so consumers never observe a partially copied manifest. If either asset copy
fails, the old live manifest remains untouched and still names files that were not deleted.

Old content-hashed assets are deliberately retained during the deployment. Do **not** add `--delete`
to the asset publish step and do not prune old hashes in the same release. Cleanup is a separate,
later maintenance act after the new deployment has been verified and any previously served page has
had time to age out.

`.env` is excluded deliberately and uploaded once, by hand, with its own permissions. A deploy that
overwrites configuration is a deploy that can silently change which database it points at.

**Do not symlink a separate directory into the document root.** Loopia states it does not support
an application folder other than `public_html/` under the domain folder, so a symlinked root is not
a supported configuration to rely on.

**Verify the boundary after deploying.** Each of these must NOT return the file:

    curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/.env
    curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/app/Support/Env.php
    curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/database/migrations/010_governance_core.sql
    curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/storage/scheduler.log
    curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/worker/tick.php
    curl -sS -o /dev/null -w '%{http_code}\n' https://<domain>/ops/migrate.php

403 or 404 are both correct. **A 200 from any of them means the layout is wrong** — and the fix is
the layout, not the `.htaccess`. Those files are defence in depth: every directive is
`<IfModule>`-guarded, so on a host missing the module each is a silent no-op.

### 3.2 Configuration fails closed

`.env` is production's file and `.env.testing` is a development convenience. A staging or
production deployment configured from the test file **refuses to start**, so a host whose `.env` is
missing or unreadable cannot silently run on test credentials. So does a deployment with no
configuration file at all — an empty configuration would answer every default as though somebody
had chosen it.

Staging and production additionally require `DB_NAME`, `DB_USER` and an `APP_MASTER_KEY` that
decodes to 32 bytes, all checked at boot. A refusal names the missing keys and nothing else; the
key names are public (they are in `.env.example`) and their values never travel with them.

`DB_PASSWORD` is deliberately not required: a socket-authenticated MariaDB user legitimately has
none.

A refusal looks like this, and is the only thing the deployment will say until it is fixed:

    {"status":"MISCONFIGURED","detail":"refusing to start in production: required configuration missing: APP_MASTER_KEY"}

### 3.3 Install

    git clone <repo> && cd allfather-loopia-p01
    cp .env.example .env && edit .env          # DB_*, APP_MASTER_KEY, SCHEDULER_TOKEN_HASH
    php ops/migrate.php status --env=.env      # report only, changes nothing
    php ops/migrate.php up --env=.env          # apply

Loopia **does provide an SSH shell** for web-hosting accounts, so migrations and other one-shot CLI
maintenance can be run over SSH. What Loopia does not provide is root, a persistent custom daemon,
or shell cron for recurring work; recurring jobs are directed to the Kundzon scheduler. The
migrator never runs implicitly on a web request.

The migrator records a content digest per migration and reports each as APPLIED, PENDING or
DRIFTED. DRIFTED means the file changed after it was applied — the database and the repository
disagree about history, which is a condition for a human rather than for automation. Resolve it by
hand; do not re-run and hope.

## 4. Verify the deployment — do not skip this

### 4.1 The suite

    php tests/run.php --strict

Expect **3325 passing, 0 failing, 0 skipped** at this repair head. Under `--strict` a skip is a
non-zero exit, so an unreachable database cannot be mistaken for a green run.

To run against a DIFFERENT server than the one `.env.testing` names — a second MariaDB version, a
staging database — set `TEST_ENV_FILE` rather than editing `.env.testing` in place:

    TEST_ENV_FILE=.env.mariadb106 php tests/run.php --strict

The runner has no `--env` flag of its own; passing one is silently ignored and the run connects to
whatever `.env.testing` names, which is how a "10.6 run" can report a clean pass without ever having
touched 10.6. `TEST_ENV_FILE` wins over both conventional files and throws if the file is missing.

### 4.2 CHECK constraints are ENFORCED

MariaDB before 10.2.1 parsed CHECK constraints and ignored them, and several of this system's
guarantees are CHECK constraints. Confirm the storage actually refuses:

    -- must FAIL
    UPDATE workspaces SET external_side_effects_allowed = 1 LIMIT 1;

If that succeeds, **stop**. The external-effect boundary is not being enforced, and
`SECURITY_BOUNDARY.md` §2 is not true of this deployment.

### 4.3 Append-only triggers survived the import

This is the check most likely to fail on shared hosting, because `DELIMITER`-defined triggers are
routinely lost in dump/import round-trips:

    SELECT COUNT(*) FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE();

Then confirm one actually fires:

    -- must FAIL with 'decision_records cannot be deleted'
    DELETE FROM decision_records LIMIT 1;

If the triggers are missing, the append-only guarantees in `SECURITY_BOUNDARY.md` §7 do not hold and
rollback discipline (§6 below) is unenforceable. Re-apply the migrations rather than patching by
hand.

### 4.4 Egress denies what it should

The guard's refusals are unit-tested, but confirm the deployed configuration reaches the same
answer for a private address before trusting it with a real source.

## 5. Provision a tenant

One command, which composes the real module registrations and decides nothing itself:

    php ops/provision-staging.php \
      --tenant-key=acme --workspace-key=main \
      --display-name="Acme AB" --operator-name="Ops" \
      [--modules=P01,P02,P03,P04,P05] [--env=path] [--dry-run]

It prints the tenant, workspace and operator ids and, per module, the Valkyrie, contract, governed
tool and policy set it established. Run `--dry-run` first: it validates the arguments and the
configuration and writes nothing.

**What each module registration establishes** (its own decisions, made by the module):

| Plane | What is established |
| --- | --- |
| M03 | the capability's Valkyrie, its contract, and the forbidden-action clauses recorded on it |
| M06 | the governed tool and its operation — platform rows, converged on if they already exist |
| M03 | the per-contract tool right, which is where per-tenant authority actually lives |
| M08 | the module's admission policy set, workspace-scoped |
| M16 | the tenant's output visibility profiles (P01 registration; internal permitted, every wider audience seeded explicitly as not permitted) |
| Entitlement | the commercial tiers — platform rows, created once per deployment |

**The authority vocabulary is seeded first.** Permissions and roles are GLOBAL — the shared
vocabulary every membership resolves against, not tenant-owned data — so `M02\Seed` runs before the
module registrations and reports how many of each it created. It is idempotent, ported verbatim from
canonical, and structurally unable to create an excluded permission. Before it was wired here, a
freshly provisioned deployment had no roles at all, and granting one meant writing SQL or inventing
a role: the two things this projection exists to make unnecessary. Seeding creates no authority — a
role is inert until a membership grants it.

**What the command itself writes:** the tenant, workspace and operator rows, and nothing else. Those
are the scope the capabilities are registered INTO; no module owns them and they must exist first.
It issues no UPDATE or DELETE at all, refuses an unrecognised argument rather than ignoring it, and
refuses to run under any web SAPI before it connects to anything.

**Production provisioning deliberately refuses before DB access.** The pinned canonical identity
schema does not define `CUSTOMER` tenant/workspace types or a `PRODUCTION` environment class. This
projection therefore uses canonical `TEST` identity vocabulary for staging/testing and does not
promote new Core words merely to get a row through a CHECK constraint. Production customer identity
and onboarding require an explicit canonical decision first.

**It fails closed and says how far it got.** The first module refusal stops the run and reports
which module refused, why, and what was provisioned before it — because a half-provisioned tenant is
a real state and implying a rollback that did not happen would be worse than reporting the truth.
Exit codes: 2 bad arguments, 3 database unreachable, 4 tenant key already exists (pass
`--allow-existing` only if minting a second set of per-tenant Valkyries is what you intend), 5 a
module refused, **6 production provisioning refused because the pinned canonical identity
vocabulary has no production/customer classes**.

**P02 became provisionable** when its governed lifecycle was ported. The provisioner registers the
ENTITY-twin curator capability: curator, contract, read tool, `P02_TWIN_STATE_ADMISSION` policy set
and tiers. The entity **and relationship** runtime lifecycles are present, including governed
state-change admission, M10 release and M16 snapshot composition; the relationship path is not a
separate registration or browser surface. Offering twins remain excluded by the upstream gate.
See [`PORT_SCOPE_MATRIX.md`](./PORT_SCOPE_MATRIX.md) §3.7.

**The P02 audit profiles are a separate operator step.** `TwinAssurance::seedProfiles($tenantId,
$workspaceId)` creates the twelve, and provisioning does not call it — for the same reason it does
not seed P01's, P04's or P05's: audit profiles are assurance, not registration, and folding them
into the registration act would make one call do two different jobs. Seeding is idempotent per
scope.

**Provisioning is not activation.** It establishes capability, never authority to use it against the
outside world: `live_read_activated` and `external_action_authorized` are restated as false on every
successful run.

## 5a. Onboard a beta partner

Provisioning establishes the tenant. Onboarding gives one invited person a way in. One command, and
no hand-written SQL at any step:

    printf '%s\n' "$INITIAL_PASSWORD" | php ops/onboard-beta-user.php \
      --tenant-key=acme --workspace-key=main \
      --email=partner@example.com --display-name="A Partner" --operator-name="A Partner" \
      --roles=ROLE_COMPETITION_MONITOR,ROLE_MARKETING_CUSTOMIZER \
      --password-stdin [--env=path] [--dry-run]

It creates the governed operator if there is none, ensures the `LOOPIA_SME_BETA` tier exists exactly
as declared, subscribes the tenant to it as a `TRIAL`, creates the platform login, grants the named
EXISTING roles, and marks an initial password change required. Run `--dry-run` first: it validates
the scope, the role keys and the tier definition and writes nothing.

**The password never touches the command line.** `--password` is refused by name, with the reason:
anything on argv is in the process list and in the shell history file. Use `--password-stdin`, or
omit it and answer the prompt, which suppresses terminal echo and asks twice. The value appears in
no output, no log and no JSON result.

**It grants only what already exists.** A role key that is not an ACTIVE role is refused rather than
created — checked before anything is written, so an unknown role key cannot leave a half-onboarded
partner. `--roles` names roles from the canonical M02 set seeded in step 5.

**The beta tier is exactly six read permissions** — `battlecard.read`, `entity_twin.read`,
`marketing_asset.read`, `priority_outreach_queue.read`, `prospect.read`, `role_briefing.read` — at
zero charge. A tier already present under that key with a different permission set, status or price
is REFUSED rather than reused or repaired. A tenant already on a different live subscription is
refused too; `--replace-live-subscription` transitions the old one to `CANCELLED` transactionally,
and is the only way to do it.

**The two gates stay independent.** The tier is a commercial gate in front of M02, never a second
authority: a partner whose role lacks a permission is denied whatever the tier says, and a tenant
whose tier lacks one is denied whatever the role says.

## 5b. Support a beta partner

    php ops/manage-beta-user.php <command> --email=partner@example.com \
      [--tenant-key=acme] [--password-stdin] [--reason=...] [--env=path]

| Command | Effect |
| --- | --- |
| `inspect` | sanitized account state, memberships and commercial state |
| `suspend` | stops sign-in; every live session ends in the same transaction |
| `reactivate` | returns a suspended or locked account to service; old sessions stay revoked |
| `reset-password` | issues a replacement password and requires the holder to change it again |
| `revoke-sessions` | ends every live session, leaving the lifecycle state alone |
| `retire` | terminal — no transition out of it exists |

`--tenant-key` is a SCOPE, not a filter: an address outside the named tenant reports exactly as an
address that does not exist, so the command cannot be used to ask whether somebody has a login
elsewhere in the deployment. Every command emits identifiers, lifecycle states, counts and
timestamps — never a password, hash, algorithm, session token, CSRF token or provider credential.

**A partner's first sign-in lands on `/account/password`.** The redirect is a courtesy; the
enforcement is a Kernel gate between routing and dispatch, so `/console`, `/console/app`, the
workspace routes and every JSON read refuse until the password is replaced — the JSON reads with a
distinct `PASSWORD_CHANGE_REQUIRED` status and no data envelope at all.

**Every response carries `X-Request-ID`**, an opaque 22-character reference that encodes nothing
about the tenant, workspace, user, address or time. It is shown on the authenticated `/support` page
and on safe error pages, so a partner can report a failure by quoting it rather than describing it.
Set `SUPPORT_CONTACT_TEXT` in `.env` or the support page will say no contact is configured; set
`APP_RELEASE_REF` to the deployed SHA so a report and a build agree about which code produced it.

Afterwards, grant the operator any further roles and workspace memberships, and confirm the tenant's
tier — a role grant is not a purchase, and both gates are checked independently at every surface.

## 6. Stand-down and rollback

**Rollback is never a delete.** The storage refuses one, and the reason is that a rollback which
destroyed the record would also destroy the proof the rollback happened. Five levers, in
increasing order of scope:

| Lever | Effect | Reversible? |
| --- | --- | --- |
| `M16::deactivateVisibility` | removes an artifact from the surface | yes, by a new activation |
| `EvidenceSpine::setSourceStatus(..., 'REVOKED')` | stops NEW captures immediately; existing evidence stays valid as history | yes, by re-activating |
| `OutreachArtifacts::revokeAuthorization` | an outstanding permission to act can never be consumed | no — issue a new one |
| `DecisionLedger::supersede` | withdraws reliance on a release; the original stays byte-intact | no — restoring reliance is a NEW decision |
| `M11::openIncident` | records that something needs looking at | resolved, not deleted |

A CONSUMED authorization is **not** revocable. What it authorized already happened, and that is
historical fact — recorded truthfully, never un-recorded.

After any stand-down, verify the ledger still verifies:

    php -r '... DecisionLedger::verifyStream($streamId) ...'   // expect status PASS

The chain must verify WITH the correction in it. That is what makes the withdrawal provable rather
than merely performed.

## 6a. The scheduler tick

Loopia gives no daemon **and no shell cron** — verbatim from its SSH page: *"Nej. För återkommande
jobb (cronjobb) hänvisar vi i stället till schemaläggaren i Kundzonen."* This is distinct from SSH
shell access, which Loopia does provide. An earlier draft of this runbook offered a Loopia crontab
line. That was wrong and is gone.

The full capability analysis and the decision table are in
[`LOOPIA_HOST_MODEL.md`](./LOOPIA_HOST_MODEL.md) §4. In short:

**Available fallback — an external scheduler over SSH.** If an external scheduler is deliberately
chosen, a host you control can run the tick. Authority is SSH key possession; this is an option, not
an assumption or a requirement silently added to the Loopia-only deployment:

    */15 * * * * ssh <user>@<host> 'php ~/<domain>/worker/tick.php' >> /var/log/allfather-tick.log 2>&1

or, equivalently, a POST from anywhere that can make one:

    */15 * * * * curl -fsS -X POST -H "X-Scheduler-Token: $TOKEN" https://<domain>/scheduler.php

**Kundzon's own scheduler is BLOCKED pending one answer from Loopia support.** It visits a URL, and
Loopia documents neither the HTTP method nor whether custom headers can be set. The endpoint here is
POST-only and requires `X-Scheduler-Token`, so a plain GET with no headers would get 405 and the
schedule would silently do nothing. **Do not weaken the endpoint to fit an assumption.** The
question to ask is written out in `LOOPIA_HOST_MODEL.md` §4.

Kundzon also **emails the response body**, so nothing this endpoint returns may carry a credential —
it does not, and that is a property to preserve if the response shape ever changes.

**Token handling.** Generate it, store only its digest, keep the token in the caller:

    php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=") . PHP_EOL;'
    # the sha256 of that value goes in .env as SCHEDULER_TOKEN_HASH

The token goes in a HEADER, never a query string: a scheduler's configured URL sits in a control
panel, in access logs, and in any Referer it generates. `SCHEDULER_TOKEN_HASH` is preferred over
`SCHEDULER_TOKEN` because a read of `.env` then yields nothing presentable; if both are set the
digest wins, so a rotation cannot be undone by a stale plaintext value left beside it.

**An unconfigured scheduler is unreachable, not open.** With neither key set, every token is refused
with `SCHEDULER_CREDENTIAL_NOT_CONFIGURED`.

**What a tick does today.** The step registry is closed and EMPTY: `LIVE_READ_ACTIVATED` is false,
so no handler performing an external read is registered, and the internal steps are a separate act
to wire. The registry BOUNDS THE CLAIM, so a tick today claims nothing and reports:

    {"status":"OK","claimed":0,"advanced":0,"failed":0,"released":0,
     "executable_states":[],"stopped_reason":"SCHEDULER_INACTIVE"}

That is a successful run with zero effect. No lease is taken, no attempt is consumed, no transition
is written — so a scheduler firing every fifteen minutes against an inactive capability leaves every
waiting job exactly as claimable on the day it is switched on. **Do not read a quiet log as work
being done**, and do not read `SCHEDULER_INACTIVE` as a fault.

## 7. Monitoring

Run the audits on a schedule; each opens an incident through the certified M11 intake on failure:

| Audit | Asks |
| --- | --- |
| P01 source freshness | is the authority behind this source still current? |
| P01 diff integrity | does the recorded change still reconcile with its snapshots? |
| P04 distribution authority | is every released briefing still routed to a configured recipient? |
| P04 redaction policy | does each frozen profile still cover its role's current rules? |
| P04 stakeholder provenance + no-covert-telemetry | role-class only, individual-free, public-sourced |
| P05 evidence citation, draft-only, content compliance | every comparative claim cited; nothing external; no forbidden copy |
| P07-A × 5 | reconciliation, consent integrity, content, PII firewall, **external-effect guard** |
| M11 ledger integrity | does the decision chain still verify? |

The external-effect guard is the one to alert on loudest. It is S5, and a positive finding means
the deployment did something this codebase has no path to do.

## 7a. ROAD_TEST_001 — operator-assisted, and it cannot be otherwise

A road test is something an operator does WITH this build. It is not something the build does, and
the difference is structural rather than procedural.

**What this build cannot do on its own:**

- **It cannot fetch anything.** `CurlHttpsTransport` and `DnsHostResolver` exist, are reviewed and
  are tested — and nothing constructs either. The default transport refuses every request with
  `LIVE_READ_NOT_ACTIVATED`. `tests/foundation/no_external_action_surface.php` asserts that no file
  in the application, the entry points or the worker wires them, so activating live read is an
  owner act with its own review rather than a line that could arrive in a refactor.
- **It cannot record that a road test happened.** The claim-boundary flags live in
  `upstream.lock.json`, which no code path writes. `road_test_001_executed`,
  `live_read_activated`, `owner_go_granted` and `production_launch_authorized` are a human's to
  change, and the foundation suite fails if any of them is true.
- **It cannot approve itself.** The P07-A approval seat requires a human principal; there is no
  service actor that can occupy it.

**What an operator supplies, in order:**

1. **A real source card, reviewed and approved** — a public page whose robots and terms have
   actually been read by a person, recorded through `P01\SourceCards::project`. The card is the
   authority; nothing downstream can manufacture one.
2. **A decision to activate live read**, recorded before anything is wired. This is the owner act
   the two unwired classes exist to make visible.
3. **The wiring itself**: construct `CurlHttpsTransport` and `DnsHostResolver` and pass them to
   `P01\Capture::captureMonitoredSource`. They are constructor arguments precisely so this is one
   reviewable line at a call site rather than a default buried in a factory.
4. **A capture**, then **a second capture** after the source changes, so change detection has two
   snapshots to reconcile.
5. **A reading of what it found** — including anything the change detector classified badly. A road
   test whose findings are not read is a deployment rehearsal, not a road test.

**What to record afterwards**, in `upstream.lock.json` and by hand:

| Flag | Set true when |
| --- | --- |
| `live_read_activated` | the transport was wired and a real fetch occurred |
| `road_test_001_executed` | steps 1–5 completed and the findings were read |
| `loopia_capability_validated` | the probe ran on the actual host, not a container |
| `staging_deployed` | the code is on the Loopia staging host |

`owner_go_granted` and `production_launch_authorized` are separate from all of the above and depend
on nothing in this file. **Build completion is not launch authority**, and a green suite is
evidence for a decision somebody else makes.

## 8. Known untested surface

Stated so nobody assumes otherwise:

- **This has never run on Loopia shared hosting.** Development and CI verify PHP/MariaDB behavior,
  but §4.2 and §4.3 exist precisely because the real shared host can differ in configuration,
  privileges and import behavior.
- Hosted CI runs the full suite on PHP 8.2.33 — the supported floor — against **both MariaDB
  10.6.28 and 10.11.19**, with each server version read back and asserted before the suite runs.
- No road test has been executed. No live source has been captured outside the test suite.
