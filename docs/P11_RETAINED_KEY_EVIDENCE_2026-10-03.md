# P11 retained-key recovery observation — 3 October 2026

The new credential-containing encrypted backup was restored to a separate isolated local target. The actual restored v1 credential authenticated with the retained v1 key and matched its keyed digest; a newly generated in-memory v2-only keyring refused the same row with `CREDENTIAL_KEY_VERSION_UNAVAILABLE`. The row digest and rotation-event count were unchanged, the read-only transaction was rolled back, and no provider call or credential rewrap occurred.

The [sanitized supplemental artifact](deployment-evidence/2026-10-03/2ada973c/p11-retained-key-condition.json) binds the source observations by SHA-256. This is supplemental evidence; it does not invent a `KEY_ROTATION` registry kind or change the 21 existing registry records. No runtime source, canonical pin, permission, grant or activation setting is changed by this filing.

The source remained exact staging release `2ada973c520a32a2a4d03e79d9c1e3a55b10ee6a`, fingerprint `6460ca1b44ab3147966037163e27e4f2c7e2e6a961cf06c4ec9ee0cdb084a21b`. The new encrypted archive SHA-256 is `c45d4f58f6abc020e174d3d87fc238ddc461f6e71d942dd255a5dbe7b79e848b`. The exact restore verifier passed **219 tables, 2 artifacts, 0 M10 streams, 0 Monitor runs and 0 retained outputs rendered**. This proves the credential recovery condition, not populated ROAD_TEST history recovery.

The initial local target startup failed on namespace metadata access and was completed through a separately reviewed helper. The first restore attempt produced no retained verdict; its shim had a CRLF shebang. A separate read-only reconciliation found zero target objects and no temporary work directory. The explicitly reviewed second attempt used a new LF-only executable shim and distinct attempt paths. Both failures remain recorded; neither is converted into a success.

Staging left BACKUP maintenance at **19:16:09 UTC**, with maintenance and backup operation OPEN, unchanged exact release, all five inert flags false, ten execution counters zero, and zero current/future admitted grants. Temporary plaintext cleanup was confirmed. The first P10 archive and target remain intact; that first snapshot still has zero provider credential rows.

Full live master-key rotation, credential rewrap, historical grant rebinding, a current-release rollback, real model evaluation, ROAD_TEST_001, customer visibility, external action and production remain unproved or unauthorized by this observation. The retained-key condition is a narrow part of the P11 runbook, not a full rotation PASS.
