# AN-03 — Multi-reference historical finding and release HOLD

**Package:** BUILD-22 / AN-03, Wave 10, LI-12. Dependencies AN-02 and IV-01 are merged. U03 historical reuse, U06 provider authority and U07 multi-evidence P01/M10 admission remain unratified. This is BUILT_INERT source only.

## Separate history identity

P01 CompanySignals and ReleaseStages currently link one source diff to a signal and M10/M16. A conclusion over multiple historical sources must not be attributed to an arbitrarily selected diff. HistoricalFindingAdapter therefore constructs a separately versioned, provisional historical finding, while the existing P01/M04/M05/M07/M08/M10/M16 code remains unchanged. The CompanySignal promotion and HistoryReviewRelease release entrypoints always refuse pending U07 owner adoption.

## Multi-reference and human-HOLD constraints

A candidate requires an exact complete HS-01 basis, AN-02 immutable run, three ordered and hash-linked request-inspect/analyst/critic events ending HUMAN_REVIEW_REQUIRED, and the critic linked to the exact analyst digest. It copies only metadata IDs, digests and source clocks for ALL primary references. Supporting refs must really have SUPPORT polarity; contrary refs remain present and cannot be relabelled. A reference is not proof that a natural-language claim follows from it. Two captured changes do not prove independent publishers, causation, economic materiality or separate real-world events. M17 statistics are context, not primary evidence.

Every primary source ref is an input to a typed IV-01 dependency graph: CAPTURE -> HISTORICAL_BASIS -> ANALYSIS -> FINDING. The graph is regenerated on verification, so changing a source digest and recomputing a candidate hash cannot silently replace lineage. This is structural integrity, not native M05 source authentication.

## Release, invalidation and human approval

HistoryReviewRelease can inspect a structurally valid finding but returns HOLD_U03_U06_U07_NATIVE_MULTI_EVIDENCE_ROUTE_NOT_ADOPTED. Optional HS-02 current-use metadata remains HOLD and is not accepted as a native authorization token. Future native release must separately recheck current M02/P01/M05 source rights and byte retention, U03 historical use, U06 model disclosure, U07 finding representation, M07 review, M08 rule, M10 reliance and M16 artifact. The factual human review and the later delivery/recipient human review remain distinct. No P02 state, P04 distribution or customer visibility is created by this package.

## Proposed family 330 and tests

Disposable-only family 330 proposes three tables and six append-only triggers: one finding candidate, all individual reference bindings, and an exact analyst/critic event-link receipt with both human approvals hard-false. HistoricalFindingStore is CLI/disposable-only; its production factory refuses before SQL. Atomic inserts and idempotent exact replay retain every reference, polarity, source hash and reviewer digest; foreign tenants/questions are refused.

Positive tests use actual HS-01/AN-01/TM-02/AN-02 synthetic source contracts. Negative tests cover missing/broken review chain, contrary-as-support, rehashed owner/release claims, changed dependency hashes and the forbidden single-diff route. The guarded CLI fixture preview exports only safe counts and hashes.

**Deferred:** actual owner U03/U06/U07 adoption, live schema and M10 native path, human factual and delivery approvals, original M05 source authentication, real model quality, RT-01/RT-02, final V01–V11 and deployment. No source CI green result grants those rights.


## NativeReleaseProposal v1 and synthetic owner handoff

AN-03 now prepares a separately versioned `NativeReleaseProposal` from a verified historical finding. It preserves the exact finding, all selected M05 metadata references, claim/support and contrary mappings, as-of cutoff, review-chain digests and IV-01 lineage. Proposal verification reconstructs the canonical candidate, so a caller cannot rehash an altered authorization flag or omit contrary references.

`HistoricalFindingReleaseOwnerPort` defines eight explicit future owner stages: current M05 source-use check, factual human decision, M04 multi-evidence proposal, M07 independent review, M08 admissibility, M10 current-reliance candidate, separate delivery human decision and M16 internal representation candidate. `HistoryReviewRelease::exerciseFixtureOwnerPath()` invokes every stage through the same port interface, verifies scope, proposal and basis binding, all reference IDs, predecessor receipts and distinct purpose-bound human-fixture decision digests.

The sole executable port in this revision is `tests/support/An03FixtureReleaseOwner.php`. It requires CLI, an explicit test-only process marker, the exact test implementation class and source file, and it performs no source fetch, model call, M10 write or customer activation. Its positive outcome is `FIXTURE_OWNER_SEQUENCE_PASS_NO_LIVE_RELEASE`, **not** a real release or human approval.

**Native owner adoption remains CONTRACT_BLOCKED_U03_U06_U07.** The existing P01 single-diff CompanySignal route and P04 lifecycle are unchanged. Runtime release, actual source-use/paid model rights, current M10 reliance, live family 330 DDL, P02 changes and customer visibility remain refused. Future adoption requires a separately reviewed native multi-evidence owner contract and real owner-side checks. A passing fixture test does not establish the post-build V01-V11 gates.
