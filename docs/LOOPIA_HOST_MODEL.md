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

- **Option F is the recommended deployment**, and it needs nothing from Kundzon.
- **Option A stays the primary interface**, unweakened.
- **Option C is prepared but NOT enabled**, and enabling it is an operator decision that accepts a
  secret-in-URL risk in exchange for Kundzon compatibility.
- **Option E is refused outright** and no code path offers it.

## 5. Open blockers before staging

1. **`LOOPIA_SCHEDULER_TRANSPORT_BLOCKER`** — the question above is unanswered. A Kundzon schedule
   may be unable to invoke the tick at all.
2. **Nothing has run on Loopia.** No deployment, no probe execution, no scheduled task. A container
   running the same MariaDB version is not the same as Loopia's instance of it — the version
   question is settled, the HOST question is not.

**Closed since the previous revision:** MariaDB 10.6 was listed here as untested. It is now verified
against a real 10.6 server in CI, on every commit, with no skips.
