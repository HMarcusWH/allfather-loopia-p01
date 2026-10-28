# Project Boundary

**Plane:** Ops / Sources bridge — deployment-projection scope contract.
**Projection:** `LOOPIA_SME_MVP`
**Historical projection:** `LOOPIA_P01` (see §1)
**Canonical runtime authority:** `HMarcusWH/Allfatherbusinessintelligence`

**Dated bounded extension — 2026-09-27:** §7 records the user-directed, inactive Monitor
integration work. The built-and-owner-gated descriptions in §§3–4 describe the existing product
base; they do not falsely promote the newly authored prompt/provider/workflow seams. The
canonical owner, pinned baseline and §5 hard prohibitions remain unchanged.

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
authority, one place a release can be revoked from.

That sentence was aspirational until 2026-09-06, and it is worth saying so plainly rather than
letting a corrected document read as though it had always been right. Before that date the PHP
release path validated nothing — it took a basis the CALLER assembled and appended it — and M04
performed the `SUBMITTED -> RELEASED` mutation separately, in a different transaction. Two modules
owned release between them, and the record the console treats as PROOF of release proved only that
some caller had asserted something. The guard on the record type was a capability object with a
public static factory, so any module that could name the class could mint one.

What now enforces the sentence:

- `DecisionLedger::appendInternalReleaseDecisionRecord` takes a VERDICT IDENTITY and nothing else.
  It loads the verdict, the proposal and the workspace itself; validates the verdict value AND its
  routed stage, the M08 invalidations, the outstanding conditions, the tenant/workspace scope and
  the submission digest; and builds the basis references from the records it read.
- The record, its references, the head advance, the guarded `SUBMITTED -> RELEASED` flip and the
  M10 transition row commit in ONE transaction. A flip matching any number of rows but one rolls
  the whole unit back.
- There is no capability token to forge, because there is no token: the public `append()` refuses
  the release record type outright and takes no authorization parameter, and the only code that can
  pass that type is a private method of the ledger.
- M04 has no method that reaches RELEASED, and no product module has a `Proposals` handle in its
  release path.

### A governed command is a unit, and a refusal undoes it

Upstream's composite commands are written the same way everywhere:
`if (step.outcome !== OK) { db.exec("ROLLBACK"); return REJECTED; }`. Across the whole selected
graph exactly ONE upstream file commits and then refuses — `p07/outreach-delivery.ts`, twice, and it
says why: the consume's EXPIRED and payload-mutation REVOKE transitions are "legitimate persisted
effects (a real status change, not a half-finished dispatch)".

Until 2026-09-06 this port did the opposite by default. `Database::transaction()` committed whatever
its callback returned, so a refusal RETURNED after four of five steps had written left those four
writes durable while the caller was told the command had refused: an evidence object and a bundle
draft with no proposal; a released briefing with both status advances and no M16 artifact. Nothing
threw, so nothing said so. The P07-A synthetic dispatch had the same fault in a sharper form — four
separate transactions, so a failure after the consume left a spent authorization with no outcome
recorded against it, and no retry possible because the authorization was single-use.

The rule now: **a refused or failed `Outcome` returned from inside a transaction rolls that
transaction back.** A site that genuinely needs the writes kept passes `commitOnRefusal: true`, and
there are exactly five, each matching something upstream persists:

| Site | Why upstream persists it |
| --- | --- |
| `P07A\SyntheticDelivery` | upstream's own COMMIT-then-refuse, both cases |
| `P07A\OutreachArtifacts` (consume) | upstream runs no transaction there at all, so the REVOKEs stand |
| `M07\Council` | the `hmwh_eligibility_checks` row IS the record that the denial happened; deleting it would remove the evidence of a refusal from an append-only ledger |
| `P02\TwinAdmission` | upstream COMMITs before returning BLOCK or QUARANTINE — a terminated state |
| `P02\RelationshipAdmission` | change is a real status change, and one rolled back to PENDING_VERDICT invites the same content again, reworded |

`tests/security/p07a_dispatch_atomicity.php` proves the rule and scans the repository to pin that
set closed, so a sixth exception cannot arrive looking like a fix.
- `tests/security/m10_release_ownership.php` proves each of those by reversion, including a
  repository scan asserting exactly one runtime file mutates a proposal to RELEASED;
  `tests/conformance/m10_release_conformance.php` walks CONF-M10-001…010 against the exact
  canonical reason codes.

**A ledger scope is (tenant, workspace, ENVIRONMENT).** Not (tenant, workspace) — that was a
separate defect, fixed 2026-09-06, and it is worth stating rather than quietly correcting. The
active-stream lookup omitted `environment_class`, so a caller asking for the DEMO ledger was handed
the TEST one and appended DEMO history to it. Nothing reported the collapse, because a hash chain
has no opinion about which environment its records came from: the chain still verified.

A TEST ledger and a DEMO ledger for one tenant and workspace are two distinct governed histories,
and three things now keep them apart:

- every ACTIVE lookup, uniqueness check and recovery path is keyed by tenant, workspace AND
  environment, and `ensureActiveStream` has no default environment to fall back on;
- the default stream key is canonical — `ledger:test`, `ledger:demo`, `ledger:local_dev` — so the
  real unique index on `(tenant, workspace, stream_key)` permits one per environment rather than
  one per scope for all time;
- the genesis anchor digests the environment, so the two chains differ at their roots and neither
  can be reconstructed as the other.

The canonical partial unique index behind this is **database-enforced** in MariaDB rather than
application-enforced — see `docs/SCHEMA_REACHABILITY.md` for the translation and why this one
partial index does not follow the standing rule.

What differs between modules is what gets PROJECTED afterwards:
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


---

## 7. User-directed bounded Monitor integration — 2026-09-27

The user requested completion of the agent-driven SME Monitor workflow after a current canonical
and deployment audit. This is authorization to implement and verify the bounded integration;
it is not an assertion that the new candidate is already promoted, deployed, model-evaluated or
authorized for native model/source execution. The pre-change capability matrix and exact source
baseline are retained in [docs/reconciliation/canonical-parity-baseline-2026-09-27.md](docs/reconciliation/canonical-parity-baseline-2026-09-27.md)
and its JSON companion. The additive [scope manifest](docs/reconciliation/monitor-scoped-extension-2026-09-27.json)
records the distinction without changing `upstream.lock.json`.

Canonical main was resolved at `32c98a7191b7a4ae7a45d745917f39521f8f5112`. The tracked
`packages/`, `tools/`, `scripts/` and `tests/` trees are byte-identical to the existing projection
pin `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`. There is no newer runtime implementation in
those trees to import by a blanket pin advance. Shared defects require owner-local canonical
repairs and explicit PHP parity; omitted composition and product authoring remain separately
identified work.

The extension permits these bounded implementation candidates:

- A selective PHP port of the pure canonical PromptOps hash, binding-resolution and promotion-report
  contracts. Exact source hashes and characterized serialization limits are recorded in
  [docs/PROMPTOPS_SOURCE_MANIFEST.json](docs/PROMPTOPS_SOURCE_MANIFEST.json). The canonical six roles
  remain unchanged, and their generic fixture templates are not product agents.
- Deployment-authored `P01_SIGNAL_CLASSIFIER`, `BATTLECARD_GENERATOR` and
  `MONITOR_REVIEW_CRITIC` and `MONITOR_ROUTING_CRITIC` prompts, strict schemas, private immutable versions and offline contract
  cases. These are DRAFT/INACTIVE authored candidates; fixture success is not model evaluation or
  approval. The critic recommends human review and cannot issue a release decision.
- Scoped model-context/request/response seams and a manual Monitor composition joining the existing
  capture/evidence/proposal/review/admission/release owners. Native dispatch remains inactive;
  composing a request or substituting a fixture transport does not grant model authority.
- An authenticated operator journey for configured missions, request/prompt/candidate inspection,
  explicit human review, status and stand-down. Browser-supplied scope, credential editing,
  arbitrary provider/tool selection and automatic approval are outside this slice.

This is not a full M13 tenant-adaptation port, M14 optimization/learning engine, Universal Fetch
crawler, Universal Port connector platform, general-purpose agent host or replacement for canonical
runtime owners. Models contribute candidate intelligence. Existing M02/M03 scope, M06/M01 dispatch,
M05 evidence, M04 proposals, M07 independent review, M08 verdicts, M10 release, M11 replay and
M12/M16 surface/source-use rules remain the owning paths. No parallel admission, release or egress
authority is introduced.

`live_read_activated`, native model execution, external-action authority, customer-visible output,
adaptive prompt mutation and production launch remain false/unapproved for this candidate.
Real model evaluation and separately evidenced activation are later steps. The existing PR16
staging qualification applies to PR16, not automatically to this changed tree. Any later staging
deployment requires exact-release checks and preserves the recorded E.1 failure/E.3 unverified
shared-host deviation; production does not inherit that exception.


---

## 2026-10-28 BF-00 build-first registry (additive; no new rights)

[Build-first source checkpoint](docs/longitudinal/BUILD_FIRST_STATE_2026_10_27.md) assigns the **29 engineering build packages**, U01–U10 canonical decision requests, and 12 **candidate-only** schema families without expanding the bounded SME product projection. P00–P12 in earlier completion material denote **engineering work packages**, not product modules. The original product gates, no-external-action ceiling, source rights, M10/M16 release boundaries and frozen global canonical pin above remain controlling.
