# MVP release state

**The one place to read the current state.** It answers four questions separately, because they
have different answers and different evidence, and running them together is how a candidate gets
described as deployed or a deployment gets described as activated:

| Question | Current answer | Evidence |
| --- | --- | --- |
| **Source** — what application code is on `main`? | the PR18–PR22 Monitor work, last reviewed application merge `8dbea16` | Git history; [Monitor delivery](MONITOR_DELIVERY_AND_REMAINING_WORK_2026_09_27.md) |
| **Tested** — which exact commit was qualified, and how? | from this change on: a receipt from `ops/ci/qualify-release.sh` per commit. Latest registry-backed qualification: PR16 | §2; `deployment-evidence.json` |
| **Deployed** — which application runs on staging? | PR16 `392f48de`, inert. **None of the Monitor work is deployed** | §3; [Gate R filing](STAGING_GATE_R_EVIDENCE_2026-09-26.md) |
| **Activated** — what may run against the world? | nothing: no live read, no native model call, no scheduler handler, no external action | §4; `upstream.lock.json` |

Update this file in the same change that alters any of the four answers.
`tests/foundation/release_state_currentness.php` fails the build when the values in the block below
stop matching `upstream.lock.json`, `deployment-evidence.json` and `database/migrations/`.

```text
canonical_pin                      = c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
last_reviewed_application_merge    = 8dbea16b262df7b6d40fe945f34e301eedf2aba6
deployed_staging_release           = 392f48deef11380f6dccfe88f2a47ba1fd94575c
evidence_records                   = 12
candidate_migrations               = 25
```

## What the words mean here

| Word | Means | Does not mean |
| --- | --- | --- |
| **implemented** | the code is merged on `main` | that anything ran it outside a test |
| **qualified** | a complete strict run of every workflow step for that exact commit — hosted CI, or a local receipt that says it is local | host proof, deployment, or activation |
| **deployed** | a filed `STAGING_DEPLOYMENT` record names that exact release on a named host | that it does anything beyond inert serving |
| **activated** | a reviewed change wired a capability AND an owner decision admitted it | anything a flag, a preflight mode or a merge can do by itself |
| **authorized** | an owner act recorded upstream | anything evidence can establish |

## 1. Source

- **Canonical pin:** `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` in `upstream.lock.json`, unchanged.
  Selected later canonical repairs are recorded separately in
  [`reconciliation/`](reconciliation/monitor-scoped-extension-2026-09-27.json); the latest canonical
  main inspected for them is `1c40306c22e15b2ad3049e8d32abff47450c8921` (canonical PR 368).
  Inspecting a newer canonical head does not advance the pin.
- **Last reviewed application merge:** `8dbea16b262df7b6d40fe945f34e301eedf2aba6` (PR22), completing
  PR18–PR22: Monitor foundation, inactive PromptOps contracts, bounded provider/context composition,
  canonical P01 parity repairs, and the governed workbench at `/console/monitor`.
- **Since then:** the P00 change adds qualification tooling and documentation. Its one new `app/`
  class, `Ops\ReleaseQualification`, is used only by the `ops/ci/` commands; no web entry point,
  worker or product service reaches it, and no runtime behavior changes.
- **Candidate schema:** 23 migration files, including `190_bounded_model_provenance` and
  `200_monitor_runs`. The deployed PR16 release has 19.

## 2. Tested

**How a commit is qualified now.** `bash ops/ci/qualify-release.sh` holds every step whose exit
status decides qualification. The workflow calls it one step at a time; the `local` route calls the
same steps in the same order, each job from its own `git archive` export of the named commit:

```bash
bash ops/ci/qualify-release.sh local --out=<new-dir> [--ref=<commit>] \
  --env-10.6=<disposable 10.6 database env> --env-10.11=<disposable 10.11 database env>
php ops/ci/verify-qualification.php verify-receipt --receipt=<new-dir>/release-qualification-receipt.json \
  --lanes-dir=<new-dir>/reports --preflight=<new-dir>/jobs/release-preparation/work/preflight.json
```

The receipt binds the commit and tree, the SHA-256 of the workflow, runner and verifier that ran,
every step's result, the sanitized lane reports and the prepared-release preflight (with its source
fingerprint). It is `PASS` only when every declared step of every job passed, both MariaDB lanes are
complete and strict with zero failures and zero skips, and — for a local run — PHP is on the
workflow's 8.2 line and Node/npm match the repository pins. **Every lane database is truncated by the
suite; never name a runtime database.**

A receipt is **self-reported**. A `LOCAL` receipt says `hosted_ci: false` and the verifier refuses one
that says otherwise; it may be filed only as `CONFORMANCE` evidence with `environment: "ci"`. It is
never GitHub green and never a host result.

**Hosted CI.** Runner allocation has failed for account billing since PR13 (runs 35525291974 and
36197550928 failed before any step). Billing and branch protection are repository settings; nothing in
this repository changes them. See the
[owner-directed deviation](STAGING_OWNER_DIRECTED_DEVIATION_2026-09-25.md) §4 for how a complete
local reproduction was accepted for inert staging.

**Latest registry-backed qualification:** PR16 `392f48de` — `CONFORMANCE` (`ci`, local exact-head
reproduction) and `CONFORMANCE` (`staging`, the real Loopia host), both 5,552/0/0. The PR18–PR22
qualification reports are held in the operator evidence workspace and have no registry record, so
this file makes no claim from them. The next deployment candidate needs its own receipt at its exact
SHA.

## 3. Deployed

- **Staging** (`staging.allfatherbi.com`) runs PR16 `392f48deef11380f6dccfe88f2a47ba1fd94575c`, inert,
  under the [owner-directed shared-host staging deviation](STAGING_OWNER_DIRECTED_DEVIATION_2026-09-25.md):
  PHP 8.5.9, MariaDB 10.11.19, 19 migrations, strict host suite 5,552/0/0, rollback drill
  PR16→PR15→PR16 passed. E.1 remains `FAIL_READABLE` and E.3 `UNVERIFIED`; neither is an isolation
  pass.
- **The Monitor work (PR18–PR22) is not deployed.** `/console/monitor`, migrations 190/200 and the
  bounded model composition exist only in the candidate.
- **Production** is neither deployed nor authorized.
- **Evidence registry:** 12 reviewed records, preserving the PR13 host failure and the PR15 isolation
  failure alongside current PR16 evidence.

## 4. Activated

Nothing that reaches the outside world is active. Each control below is structural, not a flag, and
is held by a named test:

| Capability | State | Held by |
| --- | --- | --- |
| Live source read | `CurlHttpsTransport` and `DnsHostResolver` are constructed only on `MonitorFactory::forWorkspace`'s admitted branch — an ADMITTED `MANUAL_MONITOR` grant, which needs a reviewed owner decision. None exists | `tests/foundation/no_external_action_surface.php`, `tests/integration/monitor_native_composition.php` |
| Native model execution | one provider-transport constructor, `MonitorFactory::modelTransport($admitted)`; HTTP composes `MonitorFactory::forWorkspace()`, which without an ADMITTED grant is the inactive composition and refuses `NATIVE` steps with `MONITOR_NATIVE_EXECUTION_INACTIVE`; with one, every stage is re-admitted | the same tests; `tests/security/monitor_grant_revocation.php` |
| Product prompts | pack `2026-09-27.monitor.v1`: `activation_status: INACTIVE`, approval `DRAFT`, evaluation `OFFLINE_CONTRACT_TESTED`, `model_evaluated: false` | `app/PromptOps/ProductPromptPack.php` refuses any other state |
| Scheduler | handler registry is `[]` in `worker/tick.php` and `public_html/scheduler.php` | `ops/deploy/preflight.php`, `ops/rollback/verify-stand-down.php` |
| External action, publish, send, charge | no transport exists | `tests/foundation/no_external_action_surface.php` |
| ROAD_TEST_001 | not executed | no `ROAD_TEST_001` record exists |

The stored lock flags, unchanged by anything above. Evidence filing does not move them; a separate
reviewed edit would.

```text
production_launch_authorized       = false
external_action_authorized         = false
owner_go_granted                   = false
customer_visible_output_authorized = false
no_external_charge_executed        = true
conformance_complete               = false
staging_deployed                   = false
loopia_capability_validated        = false
live_read_activated                = false
road_test_001_executed             = false
```

## 5. What comes next

The [Monitor completion plan](MONITOR_COMPLETION_PLAN.md) sequences the rest as three releases: a
qualified manual native Monitor, an operational internal beta, and a separately approved production
release. The next implementation package is **P01 — execution admission and approved prompt
selection**. Its merge activates nothing either: importing an admitted grant is a separately recorded
owner act.

## 6. Superseded narrative

Older documents keep their dated findings. Where one describes the present wrongly, it now carries a
note pointing here rather than being rewritten:

| Statement | Where | Now |
| --- | --- | --- |
| P02 and P03 are `WEB_SURFACE_NOT_INCLUDED` | `PORT_SCOPE_MATRIX.md` §3.7 and the P03 console-panel classification | superseded: their **index** reads are wired into the authenticated React console (`/console/api/v1/p02/entities`, `/p02/relationships`, `/p03/priority-queue`, `/p03/monitored`). Detail reads (P02 entity detail, P03 battlecards) remain `NEEDS_READ_MODEL`, and the scriptless SSR surfaces still serve neither product |
| P02 "web surface not included" | `LOOPIA_CAPABILITY_REPORT.md` §1 | corrected in place |
| "Nothing has run on Loopia", the canary "has never been run on a real host", the probe "has only ever run in CI" | `LOOPIA_HOST_MODEL.md` §5 | historical as of 2026-09-06; PR13–PR16 ran all three on Loopia |
| MariaDB 10.6 "verified in CI, on every commit" | `LOOPIA_HOST_MODEL.md` §5 | hosted CI has not executed since PR13; 10.6 is verified by complete local reproductions |
