# MVP release state

**The one place to read the current state.** It answers four questions separately, because they
have different answers and different evidence, and running them together is how a candidate gets
described as deployed or a deployment gets described as activated:

| Question | Current answer | Evidence |
| --- | --- | --- |
| **Source** — what application code is in this release tree? | P00–P06 and P10 are merged; this tree adds the P11 version-aware provider keyring/rewrap/recovery candidate | Git history; [Monitor completion plan](MONITOR_COMPLETION_PLAN.md) |
| **Tested** — which exact commit was qualified, and how? | merged PR26 `26b92c82` passed hosted CI push run 36847557585; both MariaDB lanes were 7,508/0/0 | §2; `deployment-evidence.json` |
| **Deployed** — which application runs on staging? | PR16 `392f48de`, inert. **None of the Monitor work is deployed** | §3; [Gate R filing](STAGING_GATE_R_EVIDENCE_2026-09-26.md) |
| **Activated** — what may run against the world? | nothing: no live read, no native model call, no scheduler handler, no external action | §4; `upstream.lock.json` |

Update this file in the same change that alters any of the four answers.
`tests/foundation/release_state_currentness.php` fails the build when the values in the block below
stop matching `upstream.lock.json`, `deployment-evidence.json` and `database/migrations/`.

```text
canonical_pin                      = c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
implementation_baseline_merge      = 26b92c8293ea3c1dd6f579e79aa500b97da07f7d
deployed_staging_release           = 392f48deef11380f6dccfe88f2a47ba1fd94575c
evidence_records                   = 13
candidate_migrations               = 28
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
- **Implementation baseline merge:** `26b92c8293ea3c1dd6f579e79aa500b97da07f7d` (PR26), completing
  **P00–P06 plus P10 source implementation**. PR26 adds deployed-Monitor qualification,
  `ROAD_TEST_001` protocol/evidence tooling, stronger post-pilot stand-down verification and the
  qualification repairs surfaced when hosted runners resumed.
- **Qualification history:** PR23, PR24 and PR25 were merged by owner decision while hosted runner
  execution was unavailable. Hosted CI resumed for PR26. Push run `36847557585` qualified merged
  main `26b92c8293ea3c1dd6f579e79aa500b97da07f7d` directly: MariaDB 10.6 and 10.11 both reported
  **7,508/0/0**, and foundation, release preparation and aggregate release qualification all passed.
  This does not retroactively qualify PR23–PR25, and it is not Loopia host evidence.
- **P06 operational state:** source/tooling is merged and CI-qualified, but no newer Monitor release
  has been deployed to Loopia, no funded model evaluation or MANUAL_MONITOR grant has been applied,
  and `ROAD_TEST_001` has not executed. P10's real native/off-host/clean-target rehearsal also
  remains operational acceptance work.
- **P11 candidate in this tree:** adds exact-version credential decryption, legacy/versioned
  keyring configuration, bounded tenant-wide master-key rewrap, append-only rotation receipts,
  tenant-mutex serialization with grant admission, and retained-old-key recovery tests. It does not
  rotate staging key material or change deployment/activation/owner authority.
- **Candidate schema:** 28 migration files, including `210_monitor_execution_controls`,
  `220_monitor_evaluation_records`, `225_monitor_setup_receipts`, `230_monitor_attempt_recovery` and
  P11 `240_provider_rotation_events`. The deployed PR16 release has 19.

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

**Hosted CI.** Runner allocation was unavailable through PR25, which is why the earlier inert-staging
path used complete local reproductions under the owner-directed deviation. Hosted runners resumed for
PR26. Pull-request run 36798285760 passed the PR merge tree, and the subsequent **push run 36847557585
qualified the actual merged-main commit `26b92c8293ea3c1dd6f579e79aa500b97da07f7d` directly**.
Repository billing/branch-protection settings remain outside application code.

**Latest registry-backed code qualification:** merged PR26
`26b92c8293ea3c1dd6f579e79aa500b97da07f7d` — hosted `CONFORMANCE` (`ci`) from push run
`36847557585`, with strict MariaDB 10.6 and 10.11 lanes both **7,508/0/0**. The committed receipt is
self-reported hosted-CI evidence and establishes no Loopia host claim. **Latest registry-backed host
qualification remains PR16 `392f48de`** at 5,552/0/0 on Loopia. PR18–PR22 reports remain unfiled.

## 3. Deployed

- **Staging** (`staging.allfatherbi.com`) runs PR16 `392f48deef11380f6dccfe88f2a47ba1fd94575c`, inert,
  under the [owner-directed shared-host staging deviation](STAGING_OWNER_DIRECTED_DEVIATION_2026-09-25.md):
  PHP 8.5.9, MariaDB 10.11.19, 19 migrations, strict host suite 5,552/0/0, rollback drill
  PR16→PR15→PR16 passed. E.1 remains `FAIL_READABLE` and E.3 `UNVERIFIED`; neither is an isolation
  pass.
- **The merged Monitor work through PR26 is not deployed.** The deployed PR16 tree predates
  `/console/monitor`, the bounded model/admission path and P04/P05/P10/P06 tooling.
- **Production** is neither deployed nor authorized.
- **Evidence registry:** 13 reviewed records, adding PR26 merged-main hosted CI conformance while
  preserving the PR13 host failure and PR15 isolation failure alongside current PR16 host evidence.

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

The [Monitor completion plan](MONITOR_COMPLETION_PLAN.md) sequences the rest as three releases.
PR26 remains the **P00–P06 plus P10 application baseline**; PR27 filed its hosted merged-main
qualification evidence. This tree implements the P11 source candidate while P10 operational
acceptance (real native backup, off-host retention and clean disposable restore) still gates the live
P06 road test. P07 remains after that road test. No code merge or CI result creates execution
authority, deployment evidence or owner GO.

## 6. Superseded narrative

Older documents keep their dated findings. Where one describes the present wrongly, it now carries a
note pointing here rather than being rewritten:

| Statement | Where | Now |
| --- | --- | --- |
| P02 and P03 are `WEB_SURFACE_NOT_INCLUDED` | `PORT_SCOPE_MATRIX.md` §3.7 and the P03 console-panel classification | superseded: their **index** reads are wired into the authenticated React console (`/console/api/v1/p02/entities`, `/p02/relationships`, `/p03/priority-queue`, `/p03/monitored`). Detail reads (P02 entity detail, P03 battlecards) remain `NEEDS_READ_MODEL`, and the scriptless SSR surfaces still serve neither product |
| P02 "web surface not included" | `LOOPIA_CAPABILITY_REPORT.md` §1 | corrected in place |
| "Nothing has run on Loopia", the canary "has never been run on a real host", the probe "has only ever run in CI" | `LOOPIA_HOST_MODEL.md` §5 | historical as of 2026-09-06; PR13–PR16 ran all three on Loopia |
| MariaDB 10.6 "verified in CI, on every commit" | `LOOPIA_HOST_MODEL.md` §5 | historical interruption: hosted CI was unavailable through PR25; PR26 merged main is now directly hosted-qualified on both 10.6 and 10.11 |
