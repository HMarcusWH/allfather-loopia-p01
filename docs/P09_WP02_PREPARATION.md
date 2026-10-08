# WP-P09-02 — governed TEST-only browser acceptance preparation

**Status: SOURCE/TEST PREPARATION ONLY.** No role assignment, user provisioning, setup apply,
schedule admission, source/model/provider call, Railway or production mutation. Source code,
a proposal digest and passing offline tests do not confer owner execution authority.

## Exact baseline and measurements still needed

- GitHub main and Railway staging PHP deployment: 4e08ac0488fea8f9529f36c010040267128a422d.
- Staging host is ONLY https://allfather-php-mvp-staging.up.railway.app.
- Prior actual Chromium 1.56.1: 45 PASS, 0 FAIL, 22 NOT_COVERED at 1440x900 and 390x844. This was a subset, not full P09.
- Railway service status last inspected: PHP and MariaDB online, canonical adapter intentionally offline, zero pending work.
- Previously observed 31 migrations. A fresh authorized private DB-status check, prepared-source fingerprint,
  console manifest digest, maintenance-state check and current grant/schedule inventory are STILL REQUIRED.
  Do not infer these facts solely from the source SHA.

## A. Exact role-assignment review — NO assignment included

Use a dedicated synthetic TEST tenant/workspace, not a beta partner or customer identity.
Prefer separate TEST operators for setup administration and product read fixtures.
**WARNING:** the available P02/P03/P04 product roles are not read-only: they also include
proposal, review, release, routing or related write permissions. A browser runner that
only issues GETs does NOT make the underlying memberships read-only. Each such broader
role requires a specific necessity/least-privilege review or a future separately adopted
narrow role definition; do not silently weaken M02 or fabricate new rights.
Proposed roles are version-pinned; all IDs and actual validity dates are PENDING
a real current-state query, never invented:

| Seat | Existing role and version | Purpose |
| --- | --- | --- |
| Setup-only TEST operator | ROLE_TENANT_ADMIN v2 | tenant administration plus runtime_host_binding.administer, valkyrie.register, valkyrie.administer_contract |
| Setup-only TEST operator | ROLE_WORKSPACE_OPERATOR v1 | mission.create, mission.activate, evidence.submit and monitor reads |
| Setup-only TEST operator | ROLE_INTELLIGENCE_DISTRIBUTOR v1 (includes routing rights) | distribution.configure_targets |
| Setup-only TEST operator | ROLE_COMPETITION_MONITOR v1 | P01-specific visibility where needed |
| P02 browser-read QA seat | ROLE_ENTITY_TWIN_CURATOR v1 (broader than read-only) | entity_twin.read plus other propose/review/release rights |
| P03 browser-read QA seat | ROLE_LEAD_QUALIFIER v1 (broader than read-only) | priority_outreach_queue.read plus upload/review rights |
| Monitor-read TEST operator | ROLE_WORKSPACE_OPERATOR v1 | mission.read and evidence.read |

Important: TenantAdmin v2 inherits powerful role.assign and role.revoke permissions.
Onboarding CLI selects v1 even when v2 vocabulary exists. Never silently change that.

The actual review packet must capture full release SHA, TEST tenant/workspace/operator IDs,
each exact role ID/key/version and permission set, membership validity interval and current
status, any existing memberships and expiries, legitimate role.assign actor, authorizing
owner decision, planned changes, stand-down/retirement procedure and canonical proposal
digest. The digest is created ONLY from the real current-state packet, not placeholders.
The owner reviews the packet BEFORE any invocation of the existing AccountService
explicit-v2 membership API. No general assignment CLI or raw SQL is added.

## B. Source and fixture prerequisites — separate owner operations

MonitorSetup requires all eight existing M02 permissions: mission.create, mission.activate,
distribution.configure_targets, tenant.administer, runtime_host_binding.administer,
valkyrie.register, valkyrie.administer_contract and evidence.submit.

A valid plan also depends on P01 entitlement, registered capabilities and governance,
an imported synthetic company-profile evidence object, a genuinely reviewed test-source
robots/terms policy with attestation by the real operator, and a supported private
credential reference. No fake evidence, source-review timestamp or provider credential
may be fabricated. Review the supported private import and credential paths first.
If an inert credential cannot legitimately satisfy setup, mark that step BLOCKED.

The P02/P03 detail tests need two previously governed, owner-visible/released synthetic
objects and two foreign-workspace objects with exact UUIDv4 selectors. Monitor run detail
needs a real inert governed TEST run; do not write fixture rows directly into DB.
Confirm that applicable product entitlements and reads are currently authorized.

ScheduleService requires a genuine existing SCHEDULED_OBSERVATION parent grant in a
live, non-revoked/non-expired state plus a frozen NATIVE mission configuration, current
source policy and properly scoped scheduler service identity, EVEN FOR plan/propose.
Preparing those objects is separate governed work. Proposed schedules are inert until
a separate owner decision admits them. Suspend requires an admitted version; do NOT
admit recurrence for browser acceptance. Isolate the suspend-admitted test under the
future explicitly authorized P07 gate and record NOT_COVERED here.

## C. Offline code and future read-only browser checks

- ops/acceptance/p09-fixture-contract.mjs: pure TEST fixture contract and safe selector
  /metadata comparison guards, no imports from the live app.
- frontend/console/tests/p09-fixture-contract.test.js: offline Node test suite.
  Included by existing frontend package "npm test" glob, and therefore by the
  existing single-procedure qualification runner when hosted CI resumes.
  To run only this focused suite from the repository root:
  node --test frontend/console/tests/p09-fixture-contract.test.js
- ops/acceptance/p09-browser.mjs: source-only Playwright 1.56.1 Chromium harness copied
  from the previously executed negative-browser subset, enhanced to optionally check
  approved owned/foreign P02, P03, Monitor details. Does not intercept CSS.
  No setup apply, role assignment, schedule proposal/admission or model/source work.

Later, an authorized operator uses private environment variables:
P09_TEST_SCOPE_REVIEWED=YES (operator acknowledgement; NOT owner authorization);
P09_EXPECTED_RELEASE_SHA (exact externally verified deployed SHA);
TEST_LOGIN_EMAIL (TEST-only @example.test) and TEST_LOGIN_PASSWORD (ephemeral secret).
Optional P09_FIXTURES_FILE (0600 JSON) MUST be paired with P09_REVIEWED_PLAN_DIGEST
(64 lower-case hex digest of separately reviewed TEST membership plan).

Private fixture file JSON shape:
    {"test_only":true,"release_sha":"<40 hex>",
     "reviewed_plan_digest":"<64 lowercase hex>",
     "p02":{"owned":"<uuidv4>","foreign":"<uuidv4>"},
     "p03":{"owned":"<uuidv4>","foreign":"<uuidv4>"},
     "monitor":{"owned":"<uuidv4>","foreign":"<uuidv4>"}}

The runner requires the fixture's reviewed_plan_digest to match the separately
provided P09_REVIEWED_PLAN_DIGEST. This prevents accidental mismatched QA input,
but is **not a signature, authorization verifier, or proof that an owner reviewed
a plan**. Only the independently retained and approved M02 packet establishes
that fact. The runner does not independently attest the deployed release SHA:
an operator must first compare it to Railway's exact live deployment receipt.

Kinds not supplied are NOT_COVERED. Never commit raw cookies, credentials, CSRF,
private profile material or unsanitized evidence. The helper accepts only a
hard-pinned staging URL and does not support production targets.
Playwright is test-only; it is NOT part of production console dependencies.
A matching env variable is not evidence of a legitimate signed owner decision.

## D. Follow-on execution plan and PASS criteria

1. Re-observe migration state, runtime grant/schedule/maintenance posture, exact source SHA,
   asset manifest and source fingerprint through the authorized private owner surfaces.
2. Owner checkpoint A: review time-bounded M02 TEST membership plan. Only after approval
   may an authorized actor assign explicit role versions in the exact scope.
3. Owner checkpoint B: review every synthetic profile/source/credential and P02/P03/Monitor
   fixture operation, exact current state, retention and cleanup. No invented fixtures.
4. Run real Chromium desktop/mobile against staging using the approved TEST scope.
   Verify login/workspace persistence, visual layout, scope isolation, CSRF, missing plan
   digest, no browser admission, unknown-vs-foreign 404 equivalence and metadata minimization.
5. Separately review setup PLAN; present the actual digest before an approved APPLY.
   Verify changed-material stale digest refusal and exact receipt/object diff.
6. Separately review inert schedule PLAN and any PROPOSE/REVOKE. Admit nothing.
   Preserve separate future P07 suspension/admission obligations.
7. Retire TEST login and memberships through supported lifecycle owners and verify no
   residual ability, while retaining governed audit history. Do not run strict destructive
   tests on staging runtime DB or preserved restore targets.
8. Record PASS/FAIL/NOT_COVERED for every P09 obligation. This source-only PR is
   NOT P09/B2/ROAD_TEST/P10/production acceptance.

Issues: #35 (P09 operational gate), #43 (P10 restore), #44 (B2 evaluation).
