# LOOPIA_PROBE_001

Host capability probe, and the pre-secret cross-site isolation canary that runs before it.

**Plane:** Ops validation. Passing establishes what the host can do. It promotes no runtime
authority, activates no live read, and authorizes no deployment.

---

## 1. The fail-closed rule

Any load-bearing capability that cannot be **demonstrated** is reported `MISSING` or `NOT_PROBED`,
never assumed. Nothing is inferred from a version string: MariaDB calling itself 10.11 does not tell
you that this account may create a trigger, that this instance enforces foreign keys, or that row
locking behaves. Each is proved against disposable, randomly-named tables and cleaned up afterwards,
including when the proof itself failed.

**The disposable tables live in the database `--env` names, and briefly hold a row lock.** That is
unavoidable: a temporary table supports neither a foreign key nor a trigger, and is invisible to the
second connection the locking proof needs, so the proofs need real tables. The consequence is
operational rather than architectural — **do not run the probe and `tests/run.php` against the same
database at the same time.** The harness truncates every base table in its schema between suites,
and it would block on the probe's lock for the server's `innodb_lock_wait_timeout`. The runbook
sequences them at different gates and against different databases, which is why this is a note
rather than a defect.

## 2. Three verdicts, because there are three questions

Version 1 emitted one verdict, and it could read `CAPABILITIES_DEMONSTRATED` on a run where outbound
HTTPS was never probed. The subjects are answered by different evidence and gate different
decisions, so they are reported separately:

| Verdict | Asks | Gates |
| --- | --- | --- |
| `staging_host_verdict` | can this host run the application inertly? | the inert staging deployment |
| `live_read_host_verdict` | *could* this host perform a governed external read, if one were ever authorized? | nothing yet — live read is not activated |
| `scheduler_transport_verdict` | can a recurring tick actually reach this deployment? | nothing yet — the handler registry is empty |

Each takes one of four values:

| Value | Means |
| --- | --- |
| `READY` | every required capability was demonstrated |
| `MISSING_CAPABILITY` | at least one required capability is absent or only partly present |
| `NOT_QUALIFIED` | nothing is missing, but something required was not demonstrated in this run |
| `UNVERIFIED` | this probe cannot answer the question at all |

`MISSING_CAPABILITY` outranks `NOT_QUALIFIED`: a host known to lack something and a host nobody
asked are different answers, and the worse one has to be the one a reader sees.

### `scheduler_transport_verdict` is always `UNVERIFIED`, and that is the finding

Loopia documents neither which HTTP method the Kundzon scheduler uses when it visits a URL nor
whether a custom header can be set, and nothing measurable from inside PHP answers either. The probe
reports what it *can* establish — that the CLI door exists and `worker/tick.php` is present beside
it — and refuses to call the transport READY. That gap is `LOOPIA_SCHEDULER_TRANSPORT_BLOCKER` in
[`docs/LOOPIA_HOST_MODEL.md`](../../docs/LOOPIA_HOST_MODEL.md) §4 and is closed by one question to
Loopia support, never by this probe reporting READY and never by weakening the POST-only endpoint.

## 3. What is probed

### Runtime

- `php_version` — 8.2 or later
- `ext_pdo`
- `ext_pdo_mysql`
- `ext_curl`
- `ext_openssl`
- `ext_json`
- `ext_mbstring`
- `ext_hash`
- `password_argon2id` — preferred; bcrypt via `PASSWORD_DEFAULT` is an accepted fallback
- `aes_256_gcm` — encrypt/decrypt round-trip with AAD and tag, not merely listed as available
- `csprng`
- `max_execution_time`, `memory_limit`, `post_max_size`, `upload_max_filesize`, `date_timezone` — recorded
- `disable_functions` — recorded; the application uses no process-execution function
- `open_basedir` — **recorded as metadata, not as a finding.** On shared hosting a configured
  `open_basedir` is normal. The load-bearing question is whether the exact private-storage
  operations below succeed, and those are demonstrated on their own.

### Filesystem

- `private_storage_writable`
- `atomic_rename` — write to a same-directory temporary name, rename into place, **and read the
  payload back intact**. A rename that returned true onto a filesystem serving stale content would
  pass a rename check and fail the thing the rename exists for.
- `public_root_separation` — that `storage/` really is outside `public_html/`, checked as a resolved
  path relationship rather than as a directory that happens to exist

### Storage, demonstrated

- `mariadb` — reachable
- `mariadb_version_floor` — 10.6 or later, read back from the server
- `innodb_available`
- `check_constraints_enforced` — a write that violates a CHECK is refused
- `transaction_rollback` — a throwing transaction leaves nothing behind
- `foreign_keys_enforced` — a child row referencing an absent parent is refused
- `row_locking` — **two independent connections.** The holder takes `FOR UPDATE`; the contender sets
  a one-second `innodb_lock_wait_timeout`, blocks and times out; the holder rolls back; the
  contender then acquires. Both halves matter — a database that blocked forever would pass the first.
- `named_advisory_locking` — two independent connections prove `GET_LOCK(...,0)` acquisition,
  **two distinct locks held simultaneously by one session** (the Monitor nests its source lock inside
  its run lock), refusal to acquire while held, refusal by the non-owner to release it, owner release,
  and acquisition/release by the contender afterwards. This is the exact session-lock primitive used
  by the Monitor run journal and P05 source serialization, so no SQL transaction has to stay open
  across network I/O.
- `trigger_support` — a `BEFORE DELETE` trigger is created, the DELETE is refused **with SQLSTATE
  `45000`**, and the row survives. This is the capability most likely to be silently absent: the
  append-only guarantees in `SECURITY_BOUNDARY.md` §9 *are* triggers, and a MariaDB user without the
  TRIGGER privilege imports the migrations and drops them.

  The SQLSTATE is the proof, not the fact that the DELETE threw. An account that may create triggers
  but lacks `DELETE` also throws — SQLSTATE `42000`, *command denied* — and the row also survives,
  because nothing deleted it. The observed state is reported as `delete_sqlstate` so a privilege
  refusal is legible as what it is rather than as a passing trigger.
- `aes_256_gcm` — a real encrypt/decrypt round trip, guarded by the extension AND by each function
  existing, since `disable_functions` can remove one from a loaded extension. An unavailable OpenSSL
  makes this `MISSING` with the reason stated; it does not stop the report. A diagnostic that dies on
  the host it is diagnosing is no diagnostic, and this one used to: it recorded `ext_openssl: MISSING`
  and then called `openssl_get_cipher_methods()` anyway, taking a fatal before any JSON was emitted.
- `probe_cleanup` — every disposable table and trigger this probe created was dropped again. An
  account that can `CREATE` but not `DROP` passes every proof above and leaves `probe_*` litter in
  the database, which on a shared host is something an operator has to recognise and remove by hand
  while already reading a failure report. It is required for a READY staging verdict.

### Egress — only with `--allow-network`

- `curl_version`
- `curl_ssl`
- `curl_resolve_pinning` — `CURLOPT_RESOLVE`, without which the resolve-connect pin cannot be
  enforced and the DNS-rebinding window the egress guard closes is reopened
- `curl_timeout_ms`
- `curl_writefunction` — the streaming response-size cap
- `curl_protocols_restrictable` — confines the transport to https so a redirect cannot switch scheme
- `dns_function` — `dns_get_record` exists
- `dns_resolution` — a name was actually resolved, by **A or AAAA**: the governed resolver screens
  both, so an IPv6-only host is one this deployment can reach. Version 1 reported this PRESENT when
  the function merely existed, so "DNS works here" was recorded by runs that never resolved anything.
- `outbound_https` — a real TLS-verified request completed **over a direct connection**. The probe
  empties `CURLOPT_PROXY`, exactly as `CurlHttpsTransport` does: cURL otherwise reads `HTTPS_PROXY`
  and `ALL_PROXY` from the environment, and on a host where only proxy-mediated egress works a
  proxied probe would report PRESENT and yield a READY live-read verdict for a transport that will
  fail on its first request. A probe that demonstrates a path production cannot take has
  demonstrated nothing.

## 4. Running it

    # Default: no network, and the exit code follows staging readiness alone.
    php ops/loopia-probe/probe.php --env=.env

    # Egress capability, on the real host, against a host you choose.
    php ops/loopia-probe/probe.php --env=.env --allow-network --probe-host=example.com

    # Make a live-read verdict below READY a non-zero exit.
    php ops/loopia-probe/probe.php --env=.env --allow-network --require-live-read --probe-host=example.com

The default invocation is what CI runs, and on a healthy host it produces
`staging_host_verdict: READY`, `live_read_host_verdict: NOT_QUALIFIED` and exit 0 —
`NOT_QUALIFIED` because nothing was demonstrated, which is the honest answer for a run that made no
request.

`--require-live-read` without `--allow-network` is refused as a contradiction rather than answered
`NOT_QUALIFIED`, because that answer would look like a host finding and send the operator to the
wrong place.

**Running the probe does not activate live read.** `--allow-network` makes one request to a host of
the operator's choosing to show that outbound HTTPS works at all. It goes through no source card, no
egress guard and no evidence spine, and it writes nothing. Demonstrating that live read *could* work
is as far from activating it as reading the specification of a lock is from opening the door. What
activation takes is in `docs/DEPLOYMENT_RUNBOOK.md` §7a, and it is an owner act with its own review.

## 5. The pre-secret cross-site isolation canary

**Run this before any secret goes near the host.**

`APP_MASTER_KEY` and the database password cannot be placed on Loopia until it is known whether the
PHP running for one site in the account can read another site's private files. Answering that
afterwards means answering it too late: a secret that turns out to be readable from a sibling site
has already been exposed for as long as it took to find out.

So the canary is worthless by construction. Its content is the literal string
`ALLFATHER_LOOPIA_NON_SECRET_CANARY_<random hex>` and nothing else — there is no flag, no argument
and no environment read that could put a real credential in one.

    # On each site, in its own PRIVATE directory (a sibling of public_html, never inside it):
    php ops/loopia-probe/isolation-canary.php --mode=create  --dir=~/<domain>/storage

    # Then, as EACH site, against EVERY directory including its own:
    php ops/loopia-probe/isolation-canary.php --mode=probe   --dir=~/<other-domain>/storage

    # Afterwards, on each site:
    php ops/loopia-probe/isolation-canary.php --mode=cleanup --dir=~/<domain>/storage

Probe mode returns `READABLE`, `NOT_READABLE` or `NOT_FOUND` and **never prints content**. It always
exits 0 when the probe could be performed: this command cannot know whether the directory belongs to
the site running it, so an exit code would either fail the control or pass the crossing.

### The matrix to fill in

| Reader | Directory probed | Required |
| --- | --- | --- |
| staging site | its own private directory | `READABLE` |
| platform site | its own private directory | `READABLE` |
| staging site | platform's private directory | `NOT_READABLE` or `NOT_FOUND` |
| platform site | staging's private directory | `NOT_READABLE` or `NOT_FOUND` |
| presentation/other site | staging's private directory | `NOT_READABLE` or `NOT_FOUND` |

Probing a site's **own** canary is the control. Without it, a `NOT_FOUND` everywhere would look like
perfect isolation and could just mean nothing was ever written.

A probe against a directory this site cannot resolve at all answers `NOT_FOUND` with
`directory_resolved: false`, not an error. Under a restrictive `open_basedir` a sibling's directory
commonly cannot be resolved, and that **is** the finding — treating it as an operational failure
would make the matrix completable only on a host where isolation had already broken. In `create` and
`cleanup` mode an unresolvable directory stays a hard failure, because those act on a directory the
operator believes is their own.

**Any cross-site `READABLE` is a HARD STOP.** Do not place `APP_MASTER_KEY` or database credentials
on the host. Record the finding and raise it with Loopia before going further.

### What the matrix does not close

This runs under the CLI SAPI, as the SSH user. It measures **that** identity's filesystem
permissions and `open_basedir` — not the identity or restrictions the web PHP process runs under for
each site. On shared hosting those routinely differ, in both directions: the CLI account is often
more permissive than web PHP, and it can also be restricted where web PHP is not.

`docs/LOOPIA_HOST_MODEL.md` establishes no equivalence between the two, so this tool proves
**SSH/CLI cross-site filesystem isolation only**. Every result says so in its own output —
`identity_measured: SSH_CLI`, `sapi`, `proves`, and `web_php_cross_site_isolation: UNVERIFIED` — on
the reasoning that the one place a reader will look is the JSON they just pasted into a ticket.

Cross-site isolation of the **web PHP** identity therefore stays UNVERIFIED and is carried as an
open blocker in `LOOPIA_HOST_MODEL.md` §5. It is not closed by a green run here, and a completed
E.1 matrix must not be reported as closing it.

There is no web-SAPI equivalent, deliberately. One would need a file reader reachable over HTTP, and
this projection will not have one: `public_html/` is a closed set of four entries that
`tests/foundation/deployment_boundary.php` pins, and a file-reading endpoint is exactly the
arbitrary-read oracle the whole boundary exists to prevent. Closing the blocker takes a written
Loopia guarantee, or a host proof that does not require adding such an endpoint.

## 6. The HTTP boundary probe

Separate from the canary, and separate again from `tests/foundation/deployment_boundary.php`. The
repository test proves the **layout** — that nothing private sits inside the served tree. This proves
**Loopia's behaviour**: that the server actually refuses the paths the layout says it should. Apache
does not run in CI, and every `.htaccess` directive here is `<IfModule>`-guarded and therefore a
silent no-op on a host missing the module, so this is the only thing that establishes it.

Run from outside, against the deployed site, after the copy and before any secret — this is the same
check `docs/BETA_RUNBOOK.md` §E.2 requires, and its exit code is the result:

    ops/deploy/verify-e2-private-path-refusal.sh --base-url=https://<domain>

**403 or 404 are both correct. A 200 from any of them means the layout is wrong** — and the fix is
the layout, not the `.htaccess`. **A 503 means the path was rewritten to `index.php` instead of
being refused**, which is a different fault: the front controller resolves configuration before it
answers anything, including its own 404, so a private path and a typo were indistinguishable before
secrets exist. `public_html/.htaccess` refuses the private-directory list and the sensitive root
filenames above by request path, before that rewrite runs. See `docs/BETA_RUNBOOK.md` §E.2 for the
one accepted exception (`/.env` may 302 to Loopia's own `roadblock.loopia.com`, and only that host) —
the script checks it by exact host equality, never by a prefix of the `Location`.

## 7. Filing the result

The probe prints sanitized JSON and nothing else. Save it, take its digest, and record it as a
`LOOPIA_PROBE_001` record in `deployment-evidence.json` — see
[`docs/DEPLOYMENT_EVIDENCE_MODEL.md`](../../docs/DEPLOYMENT_EVIDENCE_MODEL.md) §7. A probe run in a
container is not a host record: only `environment: "staging"` or `"production"` can support
`loopia_capability_validated`, and that claim additionally needs a `STAGING_DEPLOYMENT` PASS for the
same release.
