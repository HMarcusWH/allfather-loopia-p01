# CI-FIX-05 — protected source promotion and Railway staging admission

**Scope:** `HMarcusWH/allfather-loopia-p01`, prepared 2026-10-25. **Source PR and owner-controlled settings are different deliverables.** This document grants no deployment, model execution, source fetching, customer visibility, new permission or production GO. It cannot itself activate GitHub branch protection or Railway Wait for CI.

## Identity and authorization do not collapse

`CODE_ONLY=PASS` **does not imply** `INERT_COPY=READY`, `MANUAL_PILOT=GO`, `CUSTOMER_VISIBLE=TRUE` or `PRODUCTION=GO`. Preserve the four reviewed `docs/decisions/*.json` owner decisions; removing/rewriting/excluding them to green a deployment preflight could change scoped admission authority. The existing `inert-staging-preflight.yml` remains manual (`workflow_dispatch`), fail-closed and separate from ordinary code CI.

The stable required source check is **`code qualification`** (job key `code-qualification`, issuer **GitHub Actions**) in [CI](../../.github/workflows/ci.yml). It runs on PRs and pushes to `main`, with `if: always()` and dependencies on foundation, full strict MariaDB **10.6 and 10.11**, and exact Git-object package integrity. Missing/failed/skipped/cancelled dependencies cannot produce code qualification PASS. Its versioned `CODE_QUALIFICATION_RECEIPT` explicitly records `deployment_readiness=NOT_ASSESSED`.

### Audited baseline — 2026-10-25 (historical, refresh before action)

| Plane | Observed | Claim ceiling |
| --- | --- | --- |
| Main commit | `ace16644193f5cba924eb90ae24ff89c32a9ab82` (PR #80 merged) | Source only |
| Hosted post-merge [CI #618](https://github.com/HMarcusWH/allfather-loopia-p01/actions/runs/40219584849) | All five jobs SUCCESS | Exact source qualified, not host authorization |
| GitHub `main` protection | `protected=false`; zero active rulesets | **NOT ENFORCED** at observation; integration's protection-detail endpoint returned 403 |
| Railway staging service | `allfather-staging / staging / allfather-php-mvp` | Staging only |
| Railway source | `HMarcusWH/allfather-loopia-p01` @ `main`; `checkSuites=false` | **NOT ENFORCED** at observation |
| Railway staging last settled | `4fa10f04-9055-469d-a6f3-1b567021b74c`, SUCCESS for `ace16644…` | Not evidence that future pushes wait for CI |
| Inert copy | Four owner decisions are still in source | Strict manual copy gate expected `NOT_READY` |

## A. GitHub `main` admission — owner setting, not source code

**Precondition:** recent genuine GitHub Actions check named **`code qualification`** succeeded on current `main`, and the owner has confirmed that a protected-branch rule will not deadlock the available review process.

In GitHub repository **Settings → Rules → Rulesets** (or classic branch protection if rulesets unavailable), create an **active branch ruleset** scoped exactly to `refs/heads/main`:

1. Require changes via a **pull request**. If the owner is the sole approval-capable maintainer, begin with **0 required approvals** (explicit exception); use **1 independent approval** only when an actual independent reviewer can satisfy it. Do not create an impossible review rule then routinely bypass it.
2. Require the status check **`code qualification`** with source **GitHub Actions**, if issuer selection is supported. Require PR checks **up to date with `main`** before merge (strict); do not confuse a feature-head SHA with the synthetic merge SHA/tree that CI actually qualified.
3. Block direct force pushes and deletion of `main`. Avoid routine administrator/actor bypass; if emergency recovery is needed, document the actor, reason, SHA, scope and restoration of controls.
4. Do **not** require the intentionally blocked `inert staging preflight` check, transient MariaDB matrix labels, an imagined `CI / ...` status, or merge queue before `merge_group` workflow support is separately qualified.

**Required readback:** active ruleset ID and target, `main.protected=true`, exact check name/source, required PR, strict-up-to-date mode, review count, no destructive update/force/delete bypass. If the connected GitHub app returns 403 for protection details, obtain a **redacted GitHub owner-settings UI or authorized admin readback**. Inaccessible is `UNVERIFIED`, not PASS. A new source file is not proof of a settings change.

**Safe negative check:** open a temporary, non-merged PR whose *offline* required check fails or has not reported; confirm GitHub refuses merge, then close it without override. Check that stale PRs requalify when main changes. Do not attempt an actual force push/delete merely to prove it is prohibited.

## B. Railway staging source admission — separate owner setting

Only after protected `main` is confirmed, enable **Wait for CI** in the existing Railway **allfather-staging → staging → allfather-php-mvp → Settings → Source**. Keep the same GitHub repo and branch. This must read back as:

- `source.repo=HMarcusWH/allfather-loopia-p01`
- `source.branch=main`
- `source.checkSuites=true`

Retain the existing staging PHP service, `/srv/allfather/storage` persistent volume, dedicated MariaDB runtime, current environment, provider variables, predeploy command, healthcheck and domains. Do not reconnect GitHub, change deployment authority, promote to production or run destructive migrations. Merely changing settings is not a deployment approval. Record owner, timestamp, config before/after, environment/service IDs and observed deployment state. Readback missing means `UNVERIFIED`.

**Railway is not filtering by the specific `code qualification` job.** Its [Wait for CI](https://docs.railway.com/deployments/github-autodeploys#wait-for-ci) evaluates *whole GitHub Actions workflow conclusions on a commit*. A FAIL from the independent manually dispatched, correctly blocked inert preflight on that same SHA while staging waits may cause Railway to **SKIP** an otherwise source-qualified deployment. A neutral/skipped run does not block; cancellation has special rules and a >2-hour wait can skip deployment. Do not make inert preflight green, delete decisions, or issue fake authorization to get around this. Keep optional manual checks outside the staging-promotion window or choose a separately reviewed exact-SHA manual promotion model if per-workflow isolation is essential. This caveat is a distinct acceptance test, not something `CODE_ONLY` hides.

## C. First protected positive promotion

1. Qualify a harmless docs/static-test PR through the real GitHub-hosted aggregate `code qualification` check, including both strict MariaDB lanes (0 FAIL, 0 SKIP), byte-verified exact package and root-rsync rehearsal. Record `pr_head_sha`, GitHub's synthetic `pr_merge_test_sha`, `pr_tested_tree_sha`, run ID/conclusion, artifact hashes. No retroactive relabelling of earlier failures.
2. Confirm owner-applied GitHub rules and Railway `checkSuites=true` by **independent readback before merge**. Merge only under verified protection.
3. Observe a new staging promotion on the resulting exact main SHA: deployment `WAITING` before main push-CI has finished, main CI `SUCCESS`, then Railway deployment `SUCCESS` and healthy `/healthz`. Compare the **complete 40-hex deployed source SHA** and merged-main Git tree with the PR-qualified tree. A mismatched tree needs fresh testing.
4. Record the results in a new dated external-settings evidence entry; leave historical PR #80 receipts and October 3 Loopia documents untouched.

**Negative Railway case:** use only a disposable, isolated canary service/environment with no application credentials, shared storage, runtime DB, owner grants or model-provider access. Verify a failing CI workflow yields `SKIPPED`, retaining last known-good staging. **Do not** deliberately land broken test code on protected `main`. If an isolated negative test is unavailable, record `NOT_EXECUTED` and keep the closure partial.

## D. Evidence ledger and rollback

For each promotion, report independently:

`pr_head_sha`, `pr_merge_test_sha`, `pr_tested_tree_sha`, `main_merge_sha`, `main_merge_tree_sha`, check name/source, GitHub run/attempt/conclusion, real MariaDB 10.6/10.11 pass/fail/skip counts, package hash, GitHub ruleset ID and strict status with observation source, Railway `repo`/`branch`/`checkSuites`, deployment ID/commit/status/healthz, and independent inert readiness, owner grants and customer visibility. Use `UNVERIFIED`/`NOT_EXECUTED` for any unknown.

CI failure: **no merge**. Railway `WAITING`/`SKIPPED`: preserve last successful staging deployment and repair in another qualified PR, not by turning off the protection gate. For deadlocking rules, a documented owner-authorized emergency procedure must identify why, the exact commit/actor, compensating checks and when controls were restored. Correcting a source SHA, rolling back a staging deployment and changing runtime permissions are separate operations.

**Closure predicate:** `PR_SRC_PASS && MAIN_RULESET_ENFORCED && REQUIRED_CODE_CHECK_VERIFIED && RAILWAY_STAGING_GATE_VERIFIED && EXACT_SHA_DEPLOY_TRACE && AUTHORITY_UNCHANGED`. Any `UNKNOWN` is **PARTIAL**, not protected-promotion completion.

See [acceptance matrix](PROMOTION_TEST_MATRIX.md) and [qualification profiles](QUALIFICATION_PROFILES.md). Later separate work: CI-FIX-06 public-repository exposure audit, and pinned GitHub Actions runtime warning cleanup.
