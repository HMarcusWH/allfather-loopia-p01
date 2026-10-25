# CI qualification profiles — separate correctness from deployment authority

**Created:** 2026-10-25. **Source:** CI-FIX-01 through CI-FIX-06, [repair plan](../../docs/longitudinal/LONGITUDINAL_INTELLIGENCE_STATE.md). These profiles do not grant any new runtime capability.

## CODE_ONLY — ordinary PR and main source qualification

The required hosted **code qualification** job depends on **all** of: frontend clean install/Node tests/build, PHP runtime/lock/syntax/foundation/unit, complete strict MariaDB **10.6 and 10.11** independently, and exact prepared package inspection (npm pin, Git-object export, manifest, byte comparison, disposable root-rsync rehearsal). A missing, skipped, failed or cancelled dependency is a FAIL. It produces a unique `CODE_QUALIFICATION_RECEIPT`, schema `2.0`, whose `deployment_readiness` is always `NOT_ASSESSED`.

The source report includes SHA/tree, source verifier hashes, a complete matrix with pass/fail/skip per layer and real server versions, the hashed prepared-release verdict, GitHub run/attempt identity when hosted, and a list of claims it **cannot** establish. The receipt is self-reported: compare it with GitHub's authenticated job and artifact records. It does not approve deploying any branch, schedule, human review, model calls, source capture, external notification, production, or customer visibility.

For a PR, GitHub checks out a synthetic **merge commit**. Its `source.commit` must name that checked-out object, not merely the feature branch head. The final merge's full Git **tree** must be compared against the tested tree before reusing qualification evidence. A new tree requires new tests.

## INERT_COPY — explicit operator-requested deployability check

`.github/workflows/inert-staging-preflight.yml` runs only by `workflow_dispatch` for a supplied exact 40-hex SHA. It prepares the exact source, reruns package verification, and invokes the unchanged `ops/deploy/preflight.php` and `verify-preflight`. It makes **no deployment**. An inert copy must contain **zero** `docs/decisions/*.json` files and satisfy every other staging invariant. This repo currently has four valid model-evaluation owner decisions, so the correct inert-preflight outcome is `NOT_READY` / workflow FAILURE, *not* a code regression. It uploads the refusal evidence even on failure.

Never delete, rename, edit or exclude the four decisions merely to green ordinary CI: that could withdraw previously reviewed authority. A distinct derived inert artifact with a separately accepted manifest/provenance contract would need an owner review and separate QA.

## MANUAL_PILOT and PRODUCTION

A proposed manual pilot must be checked under `ops/monitor/preflight.php`, with exact owner-approved tenant/workspace/prompt/budget/host context. That check does not run on public PRs, and never executes a model merely to verify a configuration. Production remains limited to read-only P12 preflight and needs a separate canonical production contract and owner GO. Code qualification is not permission to call the model.

## Verification

```bash
# In a CI checkout after the code profile has built package + strict lane artifacts:
php ops/ci/verify-qualification.php verify-code-receipt \
  --receipt=<receipt.json> --lanes-dir=<downloaded-lane-artifacts> \
  --package-verdict=<downloaded-prepare-release.verdict.json>

# Existing original RELEASE_QUALIFICATION_RECEIPT v1 stays valid under its
# original verifier, including historical FAIL results and inert preflight.
php ops/ci/verify-qualification.php verify-receipt --receipt=<v1.json>
```

The source-controlled shared local procedure is `bash ops/ci/qualify-release.sh code-local --out=<new-private-directory> --env-10.6=<disposable-database-env> --env-10.11=<disposable-database-env>`, from an exact Git object. Local receipts explicitly report `hosted_ci=false` and are not interchangeable with GitHub artifacts. **Never pass it a runtime/staging database**; the strict tests truncate their test database.

Previous private-repo billing/zero-run outcomes and the PR #79 aggregate failure remain historical facts; do not relabel them PASS. GitHub's ordinary code jobs now run independently of the opt-in inert preflight. If a hosted run is queued, its result is NOT_RUN, not PASS.

## Operations and branch settings (CI-FIX-05)

**Source-control contract:** [Protected source promotion](PROTECTED_SOURCE_PROMOTION.md) defines the exact required status `code qualification` (GitHub Actions), source tree identity, main-branch rules, Railway Wait for CI, owner readbacks, exceptional recovery and the non-compensatory closure predicate. [Promotion acceptance matrix](PROMOTION_TEST_MATRIX.md) separates source-mutation fixtures from owner-only settings and isolated canary evidence.

**Dated external state (2026-10-25):** GitHub `main` was observed `protected=false` with no rulesets; Railway staging `allfather-php-mvp` followed `main` with `checkSuites=false`. Hosted main run #618 was green, but that success **does not** mean these account settings have been enabled. A source PR cannot enforce them. Require an actual, redacted owner/admin GitHub readback and Railway service source readback before claiming the new promotion gate is in service.

Railway checks *whole workflow conclusions*, not only the required aggregate job. An independently and correctly failing manually dispatched inert preflight on the same SHA can block a WAITING staging deployment; never hide that failure or erase the four owner-decision files. Use isolated canary evidence for negative Railway behavior. No staging/production or runtime authority is created by this documentation.

## Public-repository privacy (CI-FIX-06)

The four owner decisions are now publicly tracked. Do not paste exact tenant/workspace IDs or internal authorization hashes into CI comments; secrets are not to be printed or staged. Audit source and history privately; any credential found requires owner-authorized revocation/rotation, not merely deleting the current Git blob. Node 20 runner warnings belong to a separate pinned-action compatibility PR and are not the cause of the release-preflight failure.


**CI-FIX-06 source exposure guard (2026-10-26):** The mandatory foundation suite now checks the public source export for high-confidence credentials and unauthorized tracked-file paths, and checks the Git index in a normal checkout. Operator-only `ops/security/audit-public-repository.php --scope=history` can inspect reachable local Git-history blobs and must not be relabeled as executed or complete unless actually run on a complete ref inventory. See [public repository exposure audit](../security/PUBLIC_REPOSITORY_EXPOSURE_AUDIT_2026-10-26.md). Four publicly tracked owner decisions remain intentionally unmodified; public owner metadata is a separate human privacy review, and a green token scan is not proof of full privacy clearance.
