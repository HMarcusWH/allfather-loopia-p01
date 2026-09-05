# Worker

Bounded, resumable, scheduler-invoked work. **No daemon and no persistent worker is assumed** — a
tick is one HTTP request or one cron process, and nothing here outlives it.

## The two doors

| Entry | Authority | Use when |
| --- | --- | --- |
| `worker/tick.php` | filesystem access to `.env` | Loopia's shell cron is available — **preferred**, because there is no credential on the wire to rotate, leak or misconfigure |
| `public/scheduler.php` | the `X-Scheduler-Token` header | the tick must be fired over HTTP |

`worker/tick.php` refuses to run under any SAPI but CLI. It lives outside the document root, but
"outside the document root" is a property of a deployment's configuration, not of the file — so the
file checks too.

`public/scheduler.php` is **POST only**. A tick advances governed work, and a GET that mutates is a
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
- **The step registry is closed and currently EMPTY.** `LIVE_READ_ACTIVATED` is false, so no
  handler that performs an external read is registered, and the internal steps are a separate act
  to wire. A claimed job whose state has no handler is FAILED by name
  (`STEP_HANDLER_NOT_REGISTERED`) rather than skipped: a skip would leave it claimed, leased and
  indistinguishable from work in progress.

So a tick against this projection today claims eligible jobs, fails them with that reason recorded
in the transition ledger, and exits. That is the honest behaviour of a machine whose capability
nobody has granted — not a placeholder.
