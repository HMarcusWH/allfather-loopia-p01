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
| Parity vectors | [`tests/fixtures/p03_assurance_vectors.json`](../tests/fixtures/p03_assurance_vectors.json), 62 audit cases and a 47-instant `Date.parse` battery |
| Vector generator | [`tests/fixtures/p03-assurance-golden-generator.mts`](../tests/fixtures/p03-assurance-golden-generator.mts) |
| Conformance suite | [`tests/conformance/p03_assurance.php`](../tests/conformance/p03_assurance.php) |
| Projection suites | [`tests/integration/p03_assurance.php`](../tests/integration/p03_assurance.php), [`p03_assurance_sweep.php`](../tests/integration/p03_assurance_sweep.php), [`p03_assurance_scale.php`](../tests/integration/p03_assurance_scale.php) |
| Upgrade-order proof | [`tests/integration/p08_migration_order.php`](../tests/integration/p08_migration_order.php) (migrations 245 and 246 on a pre-P08 database) |
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

## 2. Semantics kept, narrowed, and divergent

Valid inputs follow canonical exactly, including reason-code order, the strict `>` window
comparisons, an unreadable stored instant being ignored (except the terms recheck, which canonical
fails closed), the incident's related refs, and **canonical's tenant-only catalogue scope** (UF-004).

**Narrowed at the input boundary (UF-003).** Canonical computes `Date.parse(asOf)`; a malformed
`asOf` becomes `NaN`, and every time-based comparison against it is false — review expiry, capture
age, terms-review age and signal age all stop firing, while status and missing-row reasons still
fire. A target stale only by time then records `CONTROL_PASS`. The port accepts `as_of` only in the
canonical stored form `YYYY-MM-DDTHH:MM:SS.sssZ` (`AS_OF_NOT_CANONICAL`; this also refuses ES forms
canonical would read, such as a date-only `2026-07-01`, and applies to the buyer-intent audit, which
canonical lets carry an `asOf` it never reads), and refuses a negative or non-integer window
(`FRESHNESS_WINDOW_INVALID`) and an unknown requester type or malformed requester id **before**
reading or writing anything. Recorded in [`UPSTREAM_FINDINGS.md`](UPSTREAM_FINDINGS.md).

**Stored instants (UF-005).** Instants read from the database (review expiry, capture, terms review,
signal creation) are read the way canonical `Date.parse` reads the ES date-time-string format:
date-only forms as UTC midnight, fractions truncated to milliseconds, `T24:00` as the next midnight,
a day past the month's end rolled over, and `:60`, month 13 or `±24:00` unreadable. A 47-instant
battery generated from canonical pins this. Two parts of canonical's behaviour are engine- or
host-dependent and are **not** reproduced: an offset-less date-time is host-local in canonical and
read as UTC here (canonical's answer on a UTC host, which is how the vectors are generated), and a
legacy non-ISO string that V8 may parse (`"June 15, 2026"`) is unreadable here. Canonical's writers
store `toISOString()` values, so this matters only for imported or hand-written rows.

**Not persisted (UF-005).** Canonical records neither the `asOf` an audit judged nor a
`freshnessWindowMs` that overrode the card's window. The service methods keep both inputs for
parity; the operator surface accepts neither (§4), so operator-run evidence is always "now, under
the card's own window".

**Incident timeline (pre-existing M11 projection difference).** The port opens incidents through the
certified `M11\Assurance::openIncident`, which every product shares. Its `OPENED` timeline entry is
recorded by `SERVICE` with the first related ref; canonical triage records it by `SYSTEM` with no
ref. Entry type, summary and recorder id match. The conformance suite asserts the timeline on every
failing vector and pins exactly this difference, so a change on either side fails the build. It is
not changed in P08 because changing it would change every product's incident record.

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
  `REJECTED`/`SUPERSEDED`) fit hypothesis, and runs the buyer-intent scan once over every queue item.
  A `RETIRED` catalogue is swept only while a live hypothesis in this workspace still cites one of
  its products; otherwise it backs nothing and would re-open the same incident on every sweep. A
  sibling workspace's catalogue is not swept even though the single-target audit would accept it.
  - **Bound.** Catalogues plus hypotheses together must not exceed `max_targets` (default 200, max
    1000), checked **before** any audit runs (`SWEEP_TARGETS_EXCEED_BOUND`), rather than auditing a
    prefix and reporting it clean. The buyer-intent scan is one audit whatever the queue size;
    migration 246 lets it record any number of violators (proved with 1,700).
  - **Serialization.** One sweep per scope at a time, held by a named database lock; a concurrent
    sweep of the same scope is refused (`SWEEP_IN_PROGRESS`) before it audits anything.
  - **Honesty.** An audit that throws is reported as `ERRORED` (`AUDIT_ERRORED`, no error text) and
    the sweep carries on. `skipped_categories` names each category without an ACTIVE profile and
    why (`MISSING`, `SUSPENDED`, …); the sweep never seeds or reactivates one. `complete` is true
    only when every category had an ACTIVE profile and every audit COMPLETED.
  - Each failing audit opens its own incident, as canonical does for every failing call; a target
    still failing on the next sweep opens another. Any de-duplication is an upstream decision.
- **Seeding is serialized.** `seedProfiles` locks the tenant row first, so two concurrent seeds of
  one scope cannot mint rival ACTIVE profiles; the second waits and then reuses the first's.
- **Operator entrypoints.** `operatorSeedProfiles` needs `tenant.administer`; `operatorSweep` needs
  `incident.manage`, because a failing audit opens an incident; both **retain** their M02 ALLOW as
  an access record (`P03_AUDIT_PROFILE`, `P03_AUDIT_SWEEP`), because `audit_profiles` carries no
  actor and who configured or ran assurance must be answerable. `operatorSweep` evaluates at the
  clock's now under each card's own window: it refuses an `as_of` (`SWEEP_AS_OF_NOT_ACCEPTED`) and
  a catalogue-window override (`SWEEP_CATALOGUE_WINDOW_NOT_ACCEPTED`). Its runs are recorded as
  `OPERATOR` with that operator's id. `operatorStatus` needs `incident.read`, is read-only and
  retains nothing; per category it reports each audited target's **latest** result, the failing
  targets, and a posture of `NOT_AUDITED`, `PASSING` or `FAILING` (failing if any target's latest
  result failed — not merely the newest run).
- **CLI.** `php ops/assurance/p03.php <seed-profiles|status|sweep> --env=... --tenant-id=...
  --workspace-id=... --operator-id=... [--signal-window-days=N] [--max-targets=N]`. There is no
  `--as-of` and no catalogue-window option. Exit codes: 0 complete; 1 refused; 2 usage error;
  3 the sweep ran but was **incomplete**, so the scope must not be read as clean. Nothing here
  reaches the network.

Bounded scheduled audit jobs wait for an admitted P07 scheduling policy; none is registered.

### Translation repairs found by P08

The canonical card windows (`warn_after_ms`, `error_after_ms`, `recheck_terms_after_ms`) are SQLite
INTEGER (64-bit). Migration 020 translated them to MariaDB `INT` (32-bit): any window over about
24.86 days could not be stored, so a card with an ordinary 30-day terms recheck could not be
projected at all. Migration `245_source_card_window_width.sql` widens the three columns to `BIGINT`
without editing 020. The same translation class also affects the inert M15 column
`ingestion_fetch_plans.cadence_min_interval_ms`; it is recorded, not widened, because nothing reads it.

The canonical id lists `audit_runs.target_object_refs` and
`incident_evaluation_requests.related_object_refs` are unbounded SQLite TEXT; migration 010 made them
MariaDB `TEXT` (64 KiB). The buyer-intent audit writes every violating queue-item id into both, so
past about 1,680 violators the write was refused and the whole audit rolled back — the larger the
breach, the less was recorded. Migration `246_assurance_refs_width.sql` widens both to `LONGTEXT`.
`tests/integration/p08_migration_order.php` proves 245 and 246 on a database that already had every
earlier migration applied (each applied once, nothing replayed or drifted, a second pass a no-op,
existing rows byte-identical, the schema identical to a fresh install), and
`tests/integration/p03_assurance_scale.php` records 1,700 violators. Other unbounded list columns
translated to `TEXT` have not been audited for the same class.

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
