# Upstream Authority Contract

## Classification

- **Plane:** Ops / Sources bridge
- **Object:** deployment-projection authority contract
- **Runtime status:** non-doctrinal
- **Canonical runtime authority:** `HMarcusWH/Allfatherbusinessintelligence`

This repository implements a bounded deployment projection. It does not promote, amend, or replace upstream doctrine, kernels, module law, governance law, or source authority.

## Scope widening rule

The projection's scope was widened from `LOOPIA_P01` to `LOOPIA_SME_MVP` by owner decision. That
act widened what is **ported**; it widened no **claim** and no **authority**.

A module may enter projection scope only when all four hold at the pinned upstream head:

1. it is **built** upstream (not spec-only, not roadmap);
2. it is **owner-gated** upstream, and the projection stays inside that gate's recorded ceiling;
3. its dependency closure is **complete** within this projection;
4. it introduces **no external-action authority**.

A gate granted "with explicit limitations" carries those limitations into the projection. Where
upstream excluded part of a module from its gate — P02 offering twins under V1-GAP-097 — the
exclusion is inherited. **Promotion by convenience is forbidden:** an object is not in scope
because it exists upstream, because it would be useful, or because adjacent objects are in scope.

## Precedence

When this repository conflicts with upstream canonical P01/runtime law, upstream wins.

The projection must fail closed until the conflict is reconciled and its upstream pin is deliberately updated.

## Update rule

Upstream changes are never merged wholesale into this repository.

For a projection update:

1. resolve the current upstream live head (never trust a registry snapshot);
2. identify changes relevant to the projected product modules and their exercised substrate law;
2b. re-check every gate recorded in `upstream.lock.json.upstream_gate_authority` — a gate that has
   narrowed narrows the projection, and a gate that has widened does **not** widen the projection
   without a separate owner act;
3. update this implementation deliberately;
4. run conformance and security tests;
5. record the new exact upstream commit in `upstream.lock.json`;
6. review and merge through this repository's normal PR process.

## Conformance principle

Technology equivalence is not required. Governed outcome equivalence is.

Node/SQLite upstream behavior may be projected into PHP/MariaDB only when load-bearing invariants remain equivalent, including source authority, evidence-before-interpretation, tenant isolation, uncertainty labeling, admissibility, release gating, auditability, and the A/B/C action ceiling.

Identifiers and timestamps need not match upstream byte-for-byte and are normalized in conformance
comparison. Reason codes, policy decisions, interpretation kind, release eligibility, visibility,
scope denial, current reliance and audit outcome are **never** normalized — they are the claim.

Where upstream still uses a product-specific released-reference or read model rather than routing
through M16, the projection ports **that** behavior. Canonical semantics are not "improved" by
re-routing them through a different spine; any such divergence must be recorded as a Loopia
implementation-equivalent and separately proven behaviorally.


---

## 2026-10-28 build-first decision register (descriptive only)

The [U01–U10 owner decision matrix](docs/longitudinal/BUILD_FIRST_DECISION_MATRIX.json) records proposed semantic options and remaining ratifications. In particular U04's owner-selected successor design is not blanket cross-owner adoption or runtime authorization. The [capability matrix](docs/longitudinal/build-first/CAPABILITY_MATRIX.json) cannot mint a grant; only the exact canonical owner and existing runtime admission mechanisms do so. `upstream.lock.json` is unchanged.
