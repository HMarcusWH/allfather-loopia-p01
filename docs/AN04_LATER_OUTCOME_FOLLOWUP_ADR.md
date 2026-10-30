# AN-04 — Later-evidence outcome annotations and inert follow-up conditions

**Manifest:** BUILD-23 / AN-04, Wave 11 / LI-14. Depends on AN-03, TM-02 and IV-01. U03/U07/U10 source, product and outcome-adjudication decisions remain unratified. This is a source-only BUILT_INERT candidate, not a production intelligence feedback system.

## Preserve historical truth as of its original cutoff

HistoryReviewOutcomes freezes a later-known reference set against the exact immutable AN-03 provisional historical finding digest, review ID, question scope and original knowledge cutoff. New references have their own original source/evidence/snapshot IDs, safe payload digests, observed-at/known-at clocks and explicit SUPPORT/CONTRARY/UNKNOWN polarity. An input known before the original cutoff cannot be relabeled as later evidence; the original AN-03 candidate and its archived as-of assessment are NEVER changed.

Reported dispositions have a closed vocabulary: STILL_UNRESOLVED, REPORTED_CONFIRMED_IN_SCOPE, REPORTED_CONTRADICTED, COVERAGE_INSUFFICIENT, REPORTED_SUPERSEDED. The word REPORTED is load bearing: without a genuine M02/M05 reviewer and U10 decision this is only a metadata proposal. Contradiction requires new contrary reference metadata; confirmation requires a new supporting reference; supersession requires later data. There is no causal claim, business ROI grade, empirical independence assertion, M11 validated reliability weight, factual human review, or automatic prompt, scoring or scheduler change.

## Additive successor and pending forks

An annotation is immutable and its next version links to an exact predecessor digest. A later observation may change the reported hypothesis, but must do so as a new dated candidate, preserving all original evidence, snapshots, dispositions and past decision times. Incomparably linked siblings remain AMBIGUOUS_OUTCOME_HEAD_HOLD, never last-write-wins or a silent reliability update. No outcome is sent into production or customer views merely because the stored candidate hash validates.

## Follow-up is NOT authorization to act

HistoryReviewFollowUp captures a proposed next check, bounded source-ref targets, requested/not-before/deadline times, and the exact outcome annotation digest. The follow-up can request owner review, wait for routine observation, propose a bounded review, propose a contrary check or note insufficient coverage. Its dispatch always refuses. It creates no M02 grant, new capture, provider budget, native JobMachine job, recurrence, P02 twin write, P04 alert, M10 correction/release or customer output.

## Disposable proposed family345 and native linkage

Two metadata-only 345 candidate tables and four append-only UPDATE/DELETE triggers retain outcome annotations and follow-up conditions. They are outside database/migrations. HistoryReviewOutcomeStore is available only through a CLI/disposable-test marker; the runtime factory refuses. Each annotation must match a real source-only AN-03 family330 candidate row and its exact frozen JSON under the same tenant/workspace/P01 question and competitor; the next annotation may link only a same-finding prior row. Follow-up references must resolve to a stored outcome in that scope. Exact replay writes nothing. A typed readback returns all unadjudicated heads or an ambiguity HOLD; no real M05 evidence or provider content is fetched.

## Source test and deferred promotion

A fixed synthetic multi-reference AN-03 fixture feeds unit/security and disposable MariaDB 10.6/10.11 tests. Negative controls cover backdated known-at, reused old ref IDs, unsupported contradiction, forged business success/reliability/authorization, cross-tenant reads, duplicate writes, fork handling and append-only triggers. Operator CLI is status or fixed local synthetic preview with no user-specified tenant, file, source URL, model, SQL or execution path.

U03/U07/U10 owner ratification, an actual truth-adjudication process, M11 calibration, current M10 release reliance, real recovery/race proof, live schema adoption, RT-01/RT-02, post-build V01–V11 and production/customer GO are all independent future gates. Passing source CI is not those gates.

## Repair reconciliation against AN-03 native-proposal fixture

The AN-04 candidate is rebased in source onto AN-03 repair commit `39de170b60bbef6909ba1ca5206e86ba57ea8b57` without treating AN-03 as merged. The old WIP branch and original AN-03 historical finding remain untouched. The project build tracker still counts only 21 merged packages until a separately authorized integration merge.

OutcomeAnnotation v2 now records a reported observer actor type/id, with identity authentication explicitly false, along with a typed NOT_ADJUDICATED outcome, known source-reference membership, the original IV-01 dependency-graph digest and PROPOSAL_ONLY_NO_INVALIDATION_EXECUTED. The original finding and all historical analyst/critic evidence remain immutable. An observed outcome is not the same as an owner-confirmed business outcome.

FollowUpCondition v2 accepts only previously recorded original/later source references as targets; arbitrary foreign source IDs are refused before a follow-up candidate is formed. Even a valid target is not a fetch or permission to alter a schedule. HistoryReviewOutcomeStore independently recomputes the original-source set and graph digest from the stored AN-03 finding and checks the follow-up against its stored parent.

A distinct `HistoryReviewAssessmentSuccessor` reconstructs a new assessment candidate from an exact prior and later outcome annotation, binds their digests, reporter and original IV-01 graph, and refuses orphan/mismatched/out-of-order successors. It does **not** execute an IV-01 invalidation, change M10 reliance, certify a business result, update reliability weights or retune model prompts. The family345 store retains the pair as separate append-only annotation rows and offers a read-only successor reconstruction; no new table or live migration is introduced.

**Qualification:** source review and a new PR/CI run are required after AN-03 merges. U03/U07/U10, actual observer authentication, real source evidence rights, human adjudication, source/provider execution, customer distribution and deployment remain separately blocked.
