# LI-18 — Longitudinal sequence evaluation protocol (fixture-first proposal)

**Version 2026-10-25.dev1. Status: offline development fixtures only.** The original 18-case Monitor manifest and DEV7 records are immutable and are **not** modified. This PR contains no new model calls, live source transport, scheduling, evaluator grant, persistent observation owner or release route.

## Available deterministic development evidence
The 15 synthetic, fictional sequences in `tests/fixtures/historical_review_development_cases.json` freeze event/observed/known-at dates, distinct event identities, uncertain common origin, source rights at review time, knowledge-cut selection, unchanged/unavailable coverage, and requirement to keep materiality and attention separate. They include menu-only noise, tiny price change, correlated copies, late evidence, rights withdrawal, coverage gap, contradicting sources, untrusted historical prompt-injection text, slow drift, backpressure, repeated alert, high-consequence claim, unknown event date, post-withdrawal evidence and ambiguous origin independence.

A deterministic fixture validator (`tests/unit/historical_sequence_fixture_freeze.php`) proves syntax, no duplicate identities, eligibility under explicit known-at cuts, distinct event/origin groups and that these fixtures grant zero provider/model/release authority. **This is fixture correctness, not proof of model skill or implemented temporal runtime.**

## Held-out truth
`tests/fixtures/historical_review_heldout_manifest.json` reserves six independent scenario categories but **does not claim the payload or oracle is frozen**. It is explicitly `RESERVED_NOT_FROZEN_NOT_RUN`. Before any prompt tuning or model-quality evaluation, an independent owner must freeze complete held-out payloads, SHA-256 commitments, private answer keys, contamination protections and human scoring rubric under U01/U06. **Until that happens: no held-out pass, scorecard approval or evaluation-backed deployment claim.**

## Future scorecard (requires U01/U05/U06 policy owners)
Separate denominators and reports for:
observation retention/retrieval; eligibility and coverage; unique events vs captured pages vs common-origin groups; due-review precision/timing; false reviews; grounding; preserved contradictions; alert precision/recall/dedup; time and operator burden; actual provider usage and unknowns. Do not average hard failures away. Compare against current per-diff flow and simple calendar review. Missing/rate-limited cases remain in the denominator. Model verdicts do not self-adjudicate, and a due timestamp never creates a grant.

## Boundaries
The fixture time-cut evaluator only selects *illustrative packets*; real M05 rights checks, tenant scope, current quarantine, authorization of historical provider disclosure and owner-reviewed U01–U10 contracts are still required for runtime semantics. No schema migration, no history backfill, no customer release, no inference activation.

**Next:** settle U01 and independently freeze protected held-out material, then implement a proper historical sequence runner with separate independent human-quality assessment and exact grant/budget review.
