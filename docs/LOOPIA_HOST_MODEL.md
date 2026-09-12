# LOOPIA HOST MODEL

**Plane:** Ops. Official Loopia documentation is the authority for everything in this file.

What the target host actually is, established from Loopia's own documentation rather than from
what a Unix host usually looks like. Every claim below carries its source. Where Loopia does not
document something, this file says **NOT DOCUMENTED** and does not fill the gap with an assumption —
an assumed capability is the one that fails on the day of deployment.

Verified 2026-09-06 against the pages cited. Re-verify before staging: Loopia changes its offering,
and a runbook that was true a year ago is a runbook nobody checked.

---

## 1. What is established

| Fact | Source |
| --- | --- |
| **SSH exists**, on all three hosting tiers (Privat, Företag, Företag Plus) | [Webbhotell specifikationer](https://www.loopia.se/webbhotell/specifikationer/) |
| The SSH home directory **is the same directory FTP reaches** | [SSH i ditt Webbhotellskonto](https://support.loopia.se/wiki/ssh/) |
| Available over SSH: bash, git, PHP, Python, composer, rsync, zip, vim/nano | [SSH](https://support.loopia.se/wiki/ssh/) |
| **You cannot become root**, and the service is not for running your own server processes | [SSH](https://support.loopia.se/wiki/ssh/) |
| **Shell cron / crontab is NOT available.** Verbatim: *"Nej. För återkommande jobb (cronjobb) hänvisar vi i stället till schemaläggaren i Kundzonen."* | [SSH](https://support.loopia.se/wiki/ssh/) |
| Scheduled tasks are configured **in Kundzon**, under *Lägg till / skapa… > Cron-jobb* | [Cron](https://support.loopia.se/wiki/cron/) |
| The scheduler **visits a URL**. Verbatim: *"Med cron säger du åt vårt system att med jämna mellanrum besöka en viss webbsida (URL)."* | [Cron](https://support.loopia.se/wiki/cron/) |
| The scheduler **emails the result** to an address you give it | [Cron](https://support.loopia.se/wiki/cron/) |
| **Only one instance runs at a time**; a run that overruns its interval causes the next to be skipped | [Cron](https://support.loopia.se/wiki/cron/) |
| Intervals: daily, custom (documented in 5-minute increments), weekly, yearly | [Cron](https://support.loopia.se/wiki/cron/) |
| Web material goes in **`<domän>/public_html/`**, and Loopia **does not support pointing at any other application folder** | [Undermapp till applikationsfoldern](https://support.loopia.se/wiki/undermapp-till-applikationsfoldern/) |
| Files **above** `public_html/` are reachable by FTP/SSH and **not from the web** | [Undermapp till applikationsfoldern](https://support.loopia.se/wiki/undermapp-till-applikationsfoldern/) |
| PHP 8.2, 8.3, 8.4, 8.5 offered | [Specifikationer](https://www.loopia.se/webbhotell/specifikationer/) |
| **MariaDB 10.6** standard; **10.11 only on Företag Plus** | [Specifikationer](https://www.loopia.se/webbhotell/specifikationer/) |

## 2. What is NOT documented

These are open questions, not defaults. **Section 4 depends on them**, which is why they are listed
before the decision rather than discovered during it.

- **Which HTTP method the scheduler uses.** The page says it visits a URL. It never says GET or POST.
- **Whether custom request headers can be set.** Not mentioned anywhere.
- **Whether HTTP Basic Authentication is supported** (either as `user:pass@host` in the URL or as a
  configurable field). Not mentioned.
- **Whether a request body can be sent.** Not mentioned.
- **Whether a query string in the configured URL is preserved.** A query string is structurally part
  of a URL, so it is *probable* — but Loopia documents no parameter handling, and probable is not
  documented.
- **Timeout, response-size limit, and maximum frequency.** Not stated.

## 3. What this changes about the deployment

**The filesystem layout is Loopia's, and the repository now matches it.** The served directory in
this repository is named `public_html/` precisely so that the repository layout *is* the host
layout — deployment is a copy into `<domain>/`, with no rename, no shim and no symlink:

    <domain>/                     <- the SSH/FTP home for this domain
      app/  database/  ops/  worker/  storage/  vendor/  .env
      public_html/                <- THE ONLY web-served directory, and its name is fixed by Loopia
        index.php  scheduler.php  assets/  .htaccess

Everything private is a **sibling** of `public_html/`, not a parent of it. That is better than the
usual arrangement: the private files are not merely excluded from the served tree, they are outside
it, and `<domain>/` itself is never a document root.

**No symlink is proposed**, and none is needed. Loopia states it does not support pointing at any
folder other than `public_html/` under the domain folder, so a symlinked document root is not a
supported configuration to rely on.

**Shell cron is not claimed anywhere.** The previous runbook offered a crontab line. Loopia says
crontab is not available over SSH, so that line was wrong; it is gone.

**MariaDB 10.6 is the realistic floor, and it is verified.** 10.11 is a **Företag Plus** feature;
plain Företag is 10.6, so proving only 10.11 would have proved the tier a customer may not be on.
CI now runs the full strict suite against BOTH, as a matrix rather than two copied jobs, and each
job asserts the server version it actually got rather than the one it asked for. Demonstrated on
10.6.28: migrations apply and are idempotent, CHECK constraints ENFORCE, `SIGNAL SQLSTATE '45000'`
append-only triggers fire, transactional rollback works, and all seven layers pass with no skips.

## 4. Scheduler transport — decision table

The tick endpoint must be reachable by something, must never be an unauthenticated mutation, and
must never put a permanent reusable secret somewhere it will be logged or emailed. Loopia's own
scheduler emails the response body, which rules out anything that echoes a credential.

| # | Option | Security | Supported by Loopia? | Secret exposure | Replay risk | Recommendation |
| --- | --- | --- | --- | --- | --- | --- |
| A | POST + `X-Scheduler-Token` header | **Strong.** Secret never in a URL, never in a log, never in a Referer | **UNKNOWN** — method and header support are both undocumented | None | None (bounded, idempotent) | **Keep as the primary interface.** Works today from SSH, CI, or any POST-capable caller |
| B | HTTP Basic Auth | Moderate. Credential travels per request; TLS protects it in transit | **UNKNOWN** — not mentioned | Low, unless the URL carries `user:pass@` | None | Only if Loopia confirms support |
| C | Opaque high-entropy path capability (`/scheduler/<token>`) | Weak-to-moderate. The secret IS the URL | **Probable** — it is just a URL | **High.** In Kundzon config, in access logs, in the emailed result if the app echoes the path | None if bounded | Fallback only, with rotation and explicit risk acceptance |
| D | Query-string token | Weak. Same as C, and query strings are the most commonly logged and Referer-leaked part of a URL | **Probable but undocumented** | **High** | None if bounded | **Not recommended.** No advantage over C |
| E | GET endpoint with no credential | **Unacceptable** | Yes | N/A | Anyone who learns the URL drives the machine | **Refused.** Never create an unauthenticated mutation endpoint |
| F | **External scheduler over SSH** — a host you control runs `php <domain>/worker/tick.php`, or POSTs to option A | **Strongest.** Authority is SSH key possession; no HTTP credential exists to leak | **YES — SSH is documented on all tiers** | None | None | **Recommended** where an external scheduler is available |

### Conclusion — `LOOPIA_SCHEDULER_TRANSPORT_BLOCKER`

**Option A cannot be confirmed usable from Loopia's Kundzon scheduler on the documentation alone.**
Method and header support are both undocumented, and the current endpoint requires POST *and* a
custom header. It is very likely that Kundzon issues a plain GET with no headers, in which case the
endpoint answers 405 and the schedule silently does nothing — which the emailed result would show,
but only to whoever reads it.

**This is not resolved by weakening the endpoint.** The correct next step is one question to Loopia
support:

> *Vilken HTTP-metod använder schemaläggaren i Kundzonen när den besöker en URL — GET eller POST?
> Går det att skicka en egen HTTP-header (t.ex. `X-Scheduler-Token`), eller att använda HTTP Basic
> Authentication? Bevaras en query-sträng i den konfigurerade adressen?*

Until it is answered:

- **Option F is the recommended deployment**, and it needs nothing from Kundzon. An external
  scheduler reaches `worker/tick.php` over SSH; the authority is SSH key possession, so there is no
  HTTP credential to leak, rotate or misconfigure.
- **Option A stays the primary interface**, unweakened.
- **Option C is prepared but NOT enabled**, and enabling it is an operator decision that accepts a
  secret-in-URL risk in exchange for Kundzon compatibility.
- **Option E is refused outright** and no code path offers it.

### The probe will not close this, and says so

`LOOPIA_PROBE_001` reports `scheduler_transport_verdict` as **`UNVERIFIED`**, never `READY`, and
`tests/foundation/loopia_probe_contract.php` asserts that it cannot. What the probe can establish is
that the CLI door exists — it is itself running under the CLI SAPI on the host, with
`worker/tick.php` present beside it. What no probe can establish is which HTTP method Kundzon uses
or whether a custom header survives, because Loopia documents neither and nothing measurable from
inside PHP answers it. A probe that reported READY here would be closing a blocker by assertion.

The verdict block names the state rather than hiding it:

    "scheduler_transport_verdict": "UNVERIFIED",
    "scheduler_transport": {
      "cli_tick_present": true,
      "kundzon_http_method": "NOT_DOCUMENTED",
      "kundzon_custom_headers": "NOT_DOCUMENTED",
      "blocker": "LOOPIA_SCHEDULER_TRANSPORT_BLOCKER",
      "preferred_transport": "EXTERNAL_SCHEDULER_OVER_SSH_TO_WORKER_TICK",
      "scheduler_handlers_registered": false
    }

### Scheduler activation is not part of this phase

The last line above is why the blocker is not yet urgent. **The handler registry is EMPTY in both
composition roots** — `worker/tick.php` and `public_html/scheduler.php` each construct
`SchedulerRunner` with `[]` — so a tick that did arrive would correctly claim nothing, advance
nothing and report `SCHEDULER_INACTIVE`. That is a successful run with zero effect, and
`ops/deploy/preflight.php` and `ops/rollback/verify-stand-down.php` both check the registry is still
empty before and after a deployment.

Wiring a handler is a separate act with its own review, and it comes after real staging
qualification and a road test — neither of which has happened. **Scheduler activation is not part of
this deployment phase**, and the inert staging configuration therefore leaves `SCHEDULER_TOKEN` and
`SCHEDULER_TOKEN_HASH` blank: with neither set, every token is refused with
`SCHEDULER_CREDENTIAL_NOT_CONFIGURED` and the door is unreachable rather than open.

## 5. Open blockers before staging

1. **`LOOPIA_SCHEDULER_TRANSPORT_BLOCKER`** — the question above is unanswered. A Kundzon schedule
   may be unable to invoke the tick at all.
2. **Nothing has run on Loopia.** No deployment, no probe execution, no scheduled task. A container
   running the same MariaDB version is not the same as Loopia's instance of it — the version
   question is settled, the HOST question is not.

3. **Cross-site isolation of the SSH/CLI identity is unproved.** Whether the SSH user for one site
   can read another site's private files decides whether `APP_MASTER_KEY` and the database password
   may be placed there at all. `ops/loopia-probe/isolation-canary.php` exists to answer it with
   files that are worthless by construction, and it has never been run on a real host.

4. **`LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER` — cross-site isolation of the WEB PHP identity is
   unproved, and this repository has no tool that can prove it.** The canary in (3) runs under the
   CLI SAPI as the SSH user, so it measures that identity's permissions and `open_basedir`. The
   identity and restrictions the web PHP process runs under per site are a different thing, and on
   shared hosting they routinely differ in both directions — CLI more permissive than web PHP, or
   restricted where web PHP is not. **This document establishes no equivalence between them**, so a
   clean Gate E.1 matrix closes (3) and leaves this open.

   Unlike every other item here, this one has **no command with a pass condition**. A web-SAPI
   equivalent would need a file reader reachable over HTTP, which is precisely the arbitrary-read
   oracle `SECURITY_BOUNDARY.md` §2 exists to prevent, and `public_html/` is a closed set of four
   entries pinned by `tests/foundation/deployment_boundary.php`. Adding one to answer a security
   question would create the exposure it was meant to measure.

   It therefore closes only by **a written statement from Loopia** about per-site web PHP isolation
   in a shared account, or by a host proof that does not require adding such an endpoint. Until one
   of those exists it stays UNVERIFIED — never "probably fine because the CLI matrix was clean" —
   and `CROSS_SITE_ISOLATION` evidence records must not be read as covering it.

5. **`open_basedir` behaviour, the MariaDB account's TRIGGER privilege, foreign-key enforcement, row
   locking and outbound HTTPS are unproved ON LOOPIA.** The probe demonstrates each rather than
   inferring it from a version string, and has only ever run in CI and in development.

**Closed since the previous revision:** MariaDB 10.6 was listed here as untested. It is now verified
against a real 10.6 server in CI, on every commit, with no skips.

**What has changed about how these get closed:** blockers 1, 3 and 5 are now each a command with a
pass condition rather than an investigation. `docs/BETA_RUNBOOK.md` sequences them as Gates E and H,
and the results are filed as `CROSS_SITE_ISOLATION` and `LOOPIA_PROBE_001` records — which is what
makes `loopia_capability_validated` capable of becoming true, and what stops it becoming true
without them. Blocker 4 is the exception and is stated as one: it has no command, it is not closed
by running anything in this repository, and nothing in the evidence registry can record it as
closed.
