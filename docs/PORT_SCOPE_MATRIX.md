# PORT_SCOPE_MATRIX

**Plane:** Ops / Sources bridge — port scope decision record.
**Canonical authority:** `HMarcusWH/Allfatherbusinessintelligence` @ `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`
**Projection:** `LOOPIA_SME_MVP` (historically `LOOPIA_P01`)
**As-of:** 2026-09-04

This document records, per upstream object, whether it is INCLUDED in the Loopia deployment
projection, on what current authority, and where its PHP implementation lives. It is the audit
entry point: another engineer should be able to check the port against upstream without reading
any conversation.

---

## 0. Head resolution (recorded before any change)

| Repository | Expected head (task prompt) | Live head resolved | Difference |
| --- | --- | --- | --- |
| `HMarcusWH/Allfatherbusinessintelligence` (main) | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | **none** |
| `HMarcusWH/allfather-loopia-p01` (main) | `cd56c206f5fd2626fc649dcf5f96ae9186234405` | `cd56c206f5fd2626fc649dcf5f96ae9186234405` | **none** |

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
| M11 audit profiles | Core | `src/p03/assurance.ts` | promoted | ❌ | — | **NOT PORTED.** |
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
| P02 stakeholder role profiles | PORT_USEFUL_AND_COHERENT, not yet done | `stakeholder_role_profiles` and `stakeholder_role_attributes` have no producer | the `STAKEHOLDER_PROFILE_MINIMIZATION` audit is ported and proved to bite, so the control precedes the producer rather than following it |
| P03 assurance (3 M11 audits) | PORT_USEFUL_AND_COHERENT, not yet done | no post-outcome audit trail for catalogue freshness, signal-pairing freshness or the buyer-intent invariant | the buyer-intent PRIMARY gate is the M08 rule and is ported and tested; these audits are the defence-in-depth re-scan, not the gate |
| P03 web surface | **RUNTIME_IMPLEMENTED / WEB_SURFACE_NOT_INCLUDED** | no dedicated console screen renders released prospect outputs | the two-gate read model IS ported and tested (`P03\Release::listVisibleProspectOutputs`). This is a UX scope decision for the first staging experience, NOT missing runtime and NOT a conformance gap |
| P02 web surface | **RUNTIME_IMPLEMENTED / WEB_SURFACE_NOT_INCLUDED** | no dedicated console screen renders released twin or relationship snapshots | both two-gate read models are ported and tested (`P02\TwinRelease::listVisibleSnapshots`, `P02\RelationshipRelease::listVisibleSnapshots`), and the M16 operator hold-view is where a held artifact is read. The first staging experience for P02 is operator-assisted |
| P02 offering twins | **EXCLUDED_BY_AUTHORITY** | none | not a gap — excluded by the upstream P02 cp11 gate (V1-GAP-097). P03 catalogue is the MVP products/offers authority |
| P07-A channel binding | **EXCLUDED_BY_AUTHORITY / deliberate** | no `outreach_channel_bindings` row can exist | stronger than porting it inert — the port ships no producer, so there is nothing for the table's CHECK constraints to constrain |
| P02 stakeholder role OBSERVATIONS | **ALREADY_SUBSUMED** | none | ported as `app/Product/P04/StakeholderRoles.php`, writing `stakeholder_role_observations`, where the distribution path consumes it. This is a DIFFERENT object from P02's role profiles above — an earlier revision of this matrix conflated them |

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

Three places where the PHP projection does not read line-for-line like upstream. Each is recorded
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
