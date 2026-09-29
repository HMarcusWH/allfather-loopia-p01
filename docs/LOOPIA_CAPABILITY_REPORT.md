# LOOPIA CAPABILITY REPORT

What this deployment projection can actually do today, what it deliberately cannot, and what
remains before anyone should point a customer at it.

Written to be read by someone deciding whether to launch. The uncomfortable parts are in §5 and §6.

For the current source, tested, deployed and activated state in one place, read
[`MVP_RELEASE_STATE.md`](MVP_RELEASE_STATE.md) first; this report describes capability, and the
remaining sequence is [`MONITOR_COMPLETION_PLAN.md`](MONITOR_COMPLETION_PLAN.md).

---

## 1. What is built

**Governance substrate** — twelve modules, ported as runtime law rather than as scaffolding:

| Module | Responsibility |
| --- | --- |
| M01 | governed invocation; nominal admission via a WeakMap-keyed token |
| M02 | tenancy, scope, permissions; the excluded-permission boundary |
| M03 | capability identity, contracts, output-class bindings |
| M04 | missions, proposals, the action-class request ceiling |
| M05 | the evidence spine; source admission; bundle readiness |
| M06 | the governed tool registry and dispatch gateway |
| M07 | the HMWH review council, with producer-self-review eligibility |
| M08 | the arbiter: policy sets, rule engine, verdicts |
| M10 | the decision ledger; hash-chained, append-only, verifiable |
| M11 | audits, incidents, replay |
| M12 | authorized console surfaces and release readiness |
| M16 | governed release output; frozen artifacts and visibility |

M09 (approval) is present in exactly one narrow form — the P07-A outreach approval seat — and
nowhere else. M15 governed ingestion is present with the guarded egress path.

**Product planes** — six:

| Plane | What it does |
| --- | --- |
| P01 | competition monitoring: sources, capture, change detection, signals, battlecards, release, surfacing, usage metering, two audits |
| P02 | entity and relationship twins with four privacy screens, governed state-change admission, M10 release, and M16 entity/relationship snapshot artifacts; the released entity and relationship indexes are readable in the React console, entity detail is not built |
| P03 | catalogue, ICP, fit hypotheses, prospect queue admission, **M10 release and two-gate surfacing**; the priority-queue and monitored-prospect indexes are readable in the React console, prospect detail is not built |
| P04 | stakeholder roles, role distribution, admission, release, three-gate surfacing, four audits |
| P05 | marketing drafts, claim citations, variants, admission, internal release, three audits |
| P07-A | consent, PII firewall, outreach admission, approval, frozen artifacts, synthetic dispatch, five audits |

**Storage** — 27 migrations in the current implementation candidate, MariaDB 10.6+ / InnoDB, with the invariants held as CHECK constraints,
append-only triggers and the database-enforced uniqueness translations named in
`SCHEMA_REACHABILITY.md`. **Loopia Företag is MariaDB 10.6**; 10.11 is Företag Plus only. Both are
verified against real servers in the test matrix — see `CONFORMANCE_MATRIX.md` §1.

**Provisioning** — `ops/provision-staging.php` stands a tenant up by composing the real module
registrations for P01, P02, P03, P04 and P05. P02 provisions the promoted entity-twin curator
capability; offering twins remain outside the selected canonical graph.

**Tests** — the strict suite runs across seven layers against BOTH MariaDB 10.6 and 10.11, with the
server version read back from each connection rather than inferred from the requested image. The
exact current assertion counts are in `CONFORMANCE_MATRIX.md` §1 and the PR test matrix.

## 2. What it deliberately cannot do

Not "does not yet" — cannot, structurally:

- **Send business messages or mutate external customer systems.** No email/SMS/CRM outreach
  channel transport is present, and the P07-B binding producer is not ported. The new bounded
  model-inference transport is a separate implementation candidate whose default factory and
  prompt bindings remain inactive; it is not a messaging or external-action adapter.
- **Publish anything.** The marketing status vocabulary has no published state.
- **Perform an external or consequential action.** Refused at four independent planes.
- **Claim buyer intent.** The column admits only `REASON_TO_INVESTIGATE`.
- **Score an individual, or identify one.** Structural in three separate planes.
- **Delete a decision, an evidence object, or a release.** The storage refuses it.

## 3. What a tenant would actually get

Working end to end today, given seeded authority:

1. Register competitors and monitored sources under licence cards.
2. Capture source snapshots through the guarded egress path.
3. Detect and classify structural change.
4. Raise a company signal, route it through review and an arbiter verdict.
5. Materialize a battlecard — only on an allowing verdict.
6. Release it as a decision record, frozen into an M16 artifact.
7. Read it on a surface gated by role AND subscription AND live release.
8. Distribute it to a configured internal org-role, cross-role default-deny.
9. Draft marketing from it, with every comparative claim citing governed evidence.
10. Prepare an outreach for a consented, pseudonymized recipient — up to the point where a human
    must decide, and no further.

## 4. Operational readiness

The current working candidate has additional migrations. PR16 staging evidence proves the prior
19-migration release only; candidate migration execution, repeatability and host qualification must
be evidenced for the new exact release before claiming deployment readiness. P05 also makes named
MariaDB advisory locking a required host capability because Monitor run/source serialization now
depends on it; the PR16 host probe predates that proof, so it must be re-run for the P05 candidate.
The candidate also adds the inactive Monitor workbench and bounded model composition described in
[Monitor delivery and remaining work](MONITOR_DELIVERY_AND_REMAINING_WORK_2026_09_27.md).
Its local implementation and fixture checks do not update the deployed PR16 claims below.

| Area | State |
| --- | --- |
| schema migrations | 27 files in candidate; deployed-release count is recorded separately |
| authority seeding | `Permissions` vocabulary + role seeds; product registrations provision their selected tenant capabilities |
| audit profiles | created on demand per category |
| CI | PR16 exact-head local workflow reproduction PASS: PHP 8.2.34, MariaDB 10.6/10.11 and both host-shaped lanes at 5,552/0/0; hosted runner allocation blocked by account billing/spending. The workflow's steps now live in `ops/ci/qualify-release.sh`, whose `local` route reproduces them from an exact-commit export and writes a self-reported receipt |
| runbook | `DEPLOYMENT_RUNBOOK.md` for the mechanics, `BETA_RUNBOOK.md` for the sequence |
| rollback | PR16 to PR15 to PR16 drill PASS for named immutable history and request-log prefix; no populated M10 stream or full database restore exercised |
| host qualification | PR16 actual Loopia strict suite 5,552/0/0, probes, N/O/P and browser checks PASS under the staging deviation; E.1 FAIL_READABLE, E.3 UNVERIFIED |
| evidence | `deployment-evidence.json`, validated on every commit; **12 reviewed records; PR16 inert staging qualified under the recorded staging deviation** |

## 5. Measured staging state and uncompleted transitions

PR16 `392f48deef11380f6dccfe88f2a47ba1fd94575c` is deployed and qualified on
`staging.allfatherbi.com` under the recorded owner-directed shared-host staging deviation. The
actual Loopia runtime is PHP 8.5.9 with MariaDB 10.11.19-MariaDB-log. The complete strict suite
measured 5,552 passes, zero failures and zero skips against the separate qualification database.
The runtime's 19 migrations were already applied and remained unchanged, with no drift. Host
probes, runtime invariants, anonymous and authenticated N/O, request-ID proof, manual browser
inspection and the PR16-to-PR15-to-PR16 rollback/restore drill passed. See
[the reviewed Gate R filing](STAGING_GATE_R_EVIDENCE_2026-09-26.md) for scope, hashes and limitations.

Hosted GitHub Actions did **not** pass for PR16: run 36197550928 failed before any runner step,
with account billing/spending annotations. The distinct exact-head local reproduction completed
34 shell steps using PHP 8.2.34. Its MariaDB 10.6.28 and 10.11.19 lanes, and both host-shaped
copies, each measured 5,552/0/0. These local results are not substituted for hosted CI or the
separately measured Loopia result. Earlier runtime-version statements described historical checks.

The following remain uncompleted or unauthorized:

- **ROAD_TEST_001 and live read remain off.** No road test has run. The reviewed transport and DNS
  resolver are constructed only on MonitorFactory's admitted branch, and no grant is admitted (no owner
  decision ships); scheduler handler
  registries remain empty. Their implementation and host capability do not activate them.
- **Production is not deployed or authorized.** The staging owner direction is not a road-test or
  production launch GO. `OWNER_GO_GRANTED`, customer-visible output, external action and production
  launch authority remain false.
- **Production tenant provisioning remains outside the pinned canonical identity vocabulary.**
  Canonical admits TEST/DEMO/LOCAL_DEV identity classes; this projection must not invent CUSTOMER
  or PRODUCTION synonyms to bypass that boundary.
- **Technical cross-site isolation has not passed.** E.1 remains FAIL_READABLE; E.3 web-PHP
  isolation remains UNVERIFIED. The owner-directed staging deviation and WordPress maintenance
  exclusion are not isolation or hygiene proof. Production cannot inherit the staging exception.
- **A complete database backup/import restore has not been proved.** Gate Q preserved the named
  immutable history rows and prior request-log prefix. All phases had no M10 streams; it did not
  test a populated-ledger rollback or full database restore.
- **Credential rotation is not established by this evidence.** Preflight verified that the staging
  master key is set without disclosing it; that does not prove a rotation exercise or resolve the
  historical credential-log hygiene item.

## 6. Remaining work before live read or production

1. **Review and explicitly authorize ROAD_TEST_001 as a separate activation.** Use only the bounded
   approved public-source set, with captured evidence, replay and stand-down proof. Keep external
   effects, customer visibility and production authority off. The staging result alone grants no GO.
2. **Establish independent backup and full restore evidence.** Retain an off-Loopia backup and
   exercise its import/restore path, including append-only triggers and a populated ledger where
   applicable. The qualified host's trigger behavior and Gate Q's limited preservation proof do not
   replace that evidence.
3. **Close credential lifecycle evidence.** Plan and verify controlled rotation without losing
   access to encrypted data, and resolve the historical credential-like log item's status. Do not
   report either completed from a key-presence check. Production secrets must be new and distinct
   from staging.
4. **Close the production hosting boundary.** Obtain written assurance, a safe equivalent proof or
   a dedicated hosting boundary. Preserve E.1/E.3 findings until actual technical evidence changes
   them; the staging exception is not a production authorization.
5. **Resolve production identity and governed customer visibility through reviewed authority and
   implementation changes.** Do not widen tenant/workspace/environment vocabulary merely to get
   a production row through a constraint. Review the product's claims against `CLAIM_CEILING.md`
   and complete required privacy, legal, support and operational material before customer entry.
6. **Qualify scheduler transport if the production plan needs it.** Its method/header authentication
   remains UNVERIFIED and the handlers remain empty. Do not make scheduler activation a hidden
   prerequisite for the first controlled road test.
7. **Freeze a separately reviewed production candidate and obtain explicit owner GO.** Production
   readiness must include the completed road test, relevant beta findings, hosting boundary,
   identity/visibility changes, backup/restore and operational prerequisites. A green staging smoke
   or evidence-only documentation commit cannot grant that authority.

## 7. Console

Built, and it is now **two surfaces with one authority model** rather than the single scriptless
console an earlier revision of this document described.

**Server-rendered, scriptless, no JavaScript and a CSP that permits none:**

| Surface | What it is |
| --- | --- |
| `/login` | sign-in |
| `/account/password` | the compulsory first password change, and the ONE authenticated path reachable while it is outstanding |
| `/account` | the login, the governed operator it acts as, and the route to changing the password |
| `/console/workspaces` | the workspace chooser — a membership projection, never a workspace list |
| `/console/monitor` | candidate workbench for configured missions, exact request/candidate inspection, two human reviews and run history; default execution remains inactive |
| `/support` | Help & support: the configured contact, the deployed release reference, and the claim ceiling restated |
| `/console`, `/console/decisions`, `/console/briefings`, `/console/distributions`, `/console/marketing` | the legacy governed SSR surfaces |

**React, authenticated, at `/console/app`:** the Operator Console shell. It is served only to a
signed-in session, only from the locally staged content-hashed bundle, and it receives exactly one
value in its document — the session's own CSRF token, so sign-out can stay a POST.

**Transport.** An explicit same-origin GET API: nine exact routes under `/console/api/v1/`, each
registered by hand. There is no generic collection route, no query route and no raw-table route, and
`tests/foundation/governed_browser_read_boundary.php` asserts the absence of each by name.

**Authority is the backend's, entirely.** Every one of those nine reads terminates in the owner
gate — M02 permission, then the commercial entitlement where one applies, then the module's own
visibility rule. The browser's permission state is **presentation and usability only**: it decides
what to render and what to ask for, and it decides nothing about what it is given.

**Scope is the session's, entirely.** Tenant, workspace and operator come from the resolved session
row. A caller supplying `tenant_id`, `workspace_id`, `operator_id` or `environment_class` as query
parameters changes no status, no access decision and no returned value, and none of the four is
reflected back — proved end to end in `tests/acceptance/beta_launch_journey.php`.

**Two CSP profiles, deliberately distinct:**

    SSR:    no script-src at all — the surfaces are scriptless and cannot become otherwise by accident
    React:  default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self';
            connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'

No `unsafe-inline`, no `unsafe-eval`, no CDN, no remote origin, no source map, no fixture fallback.

Four properties are worth naming because they are what make the console trustworthy rather than
merely present:

- **Every surface re-runs the M02 guard** against the permission it actually needs. Being signed in
  is identity; authority is a separate question asked on every request.
- **A denial renders the guard's own reason codes**, and the list is complete rather than partial. A
  missing role, a missing tier and an unconfigured target have different remedies, and an operator
  who cannot see which one refused cannot act on it.
- **A refusal by STATE is not dressed as a refusal by authority.** A session with no active
  workspace gets a 409 naming `NO_ACTIVE_WORKSPACE` and **no** required permission — because no
  grant fixes having no workspace, and naming one would send an operator to ask for a permission
  they already hold. The chooser is the remedy, and the page points at it.
- **An owner denial is never an empty result.** A 403 carrying `DENIED` and a 200 carrying `EMPTY`
  are different answers to different questions, and a console that showed the first as the second
  would tell a partner there is nothing to see when the truth is that they may not look.

**Released-intelligence read surfaces use governed projections.** The new Monitor workbench has
a different purpose: it reads the scoped run journal and private content-addressed request,
candidate and review artifacts, together with fresh M10 reliance. Those records include unreleased
work and are not presented as a released-intelligence projection. Historical run completion is
separate from the currently effective release state.

The navigation rail states on the page that hiding an item is not a permission and showing one is
not either, because an operator who believes the menu is the boundary will eventually test it.

## 8. Deployment qualification tooling

Added so that host qualification is a matter of running commands and collecting evidence rather than
another design phase:

| Command | Answers |
| --- | --- |
| `ops/loopia-probe/probe.php` | what this host can actually do — three separate verdicts, every storage capability demonstrated rather than inferred |
| `ops/loopia-probe/isolation-canary.php` | can the SSH/CLI identity of one site in this account read another's private files? Asked **before** any secret is placed. Bounded to that identity — web PHP isolation stays UNVERIFIED, see `LOOPIA_HOST_MODEL.md` §5 blocker 4 |
| `ops/deploy/preflight.php` | is this release internally consistent and safe to copy inertly? |
| `ops/staging-smoke.php` | does the DEPLOYED site answer correctly over real HTTPS? |
| `ops/rollback/verify-stand-down.php` | did a rollback or stand-down actually take effect? |
| `ops/evidence/validate.php` | does the evidence registry support the claims the lock asserts? |

None of them deploys anything, and none records evidence: filing a record is a reviewed edit to
`deployment-evidence.json`. The sequence that uses them is `docs/BETA_RUNBOOK.md`.
