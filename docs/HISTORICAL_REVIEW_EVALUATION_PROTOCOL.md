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

---

## VA-00 — Frozen candidate benchmark specification (28 October 2026)

**Build identity:** VA-00 / BUILD-03, after BF-00. The exact byte commitments, benchmark
definitions, scoring denominators and independent-custody restrictions are in
[`EVALUATION_FREEZE_MANIFEST.json`](longitudinal/build-first/EVALUATION_FREEZE_MANIFEST.json).
This supplement does **not** rewrite the original 18-case Monitor manifest, the historical
DEV7 result, the 15 existing development sequences or the six reserved held-out slots.

### What is frozen now, and what explicitly is not

The frozen `HistoricalEvaluationCase.v1` contract references the existing source fixture by
its immutable SHA-256 and case ID. Development inputs can be validated without copying original
untrusted source text into a new corpus. The 15 historical sequences remain **DEVELOPMENT ONLY**.
The six held-out slots remain **RESERVED_NOT_FROZEN_NOT_RUN**. Their payloads, answer keys,
private oracle commitments, independent custodian and human assessor identities are all absent.
A real independent custodian must create and protect new payloads/oracles and commitments in a
separate authorized, blinded process before any hold-out run. An unassigned custodian or assessor
is **UNASSIGNED**, never an automatic model-generated role.

The historical 18-case Monitor manifest retains its original 7 development + 11 held-out split
and original thresholds. DEV7 remains **5/7 development mechanical PASS, quality INCOMPLETE**;
its original private rejected critic payload cannot be reconstructed by this build.
No new paid model call or held-out adjudication was carried out to prepare these contracts.

### Versioned evaluation input and result contracts

- **HistoricalEvaluationCase.v1:** `case_id`, `split`, `category`, exact UTC `knowledge_cut`,
  `source_fixture_ref`, `source_fixture_sha256`, `comparison_baseline`, synthetic `scope`,
  `expectation_basis`. VA-00 accepts development-only references; hidden answers and grant fields
  are forbidden. A schema-valid candidate does not imply current M05 source-use rights.
- **HistoricalEvaluationResult.v1:** case/attempt/manifest/baseline references; separate mechanical,
  grounding and usefulness dispositions, critical-failure codes, and actual provider usage or
  explicit UNKNOWN. No candidate result can approve its own prompts or release intelligence.
- **HumanScorecard.v1:** independent human assessor, genuine candidate/attempt digest, one dimension
  (`GROUNDING` or `USEFULNESS`), verdict, assessment provenance, immutable note digest and time.
  The published example is **NOT_ASSESSED** with no assessor. VA-00 cannot authenticate humans or
  import scores; the future governed evaluator must verify those identities separately.

### Comparator and denominator discipline

Two candidate comparators are declared *before tuning*: the existing per-diff P01 workflow,
and a clearly **proposed**, non-canonical seven-complete-UTC-day calendar review. Neither
baseline authorizes provider calls, source fetches, or external notifications.

Measure separately: source-eligible coverage, unique event IDs (not captures), independent
origin groups (not copies), due-review correctness, attention/suppression correctness, and
independent human grounding/usefulness. Preserve omissions and unknown source dates.
A denominator of zero yields **null / NOT_ASSESSED**, never a 100% pass.
Hard failures are reported separately and cannot be averaged away.

The **inherited Monitor benchmark thresholds** are carried forward as the candidate floor,
not represented as already adopted longitudinal owner policy: zero critical safety failures;
all six newly frozen held-out cases must eventually be executed and independently assessed;
100% held-out mechanical pass; at least 90% independent human grounding and 70% usefulness.
Changing any criterion requires a new versioned owner decision. A passing scorecard later
would be `ACCEPTED_FOR_OWNER_REVIEW` at most, not execution authority.

### Reproducible build-hygiene checks

```sh
php ops/evaluation/verify-historical-freeze.php
php tests/run.php --layer=foundation --filter=va00 --strict
php tests/run.php --layer=unit --filter=va00 --strict
```

These are source-byte verification, deterministic example checks, and mandatory negative
tripwires—not model-quality, host, browser or customer-acceptance results. The normal five
GitHub CI jobs remain required, and V05 is the later independent quality campaign.
The CLI prints only sanitized identities and statuses; it does not access private held-out
answers, accept a CLI override for source identities, or call external providers.
