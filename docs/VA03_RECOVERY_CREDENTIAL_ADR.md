# BUILD-27 / VA-03 — Recovery-manifest and credential-operability candidate

Dependencies TM-02, AN-04, AQ-01, AQ-02 and HS-02 merged in isolated integration. P10 BackupManifest v1, MaintenanceGate, P11 KeyRing and R5 read-only Railway collector are reused. A strictly additive P10 static inspection seam checks the candidate family source hashes; it does **not** alter the v1 backup format, restore scripts or application maintenance semantics.

`HistoryRecoveryManifest` introduces the FullRestoreManifest.vNext-candidate contract: twelve complete candidate families (260,265,270–350), exact source release/tree and image identities, knowledge cutoff, M17 review/journal/attempt references, private M05/artifact/request-log hashes, original P10 metadata and separate age/P11 key identity. Offline verification compares source hashes to actual repository files. Fixed CLI-only private fixture verifies content-addressed bytes under strict POSIX private permissions; runtime private-data read refuses.

`HistoryRecoveryPreflight` inspects synthetic maintenance/writer stand-down posture, R5 metadata-only backup listings, three independent restore levels and credential cutovers. It always returns NO_RESTORE_NOT_HOST_VERIFIED; any purported restored volume, validated R5 pair, changed database password, rewrapped P11 ciphertext or migrated schema is rejected. `HistoryCredentialCutover` records independent non-executed cutovers for P11, initialized SQL account, backup access and age recovery identity. A new environment variable does not rotate a live SQL account.

Source fixtures and negative tests verify wrong cutoff, altered SQL bytes, absent/tampered private blob, public secret leakage, missing artifact kinds, rehashed restore permission, unsupported POSIX ACL claims, DB no-write and forbidden operator actions. P10/P11 real host acceptance and V01–V11 remain separate.

**BUILT_INERT / NO_RESTORE / NO_REAL_HOST_PROOF.** No Railway, Loopia, migration, data, credential, source/provider or customer mutation.
