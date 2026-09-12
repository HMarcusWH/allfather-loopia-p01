# DEPLOYMENT EVIDENCE MODEL

How an operational claim about this deployment becomes true, and what it takes.

**Plane:** Ops. This document governs `deployment-evidence.json` and the five claim-boundary
booleans it can support. It grants no authority, activates nothing, and authorizes no deployment.

---

## 1. Why there are two kinds of boolean

`upstream.lock.json` carries ten booleans under `claim_boundary`. Until PR8 the foundation suite
held all ten with a single rule — every one must be false, forever — and that rule was correct for
exactly as long as nothing had been deployed.

It stopped being correct the moment real staging work began, because two genuinely different kinds
of statement were being held by one tripwire:

**An authority lock is an owner act.** Somebody decides, and records the decision upstream. No
observation made on any host can produce one, so no evidence collected here has an opinion about it.

    customer_visible_output_authorized
    external_action_authorized
    owner_go_granted
    production_launch_authorized

These four remain false, and this model deliberately cannot express a way for them to become true.
`no_external_charge_executed` is their mirror image: it remains **true**, and nothing here can make
it false.

**An operational state is an observation.** "Staging is deployed" either happened or it did not.
Forbidding the claim forever means that on the day it happens, somebody has to edit a tripwire in
order to tell the truth — and a tripwire that must be edited to record reality is one nobody trusts
the next time it fires.

    conformance_complete
    live_read_activated
    loopia_capability_validated
    road_test_001_executed
    staging_deployed

These five may become true, and only while the evidence registry supports them.

## 2. The one-way implication

`tests/foundation/deployment_evidence_boundary.php` asserts exactly this, on every commit:

> **A claim asserted true must be supported by evidence.**

And never the reverse. Filing a successful deployment record does not flip a flag: a human still
edits `upstream.lock.json`, and somebody still reviews that diff. What cannot happen is a flag
moving with nothing behind it — including by an edit to a JSON file that looks harmless.

## 3. What the registry is, and is not

`deployment-evidence.json` is a repository-owned, reviewed, machine-validated register of pointers.

- **It is reviewed.** Adding a PASS record is a diff somebody reads. For a claim of this weight that
  is the property that matters most.
- **It is not written by the application.** `tests/foundation/no_external_action_surface.php` proves
  that no code path in `app/`, `public_html/`, `worker/` or `ops/` writes it — the same proof that
  covers `upstream.lock.json`.
- **It holds pointers, not payloads.** `evidence_ref` names where the artifact was filed;
  `evidence_sha256` pins which bytes were meant. A registry carrying payloads would be a second
  evidence store competing with M05, which is the one this projection already has for governed
  evidence.
- **It is not a database table.** The deployment whose evidence matters most is the one where the
  database is what failed. A registry needing a migration to record "the host was qualified" would
  need the host to work in order to say that the host works.

## 4. Schema

    {
      "schema_version": "1.0",
      "projection_id": "LOOPIA_SME_MVP",
      "canonical_pin": "<upstream.lock.json projection_pinned_head>",
      "records": []
    }

`projection_id` and `canonical_pin` must match the lock. Evidence collected under one canonical pin
says nothing about a projection re-pinned to another, and inheriting it silently would be the
quietest possible overclaim.

### 4.1 A record

| Field | Required | Shape |
| --- | --- | --- |
| `record_id` | yes | 3–64 characters of `A-Z 0-9 _ -`, unique within the registry |
| `kind` | yes | one of the closed kinds in §5 |
| `result` | yes | `PASS`, `FAIL` or `STAND_DOWN` |
| `release_ref` | yes | the **full** 40-character lowercase git SHA — never an abbreviation |
| `environment` | yes | `ci`, `development`, `testing`, `staging` or `production` |
| `recorded_at` | yes | canonical UTC `YYYY-MM-DDTHH:MM:SS.sssZ` |
| `evidence_ref` | yes | a local POSIX relative path, no scheme, no traversal |
| `evidence_sha256` | yes | 64-character lowercase sha256 of the referenced artifact |
| `target` | **for a host record** | the host the record speaks about, e.g. `staging.allfatherbi.com` |
| `note` | no | one printable ASCII line, at most 200 characters |

The schema is **closed**. An unknown field is refused rather than ignored: a misspelled `result`
falling through as an extra field would leave the record with no result at all.

### 4.2 `ci` is in the vocabulary and cannot qualify a host

CI proves the code. It has never touched Loopia. A qualification report produced by a
GitHub-hosted runner is legitimate evidence *of CI*, and `environment: "ci"` is how it is filed
without being mistakable for a host record. Only `staging` and `production` records can support a
claim about the real deployment target.

### 4.3 A host record must name its host

`target` is optional for `ci`, `testing` and `development` — there is no host for those to name, and
a required-but-invented one would be a fiction in the evidence file. For `staging` and `production`
it is **required**, and a record without it supports nothing.

The reason is pairing. `loopia_capability_validated` needs a `STAGING_DEPLOYMENT` PASS and a
`LOOPIA_PROBE_001` PASS **about the same deployment**, and the same release SHA can sit on several
hosts. With release as the whole identity, a deployment to one host and a probe of another satisfied
the rule while no single machine had been both deployed to and probed; with `environment` added, a
staging deployment could still pair with a *production* probe. The scope is therefore the triple —
environment, target, release — and an untargeted host record cannot form one.

It is enforced twice on purpose: `parse()` reports it as a problem the diff reviewer sees, and the
claim reducer refuses such a record again at the point a claim is computed, so no read path can let
an anonymous host record speak for a host.

**CI artifacts are not Loopia evidence.** The `qualification-mariadb-10.6` and
`qualification-mariadb-10.11` artifacts the CI workflow uploads are named for what they are.

## 5. Evidence kinds

| Kind | Records |
| --- | --- |
| `CONFORMANCE` | the strict suite run to completion against real MariaDB, with its measured totals |
| `STAGING_DEPLOYMENT` | a release copied to the staging host and answering |
| `LOOPIA_PROBE_001` | the host capability probe run **on the actual host**, not in a container |
| `CROSS_SITE_ISOLATION` | the pre-secret canary and HTTP private-path probes, run on the real host |
| `STAGING_SMOKE` | the deployed site exercised over HTTPS from outside |
| `ROLLBACK_DRILL` | a rollback and stand-down actually performed and verified |
| `LIVE_READ_ACTIVATION` | live read wired and a real fetch performed, for one exact release |
| `LIVE_READ_STAND_DOWN` | live read withdrawn again |
| `ROAD_TEST_001` | a road test executed — see §6 on why this one is different |

## 6. What supports what

| Claim | Supported when |
| --- | --- |
| `conformance_complete` | a `CONFORMANCE` record with result `PASS` exists |
| `staging_deployed` | a `STAGING_DEPLOYMENT` `PASS` from a **host** environment exists |
| `loopia_capability_validated` | a `STAGING_DEPLOYMENT` `PASS` **and** a `LOOPIA_PROBE_001` `PASS`, both from a host environment and both for **the same `release_ref`** |
| `live_read_activated` | folding all `LIVE_READ_ACTIVATION` / `LIVE_READ_STAND_DOWN` records in time order leaves the deployment activated |
| `road_test_001_executed` | a `ROAD_TEST_001` record exists with result `PASS` **or** `FAIL` |

Three of these deserve their reasoning stated rather than assumed.

**The host pair must name one release.** A probe of release X beside a deployment of release Y
qualifies nothing. The pair is the evidence; neither half is.

**Live read is one timeline, folded in time order.** Three decisions here, each with a reason.

*One timeline, not one per release.* `live_read_activated` is a claim about the DEPLOYMENT, and a
deployment runs one release at a time — so `release_ref` documents which release each event
concerned rather than partitioning the state. Partitioning it would break the only withdrawal
procedure there is: standing live read down means removing the wiring in a reviewed change and
**deploying that release**, so the stand-down necessarily names a *newer* release than the
activation it withdraws. A per-release rule would file the two in separate buckets and leave the old
activation standing forever.

*Folded, not read off the latest record.* A `LIVE_READ_STAND_DOWN` whose result is `FAIL` is a
withdrawal that **did not happen**, so live read is still on. A latest-wins rule would report it off
at exactly the moment somebody has just discovered they could not turn it off. Each record is
therefore applied for what it is: an activation that passed turns the claim on, a withdrawal that
did not fail turns it off, and anything that failed changes nothing, because a failed attempt is not
a state transition.

*The environment rule is asymmetric.* An **activation** counts only from a host environment — CI has
never touched Loopia and cannot activate anything there. A **withdrawal** counts from anywhere:
honouring a stand-down is always the safe move, and discarding one because it was filed under the
wrong environment would leave the claim standing on a technicality.

Canonical timestamps sort lexicographically by construction, so ordering needs no parsing and the
order in the file is irrelevant. An exact tie applies the `STAND_DOWN` last and therefore resolves
**against** the claim.

**Execution is not success.** `road_test_001_executed` is supported by a `ROAD_TEST_001` record
that `PASS`ed **or** `FAIL`ed — because "we ran one" and "it passed" are different facts, and
collapsing them is precisely how a failed road test becomes a green flag. Every other claim here
requires `PASS`. If a road test failed, the flag says it was executed and the record says it failed;
both are true and both are readable.

`STAND_DOWN` supports nothing, here included. It is neither a pass nor a fault — it records that an
activity was deliberately withdrawn, and a road test that was stood down is not a road test that was
executed. "Execution is not success" widens what counts as executed; it does not make every record
count.

## 7. Recording evidence

1. Run the activity and keep its output as a file, outside the repository if it is large.
2. Take its digest: `sha256sum <artifact>`.
3. Add one record to `deployment-evidence.json` by hand.
4. Validate: `php ops/evidence/validate.php`
5. Only then, if the claim is now supported, edit `upstream.lock.json` — as a separate, reviewed act.

Steps 3 and 5 are both human edits and both are reviewed. That is the design, not an ergonomic gap
waiting for automation: the automation would remove the review from exactly the decisions that need
one.

    php ops/evidence/validate.php
    php ops/evidence/validate.php --evidence=deployment-evidence.json --lock=upstream.lock.json

The command exits non-zero when the registry is malformed (`EVIDENCE_REGISTRY_INVALID`) or when the
lock asserts a claim the registry does not support (`CLAIM_WITHOUT_EVIDENCE`). It writes nothing.

## 8. Current state

The registry carries **no records**. Nothing has been deployed to Loopia, no probe has run on the
host, no smoke test has been executed against a deployed site, no rollback drill has been performed,
live read has never been activated, and no road test has been run.

Accordingly all five operational states are false, all four authority locks are false, and
`no_external_charge_executed` is true.
