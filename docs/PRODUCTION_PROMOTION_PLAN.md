# Production promotion plan (completion plan P12)

**Status: CONDITIONAL — not started, not authorized.** Nothing in this repository promotes anything
to production, creates a production identity, or widens an audience. This document is the order of
operations for the day the canonical owners decide to; until then it is a checklist that fails.

`ops/deploy/production-preflight.php` evaluates every prerequisite below against a release tree and
prints the full list of what is missing. On this tree it reports `NOT_READY` on six grounds.
The current implementation cannot recognize any adopted positive customer-audience contract:
recognized internal-only schema refuses, and missing/unrecognized schema reports UNKNOWN rather
than inferring permission. A future separately adopted contract would be required even to reach
`READY_FOR_OWNER_DECISION`. `grants_go` remains false, and production launch/customer visibility
need separate recorded owner decisions. No command here creates those decisions.

The report requires `--target=<exact-lowercase-host>` and `--release-ref=<full-sha>` to evaluate
host prerequisites. For each evidence kind, the latest production observation for that target
controls across releases; it must be an unambiguous PASS naming this exact release. A later failure,
stand-down or different-release observation invalidates an older PASS for this candidate. Tied latest
observations refuse independently of array order. Evidence for another target or staging cannot
satisfy or alter the selected production target's checks. The registry supplies reviewed pointers,
not independent verification of the referenced artifact bytes.

## 1. Why this package is conditional

The pinned canonical graph does not settle production. Specifically, today:

| Question | Canonical state | Where the projection shows it |
| --- | --- | --- |
| Which identity classes exist? | `LOCAL_DEV`, `TEST`, `DEMO` environment classes; `TEST`, `INTERNAL_DEMO_OWNER`, `FUTURE_RESERVED` tenant types | storage CHECKs in migrations 001/010; `ops/provision-staging.php` refuses production before opening a connection |
| Which audiences may be activated? | `OPERATOR_ONLY` and `TENANT_INTERNAL` only; every wider audience is designed-inert upstream (V1-GAP-093) | `output_visibility_profiles` CHECK; missing/unrecognized schema is UNKNOWN, never an unrestricted audience |
| Who may launch? | `production_launch_authorized`, `owner_go_granted`, `customer_visible_output_authorized` are permanent authority locks, all false | `upstream.lock.json` → `claim_boundary`; owner acts recorded upstream, never edited by code |
| Is the host isolated? | E.1 `FAIL_READABLE`, E.3 `UNVERIFIED`; staging runs under an owner-directed deviation that production cannot inherit | `deployment-evidence.json` → `PR15_CROSS_SITE_ISOLATION_FAIL` |
| Is recovery proven on production? | P10 backup/restore and P11 rotation tooling exist; `FULL_RESTORE_DRILL` / `KEY_ROTATION_DRILL` are valid evidence kinds, but no production drill is filed | production preflight requires both latest records to match the exact selected production target/release |

`APP_ENV=production` is already a strict bootstrap posture. It is **not** a grant: it does not create
a production identity, open a production ledger stream or widen an audience, and the preflight treats
it as one check among several.

## 2. Order of operations

Each step is a gate for the next. None may be skipped by a configuration value or a lock edit.

1. **Canonical decisions, upstream.** Identity classes for production tenants/workspaces/environment;
   internal versus customer audience semantics; which product modules are sold (full P03 release needs
   P08's open defects resolved, recurring operation needs P07 admitted, customer UX needs the selected
   P09 scope); source and output rights per audience; the hosting boundary. Billing, CRM, email and SMS
   stay out of scope unless separately authorized.
2. **Owner contracts, upstream first.** The M02 identity, M10 environment/reliance and M16 audience
   contracts change in canonical with their own schema, migrations and tests. Nothing is predeclared
   here: no enum value is added and no migration number is reserved until those decisions exist.
3. **Projection port.** Only the adopted owner changes are ported, as **new** additive migrations
   (numbers allocated then; applied migrations are never edited), with shared conformance vectors and
   source hashes, and the schema inventory, invariants and `SCHEMA_REACHABILITY.md` updated together.
4. **Distinct production identities.** New tenants, workspaces, credentials and M10 streams. Historical
   TEST/DEMO identities and streams are never converted in place or reinterpreted as production; any
   data deliberately carried over is an explicit, recorded intake.
5. **Hosting closure.** Either a production host that passes cross-site isolation with acceptable
   evidence, or a newly qualified isolated boundary. The staging deviation, the account-wide SSH login
   and the earlier failed/unverified isolation results stay reported as they are. The static public
   website stays separate from the application runtime.
6. **Offering documents, reviewed.** Source-use and disclosure, support, retention, incident and
   recovery, and product-claim documents for the actual offering, with owner and professional review
   where needed — never generated and labelled compliant.
7. **Release qualification.** Freeze a distinct production release; full strict suite on the supported
   MariaDB versions; exact Git-object release preparation; production host conformance, isolation,
   full-restore and rotation drills filed as evidence records by reviewed commit.
8. **Preflight.** `php ops/deploy/production-preflight.php --release-ref=<sha> --target=<host> --env=<private env>`
   must eventually report `READY_FOR_OWNER_DECISION` under the separately adopted positive contracts.
   The current negative scaffold cannot establish that state.
9. **Owner GO.** A separate, recorded owner decision authorizes production launch and, separately,
   any customer visibility. Only then may the corresponding lock flags change — upstream, by the owner.

## 3. What must never happen

- Inventing `CUSTOMER` or `PRODUCTION` vocabulary locally to make a CHECK pass.
- Editing `upstream.lock.json` or `deployment-evidence.json` from application code, a helper or the
  preflight. Evidence is filed by reviewed commit.
- Treating an observation (a passing check, a green suite, a successful deploy) as owner GO.
- Relabelling a TEST workspace, tenant or ledger stream as production.
- Enabling a wider M16 audience for content whose source-use profile does not allow it.
- Combining different host/release observations or ignoring later failures to claim readiness.

## 4. What exists now

| Artifact | Purpose |
| --- | --- |
| [`ops/deploy/production-preflight.php`](../ops/deploy/production-preflight.php) | read-only prerequisite report; no DB connection, no writes, no GO |
| [`tests/unit/production_preflight.php`](../tests/unit/production_preflight.php) | real CLI regressions for target/release binding, supersession, ties, unknown schema and unchanged authority files; synthetic records only |
| [`tests/acceptance/production_identity_visibility.php`](../tests/acceptance/production_identity_visibility.php) | identity/audience refusals at storage and owner level, the preflight's verdicts, no customer route |
| [`PRODUCTION_OPERATIONS_RUNBOOK.md`](PRODUCTION_OPERATIONS_RUNBOOK.md) | the operating procedures a production deployment would need, marked not in force |
