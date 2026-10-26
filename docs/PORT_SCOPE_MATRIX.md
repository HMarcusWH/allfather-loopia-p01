# PORT_SCOPE_MATRIX

**Plane:** Ops / Sources bridge — port scope decision record.
**Canonical authority:** `HMarcusWH/Allfatherbusinessintelligence` @ `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`
**Projection:** `LOOPIA_SME_MVP` (historically `LOOPIA_P01`)
**Original port record as-of:** 2026-09-04
**Bounded integration addendum:** 2026-09-27 (§7)

This document records, per upstream object, whether it is INCLUDED in the Loopia deployment
projection, on what current authority, and where its PHP implementation lives. It is the audit
entry point: another engineer should be able to check the port against upstream without reading
any conversation.

---

## 0. Historical head resolution (original port decision)

| Repository | Expected head (task prompt) | Live head resolved | Difference |
| --- | --- | --- | --- |
| `HMarcusWH/Allfatherbusinessintelligence` (main) | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | **none** |
| `HMarcusWH/allfather-loopia-p01` (main) | `cd56c206f5fd2626fc649dcf5f96ae9186234405` | `69d2e5daa4e1e185e55483dab9b6c8c2ea78b9b8` | **moved** — PR #2 merged. `git diff c412f11 69d2e5d` is empty, so the merged tree is exactly the audited PR head; the merge introduced nothing unreviewed |

Both resolved by `git fetch origin main && git rev-parse origin/main` against the live remotes.
No registry snapshot was treated as authority.

---

## 1. Current upstream gate authority (the promotion facts this port relies on)

Read from the owning current records at the pinned SHA, not from historical MVP maps.

| Module | Gate act | Date | Ceiling recorded upstream | Port decision |
| --- | --- | --- | --- | --- |
| P01 Competition Monitoring | **GRANTED** | 2026-06-06 | `INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS` | **INCLUDE** |
| P02 Entity Twin Network | **GRANTED with explicit limitations** | 2026-06-10 | `INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS`, **entity + relationship twins only** | **INCLUDE (bounded)** — BOTH lifecycles ported end to end, each adopting M16. Offering twins remain excluded |
| P02 offering twins | **NOT PROMOTED** (V1-GAP-097) | — | `PARTIAL_IMPLEMENTATION_NOT_PROMOTED` | **EXCLUDE** |
| P02 EOM (Entity Operating Model) | `EOM_RUNTIME_STATUS=ABSENT`, `PROPOSED_ONLY` | — | outside cp11 gate | **EXCLUDE** |
| P03 Lead Qualification | **GRANTED** | 2026-06-06 | `INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS` | **INCLUDE** |
| P03 ICP extension (ICP-D5) | **GRANTED** | 2026-06-12 | internal / no-customer-visible | **INCLUDE** |
| P04 Intelligence Distribution | **GRANTED** | 2026-06-07 | `INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS` | **INCLUDE** |
| P05 Marketing Customization | **GRANTED** | 2026-06-10 | internal / **no-live-effect** | **INCLUDE** |
| P07-A Outreach safety foundation | **GRANTED** | 2026-06-07 | `INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS`, synthetic dispatch only | **INCLUDE (synthetic only)** |
| P07-B live external send | **NOT GRANTED** — legal/privacy review recorded as FINAL BLOCKER | — | no live send | **EXCLUDE — HARD** |
| M15 Governed Ingestion | **GRANTED** (R-M15-5) | 2026-06-11 | A/B ceiling; `LIVE_READ_ACTIVATED=FALSE` | **INCLUDE (activation gated)** |
| M16 Governed Release Output | **GRANTED** (R-M16-5) | 2026-06-12 | `TENANT_INTERNAL` only; every wider audience designed-inert | **INCLUDE (TENANT_INTERNAL only)** |

Sources: `docs/allfather-bi/implementation/{p01,p02,p03,p04,p05,p07}_release_readiness_report.md`,
`{p01,p02,p03,p04,p05,p07,p07b,m15,m16}_promotion_record.md`,
`icp_extension_readiness_report.md`, `icp_extension_ratification_packet_2026_06_12.md`,
`docs/allfather-bi/STATE_OF_THE_BUILD.md`.

---

## 2. Deployment-truth states carried forward unchanged

Upstream records these at the pinned head; **finishing this port does not move any of them**:

```text
ROAD_TEST_001_EXECUTED        = FALSE
LIVE_READ_ACTIVATED           = FALSE
OWNER_GO_GRANTED              = FALSE
PRODUCTION_LAUNCH             = NOT_AUTHORIZED
CUSTOMER_VISIBLE_OUTPUT       = FALSE
LIVE_EXTERNAL_ACTION          = FALSE
```

The Loopia projection mirrors these in `upstream.lock.json.claim_boundary` and enforces them in
`tests/` as foundation invariants.

---

## 3. Product modules — object-level port scope

Legend — **Plane**: Core = runtime law / governed execution object; Src = index/registry/authority
description; Ops = implementation/deployment/validation. **Port**: ✅ ported · ⚠️ partial, with what
is and is not there named in the Notes · ❌ not ported.

> **These tables were written as a PLAN and were corrected against the code on 2026-09-05.** Roughly
> a third of the rows carried a ✅ and a filename that was never created — some because the behaviour
> landed in a differently-named or merged file, and some because it was not ported at all. The
> filenames and the marks below now name what exists. Nothing was BUILT to make this table true:
> where the port is smaller than the plan, the table says so, because a Sources document that
> overstates the runtime is worse than one that admits a gap. The gaps are collected in §3.7.

### P01 — Competition Monitoring & Company Briefings

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Competitor registry + status transitions | Core | `src/p01/competitors.ts` | promoted | ✅ | `app/Product/P01/Registry.php` | Forward-only lifecycle; `ARCHIVED` terminal. Merged with monitored sources: one registry, two subjects |
| Source license cards | Core | `src/p01/source-cards.ts` | promoted | ✅ | `app/Product/P01/SourceCards.php` | robots/terms/licence/expiry gates |
| Monitored sources | Core | `src/p01/monitored-sources.ts` | promoted | ✅ | `app/Product/P01/Registry.php` | approved-card + ACTIVE_FOR_CAPTURE fail-closed |
| Governed source capture | Core | `src/p01/capture-source.ts` | promoted | ✅ | `app/Product/P01/Capture.php` | drives the egress guard; compiles the source policy with NO connector (§4.3) |
| Change detection / diffs | Core | `src/p01/change-detection.ts` | promoted | ✅ | `app/Product/P01/ChangeDetection.php` | deterministic; never LLM |
| CompanySignal | Core | `src/p01/company-signals.ts` | promoted | ✅ | `app/Product/P01/CompanySignals.php` | FACT_OBSERVED vs HYPOTHESIS |
| Battlecard briefs | Core | `src/p01/battlecards.ts` | promoted | ✅ | `app/Product/P01/Battlecards.php` | evidence-cited |
| M10 release | Core | `src/p01/release.ts` | promoted | ✅ | `app/Product/P01/Release.php` | post-verdict source re-check |
| Entitlements / tiers | Core | `src/p01/entitlements.ts` | promoted | ✅ | `app/Product/P01/Entitlements.php` | recording only |
| Surfacing (M12) | Core | `src/p01/surfacing.ts` | promoted | ✅ | `app/Product/P01/Surfacing.php` | three-gate default-deny |
| Usage metering | Core | `src/p01/billing.ts` | promoted | ✅ | `app/Product/P01/Metering.php` | **RECORDING ONLY.** `no_external_charge_executed` CHECK-pinned 1, table append-only, rate snapshotted at record time. No charge, no invoice, no payment path — asserted by a tripwire over the module's executable code |
| M11 assurance profiles | Core | `src/p01/assurance.ts` | promoted | ✅ | `app/Product/P01/Assurance.php` | freshness / diff-integrity audits |
| Registration | Src | `src/p01/registration.ts` | promoted | ✅ | `app/Product/P01/Registration.php` | `registerCompetitionMonitor` — Valkyrie, contract, tool, M08 policy set, M16 profiles, tiers. P02–P05 now have their own; **P07-A has none** |
| `p01/fetch-guard.ts` | — | re-export shim | superseded | ❌ | — | **do not resurrect**; real guard is `ingestion/egress-guard.ts` |

### P02 — Entity / Company Twin (bounded to the promoted scope)

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Entity twins + versions | Core | `src/p02/entity-twins.ts` | promoted | ✅ | `app/Product/P02/EntityTwins.php` | own company = `OWN_COMPANY` first-party boundary |
| Twin state-change proposals | Core | `src/p02/twin-state-change.ts` | promoted | ✅ | `app/Product/P02/TwinStateChange.php` | `create` / `submit` / `openReview` — the binding, the M04 proposal freezing the requested scope into its digest, the M05 basis, the M07 `TWIN_STATE_REVIEW` |
| Twin admission | Core | `src/p02/twin-admission.ts` | promoted | ✅ | `app/Product/P02/TwinAdmission.php`, `M08/PolicySets.php`, `M08/RuleEngine.php` | The policy set (15 rules), their evaluation, and the caller. Only `ALLOW_INFORMATIONAL_RELEASE` advances; BLOCK/QUARANTINE terminate |
| Entity relationships | Core | `src/p02/entity-relationships.ts` | promoted | ✅ | `app/Product/P02/RelationshipTwins.php` | evidence/confidence/freshness required |
| Relationship admission + release | Core | `src/p02/relationship-admission.ts` | promoted | ✅ | `app/Product/P02/RelationshipStateChange.php`, `RelationshipAdmission.php`, `RelationshipRelease.php`, `RelationshipTwins::releaseSnapshotTxFree` | The full governed path with its own six-rule `P02_RELATIONSHIP_STATE_ADMISSION` set. The producer's authority is checked in three parts: contract, output-class binding, mission |
| Twin release + M10 binding floor | Core | `src/p02/twin-release.ts`, `m10-binding-floor.ts` | promoted | ✅ | `app/Product/P02/TwinRelease.php`, `M10BindingFloor.php`, `EntityTwins::releaseSnapshotTxFree` | M10 owns the release; the floor is a typed refusal BEFORE the insert. The M16 snapshot-view artifact composes inside the post-M10 transaction — see the M16 row below. (An earlier revision of this cell said it was not ported; that was corrected 2026-09-06 along with the row itself) |
| Stakeholder roles (minimized) | Core | `src/p02/stakeholder-roles.ts` | promoted | ⚠️ | `app/Product/P04/StakeholderRoles.php` | **Corrected 2026-09-06.** P04's module writes `stakeholder_role_observations` — the distribution-side object it consumes. P02's own `stakeholder_role_profiles` / `stakeholder_role_attributes` have **no producer in this port**; the `STAKEHOLDER_PROFILE_MINIMIZATION` audit stands over them as a control with nothing yet to audit |
| Twin assurance (12 M11 audits) | Core | `src/p02/twin-assurance.ts` | promoted | ✅ | `app/Product/P02/TwinAssurance.php` | All twelve, on the certified M11 plane. Ten have a producer in this port; `TWIN_RELATIONSHIP_EDGE_SUPPORT` and `STAKEHOLDER_PROFILE_MINIMIZATION` are STANDING controls whose subjects nothing here writes — each is proved to bite against a planted row |
| Registration | Src | `src/p02/registration.ts` | promoted | ✅ | `app/Product/P02/Registration.php` | `registerEntityTwinCurator`. The tier grants `entity_twin.read` ONLY — upstream's wider read set names surfaces this port does not have |
| M16 snapshot-view artifact | Core | `src/release-output/p02-adapter.ts` | promoted | ✅ | `app/Substrate/M16/P02Adapter.php` | **Corrected 2026-09-06.** An earlier revision excluded this, reasoning that a HELD outcome made the release "identical either way". It is not: a held artifact is frozen, M10-bound, and carries an operator hold-view. Both kinds compose inside the post-M10 transaction — REFUSED rolls the unit back, HELD completes the release |
| Ledger anchors | Core | `src/p02/entity-twins.ts` (`recordEntityTwinLedgerAnchor`) | promoted | ✅ | `EntityTwins::recordLedgerAnchor` | Ported because `RETENTION_POLICY_COVERAGE` audits for one. Workspace-scoped: the anchor is release-gating authority |
| **Offering twins** | Core | `src/p02/offering-twins.ts` | **NOT PROMOTED** (V1-GAP-097) | ❌ | — | **EXCLUDED by current gate.** P03 catalogue is the MVP products/offers authority |

### P03 — Lead Qualification & Product Matching

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Customer catalogue authority | Core | `src/p03/customer-catalogue.ts` | promoted | ✅ | `app/Product/P03/Catalogue.php` | tenant-owned source card |
| Governed catalogue capture | Core | `src/p03/catalogue-capture.ts` | promoted | ✅ | `app/Product/P03/Catalogue.php` | `addProduct`, gated on the reference still being ACTIVE_FOR_CAPTURE |
| Fit hypotheses | Core | `src/p03/fit-hypothesis.ts` | promoted | ✅ | `app/Product/P03/FitHypotheses.php`, `FitScoring.php` | deterministic scoring; **never buyer intent** |
| ICP profiles + versions | Core | `src/p03/icp.ts` | promoted (ICP-D5) | ✅ | `app/Product/P03/Icp.php` | excluded-segment BLOCK |
| Prospect admission | Core | `src/p03/prospect-admission.ts` | promoted | ✅ | `app/Product/P03/ProspectQueue.php` | `submitForAdmission` / `admit`, against `P03_PROSPECT_ADMISSION` |
| Priority outreach queue | Core | `src/p03/prospect-queue.ts` | promoted | ✅ | `app/Product/P03/ProspectQueue.php` | buyer-intent gate |
| Prospect battlecard briefs | Core | `src/p03/release.ts` | promoted | ✅ | `app/Product/P03/Release.php` | M10 release + the two-gate surfacing. **Its own projection, NOT M16** — canonical P03 references the decision record rather than minting an artifact. Release-boundary card re-read along the prospect→hypothesis→product→catalogue→card lineage. The `PROSPECT_BATTLECARD_BRIEF` kind is NOT minted: upstream's release path carries that column NULL |
| M11 audit profiles | Core | `src/p03/assurance.ts` | promoted | ✅ | `app/Product/P03/Assurance.php` | P08: `CATALOGUE_FRESHNESS`, `SIGNAL_PAIRING_FRESHNESS`, `BUYER_INTENT_INVARIANT` on the certified M11 plane, held to 74 canonical-generated parity vectors and a 60-instant `Date.parse` battery; operator sweep `ops/assurance/p03.php`. Post-outcome assurance only — see `docs/P03_ASSURANCE_PORT.md` |
| Console panels | Core | `src/p03/console-panels.ts` | promoted | ❌ | — | **NOT PORTED.** The console surfaces P01, P04 and P05 output; P03 has no panel |
| Registration | Src | `src/p03/registration.ts` | promoted | ✅ | `app/Product/P03/Registration.php` | `registerLeadQualification` — M03 Valkyrie + contract, M06 tool (platform row, converged), M08 policy set, two tiers |

### P04 — Intelligence Distribution (internal only)

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Role distribution composition | Core | `src/p04/distribution.ts` | promoted | ✅ | `app/Product/P04/Distribution.php` | role-class targeting; redaction config |
| Routing admission | Core | `src/p04/distribution-admission.ts` | promoted | ✅ | `app/Product/P04/DistributionAdmission.php` | non-overridable `p04_individual_identifier_forbidden` |
| Release + three-gate visibility + ack | Core | `src/p04/release.ts` | promoted | ✅ | `app/Product/P04/Release.php` | delivery-assurance-only ack |
| Stakeholder ROLE_CLASS observation | Core | `src/p04/stakeholder-roles.ts` | promoted | ✅ | `app/Product/P04/StakeholderRoles.php` | `subject_kind` storage-pinned `ROLE_CLASS` |
| M11 audits incl. no-covert-telemetry | Core | `src/p04/assurance.ts` | promoted | ✅ | `app/Product/P04/Assurance.php` | |
| Registration | Src | `src/p04/registration.ts` | promoted | ✅ | `app/Product/P04/Registration.php` | `registerIntelligenceDistribution` — contract forbids covert stakeholder scoring and external message delivery explicitly |

### P05 — Marketing Customization (internal drafts only)

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Marketing asset drafts + variants | Core | `src/p05/marketing-asset.ts` | promoted | ✅ | `app/Product/P05/MarketingAssets.php` | **no `PUBLISHED` status exists** |
| Admission | Core | `src/p05/marketing-admission.ts` | promoted | ✅ | `app/Product/P05/MarketingAdmission.php` | uncited-claim + forbidden-pattern BLOCK |
| Internal release + surfacing | Core | `src/p05/marketing-release.ts` | promoted | ✅ | `app/Product/P05/Release.php` | |
| M11 assurance | Core | `src/p05/assurance.ts` | promoted | ✅ | `app/Product/P05/Assurance.php` | |
| Registration | Src | `src/p05/registration.ts` | promoted | ✅ | `app/Product/P05/Registration.php` | `registerMarketingCustomization` — contract forbids EXTERNAL_PUBLISH and DARK_PATTERN |

### P07-A — Synthetic outreach preparation (NO live send)

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Consent / lawful basis registry | Core | `src/p07/consent.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/Consent.php` | append-only |
| PII firewall + pseudonymization | Core | `src/p07/pii-firewall.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/PiiFirewall.php` | erasure = retention downgrade |
| Frozen outreach artifacts | Core | `src/p07/outreach-artifacts.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/OutreachArtifacts.php` | digest-bound |
| Outreach admission | Core | `src/p07/outreach-admission.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/OutreachAdmission.php` | non-overridable consent rule |
| Synthetic dispatch | Core | `src/p07/outreach-delivery.ts` | promoted (P07-A) | ✅ (inert) | `app/Product/P07A/SyntheticDelivery.php` | **synthetic only, no network** |
| Channel binding | Core | `src/p07/channel-binding.ts` | promoted (P07-A) | ❌ | — | **DELIBERATELY NOT PORTED**, and this is stronger than porting it inert. Upstream labels the file its own P07-B checkpoint; no P07-A module reads `outreach_channel_bindings`, so the port ships NO PRODUCER for that table — no row can exist for a CHECK to constrain |
| M11 audits | Core | `src/p07/assurance.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/Assurance.php` | |
| **P07-B live send** | — | `p07b_promotion_record.md` | **NOT GRANTED** | ❌ | — | **HARD EXCLUSION.** No SMTP/SMS/CRM/webhook adapter exists in this repo |

### 3.7 What the product port does NOT contain

Collected from the tables above so it can be read in one place, because a gap named only in a row
of a long table is a gap somebody will miss. **Reduced substantially by the final pre-staging pass**
— P03's release path, the P02 governed twin AND relationship lifecycles, the P02 adoption of M16,
all five missing registrations and P01 metering were launch-reachable gaps left by the first port,
and are now filled.

| Missing | Classification | Consequence | Consequence it does NOT have |
| --- | --- | --- | --- |
| P02 stakeholder role profiles | **STANDING_CONTROL_WITHOUT_PRODUCER** | `stakeholder_role_profiles` and `stakeholder_role_attributes` have no producer | the `STAKEHOLDER_PROFILE_MINIMIZATION` audit is ported and proved to bite, so the control precedes the producer rather than following it. No selected flow reads these tables — see §4.4 |
| P03 assurance (3 M11 audits) | **PORT_REQUIRED_BEFORE_CUSTOMER_LAUNCH** (not before staging) — **present in source since P08, not deployed, not scheduled, never run** | until the audits are deployed and run on a standing basis there is no post-outcome audit trail in operation for catalogue freshness, signal-pairing freshness or the buyer-intent invariant | every one of the three has its PRIMARY gate ported and tested; these audits are the defence-in-depth re-scan, not the gate — see §4.4 |
| P03 web surface | **INDEX_AND_DETAIL_READS_WIRED** — superseding `INDEX_READS_WIRED / DETAIL_READS_NOT_INCLUDED` (P09; see note below) | there is still no prospect-battlecard LIST read, and scriptless SSR does not serve P03 | the React console retains both released-prospect indexes and adds one output detail through owner getter `P03\Release::visibleProspectOutputDetail` (`/console/api/v1/p03/prospect-detail?output=`), with fit hypothesis, released signal, latest P08 assurance findings and current M10 reliance |
| P02 web surface | **INDEX_AND_DETAIL_READS_WIRED** — superseding `INDEX_READS_WIRED / DETAIL_READS_NOT_INCLUDED` (P09; see note below) | no relationship picture is invented; the old placeholder is removed, and scriptless SSR does not serve P02 | the React console retains both released-snapshot indexes and adds one entity snapshot detail through owner getter `P02\TwinRelease::visibleSnapshotDetail` (`/console/api/v1/p02/entity-detail?snapshot=`), with state/value status, uncertainty, lineage/freshness, current reliance and supersession. A **separate** P02 first-party `OWN_COMPANY` latest-`INTERNAL_DRAFT` read (`/console/api/v1/p02/own-company-drafts`) requires existing M02 `tenant.administer` + `entity_twin.read` and an active tier granting `entity_twin.read`; it is not a released snapshot or customer-visible output |
| P02 offering twins | **EXCLUDED_BY_AUTHORITY** | none | not a gap — excluded by the upstream P02 cp11 gate (V1-GAP-097). P03 catalogue is the MVP products/offers authority |
| P07-A channel binding | **EXCLUDED_BY_AUTHORITY / deliberate** | no `outreach_channel_bindings` row can exist | stronger than porting it inert — the port ships no producer, so there is nothing for the table's CHECK constraints to constrain |
| P02 stakeholder role OBSERVATIONS | **ALREADY_SUBSUMED** | none | ported as `app/Product/P04/StakeholderRoles.php`, writing `stakeholder_role_observations`, where the distribution path consumes it. This is a DIFFERENT object from P02's role profiles above — an earlier revision of this matrix conflated them |

> **Currentness note, P09.** Both rows are now `INDEX_AND_DETAIL_READS_WIRED`: one owner-safe
> detail read per product, added as scoped getters on the owning release services (no controller SQL),
> with object-level authorization tested in `tests/security/product_detail_reads.php`. No second
> list endpoint was added. `tests/foundation/deployment_boundary.php` pins the routes/getters.
>
> **Currentness note, 27 September 2026.** The two web-surface rows above said
> `WEB_SURFACE_NOT_INCLUDED` from the pre-staging gap pass. PR6 then wired the P02 and P03 **index**
> reads into the authenticated React console, and the classification was not updated. It is now
> `INDEX_READS_WIRED / DETAIL_READS_NOT_INCLUDED`; `tests/foundation/deployment_boundary.php` pins
> that split. Richer detail, lineage and reliance views are work package P09 in
> [`MONITOR_COMPLETION_PLAN.md`](MONITOR_COMPLETION_PLAN.md) — they are not duplicate index screens.
> The current state of the whole projection is [`MVP_RELEASE_STATE.md`](MVP_RELEASE_STATE.md).

### Final selected-graph gap classification

Every launch-reachable object still absent from the selected SME graph, classified against the
pinned canonical authority rather than against preference. Each answers the same seven questions:
(1) promoted upstream? (2) does a selected product depend on it? (3) does a runtime path READ it?
(4) does an assurance or release GATE depend on it? (5) does its absence create a false product
claim? (6) can staging exercise the selected graph without it? (7) can customer launch honestly
proceed without it?

#### P03 assurance — `src/p03/assurance.ts` → PORT_REQUIRED_BEFORE_CUSTOMER_LAUNCH

| | |
| --- | --- |
| 1. promoted upstream | **yes** — P03 checkpoint 6, three audit profiles on the certified M11 plane |
| 2. selected product depends on it | **no** — no P03 module imports it |
| 3. runtime path reads it | **no**. Grepped: no file under `src/p03/` outside `assurance.ts` itself reads `audit_runs`, `audit_findings` or calls the three audit entry points. The audits READ canonical state and RECORD their own append-only findings; canonical states outright that they "never grant authority (`audit_profiles.does_not_grant_authority` is CHECK-pinned to 1)" |
| 4. a gate depends on it | **no**, and canonical says so for the one that matters: of `BUYER_INTENT_INVARIANT` it writes "The PRIMARY gate is the M08 `p03_buyer_intent_trigger` rule; this audit catches any item that somehow bypassed it". Of `SIGNAL_PAIRING_FRESHNESS`: "the M08 verdict already HOLDs; this is the post-outcome assurance trail" |
| 5. absence creates a false claim | **no** — provided the primary gates are present, which they are (below) |
| 6. staging can proceed | **yes** — `ROAD_TEST_001` is a P01 flow end to end (source card → live-read activation → capture → second capture → change detection → read the findings). It does not touch P03 |
| 7. customer launch can proceed | **no**. These audits catch POST-outcome drift — a catalogue whose review window lapses after admission, a signal retracted after pairing. At staging an operator watching a handful of flows sees that; under continuous customer operation it accumulates unobserved. P01 and P02 both carry their assurance planes, and shipping P03 without one is an asymmetry a customer-facing deployment should not carry |

All three PRIMARY gates are present in this port, which is what makes (5) and (6) true rather than
hopeful:

| Canonical audit | What it re-scans | The primary gate, ported |
| --- | --- | --- |
| `CATALOGUE_FRESHNESS` | catalogue past its review window, not ACTIVE, or card not `approved` | `p03_admission_catalogue_active` (M08) **and** the release-boundary source-card re-read in `P03\Release` |
| `SIGNAL_PAIRING_FRESHNESS` | cited signal no longer RELEASED, or the pairing stale | `p03_admission_signal_released` (M08) |
| `BUYER_INTENT_INVARIANT` | a queue item whose trigger is not a buying-trigger class AND directly observed | `p03_buyer_intent_trigger` (M08, BLOCK) — the rule canonical itself names as primary |

**Not built in this pass, deliberately.** §13's test is evidence, and the evidence says B: an
assurance capability that can remain absent for first staging while runtime behaviour stays honest.
Porting it now would be a side quest against an instruction that forbids one.

**P08 update (2026-10).** The three audits are now ported in source with parity vectors and
offline tests (`docs/P03_ASSURANCE_PORT.md`). That moves the §3.7 row from "absent" to "present in
source, not deployed and not run"; it does not change answer (7): an audit nobody runs is not an
assurance plane. Customer
launch still needs the audits deployed and run on a standing basis — an admitted P07 schedule or a
written operator procedure — plus the open P03 rows of the P08 expansion register (owner-upload
licence mapping, UF-004, structured ICP size discrimination).

#### P03 console panels — `src/p03/console-panels.ts` → OPTIONAL_PRODUCT_POLISH for staging

| | |
| --- | --- |
| 1. promoted upstream | **yes** — M16 cp8 |
| 2. selected product depends on it | **no** |
| 3. runtime path reads it | it IS the read path, but it adds none: canonical says it rides "ONLY the existing surfaces" — the two-gate `listVisibleProspectOutputs` read model (ported and tested) and the M12 display guard (ported) |
| 4. a gate depends on it | **no**. Canonical: "No new permission …, no new authority plane, no customer-visible surface (V1-GAP-093 stands)" |
| 5. absence creates a false claim | **no**. The matrix now says `INDEX_AND_DETAIL_READS_WIRED`: P09 adds one owner-safe detail read while still making no claim that a prospect-battlecard list exists. A foundation test pins that classification against drift |
| 6. staging can proceed | **yes** — `ROAD_TEST_001` requires no browser inspection of P03 output. Operator access to the read model is through the tested index API, the React console's index panels, and the database |
| 7. customer launch can proceed | **not decided here.** It is a UX decision about the first customer experience, not a conformance one, and it belongs to whoever scopes that experience |

**Explicitly:** building this now would be a side quest. It becomes
`PORT_REQUIRED_BEFORE_STAGING` only if the staging acceptance is rewritten to require a human
operator to inspect P03 output through the browser. It currently is not.

#### P02 stakeholder role profiles → STANDING_CONTROL_WITHOUT_PRODUCER

| | |
| --- | --- |
| 1. promoted upstream | **yes** — `src/p02/stakeholder-roles.ts` |
| 2. selected product depends on it | **no**. The selected consumer is P04 distribution, and it reads `stakeholder_role_observations` — a DIFFERENT object, produced here by `P04\StakeholderRoles` |
| 3. runtime path reads it | **no**. Grepped canonical: `stakeholder_role_profiles` / `stakeholder_role_attributes` appear only in the schema, in P02's own producer, and in the P02 twin-assurance audit. **No P04 file reads them** |
| 4. a gate depends on it | **no** — the audit stands OVER them; nothing gates on their contents |
| 5. absence creates a false claim | **no** — nothing in this port claims a stakeholder role profile exists |
| 6. staging can proceed | **yes** |
| 7. customer launch can proceed | **yes** — for the selected graph. It becomes required only if a selected flow starts consuming role PROFILES rather than observations |

**Left inert on purpose.** `STAKEHOLDER_PROFILE_MINIMIZATION` passes over zero rows and is proved
to bite against a planted row, so the control is real and precedes its subject. Inventing a producer
to give an audit something to audit would be building product to satisfy a table.

### Known deliberate divergences from canonical (stricter, never looser)

| Divergence | Canonical | This port | Why it stands |
| --- | --- | --- | --- |
| Card path's capture-status gate | — | the card fold also refuses a source that is not `ACTIVE_FOR_CAPTURE` | Not a divergence in the fold but an extra gate the contribution fold structurally cannot apply: a twin's first-party basis was never fetched from anywhere and has no capture status |

**The two audience-ceiling divergences this table used to carry are gone, not restated.** They were:
the card path reaching `OPERATOR_ONLY` where canonical reaches `TENANT_INTERNAL`, and both paths
capping the recorded ceiling at `TENANT_INTERNAL` where canonical records the full ladder. Both were
defended here as "stricter, never looser".

That defence was wrong, and it is worth saying why rather than quietly deleting it. The audience
ceiling answers **what the SOURCES permit**. It is not a statement about what this deployment shows
anyone — `ReleaseOutput::ACTIVATABLE_AUDIENCES` answers that, separately, and still pins activation
to `OPERATOR_ONLY` and `TENANT_INTERNAL`. Folding the deployment answer into the source answer made
the recorded profile say something false: that a licence permitting customer output does not permit
it. Stricter is not a synonym for conformant when the two questions are different questions, and
there was no host reason for the difference — MariaDB does not constrain a fold. Both compile paths
now call one function, `foldAudienceCeiling`, implementing canonical exactly; `governed_vocabulary.php`
asserts their EQUALITY, and asserts the activation pin beside it so the distinction stays visible.

**None of these is a hidden capability.** Every one is an absence: no code path, no surface, no row.

**Where this matters for a reader:** the port is a vertical slice, and after this pass every included
module carries its governed lifecycle end to end — proposal, review, verdict, M10 release, a gated
surface, and an assurance re-scan. What remains above is **PORT_USEFUL_AND_COHERENT** work and one
**stated exclusion**; none of it is a lifecycle stopping half way, and none of it is a capability
present but ungoverned.

---

## 4. Substrate families — port scope by reachability---

## 4. Substrate families — port scope by reachability

Reachability was computed mechanically: every `FROM|INTO|UPDATE|JOIN` table token in each selected
module's non-test sources, intersected with the 225 `CREATE TABLE` names in
`packages/allfather-control-substrate/src/db/schema.ts`, then closed over FK/authority parents.
Detail per table in [`SCHEMA_REACHABILITY.md`](./SCHEMA_REACHABILITY.md).

| Family | Upstream path | Port | Bounded to |
| --- | --- | --- | --- |
| M01 runtime invocation | `src/m01/` | ✅ minimal | governed tool invocation + host-tool binding needed by capture. **No** host adapter/agent runtime. See §4.1 for the two excluded dispatch paths |
| M02 tenancy / scope / permissions | `src/m02/` | ✅ | tenants, workspaces, memberships, roles, permissions, data scopes, access evaluation |
| M03 capability identity | `src/m03/` | ✅ | valkyries, capability contracts, tool rights, forbidden action clauses, revocations |
| M04 missions / proposals | `src/m04/` | ✅ | proposal envelopes + status transitions + review paths used by selected flows |
| M05 evidence | `src/m05/` | ✅ | source references, evidence objects/bundles, raw artifacts, readiness, missingness |
| M06 governed tool registry | `src/m06/` | ✅ | governed tools, read-only dispatch, tier checks, idempotency |
| M07 independent review (HMWH Council) | `src/m07/council.ts` | ✅ | `hmwh_*` review sessions, participant eligibility, producer-self-review denial (`SELF_REVIEW_ONLY`), frozen results. `not_approval_or_execution_authority` storage-pinned |
| M08 arbiter | `src/m08/arbiter.ts` | ✅ selected policy sets | default, P01, P02×2, P03, P04, P05, P07 + source-config. The `M07_HMWH` rule category **is** included — it is the M07 Council rule, not the MCM-HMWH diagnostic |
| M09 approval / frozen action | `src/m09/` | ✅ **only for P07-A** | approval requests/decisions, frozen action artifacts, single-use short-TTL authorizations. **No live external adapter** |
| M10 decision ledger | `src/m10/` | ✅ | decision records, streams, supersession, current reliance |
| M11 audit / replay | `src/m11/` | ✅ | audit profiles/runs/findings, incidents, replay, blocking gaps |
| M12 authorized surfaces | `src/m12/` | ✅ | console projections, release readiness, redaction policy projections |
| M15 governed ingestion | `src/ingestion/` | ✅ bounded | policy, egress-guard, https transport, raw-capture, normalize, candidates, admission, assurance, replay, adapters |
| M15 live-read activation ladder | `src/ingestion/live-read-*.ts` | ⚠️ **state only** | the activation gate STATE is ported as inert/false; the upstream activation-ceremony machinery is not re-implemented |
| M16 governed release output | `src/release-output/` | ✅ | artifacts, source-use profiles, evidence links, projections, visibility activation, lineage, reliance-aware reads |
| `substrate-core` | `packages/allfather-substrate-core/src/` | ✅ **extract only** | canonical digest/serialization, chain digest, id generation, action classes, statuses, error model |

### 4.1 M01/M06 dispatch paths — what shipped and what did not

The canonical M01 module carries four dispatch entrypoints. Two are ported; two are excluded, and
the exclusions are structural rather than merely unimplemented.

| Upstream entrypoint | Port | Reason |
| --- | --- | --- |
| `invokeAuthorizedHostTool` (synthetic fixture) | ✅ `Invocation::invokeAuthorizedHostTool` | deterministic safe-read dispatch; no host contacted |
| `invokeAuthorizedLiveSourceCapture` | ✅ `Invocation::invokeAuthorizedLiveSourceCapture` | the egress-guarded read P01 capture needs |
| `invokeAuthorizedHarnessTool` | ❌ | the Harness native adapter path **is** the host adapter runtime, which this projection excludes |
| Class-E / `HARMLESS_DEMO_ACTION` demo dispatch | ❌ | requires an M09 single-use authorization and a frozen artifact. There is no M09 plane here, so rather than porting a branch that could never be satisfied, `EXECUTE_CONSEQUENTIAL_ACTION` is refused outright at `CLASS_E_NOT_AVAILABLE_IN_PROJECTION` |

Class E is separately unreachable by three independent planes, none of which depends on the others:
M02 lists the matching permissions as EXCLUDED; M03 refuses to issue a capability contract granting
the class; and the M06 V1 scope filter routes every class above `ANALYZE` to `REQUIRE_APPROVAL`,
which — with no M09 plane — is terminal rather than a step toward eventual dispatch.

**Adapter conformance is a characterized partial port.** Upstream replays the Harness Port
conformance law (`checkAdapterConformance`) over the stored descriptor, manifest and profile.
That law lives in the harness package. `M01\AdapterConformance` therefore verifies the half that
does not depend on the harness — that all three identity payloads are in canonical form, and that
the host-bound identity digest recomputes from exactly those payloads. It proves the adapter
identity has not **drifted**; it does not prove conformance to a harness descriptor contract, and
the class says so rather than implying the stronger property.

### 4.2 Product-plane characterized divergences

Four places where the PHP projection does not read line-for-line like upstream. Each is recorded
here rather than left for a reader to discover, and each states what the observable difference is.

**P04 `observeRoleClass` — an added guard, placed last.** Upstream scans only `roleClassLabel` for
an individual identifier. The port scans `subjectCompanyRefSafe` as well, because a person smuggled
into the company field is an individual observation wearing a company's label. The added check runs
AFTER all three upstream guards, so every input upstream refuses is refused here for the same
stated reason; only inputs upstream would have ACCEPTED can newly refuse. Strictly fail-closed, and
no upstream reason code changes.

**P05 `p05_source_still_released` — a dead branch not carried forward.** Upstream selects between
two reason codes on `sourceReliance === "INVALIDATED_AT_RELEASE"`. No reliance vocabulary contains
that value — `decision_records.current_reliance_status` and
`decision_supersession_records.new_current_reliance_status` both admit only `ACTIVE_AS_RECORDED`,
`HISTORICAL_ONLY`, `SUPERSEDED`, `INVALIDATED_FOR_CURRENT_RELIANCE` and `UNDER_REVIEW` — so the
branch cannot fire upstream either, and the rule always yields `SOURCE_NOT_RELEASED`. The port
emits that code unconditionally. Observable behavior is identical; the unreachable branch is not
reproduced.

**P05 `captureClaimBasis` — an added producer for a lineage the audit already required.** The
upstream citation gate accepts any real in-scope evidence, while the upstream
`MARKETING_EVIDENCE_CITATION` audit accepts a comparative citation only when the cited evidence has
lineage to that draft: its own admission basis, or a `marketing-claim-basis:<draftId>:<tag>`
reference under the `marketing_claim_basis` use scope. Upstream ships no product function that
mints the latter — its tests construct it by hand — so a marketer citing an ordinary P01 evidence
object passes the gate and fails the audit permanently. `MarketingAssets::captureClaimBasis` closes
that hole by minting exactly the shape the audit already recognizes, through the certified M05
path. It grants no authority: the basis is an INTERNAL_DEMO derived reference over a still-relied-
upon source, the claim must still be cited, and the audit must still accept it.

**`ux_arbiter_policy_sets_scope_one_active` does not defend NULL scopes — a host limit, not a
choice.** Canonical keys that index by `COALESCE(tenant_id, '*'), COALESCE(workspace_id, '*'),
policy_key`, so platform-wide policy sets — whose scope columns are NULL, and NULLs never collide in
a unique index — still collide with each other. MariaDB refuses a string literal inside an indexable
generated column (error 1901), and every spelling was tried on 10.11.19; a column-to-column `CASE`
with no literal is accepted, which identifies the literal as the cause.

| | |
| --- | --- |
| canonical behavior | at most one ACTIVE policy set per scope, INCLUDING the platform-wide (NULL, NULL) scope |
| projection behavior | at most one ACTIVE policy set per scope, for every scope this port can write |
| reason | MariaDB cannot index a generated column whose expression contains a string literal |
| host necessity | **yes** — verified against 10.11.19 across nine expression forms and both storage kinds |
| effect on claims | none. The sole writer, `M08\PolicySets::seed`, takes non-nullable scope parameters, so a NULL-scoped policy set cannot be created. The gap is unreachable rather than tolerated |
| test evidence | `tests/security/authority_uniqueness.php` — the index rejects a second ACTIVE row and a reactivation; and the unreachability is asserted three ways (reflection on the writer's parameters, a repository scan for other writers, a query for existing NULL-scoped rows) |

The alternative was to weaken the invariant to what MariaDB will index without complaint — for
instance dropping the scope from the key. That would have made the constraint pass on both servers
and defend less. A host that cannot express a rule is a reason to record the gap and prove it
unreachable, not a reason to restate the rule as something the host finds convenient.

### 4.3 Repaired divergences

Not every difference from upstream was a decision. This one was a defect, and it is recorded here
because the shape of the mistake is worth keeping.

**P01 capture compiled with a connector descriptor.** `P01\Capture` resolved an ACTIVE
`ingestion_connector_descriptors` row for the card's mapped connector class, handed it to
`SourcePolicy::compile`, and then refused on `fail_closed_reason_codes[0]` whenever the compile was
not `COMPILED_ALLOW`. Upstream's `captureMonitoredSource` passes `connector: null` and maps the
compiler's codes back into P01's own vocabulary through an ordered table, skipping ONLY the three
connector legs. The port's version therefore made every P01 capture depend on a platform-level M15
connector row that canonical P01 never required — a source whose card, reference, terms review and
scope were all in order would refuse with `CONNECTOR_NOT_ACTIVATED`, a reason belonging to a
dispatch path P01 does not use. It also renamed every other refusal to whichever code the compiler
happened to order first, rather than to the P01 code P01's callers and audits were built on.

Repaired by porting the upstream adapter verbatim in intent: `P01_REASON_BY_COMPILER_CODE` (ordered,
narrowing to `SOURCE_POLICY_PROHIBITED` where P01 has no historical code),
`P01_OUT_OF_SCOPE_COMPILER_CODES` (exactly the three connector legs) and a `p01ReasonFor` that fails
CLOSED on anything the map does not know. `SourcePolicy` remains the ONE compiler — no source-policy
law was copied into P01, and no connector class was promoted to make capture work.
`tests/conformance/p01_source_policy_adapter.php` pins every mapping, the closure of both tables
against the compiler's declared vocabulary, and the independence claim itself: the suite seeds no
connector descriptor at all, and both P01 integration chains now run without one.

### Substrate families deliberately NOT ported

| Family | Upstream path | Why excluded |
| --- | --- | --- |
| SEV Candidate Runtime / Phenotype Host | `src/sev/` | `SEV_ACTIVATION=NONE`; not activated upstream |
| GSMC | `src/gsmc/` | source-monitoring research machinery beyond the selected launch graph |
| Polymorphic DB control plane | `src/polymorphic-db/` | not required by the selected product graph |
| PromptOps / meta-validation | `src/promptops/`, `src/meta-validation/` | not required by the selected launch graph |
| MCM-HMWH **diagnostic** programme | `src/mcm-hmwh-mvp-*.ts` | diagnostic/eval programme, outside the SME product graph. Distinct from the M07 HMWH Council (`hmwh_*` tables), which **is** ported |
| MVP pilot / road-test ceremony machinery | `src/mvp-*.ts` | Ops-plane ceremony for the upstream road test, not product runtime |
| M13/M14 adaptive config | `adaptive_config_*`, `compiled_config_bundles` | not reachable from the selected graph |
| Economy / Climate / War kernels | `economy_projection_*` | no selected product path depends on them |
| Trait inference | `trait_*` | not reachable from the selected graph |
| M15 entity anchors | `entity_anchor_*`, `entity_relation_anchor_*` | not reachable from the selected modules' call graph |
| Node host adapters | `packages/allfather-harness-*` | implementation technology; replaced by the PHP host |

---

## 5. Implementation-technology replacements (category D)

| Upstream technology | Loopia replacement | Where |
| --- | --- | --- |
| TypeScript / Node | PHP 8.2+ (developed and tested on 8.4) | `app/` |
| `node:sqlite` `DatabaseSync` | PDO + MariaDB InnoDB | `app/Db/` |
| Vitest | PHP test runner (`tests/run.php`) with unit / integration / conformance / acceptance / security / rollback layers | `tests/` |
| `node:crypto` `createHash` | `hash('sha256', ...)` | `app/Core/Digest.php` |
| `node:crypto` `randomUUID` | `random_bytes(16)` → RFC 4122 v4 | `app/Core/Ids.php` |
| `node:https` + `lookup` pin | cURL + `CURLOPT_RESOLVE` pin | `app/Ingestion/CurlHttpsTransport.php` |
| `node:dns` resolver | `dns_get_record` (A + AAAA) | `app/Ingestion/DnsResolver.php` |
| Long-running worker | DB-backed FSM job machine, URL-triggered, leased, bounded | `app/Jobs/`, `worker/` |
| Node host filesystem | PHP private storage outside `public_html`, content-addressed | `app/Storage/EvidenceStore.php` |
| — (no upstream equivalent) | platform auth, sessions, CSRF, rate limiting | `app/Auth/`, `app/Http/` |
| — (no upstream equivalent) | BYOK credential storage, AES-256-GCM | `app/Provider/CredentialStore.php` |

---

## 6. Conformance fixture linkage

Every INCLUDED row above must have at least one fixture in
[`CONFORMANCE_MATRIX.md`](./CONFORMANCE_MATRIX.md). A row with no fixture is **not** conformed and
must be recorded as such in `CLAIM_CEILING.md` — code existing is not conformance.


---

## 7. Bounded Monitor integration addendum — 2026-09-27

This dated addendum narrows the earlier blanket PromptOps exclusion for one selective import and
explicitly authored inactive product components. It does not rewrite the historical gate acts or
claim that upstream fixtures are already deployed agents. [PROJECT_BOUNDARY §7](../PROJECT_BOUNDARY.md)
records the user-directed scope; the [source/capability baseline](reconciliation/canonical-parity-baseline-2026-09-27.md)
and [scoped extension manifest](reconciliation/monitor-scoped-extension-2026-09-27.json) distinguish
existing implementation, inactive machinery, fixtures, specifications and absence.

Currentness: canonical main `32c98a7191b7a4ae7a45d745917f39521f8f5112` contains no tracked
runtime differences in `packages/`, `tools/`, `scripts/` or `tests/` versus pin
`c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`. The pin remains unchanged. Later documentation
registration/currentness does not itself promote runtime. Subsequent owner-local fixes must name
their exact canonical and PHP evidence independently.

| Component | Dated port/composition decision | Claim ceiling |
|---|---|---|
| Canonical PromptOps records/hash/resolver/promotion report | Selective pure PHP import in `app/PromptOps/CanonicalContracts.php`; source hashes and limits in `PROMPTOPS_SOURCE_MANIFEST.json` | Runtime-inert, six unchanged canonical roles; no durable store or invocation authority |
| Canonical six-role store/templates | Golden conformance fixture only | In-memory/simulator evidence; not product prompts or approved production content |
| Monitor product prompts | New deployment authoring in private `app/PromptOps/bundles/`; separate role namespace | DRAFT / INACTIVE / OFFLINE_CONTRACT_TESTED; model evaluation not claimed |
| Provider context/request/response seams | Bounded implementation candidate under `app/Provider/` | Scoped, inspectable, inactive native execution; fixture transport is labelled |
| Manual Monitor composition | Bounded implementation candidate under `app/Workflow/`, reusing existing product/governance services | No replacement of M02–M12/M15/M16 owners; no automatic release or external action |
| Operator mission/review surface | Bounded authenticated authoring and inspection surface | Session-derived scope, existing CSRF, explicit human review; no credential or arbitrary tool controls |
| Full M13/M14 adaptive runtime, Universal Fetch/Port | Exclusion retained | Specification/future architecture; no promotion by this addendum |

Product schemas use canonical P01 signal classes and actual P04 organization role classes. Role
recommendations do not create memberships or distribution targets. Battlecard output supplies the
existing five content fields with citations; model output is still a candidate requiring the normal
review/verdict/release chain. Mechanical citation checks explicitly do not certify semantic truth.

The focused [PromptOps import note](PROMPTOPS_PORT.md) identifies APIs, exact conformance vectors,
offline cases and what remains untested. Native model evaluation/activation, real-source execution,
customer-output promotion and production remain separate evidence/owner transitions. No new code,
test, request inspection or fixture result changes the false claim-boundary flags in §2.


---

## 2026-10-26 LI-05A bounded source-projection overlay (PR #83 candidate)

**LI-05A = INCLUDE AS PURE OFFLINE CODE ONLY; NOT_ADMITTED / NOT_PERSISTED.** Three new detached PHP classes (`HistoryReviewBasis`, `HistoricalEvidenceSelector`, `HistoricalEvidenceEligibility`) can digest and deterministically time-select only **caller-supplied, already-authorized-or-synthetic metadata**. They cannot read any M05 EvidenceStore bytes, perform SQL/source capture, invent P01↔P02 mappings, implement `ModelContextAuthority`, dispatch models, approve outputs or start a schedule.

**No new canonical or product authority is adopted.** U03 historical reuse/clocks and U06 manual historical-analysis purpose remain owner-pending. Proposed upstream U01 (#379) and U04 (#378) are not merged or projected as live authority at this filing. The `270_history_review_bases.sql` slot and `HistoryReviewContext` are **NOT_IMPLEMENTED**; the existing 31 migrations, current `ContextEnvelope`, M02/P01/M05 owners, four `docs/decisions/*.json` and deployment-gating contracts remain unchanged.

The manifest's only qualifying status is `OFFLINE_NOT_ADMITTED` or `INSUFFICIENT_BASIS`; `selection_integrity=COMPLETE_WITHIN_INPUT` explicitly means metadata completeness *within supplied rows*, never rights assessment, completeness of the real evidence store, corroboration, historical synthesis or production permission. Source and tests: [LI05 offline contract](LI05_OFFLINE_HISTORY_BASIS_CONTRACT.md). This dated additive overlay does not edit the historical per-module port decisions above.

---

## 2026-10-26 — LI-06A inert M17 temporal candidate projection

**Port classification: INCLUDE OFFLINE/PURE CODE ONLY; NOT_ADMITTED / NOT_PERSISTED.**
New detached `app/Substrate/M17/{Vocabulary,InputSet,WindowEngine,ProfileResolver,DynamicMeasures}.php` classes accept closed, synthetic or independently permitted metadata and calculate deterministic reference digests, proposed DAY/WEEK/MONTH window bounds, conservative narrow-only profile folds and integer/rational candidate measures.

The source-projection implementation **does not constitute adoption of canonical U04**, which is still proposed in [upstream M17 PR #378](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/378). Canonical M17 is architecture-only; M17 G21 schema, seven table families, source owners, M13 calendars/modality, P02 entity mappings, M04 scheduling, M05 evidence status, M16 output and history release remain unchanged. No M17 registry, model dispatch, file/capture reader, SQL migration, HTTP route, provider call, deletion, tenant adaptation or customer delivery is wired.

Only `SOURCE_DIFF` (14/90-day ladder) and `COMPANY_SIGNAL` (30/180-day ladder) are supported as **offline metadata classes**; all other canonical data classes and the non-DAY/WEEK/MONTH windows remain inert. U03/U06 historical rights and model-purpose decisions also remain pending. An exact digest, time bucket, inferred volatility or conservative profile cannot grant authority to reuse old evidence. Current pinned canonical identity, all four owner decisions, legacy observations and receipts remain intact. See [M17 Projection Contract](M17_PROJECTION_CONTRACT.md) for the explicit clock, DST, timezone-rules caveat, numeric candidate units, conflict and claim-ceiling law.

---

## 2026-10-26 LI-07A referential rollup/lineage/replay candidate (PR #85)

**Scope classification: SOURCE-ONLY / PURE / OFFLINE / NOT_PERSISTED / NOT_ADMITTED.** Adds detached `app/Substrate/M17/{RollupEngine,Lineage,Replay}.php` on top of LI-06A's existing, digest-checked `InputSet`, `WindowEngine`, `ProfileResolver` and `DynamicMeasures`. The implementation derives typed metadata-only period rollups with fully itemized contrary and high-diagnosticity references; supports hash-bound, strictly additive same-period successor **proposals**; and provides read-only frozen-metadata replay with `MATCH_METADATA_ONLY`, `DIVERGED`, or `NOT_REPLAYABLE`. No rights verification or actual source content access is implied by replay.

**Canonical M17 U04 remains unadopted** (upstream decision PR #378 is still a proposal). Formal work package LI-07 includes seven M17 table families, row status transitions, `280_m17_temporal_memory.sql`, owner adapters and ops commands; those are all **NOT_IMPLEMENTED** by this narrower LI-07A precursor. U03 historical source rights and U06 model-analysis purpose remain pending. No changes to P01 capture/M05 evidence owners, ContextEnvelope, M13 calendars, migrations, retention/delete, schedule/cron, provider/model, external action, customer/production output or the four reviewed owner decision files.

The candidate hash is reproducibility, not authenticity or promotion. `Lineage::proposeLateSuccessor` does not define the permanent M17 database uniqueness key; no old rollup is modified. Full product use requires owner ratification, selective adoption, scoped native source/rights checks and dual-version SQL/migration/restore qualification. See [LI-07A offline contract](LI07A_OFFLINE_ROLLUP_LINEAGE_REPLAY.md).

---

## 2026-10-26 LI-07B candidate-only G21 persistence (PR #86 DRAFT, NOT ADOPTED)

**Classification:** PROPOSED TEST-ONLY CODE, NOT a new live M17 port or projection grant. The seven G21 candidate table families, strict append-only triggers and draft MemoryStore/P01 owner-read adapter are being reviewed in PR #86. **No `database/migrations/280_m17_temporal_memory.sql` is installed**; proposed SQL is deliberately held under `docs/longitudinal/proposals/280_m17_temporal_memory_CANDIDATE_NOT_ADOPTED.sql`, outside the Migrator's directory. Only disposable-MariaDB integration tests may execute the prospective SQL and they clean up their test-only tables afterward.

U04 upstream PR #378 is **OPEN/DRAFT/NOT_ADOPTED**: immutable output identity, fork/current-head rules, typed P01 scope and time/numeric serialization require owner ratification. The draft candidate uses `UNIQUE(compression_run_id,memory_class,output_ordinal)` and an input/profile/engine-bound derivation digest, preserving incomparable heads with an explicit `AMBIGUOUS_HEAD_HOLD`. This is *proposed* technical evidence, not independent authorization. U03 historical source rights and U06 model-analysis admission remain open. Memory source references are caller-supplied metadata, **not** owner-native M05 foreign keys or approved historical reuse. No runtime service, scheduler, provider, source fetch, model inference, customer read route, production GO, P02 truth mutation or deletion is installed. Owner decision files and the existing 222-table production schema inventory are unchanged.

Details: [LI-07B candidate persistence contract](LI07B_M17_PERSISTENCE_CANDIDATE.md). The additive source-only staging layer from PR #85 remains the last adopted deployment baseline. The October 25 JSON remains a historical proposal, not a ratification.
