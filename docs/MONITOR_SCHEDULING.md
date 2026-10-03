# Monitor scheduling (completion plan P07)

**Status:** implemented and offline-tested. **No schedule is admitted anywhere**, this repository
ships no owner decision that could admit one, and nothing here is deployed. With no admitted schedule
both scheduler entry points construct exactly the empty-registry runner they always did.

A schedule turns "an operator runs the Monitor by hand" into "the Monitor observes on a cadence and
queues what changed for a human". It does **not** turn into autonomous inference or release: every
model call still needs a human to inspect the exact request, and both human reviews stay human.

## 1. Two owner acts, not one

| Record | Admitted by | What it permits |
| --- | --- | --- |
| `SCHEDULED_OBSERVATION` execution grant (`monitor_execution_grants`, P01) | an owner decision listing the grant digest | capture and compare for one frozen NATIVE mission configuration, one source, inside a window, up to a capture budget. **Zero model attempts** (`ExecutionGrantStore::CEILINGS`) |
| Monitor schedule version (`monitor_schedules`, migration 250) | an owner decision listing the **schedule digest** under `authorizes.monitor_schedules` | recurrence: the scheduler's SERVICE principal may start runs of that configuration on a cadence, under that grant |

Admitting the grant's budget does not admit a recurrence, and the reverse cannot happen: a schedule is
admitted only while its grant is ADMITTED, and every occurrence re-reads both decisions. A decision
must cite the canonical authority it relies on; canonical has no service-delegation contract today,
so an actual admission needs that upstream decision first (see §8).

## 2. Who acts

`MonitorPrincipal` types the actor on every Monitor act:

- **OPERATOR** — a human, exactly as before. Operator journal events are **v1**, byte for byte.
- **SERVICE** — a tenant-bound `SYSTEM_SCHEDULER` service identity acting under a **delegating
  operator**. The delegator is the grant's principal and must be the operator the frozen mission
  configuration names. Both are evaluated by M02 on every act — the service through its **own**
  workspace membership, the delegator through theirs — so delegation can never exceed the delegator's
  current authority. The service is recorded as the actor on M06 source requests and context reads.
  Its journal events are **v2**: `actor_operator_id` is NULL and `actor_service_identity_id` and
  `delegating_operator_id` are separate columns, with a v2 digest. A scheduled act can never read as
  the human's. v1 events and their digests are unchanged, and a run can mix both (the scheduler
  observes; the human takes over).

The service may: start the one run its admitted occurrence names (idempotency key
`schedule-occurrence:<occurrence id>`), and advance it through `NEW` (capture) and `CAPTURED`
(compare). Everything else refuses it before anything is prepared, read or sent: every model stage
(`MONITOR_STAGE_REQUIRES_HUMAN_OPERATOR`), both reviews (`MONITOR_REVIEW_REQUIRES_HUMAN_OPERATOR`),
routing, stop, list and the workbench. A composition without the schedule owner refuses every service
principal (`MONITOR_SERVICE_DELEGATION_NOT_ADMITTED`).

## 3. Schedules

Immutable versions plus append-only lifecycle (`ADMITTED`, `SUSPENDED`, `REVOKED`); `EXPIRED` and
`NOT_YET_VALID` are computed. A changed cadence, window, grant or cap is a successor version with its
own digest and admission; only one version of a series may be admitted. A new series is named by its
first version's reviewed input, so the digest `plan` shows is exactly the digest `propose` records,
and proposing the same first version again is idempotent.

Besides the CLI below, the scriptless console page `/console/monitor/schedule` (P09) can plan,
propose (bound to the digest the operator reviewed), suspend and revoke. It cannot admit: admission
stays the CLI act below.

| Bound | Rule |
| --- | --- |
| cadence | hourly floor and 31-day ceiling (also CHECK-pinned in storage), **and never below the source policy's own cadence floor** (card caching window or rate-limit floor, compiled by the same `SourcePolicy` capture uses). The floor is part of the definition, so a changed source policy forces re-review |
| window | inside the parent grant's window |
| occurrence cap | ≤ 500 and ≤ the grant's capture budget |
| source | one live series per monitored source per tenant (`SCHEDULE_SOURCE_ALREADY_SCHEDULED`); captures are also serialized by the existing source lock |
| mode | `OBSERVE_AND_QUEUE` only; storage has no value meaning unattended inference |

```text
php ops/monitor/schedule.php plan|propose --schedule=<definition.json>   ...scope flags
php ops/monitor/schedule.php admit --schedule-version-id=… --decision=docs/decisions/<file>.json --decision-sha256=…
php ops/monitor/schedule.php suspend|revoke --schedule-version-id=… --reason=<CODE>
php ops/monitor/schedule.php status --schedule-version-id=…
php ops/monitor/schedule.php queue
```

`ops/monitor/stand-down.php` (without `--grant-id`) now also suspends or revokes every live schedule in
the workspace, and `ops/rollback/verify-stand-down.php` reports any still-admitted schedule as a
finding.

## 4. Occurrences

Each tick, `ScheduleDispatcher` considers at most 20 admitted schedules and reserves **at most one
occurrence per schedule**, for the latest due slot. That is the whole catch-up policy (`LATEST_ONLY`):
a ten-day outage at a daily cadence yields one occurrence with `skipped_slots = 9`, never ten captures.
A slot is reserved only when, under a lock on the series:

- the schedule version is current (decision re-read) and its grant is ADMITTED;
- the slot is new — `(schedule, version, scheduled instant)` is also UNIQUE in storage;
- no earlier occurrence of the series is unfinished (non-overlap);
- fewer than three runs of the series wait unread in the human queue (`OPERATOR_QUEUE_FULL`);
- the schedule cap and the grant's capture budget both have room. A successor version does **not**
  refill the grant.

Reserving spends nothing. The capture unit is spent at capture time by the existing admission path,
under the source lock, after the schedule is re-checked once more.

## 5. The MONITOR_STEP job

A typed job with its own small graph (`JobMachine::TYPE_TRANSITIONS`), claimed only through a typed
registry key, so a legacy `PENDING` handler can never pick one up. It names one occurrence and nothing
else — no callback, SQL, URL or workflow definition (also CHECK-pinned).

```text
PENDING ──► WAITING_FOR_OPERATOR   run changed; waits at DIFFED, the request-inspection boundary
   │   └──► SETTLED                baseline, no change, blocked, or refused (schedule/grant gone)
   └──────► FAILED ──► (retry with backoff) ──► ABANDONED after 3 attempts
```

`WAITING_FOR_OPERATOR` is a **successful** resting state: the lease is released and nothing retries
it. A successful occurrence spends one attempt. A busy source hands the claim back unattempted. A
retry after a crash is safe: the run, the journal stage and the M06 capture are all idempotent on the
occurrence. Each occurrence's result is one append-only row in `monitor_schedule_occurrence_outcomes`.

## 6. Composition and the inert posture

```php
$runner = ScheduleDispatcher::admittedAnywhere($db, $clock, $root)
    ? ScheduleDispatcher::runner($db, $clock, $root, $env)
    : new SchedulerRunner(new JobMachine($db, $clock), []);
```

`admittedAnywhere()` reads the **deployed tree's** `docs/decisions/` — a decision anywhere else
admits nothing. `ScheduleDispatcher::runner()` registers only `MONITOR_STEP:PENDING` and
`MONITOR_STEP:FAILED`, through `MonitorFactory::forScheduledObservation()`, whose model transport is
always the inactive one. `ops/deploy/preflight.php` and `tests/foundation/no_external_action_surface.php`
pin this exact shape.

## 7. Invocation

The recommended route stays option F of [`LOOPIA_HOST_MODEL.md`](LOOPIA_HOST_MODEL.md) §4: an external
scheduler reaches `worker/tick.php` over SSH. [`ops/scheduler/invoke-remote.sh`](../ops/scheduler/invoke-remote.sh)
does that with a dedicated 0600 key, a pinned `known_hosts`, no agent or forwarding, and a fixed remote
command; it sends no work definition. **An SSH login on Loopia is account-wide, not site-isolated**,
and must not be described otherwise. The authenticated POST endpoint `public_html/scheduler.php`
remains as the alternative. Operational attention goes to the console queue
("Scheduled observations waiting for you" on `/console/monitor`, and `schedule.php queue`), not to a
new email or SMS channel.

## 8. Deliberate differences from the plan text, and what remains

- **Queued stage, not a precomputed request digest.** The plan says the scheduler queues "the exact
  next request". The exact request depends on the operator's current MANUAL_MONITOR grant and prompt
  approvals at inspection time; precomputing it from the scheduler would either produce an inert
  preview that is not the real request or require the scheduler to resolve model authority it does
  not have. The occurrence records the run, its revision and that it waits at `DIFFED`; the
  workbench computes and shows the exact request when the human opens it.
- **No new canonical authority.** `SCHEDULED_OBSERVATION` was already a local execution purpose (P01).
  Service delegation has no canonical contract; per the plan, that upstream decision must land before
  any real schedule is admitted. Nothing here pretends otherwise.
- `ContextEnvelope` and `MissionConfiguration` needed no change: the principal rides beside the
  operator scope, and both read the actor or the delegating operator they always read.
- Not done here: a qualified external scheduler identity on a real host, any admitted schedule, and
  bounded P08 audit jobs on the same machinery (they wait for an admitted scheduling policy).
- **Blocked upstream: provisioning the scheduler identity.** Every schedule names a tenant-bound
  `SYSTEM_SCHEDULER` service identity holding its **own** workspace membership. There is no governed
  operator path that creates that identity or its membership: nothing under `app/` or `ops/` writes
  `service_identities`, and only the offline fixture (`tests/support/ScheduleFixture.php`) inserts
  one. That path must not be invented locally. In particular, giving the service
  `ROLE_WORKSPACE_OPERATOR` (or any other existing role) is not a shortcut, because the role and exact
  permission set a scheduler may hold is what the pending upstream service-delegation decision has to
  fix. Until it lands, the code fails closed: no supported path can create the identity, so in any
  real database planning refuses (`SCHEDULE_SERVICE_IDENTITY_INVALID`, or
  `SCHEDULE_PRINCIPAL_NOT_AUTHORIZED` for an identity without its own authority) and nothing can be
  scheduled. Once the decision lands, two things become the **P07 merge gate**:
  1. a private plan → provision CLI for the identity and its membership, under that decision;
  2. a fixture-free end-to-end test: provisioned scope → grant → proposed schedule → owner-admitted →
     tick → exactly one occurrence → capture/diff → `WAITING_FOR_OPERATOR` at `DIFFED`. It must also
     show no model call, no auto-submitted request digest, no review, no release, no duplicate
     capture on repeated ticks, a stop on revocation and on expiry, and the service and its
     delegator recorded separately.

## 9. Tests

- [`tests/integration/monitor_schedule.php`](../tests/integration/monitor_schedule.php) — planning
  refusals, proposal vs admission, baseline, change queued at the boundary, the service refused every
  human surface, the human takeover with exactly one inspected model call, mixed v1/v2 replay,
  no-change, revocation and grant suspension, the CLI.
- [`tests/integration/monitor_schedule_budget.php`](../tests/integration/monitor_schedule_budget.php)
  — LATEST_ONLY catch-up, occurrence cap, no grant refill by a successor, the human-queue cap, expiry.
- [`tests/security/monitor_schedule_concurrency.php`](../tests/security/monitor_schedule_concurrency.php)
  — two-connection reservation and claim races, a held source lock, two series on one source, forged
  principals and delegators, a withdrawn decision, and storage pins on every new table and the jobs
  vocabulary.
- [`tests/integration/p07_migration_order.php`](../tests/integration/p07_migration_order.php) —
  migration 250 as an in-place upgrade, run in both MariaDB lanes (10.6 and 10.11). It builds the
  true pre-P07 projection: every migration except 250, so historical `900` is already applied and
  ledgered. It writes v1 runs and events exactly as the pre-P07 `RunJournal` did, plus legacy jobs,
  then runs the ordinary Migrator. It proves:
  - 250 alone applies, once, with its file digest; 900 is neither replayed nor drifted;
  - the historical v1 rows survive byte for byte and replay through the current journal with
    identical digests;
  - new v2 service events (including one appended to a v1 chain) replay, and the actor-version CHECK
    and the recreated freeze trigger hold;
  - over every job type × state × subject, the upgraded `jobs` table accepts exactly what it accepted
    before plus the five `MONITOR_STEP`-over-an-occurrence combinations;
  - a second pass is a no-op, and the upgraded schema and ledger equal a fresh install.

  The test always leaves the database at the full current schema.
