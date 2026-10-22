# Plus 46 Studio — isolated TEST customer bootstrap

**State (22 Oct 2026):** Source-level TEST-only private replay and verification tools. The separately retained 12 October Plus 46 staging execution archive records actual completion and independent database verification of the original bootstrap; do **not** rerun the module, business-record or login creation workflows merely because these examples exist. This source repair makes subsequent replay checks fail closed; it does not migrate, change, or activate any existing customer, role, subscription, prompt, source, model or production state.

**Verification contract:** `plan` is a read-only description and may return `CREATE`, `APPEND_VERSION`, `CREATE_DRAFT`, or `BLOCK` while exiting 0. `verify` exits 0 **only when every requested object is exactly reusable** and all current authorizations are valid; otherwise it prints `status: INCOMPLETE` and exits 5. Under-lock `apply`/login creation re-read current role and capability validity; successful writes also recheck completeness. The reviewed input digest is based on the exact bytes parsed, not a second file read. Company versions include uncertainty and source lineage; catalogue replay blocks unexpected active offers, duplicate tenant-wide keys, expired review cards and non-string fit attributes. Negative unit tests cover pending or blocked actions, and real MariaDB state still requires an independent read-only runtime audit.

## Workflows and commands

The reviewed TEST scope must already exist. Never use `provision-staging.php --allow-existing`: it creates an additional operator, and some module registrations are not replay-idempotent.

### 1. Complete only missing P01–P05 registrations

```sh
cd /srv/allfather
php -l ops/complete-existing-test-modules.php
php ops/complete-existing-test-modules.php plan \
  --env=/srv/allfather/.env --tenant-id=TENANT_UUID \
  --workspace-id=WORKSPACE_UUID --operator-id=OPERATOR_UUID
```

Read the `CREATE`, `REUSE`, `BLOCK`, `plan_digest` and tenant metadata. Apply **only after owner approval of the exact result**:

```sh
php ops/complete-existing-test-modules.php apply \
  --env=/srv/allfather/.env --tenant-id=TENANT_UUID \
  --workspace-id=WORKSPACE_UUID --operator-id=OPERATOR_UUID \
  --plan-digest=REVIEWED_SHA256 --ack=REGISTER_TEST_MODULES
php ops/complete-existing-test-modules.php verify \
  --env=/srv/allfather/.env --tenant-id=TENANT_UUID \
  --workspace-id=WORKSPACE_UUID --operator-id=OPERATOR_UUID
```

The full transaction rolls back on refused registration; no new operator/role/subscription/execution grant is created. Note: product module registrars may materialize their **existing platform tier definitions**; they do not subscribe this tenant.

### 2. Populate P02 company draft, one P03 primary ICP and P01 competitor DRAFT candidates

The input must be a private UTF-8 JSON file outside `public_html`, matching `allfather-customer-bootstrap-v1`, with the exact pre-imported evidence IDs and SHA-256. This file is **not committed** to the repository. It contains only customer-supplied organization attributes and carefully scoped research candidates; fictional change scenarios, prospects, fabricated M10 approvals and marketing releases are excluded.

```sh
sha256sum /srv/allfather/storage/private-imports/plus46/customer-bootstrap.json
php -l ops/bootstrap-test-customer-data.php
php ops/bootstrap-test-customer-data.php plan \
  --env=/srv/allfather/.env \
  --manifest=/srv/allfather/storage/private-imports/plus46/customer-bootstrap.json \
  --manifest-sha256=REVIEWED_FILE_SHA256 --sections=company,icp,competitors
```

If the plan refuses missing P02/P03/P01 permissions, **stop and obtain the exact role-grant decision**; the CLI never silently adds one. If it finds existing conflicting versions or duplicates, fix the plan rather than overriding. Only after owner approval:

```sh
php ops/bootstrap-test-customer-data.php apply \
  --env=/srv/allfather/.env \
  --manifest=/srv/allfather/storage/private-imports/plus46/customer-bootstrap.json \
  --manifest-sha256=REVIEWED_FILE_SHA256 --sections=company,icp,competitors \
  --plan-digest=REVIEWED_PLAN_DIGEST --ack=CREATE_TEST_BUSINESS_RECORDS
php ops/bootstrap-test-customer-data.php verify \
  --env=/srv/allfather/.env \
  --manifest=/srv/allfather/storage/private-imports/plus46/customer-bootstrap.json \
  --manifest-sha256=REVIEWED_FILE_SHA256 --sections=company,icp,competitors
```

### 3. P03 product catalogue (separate source-licence decision)

Do **not** reuse the imported M05 upload reference as if it were already a source licence card. A source reviewer must first approve an actual `TENANT_OWNED` card and matching `ACTIVE_FOR_CAPTURE` source through P01 `SourceCards` with accurate ownership and source review. No script invents that approval. Repeat the private business-record plan with `--sections=catalogue --catalogue-card-id=REVIEWED_CARD_UUID`, then apply the exact reviewed digest. Five customer-supplied offers are expected. Price examples remain quoted/caveated, not live guarantees.

### 4. Existing-operator TEST login (separate identity decision)

The existing email bound to the synthetic smoke tenant cannot be reused: platform login email addresses are globally unique. The owner must select a separate unique email and consent to a **zero-fee** `LOOPIA_SME_BETA` trial. The command requires the operator to hold `ROLE_TENANT_ADMIN` and `ROLE_WORKSPACE_OPERATOR` v1 and binds the owner-reviewed **complete current membership snapshot** into the plan digest. Additional explicitly authorized roles may exist; none is created by this command. It creates no new operator and forces a password change.

```sh
php ops/bind-existing-test-operator.php plan --env=/srv/allfather/.env \
  --tenant-id=TENANT_UUID --workspace-id=WORKSPACE_UUID \
  --operator-id=OPERATOR_UUID --email=OWNER_APPROVED_UNIQUE_EMAIL \
  --display-name='OWNER APPROVED NAME'
```

Stop for approval. To execute, use a trusted no-echo private terminal and `--password-stdin` with the approved plan digest and `--ack=BIND_TEST_LOGIN_AND_BETA_TRIAL`; never type a password into a screenshot, chat, argv or visible web Console. Then verify account, trial, password-change flow and separate tenant isolation.

## Strict limits and test status

- TEST only; no production identities, customer visibility, external side effects or recurring execution.
- P01 competitor names are `DRAFT` proposals, **not monitored sources**. Actual source URLs require terms/robots evidence and a separate reviewed setup.
- The P02 `OWN_COMPANY` state is `INTERNAL_DRAFT` with an M05 evidence link. P03's one primary ICP does not represent a verified buyer-intent claim.
- No fixture, M10 release, published output, live model call or P04/P05 released marketing asset is produced by these tools.
- Account creation does not require a duplicate operator. The standard beta onboarding tool remains unchanged.
- Offline PHP lint, `tests/foundation/plus46_bootstrap_boundary.php` and `tests/unit/bootstrap_verification.php` can run without a database. The **historical October 12 execution** is evidenced by the separately retained Plus 46 archive, but is not an authorization to repeat writes. This newer source repair needs exact-head syntax/unit qualification and disposable MariaDB negative/replay tests before merging/deploying; a fresh read-only TEST observation is required to claim current live verification.
- Do not merge/deploy the code while other qualified staging tests are running without coordinating exact release SHA.
