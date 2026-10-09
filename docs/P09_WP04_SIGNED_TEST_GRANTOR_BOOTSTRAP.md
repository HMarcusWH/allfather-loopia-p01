# WP-P09-04 — Owner-signed, read-only TEST QA viewer (PR #47)

**DRAFT SOURCE ONLY — NOT LIVE AUTHORIZATION OR DEPLOYMENT.**

## Why the earlier initial grantor was withdrawn

Read-only Railway inventory observed 7 TEST tenants/workspaces and 7 unbound
operators, 14 ACTIVE legacy unbounded memberships, 7 RETIRED login accounts,
30 REVOKED sessions, and zero effective P09 role-management grantors. The
initial PR candidate proposed a <=1h TenantAdmin-v2 grantor to create
subsequent bounded memberships. **That is rejected as unsafe for QA:**
TenantAdmin-v2 can register identities, administer runtime bindings,
assign roles, and create longer-lived authorities unaffected by expiry
of the original one-hour membership.

The privileged class `TestAuthorityBootstrap.php` was deleted. Its signed
plan domain `P09_TEST_BOOTSTRAP_V1` has been superseded, never reused.

## Replacement: one directly owner-signed viewer seat

`app/Auth/TestQaViewerBootstrap.php`, called by the private CLI
`ops/p09-bootstrap-test-grantor.php` (legacy entrypoint filename; now
viewer-only), may create exactly **one active ROLE_DEMO_VIEWER v1 membership
per independently authorized plan** for an existing, otherwise unbound
operator in an existing isolated TEST tenant/workspace.

- The exact role permissions are `workspace.read`, `decision.read`,
  and `internal_release.read`. The full canonical role vocabulary
  and an independent pinned read-only allowlist must both match; any
  drift refuses.
- The role does **not** provide `tenant.administer`, `role.assign`,
  `role.revoke`, `mission.activate`, `tool.invoke`, outbound
  delivery, approvals or identity management.
- The recipient must be an existing ACTIVE operator belonging to the
  ACTIVE TEST tenant; the ACTIVE workspace must be TEST class/type
  with `external_side_effects_allowed=0`.
- Recipient must have **no historical login** and no historical operator
  membership (including revoked, expired or foreign-workspace rows).
  This prevents silent renewal for the same recipient. A DIFFERENT
  recipient requires a different independently signed plan; the
  implementation does **not** claim global tenant-wide single-use.
- Window starts within five minutes of current UTC and lasts at most
  3,600 seconds, enforced in integer milliseconds. M02 AccessGuard
  enforces expiry and revocation even if no cleanup job runs.
- `apply` uses an InnoDB tenant row `FOR UPDATE` lock, repeats the
  exact preconditions and plan digest, and commits only the bounded
  membership. `plan` is read-only. No login/password, new operator,
  scheduler, model/provider operation, mutation of existing role or
  entity, schema migration or web route is created.

**It is deliberately NOT a first administrator or a replacement for
`TestMembershipLifecycle`.** This allows read-only browser smoke tests
after separately approved QA account creation. P09 operator/write-role
tests remain blocked until their own separately designed least-privilege
grantor mechanism; do NOT use legacy unbounded
`AccountService::addRoleMembership` as a shortcut.

## Independent signature / trust anchor

The signed approval is SHA-256 of the exact canonical definition, including
runtime `app_environment` (`testing` and `staging` signatures are
not interchangeable), tenant, workspace, recipient, role ID/version, full
permissions and validity window. The new domain-separated message is:

    P09_TEST_QA_VIEWER_V2\n<lowercase-plan-digest>\n

Those \n escapes above denote actual LF characters, including trailing LF.
An owner-held offline Ed25519 private key signs this exact message.
Neither the private signing key nor the owner's password is uploaded to
Railway, GitHub, container environment variables or chat.

The only enrolled material on the private app container is the pre-verified
base64 public key in `/srv/allfather/.private-ops/p09_bootstrap_owner_ed25519.pub`
(root-owned file mode 0400 in a root-owned directory mode 0700), plus an
owner-signed detached signature in a separate absolute path, root-owned,
group/world-inaccessible, not symlinked, and outside the served
`public_html` and writable `storage` volume.

The private CLI requires effective UID 0 and acquires the normal
`BetaCli::connect` maintenance lease. The verifier path is FIXED;
there is no caller-selected verification key. The source tree and
Docker context exclude `.private-ops/`.

Railway supports SSH into the running service and SFTP/SCP. The
container filesystem outside persistent volumes is EPHEMERAL:
a trusted operator must verify the root identity and enroll the
approved public verification key separately for each authorized run,
with explicit owner approval. Actual live key enrollment, permission
validation through SSH, CLI apply and account creation **have not
occurred**. Railway admins with root/source/DB write access remain
inside the infrastructure trust boundary; this scheme does not
cryptographically prevent a privileged platform operator from
tampering with code or data.

## Review and execution boundaries

1. Privately select an existing TEST tenant, workspace and unbound
   operator; verify all underlying IDs, histories, allowed role
   definition and absence of model/execution/schedule authority.
2. The private CLI `plan` prints the full exact role/permission
   definition and digest; planning does not grant anything.
3. Owner reviews the full bounded plan, QA purpose, expiry, scope,
   cleanup and recipient, signs the exact domain-separated digest
   offline and independently attests the enrolled public key.
4. Only a separate, explicit execution approval may authorize
   `apply`; a generic "proceed" to source development is not approval.
5. Separately approve a fresh TEST browser login, test only allowed
   read-only UX, retire it, revoke the membership and recheck all
   sessions, effective permissions, execution grants and schedules.
6. No role-management, write-role, production, P10 disaster-recovery
   or B2 real-model operations are authorized by this workflow.

## Assurance

The focused MariaDB integration test covers canonical role drift,
non-TEST/foreign scope, retired login and legacy membership refusal,
Ed25519 signature validation, cross-environment and cross-recipient
replay resistance, one-hour expiry, M02 read allows and admin/execute
denials, explicit revocation, and immutability for the same recipient.

Run all tests ONLY on disposable isolated MariaDB, never on Railway
staging's persistent database or Loopia. Exact new head must be tested
separately from any prior full-suite qualification result.

**PR #47 remains draft and unmerged. Do not deploy, enroll an owner key,
sign or execute an apply from this runbook alone.**
