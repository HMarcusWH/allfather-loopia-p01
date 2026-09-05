# DEPLOYMENT RUNBOOK

Staging deployment, verification, and stand-down for the Loopia host.

**Read `LOOPIA_CAPABILITY_REPORT.md` §5 first.** Nothing in this runbook constitutes launch
authority, and the four launch flags are all false.

---

## 1. What you are deploying

A PHP 8.2+ / MariaDB application with no external effect. It captures public web sources through a
guarded egress path and writes governed records. It sends nothing, publishes nothing, and performs
no consequential action — see `SECURITY_BOUNDARY.md` §1–§2 for why those are structural rather than
configured.

## 2. Prerequisites

| Requirement | Why it matters |
| --- | --- |
| PHP 8.2 or later | the code uses readonly promoted properties, enums, and `WeakMap` |
| extensions: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json` | `openssl` for credential encryption; `curl` with `CURLOPT_RESOLVE` for the pinned-address egress guard |
| MariaDB 10.5 or later | CHECK constraints must be ENFORCED, not parsed and ignored |
| `APP_MASTER_KEY` in server-private configuration | provider-credential encryption; never in the repository, never in the database |
| DB credentials via environment | `DB_HOST`/`DB_PORT` or `DB_SOCKET`, plus `DB_NAME`, `DB_USER`, `DB_PASSWORD` |

**Verify `CURLOPT_RESOLVE` exists before deploying.** Without it the egress guard cannot pin a
connection to the address it validated, and the guard's central property is gone:

    php -r 'echo defined("CURLOPT_RESOLVE") ? "ok" : "MISSING", PHP_EOL;'

## 3. Deploy

    git clone <repo> && cd allfather-loopia-p01
    cp .env.example .env && edit .env          # DB_*, APP_MASTER_KEY
    php ops/migrate.php status --env=.env      # report only, changes nothing
    php ops/migrate.php up --env=.env          # apply

Loopia offers no shell on the production host, so `ops/migrate.php` is also what the one-shot
maintenance route invokes. It never runs implicitly on a web request.

The migrator records a content digest per migration and reports each as APPLIED, PENDING or
DRIFTED. DRIFTED means the file changed after it was applied — the database and the repository
disagree about history, which is a condition for a human rather than for automation. Resolve it by
hand; do not re-run and hope.

## 4. Verify the deployment — do not skip this

### 4.1 The suite

    php tests/run.php --strict

Expect 1952 passing, 0 failing, 0 skipped. Under `--strict` a skip is a non-zero exit, so an
unreachable database cannot be mistaken for a green run.

### 4.2 CHECK constraints are ENFORCED

MariaDB before 10.2.1 parsed CHECK constraints and ignored them, and several of this system's
guarantees are CHECK constraints. Confirm the storage actually refuses:

    -- must FAIL
    UPDATE workspaces SET external_side_effects_allowed = 1 LIMIT 1;

If that succeeds, **stop**. The external-effect boundary is not being enforced, and
`SECURITY_BOUNDARY.md` §2 is not true of this deployment.

### 4.3 Append-only triggers survived the import

This is the check most likely to fail on shared hosting, because `DELIMITER`-defined triggers are
routinely lost in dump/import round-trips:

    SELECT COUNT(*) FROM information_schema.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE();

Then confirm one actually fires:

    -- must FAIL with 'decision_records cannot be deleted'
    DELETE FROM decision_records LIMIT 1;

If the triggers are missing, the append-only guarantees in `SECURITY_BOUNDARY.md` §7 do not hold and
rollback discipline (§6 below) is unenforceable. Re-apply the migrations rather than patching by
hand.

### 4.4 Egress denies what it should

The guard's refusals are unit-tested, but confirm the deployed configuration reaches the same
answer for a private address before trusting it with a real source.

## 5. Provision a tenant

1. Seed the platform permission vocabulary and roles (`M02\Permissions`).
2. Register the tenant capability (`P01\Registration::registerTenantCapability`) — this provisions
   the Valkyrie identity, governed tools, arbiter policy sets, M16 visibility profiles and the
   subscription tier in one governed act.
3. Subscribe the tenant to a tier. **A role grant is not a purchase**: both gates must pass, and
   they are deliberately separate.
4. Create audit profiles per category as needed.

## 6. Stand-down and rollback

**Rollback is never a delete.** The storage refuses one, and the reason is that a rollback which
destroyed the record would also destroy the proof the rollback happened. Five levers, in
increasing order of scope:

| Lever | Effect | Reversible? |
| --- | --- | --- |
| `M16::deactivateVisibility` | removes an artifact from the surface | yes, by a new activation |
| `EvidenceSpine::setSourceStatus(..., 'REVOKED')` | stops NEW captures immediately; existing evidence stays valid as history | yes, by re-activating |
| `OutreachArtifacts::revokeAuthorization` | an outstanding permission to act can never be consumed | no — issue a new one |
| `DecisionLedger::supersede` | withdraws reliance on a release; the original stays byte-intact | no — restoring reliance is a NEW decision |
| `M11::openIncident` | records that something needs looking at | resolved, not deleted |

A CONSUMED authorization is **not** revocable. What it authorized already happened, and that is
historical fact — recorded truthfully, never un-recorded.

After any stand-down, verify the ledger still verifies:

    php -r '... DecisionLedger::verifyStream($streamId) ...'   // expect status PASS

The chain must verify WITH the correction in it. That is what makes the withdrawal provable rather
than merely performed.

## 7. Monitoring

Run the audits on a schedule; each opens an incident through the certified M11 intake on failure:

| Audit | Asks |
| --- | --- |
| P01 source freshness | is the authority behind this source still current? |
| P01 diff integrity | does the recorded change still reconcile with its snapshots? |
| P04 distribution authority | is every released briefing still routed to a configured recipient? |
| P04 redaction policy | does each frozen profile still cover its role's current rules? |
| P04 stakeholder provenance + no-covert-telemetry | role-class only, individual-free, public-sourced |
| P05 evidence citation, draft-only, content compliance | every comparative claim cited; nothing external; no forbidden copy |
| P07-A × 5 | reconciliation, consent integrity, content, PII firewall, **external-effect guard** |
| M11 ledger integrity | does the decision chain still verify? |

The external-effect guard is the one to alert on loudest. It is S5, and a positive finding means
the deployment did something this codebase has no path to do.

## 8. Known untested surface

Stated so nobody assumes otherwise:

- **This has never run on Loopia shared hosting.** Everything above is verified on a development
  container with PHP 8.4.19 and MariaDB 10.11.14. §4.2 and §4.3 exist precisely because shared
  hosting is where they are most likely to fail.
- Hosted CI has reported green on pushed commits, but was not inspected run-by-run in the session
  that wrote this. `CONFORMANCE_MATRIX.md` §1 does not claim a hosted PASS it did not read.
- No road test has been executed. No live source has been captured outside the test suite.
