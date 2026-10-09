# P09 WP-05 — Signed QA viewer login-only and GET browser smoke

**Status:** SOURCE CANDIDATE, NOT APPROVED / NOT MERGED / NOT DEPLOYED / NOT EXECUTED.
**Base:** exact repository main 21d9b8113a51cb28391c980d5eaef705bd00e360.
**Purpose:** close G1 and part of G3 of the P09 owner preparation; retain the conservative natural-expiry closeout in G2. This document conveys no authority.

## Why

PR #47 permits a separately owner-signed canonical ROLE_DEMO_VIEWER v1 membership (only workspace.read, decision.read, internal_release.read; <=3600 seconds). It deliberately does **not** create a browser login. The ordinary beta onboarding CLI is **unsuitable** because it provisions extra things: subscriptions, potentially operators, and legacy role seats. Existing TestMembershipLifecycle does not revoke DemoViewer v1, and the viewer has no delegation/revocation privileges. Existing P09 browser acceptance includes operations outside DemoViewer's authority.

## Added surfaces

1. app/Auth/TestQaViewerLogin.php — requires an already ACTIVE isolated TEST viewer member in the original signed <=1-hour window. Reconstructs the original P09_TEST_QA_VIEWER_V2 canonical plan definition *from the existing membership* and compares its digest to the privately reviewed digest. The role's complete installed permissions must exactly match both canonical DemoViewer v1 and the independent 3-permission allowlist. The operator must have exactly ONE historical membership (the approved viewer seat) and ZERO historical platform logins. The synthetic email must match a lower-case address at example.test and have no existing binding.
2. ops/p09-test-qa-login.php — private CLI with plan and create only. Create requires root, the **same fixed root-owned verifier**, the **original detached viewer signature**, and a separate detached signature over a freshly reviewed login-specific digest. The new login plan binds exact existing membership, recipient, email, scope, expiry, role and no-subscription/no-role effects. Passwords are read through stdin only. The service hashes the password through AccountService and leaves must_change_password=true. No extra membership, identity, subscription, password reset, release, model, schedule or web route.
3. tests/integration/p09_qa_viewer_login_only.php — dedicated disposable MariaDB proof of signed membership -> login, strict role vocabulary, bad signature/cross-scope/environment/recipient refusal, forced password change, login retirement and M02 expiry.
4. ops/acceptance/p09-viewer-browser.mjs — a distinct browser runner. Login authentication necessarily performs a POST. **After login, no POST, PATCH, PUT or DELETE is deliberately issued.** Authorized pages are GET-only. No workspace-picker POST, no account password-change POST, setup apply, schedules, admin mutation, fake product fixture or CSRF-negative POST. Any required password change or workspace chooser is reported BLOCKED for separately authorized account/session setup. Inaccessible detail routes are NOT_COVERED, not positives.
5. This operator/proof packet.

## Important approval separation

The original signature proves the bound viewer membership, not consent to create a new login. The login operation now requires a **second cryptographically distinct owner signature** over the P09_TEST_QA_LOGIN_V1 digest created by the private login PLAN after the viewer membership exists. Its exact domain-separated bytes are `P09_TEST_QA_LOGIN_V1\n<lowercase-login-plan-digest>\n` (actual LF characters). Neither a CLI command, correct digest, or TEST email grants that decision. The reviewer must authorize the exact recipient, membership, email, run purpose, password-change setup, expiry, operator and cleanup.

The root-owned trust anchor is expected at /srv/allfather/.private-ops/p09_bootstrap_owner_ed25519.pub (directory 0700, public key 0400). Both independent detached signatures must remain in separate non-web, non-writable, root-owned files, not a shell argument, environment variable or GitHub asset. Its private Ed25519 key NEVER goes to Railway. Use a verified private operator shell; connected Railway service controls alone do not open a shell or prove root. Reverify this key path after each fresh container deployment.

## Authorized chronological operation (NOT execution permission)

1. Qualify exact branch on disposable PHP 8.2/MariaDB 10.11 with full strict seven layers and focused tests; run PHP syntax and Node syntax. Check MariaDB 10.6 compatibility as separately required; 10.11 alone is partial qualification.
2. Independently review branch, find any authority edge or accidental widening; fix, retest, then only separately approve source merge.
3. Qualify merged main; optionally deploy **only after** a separately reviewed source-pin/staging release decision. No secrets, volume, runtime DB1, scheduler or canonical adapter mutation.
4. Before signing anything, complete a current private SELECT-only inventory. Verify TEST tenant/workspace and operator, 3-permission canonical role, no historical login or membership, 0 admitted grants/schedules, maintenance and other runtime preconditions.
5. Separately approve offline Ed25519 key custody and the root verifier fingerprint. Generate the exact viewer plan **immediately before execution**, with valid_from within +/- 5 minutes of apply and <=1 hour duration. Review exact UUIDs/role/window and sign ONLY the P09_TEST_QA_VIEWER_V2 domain-separated digest offline. Independently authorize verifier enrollment, then authorize membership apply as its own act.
6. Obtain a distinct owner approval to create a TEST browser credential. With the exact active signed membership ID, matching digest and TEST-only email, use login CLI PLAN. Review its complete definition and new **login_plan_digest**. Have the offline owner signer independently sign the exact `P09_TEST_QA_LOGIN_V1` domain-separated digest; store its detached signature separately from the prior viewer signature. Then, and only then, authorize CLI CREATE, with the original viewer signature, a distinct login signature and secret passphrase fed through stdin. The CLI does not accept a password flag. It keeps must_change_password=true.
7. Satisfy the existing forced password-change flow and any necessary workspace selection as **separately authorized session setup** outside the GET-only QA harness. If not done, browser harness will report BLOCKED; it must never bypass the login policy. The limited membership window may be too short to complete setup: if it expires, fail closed and use an entirely NEW pre-approved operator; the same recipient cannot be re-enrolled.
8. Once the credential/session is ready, run GET-only Desktop (1440x900) and Mobile (390x844) smoke with privately supplied P09_EXPECTED_RELEASE_SHA, P09_TEST_SCOPE_REVIEWED=YES, TEST_LOGIN_EMAIL, TEST_LOGIN_PASSWORD. Retain only the bounded sanitized result. No API credentials or cookie bytes in artifacts.
9. Immediately use separately authorized ops/manage-beta-user.php retire (with exact synthetic email and tenant) to retire the login and end sessions. The membership remains in status ACTIVE until an **independent early revoke** (not implemented here) or its valid_until time. Under this conservative path, wait for valid_until and privately prove effective M02 DENY with a fresh SELECT-only audit. Record actual historical row status accurately. Never reuse the same operator, run direct UPDATE, or grant TenantAdmin to clean up.
10. File sanitized PASS/FAIL/NOT_COVERED/BLOCKED results against Issue #35. No overall P09 browser acceptance, P10, B2, ROAD_TEST or production GO is implied.

### Private CLI interface (template only — no real IDs)

php ops/p09-test-qa-login.php plan --env=/srv/allfather/.env \
  --tenant-id=EXACT_TEST_TENANT_UUID \
  --workspace-id=EXACT_TEST_WORKSPACE_UUID \
  --operator-id=EXACT_PREVIOUSLY_SIGNED_OPERATOR_UUID \
  --membership-id=EXACT_ACTIVE_SIGNED_MEMBER_UUID \
  --email=synthetic-qa@example.test \
  --viewer-plan-digest=EXACT_SIGNED_VIEWER_PLAN_SHA256

The create command uses the SAME exact scope flags and adds `--login-plan-digest=EXACT_SEPARATELY_SIGNED_DIGEST --signature-file=/root/private/p09-viewer.sig --login-signature-file=/root/private/p09-login.sig --password-stdin`. It must only be run on the separately approved short live window, not pasted into chat or GitHub with real IDs, and no password on argv. The two signature files are independent artifacts produced using the owner-held offline signing key; the original viewer signature cannot be reused as the login approval.

### Source verification checklist

- [ ] php -l app/Auth/TestQaViewerLogin.php
- [ ] php -l ops/p09-test-qa-login.php
- [ ] php -l tests/integration/p09_qa_viewer_login_only.php
- [ ] node --check ops/acceptance/p09-viewer-browser.mjs
- [ ] PHP 8.2, disposable MariaDB 10.11, focused p09_qa_viewer_login_only integration with --strict
- [ ] Entire seven-layer strict suite on exact branch, 0 fail, 0 skip
- [ ] MariaDB 10.6 full integration lane where available, 0 fail, 0 skip
- [ ] Browser harness browser version/install and static network-policy inspection
- [ ] Independent authority review and Codex comments addressed
- [ ] Staging source-pin updated only on separately approved deployment

**Do not label syntax check, static audit, unexecuted scripts, a GitHub Actions zero-step failure, or previous PR #47 qualification as a PASS for this new candidate.**
