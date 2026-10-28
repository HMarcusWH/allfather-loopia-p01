# OB-02 — Scoped observation history and append-only metadata candidate

**Prepared 2026-10-28.** Package BUILD-08 / OB-02 (Wave 03). Source trace LI-03, U01, U02. Prerequisite OB-01 merged. Source status **BUILT_INERT**; no live rights or production schema admission.

## Current owner law

Existing P01 ObservationHistory::listSnapshots is the only history authorization: M02 competition_monitoring.read and evidence.read, tenant entitlement, active competitor, currently approved source card, active M05 source reference and retained evidence state. OB-02 extends the SAME reader with previewAssessmentBackfill, not a second competitor registry. Every page rechecks CURRENT rights. The new ObservationBackfill additionally checks the source/evidence/diff rows against tenant, workspace, competitor, source, after-snapshot, significance and original detected-at.

CompetitorScope.v1 binds the exact P01 tenant/workspace/competitor/monitored-source/source-ref/source-card/snapshot/evidence identities. Legacy P01 does NOT record question identity: question_id is NULL, question status is NOT_RECORDED_IN_LEGACY_P01. Legacy run_id and human_annotation_id remain NULL. Never infer question or independence from a P02 twin, source hostname or arbitrary UUID. U02 must ratify any future mapping.

## Historical record meanings

The immutable ObservationAssessment metadata record is LEGACY_METADATA_ONLY, NOT_ASSESSED for business significance and assessment_status, with complete existing M05 evidence and P01 diff references. The five explicit coverage states are:

- BASELINE -> BASELINE_NO_COMPARISON
- STRUCTURALLY_UNCHANGED -> UNCHANGED_STRUCTURE_NOT_MATERIALITY
- STRUCTURAL_CHANGE_RECORDED -> CHANGED_STRUCTURE_NOT_MATERIALITY
- MULTIPLE_COMPARISONS -> HELD_CONTRARY_OR_MULTIPLE_DIFF_REFERENCES
- COMPARISON_UNAVAILABLE -> INSUFFICIENT_COMPARISON_EVIDENCE

Separate non-creating gaps: REVOKED_SOURCE_RIGHTS, MISSING_SOURCE_BYTES, REJECTED_CANDIDATE, TERMINAL_NO_CHANGE, AMBIGUOUS_SOURCE, INSUFFICIENT_EVIDENCE. No invented retrospective model assessment, no reconstruction of rejected text, no source-body retrieval, no terminal NO_CHANGE rewrite. The preview has known_at and assessed_at NULL: no retroactive knowledge time. A disposable test append writes known_at and assessed_at from the actual **CURRENT** append clock; captured_at remains the earlier original observation time. Structural significance is not commercial materiality, and no customer release follows.

## Bounded cursor ledger and proposed schema 260

The reader accepts the existing M02 actor, exact competitor, page limit 1–20 and owner-validated opaque snapshot cursor. The stable page digest binds tenant/workspace/competitor/cursor/page size, every immutable lineage digest and next cursor. A repeated apply rechecks current rights and requires identical page digest: EXACT_PAGE_REPLAY_NO_WRITE; changed rights or history refuses with LEDGER_CURSOR_DRIFT and never updates prior links. Each source reference must belong to the same source card and scope.

The candidate-only SQL at docs/longitudinal/proposals/260_competitor_assessments_CANDIDATE.sql is **outside runtime migrations**. It proposes p01_observation_assessments and p01_observation_assessment_refs, each append-only, plus an explicitly proposed third table p01_observation_backfill_ledger for durable cursor restarts. Six database triggers reject UPDATE/DELETE. Parent FKs reference the existing tenant, workspace, competitor, monitored source, snapshot and evidence owners; refs also link to original source_diff IDs. No body text or model prompt is stored.

The BF-00 family 260 register allocates **two tables only**. The additional cursor ledger therefore requires a separate RT-01 owner/schema/recovery decision, and MUST NOT silently rewrite that register or enter production. Six-trigger candidate DDL is installed **only against disposable MariaDB QA** and removed afterwards. No current existing migrations, including 900, are modified.

## Operator and test paths

- Positive authenticated read: ObservationHistory::previewAssessmentBackfill uses the existing owner reader and its CURRENT right checks, returns metadata rows and next cursor with native_persistent_apply_authorized FALSE.
- ops/history/backfill-preview.php: --help/--status introspection only; unauthenticated native requests refuse OWNER_AUTHENTICATED_HISTORY_CONTEXT_REQUIRED.
- ops/history/backfill.php: --help/--status introspection only; all native apply requests refuse NATIVE_PERSISTENCE_U01_U02_NOT_ADOPTED.
- Disposable positive: ObservationAssessmentStore::forDisposableTest requires CLI, explicit after-install test marker and a workspace with environment_class TEST and external_side_effects_allowed=0. No native runtime factory wires this class.
- Focused tests: unit ob02_coverage_candidate, security ob02_candidate_security, foundation ob02_history_boundary, integration ob02_disposable_history_backfill.

QA exercises real M02/M05 seeded history; initial page, exact replay no-op, next page, complete evidence/diff refs, six append-only triggers, foreign tenant refusals, revoked source-card rechecks, unchanged legacy source_snapshot/source_diff/company_signal/provider rows, and no family 260 migration ledger entry. All five mandatory GitHub checks must succeed on exact final PR head/merge ref; no skip is PASS.

**Remaining owner gates:** U01 semantic assessment adoption, U02 exact question/competitor source identity, U03 historical reuse, RT-01 schema reconciliation, RT-02 full composition, VA-03 populated restore and V01-V11 operational/quality/customer admission. Build hygiene does not constitute any of those. No Railway staging, provider, live crawler, model or customer output is activated.
