# WP-P09-04 — signed, one-use TEST initial-authority bootstrap (source proposal)

**SOURCE CANDIDATE ONLY. NOT AN AUTHORIZATION, LIVE DEPLOYMENT OR P09 ACCEPTANCE RECEIPT.**

## Evidence from Railway staging, 2026-10-09

Two independent private, read-only audits observed 7 active TEST tenant/workspace
pairs, 14 ACTIVE but **unbounded** membership rows, 7 retired logins,
30/30 revoked sessions, 7 unbound active operators, and **zero** operators
with the simultaneous tenant.administer, role.assign, role.revoke authority.
Monitor execution grants and schedule rows were zero. No monitor mission config,
run, setup receipt, source/evidence object, provider credential, or released P02/P03
test output was observed.

The ordinary AccountService::addRoleMembership path is not bounded and cannot
be used as a temporary P09 grantor. TestMembershipLifecycle::planAssign
requires an existing authorized grantor. AccountService::reactivate refuses
the RETIRED state, so the old browser accounts cannot be reactivated.

## Narrow exception

The private CLI ops/p09-bootstrap-test-grantor.php has TWO commands:
plan (read-only) and apply (separate signed owner decision required).
The only effect of approved apply is inserting **one** finite M02 membership
for an EXISTING active, otherwise unbound TEST operator:

- TEST tenant and TEST workspace must be ACTIVE, environment_class TEST,
  external_side_effects_allowed=0, and PHP APP_ENV=staging/testing.
- Target must already belong to the tenant and have **no previous platform login
  or any operator workspace membership**, in any workspace.
- Role is **exact canonical ROLE_TENANT_ADMIN v2**, checked against the complete
  pinned M02 Permissions::roleVersionDefinitions vocabulary. Any role status,
  display-name or permission drift fails closed. This is a powerful role,
  *not* a read-only QA membership; it includes tenant.administer, role.assign,
  role.revoke and other canonical Admin-v2 permissions.
- Window is at most **one hour** and starts within five minutes of current UTC.
  The receipt and persisted row retain exact start/end and role version.
- An existing TenantAdmin-v2 membership record **anywhere in the same tenant**
  blocks the exception forever while that audit history is retained. Even an
  EXPIRED or REVOKED row blocks a new bootstrap. A tenant row FOR UPDATE lock
  serializes competing apply operations. DBA deletion of historical rows is
  outside the protection provided by this application-only mechanism.
- The bootstrap creates NO platform login or password, new tenant/operator,
  schema change, public route, provider/model/source call, execution grant,
  mission setup, proposal, scheduled job or release.
- No other or foreign tenant workspace is authorized, and neither normal
  onboarding nor the regular P09 role lifecycle is altered.

## Offline owner approval / cryptographic gate

The plan output contains the **entire** exact canonical role permission set,
operator, workspace, tenant, expiry, role ID, and SHA-256 plan_digest. This plan
does not constitute owner authorization.

A separately held **Ed25519 owner signing key** signs the exact ASCII bytes:

    P09_TEST_BOOTSTRAP_V1\n<lowercase 64-character plan_digest>\n

The actual message contains literal LF newlines, including one at the end.
The 64-byte detached signature is base64-encoded into a private signature file,
never placed on a command line or into GitHub. The owner's **private signing key
must never be put on Railway or Loopia.**

A trusted human operator must enroll only the 32-byte base64 public verification
key at the FIXED path (relative to the repository runtime root):

    .private-ops/p09_bootstrap_owner_ed25519.pub

The directory AND file must be root-owned, non-symlinked, unreadable to the web
runtime, and group/world non-writable. **Do not use the application storage
volume** (owned by www-data) for this trust anchor. Neither the CLI nor the
library generates, enrolls or accepts an arbitrary caller-supplied public key.
No trust-on-first-use is allowed. Creating the root-controlled location is an
explicit, separate owner-controlled deployment step **not performed by this PR**.
Until enrolled, apply refuses. Review whether the host's runtime filesystem and
access control can satisfy this invariant before attempting any real approval.

The operator must retain the signed decision artifact, full plan, signed digest,
owner identity, chosen scope, proof of current role vocabulary, the expiry and
stand-down instructions in the protected evidence register. An exact digest
alone is not a signature and cannot authorize applying the plan.

## Proposed procedure (not executed)

1. Privately inspect the real IDs and all membership/login histories in a single
   eligible TEST scope. Never reuse the 7 retired platform-user accounts.
2. Plan with explicit private env and exact arguments, for example:

    php ops/p09-bootstrap-test-grantor.php plan \
      --env=/private/staging.env \
      --tenant-id=UUID --workspace-id=UUID --operator-id=UUID \
      --valid-from=YYYY-MM-DDTHH:MM:SS.sssZ \
      --valid-until=YYYY-MM-DDTHH:MM:SS.sssZ

3. Independently review the exact broad Admin-v2 permission set, one-hour clock,
   tenant, workspace, operator, other membership state, access risk, restoration/
   disaster recovery posture, maintenance state, owner identity and revocation plan.
   The owner must explicitly authorize THIS decision, not merely say "proceed".
4. A trusted operator installs the owner public verification key outside the
   writable volume. The owner signs the exact plan digest offline, supplying
   a private detached signature file. Only after approval, run:

    php ops/p09-bootstrap-test-grantor.php apply \
      --env=/private/staging.env \
      --tenant-id=UUID --workspace-id=UUID --operator-id=UUID \
      --valid-from=YYYY-MM-DDTHH:MM:SS.sssZ \
      --valid-until=YYYY-MM-DDTHH:MM:SS.sssZ \
      --plan-digest=LOWERCASE_SHA256 \
      --signature-file=/private/owner-approval.sig

5. Re-query that *single* membership and verify canonical version, start/end,
   exact operator/workspace, and owner signature verification receipt.
   Separately plan a target QA role via ops/p09-test-membership.php; every
   subsequent assignment remains subject to its own independently approved plan.
6. Review creation of a **fresh**, short-lived browser account using AccountService;
   do not silently attach to an operator who already has an unbounded role.
7. Stand down by separately approved P09 plan-revoke/revoke while the Admin seat
   still has authority. It also expires in AccessGuard after its finite window.
   Rebootstrapping is never the cleanup path.

## Assurance / release boundary

tests/integration/p09_signed_test_bootstrap.php tests wrong environment,
tenant crossover, changed permission set, unbounded legacy member, prior
RETIRED login, one-hour boundary, missing/forged/foreign signature, valid
signature with incorrect state digest, successful finite membership,
single-use replay even after revocation, and M02 permission denial on stand-down.
Run DB tests against disposable MariaDB only, never against Railway's staging
volume, a P10 preserved restore DB, or the original Loopia live DB.

Code must pass syntax checks and independent MariaDB 10.11 qualification
before merge. GitHub Actions may fail because of unavailable minutes; that is
not a green CI receipt. **Do not merge/deploy/apply under this document alone.**

No live Railway services, roles, identities, database rows, network routes,
provider connections or schedules are changed by the source-only proposal.
P09 browser acceptance, P10 recovery, and B2 real-model authority remain
separate gates.
