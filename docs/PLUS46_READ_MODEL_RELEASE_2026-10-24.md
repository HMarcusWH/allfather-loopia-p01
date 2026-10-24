# Plus 46 Studio TEST — governed customer reads and explicit authorization

**Scope:** Railway **staging** only. This release adds internal read models for the already-present P01 competitor candidates and P03 customer declarations, plus a minimal P02 reader role. It does **not** duplicate the October 12 import, release battlecards, infer real buyer intent, conduct source capture, schedule jobs, or authorize paid model calls.

## Source and UI

- `GET /console/api/v1/p01/source-watch` — P01 source watchlist, competitor `DRAFT` state and the count of CURRENT approved monitored-source registrations. Does not return raw URLs/evidence, does not imply that candidates are active monitors.
- `GET /console/api/v1/p03/declared-icp` — ACTIVE customer profile(s), latest append-only ICP version, declared industries, markets, company-size band, buyer **role classes** and uncertainty. Explicit `CUSTOMER_DECLARED_UNVERIFIED` status.
- `GET /console/api/v1/p03/customer-offers` — ACTIVE products/services in the current tenant **and workspace**, including safe offer descriptions, attributes and source-review status. Explicit `CUSTOMER_PROVIDED_UNVERIFIED` status.
- Existing P02 `/console/api/v1/p02/own-company-drafts` remains protected by M02 `tenant.administer` plus `entity_twin.read` and active subscription. The new role `ROLE_ENTITY_TWIN_READER` grants **only** `workspace.read` and `entity_twin.read`. No curator approval/release rights.
- Frontend read metadata uses `OWNER_READ_WIRED` with **auditedDisplay=true**, so no prefetch or transparent retries for these internal customer records.

### Dual access checks

Every new P01/P03 GET checks effective current M02 membership/permission AND active tenant-tier entitlement. The former original global `LOOPIA_SME_BETA` has exactly **six** read permissions and remains unchanged. It **does not** grant `competition_monitoring.read` or `customer_catalogue.read`.

An **additional** zero-fee `LOOPIA_SME_BETA_READS_V2` contract contains exactly these eight read-only keys:

```
battlecard.read
competition_monitoring.read
customer_catalogue.read
entity_twin.read
marketing_asset.read
priority_outreach_queue.read
prospect.read
role_briefing.read
```

It is a separate opt-in tier, not an update to the shared six-permission tier. The only allowed transition is the existing `plus46-studio-ab-test` TEST tenant, workspace `plus46-profile-test`, existing login `46@hmwh.se`. Other customer trials are untouched.

## Owner-reviewed Railway console operation after successful code deployment

In the existing **allfather-php-mvp** service's SSH/Console, inspect the currently deployed exact Git SHA first. **Do not use the MariaDB container, SQL editors, another account, or production services.**

1. Provision canonical role vocabulary **only** (this command creates the new role definition, not a membership):

   ```sh
   cd /srv/allfather
   php ops/provision-role-vocabulary.php --apply --env=/srv/allfather/.env
   ```

   Confirm `ROLE_VOCABULARY_PROVISIONED` and the role creation count; no user permissions have yet been granted.

2. Obtain the current **read-only** exact state plan:

   ```sh
   php ops/activate-plus46-reads.php plan --env=/srv/allfather/.env
   ```

   Confirm `DRY_RUN_OK`, tenant/workspace/email, the **eight reads**, the exact active prior `TRIAL` subscription, and the intended role. Record its `plan_digest` (SHA-256). Review the plan before mutation.

3. Apply only the exact plan after owner approval. Replace the placeholder with the **digest from the most recent plan**:

   ```sh
   php ops/activate-plus46-reads.php apply --env=/srv/allfather/.env \
     --plan-digest=REVIEWED_PLAN_SHA256 --ack=PLUS46_TEST_READ_V2
   ```

   A stale plan, a different tenant, changed account/role, missing authorization or source of tier conflict refuses/rolls back. Its one transaction changes exactly the TEST customer's role membership, transitions the original tenant subscription to `CANCELLED` (historical row preserved) and opens one new zero-fee `TRIAL`. It does **not** touch credentials, other tenants or product records.

4. Rerun the plan; it must return `action: REUSE`. Inspect the account separately:

   ```sh
   php ops/manage-beta-user.php inspect --email=46@hmwh.se --tenant-key=plus46-studio-ab-test --env=/srv/allfather/.env
   ```

   The account should show `ROLE_ENTITY_TWIN_READER` and the successor trial, with no duplicated customer records or multiple live subscriptions.

5. In a new authenticated browser session, choose `Plus 46 Studio AB — TEST`. Test P02 draft, P01 watchlist and P03 ICP/offers. Check that a synthetic-tenant account cannot see these records and that released battlecards remain `EMPTY` until governance produces real releases.

## Qualification limits

The Docker build runs exact PHP 8.5.12 lint and focused no-DB foundation boundary tests, plus all existing frontend Node tests and production asset build. **Disposable MariaDB integration** in `tests/integration/plus46_customer_reads.php` needs a separate temporary database; never run the destructive freshDb test against the staging customer database. After code deployment, no role or subscription changes have happened until Step 3 is explicitly executed in the correct authenticated private CLI.

Do not activate recurring Monitor runs or model calls through this release.
