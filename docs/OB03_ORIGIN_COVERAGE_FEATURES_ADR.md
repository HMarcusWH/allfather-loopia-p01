# OB-03 — Source-bound observation features, origin assertions and coverage receipts

**Package:** BUILD-10 / OB-03, Wave 04, prepared 2026-10-29. **Source trace:** LI-04, U02; integrates CR-02 / BUILD-07 and OB-02 / BUILD-08. **Semantic owner:** P01/M05 provenance. **Build status:** BUILT_INERT (no native factory, source rights, schema install or live customer output).

## Existing owners and source evidence

The retained original LI-04 pure operators, ObservationFeatures, SourceOriginGroups and ObservationCoverage, are used *without rewriting their frozen versions*. OB-03 composes their outputs with CR-02 SemanticDelta.v1 and OB-02 ObservationAssessment.v1 without converting a structural change into commercial materiality or a duplicated page into an independently supported event.

The source/authority chain remains:

1. **M05/P01 primary**: authenticated source reference, current approved card, original P01 canonical URL/body content hash and exact captured evidence/snapshot identity, under M02 actor scope and entitlement.
2. **CR-01/CR-02 derived**: separate original raw HTML byte SHA-256, normalized section/body digests, observed typed changes, and explicit uncertainty about repaired HTML and raw byte offsets. The CR-01 SHA-256 is not automatically identical to P01 canonical content digest.
3. **OB-02**: immutable legacy metadata-only assessment with question_id NULL when unrecorded, NOT_ASSESSED business significance, M05 evidence and complete P01 structural diff references. It is not a historical model assessment.
4. **OB-03**: normalized, bounded ObservationFeatureSet candidate, M05-source-reference owner readback and optional owner-backed CoverageReceipt; no primary evidence, source bytes, rights or authorizations are minted.

Current owner permissions are rechecked through **ObservationHistory::listSnapshots** (M02 permissions, live entitlement, current P01 competitor/card/M05 rights), then **SourceOriginAssertionReader::inspect()** verifies the exact snapshot, source reference, evidence object and P01 canonical content hash through CompetitorScope. This read establishes only *that the registered source reference was current and coherent at readback*. It does **not** authenticate the real-world original publisher, syndication tree, economic event, independently corroborating sources, or permit new historical U03 reuse.

The reader has a strict **20 requested reference** bound and must find each ID in the existing owner's max-50-snapshot current page. Captures outside that authorized window refuse rather than silently using an unscoped database scan. Cross-tenant, revoked, unknown, duplicated or cross-source IDs fail closed. No URL/body content is returned.

## ObservationFeatureSet.v1: byte classes are not events

Version: ob03.observation-feature-set.v1. Input: OB-02 metadata record, CR-02 SemanticDelta and list of exact-capture-id/source-ref/RAW-payload-SHA fixtures, optionally bounded original bytes already supplied by an authorized test caller for LI-04 comparison. It validates **matching tenant/workspace/monitored-source/source-reference/license-card, after-snapshot and M05 evidence IDs** and rehashes the CR-02 and OB-02 versioned candidates. The raw SHA is explicitly held **separate** from the P01 canonical digest.

LI-04 SourceOriginGroups classifies exact captured payload SHA bytes. **Ten** identical capture payloads remain **one exact-byte equivalence class** and **nine byte repeats**, never ten corroborating events or ten independent publishers. Cross-domain or different source-ref labels are not origin evidence; an original page can be syndicated to many hosts and differently worded pages can still refer to one event.

CR-02 section rows retain change kind (ORDER_ONLY, PRICE_CHANGED, NEGATION_CHANGED, DATE_CHANGED, UNKNOWN/unpaired variants), source-bound before/after SHA, derived selector/section identity, sensitive-token families, uncertainty and a stable **exact-change signature**. Identical section-change signatures are counted once, but they are **not** automatically unique real-world events. Optional LI-04 token extraction cross-check returns CONCORDANT_BOUNDED_TOKEN_CLASSES, DISAGREEMENT_HELD, NO_BOUNDED_FEATURE_TO_CONFIRM or NOT_PROVIDED; a disagreement cannot silently elevate classification. The LI-04 raw input bytes and potentially hostile navigation labels are discarded immediately. No source excerpt or raw HTML is put into feature rows.

The output separates: captured observations, distinct exact-byte payload classes, repeated bytes, candidate change signatures, registered source references, and **NULL** distinct-real-world-event/independent-corroboration counts. Source-origin independence remains UNKNOWN, business materiality NOT_ASSESSED, and U02/U03, release, paid model and scheduling grants FALSE. Every candidate has a SHA-256 versioned feature digest. A self-hash is candidate integrity, **not owner authentication**.

## CoverageReceipt.v1: only actual owner receipts prove an observed slot

Version: ob03.coverage-receipt.v1. The positive native path is **ObservationCoverageReceipt::fromCurrentOwner(ScheduleHistoryCoverage, actor, competitor, period, cutoff, scheduleVersionId)**, which calls the **existing** current-owner ScheduleHistoryCoverage::inspect() rather than trusting a caller-supplied receipt. That owner verifies M02 scope, admitted current ScheduleService version/digest, exact occurrence and RunJournal records, service identity, original source snapshot and private M05 captured payload binding. OB-03 retains that receipt digest and distinguishes captured BASELINE, changed, unchanged, missing/unreported (EXPECTED_NOT_FETCHED_UNKNOWN), explicitly skipped, BLOCKED_OR_REFUSED, FAILED_CAPTURE and PENDING_NOT_OBSERVED.

A missing capture **never** becomes NO_CHANGE or a business NO_MATERIAL_CHANGE. UNCHANGED counts only when a valid owner occurrence actually captures an unchanged page. A gapped owner schedule yields HOLD_GAPPED_OR_INCOMPLETE; a complete owner schedule yields COMPLETE_OWNER_CAPTURE_COVERAGE but still NOT owner-admitted historical reuse, publisher independence, model inference or customer release. A paused/expired/foreign schedule refuses.

Without an authenticated owner schedule, **synthetic()** calls the frozen LI-04 arithmetic and produces UNKNOWN_NOT_OWNER_VERIFIED even if the supplied planned slots look complete. Both have exact numerator/denominator rates (not unqualified percentages), explicit zero-denominator NULL, count consistency, versioned coverage digest and no runtime scheduling power.

## Family 265 — candidate persistence with no U02/U03 grant

Candidate-only SQL: docs/longitudinal/proposals/265_observation_features_CANDIDATE.sql. The frozen BF-00 allocation already names the **two proposed tables** p01_observation_features and p01_source_origin_assertions. This DDL proposes them, with scoped FKs to existing P01/M05, source_snapshots, evidence and OB-02 metadata, UNIQUE identities and four database-level APPEND_ONLY UPDATE/DELETE triggers. Columns store only SHA-256-linked feature metadata and **unknown** origin state. No raw bytes, model prompt, independent-origin proof, or customer statement are persisted. RT-01 must still reconcile exact table schema/permissions/FKs and VA-03 recovery before production; these candidate tables are NOT in database/migrations.

ObservationFeatureCandidateStore::forDisposableTest is usable **only on CLI with a test-local schema marker**, after both family 260 and 265 are temporarily installed in a TEST workspace whose external effects are disabled. Its recordFixture first rechecks current native M02/P01/M05 rights through SourceOriginAssertionReader, the source card, exact snapshot/evidence/OB-02 assessment lineage, then transactionally appends feature and origin reference rows. Exact replay returns NO_WRITE; changed same-assessment data refuses, and revoking source permissions blocks repeat reads/writes. The test tears down all temporary tables and changes **zero** native M05/P01 source/diff/provider/stream rows.

This is **not** a live feature writer. The test marker only enables a disposable QA scenario; it does not admit U02/U03 source-use contracts. No production SQL or customer action results from merging the source candidate.

## Verification and deferred obligations

Focused tests cover:
- unit: five CR-02/OB-02/LI-04 feature scenarios, 10 duplicated captured pages → exactly 1 byte class, price/negation/date and excerpt uncertainty, missing/blocked/failed/pending slot accounting;
- security: cross-tenant/source/hash/evidence mismatches, duplicate captures, fake origin authority, false source digest, model/grant injection, false no-change;
- disposable MariaDB: current M02/M05 proof, 260→265 candidate DDL, scoped feature and origin append-only FK/triggers, idempotent replay, foreign principal and source-card revocation;
- native schedule: real admitted ScheduleService/RunJournal + M05 fixture capture, baseline/changed/unchanged slots, outage skip, revoked/suspended schedule and no model calls;
- foundation: legacy LI-04 versions intact, source-only capability matrix, no egress and no runtime 265 migration.

No production/host/Loopia/Railway, source rights, owner ratification, model quality, human review, source origin, or customer output is claimed by these tests. Required exact-head GitHub CI is foundation, MariaDB 10.6, MariaDB 10.11, package integrity and code qualification all SUCCESS with **0 failures and 0 skips**, before the isolated integration merge. RT-02 end-to-end and V01–V11 final assurance remain separate.

**Remaining owner gates:** U02 real identity/common-origin/independence ratification; U03 historical source-use/clocks; RT-01 approved schema and owner FK order; OB-03 live consumer integration via CR-04 and subsequent HS-01; VA-03 populated recovery, V05 human/model assessment, final customer/production GO. Build pass is not a grant.
