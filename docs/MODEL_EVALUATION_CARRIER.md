# Synthetic model-evaluation carrier

This is private operator preparation for the existing `MODEL_EVALUATION` lane. It is not a Monitor product configuration, provider call, prompt approval, owner decision or execution grant. It depends on the explicit Tenant Administrator v2 repair in `TENANT_ADMIN_VERSION_2.md`.

## Why a separate carrier

`MonitorSetup` configures product source observation and internal release, and legitimately requires a reviewed source and company/profile evidence. Synthetic evaluation inputs come from the frozen evaluation manifest instead. Do not invent source rights or a fictional company profile to get a synthetic evaluation through product setup.

`EvaluationCarrier` composes the existing model-descriptor, M03 and M04 owners. It creates one TEST mission with action scope `ANALYZE`, empty source and product-output scopes, one model tool, and two separate evaluation author/critic identities with exact contracts and narrowed assignments. Neither assignment has product-output scope and neither contract gains an output-class binding. Candidate retention belongs to the existing evaluation runner; it is not an M03 product release. No schema or output gate is widened.

It creates no subscription, source, profile, credential, prompt approval, native grant, provider attempt, product configuration or release. The database refused the first candidate's invalid product-output binding during CI; the repair removed the unnecessary binding instead of changing the schema to accommodate it. That failed run remains failed.

## Authorization and plan semantics

The same M02 AccessGuard used by other owners requires `tenant.administer`, `runtime_host_binding.administer`, `valkyrie.register`, `valkyrie.administer_contract`, `mission.create` and `mission.activate`. Actual shipped TenantAdmin v2 plus Workspace Operator can supply preparation authority. The evaluation runner separately requires its own access, entitlement, prompts, credential and admitted grant.

No role is assigned by preparation. Installing v2 vocabulary does not assign v2. The operator must separately approve the exact intended v2 membership; an old v1 approval is not reusable for expanded authority.

An allowed plan changes no database table. A denied plan retains the normal M02 denied-request audit; it does not bypass that audit or implement a second access policy. A plan names its target, release, computed source fingerprint, scope, intended definitions and digest of current identity/configuration state. Its digest must be reviewed before apply. No time-only nonce is added to the plan.

Apply rechecks M02, acquires the same workspace/tenant lock order as MonitorSetup, recomputes the plan, and refuses a changed digest before owner composition. The complete operation is transactional, including nested owner calls. It reuses an exact existing carrier; partial, ambiguous, changed, revoked or narrowed state refuses rather than being repaired or revived. After a CREATE the current-state digest changes, so an operator must plan again before a later REUSE. Repeating that reviewed reuse changes no configuration.

## Private CLI

Run only from an independently qualified testing/staging release. The command refuses production, requires the exact configured `APP_RELEASE_REF`, derives target from `APP_BASE_URL`, computes the actual source fingerprint, and keeps the ordinary BetaCli maintenance lease. It neither requests nor loads a provider key or master key.

```sh
php ops/evaluation/carrier.php plan --env=<private-env> \
  --tenant-id=<tenant> --workspace-id=<workspace> --operator-id=<operator> \
  --release-ref=<exact-deployed-sha>

# Separately review the returned plan before applying its exact digest.
php ops/evaluation/carrier.php apply --env=<private-env> \
  --tenant-id=<tenant> --workspace-id=<workspace> --operator-id=<operator> \
  --release-ref=<exact-deployed-sha> --plan-digest=<reviewed-sha256>
```

The apply receipt contains `governance.author` and `governance.critic`, suitable for the existing evaluation runner. Retain those IDs in a private governance JSON file using the operator's normal protected workflow. Do not modify the shipped owner-decision directory or execute unreviewed archive helpers.

## What must happen separately before the first provider request

1. Review the exact model policy, manifest/split, prompt bodies and provider/data-use boundary. The current manifest has eighteen cases; placeholder offline request digests are not an admitted run's governed digests.
2. Obtain the separate owner authorization for the selected prompt identities with purpose `MODEL_EVALUATION`. These initial evaluation-purpose approvals do not require a completed product evaluation and do not approve product use.
3. Prepare and obtain owner approval of the exact `MODEL_EVALUATION` grant, credential binding, source fingerprint, validity and attempt/spend budget. It permits zero source captures. Carrier creation alone satisfies none of these steps.
4. Start and preview the run with the existing `ops/evaluation/monitor.php`. Inspect actual prepared requests privately before execution; the ordinary CLI summary reports digests, not full bodies.
5. Execute only the separately admitted budget and preserve quota/failure results. An unfunded key or single-attempt quota diagnostic cannot qualify eighteen-case model quality. No new grant or identity is minted merely to retry a refusal.
6. Record genuine human grounding/usefulness assessments. An accepted evaluation may be cited in separate `MANUAL_MONITOR` prompt approvals. Product prompt approvals and the manual road-test execution grant remain distinct owner acts.

## Qualification

`tests/integration/evaluation_carrier.php` uses shipped roles on a disposable MariaDB database. It covers v1 refusal/v2 preparation, configuration-read-only planning, exact digest/target/release binding, complete-table write-set checks, verified outer rollback, distinct author/critic identities, M03 model eligibility, zero source/output/grant/credential/release creation, reuse, revocation and cross-scope refusal, actual private CLI replay, and the real evaluation runner refusing absent execution authority.

`tests/integration/evaluation_carrier_owners.php` independently composes the same fixed zero-output carrier through the existing owners, then checks its recognition and exact reuse. This is a compatibility test, not an operational SQL procedure.

These are source tests, not a live evaluation, deployment, quality result or ROAD_TEST observation. Real staging execution and the two human road-test reviews remain separately approved operational work.
