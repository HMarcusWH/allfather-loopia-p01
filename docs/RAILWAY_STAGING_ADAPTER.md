# Railway staging deployment adapter — proposal

**Status:** staging-only infrastructure adapter. Not a release qualification, active beta, or owner-go. This adapter does not change canonical upstream authority, `upstream.lock.json`, mission decisions, or product execution permissions.

## Scope and source of truth

- Canonical source remains `HMarcusWH/Allfatherbusinessintelligence`.
- This repository remains the bounded PHP/MariaDB **LOOPIA_SME_MVP** projection, even when placed on Railway.
- The existing Loopia staging deployment is not replaced by merging this adapter.
- Railway project: `allfather-staging`; environment: `staging`.
- A separate canonical Node.js runtime adapter is **not** implemented by this change.

## Runtime layout

`Dockerfile.railway` builds the pinned Node.js operator-console assets and runs PHP 8.2/Apache. Apache serves **only** `/srv/allfather/public_html`. Application code, private storage, migrations and `.env` remain outside the public document root. `.dockerignore` excludes secret and ephemeral files from the build.

`ops/railway/entrypoint.sh` requires `APP_ENV=staging` and required database/key variables. It writes an atomically replaced private `.env` readable by the Apache worker and refuses CR/LF injection. No database migrations, test runs, seed data, network collection, model calls, scheduler admission, or external actions are performed by this entrypoint. An absent master key or database variable refuses startup.

## Proposed service configuration (not an activation plan)

| Service | Source | Durable state |
| --- | --- | --- |
| `allfather-php-mvp` | This GitHub branch via `Dockerfile.railway` | Private volume at `/srv/allfather/storage` |
| `allfather-mariadb` | `mariadb:10.11` (pin digest for qualified release) | Private volume at `/var/lib/mysql` |
| `allfather-canonical-adapter` | **No source** until a reviewed host adapter exists | None |

The Railway trial currently limits individual volumes to 500 MB. The proposed 480 MB database volume is suitable only for a disposable feasibility test. The image does not implement a qualified backup/restore service, and the unverified shared-provider isolation and database runtime must be tested separately.

Railway env variables are bridged through the private startup file for the PHP projection. Do not log rendered variable values. Use only internal Railway networking between services; do not add a public database TCP proxy.

## Manual qualification sequence

1. Review the exact PR diff and external Docker image provenance. Record the reviewed commit SHA.
2. Confirm the private Railway project, secret names, volume mounts, build configuration and network restrictions. Do not deploy a root `main` build by inference.
3. Build the PHP image and verify its PHP extensions, Apache document root, front controller, static asset integrity, file permissions and startup refusal cases.
4. Boot the **disposable** MariaDB staging database; validate server version, persistence, and private network isolation.
5. Apply migrations explicitly with the reviewed repository CLI. Use a **separate** disposable qualification database for the strict test suite: tests truncate their database.
6. Run the strict exact-commit release-qualification procedure and retain its evidence without claiming a hosted GitHub PASS where no hosted checks ran.
7. Verify `/healthz`, private-path refusal (403/404), login/CSRF posture, inert scheduler, no grants/provider calls, isolated workspace behavior, web PHP permissions, restore rehearsal and rollback.
8. Compare results with the documented `docs/POST_MERGE_TEST_BACKLOG.md`, `docs/MVP_RELEASE_STATE.md`, and owner-gated beta runbook. Only an explicit reviewed owner decision may authorize any later activation.

**Absent or false until separately established:** current merged-main conformance, live-read activation, ROAD_TEST_001, production deployment, production launch approval, customer-visible release, external messaging/actions, and canonical runtime deployment.

## Constraints

Railway builds and volumes can consume trial credits. Do not deploy the empty canonical placeholder as though it were a runnable service. MariaDB data and PHP storage require tested backup/restore and security reviews before any non-disposable data is used.

A successful container build or 200 response does **not** establish application-level readiness, governance equivalence, or any new authority.
