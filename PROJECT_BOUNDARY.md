# Project Boundary

**Plane:** Ops / Sources bridge — deployment-projection scope contract.
**Projection:** `LOOPIA_SME_MVP`
**Historical projection:** `LOOPIA_P01` (see §1)
**Canonical runtime authority:** `HMarcusWH/Allfatherbusinessintelligence`

---

## 1. Lineage — why this repository is named `allfather-loopia-p01`

The repository name is **historical**. It records where the projection began, not what it now
deploys.

- The repository was bootstrapped as `LOOPIA_P01`: a narrow projection of P01 (Competition
  Monitoring & Company Briefings) alone.
- The boundary recorded at bootstrap explicitly excluded P02–P07. That text is preserved in §2
  below as the historical boundary, because deleting a superseded boundary would destroy the
  record of what authority actually changed.
- The owner subsequently widened the deployment candidate. The current deployment target is the
  **largest coherent, already-built, governed, no-external-effect SME product slice that is ready
  upstream** — not P01 alone, and not a wholesale Allfather port.

The name is not authority. `upstream.lock.json` carries both `historical_projection_id` and
`projection_id`, and this document carries both boundaries.

---

## 2. Historical boundary (superseded — retained for lineage)

> The following was the bootstrap boundary of `LOOPIA_P01`. It is **superseded** by §3–§5. It is
> retained verbatim so that the widening is auditable as a deliberate act rather than a drift.

> ### What this repository is
>
> A deployable Loopia-specific implementation of the minimum governed runtime surface required for
> Allfather BI P01.
>
> ### What this repository is not
>
> It is not: the One-Field host; GF-AoA runtime law; the canonical Allfather control substrate; a
> general Allfather rewrite; a port of every Allfather module; **P02–P07**; a general-purpose agent
> host; an external-action runtime.
>
> ### Allowed scope
>
> The deployment may implement the P01 slices required for:
> `source authority -> governed read-only acquisition -> evidence capture -> diff -> CompanySignal
> -> relevance review -> admissibility -> release record -> customer briefing projection`
>
> The projection may exercise bounded equivalents of the upstream M02/M03/M04/M05/M06/M07/M08/M10/
> M11/M12 laws only insofar as P01 requires them.

**What changed, exactly:** the product-module exclusion of P02–P07 is replaced by the selective,
gate-bound inclusion in §4. **Nothing else in the historical boundary is relaxed** — the hard
prohibitions in §5 are carried forward unchanged and extended.

---

## 3. What this repository is

A deployable Loopia-specific implementation (PHP 8.2+ / MariaDB) of the governed SME intelligence
product graph that is **already built and already owner-gated** upstream.

It is a **deployment projection**, not a runtime authority. Canonical runtime law remains upstream.

## What this repository is still not

It is not:

- the One-Field host;
- GF-AoA runtime law;
- the canonical Allfather control substrate;
- a general Allfather rewrite;
- a port of every Allfather module;
- an AGI host, a self-evolving runtime, or a SEV candidate runtime / phenotype host;
- a general-purpose agent host;
- an external-action runtime.

---

## 4. Allowed scope — the widened deployment candidate

Inclusion requires **all four** of: built upstream, owner-gated upstream at the pinned SHA,
dependency-complete in this projection, and no external-action authority. A roadmap item is not
launch authority; a Sources map is not runtime law.

### Product modules

| Module | Included | Bound |
| --- | --- | --- |
| **P01** Competition Monitoring | ✅ | full promoted vertical |
| **P02** Entity / Company Twin | ✅ bounded | **entity twins + relationship twins only** |
| **P03** Lead Qualification & Product Matching | ✅ | incl. the gated ICP extension |
| **P04** Intelligence Distribution | ✅ | **internal, in-platform visibility only** |
| **P05** Marketing Customization | ✅ | **internal drafts only; no publish** |
| **P07-A** Outreach safety foundation | ✅ | **synthetic / no-external-effect only** |
| **P07-B** live external send | ❌ | **prohibited — see §5** |
| P02 offering twins | ❌ | excluded by the upstream P02 cp11 gate (V1-GAP-097) |
| P02 EOM (Entity Operating Model) | ❌ | `EOM_RUNTIME_STATUS=ABSENT` upstream |

**P03 catalogue products remain the MVP products/offers authority.** Offering twins are excluded
upstream and are not silently promoted here.

### Substrate dependencies

Bounded equivalents of M01, M02, M03, M04, M05, M06, M07, M08, M10, M11, M12, **M15**, **M16**,
and **M09 only as required by P07-A** — each only insofar as the included product modules require
them, established by reachability rather than by copying whole families. See
[`docs/SCHEMA_REACHABILITY.md`](./docs/SCHEMA_REACHABILITY.md) and
[`docs/PORT_SCOPE_MATRIX.md`](./docs/PORT_SCOPE_MATRIX.md).

### The governed flow this deployment implements

```text
approved source authority
  -> M15 governed ingestion / source policy / safe acquisition
  -> M05 evidence + snapshots
  -> change detection / candidates
       -> P01 CompanySignals
       -> P02 entity facts/state where applicable
  -> P03 product-fit hypotheses / prospect qualification
       -> Watch List
       -> priority queue
  -> M07 independent review
  -> M08 Arbiter
  -> M10 canonical release decision            <- ONE release authority, for every module
       |
       ├─ ADOPTED M16 PATH  (source-use profile -> frozen artifact -> audience projection)
       │     ├─ P01  COMPANY_SIGNAL_BRIEFING + BATTLECARD_BRIEFING      (briefing.read)
       │     └─ P02  ENTITY_TWIN_SNAPSHOT_VIEW                          (entity_twin.read)
       │            ENTITY_RELATIONSHIP_SNAPSHOT_VIEW                   (entity_twin.read)
       │        Two adopting families, each governed by its OWN access rule keyed by
       │        artifact kind — so registration order cannot make one inherit the other's
       │        permission. Composition rides the post-M10 transaction: REFUSED rolls the
       │        unit back, HELD completes the release with an operator-only view.
       │
       ├─ P03 own projection -> released_prospect_outputs
       ├─ P04 own projection -> released_distribution_outputs
       └─ P05 own projection -> released_marketing_assets
  -> M12 authorized operator surfaces
  -> M11 audit / replay / rollback
```

**M16 is not the universal release spine, and this diagram used to say it was.** An earlier draft
showed M10 → M16 → all five product modules. That is not the adoption boundary at the pinned
canonical head: M16's `ReleaseArtifactKind` vocabulary contains exactly four members, two P01 and
two P02, and P03/P04/P05 each carry their own release projection referencing the M10 decision
record directly.

The distinction is load-bearing rather than cosmetic. **M10 owns release for every module** — one
authority, one place a release can be revoked from. What differs is what gets PROJECTED afterwards:
an M16 artifact is frozen content with a source-use profile and an audience projection, and a P03
released output is a reference plus a two-gate read model. Routing P03/P04/P05 through M16 to make
one release service out of four would re-decide an owner-gated adoption boundary and would change
what those artifacts are.

The map follows the runtime. The runtime was not changed to satisfy the map.

---

## 5. Hard prohibitions

The deployed runtime must not acquire authority to:

- write to CRM systems;
- send email or SMS;
- post or publish to third-party systems;
- execute trades or financial transactions;
- charge customers externally;
- mutate arbitrary external systems;
- run a live multi-turn external conversation agent;
- silently convert hypotheses into facts;
- convert a product-fit hypothesis into a claim of buyer intent;
- identify or score individual employees, or collect covert employee telemetry;
- release intelligence without the required evidence and admissibility chain;
- surface unreleased intelligence through a released-content read model.

**P07-B (live external send) is not implemented and must not be activated.** No SMTP delivery, no
SMS provider, no CRM push, no external webhook action, no live conversation agent exists in this
repository. P07-A dispatch is synthetic and reaches no network.

**Action-class ceiling: A (OBSERVE) / B (ANALYZE) / C (RELEASE_INTELLIGENCE).** D and E are
unreachable from this codebase. `external_side_effects_allowed` stays `0` and
`no_external_charge_executed` stays `1`, CHECK-pinned in storage.

The one exception in shape, not in effect: P07-A rides the M09 approval plane, which exists
upstream to *prepare* a Class D action. Here it prepares only, and the dispatch leaf is synthetic.
If any P07-A function would require real external-action authority, it stays inert and the gap is
recorded in [`docs/CLAIM_CEILING.md`](./docs/CLAIM_CEILING.md).

**Unknown authority is refusal, not permission.**

---

## 6. What this repository does not decide

Widening the *port* scope did not widen any *claim*. These remain owner acts, recorded upstream
and unaffected by any merge here:

```text
road_test_001_executed        = false
production_launch_authorized  = false
external_action_authorized    = false
live_read_activated           = false
owner_go_granted              = false
customer_visible_output       = false
```

Merging code into this repository establishes no deployment, no activation, and no customer-visible
output. See [`docs/CLAIM_CEILING.md`](./docs/CLAIM_CEILING.md).
