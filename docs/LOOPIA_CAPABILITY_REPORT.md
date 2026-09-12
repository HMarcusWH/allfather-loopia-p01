# LOOPIA CAPABILITY REPORT

What this deployment projection can actually do today, what it deliberately cannot, and what
remains before anyone should point a customer at it.

Written to be read by someone deciding whether to launch. The uncomfortable parts are in §5 and §6.

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
| P02 | entity and relationship twins with four privacy screens, governed state-change admission, M10 release, and M16 entity/relationship snapshot artifacts; runtime implemented, web surface not included |
| P03 | catalogue, ICP, fit hypotheses, prospect queue admission, **M10 release and two-gate surfacing** |
| P04 | stakeholder roles, role distribution, admission, release, three-gate surfacing, four audits |
| P05 | marketing drafts, claim citations, variants, admission, internal release, three audits |
| P07-A | consent, PII firewall, outreach admission, approval, frozen artifacts, synthetic dispatch, five audits |

**Storage** — 19 migrations, MariaDB 10.6+ / InnoDB, with the invariants held as CHECK constraints,
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

- **Send anything.** No channel adapter, no transport, no code path that could acquire one. The
  P07-B binding producer is not ported at all.
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

| Area | State |
| --- | --- |
| schema migrations | complete, 19 files, idempotent |
| authority seeding | `Permissions` vocabulary + role seeds; product registrations provision their selected tenant capabilities |
| audit profiles | created on demand per category |
| CI | PHP 8.2 foundation plus full MariaDB 10.6 and 10.11 lanes, each writing a machine-readable qualification report, gated by a `release qualification` job that re-reads them |
| runbook | `DEPLOYMENT_RUNBOOK.md` for the mechanics, `BETA_RUNBOOK.md` for the sequence |
| rollback | five governed levers plus a code-rollback procedure and a read-only verifier |
| host qualification | probe, cross-site canary, release preflight, staging smoke — see §8 |
| evidence | `deployment-evidence.json`, validated on every commit; **zero records so far** |

## 5. What has NOT happened

Stated plainly, because build completion is not launch authority:

- `ROAD_TEST_001_EXECUTED` — **false.** No road test has been run.
- `LIVE_READ_ACTIVATED` — **false**, and structurally so rather than by flag: the production
  transport and DNS resolver exist and are tested, and NOTHING CONSTRUCTS EITHER. The foundation
  suite fails if anything does. See `DEPLOYMENT_RUNBOOK.md` §7a for what activating it takes.
- `OWNER_GO_GRANTED` — **false.** No owner sign-off exists.
- `PRODUCTION_LAUNCH` — **false.** Nothing is deployed.
- **Production tenant provisioning is not authorized by the pinned canonical identity vocabulary.**
  Canonical currently admits TEST/DEMO/LOCAL_DEV identity classes, not projection-invented
  `CUSTOMER` / `PRODUCTION` synonyms. `ops/provision-staging.php` therefore refuses production
  before opening the database; a future production identity must be promoted canonically first.
- Loopia shared hosting — **untested.** The target runtime has not run this code.
- Hosted CI — runs PHP 8.2.33 with a full MariaDB **10.6 and 10.11 matrix**, including migration
  idempotency and the strict suite. That is the supported-PHP-floor evidence; the development
  container runs PHP 8.4.19.

## 6. What I would want before launch

An honest list, in the order I would do it:

1. **Deploy to staging on the actual Loopia host and run the suite there.** Everything in §1 is
   verified in development/CI rather than on Loopia's shared host. Shared hosting differs in PHP
   configuration, available extensions, filesystem behaviour and MariaDB privileges, and each of
   those can invalidate an assumption that is green elsewhere. `BETA_RUNBOOK.md` is that sequence,
   and its two standing rules are that the qualification database is never the runtime database and
   that no secret reaches the host before cross-site isolation is proved.
2. **Confirm the append-only triggers survive the host's import path.** They are the load-bearing
   half of several guarantees, and `mysqldump`/import round-trips have been known to drop
   `DELIMITER`-defined triggers.
3. **Provision a real `APP_MASTER_KEY` and exercise credential rotation** against the deployed
   configuration, not the test one.
4. **Run one road test end to end** with a real monitored source, and record what it found —
   including anything the change detector classified badly.
5. **Have somebody who did not write this read `CLAIM_CEILING.md`** and confirm the product's
   marketing does not exceed it.
6. **Before customer production, resolve the canonical production identity/onboarding model.** Do
   not widen tenant/workspace/environment vocabulary inside this deployment projection merely to
   get a production row through a CHECK constraint.

Items 1 and 2 are the ones I would not skip before staging validation; item 6 is a separate
pre-customer-production authority decision.

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

**Surfaces render from governed projections**, not from live joins across domain tables — which is
what makes what a viewer saw on a given day reconstructable.

The navigation rail states on the page that hiding an item is not a permission and showing one is
not either, because an operator who believes the menu is the boundary will eventually test it.

## 8. Deployment qualification tooling

Added so that host qualification is a matter of running commands and collecting evidence rather than
another design phase:

| Command | Answers |
| --- | --- |
| `ops/loopia-probe/probe.php` | what this host can actually do — three separate verdicts, every storage capability demonstrated rather than inferred |
| `ops/loopia-probe/isolation-canary.php` | can one site in this account read another's private files? Asked **before** any secret is placed |
| `ops/deploy/preflight.php` | is this release internally consistent and safe to copy inertly? |
| `ops/staging-smoke.php` | does the DEPLOYED site answer correctly over real HTTPS? |
| `ops/rollback/verify-stand-down.php` | did a rollback or stand-down actually take effect? |
| `ops/evidence/validate.php` | does the evidence registry support the claims the lock asserts? |

None of them deploys anything, and none records evidence: filing a record is a reviewed edit to
`deployment-evidence.json`. The sequence that uses them is `docs/BETA_RUNBOOK.md`.
