# BUILD-28 / RT-01 — Source candidate receipt

Prepared 30 October 2026. SOURCE_CANDIDATE / CI_PENDING / NO_LIVE_MIGRATION.

Added exact candidate SQL / provisional registry comparison for 12 families, 38 physical tables, 76 append-only triggers, immutable 900 source digest and FK-safe order; packaged source manifest and fixture examples. Added guarded disposable-only DDL installer with partial failure HOLD, source-only migration-plan CLI, typed source graph composition and read-only runtime status. Unit/foundation/security and independent MariaDB 10.6/10.11 integration tests require exact positive/negative behavior.

No existing migration/900 bytes edited, no native migration appended, no production DB touched, no M02/M05/M07/M10/M16 source/provider/rights, no Railway/main/customer activation. RT-02 final build freeze and V01–V11 remain future work. This receipt is not a hosted CI PASS until its exact tested tree completes.
