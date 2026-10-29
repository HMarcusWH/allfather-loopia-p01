# HS-01 — Point-in-time historical basis (candidate only)

**Package:** BUILD-11 / HS-01, Wave 05. **Owners:** P01/M05 historical-basis consumer; U03 historical-use review remains pending. **Inputs:** LI-05 offline metadata, OB-03 provenance/coverage, bounded UTC knowledge cut. **Claim ceiling:** BUILT_INERT source candidate; not a source-use, model, retention, publication, or production authorization.

## Reused architecture and version

LI-05A HistoricalEvidenceSelector/HistoryReviewBasis already implement a typed, bounded, offline selector and frozen manifest. LI-05C HistoryReviewBasisStore already has CLI/disposable-only storage and proposed family 270. HS-01 retains those v1 classes and hashes unchanged; the separately versioned PointInTimeHistoricalBasis uses the LI-05 validator and adds a stronger time, omission and successor contract. Both old manifest_digest and new basis_digest coexist in the disposable 270 store. A self-hash is candidate integrity, never owner-authenticated truth.

## Time and selection semantics

The window uses OBSERVED_AT with inclusive start and exclusive end. All clocks must be canonical UTC seconds under UTC_PROLEPTIC_GREGORIAN_SECONDS_V1; no implicit Stockholm DST, local-time coercion or calendar rounding. Event_at is a source-claimed event date, NOT evidence of actual occurrence. Observed_at is the capture clock. Recorded_at and known_at delimit what the system could have known, with observed <= recorded <= known. Review_at_utc is the separately declared hypothetical review time and cannot precede the knowledge cutoff; it does not prove human review. A later-analyzed assessment identity is masked until it is known. A late-known event with an early event_at must not enter an earlier cutoff.

Inputs preserve exact tenant/workspace/P01 competitor/question, source/evidence/snapshot/assessment IDs and payload SHA-256. The selection policy CONTRARY_FIRST_BOUNDED_V1 reserves scarce cap capacity for contrary observations before accepting other eligible observations. Final selected presentation order is observed_at then ref_id. Capped candidates remain INSUFFICIENT_BASIS; selected items cannot establish source independence, commercial materiality or universal coverage. Each exclusion is counted by BEFORE_WINDOW, AFTER_WINDOW, NOT_YET_KNOWN, REPORTED_NOT_USABLE or SELECTION_CAP and retains an opaque input digest and reason. Future-known refs and their dates/polarity are masked to prevent accidental disclosure in a historical as-of context.

The descriptive states NO_INPUT_DATA, LATE_KNOWN_INPUT_EXCLUDED, MISSING_BYTES_REPORTED, INCOMPLETE_SUPPLIED_INPUT and BOUNDED_METADATA_AVAILABLE are different from a model decision about business change. Caller-supplied scheduled coverage, even a syntactically KNOWN plan, is explicitly NOT_OWNER_VERIFIED; it is not an authenticated observation and may contain failed/skipped/missing slots.

## Immutable source and persistence semantics

The candidate freezes a sorted source_input_digest, current basis_digest, optional previous_basis_digest, exact clocks and selected/excluded references. An input-specific successor with newly known data appends another candidate; no earlier row is rewritten. The proposed family 270 remains exactly two tables: history_review_bases and history_review_basis_refs with four append-only triggers. Parent manifest_json retains excluded audit metadata and counts; child rows bind each selected reference. No original source bytes are copied.

The existing HistoryReviewBasisStore only writes when CLI and ALLFATHER_LI05C_DISPOSABLE_SCHEMA_LOADED test marker are both present. Exact duplicate replay returns NO_WRITE, changed identity conflicts, cross-scope lookup returns not found, and database UPDATE/DELETE is blocked. Candidate tables remain in docs/longitudinal/proposals, never database/migrations. Live schema selection, foreign keys, retention, populated restore and runtime owner factory belong to RT-01/VA-03.

## Preview and authority boundary

The operator CLI at ops/history/basis-preview.php accepts --status. --synthetic-development additionally requires ALLFATHER_HS01_SYNTHETIC_TEST=1 and the repository's fixed local synthetic fixture. It prints only count/clock/digest status, never source IDs, private captured bytes or claims. No arbitrary filesystem path, actor flag, credential or network action is available.

U03 remains unratified. No historical source reuse, current rights, external provider disclosure, native model context, HTTP entrypoint, scheduler, customer release, Railway deployment, production database migration, production authorization or new customer bootstrap is provided. HS-02 must independently resolve current M02/P01/M05/M10 owner rights for exact purpose and time. TM-01 may consume deterministic candidate context only, not promote it to primary evidence.

## Focused testing and verification gaps

Unit coverage checks four clocks, half-open windows, masked future knowledge, contrary-first capped selection, missing bytes versus no data, late-input successors and v1 preservation. Security rejects permission forgeries even with recomputed candidate digests and foreign scope. Disposable MariaDB 10.6/10.11 tests both v1 and v2 writes, exact replay, append-only protection and zero native migration. Static boundary tests verify inert handling. The full V01–V11, RT-02, U03, human/model quality, host and customer qualification remain separate; report exact GitHub CI evidence only after it actually completes.
