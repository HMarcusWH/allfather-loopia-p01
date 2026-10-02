# P03 assurance port (completion plan P08)

**Status:** implemented and offline-tested. **Not** deployed, activated or scheduled. The three audits
are post-outcome scans; they are not release authority, not a substitute for the M08 admission gate,
and not evidence that P03 is ready for customers.

## 1. What was ported

| Item | Value |
| --- | --- |
| Canonical source | `packages/allfather-control-substrate/src/p03/assurance.ts` |
| Canonical commit | `1c40306c22e15b2ad3049e8d32abff47450c8921` (canonical main inspected by the completion plan) |
| Canonical source SHA-256 | `0992a457afed4a921b5d3ce1cb6aa87d131dca04405a794b809090e3566bf3a1` |
| PHP port | [`app/Product/P03/Assurance.php`](../app/Product/P03/Assurance.php) |
| Parity vectors | [`tests/fixtures/p03_assurance_vectors.json`](../tests/fixtures/p03_assurance_vectors.json), 46 cases |
| Vector generator | [`tests/fixtures/p03-assurance-golden-generator.mts`](../tests/fixtures/p03-assurance-golden-generator.mts) |
| Conformance suite | [`tests/conformance/p03_assurance.php`](../tests/conformance/p03_assurance.php) |
| Projection suite | [`tests/integration/p03_assurance.php`](../tests/integration/p03_assurance.php) |
| Operator CLI | [`ops/assurance/p03.php`](../ops/assurance/p03.php) |

The three profiles, unchanged from canonical:

| Category | Asks | Failing finding | Incident |
| --- | --- | --- | --- |
| `CATALOGUE_FRESHNESS` | catalogue ACTIVE; card review `approved` and unexpired; latest `UPLOADED_SOURCE_CAPTURE` inside the caller's or card's error window; latest terms review inside the recheck window | `EVIDENCE_OR_AUTHORITY_GAP`, S3 | trigger M05, `PROVENANCE_OR_IP` |
| `SIGNAL_PAIRING_FRESHNESS` | the fit hypothesis's cited signal is still `RELEASED` and no older than the supplied window | `EVIDENCE_OR_AUTHORITY_GAP`, S3 | trigger M05, `PROVENANCE_OR_IP` |
| `BUYER_INTENT_INVARIANT` | every priority-outreach queue item in scope, whatever its queue status, cites a buying-trigger class observed as `FACT_OBSERVED` | `OVERCLAIM_OR_UI_MISREPRESENTATION`, S2 | trigger M08, `DISCLOSURE_OR_OVERCLAIM` |

They ride the existing M11 plane: one `audit_runs` row, one `audit_check_records` row and one
`audit_findings` row per audit, and a failing finding opens an incident through
`M11\Assurance::openIncident`. The PHP port writes all of them in one transaction. No new assurance
store or table was introduced; the three categories were already in the `audit_profiles` CHECK
vocabulary. `M11\Assurance` needed no change.

## 2. Semantics kept, and the one narrowing

Valid inputs follow canonical exactly, including reason-code order, the strict `>` window
comparisons, an unreadable stored instant being ignored (except the terms recheck, which canonical
fails closed), the incident's related refs, and **canonical's tenant-only catalogue scope**.

One narrowing at the input boundary: canonical computes `Date.parse(asOf)`, and a malformed `asOf`
becomes `NaN`. Every comparison against `NaN` is false, so canonical then passes every check — a
fail-open. The port refuses a non-canonical `as_of` (`AS_OF_NOT_CANONICAL`), a negative or
non-integer window (`FRESHNESS_WINDOW_INVALID`) and an unknown requester type or malformed requester
id **before** reading or writing anything. Recorded as UF-003 in
[`UPSTREAM_FINDINGS.md`](UPSTREAM_FINDINGS.md).

## 3. How parity is held

The generator runs **the frozen canonical code** against declared fixtures — catalogue/card review
states, capture and terms-review instants, signal status and age, queue-item trigger classes, profile
category/status/scope — and records what canonical wrote. The conformance suite rebuilds the same
declared fixtures in MariaDB, runs the PHP port and compares: refusal codes, reason codes in order,
finding type/severity, every run/check/finding field the audit sets, the incident request and case,
the governed row-count effects, and that the audited rows did not change. Ids are normalized to the
generator's labels.

To regenerate (a canonical checkout at the pinned commit, Node 22.19+):

```bash
pnpm install --frozen-lockfile --ignore-scripts
pnpm --filter @allfather/substrate-core build
CANONICAL_COMMIT=$(git rev-parse HEAD) node --import tsx \
  <loopia>/tests/fixtures/p03-assurance-golden-generator.mts <loopia>/tests/fixtures/p03_assurance_vectors.json
```

Fixture rows are written directly rather than through canonical's catalogue projection, because
that projection stamps its terms-review row with the wall clock and a parity vector must not depend
on when it was generated.

## 4. Projection surfaces

- **Profiles.** `seedProfiles(tenant, workspace)` creates `p03.catalogue_freshness`,
  `p03.signal_pairing_freshness` and `p03.buyer_intent_invariant` once per scope and never
  reactivates a profile an operator suspended. `P03\Registration` seeds them in the same unit when it
  is given the assurance owner (staging provisioning does); without it, registration is unchanged.
  An audit profile grants nothing — `does_not_grant_authority` is CHECK-pinned to 1.
- **Sweep.** `sweep()` audits each tenant-level or this-workspace catalogue, each live (not
  `REJECTED`/`SUPERSEDED`) fit hypothesis and runs the buyer-intent scan once. It refuses a scope with
  more targets than its bound (default 200, max 1000) **before** auditing anything, rather than
  auditing a prefix and reporting it clean. It reports a missing profile; it never seeds one. A
  sibling workspace's catalogue is not swept even though the single-target audit would accept it.
- **Operator entrypoints.** `operatorSeedProfiles` needs `tenant.administer`; `operatorStatus` needs
  `incident.read` and is read-only; `operatorSweep` needs `incident.manage`, because a failing audit
  opens an incident. Runs are recorded as `OPERATOR` with that operator's id.
- **CLI.** `php ops/assurance/p03.php <seed-profiles|status|sweep> --env=... --tenant-id=...
  --workspace-id=... --operator-id=... [--signal-window-days=N] [--catalogue-window-days=N]
  [--as-of=ISO] [--max-targets=N]`. Nothing here reaches the network.

Bounded scheduled audit jobs wait for an admitted P07 scheduling policy; none is registered.

### Translation repair found by the vectors

The canonical card windows (`warn_after_ms`, `error_after_ms`, `recheck_terms_after_ms`) are SQLite
INTEGER (64-bit). Migration 020 translated them to MariaDB `INT` (32-bit): any window over about
24.86 days could not be stored, so a card with an ordinary 30-day terms recheck could not be
projected at all. Migration `245_source_card_window_width.sql` widens the three columns to `BIGINT`
without editing 020. The same translation class also affects the inert M15 column
`ingestion_fetch_plans.cadence_min_interval_ms`; it is recorded, not widened, because nothing reads it.

## 5. P02/P03/P05 expansion register

[`tests/acceptance/product_expansion_regressions.php`](../tests/acceptance/product_expansion_regressions.php)
re-runs the scenarios the canonical parity baseline named and pins **current** behaviour. A KNOWN
DEFECT assertion pins the defect so that a repair has to flip it deliberately. None of them is
repaired here: the plan requires verified shared defects to be repaired upstream first, then ported.

**Register status** is OPEN for every row that is not HOLDS. A green suite means this register still
describes the code; it does **not** mean the broader SME blockers are closed. A row leaves OPEN only
when the repair has landed upstream first and been ported, and the pinned assertion is flipped on
purpose in the same change. `tests/acceptance/product_expansion_regressions.php` fails if any OPEN
row below disappears or stops reading OPEN.

| Scenario | Classification | Register status | Current behaviour |
| --- | --- | --- | --- |
| P03 three canonical post-outcome audits | HOLDS | closed (P08) | ported with parity vectors (this package) |
| P05 English comparative / forbidden markers | HOLDS | closed | detected |
| P05 Swedish comparative markers (`snabbare än`, `jämfört med`, `bättre än`) | KNOWN_DEFECT | **OPEN** | not detected, so an uncited Swedish comparison passes the citation rule |
| P05 Swedish urgency and trait targeting | KNOWN_DEFECT | **OPEN** | not detected |
| P05 title scan | KNOWN_DEFECT | **OPEN** | the M08 P05 context carries the body only; a comparative or forbidden **title** passes both content rules |
| P05 citation entailment | NO_SEMANTIC_EVALUATION | **OPEN** | a citation's presence and comparative wording are checked; whether the evidence supports the claim is not. A syntactically present citation does not prove entailment |
| P05 negated comparison | NO_SEMANTIC_EVALUATION | **OPEN** | over-triggers the citation requirement (the safe direction) |
| P05 unmarked comparison | NO_SEMANTIC_EVALUATION | **OPEN** | invisible to deterministic markers |
| P03 owner-upload licence mapping | KNOWN_DEFECT | **OPEN** | a `TENANT_OWNED` card carrying a public web licence still backs a catalogue; canonical maps an upload to `LICENSED_INTERNAL_ONLY` / `customer_owned_tenant_scoped` |
| P03 catalogue audit from a sibling workspace | canonical behaviour, reported upstream (UF-004) | **OPEN** | a workspace-owned catalogue is auditable from a sibling workspace's profile; pinned by vector `catalogue-workspace-owned-from-sibling-workspace` |
| P03 structured ICP size discrimination | not re-run here | **OPEN** | remains as recorded in the parity baseline |
| P02 foreign-scope working-state writes | not re-run here | **OPEN** | remains as recorded in the parity baseline; P02 write expansion stays gated on it |

**Consequence.** P01-only manual piloting does not wait on this register. Exposing P03 to customers
needs the audits (now present) **and** the owner-upload mapping repair; enabling generated P05 drafts
needs the Swedish/title repairs and a decision about semantic citation support; widening P02 writes
needs the scope repair. Each repair is an upstream change first.
