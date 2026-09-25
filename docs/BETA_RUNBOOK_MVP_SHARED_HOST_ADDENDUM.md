# BETA RUNBOOK — MVP SHARED-HOST ADDENDUM

**Applies only to:** `LOOPIA_SME_MVP` staging / closed beta.  
**Base runbook:** `BETA_RUNBOOK.md`.  
**Risk decision:** `MVP_SHARED_HOST_RISK_ACCEPTANCE.md`.

**Current execution:** [25 September owner-directed staging deviation](STAGING_OWNER_DIRECTED_DEVIATION_2026-09-25.md).
That record covers the observed E.1 failure, expanded owner-controlled inventory and expressly
excluded WordPress maintenance for this inert continuation. It does not claim this historical
checklist passed or technical isolation was verified.

This addendum changes one thing only: the treatment of
`LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER` before Gate F.

Everything else in `BETA_RUNBOOK.md` remains in force.

The web-PHP isolation property remains **UNVERIFIED**. This addendum does not relabel it CLOSED and
does not authorize a `CROSS_SITE_ISOLATION` PASS record. Instead, the repository owner has accepted
a bounded staging-only shared-host risk with mandatory compensating controls.

---

## A. Precedence for this MVP only

For `LOOPIA_SME_MVP` staging, this file and `MVP_SHARED_HOST_RISK_ACCEPTANCE.md` are the explicit,
reviewed exception to the generic no-waiver wording in:

- `BETA_RUNBOOK.md` Gate E.3 / Gate F;
- `DEPLOYMENT_RUNBOOK.md` §4 / §5;
- `SECURITY_BOUNDARY.md` §5.3; and
- `LOOPIA_HOST_MODEL.md` §5 blocker 4.

Outside this one MVP staging deployment, the generic policy remains unchanged.

The required state is:

```text
LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER = UNVERIFIED
MVP_SHARED_HOST_RISK_ACCEPTANCE = ACCEPTED
MVP_SECRET_PLACEMENT = ALLOWED_WITH_COMPENSATING_CONTROLS
PRODUCTION_SHARED_HOST_RISK_ACCEPTANCE = NOT_AUTHORIZED
```

---

## B. Gate E.3 replacement for this MVP

Run Gate E.1 and E.2 exactly as written in `BETA_RUNBOOK.md`.

- Any cross-site `READABLE` result from the SSH/CLI canary is still a hard stop.
- Any private-path HTTP result of 200 is still a hard stop.
- Neither passing result proves web-PHP isolation.

After E.1 and E.2, web-PHP isolation may proceed by one of three routes:

1. **written Loopia assurance** of per-site web-PHP filesystem isolation;
2. **equivalent safe host proof** of the web-PHP identity; or
3. **MVP shared-host risk acceptance** under `MVP_SHARED_HOST_RISK_ACCEPTANCE.md`.

Route 3 does **not** close the technical blocker. It records:

```text
web_php_cross_site_isolation = UNVERIFIED
risk_disposition = ACCEPTED_FOR_MVP_STAGING
```

Before route 3 permits Gate F, complete the following checklist by eye:

- [ ] Account inventory contains only the Allfather staging site plus the two owner-controlled blogs.
- [ ] Both blogs have been updated and obvious unused/stale components removed where practical.
- [ ] Blog/admin credentials are unique; MFA/BankID is enabled where supported.
- [ ] Staging DB credentials are dedicated to Allfather and not reused by either blog.
- [ ] `APP_MASTER_KEY` is newly generated for this staging deployment and not reused elsewhere.
- [ ] No production credential or production master key will be installed.
- [ ] Any provider/API key is dedicated or isolated, minimally scoped, separately revocable, and
      subject to a provider-side hard monthly spend/usage cap where available.
- [ ] Keys that expose unrelated sensitive resources or effectively unbounded spend are not used.
- [ ] Live read remains disabled unless separately activated through its existing gate.
- [ ] Scheduler handler registries remain empty.
- [ ] External action remains unavailable.

If any required control is false, **STOP. Gate F is forbidden.**

---

## C. Gate F under the MVP exception

After the checklist above is satisfied, Gate F may install the private staging `.env` even though the
web-PHP isolation property remains UNVERIFIED.

Gate F remains staging-only and must use:

- a dedicated staging database credential;
- a unique staging `APP_MASTER_KEY`;
- blank scheduler credentials while scheduler activation remains out of scope;
- the exact frozen release SHA.

Then run the normal host preflight exactly as written in `BETA_RUNBOOK.md`.

A passing preflight still does not convert the accepted hosting risk into a verified isolation claim.

---

## D. Provider credential onboarding rule

Before a beta user supplies a provider/API credential, communicate the shared-hosting risk in plain
language:

> If someone compromises one of the blogs in the same Loopia account, gets far enough into the
> shared hosting/FTP space, and then reaches the Allfather staging secrets, they may be able to burn
> your API tokens. Use a dedicated key and put a hard monthly spend/usage limit on the provider
> account.

The operator should prefer provider projects/accounts that support:

- dedicated API keys;
- key revocation/rotation;
- narrow permissions/scopes; and
- hard monthly spend or usage limits.

If a provider does not support a hard cap, use an isolated low-balance/prepaid project or equivalent
bounded-budget account where possible. Do not accept broadly privileged credentials that expose
unrelated sensitive resources.

---

## E. Evidence handling

Risk acceptance is not technical evidence of isolation.

Therefore under route 3:

- do **not** file `CROSS_SITE_ISOLATION` as PASS on the basis of owner acceptance;
- do **not** claim `WEB_PHP_CROSS_SITE_ISOLATION = VERIFIED`;
- do **not** claim Loopia capability validation from this risk decision alone;
- preserve the actual E.1 CLI-canary output and E.2 HTTP-private-path output truthfully;
- record the hosting risk in the deployment review notes outside governed runtime state.

All other evidence kinds in Gate R retain their normal semantics.

---

## F. Incident response under this exception

Treat any suspected compromise of either sibling blog as a possible compromise of the Allfather
staging secret boundary until investigated.

At minimum:

1. disable/revoke affected provider credentials;
2. rotate the staging DB password;
3. rotate `APP_MASTER_KEY` and re-seal provider credentials as required by the application's normal
   rotation procedure;
4. inspect the shared FTP/filesystem space and both blogs;
5. do not resume affected integrations until the hosting account is considered clean.

The provider-side monthly limit is the financial blast-radius control while this investigation is in
progress; it is not a substitute for revocation or rotation.

---

## G. Expiry

This addendum expires before production/customer-visible launch.

Production requires either:

- written Loopia assurance;
- equivalent safe web-PHP isolation proof; or
- a dedicated hosting boundary that removes the sibling-site dependency.

This addendum cannot authorize:

```text
customer_visible_output_authorized = true
external_action_authorized         = true
owner_go_granted                    = true
production_launch_authorized        = true
```
