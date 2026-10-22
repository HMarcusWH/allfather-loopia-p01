# P02 first-party internal draft read — separate from released entity snapshots

**Scope:** source-only, staging TEST read-model proposal. Does not grant permission, model/source execution, M10 decision, customer-visible release, identity, tier change, database migration or a deployment.

## Problem being addressed

The existing customer already has an M05-sourced P02 `OWN_COMPANY` record with `INTERNAL_DRAFT` version, but the P09 console only queried `released_entity_twin_snapshots`. A governed empty result from the released list was therefore incorrectly experienced as “no customer profile.”

This PR adds `GET /console/api/v1/p02/own-company-drafts` and a **separate** P02 panel. It is not a second snapshot-release path. The read owner is `Product/P02/OwnCompanyDraftRead.php`; the controller takes tenant/workspace/actor exclusively from the authenticated server session.

## Authority and content

- Requires **both** M02 `tenant.administer` (restricts unreleased first-party drafts to a tenant admin) and M02 `entity_twin.read`, plus **an ACTIVE tenant subscription tier granting `entity_twin.read`**. Existing released-snapshot readers with only `entity_twin.read` do not automatically gain access to unreleased company data. Both are independently rechecked server-side.
- Restricts to `t.entity_kind='OWN_COMPANY'`, `t.is_first_party=1`, active twin and the **latest** version with `claim_ceiling='INTERNAL_DRAFT'`; a prior draft is not shown if the current version has advanced.
- Returns company attributes and version fields marked `TENANT_PROVIDED`, `UNKNOWN` or `INFERRED_HYPOTHESIS`, an uncertainty summary and source **classification only**. No raw evidence objects, physical file paths, model output, inferred competitors or customer-release claims.
- A denied request gets the existing sanitized 403 `DENIED` envelope without revealing customer data; anonymous API calls get JSON 401. An EMPTY governed read is legitimate and distinct from permission denial.

## Separate authorization requirement

The October 12 Plus 46 staging evidence reports that the first customer operator held tenant-admin authority but did not hold `entity_twin.read`, despite the beta tier already containing that permission. **This PR does not silently grant the user a curator role or bypass the guard**. Showing the actual draft for that user requires a separately reviewed *minimal read-only* role/membership plan and a private browser acceptance; new role definitions require a canonical owner decision. The second existing customer account is not recreated.

The beta subscription does **not** presently grant `customer_catalogue.read` or `competition_monitoring.read`. Do not opportunistically expose P03 catalogue/ICP or P01 draft competitor views by routing them through unrelated permissions. Those are separate commercial and M02 authority decisions before wiring the corresponding read models.

## Qualification gates

1. Frontend Node tests and build; governed browser foundation checks; PHP syntax.
2. Disposable MariaDB integration: unauthorized role / expired role / wrong tenant or workspace / missing active tier / no company / latest version not draft / valid single existing draft with correct fields and provenance.
3. Browser desktop/mobile read-state acceptance on an exact deployed SHA, without altering original records.
4. **Do not deploy while Railway still runs the pre-PHP-8.5.12 image.** This branch is for review and qualification only.
