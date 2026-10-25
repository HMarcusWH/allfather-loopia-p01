# Post-merge test backlog — source-complete Monitor stack

> **Dated follow-up — 2026-10-25:** This backlog is the historical post-September source-merge plan, not the current status of the October 25 source head. Issue #65 and the test repairs #71/#72 have merged; the identical tested full source tree passed 12,458/0/0 on Railway disposable MariaDB 10.6 and 10.11; GitHub Actions credit-blocked jobs had zero steps. The dated [LI-00 ledger](longitudinal/LONGITUDINAL_INTELLIGENCE_STATE.md) holds the later observations and preserves separate execution/quality/production gates. Do not rewrite historical B0 attempts as passing CI.

**Status:** OPEN TEST BACKLOG.  
**Source main:** `ab6dc21590ef3ef89383c03846b2fed862474e4c`  
**Canonical authority main:** `be7962a4a7f67b40613ce8daedee17088b0db1ca`  
**Last evidenced inert staging release:** `2ada973c520a32a2a4d03e79d9c1e3a55b10ee6a`

All planned source packages are now merged. That does **not** mean the exact merged tree is host-qualified,
deployed, activated, funded for model evaluation, approved for recurring execution, production-ready, or
customer-visible.

This file is the operational test backlog after the source merge train. It deliberately keeps
**source / qualified / deployed / activated / authorized** as separate states.

The machine-readable form is
[`POST_MERGE_TEST_BACKLOG.json`](POST_MERGE_TEST_BACKLOG.json).

## Rules for every test

- Never use runtime DB1 or either retained restore target as a disposable qualification database.
- Keep `.env`, private storage, the static public website and the production root outside test mutation.
- Never commit provider keys, database credentials, master/recovery keys, cookies, CSRF values, raw backup
  contents or private logs.
- A green source test is not deployment evidence. A successful deployment is not execution authority.
- A quota/billing refusal proves only the refusal path; it is not model-quality evidence.
- No silent retry after a result-bearing provider call.
- Evidence records must name the exact release SHA, target, environment and observed result.
- Historical failed/cancelled runs remain failed/cancelled; do not relabel them PASS.

## B0 — exact merged-main qualification

**Goal:** qualify the actual merged source tree, not one of its parent PR heads.

1. Run the full hosted qualification when GitHub runner/credit capacity is available:
   - foundation;
   - frontend clean install/tests/build/stage;
   - PHP syntax;
   - MariaDB 10.6 strict suite;
   - MariaDB 10.11 strict suite;
   - release preparation;
   - aggregate release qualification.
2. Require zero failures and zero skips on both MariaDB lanes.
3. Bind the receipt to exact source commit/tree, workflow/runner/verifier hashes and prepared source
   fingerprint.
4. If hosted runners remain unavailable, a local conformance run may be retained as **LOCAL /
   hosted_ci=false** evidence only. It must never be described as GitHub green.

**Pass condition:** one complete exact-`ab6dc215...` qualification receipt, or a later backlog-PR merge
commit if this documentation PR changes main first.

## B1 — inert staging update and host qualification

**Goal:** put the exact source-complete release on staging without granting execution.

1. Re-read the actual host release/fingerprint, maintenance posture, current/future execution grants,
   admitted schedules and execution counters before mutation.
2. Prepare a protected update from the exact reviewed Git object.
3. Deploy the application copy inertly while preserving:
   - private `.env`;
   - runtime storage;
   - the static public website;
   - the production root.
4. Apply migration 250 so staging reaches **31 migrations**, then prove:
   - first upgrade applies only pending migration(s);
   - second run is a no-op;
   - drift/pending counts are zero.
5. Run the strict Loopia qualification against **qualification DB2**, never runtime DB1.
6. Repeat anonymous/authenticated/Monitor negative smokes and confirm:
   - zero admitted execution grants unless separately approved later;
   - zero admitted schedules;
   - zero provider attempts;
   - no external action/customer output.
7. Provision the additive role vocabulary through the narrow vocabulary CLI and verify:
   - 15 v1 roles including `ROLE_SYSTEM_SCHEDULER_OBSERVER` v1;
   - additive `ROLE_TENANT_ADMIN` v2;
   - **16 explicit role definitions total**;
   - existing v1 memberships remain byte-for-byte unchanged.
8. Produce the exact TenantAdmin-v2 membership plan/digest. Assignment is a **separate owner approval**;
   vocabulary installation alone must not assign it.

**Pass condition:** exact release host-qualified inertly with 31 migrations, 16 role definitions, no
implicit membership upgrade and no execution/schedule activation.

## B2 — bounded real model evaluation

**Goal:** produce genuine evaluation evidence before product execution.

1. Prepare the merged EvaluationCarrier and the frozen **18-case** evaluation manifest.
2. Record actual request digests for the approved evaluation configuration.
3. Obtain separate `EVALUATION`-purpose prompt approvals.
4. Obtain the exact `MODEL_EVALUATION` execution grant and budget approval.
5. Execute only the approved bounded attempts.
6. Preserve:
   - per-attempt provenance;
   - actual provider/model result or refusal;
   - duplicate/replay refusal;
   - grant consumption.
7. Collect genuine human grounding/usefulness assessments; do not invent missing assessments.
8. Produce the accepted quality report only if the human-assessment gate is genuinely complete.

**Special case:** if the temporary credential has no funds/quota, retain that diagnostic as a refusal
test and stop. It does **not** satisfy model-quality acceptance.

**Pass condition:** accepted evaluation evidence from real bounded calls plus genuine human scoring.

## B3 — MANUAL_MONITOR ROAD_TEST_001

**Goal:** prove the real manual Monitor path end to end while keeping output internal.

Preconditions: B1 PASS, B2 accepted evaluation, reviewed real source/context setup.

1. Obtain separate `MANUAL_MONITOR` prompt approvals citing the accepted evaluation.
2. Review the exact real source/context setup through the supported setup owner path.
3. Present and approve the exact bounded `MANUAL_MONITOR` grant.
4. Require `MANUAL_PILOT_READY` only after legitimate admission.
5. Execute ROAD_TEST_001 with:
   - baseline observation;
   - changed observation;
   - exactly **2 source captures**;
   - up to **4 model attempts** under the admitted budget;
   - exact request inspection;
   - **2 actual human reviews**;
   - internal-only release/result handling.
6. No silent retry, no external publish/send/CRM mutation, no customer-visible output.

**Pass condition:** the declared road-test sequence completes with durable journal/provider/evidence
provenance and the human reviews actually recorded.

## B4 — immediate stand-down and replay

Run immediately after ROAD_TEST_001.

1. Suspend/revoke every current/future execution grant used by the test.
2. Verify no admitted recurrence remains.
3. Replay Monitor journals and verify M10 integrity.
4. Confirm final execution counters and current/future grants are zero.
5. Export only sanitized observations; keep failed execution and successful execution distinct.

**Pass condition:** no remaining authority capable of another source/model execution and all durable
history replays cleanly.

## B5 — P07 recurring-observation acceptance

**Goal:** test the now-merged recurrence code without turning it into autonomous inference.

This is a separate owner-authorized test after the manual road test. Do not infer authorization from
the source merge.

1. Provision/reuse the exact scheduler service seat:
   - `ROLE_SYSTEM_SCHEDULER_OBSERVER` **v1** only;
   - exact four permissions;
   - no broader or future same-key version.
2. Create one bounded `SCHEDULED_OBSERVATION` grant with **zero model attempts**.
3. Plan/propose one test schedule and review its digest.
4. Admit only through a reviewed owner decision.
5. Verify:
   - occurrence cap and parent capture budget;
   - hourly-or-slower/source-policy cadence floor;
   - `LATEST_ONLY` catch-up;
   - duplicate occurrence/run prevention;
   - capture + diff only;
   - changed runs stop at `DIFFED / WAITING_FOR_OPERATOR`;
   - zero provider-model attempts before a human takes over.
6. Test suspension/revocation between ticks and before capture.
7. Stand the schedule down after the acceptance run.

**Pass condition:** bounded recurrence observes and queues, never impersonates a human and never enters
model/review/release stages unattended.

## B6 — P09 real staging browser acceptance

Execute the complete protocol in
[`P09_BROWSER_ACCEPTANCE.md`](P09_BROWSER_ACCEPTANCE.md) against the exact deployed source-complete
release.

Minimum evidence:

- desktop journey (>=1280x720);
- phone-sized journey (~390x844);
- login/workspace persistence;
- Monitor workbench;
- setup plan/apply receipt;
- schedule plan/propose/suspend/revoke with **no browser admission**;
- P02 detail;
- P03 detail;
- React Monitor run list/detail;
- foreign/unknown selector equivalence where specified;
- missing-CSRF refusal;
- stale setup/schedule digest refusal;
- source/model/operator strings escaped as text;
- no scope fields controlled by URL/form;
- no source fetch/model call/retry merely from viewing pages.

**Pass condition:** every desktop/mobile and negative browser check is PASS with sanitized evidence.

## B7 — populated-history backup / empty-target restore

**Goal:** prove recovery after real Monitor history exists.

1. Create a **new** encrypted/off-host P10 backup after ROAD_TEST_001.
2. Do not overwrite or reuse either retained earlier restore target.
3. Restore to a new, initially empty target.
4. Verify:
   - all 31 migration hashes;
   - table/trigger inventory;
   - Monitor runs/events/artifacts;
   - M10 streams/records;
   - evaluation/attempt history;
   - execution/schedule history;
   - immutable-row update/delete refusal;
   - complete relevant digests before/after.
5. Do not start the restored runtime or scheduler during verification.

**Pass condition:** populated governed history survives an empty-target restore exactly and immutability
still refuses mutation.

## B8 — real key-rotation rehearsal

The retained-key proof already established v1 recovery and v2-only refusal; that is not a full rotation.

1. Use a disposable/non-production credential record.
2. Rewrap under the next approved key version.
3. Prove the new key opens the new ciphertext.
4. Prove the retained old key can recover the pre-rotation ciphertext where the runbook requires it.
5. Prove unavailable/wrong versions fail closed.
6. Run with zero provider calls and retain no plaintext.

**Pass condition:** full rotation/recovery semantics succeed without changing provider authority.

## B9 — P12 negative production-preflight acceptance

The merged P12 code is intentionally non-authorizing.

Against the current schema, prove:

- exact lowercase target and full release SHA are required;
- latest production observation for the selected target wins;
- later FAIL/STAND_DOWN/different-release evidence defeats stale PASS;
- tied latest timestamps refuse;
- staging/other-target evidence cannot satisfy production;
- current identity vocabulary still lacks adopted production/customer semantics;
- customer audience is `NOT_ADOPTED` or `UNKNOWN`, never inferred;
- `grants_go=false`;
- `customer_visibility_granted=false`;
- the preflight writes neither the lock nor evidence registry.

The historical production blockers remain explicit:
E.1 `FAIL_READABLE`, E.3 `UNVERIFIED`, no adopted positive production/customer contract, no
production recovery evidence and no owner production/customer-visibility GO.

**Pass condition:** the current tree remains clearly **NOT_READY** for production for the correct
reasons.

## B10 — evidence filing and closure

For every completed operational stage:

1. retain the raw private evidence outside Git where required;
2. commit only sanitized reviewed evidence pointers/receipts;
3. keep failed attempts as failed attempts;
4. name exact source SHA, target, environment and timestamp;
5. update `MVP_RELEASE_STATE.md` without conflating source/test/deployment/activation;
6. close or supersede Issue #35 only after the backlog items it governs are actually satisfied.

Production/customer work after this backlog remains a separate project boundary and needs separately
adopted canonical contracts plus explicit owner decisions.
