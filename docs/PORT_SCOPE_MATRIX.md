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
| P02 Entity Twin Network | **GRANTED with explicit limitations** | 2026-06-10 | `INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS`, **entity + relationship twins only** | **INCLUDE (bounded)** |
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
description; Ops = implementation/deployment/validation.

### P01 — Competition Monitoring & Company Briefings

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Competitor registry + status transitions | Core | `src/p01/competitors.ts` | promoted | ✅ | `app/Product/P01/CompetitorRegistry.php` | Forward-only lifecycle; `ARCHIVED` terminal |
| Source license cards | Core | `src/p01/source-cards.ts` | promoted | ✅ | `app/Product/P01/SourceCards.php` | robots/terms/licence/expiry gates |
| Monitored sources | Core | `src/p01/monitored-sources.ts` | promoted | ✅ | `app/Product/P01/MonitoredSources.php` | approved-card + ACTIVE_FOR_CAPTURE fail-closed |
| Governed source capture | Core | `src/p01/capture-source.ts` | promoted | ✅ | `app/Product/P01/CaptureSource.php` | drives M15 egress guard |
| Change detection / diffs | Core | `src/p01/change-detection.ts` | promoted | ✅ | `app/Product/P01/ChangeDetection.php` | deterministic; never LLM |
| CompanySignal | Core | `src/p01/company-signals.ts` | promoted | ✅ | `app/Product/P01/CompanySignals.php` | FACT_OBSERVED vs HYPOTHESIS |
| Battlecard briefs | Core | `src/p01/battlecards.ts` | promoted | ✅ | `app/Product/P01/Battlecards.php` | evidence-cited |
| M10 release | Core | `src/p01/release.ts` | promoted | ✅ | `app/Product/P01/Release.php` | post-verdict source re-check |
| Entitlements / tiers | Core | `src/p01/entitlements.ts` | promoted | ✅ | `app/Product/P01/Entitlements.php` | recording only |
| Surfacing (M12) | Core | `src/p01/surfacing.ts` | promoted | ✅ | `app/Product/P01/Surfacing.php` | three-gate default-deny |
| Usage metering | Core | `src/p01/billing.ts` | promoted | ✅ | `app/Product/P01/Billing.php` | `no_external_charge_executed` pinned |
| M11 assurance profiles | Core | `src/p01/assurance.ts` | promoted | ✅ | `app/Product/P01/Assurance.php` | freshness / diff-integrity audits |
| Registration | Src | `src/p01/registration.ts` | promoted | ✅ | `app/Product/P01/Registration.php` | Valkyrie + contract + policy seed |
| `p01/fetch-guard.ts` | — | re-export shim | superseded | ❌ | — | **do not resurrect**; real guard is `ingestion/egress-guard.ts` |

### P02 — Entity / Company Twin (bounded to the promoted scope)

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Entity twins + versions | Core | `src/p02/entity-twins.ts` | promoted | ✅ | `app/Product/P02/EntityTwins.php` | own company = `OWN_COMPANY` first-party boundary |
| Twin state-change proposals | Core | `src/p02/twin-state-change.ts` | promoted | ✅ | `app/Product/P02/TwinStateChange.php` | M04 → M07 → M08 |
| Twin admission | Core | `src/p02/twin-admission.ts` | promoted | ✅ | `app/Product/P02/TwinAdmission.php` | `P02_TWIN_STATE_ADMISSION` |
| Entity relationships | Core | `src/p02/entity-relationships.ts` | promoted | ✅ | `app/Product/P02/EntityRelationships.php` | evidence/confidence/freshness required |
| Relationship admission | Core | `src/p02/relationship-admission.ts` | promoted | ✅ | `app/Product/P02/RelationshipAdmission.php` | `P02_RELATIONSHIP_STATE_ADMISSION` |
| Twin release + M10 binding floor | Core | `src/p02/twin-release.ts`, `m10-binding-floor.ts` | promoted | ✅ | `app/Product/P02/TwinRelease.php` | `decision_record_id NOT NULL` |
| Stakeholder roles (minimized) | Core | `src/p02/stakeholder-roles.ts` | promoted | ✅ | `app/Product/P02/StakeholderRoles.php` | no psych/emotion/loyalty/productivity |
| Twin assurance (12 M11 audits) | Core | `src/p02/twin-assurance.ts` | promoted | ✅ | `app/Product/P02/TwinAssurance.php` | |
| Registration | Src | `src/p02/registration.ts` | promoted | ✅ | `app/Product/P02/Registration.php` | |
| **Offering twins** | Core | `src/p02/offering-twins.ts` | **NOT PROMOTED** (V1-GAP-097) | ❌ | — | **EXCLUDED by current gate.** P03 catalogue is the MVP products/offers authority |

### P03 — Lead Qualification & Product Matching

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Customer catalogue authority | Core | `src/p03/customer-catalogue.ts` | promoted | ✅ | `app/Product/P03/CustomerCatalogue.php` | tenant-owned source card |
| Governed catalogue capture | Core | `src/p03/catalogue-capture.ts` | promoted | ✅ | `app/Product/P03/CatalogueCapture.php` | |
| Fit hypotheses | Core | `src/p03/fit-hypothesis.ts` | promoted | ✅ | `app/Product/P03/FitHypothesis.php` | deterministic scoring; **never buyer intent** |
| ICP profiles + versions | Core | `src/p03/icp.ts` | promoted (ICP-D5) | ✅ | `app/Product/P03/Icp.php` | excluded-segment BLOCK |
| Prospect admission | Core | `src/p03/prospect-admission.ts` | promoted | ✅ | `app/Product/P03/ProspectAdmission.php` | `P03_PROSPECT_ADMISSION` |
| Priority outreach queue | Core | `src/p03/prospect-queue.ts` | promoted | ✅ | `app/Product/P03/ProspectQueue.php` | buyer-intent gate |
| Prospect battlecard briefs | Core | `src/p03/release.ts` | promoted | ✅ | `app/Product/P03/Release.php` | D5 separate table |
| M11 audit profiles | Core | `src/p03/assurance.ts` | promoted | ✅ | `app/Product/P03/Assurance.php` | |
| Console panels | Core | `src/p03/console-panels.ts` | promoted | ✅ | `app/Product/P03/ConsolePanels.php` | M12 authorized surfaces |
| Registration | Src | `src/p03/registration.ts` | promoted | ✅ | `app/Product/P03/Registration.php` | |

### P04 — Intelligence Distribution (internal only)

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Role distribution composition | Core | `src/p04/distribution.ts` | promoted | ✅ | `app/Product/P04/Distribution.php` | role-class targeting; redaction config |
| Routing admission | Core | `src/p04/distribution-admission.ts` | promoted | ✅ | `app/Product/P04/DistributionAdmission.php` | non-overridable `p04_individual_identifier_forbidden` |
| Release + three-gate visibility + ack | Core | `src/p04/release.ts` | promoted | ✅ | `app/Product/P04/Release.php` | delivery-assurance-only ack |
| Stakeholder ROLE_CLASS observation | Core | `src/p04/stakeholder-roles.ts` | promoted | ✅ | `app/Product/P04/StakeholderRoles.php` | `subject_kind` storage-pinned `ROLE_CLASS` |
| M11 audits incl. no-covert-telemetry | Core | `src/p04/assurance.ts` | promoted | ✅ | `app/Product/P04/Assurance.php` | |
| Registration | Src | `src/p04/registration.ts` | promoted | ✅ | `app/Product/P04/Registration.php` | |

### P05 — Marketing Customization (internal drafts only)

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Marketing asset drafts + variants | Core | `src/p05/marketing-asset.ts` | promoted | ✅ | `app/Product/P05/MarketingAsset.php` | **no `PUBLISHED` status exists** |
| Admission | Core | `src/p05/marketing-admission.ts` | promoted | ✅ | `app/Product/P05/MarketingAdmission.php` | uncited-claim + forbidden-pattern BLOCK |
| Internal release + surfacing | Core | `src/p05/marketing-release.ts` | promoted | ✅ | `app/Product/P05/MarketingRelease.php` | |
| M11 assurance | Core | `src/p05/assurance.ts` | promoted | ✅ | `app/Product/P05/Assurance.php` | |
| Registration | Src | `src/p05/registration.ts` | promoted | ✅ | `app/Product/P05/Registration.php` | |

### P07-A — Synthetic outreach preparation (NO live send)

| Upstream object | Plane | Upstream path | Upstream status | Port | Loopia path | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Consent / lawful basis registry | Core | `src/p07/consent.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/Consent.php` | append-only |
| PII firewall + pseudonymization | Core | `src/p07/pii-firewall.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/PiiFirewall.php` | erasure = retention downgrade |
| Frozen outreach artifacts | Core | `src/p07/outreach-artifacts.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/OutreachArtifacts.php` | digest-bound |
| Outreach admission | Core | `src/p07/outreach-admission.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/OutreachAdmission.php` | non-overridable consent rule |
| Synthetic dispatch | Core | `src/p07/outreach-delivery.ts` | promoted (P07-A) | ✅ (inert) | `app/Product/P07A/SyntheticDispatch.php` | **synthetic only, no network** |
| Channel binding | Core | `src/p07/channel-binding.ts` | promoted (P07-A) | ✅ (inert) | `app/Product/P07A/ChannelBinding.php` | external-effect column CHECK-pinned 0 |
| M11 audits | Core | `src/p07/assurance.ts` | promoted (P07-A) | ✅ | `app/Product/P07A/Assurance.php` | |
| **P07-B live send** | — | `p07b_promotion_record.md` | **NOT GRANTED** | ❌ | — | **HARD EXCLUSION.** No SMTP/SMS/CRM/webhook adapter exists in this repo |

---

## 4. Substrate families — port scope by reachability

Reachability was computed mechanically: every `FROM|INTO|UPDATE|JOIN` table token in each selected
module's non-test sources, intersected with the 225 `CREATE TABLE` names in
`packages/allfather-control-substrate/src/db/schema.ts`, then closed over FK/authority parents.
Detail per table in [`SCHEMA_REACHABILITY.md`](./SCHEMA_REACHABILITY.md).

| Family | Upstream path | Port | Bounded to |
| --- | --- | --- | --- |
| M01 runtime invocation | `src/m01/` | ✅ minimal | governed tool invocation + host-tool binding needed by capture. **No** host adapter/agent runtime |
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
