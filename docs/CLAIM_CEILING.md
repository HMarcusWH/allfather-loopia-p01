# CLAIM CEILING

What this projection is permitted to say, and what it must not — stated before anything else,
because every other document here is downstream of it.

A claim ceiling is not a disclaimer. It is the highest-strength assertion each object in the system
is structurally capable of making, and in almost every case the ceiling is enforced by a storage
constraint rather than by code that could be edited away.

---

## 1. The three product ceilings

| Plane | May assert | May NOT assert | Enforced by |
| --- | --- | --- | --- |
| P01 competition monitoring | that a monitored page CHANGED, and how significantly | that the change means a customer will be affected | `interpretation_kind` (`FACT_OBSERVED` / `HYPOTHESIS`), carried onto the artifact as a derived uncertainty statement |
| P02 entity twins | business facts about a COMPANY, at a declared certainty | anything about a named person, any employee score, buyer intent as fact | four field screens + `INDIVIDUAL_IDENTIFIER_IN_TWIN_FIELD`; one prohibited field refuses the whole version |
| P03 lead qualification | that a company MIGHT FIT what the tenant sells | that a company wants to buy | `claim_kind` CHECK admits only `REASON_TO_INVESTIGATE`; the M08 `p03_buyer_intent_trigger` rule BLOCKs a queue admission whose signal is not an observed trigger; the P08 `BUYER_INTENT_INVARIANT` audit, **when an operator runs it** (nothing schedules it, and it is not deployed), re-scans persisted queue items and opens an incident for any that bypassed the gate (defence in depth after the fact, not the gate, and not continuous monitoring) |
| P04 distribution | that an already-released briefing may be shown to an internal ROLE | anything about an individual, and anything external | `subject_kind` CHECK admits only `ROLE_CLASS`; `is_delivery_assurance_only` CHECK-pinned to 1 |
| P05 marketing | internal draft copy citing governed evidence | a comparative claim with no evidence; anything published | `cited_evidence_id` NOT NULL; `asset_status` has no `PUBLISHED` member. The comparative/forbidden markers are English-only and read the body, not the title; a present citation is not proof the evidence supports the claim — see `P03_ASSURANCE_PORT.md` §5 |
| P07-A outreach | that an outreach WOULD be permissible, pending a person | that anything was sent | `proposal_status` tops out at `APPROVAL_REQUIRED`; no channel adapter exists |

## 2. The one sentence that matters most

**A product-fit hypothesis is not buyer intent.**

The system will tell a tenant that a company may fit what they sell, and it will refuse to tell them
that the company wants to buy it. Those are different claims about different things — one about a
product, one about a mind — and the second is not available at any evidence level.

The refusal is not a warning label. `fit_hypotheses.claim_kind` is CHECK-pinned to
`REASON_TO_INVESTIGATE`; there is no `INTENT`, `READY_TO_BUY` or `QUALIFIED_LEAD` member to write.
Promoting a hypothesis to a priority queue additionally requires the M08 rule to find a signal that
is BOTH a buying-trigger class AND directly observed — and failing it BLOCKs rather than holds,
because the claim was never available to make.

## 3. Release ceilings

`RELEASED_INTERNAL` is the strongest release state in this projection.

- M16 audiences are ordered `OPERATOR_ONLY` < `TENANT_INTERNAL` < `CUSTOMER_VISIBLE` <
  `EXPORTABLE` < `API_VISIBLE` < `CHANNEL_EMAIL_SMS_CRM`, and a projection may never exceed the
  source-use ceiling its evidence was captured under.
- `BLOCKED_QUARANTINED` is in the audience vocabulary but is NOT on that ladder. It is a
  terminal-deny pseudo-audience — a rendering state for withheld content, never a destination — so
  it appears in no storage CHECK, is seeded no visibility profile, and is refused at the projection
  gate as `AUDIENCE_NOT_A_PROJECTION_TARGET` rather than as a ceiling breach.
- **No path activates a `CUSTOMER_VISIBLE` projection**, and the reason is worth stating precisely,
  because the two halves are different rules. The source-use ceiling says what the SOURCES permit:
  a fully-permissive licence records the full ladder, exactly as canonical does, and a projection
  for a wider audience is then PROJECTED rather than refused. What stops it going anywhere is the
  activation pin — `ReleaseOutput::ACTIVATABLE_AUDIENCES` is `OPERATOR_ONLY` and `TENANT_INTERNAL`,
  the storage CHECK forbids an `ACTIVATED` event for anything wider, and no adapter asks for a
  wider projection in the first place. A licence that does NOT permit customer output refuses the
  projection too, at `CUSTOMER_OUTPUT_NOT_ALLOWED_BY_SOURCE_USE` and `AUDIENCE_ABOVE_PROFILE_CEILING`
  together. **A source permission ceiling is not a deployment authority.** An earlier revision of
  this port conflated them by capping the recorded ceiling, which made the profile assert something
  false about the licence; the ceiling is now canonical and the pin is separate.
- **A released prospect output is an M10 reference, not an M16 artifact.** P03, P04 and P05 each
  carry their own release projection. M16's frozen-artifact path is adopted by **P01 and P02**, as
  canonical does — four artifact kinds in total, and the two P02 kinds are governed by their own
  access rule keyed by artifact kind, so neither adopting family inherits the other's permission.
  Every projection rests on the same M10 decision record — one release authority, several
  projections.
- **A HELD artifact is a completed release, not a failed one.** When a contributing source cannot
  clear a tenant-wide read — a twin's external references carry no governed licence card — the
  artifact is still frozen and still M10-bound, and an operator can read it. Nothing wider is
  projected or activated. The release itself stands: the hold is a statement about the SOURCES.
- **Usage metering records; it does not bill.** `no_external_charge_executed` is CHECK-pinned to 1
  and the table is append-only. A usage summary is a statement of what WOULD be billed, and nobody
  is billed by it.
- A release is a decision record. There is no way to release without one, which is why every
  "released" claim in this system resolves to a chain that can be verified.

## 4. What the system says about its own uncertainty

Three fields are REQUIRED rather than optional, and each exists because its absence is how
overclaiming happens:

- `uncertainty_summary` on every proposal envelope — an unknown not written here has no way to
  reach a reviewer.
- `rejected_claims_safe` on every battlecard — what the evidence does NOT support. A card that only
  said what was supported would invite the reader to fill the silence themselves.
- `declared_limitations` on every proposal — including, on every outreach and marketing proposal,
  the statement that this projection performs no external action.

**Production is not a ceiling this projection can raise (P12).** No production identity class,
customer audience or production GO contract exists canonically.
`ops/deploy/production-preflight.php` reports what is missing and can at most say
`READY_FOR_OWNER_DECISION`; it never grants GO, and `APP_ENV=production` is a strict posture, not
authority. See [`PRODUCTION_PROMOTION_PLAN.md`](PRODUCTION_PROMOTION_PLAN.md).

## 5. What this document does not license

Nothing here is a claim about the CANONICAL Allfather runtime, whose ceilings are its own and are
higher in several places. This document describes the deployment projection only. Where the two
differ, `docs/PORT_SCOPE_MATRIX.md` records the difference and the reason.
