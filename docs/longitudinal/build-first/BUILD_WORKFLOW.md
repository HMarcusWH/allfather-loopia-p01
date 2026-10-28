# BF-01 — isolated build lane and executable candidate contracts

**As of:** 28 October 2026. **Status:** source implementation candidate on the integration-branch train. **No provider, source-use, customer-output, SQL migration or production rights** are created by these files.

## 1. Dependency and branch contract

- BF-00/BUILD-01: merged as `6719f769035198dd9dd6b492a727d5e06d788328`, [five-check CI](https://github.com/HMarcusWH/allfather-loopia-p01/actions/runs/40607654937) completed successfully.
- BF-01/BUILD-02 follows BF-00. Each feature PR is based on and targets `build/allfather-php-feature-complete-v2`; neither integration nor individual feature branches are connected to Railway.
- Railway `allfather-staging/staging/allfather-php-mvp` is connected to **`main` only**, with Wait for CI enabled at the previous verified read; that does not give build branches deployment authority.
- Require five current-merge-ref checks: foundation, MariaDB 10.6, MariaDB 10.11, package integrity and code qualification. Current `main` ruleset has not yet proven that the qualification check is required; manually verify all five before integration merge. Do not bypass CI or relabel local PASS as hosted PASS.
- After a successful integration-branch merge, an ordinary feature PR is **not** a staging deployment and is **not** a runtime permission grant.

## 2. Executable interfaces

[CONTRACT_REGISTRY.json](CONTRACT_REGISTRY.json) registers **64** plan-index contracts. They all accept a versioned, synthetically scoped candidate envelope with a deterministic digest; the **14 primary** contracts carry additional typed domain-specific fields and examples. The [fixture bundle](../../../tests/fixtures/longitudinal/contracts/candidate_examples.v1.json) has one positive and one missing-required-field refusal candidate per contract.

The PHP `BuildFirstContracts::loadAndValidate()` reads those files and refuses missing or duplicate names, unknown owner/package edges, version/field mismatches, unowned non-external input and any attempt to turn a build contract into authority. `checkCandidate()` returns **VALID_CANDIDATE**, never an M02/M05/M06/M10 authorization. It verifies strict field sets, UUIDs, hashes, canonical clocks, bounded primary references, scoped identities and derived section locators. No raw HTTP page or provider response is copied into the proposed contract metadata.

The common envelope is a **build-time type carrier**, not proof that all 64 domain producers/consumers exist. Its `producer_wiring` remains `UNVERIFIED_UNTIL_REAL_CONSUMER_IN_RT02`. CR/OB/HS/etc. packages must implement their genuine native adapters; only RT-02 can assert complete producer-to-consumer composition after real tests. Generic contract acceptance cannot replace those adapters.

## 3. Synthetic composition and authority boundary

`tests/support/LongitudinalBuildHarness.php` uses the existing **EgressGuard** and **SourcePolicy**, FixedClock, FixtureTransport, FixedHostResolver and FixtureModelTransport. Its only fetchable source is an exact synthetic `https://bf01.example/pricing` path, with fixed non-private test IP and no DNS/socket code. A redirect to `/outside` is refused **before** any second request. The synthetic model reports `isLive=false` and does not dispatch a provider request.

The harness is CLI-only and is not PSR-4-resolvable from `app/autoload.php`; production HTTP and scheduler startup do not require `tests/support/`. Its ephemeral candidate list is **not M05 evidence storage** or a shadow M10 authority store. Cross-workspace reads return no records; unsupported 'release' events are refused before appending.

For the integration test, the native **M02 AccessGuard** runs against the existing disposable `Harness::freshDb()` only *after* explicit `APP_ENV=testing` and safe test DB-name checks. A denied operator later receives a narrowly seeded test role and passes the real M02 check; a foreign principal remains denied. No source, model, scheduled job, released briefing or native execution grant is admitted.

### Focused commands

```sh
php tests/run.php --layer=foundation --filter=bf01 --strict
php tests/run.php --layer=unit --filter=bf01 --strict
# This integration test requires a disposable MariaDB with APP_ENV=testing and a TEST/QA/CI DB_NAME.
TEST_ENV_FILE=.env.testing php tests/run.php --layer=integration --filter=bf01 --strict
php ops/ci/check-build-completeness.php --package=BF-01
```

The normal CI still runs its full existing strict suites; this PR does not edit `.github/workflows/ci.yml` or `ops/ci/qualify-release.sh`.

## 4. Receipt semantics

`BuildFirstReceipt` and the stdout-only CLI compute SHA-256 digests over a closed list of contract source files, registry files and fixtures. The receipt may carry a caller-declared 40-hex Git commit SHA but explicitly labels that identity **NOT INDEPENDENTLY VERIFIED**. It reports exact package status and dependencies, 64 indexed / 14 primary contracts, and the absence of any newly granted authority.

**Crucial:** this command neither runs CI nor queries Railway. The result therefore says `github_ci=NOT_OBSERVED_BY_RECEIPT_COMMAND`, `full_system=NOT_RUN_DEFERRED_V01_V11`, and `existing_runtime_admissions=NOT_QUERIED`. An actual GitHub check and later independent host tests must be filed separately.

`BF-00` and `BF-01` are source-built, still inert; the contract, CI and fixture machinery does **not** mark any VA/CR/OB/HS/TM/AN/AQ/UI/RT source complete automatically.

## 5. Build acceptance and remaining work

- Read-only local registries load with exact source identity and no authorization.
- 64 valid and 64 refused candidate examples are mechanically checked; deliberate scope/time/raw-body, grant, budget and browser-switch mutations refuse.
- Actual EgressGuard synthetic accept, path refusal and redirect refusal use a fixed transport; no live network is available in the harness.
- Real M02 synthetic negative-to-positive-to-cross-tenant behavior is proved in *disposable* MariaDB through the existing authority owner.
- Build receipt is deterministic, bounded and lacks secret contents; its status cannot masquerade as verified CI/host/runtime acceptance.
- Source diff does not touch existing migrations, owner decisions, upstream.lock.json, product live factories or Railway settings.

**Deferred:** VA-00 independent held-out custody; CR/OB/... concrete producer/consumer adoption, RT-01 schema integration, RT-02 actual feature-complete composition, then final V01–V11 operational testing. Existing U01–U10 owner gates remain separate.
