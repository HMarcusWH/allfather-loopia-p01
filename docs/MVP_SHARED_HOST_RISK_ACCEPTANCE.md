# MVP SHARED-HOST RISK ACCEPTANCE

**Projection:** `LOOPIA_SME_MVP`  
**Environment:** staging / closed MVP beta only  
**Decision:** `ACCEPTED_FOR_MVP_STAGING`  
**Date:** 2026-09-12

This document records a narrow, explicit exception for the first Allfather SME MVP deployment on
Loopia shared hosting. It does **not** claim that Loopia provides per-site web-PHP filesystem
isolation, and it does **not** convert an unverified isolation property into a verified one.

The generic repository policy remains conservative: where no scoped exception exists,
`LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER` stays a pre-secret blocker. For this one deployment,
this document is the reviewed exception to that default and takes precedence over the generic
"no owner/operator waiver" wording in `BETA_RUNBOOK.md`, `DEPLOYMENT_RUNBOOK.md`,
`SECURITY_BOUNDARY.md`, and `LOOPIA_HOST_MODEL.md` **only for `LOOPIA_SME_MVP` staging**.

The exact operational override is sequenced in `BETA_RUNBOOK_MVP_SHARED_HOST_ADDENDUM.md`.

---

## 1. What is known

Loopia documents that:

- the SSH home directory is the same directory reached through FTP;
- multiple websites may exist in one account; and
- when one site is compromised, malicious files can spread to other websites on the same FTP
  space, so Loopia tells customers to inspect every website in the account.

Sources:

- <https://support.loopia.se/wiki/ssh/>
- <https://support.loopia.se/wiki/intrang-pa-sidan/>
- <https://support.loopia.se/wiki/flera-hemsidor-flera-abonnemang/>

Loopia's public documentation does **not** give this project a written guarantee that web PHP for
Site A cannot read private files belonging to Site B inside the same hosting account.

Therefore the truthful security state remains:

```text
LOOPIA_WEB_PHP_CROSS_SITE_ISOLATION_BLOCKER = UNVERIFIED
WEB_PHP_CROSS_SITE_ISOLATION = NOT_CLAIMED
MVP_SHARED_HOST_RISK_ACCEPTANCE = ACCEPTED
MVP_SECRET_PLACEMENT = ALLOWED_WITH_COMPENSATING_CONTROLS
PRODUCTION_SHARED_HOST_RISK_ACCEPTANCE = NOT_AUTHORIZED
```

The blocker is not marked CLOSED under this exception. The MVP proceeds while carrying the risk.

---

## 2. Accepted threat scenario

The accepted scenario is:

1. one of the other websites in the same Loopia account is compromised;
2. the attacker obtains code execution or sufficient filesystem/FTP-level access inside the shared
   account;
3. the attacker reaches the Allfather staging private tree or its `.env`;
4. the attacker obtains one or more of:
   - the staging database credential;
   - `APP_MASTER_KEY`;
   - provider/API credentials stored for MVP use;
5. the attacker may then consume provider quota, incur API cost, or access whatever resources those
   credentials themselves authorize.

This is a **credential-compromise / blast-radius risk**, not an accepted claim that cross-site access
is technically possible in every Loopia execution mode. The public documentation is insufficient to
prove either isolation or non-isolation at the web-PHP identity layer.

---

## 3. Why the MVP accepts it

The first MVP is intentionally bounded:

- the shared account currently contains only the Allfather MVP plus two owner-controlled blogs;
- this is staging / closed beta, not production;
- live-read activation remains separately gated;
- scheduler handlers remain inert until separately reviewed;
- external action remains structurally unavailable;
- customer-visible production authority remains false;
- the canonical projection does not contain customer/production identity vocabulary.

The risk is therefore accepted as a temporary MVP infrastructure compromise, not as the target
production security model.

---

## 4. Mandatory compensating controls

The exception is valid only while **all** controls below remain true.

### 4.1 Sibling-site inventory

Before secrets are installed, confirm that the Loopia account contains only:

- the Allfather MVP staging site; and
- the two owner-controlled blogs already known to the operator.

Adding an unrelated third-party/customer site invalidates this risk acceptance and re-opens the
pre-secret hard stop.

### 4.2 Blog hygiene

Before the staging secrets are installed:

- update the two blogs and their third-party packages/plugins/themes;
- remove unused plugins/themes/components where practical;
- remove stale administrator accounts where practical;
- use unique credentials and MFA/BankID where supported;
- treat a compromise of either blog as a compromise of the shared hosting account until proven
  otherwise.

### 4.3 Staging-only secrets

Allfather MVP secrets must be dedicated to this deployment:

- unique staging database user/password;
- unique `APP_MASTER_KEY`;
- no production database credential;
- no production master key;
- no credential reused by either blog.

### 4.4 Provider/API credentials

Any provider credential admitted to the MVP must be:

- separately revocable;
- minimally scoped;
- dedicated to the MVP or to a dedicated provider project/account where the provider supports it;
- replaceable without data migration; and
- protected by a **provider-side hard monthly spend/usage limit** wherever the provider offers one.

If the provider cannot enforce a hard monthly cap, use an isolated low-balance/prepaid project or an
equivalent bounded-budget account where possible. Do not use a broadly privileged key whose loss
would expose unrelated systems or an effectively unbounded bill.

A spend cap limits financial blast radius. It does **not** protect confidentiality. Therefore a
credential that can read sensitive resources unrelated to the MVP is outside this acceptance.

### 4.5 Rotation trigger

Rotate the staging database password, `APP_MASTER_KEY`, and affected provider credentials after any
suspected sibling-site compromise, unexpected FTP/filesystem modification, or migration to a new
hosting boundary.

### 4.6 Existing authority locks remain unchanged

This exception does not activate or authorize:

```text
live_read_activated                = false
road_test_001_executed             = false
external_action_authorized         = false
customer_visible_output_authorized = false
owner_go_granted                    = false
production_launch_authorized        = false
```

Those states may change only through their existing reviewed gates.

---

## 5. Plain-language beta disclosure

The practical warning to an MVP user is:

> If someone compromises one of the blogs in the same Loopia account, gets far enough into the
> shared hosting/FTP space, and then reaches the Allfather staging secrets, they may be able to burn
> your API tokens. Use a dedicated key and put a hard monthly spend/usage limit on the provider
> account.

This statement deliberately describes the consequence without claiming a filesystem exploit path
that has not been demonstrated.

---

## 6. What this exception does NOT prove

It does not prove:

- per-site PHP isolation;
- absence of per-site PHP isolation;
- Loopia host capability validation;
- live-read host qualification;
- scheduler transport compatibility;
- production readiness;
- partner-data confidentiality beyond the authority of the credentials actually supplied.

`CROSS_SITE_ISOLATION` evidence must **not** be filed as PASS merely because this risk was accepted.
Risk acceptance and technical verification are different facts.

---

## 7. Exit criteria

This exception expires before any production/customer-visible launch.

Before production secrets or production customer credentials may be placed on the host, at least one
of the following must exist:

1. written Loopia assurance of the relevant per-site web-PHP isolation property;
2. an equivalent safe host proof of that property; or
3. a deployment topology that removes the sibling-site trust dependency, such as a dedicated
   isolated hosting account/environment for Allfather.

Until then:

```text
PRODUCTION_SHARED_HOST_RISK_ACCEPTANCE = NOT_AUTHORIZED
```
