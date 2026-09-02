# Upstream Authority Contract

## Classification

- **Plane:** Ops / Sources bridge
- **Object:** deployment-projection authority contract
- **Runtime status:** non-doctrinal
- **Canonical runtime authority:** `HMarcusWH/Allfatherbusinessintelligence`

This repository implements a bounded deployment projection. It does not promote, amend, or replace upstream doctrine, kernels, module law, governance law, or source authority.

## Precedence

When this repository conflicts with upstream canonical P01/runtime law, upstream wins.

The projection must fail closed until the conflict is reconciled and its upstream pin is deliberately updated.

## Update rule

Upstream changes are never merged wholesale into this repository.

For a projection update:

1. resolve the current upstream live head;
2. identify changes relevant to P01 and its exercised substrate law;
3. update this implementation deliberately;
4. run conformance and security tests;
5. record the new exact upstream commit in `upstream.lock.json`;
6. review and merge through this repository's normal PR process.

## Conformance principle

Technology equivalence is not required. Governed outcome equivalence is.

Node/SQLite upstream behavior may be projected into PHP/MariaDB only when load-bearing invariants remain equivalent, including source authority, evidence-before-interpretation, tenant isolation, uncertainty labeling, admissibility, release gating, auditability, and the A/B/C action ceiling.
