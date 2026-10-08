# WP-P09-03 — bounded TEST-only M02 membership lifecycle (source proposal)

**Status: SOURCE-ONLY REPAIR; NOT AUTHORITY, NOT APPROVAL, NOT STAGING EXECUTION.**
Scope is the `allfather-loopia-p01` projection's PHP TEST operator surfaces,
not a modification of canonical M02 role semantics. No new role, permission,
database table, migration, public HTTP route, provider transport, schedule,
model call, login, subscription or credential. Legacy
`AccountService::addRoleMembership` and normal beta onboarding are unchanged.

## Problem and design boundary

The legacy API records `valid_from` but **no** `valid_until`; it is therefore
unsuitable for a P09 QA operator role claimed to automatically expire. Login
retirement is separately supported but it is not membership revocation.
`AccessGuard::activeMembershipRoleIds` already respects current validity
and `status='ACTIVE'`, so no authority-model change or migration is needed.

`app/Auth/TestMembershipLifecycle.php` is a separate exception for a
dedicated `tenant_type=TEST`, `workspace_type=TEST`,
`environment_class=TEST`, ACTIVE scope with external side effects prohibited,
in a trusted PHP `APP_ENV=staging|testing`. It requires existing `tenant.administer`
plus `role.assign` to plan/create, and `tenant.administer` plus `role.revoke`
to plan/revoke. An unprivileged operator cannot grant itself these permissions
through this owner. Self-assignment is explicitly refused.

**Allowlisted role/version pairs only:** TenantAdmin v2; Workspace Operator,
Intelligence Distributor, Competition Monitor, Entity Twin Curator and Lead
Qualifier v1. The owner compares the actual installed role display name and
entire permission set against `Permissions::roleVersionDefinitions()`. A future
successor, custom role or broader replacement is refused. Product roles
still contain proposal/review/release/routing powers and must receive a separate
necessity assessment. Browser GET-only behaviour does not narrow their rights.

The exact canonical UTC start and expiry must be supplied; `valid_until` may not
be null, duration may not exceed 24h, start may not be more than 24h before/after
the currently checked time, and expiry must be in the future. An assigned role
expires in M02 access evaluation even without a cleanup job. A separate reviewed
revocation immediately changes the *specific* existing bounded membership to
`REVOKED`. Historical rows remain; revoked/expired/pre-existing assignments are
never extended, revived or converted by repeating this API. Legacy unlimited seats
cannot be revoked through this narrow tool.

## Exact plan and execution boundaries

Private CLI: `ops/p09-test-membership.php`; server PHP CLI only. It always
requires explicit `--env` whose `APP_ENV` resolves to `staging` or `testing`,
uses the normal maintenance lease, and never accepts secrets, cookies or passwords.

**Assignment (future, separate owner approval required):**

```sh
php ops/p09-test-membership.php plan-assign --env=/private/staging.env \
  --tenant-id=<ACTUAL_TEST_TENANT_UUID> \
  --workspace-id=<ACTUAL_TEST_WORKSPACE_UUID> \
  --operator-id=<ACTUAL_AUTHORIZED_ASSIGNER_UUID> \
  --target-operator-id=<ACTUAL_TEST_OPERATOR_UUID> \
  --role-key=ROLE_WORKSPACE_OPERATOR --role-version=1 \
  --valid-from=<UTC_CANONICAL_TIMESTAMP> \
  --valid-until=<UTC_CANONICAL_TIMESTAMP>
```

Only after an independent owner decision names the **actual reviewed packet**
(scope and role ID/version and full permissions, actor, validity period,
current-state baseline, planned operations, and cleanup) and exact plan digest
may an authorized private operator run `assign` with all same flags and
`--plan-digest=<64 lowercase hex>`. This CLI does not verify owner
signatures or attestations and **must not be treated as authorization by itself**.
The digest binds the plan to the live role and original ABSENT membership state.
Assignment re-evaluates the complete plan under a tenant DB lock; a changed
plan or mismatched digest refuses atomically.

**Revocation (future, separate stand-down approval required):**

```sh
php ops/p09-test-membership.php plan-revoke --env=/private/staging.env \
  --tenant-id=<ACTUAL_TEST_TENANT_UUID> \
  --workspace-id=<ACTUAL_TEST_WORKSPACE_UUID> \
  --operator-id=<ACTUAL_AUTHORIZED_REVOKER_UUID> \
  --membership-id=<ACTUAL_BOUNDED_MEMBERSHIP_UUID>
```

After a separate reviewed decision on its plan, use `revoke` with all same
flags plus `--plan-digest=<exact reviewed digest>`. The owner re-checks
membership identity, role version, scope and prior status inside a transaction;
it updates only `status='REVOKED'`. A retained M02 ALLOW evaluation records the
actor and target membership for the privileged mutation. Preserve the CLI
receipt and independent owner decision in the protected evidence register.
Re-reading the membership and trying an M02 permission check must verify
effective DENY. Expiry protects the interval even before formal stand-down.

## Checks / qualification still due

- `tests/unit/p09_test_membership_window.php`: deterministic no-DB boundary tests.
- `tests/integration/p09_bounded_test_membership.php`: MariaDB 10.6/10.11,
  exact grants, role-version pinning, forged digests, absent and revoked states,
  foreign scope, permission denial and no legacy escalation.
- Existing CI runner discovers these tests automatically and lint-checks
  every `app/`, `ops/` and `tests/` PHP file. No GitHub Actions workflow change.
- **No hosted qualification** is implied by a GitHub PR or independent syntax
  checks. Do NOT run destructive `Harness::freshDb()` against the Railway
  staging runtime or preserved P10 restore volumes. Use disposable dedicated
  MariaDB 10.6 and 10.11 databases only.

## Before any future role assignment

1. Owner-approved read-only private DB snapshot: exact tenant/workspace/operator
   identities, currently active/expired/revoked memberships, role definitions,
   grants/schedules, role.assign/revoke actor and migration state.
2. Verify `APP_ENV`, deployment SHA and private `staging` TEST scope.
   Source-main and Railway deployed SHA must not be confused.
3. Separately review and approve the exact membership plan *after* true current
   state is known. The owner must expressly accept the broader rights on any
   P02/P03/P04 seat; no custom/read-only role is silently minted.
4. Only then use the private CLI to assign, strictly within 24 hours. Subsequent
   P09 browser execution and each profile/source/fixture/credential operation
   require their **own** authority checkpoint. No providers or schedule admissions.
5. Stand down/retire TEST logins, revoke every bounded membership with approved
   receipts, and verify both explicit revocation and effective M02 DENY.

This source PR does **not** close P09 Issue #35, P10 Issue #43, or B2 Issue #44.
