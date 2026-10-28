# BUILD-09 / VA-01 — Source-only completion receipt

**Prepared 2026-10-29; state BUILT_INERT.**
Semantics owner: Independent evaluation lead. Depends on VA-00, OB-01, BF-01.
No permission to spend, fetch source, unblind heldout, launch or release.

Positive path: 15 pinned public historical DEVELOPMENT sequences, each tested
under two frozen named comparison baselines, with 30 emitted case records
including missing/refused/abstained outcomes. Source hashes are checked through
HistoricalEvaluationFreeze. A separate synthetic 30-response fixture is openly
constructed from public development expectations; this cannot measure model
quality or real baseline performance. Output contains a deterministic complete
machine-readable scorecard, denominators, explicit unknowns, grouped failures,
and zero external/model attempts. Funded adapter explicitly refuses U01/U06
execution. CLI enables offline development only and keeps output private to
stdout. No SQL storage, schema migration, M05 source use, model provider,
customer notification or Railway/main mutation.

Frozen original 18-case, DEV7, and six HELDOUT slots remain unchanged.
Holdout payload/custodian and independent human scores remain absent, so
the release/quality status stays INCOMPLETE_UNFROZEN_HELDOUT.

Focused positive/refusal, no-egress, and frozen-file SHA regression tests
are included. Exact-head CI and actual merge proof must be recorded on the
PR/master issue only after they actually run and pass.
