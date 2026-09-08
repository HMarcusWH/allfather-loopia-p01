# Allfather Loopia — SME MVP deployment projection

Governed PHP/MariaDB deployment projection of the Allfather BI SME intelligence product graph, for
`platform.allfatherbi.com` (production) and `staging.allfatherbi.com` (staging).

> **The repository name is historical.** This projection began as `LOOPIA_P01` — a P01-only
> deployment. It now carries the widened SME MVP scope. See
> [`PROJECT_BOUNDARY.md`](./PROJECT_BOUNDARY.md) §1–§2 for the lineage and what exactly changed.

## Authority boundary

This repository is **not** the canonical Allfather runtime and is **not** a fork or replacement
for it.

- Canonical upstream: `HMarcusWH/Allfatherbusinessintelligence`
- Pinned upstream head: `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`
- Deployment projection: `LOOPIA_SME_MVP` (historically `LOOPIA_P01`)
- Target runtime: PHP 8.2+ / MariaDB on Loopia shared hosting
- Authorized action classes: **A (observe), B (analyze), C (governed intelligence release)**
- D/E external action authority: **none**

Canonical runtime law remains upstream. This repository implements only the bounded behavior
needed to deploy the already-gated product graph on the Loopia host, with explicit upstream
pinning and conformance evidence.

## Scope

| Included | Excluded |
| --- | --- |
| P01 Competition Monitoring | P07-B live external send |
| P02 entity + relationship twins | P02 offering twins (not promoted upstream) |
| P03 Lead Qualification + ICP extension | P02 EOM (absent upstream) |
| P04 internal Intelligence Distribution | SEV / GSMC / adaptive config / kernels |
| P05 internal Marketing drafts | any external action, publish, send, or charge |
| P07-A synthetic outreach preparation | |
| M01–M12, M15, M16 (reachable slices) | |

Every inclusion is bound to a recorded upstream owner gate. See
[`docs/PORT_SCOPE_MATRIX.md`](./docs/PORT_SCOPE_MATRIX.md).

**Inclusion is not uniform depth.** This is a vertical slice: deepest at P01, P04 and P05, and
progressively shallower through P02 and P03 — P02 stops at the promoted entity/relationship twin
slice and has released snapshot paths, while P03 includes governed released prospect outputs. P03
still lacks the full assurance/audit profile used by the deeper slices. What is present and what is
absent per object is in [`PORT_SCOPE_MATRIX.md`](./docs/PORT_SCOPE_MATRIX.md) §3, with the gaps
collected in §3.7.

## Documentation

| Document | What it records |
| --- | --- |
| [`PROJECT_BOUNDARY.md`](./PROJECT_BOUNDARY.md) | scope contract, lineage, hard prohibitions |
| [`UPSTREAM_AUTHORITY.md`](./UPSTREAM_AUTHORITY.md) | precedence and update rules |
| [`upstream.lock.json`](./upstream.lock.json) | exact upstream pin, scope, gate authority, claim boundary |
| [`docs/PORT_SCOPE_MATRIX.md`](./docs/PORT_SCOPE_MATRIX.md) | per-object include/exclude with authority |
| [`docs/SCHEMA_REACHABILITY.md`](./docs/SCHEMA_REACHABILITY.md) | how the MariaDB schema was derived |
| [`docs/CONFORMANCE_PLAN.md`](./docs/CONFORMANCE_PLAN.md) | fixture format, layers, required refusal cases |
| [`docs/CONFORMANCE_MATRIX.md`](./docs/CONFORMANCE_MATRIX.md) | fixture-by-fixture results |
| [`docs/SECURITY_BOUNDARY.md`](./docs/SECURITY_BOUNDARY.md) | egress, auth, isolation, secrets, evidence |
| [`docs/LOOPIA_CAPABILITY_REPORT.md`](./docs/LOOPIA_CAPABILITY_REPORT.md) | what this projection can and cannot do, and what is left before launch |
| [`docs/DEPLOYMENT_RUNBOOK.md`](./docs/DEPLOYMENT_RUNBOOK.md) | staging/production procedure, rollback, restore |
| [`docs/CLAIM_CEILING.md`](./docs/CLAIM_CEILING.md) | what may and may not be claimed |
| [`docs/LOOPIA_HOST_MODEL.md`](./docs/LOOPIA_HOST_MODEL.md) | what the target host actually is, sourced from Loopia's own documentation, and what it does NOT document |

## Current status

**Implementation in progress. Nothing is deployed and nothing is activated.**

```text
road_test_001_executed       = false
production_launch_authorized = false
external_action_authorized   = false
live_read_activated          = false
owner_go_granted             = false
customer_visible_output      = false
staging_deployed             = false
conformance_complete         = false
```

Merging code here establishes no deployment, no activation, and no customer-visible output. The
launch sequence is: implementation complete → conformance complete → staging deployment → Loopia
capability validation → controlled road test → rollback/restore verification → owner review →
explicit owner-go → production activation. Each is recorded separately.

## Development

Requires PHP 8.2+ (`pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`) and MariaDB 10.6+.

```bash
cp .env.example .env          # then fill in DB_* and APP_MASTER_KEY
composer install
php ops/migrate.php up        # apply migrations
composer check                # lint + full test suite
```

`composer check` runs lint, unit, integration, conformance, acceptance, security and rollback
layers. The DB-backed layers require a reachable MariaDB and are **skipped loudly**, never
silently passed, when one is absent.
