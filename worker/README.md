# Worker

Bounded, resumable, scheduler-invoked work. **No daemon and no persistent worker is assumed** — a
tick is one HTTP request or one cron process, and nothing here outlives it.

## The two doors

| Entry | Authority | Use when |
| --- | --- | --- |
| `worker/tick.php` | filesystem access to `.env` | an EXTERNAL scheduler can reach the host over SSH — **preferred**, because there is no credential on the wire to rotate, leak or misconfigure |
| `public_html/scheduler.php` | the `X-Scheduler-Token` header | the tick must be fired over HTTP |

**Loopia has no shell cron.** Verbatim from its SSH documentation: *"Nej. För återkommande jobb
(cronjobb) hänvisar vi i stället till schemaläggaren i Kundzonen."* SSH itself IS available on all
three tiers, so `worker/tick.php` is invoked by a scheduler running somewhere you control — not by a
crontab on the Loopia host, which does not exist.

Whether Loopia's own Kundzon scheduler can invoke the HTTP endpoint is **an open question**: it
visits a URL, and Loopia documents neither the HTTP method nor custom-header support. See
[`../docs/LOOPIA_HOST_MODEL.md`](../docs/LOOPIA_HOST_MODEL.md) §4 for the decision table and the
question to ask Loopia support. The endpoint is not weakened to fit an assumption.

`worker/tick.php` refuses to run under any SAPI but CLI. It lives outside the document root, but
"outside the document root" is a property of a deployment's configuration, not of the file — so the
file checks too.

`public_html/scheduler.php` is **POST only**. A tick advances governed work, and a GET that mutates is a
GET a crawler, prefetch or link preview will eventually perform.

## What a tick will and will not do

- **Bounded two ways**: a job count and a wall-clock budget, both clamped by the runner's own
  ceilings. The budget is checked BETWEEN jobs, so a tick always stops on a whole job with its
  lease released.
- **Overlap is safe and is not prevented.** Two ticks claim disjoint sets, because the claim is a
  conditional write the database arbitrates. A lock would add a way to deadlock the machine in
  exchange for a property the leases already give.
- **It accepts no work definition.** No job id, no job type, no step — the endpoint reads neither
  query nor body. A scheduler that accepted one would be a remote execution surface with a timer
  attached.
- **The step registry is closed, currently EMPTY, and it BOUNDS WHAT IS CLAIMED.** A job is
  claimed only when a handler is registered for its exact current state. The registry is read from
  the runner, never from the request, so bounding the claim by it cannot become a way for a caller
  to choose which work runs.
- **An inactive capability consumes nothing.** No lease, no attempt, no transition, no row touched.
  A tick with an empty registry is indistinguishable, from the jobs' point of view, from a tick
  that never happened — so a scheduler firing every fifteen minutes against a capability nobody has
  switched on still leaves every waiting job claimable on the day it is.

So a tick against this projection today claims nothing, reports `SCHEDULER_INACTIVE` with the empty
executable set that made it so, and exits 0. That is a successful run of a machine whose capability
nobody has granted — not a failure, and not a placeholder.

An earlier revision claimed those jobs and failed them for having no handler. It was honest about
what it was doing and still wrong: three ticks abandoned a monitoring job because live read was
off, discarding work nobody had asked to discard.
