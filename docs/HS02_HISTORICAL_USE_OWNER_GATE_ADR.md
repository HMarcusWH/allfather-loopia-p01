# HS-02 — Current owner historical-use gate (U03 HOLD)

Prepared: 2026-10-29. Wave 06 / BUILD-13. Depends on HS-01. Source packages LI-05/LI-15/U03. Current canonical U03 owner ratification readiness PR #382 is still OPEN and records six independent decisions as PENDING. This package is source-only, no activation.

## Exact three purposes and four checks

HistoryReviewRightsGateway declares three non-interchangeable purpose classes:
1. INTERNAL_DETERMINISTIC_REUSE
2. MODEL_ASSISTED_HISTORICAL_ANALYSIS
3. PROVIDER_SOURCE_BYTE_DISCLOSURE

It exposes four explicit reevaluation stages: BEFORE_BASIS_REUSE, BEFORE_MODEL_DISPATCH, AFTER_MODEL_RETURN, BEFORE_INFORMATION_RELEASE. These stage names are not an execution system; each is a read-only rights check and all currently return HOLD_U03_U06_UNRATIFIED even when existing source-reference registration checks are successful. U03/U06 ratification, model limits, disclosure contracts and a new registered execution purpose remain separate prerequisites.

## Owner path and no-collapse

The gateway accepts only a hash-verified, complete-within-input HS-01 metadata basis and an exact M02 actor/tenant/workspace, P01 competitor, review timestamp and selected snapshot set. It rejects partial or duplicated reference selection and invented permission flags. It calls the existing SourceOriginAssertionReader at every stage. That reader performs current M02 permission and tier validation, P01 competitor/source-card, M05 evidence and P01 canonical snapshot checks. The gateway additionally compares every selected basis source-ref and evidence identity against current owner readback, and refuses any mismatch or revoked owner card.

This is not proof that source content can now be historically reused, that retained original bytes exist, that actual source publisher identity or independent corroboration is known, or that M10 has released a historical finding. Original P01 canonical content digest and CR-01 raw-body SHA are deliberately not conflated. An M05 source-reference registration is neither U03 historical-use license nor U06 provider-disclosure approval.

## Decision contract

The output is hs02.current-use-gateway-candidate.v1: a scoped immutable HOLD record with specific purpose and stage, current authenticated reference lineage digest, historical basis digest, review-at, explicit missing original byte/terms/retention/redaction decision markers, and fixed false flags for historical analysis, byte read, provider disclosure, model acceptance and customer release. Its canonical digest is integrity only; not a cryptographic signature or adoption authority. A caller cannot change an admission flag then recompute the hash to create permission.

The gateway contains no direct SQL, no source fetch, no bytes, no SMTP, no provider calls, no source-body metadata and no new right to approve. The old HistoryReviewContext, ContextEnvelope, provider executor and scheduler retain their current fail-closed states. Owner-native positive tests use a real disposable MariaDB test scope and seeded P01/M05 capture references. In the positive test current owner scope proves coherent registration, but U03 still returns HOLD; negative tests cover foreign identity, revoked card, truncated refs, purpose aliasing, fake allow flags and subsequent stage revocation. This tests meaningful current-owner verification without pretending a coder can ratify U03.

## Remaining admission and verification

The six canonical U03 owner decisions, M02/P01/M05/M10/Ops rights, permitted historical retention, host/data location and external provider-disclosure terms remain outside this package. U06 separately governs real model execution, request inspection, post-return and release. Later packages may connect this read-only HOLD seam to a reviewed factory only after the canonical contract is genuinely ratified and the exact current owner source/rights/version are retained. No U03 grant, production migration, paid inference, source acquisition, M17 memory admission, notification, customer output or Railway/main deployment is created here. Full RT-01/RT-02 and V01-V11 qualification remain separate.
