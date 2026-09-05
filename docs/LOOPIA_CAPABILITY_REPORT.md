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
| P01 | competition monitoring: sources, capture, change detection, signals, battlecards, release, surfacing, two audits |
| P02 | entity and relationship twins, with four privacy screens |
| P03 | catalogue, ICP, fit hypotheses, prospect queue admission |
| P04 | stakeholder roles, role distribution, admission, release, three-gate surfacing, four audits |
| P05 | marketing drafts, claim citations, variants, admission, internal release, three audits |
| P07-A | consent, PII firewall, outreach admission, approval, frozen artifacts, synthetic dispatch, five audits |

**Storage** — 15 migrations, MariaDB 10.11, with the invariants held as CHECK constraints and
append-only triggers rather than as application conventions.

**Tests** — 1952 assertions across seven layers, all executed against MariaDB. See
`CONFORMANCE_MATRIX.md` §1 for the breakdown and for what has NOT been verified.

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
| schema migrations | complete, 15 files, idempotent |
| authority seeding | `Permissions` vocabulary + role seeds; P01 registration provisions a tenant capability |
| audit profiles | created on demand per category |
| CI | defined for foundation/unit and a MariaDB-backed job |
| runbook | `DEPLOYMENT_RUNBOOK.md` |
| rollback | five levers, documented and tested |

## 5. What has NOT happened

Stated plainly, because build completion is not launch authority:

- `ROAD_TEST_001_EXECUTED` — **false.** No road test has been run.
- `LIVE_READ_ACTIVATED` — **false.** No live external source has been captured outside tests.
- `OWNER_GO_GRANTED` — **false.** No owner sign-off exists.
- `PRODUCTION_LAUNCH` — **false.** Nothing is deployed.
- Loopia shared hosting — **untested.** The target runtime has not run this code.
- Hosted CI — runs the full suite on PHP 8.2.33 against MariaDB 10.11.19. It caught a
  non-deterministic test assertion that passed in the development container; see
  `CONFORMANCE_MATRIX.md` §1. That is the value of running on the supported floor rather than
  on whatever the development container happens to have.

## 6. What I would want before launch

An honest list, in the order I would do it:

1. **Deploy to staging on the actual Loopia host and run the suite there.** Everything in §1 is
   verified on a development container. Shared hosting differs in PHP configuration, available
   extensions, filesystem behaviour and MariaDB privileges, and each of those has broken a
   deployment like this before.
2. **Confirm the append-only triggers survive the host's import path.** They are the load-bearing
   half of several guarantees, and `mysqldump`/import round-trips have been known to drop
   `DELIMITER`-defined triggers.
3. **Provision a real `APP_MASTER_KEY` and exercise credential rotation** against the deployed
   configuration, not the test one.
4. **Run one road test end to end** with a real monitored source, and record what it found —
   including anything the change detector classified badly.
5. **Have somebody who did not write this read `CLAIM_CEILING.md`** and confirm the product's
   marketing does not exceed it.

Items 1 and 2 are the ones I would not skip.

## 7. Console

Built. Six surfaces — login, workspace, decision ledger, competition briefings, role distributions,
marketing drafts — server-rendered, with no JavaScript at all and a CSP that permits none.

Three properties are worth naming because they are what make the console trustworthy rather than
merely present:

- **Every surface re-runs the M02 guard** against the permission it actually needs. Being signed in
  is identity; authority is a separate question asked on every request. The test proves an operator
  holding one role is denied by each of four surfaces, each naming a different permission.
- **A denial renders the guard's own reason codes**, and the list is empty rather than partial.
  A missing role, a missing tier and an unconfigured target have different remedies, and an
  operator who cannot see which one refused cannot act on it.
- **Surfaces render from governed projections**, not from live joins across domain tables — which
  is what makes what a viewer saw on a given day reconstructable.

The navigation rail states on the page that hiding an item is not a permission and showing one is
not either, because an operator who believes the menu is the boundary will eventually test it.
