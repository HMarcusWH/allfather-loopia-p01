# CI-FIX-05 — promotion acceptance matrix

**Test classes:** `SOURCE` = no-network/static foundation tests, `HOSTED` = exact pull-request/main GitHub Actions, `SETTINGS` = owner-read GitHub/Railway configuration, `CANARY` = fully isolated service without DB, volume or secrets. **Expected dispositions are not evidence of tests executed.** Record `NOT_EXECUTED` or `UNVERIFIED` where appropriate.

| ID | Scenario / class | Required disposition | Evidence ceiling |
| --- | --- | --- | --- |
| E01 | `HOSTED` — frontend/foundation, complete strict MariaDB 10.6+10.11, byte/rsync package, aggregate | `CODE_ONLY=PASS`, `INERT_COPY=NOT_ASSESSED` | PR head, synthetic merge SHA/tree, GitHub run, both 0 FAIL/0 SKIP, package and v2 receipt |
| E02 | `SOURCE` / `HOSTED` — failed, cancelled or skipped job; missing lane, wrong PHP/filter/package/SHA | `CODE_ONLY=FAIL` and protected merge refused | Negative mutations, required check and refused merge. Never deliberately break live DB |
| E03 | `SETTINGS` — stale feature-head or PR merge-base tree | Strict up-to-date check requires requalification | Ruleset readback, before/after full Git tree |
| E04 | `SETTINGS` — direct/force push or deletion attempt to main | Blocked by enforced rule | Readback and non-destructive denial observation; **do not** force-push/delete to test |
| E05 | `HOSTED/manual` — four owner decisions still tracked | `INERT_COPY=NOT_READY`, unchanged manual fail-closed semantics | Separate report or `NOT_EXECUTED`; never remove decisions |
| E06 | `SETTINGS` — pending GitHub main push CI | Railway `WAITING`, not premature staging deployment | Exact main SHA, checkSuites true, event timestamps |
| E07 | `SETTINGS` / `HOSTED` — exact SHA main push CI passed | Eligible; Railway predeploy plus `/healthz` still required | GitHub run, matching Railway deployment SHA and SUCCESS |
| E08 | `CANARY` — failing main CI | Railway `SKIPPED` and last good stage preserved | Isolated service evidence or `NOT_EXECUTED` |
| E09 | `CANARY` / readback — optional manual inert workflow fails on same SHA | Describe Railway **whole-workflow** interaction; do not falsify READY | Commit, each Actions conclusion, Railway outcome or `NOT_EXECUTED` |
| E10 | `SOURCE` — qualified source without runtime permissions | No models, capture, external action, customer visibility or production GO | Governed files unchanged; actual runtime claims require separate owner observation |
| E11 | `SETTINGS` — GitHub protection returns 403 or Railway setting inaccessible | `UNVERIFIED`, never inferred PASS | Redacted Settings UI/admin readback still required |
| E12 | `SETTINGS` — solo maintainer | No impossible required approvals; choose review count explicitly | Owner policy/available reviewers readback |

## Run and capture sequence

1. **Preflight source:** record current main base and feature head. Static `tests/foundation/source_promotion_boundary.php` must run from both checkout and deployment-shaped rsync copy. Negative variants may only be evaluated in memory.
2. **Hosted qualification:** inspect the exact synthetic PR merge SHA and Git tree, required job `code qualification` issuer GitHub Actions, all four dependencies (including both MariaDB lanes), full seven-layer 0/0 reports, immutable package-identity verdict and receipt hash.
3. **Owner settings:** read GitHub active exact-main ruleset (`main.protected=true`, strict up-to-date check, no deletion/force push, explicit approvals and bypasses). The repo source cannot install or prove the rule.
4. **Railway settings:** read existing staging service's `source.repo`, `source.branch`, `source.checkSuites` and persistent volume names, plus timestamp and deployment ID. Do not create/reconnect services as a shortcut.
5. **Positive only after gates:** merge a source-qualified harmless PR through the protection, then observe actual `WAITING → main CI SUCCESS → matching Railway SUCCESS /healthz`. File full 40-hex SHA and timestamps.
6. **Negative only in canary:** prove `SKIPPED` on a credential-free independent service, never on protected main or the shared staging DB. If unavailable, write `NOT_EXECUTED`. Leave independent manual inert-preflight refusal intact.

## Permanent guardrails

No automatic configuration-changing token inside GitHub Actions. No modifications to `docs/decisions/*.json`, upstream canonical pins, runtime grants, historical receipts, PHP execution, migration, model policy or source cards. Do not add the source-only `inert-staging-preflight.yml` to the deployed app copy: the rsync inclusion policy correctly carries only `.github/workflows/ci.yml`. Green code remains source qualification, never execution or production authority.

For operator procedure, recovery and closure see [Protected source promotion](PROTECTED_SOURCE_PROMOTION.md) and [qualification profiles](QUALIFICATION_PROFILES.md).
