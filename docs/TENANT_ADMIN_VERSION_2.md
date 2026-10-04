# Tenant Administrator version 2 — explicit setup authority

The canonical M02 specification assigns `runtime_host_binding.administer`, `valkyrie.register`
and `valkyrie.administer_contract` to Tenant Admin. The original shipped v1 role omitted them, so
an operator holding every shipped role still could not satisfy MonitorSetup's setup guard.
This correction adds a distinct TenantAdmin **version 2** with the original ten permissions plus
exactly those three. It does not weaken MonitorSetup or its M01/M03/M04/M06 checks.

The legacy `Permissions::ROLES` map stays unchanged. `VERSIONED_ROLES` declares the additive
successor; `roleVersionDefinitions()` returns all explicit key/version definitions. Vocabulary
provisioning creates missing definitions in one transaction, compares existing permission sets
and display names exactly, and refuses conflicting definitions rather than repairing them. It
preserves role status, IDs, memberships, validity windows and revocation history. A suspended
role is not reactivated by seeding.

`Seed::roleIdFor(key)` and `AccountService::addRoleMembership(..., key)` continue to select v1.
An explicit version argument is required for v2. The onboarding CLI likewise selects v1 in both
preflight and apply; repeating onboarding does not upgrade an existing administrator. Unknown,
revoked and expired role/membership states remain refusals. This source change assigns no
membership and admits no native execution.

## Provision vocabulary separately from assigning authority

On the reviewed staging/testing release, the narrow command below calls only the M02 vocabulary
owner. It requires explicit apply and retains the normal maintenance lease. It does not create
tenants, workspaces, operators, subscriptions, credentials, capabilities or memberships:

```sh
php ops/provision-role-vocabulary.php --env=.env --apply
```

Run this only as an authorized deployment operator after reviewing the exact release. A successful
result reports how many definitions were added; a repeat reports zero. A conflict stops the
transaction. Do not use `provision-staging --allow-existing` for a vocabulary-only repair: that
command also creates scope objects. Production is refused by this narrow command.

An existing operator's assignment is a **separate reviewed act**, with the exact tenant, workspace,
operator, role ID/version, permission set and validity window bound to the reviewed plan. The
supported API is `AccountService::addRoleMembership(tenantId, workspaceKey, operatorId,
'ROLE_TENANT_ADMIN', 2)`. It is an operator service, not a public authorization endpoint: callers
must establish legitimate assignment authority (`role.assign`) and reviewed current state before
calling it. No automatic assignment, public route or general assignment CLI is added here.

Monitor setup additionally requires the existing Workspace Operator and applicable product roles;
TenantAdmin v2 alone does not grant mission activation, product routing or action approval.
The integration test now uses actual seeded v2 plus the scoped Workspace Operator, Competition
Monitor and Intelligence Distributor roles instead of a custom all-permissions fixture. Source
review, entitlement, configured context, prompt approval and execution admission remain distinct.
P07/P09/P12 and production/external action authority are outside this repair.

## Source lineage and verification

This is a selected correction to the canonical M02 role specification and versioned vocabulary.
The retained base pin in `upstream.lock.json` stays unchanged. The scoped source receipt in
`docs/reconciliation/tenant-admin-v2-source.json` records the exact reviewed upstream files.
The baseline projection is `2ada973c520a32a2a4d03e79d9c1e3a55b10ee6a`; subsequent unrelated role
additions, including the scheduler observer, retain their original permission sets.

Tests cover installed-v1 preservation, additive/idempotent provisioning, actual onboarding CLI
replay, deliberate version2 assignment, cross-scope refusals, revoked/expired memberships,
permission conflict refusal, excluded/approval denials, and the real inert Monitor setup chain.
Saved qualification receipts distinguish local PHP/MariaDB versions and hosted CI; this document
is not a deployment or activation claim.
