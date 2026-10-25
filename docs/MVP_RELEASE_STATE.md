# MVP release state

> **Dated currentness notice — 2026-10-25:** The table and measurements below preserve the October 3 historical release-state filing. They are not today's live deployment readback. The newer [longitudinal source/host/qualification/authority ledger](longitudinal/LONGITUDINAL_INTELLIGENCE_STATE.md) records merged PHP main `2a1a7eb5`, the separately Railway-qualified exact Git tree, later staging deployment and the unchanged adopted canonical pin. Runtime grants and model-quality acceptance are not inferred from a Git merge. Keep this historical filing and its evidence-bound foundation assertions intact.

> **Code-qualification reconciliation (25 October 2026):** For source trees containing `app/Ops/CodeQualification.php`, ordinary PR/main CI is the independent `CODE_ONLY` contract and produces `CODE_QUALIFICATION_RECEIPT` v2. A PASS means the exact code and prepared package qualified, **not** that an inert staging copy, manual pilot or production promotion is authorized. The strict `READY_FOR_INERT_STAGING_COPY` check remains separately available by manually dispatched workflow and correctly refuses the existing four owner decisions. See [qualification profiles](ci/QUALIFICATION_PROFILES.md). The v1 release-qualification commands and dated deployment evidence below remain historical; they are not erased or promoted by this notice. No new host observation was made by this documentation change.

**The current-state entrypoint.** Source, testing, deployment and authority remain separate.
The [3 October evidence filing](STAGING_OPERATIONS_EVIDENCE_2026-10-03.md) records the underlying
observations and their limits. This documentation change does not deploy itself or grant execution.
The later [P11 retained-key supplement](P11_RETAINED_KEY_EVIDENCE_2026-10-03.md) records the second
backup and successful local v1 recovery/v2-only refusal; full live key rotation remains unexercised.

| Question | Current answer | Evidence |
| --- | --- | --- |
| **Source** — what application code is present? | The source merge train is complete through main `ab6dc215`: TenantAdmin v2, EvaluationCarrier, P07 recurrence, P09 administration/views and inert P12 preflight scaffolding are merged. This backlog PR changes documentation only | Git history; 31 migrations; `POST_MERGE_TEST_BACKLOG.md` |
| **Tested** — which exact commit was qualified? | Historical package candidates have green hosted receipts, but the exact source-complete merged main has **not yet** completed a fresh end-to-end qualification after the merge train; B0 tracks that requirement | Historical CI receipts; `POST_MERGE_TEST_BACKLOG.md` B0 |
| **Deployed** — which release runs on staging? | `2ada973c520a32a2a4d03e79d9c1e3a55b10ee6a`, inert, with all 30 migrations and the exact prepared source fingerprint | Filed deployment, probe and HTTPS smoke artifacts |
| **Activated** — which missions may run? | No current/future execution grant; no Monitor run, source capture or model attempt in the measured counters. Scheduler handlers remain empty. ROAD_TEST_001 has not executed | Inert preflight and Monitor smoke counter readbacks |

```text
canonical_pin                      = c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
implementation_baseline_merge      = 0b8c17e9a3457baf25f25babb43d61bc71633f6a
source_merge_train_baseline          = ab6dc21590ef3ef89383c03846b2fed862474e4c
deployed_staging_release           = 2ada973c520a32a2a4d03e79d9c1e3a55b10ee6a
evidence_records                   = 21
candidate_migrations               = 31
```

## Source and qualification

The canonical pin is unchanged. Selected later canonical repairs remain documented separately in
`reconciliation/`; reading a newer upstream head does not advance the pin. Canonical scheduler-observer
v1 authority and TenantAdmin v2 are merged upstream and selectively reconciled here without advancing
the global pin. The historical implementation baseline remains PR30 `0b8c17e9`; `2ada973c` is still the
exact reviewed and deployed staging application object. The source merge train then completed through
`ab6dc21590ef3ef89383c03846b2fed862474e4c`: PR36 TenantAdmin v2, PR39 EvaluationCarrier, PR32 bounded
recurrence, PR33 operator administration/owner-safe views and PR34 inert production-preflight
scaffolding are all merged **as source**. None of those later source merges proves deployment,
activation, model-quality acceptance, recurrence admission, production GO or customer visibility.

**Candidate schema:** 31 migration files: the deployed staging release still has the original 30,
while merged source adds P07 `250_monitor_schedule_control`. Staging has not yet been updated to this
source-complete tree; its previous migration run was a no-op with drift/pending counts zero. PHP 8.5.9 and MariaDB 10.11.19 were measured on
Loopia. The host strict suite used `.env.qualification` and a separately verified database/user;
it did not truncate the runtime database.

Hosted run [37128711802](https://github.com/HMarcusWH/allfather-loopia-p01/actions/runs/37128711802)
qualified exact main `2ada973c` on PHP 8.2.34 and MariaDB 10.6.28/10.11.19, both **8,942/0/0**.
The hosted receipt remains self-reported CI evidence, paired with the independently inspected run.
Actual Loopia qualification is a separate **8,942/0/0** receipt on PHP 8.5.9/MariaDB 10.11.19.
Historical local reproductions and hosted-runner interruptions are retained in earlier records;
these results do not retroactively qualify an untested historical commit.

## Qualification procedure

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

## Deployed staging and observed boundaries

The old PR16 release lacked the current maintenance/backup implementation. The owner approved the
revised order: static HTTP fence and bounded legacy drain observation, exact-main upgrade and
migrations, then real BACKUP maintenance and P10. The exact source fingerprint is
`6460ca1b44ab3147966037163e27e4f2c7e2e6a961cf06c4ec9ee0cdb084a21b`.
The legacy drain is not described as a retrospective maintenance-lease proof.

After the successful restore rehearsal, maintenance was left and the current release was
qualified over HTTPS: anonymous smoke **23/0**, authenticated Gate N/O **118/0**, Monitor smoke
**50/0** with all six specific Monitor boundary checks, and a request-ID match to one private log
line while the public log path refused access with 403. Disposable qualification accounts were
retired with zero live sessions. The first Monitor attempt is preserved as a report-parser failure:
requests executed, but its full verdict was not retained. The corrected fresh attempt passed.

The browser inspection found the logo, workspace chooser, account/support and inert Monitor
workbench working. Empty product states and unwired detail panels remain explicit. Support text
still overstates that the entire console is read-only; the Monitor workbench exposes governed
advance/review controls. This is not full P09 desktop/mobile acceptance or a populated mission test.
The tenant-admin role-version repair remains separate work and is not claimed deployed here.

The historical shared-host deviation remains: E.1 **FAIL_READABLE**, E.3 **UNVERIFIED**. No new
cross-site isolation PASS is filed. Production is neither deployed nor authorized. No rollback
drill of current `2ada973c` was performed; the historical PR16→PR15→PR16 result stays historical.

## P10 and P11 operational evidence

The first actual native encrypted backup was retained off host and restored into an initially
empty, different, isolated local MariaDB 10.11.19 database. The unchanged protected restore script
verified **219 tables and 2 private artifacts**. Supplemental checks verified **30 exact migration
hashes and 272 triggers**, matching authenticated trigger ownership, and zero admitted grants.
Actual update and delete attempts on an existing immutable audit row both refused with SQLSTATE
45000 and were rolled back; all 28 audit rows retained identical count and full content digest.
The target runtime and scheduler were not started, and temporary plaintext was removed.

This selected backup had **zero M10 streams, zero Monitor runs, zero retained-output render
models and zero provider credential rows**. It establishes restoration of that state, not populated
business-history replay. **P11 real master-key rewrap/older-key recovery was NOT_EXERCISED.**
A temporary provider credential was stored encrypted after that backup; no provider call or
execution grant followed. The first restore proof does not cover the later credential.
No backup archive, plaintext dump, recovery identity or credential payload is committed here.

## Activation and remaining work

All planned source packages are merged. The remaining work is operational testing and evidence, not
another source package train. The complete sequence now lives in
[`POST_MERGE_TEST_BACKLOG.md`](POST_MERGE_TEST_BACKLOG.md): exact merged-main qualification, inert staging
update, bounded real model evaluation, ROAD_TEST_001, immediate stand-down/replay, optional separately
authorized P07 recurrence acceptance, P09 desktop/mobile acceptance, populated-history restore, real
key-rotation rehearsal and P12 negative production-preflight acceptance. Storing a provider credential
is not activation. The deployed `2ada973c` scheduler registry remains empty and no source merge admits a
schedule, grant, provider call, production GO or customer-visible output.

Registry evidence may support observed deployment/conformance claims without changing a lock.
All stored flags below are unchanged; this filing creates no authority transition:

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
road_test_001_executed              = false
```

For subsequent qualification use the reviewed `ops/ci/qualify-release.sh` workflow with disposable
lane databases only. A current source/CI record never substitutes for a new deployment, populated
road test, production boundary or owner decision.

## Superseded narrative

Older documents keep their dated findings. Where one describes the present wrongly, it now carries a
note pointing here rather than being rewritten:

| Statement | Where | Now |
| --- | --- | --- |
| P02 and P03 are `WEB_SURFACE_NOT_INCLUDED` | `PORT_SCOPE_MATRIX.md` §3.7 and the P03 console-panel classification | superseded: their **index** reads are wired into the authenticated React console (`/console/api/v1/p02/entities`, `/p02/relationships`, `/p03/priority-queue`, `/p03/monitored`). Detail reads (P02 entity detail, P03 battlecards) remain `NEEDS_READ_MODEL`, and the scriptless SSR surfaces still serve neither product |
| P02 "web surface not included" | `LOOPIA_CAPABILITY_REPORT.md` §1 | corrected in place |
| "Nothing has run on Loopia", the canary "has never been run on a real host", the probe "has only ever run in CI" | `LOOPIA_HOST_MODEL.md` §5 | historical as of 2026-09-06; PR13–PR16 ran all three on Loopia |
| MariaDB 10.6 "verified in CI, on every commit" | `LOOPIA_HOST_MODEL.md` §5 | historical interruption: hosted CI was unavailable through PR25; main `2ada973c` is directly hosted-qualified on both 10.6 and 10.11; actual-host evidence is filed separately |
