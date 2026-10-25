# LI-04 — deterministic feature, byte-repeat and coverage precursors

**Date:** 2026-10-25. **Status:** PURE / OFFLINE / NOT_PERSISTED. This is an intentionally smaller predecessor to the full LI-04 implementation in the retained October 25 plan; **U02** origin/group identity and **U03** historical rights/clocks are owner decisions NOT ratified by these PHP functions.

## The three isolated PHP operators

- `app/Product/P01/ObservationFeatures.php`: requires exact before/after synthetic or already-lawfully-supplied text payloads, typed snapshot/evidence references and verified SHA-256 over each passed byte string. Hard cap 32 KiB per body. Recognizes only a narrow *literal* feature vocabulary: unambiguous simple HTML navigation label permutation (single NAV, 2–12 unique direct anchors), one unambiguous typed currency/price value per capture, one exact English/Swedish negation toggle over otherwise identical lexical text, or one ISO date-literal replacement over an identical surrounding string. Multivalued price, multiple navs, bad UTF-8, missing digest, active markup or unsupported input refuses or returns UNKNOWN/UNCLASSIFIED. Outputs have immutable extractor version, bound input SHA/reference identity, no raw capture body, and explicit source-rights/magnitude limitations. Bounded nav labels are **untrusted source text**, not a safe publicly displayable description.
- `app/Product/P01/SourceOriginGroups.php`: accepts scope-bound capture IDs and SHA-256 payload hashes; groups **exact-byte equivalences only**. Cross-source identical text is NOT a demonstrated common origin; different text might still be one event. Exact duplicate capture IDs, unknown origin authority fields or foreign tenant/workspace refs refuse. `independent_corroboration_count` remains NULL (UNKNOWN), never zero or invented 2/3.
- `app/Product/P01/ObservationCoverage.php`: aggregates exact planned slot counts and explicit per-slot capture/refusal/failure/pending/skip outcomes. Separately counts missing/unreported gaps and structural unchanged captures, without converting an unavailable observation into `NO_CHANGE`. Rates carry integer numerator and explicit denominator (not an unqualified percentage). No real-world event count or business materiality inferred.

## Non-authority is a feature

The classes are pure and are **not** invoked from `SourceCaptureStage`, `ObservationHistory`, `MonitorService`, scheduler or HTTP/React. An upstream caller would first need exact M05/P01 source rights, 4-clock U03 eligibility, tenant/workspace proof and review of untrusted labels before allowing any output beyond internal offline QA. Stored evidence and its structural `source_diffs` remain unchanged. These functions do not write an alternate truth/evidence store or assert materiality; `release_authorized=false` and `capture_or_analysis_authorized=false`.

No SQL migration `265_observation_features_and_origins.sql`, no provider attempts, source fetch, scheduled work, customer-facing route, new roles, source-origin assertions, notifications or automatic review action. Tests are development-only deterministic scenarios, **not heldout model-quality validation** or admission of U02/U03.

## Qualification

Run on the exact PHP release candidate with `php tests/run.php --layer=foundation --filter=li04_pure_boundary --strict` and the three unit lanes `observation_features`, `source_origin_groups`, `observation_coverage`. Run the complete strict seven-layer regression on fresh disposable MariaDB 10.6/10.11 using PHP 8.5.12 before any main merge, preserving existing 31 migrations and staging DB/volumes.

## What remains before actual LI-04 completion

Ratify U02 common-origin/competitor scope and U03 historical read/use clocks and rights; link the bounded extractor to currently permitted retained M05 source payloads with integrity and source status rechecks; define audited typed feature persistence and origin assertion records, supersession and invalidation; add additive migration with fresh/upgrade/no-op and populated restore QA; expose only authorized in-console metadata through an explicit LI-16 owner-reviewed read adapter. A pure feature candidate is NOT a real competitor observation assessment.
