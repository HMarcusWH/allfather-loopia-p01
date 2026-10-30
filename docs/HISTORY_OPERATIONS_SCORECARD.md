# VA-02 — Historical intelligence assurance scorecard

Prepared 30 October 2026. This is a source-level M11 candidate, not an operational host audit, business adjudication, provider telemetry or recovery proof.

## Observed versus unknown
- **Lineage:** exact AN-03 historical candidate, IV-01 dependency graph, AN-04 later outcome and AQ-02 attention in one tenant/workspace/competitor/question. Counts and timestamps are verified candidate metadata, not independent M05 source authentication or business outcomes.
- **Queue:** only a verified AQ-01 queue can supply pending count and oldest pending age; without owner source these are null (UNKNOWN), not zero.
- **Coverage:** planned and reported slots with a numeric gap only from an explicitly bounded fixture. Absent P07 evidence means null gap and COVERAGE_NOT_VERIFIED.
- **Provider budget:** UNKNOWN_PROVIDER_OUTCOME returns reserved, spent and unknown-attempt counts as null. Unknown is never zero, refund, completion or spending permission. Numeric FIXTURE_ACCOUNTED_ONLY fields do not describe a real provider.
- **Source rights/redaction:** U03 historical reuse remains NOT_ADOPTED. Suppression is a reason for internal review, not customer delivery.
- **Recovery:** NOT_VERIFIED_HOST until a separate host proof; source CI does not establish physical volume restore, private M05 bytes, source image identity or P11 key rotation.
- **Incidents:** fixed reason codes (SOURCE_USE_NOT_ADMITTED, PROVIDER_COST_UNKNOWN_HOLD, COVERAGE_NOT_VERIFIED, HOST_RECOVERY_NOT_VERIFIED). Actual M11 incident written is false. The real M11 owner remains authoritative.

## Safe operator commands
- php ops/assurance/history.php --status
- php ops/assurance/temporal.php --status
- --synthetic-development requires CLI and ALLFATHER_VA02_SYNTHETIC_TEST=1; fixture-only metadata is safe and contains no private tenant selector, source body or credential.

Both source contracts verify exact hashes, UTC time and false native-authority fields. They never mutate existing M11, M10 or M16 tables.

## Deferred
Host/operator verification, M11 real case admission, P07 coverage, provider accounting, current source rights, private recovery, RT-01/RT-02 and V01–V11; no automatic Railway/main, migration, customer or production change.
