# HS-02 — Owner-native historical source-use inspection and bounded purpose HOLD

**Package:** BUILD-13 / HS-02, Wave 06. **Owner:** M02/P01/M05 source current-use owners, with U03 M10/Ops/canonical sign-off still pending. **Dependencies:** merged HS-01 + BF-00. **Status:** BUILT_INERT code candidate; no runtime factory, owner grant or production migration.

## Reuse current native source owner, do not invent a second rights engine

The existing M17 HistoricalCaptureProbe inspects each P01 captured snapshot through actual current M02 competition_monitoring.read and evidence.read grants, source references, approved source license card, source terms review and compiled M05 source policy; it then verifies private retained capture bytes and M05 raw-artifact/payload/snapshot identity. It returns no bytes, URL, credential, or source disclosure authorization. HS-02 uses that exact owner (via HistoryReviewRightsGateway) rather than reimplementing policy SQL, changing source cards, trusting a caller's hash/flag or circumventing ContextEnvelope.

An HS-01 PointInTimeHistoricalBasis candidate is input, with exact actor tenant/workspace, P01 competitor/question, selected snapshot/source/evidence/raw digest identities, four clocks and immutable basis digest. The gateway rejects unauthorized actors, unrecognized purposes, foreign scope, future review dates, invalid/hollow manifests, and over-20-reference selections. Each selected reference must match the *current* M05 owner proof for exact source/snapshot/evidence/capture digest and the original conservative observed/recorded/known-at clock conversions. No extrapolated source-publisher independence or business-event identity is accepted.

The HS-01 basis itself is metadata and can be self-hashed by its caller, so even a matching byte hash is NOT owner authentication. The gateway reports a new, separately computed native source-ref fingerprint and proof count. It does not export per-source IDs, source text, URLs, policy artifacts, captured bytes, or credentials. If source rights were revoked, the card is quarantined or retained private bytes are missing, the native owner refuses; the gateway returns a scoped HOLD without re-reading through a second source channel.

## Purpose and stage are distinct

The three separate exact purposes are DETERMINISTIC_INTERNAL_REVIEW, MODEL_ASSISTED_HISTORICAL_ANALYSIS, and EXTERNAL_PROVIDER_BYTE_DISCLOSURE. Authorization for one can never imply authorization for another. Existing LI-05 descriptive states remain immutable; HistoricalEvidenceEligibility::purposePosture merely describes a HOLD with no signed U03 contract digest.

Gateway inspection slots PRE_DISPATCH, POST_RETURN, PRE_RELEASE each re-run the actual current owner read. The latter two require the exact earlier owner fingerprint (bound to actor, scope, basis digest, purpose and current policy/source proofs) and refuse changed/revoked rights or cross-purpose substitution. These are *read-only preconditions*, not dispatch hooks. The original HistoryReviewContext ModelContextAuthority methods continue to refuse real model context and routing. Its new point-in-time preview exports metadata counts only, and its inspectCurrentUse method delegates to the gateway without enabling execution.

At every stage the result is HOLD_U03_HISTORICAL_USE_NOT_RATIFIED. The gateway may report true for current M02/P01/M05 reference coherence and private byte *verification*, but historical U03 use, external provider disclosure, model execution, M10 reliance, P04/M16 representation, customer output and production authority remain false. A current allowed source card cannot itself establish lawful historical reuse of old bytes, copyright purpose, redaction policy or a paid provider disclosure grant.

## Genuine synthetic positive and denied paths

Only tests/support/Hs02SyntheticUse.php can execute a bounded fixture-specific deterministic count over explicitly frozen synthetic candidate metadata, under an explicit CLI-only test marker. It does not import or export a source, open a DB, access M05 bytes, choose a model, fund a provider call, create a release, or represent real U03 authority. The fixture counts captures, exact byte classes, retained contrary refs and unknown real-world events. Requests for model-assisted analysis or external byte disclosure refuse, even in the synthetic lane.

The integration test builds an actual approved P01/M05 private capture using the existing fixture MonitorFactory and owner services (no external network), reconstructs a point-in-time candidate from the owner refs, and verifies CURRENT M02/M05 readback, private-byte preservation, bound clocks, a no-authority HOLD, stable pre-dispatch/post-return/pre-release fingerprints, cross-purpose refusal and revoked-source refusal. It asserts zero additional fetches, model attempts or primary source mutations.

## Unratified decisions, deferral and acceptance

Canonical U03 readiness PR #382 lists six pending decisions. None is signed/adopted by this package. Exact historical retention/rights, source purpose, M10 current reliance, non-sensitive redaction, external provider disclosure, production schema install, API execution or customer access must be separately approved and verified. The current upstream.lock.json global authority pin remains unchanged. Source-only CI will not claim owner-qualified historical use.

All five exact PR jobs and a byte-identical tested merge are required. Full V01–V11, RT-01/RT-02, live host, real source/publisher independence, independent human quality, source-provider agreement and operational backup/restore are deferred—not silently passed by synthetic examples.
