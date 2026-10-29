# UI-01 — Candidate-only frontend and scoped read receipt

Prepared 2026-10-29. SOURCE_BUILT_INERT only, NOT CI qualified, merged or deployed.

Reuses prior inert CompetitorHistoryAdapter and HistoryReviewReadAdapter plus CLI/disposable M02-gated HistoryReviewCandidateReadModel. Adds TemporalMemoryAdapter.v1 session-only owner read projection, closed P01 competitor/question, UTC v2 memory/ref/cutoff/rollup metadata and exact cursor, with strict U03_NOT_ADOPTED/NO_ACTION and private fields removed.

New React HistoricalReviewPreview uses independently checked pure view models for historical bases/due and temporal memory; actual caller-supplied positive fixture renders, denied/empty/HOLD states stay distinguishable, and no automatic network or active route exists. PHP unit/foundation and Node/JSX tests supplied.

No current M05 source retrieval, provider spending, live model, customer output, notifications, runtime SQL, API route or Railway/main mutation. U01/U03/U09, RT-01/RT-02 and V01–V11 remain pending.
