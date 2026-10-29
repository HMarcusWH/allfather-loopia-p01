# BUILD-11 / HS-01 — source-only completion receipt

**Prepared:** 2026-10-29. **Base:** integration e1a20515d14ddeab3ef428685be55bdc6be8ce64. **Status:** BUILT_INERT candidate. GitHub CI, PR merge and exact tree evidence must be reported from the actual GitHub run, not inferred from this file.

**Delivered:** PointInTimeHistoricalBasis.v1 metadata composer (precise event/observed/recorded/known/review clocks, UTC half-open windows, input-specific successors, contrary-first capped selection, per-reference countable omissions, differentiated no-input/late-known/missing-bytes dispositions, immutable digest). Legacy HistoryReviewBasis/HistoricalEvidenceSelector remain unchanged.

**Candidate 270:** HistoryReviewBasisStore accepts either legacy manifest_digest or v2 basis_digest, stores selected v2 references and parent excluded/contrary metadata only in disposable TEST mode, and can replay both versions. Existing immutable SQL has not become a runtime migration. Candidate read model recognizes both digests. CLI status and guarded synthetic preview contain no source IDs, raw bytes, provider activity or owner authority.

**Tests supplied:** unit, security and disposable MariaDB 10.6/10.11 cases. **Still unadmitted:** U03 owner-approved historical source-use and disclosure, genuine origin independence, deployed feature migrations, HS-02 current rights, TM-01 temporal memory, RT-01/RT-02 integration, VA-03 populated recovery, V01–V11 final quality/host/production.
