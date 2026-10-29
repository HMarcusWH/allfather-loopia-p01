# AN-02 — Immutable historical analyst/critic journal and deterministic replay

**Manifest:** BUILD-21 / AN-02, Wave 09, source LI-11. Dependencies AN-01, TM-02 and VA-01 have merged in the isolated build train. **Owner gates U03/U06 are NOT ratified.** This package is a complete *source-only local synthetic implementation candidate*, not a paid historical model service or finished MCM port.

## Separate, immutable historical review lifecycle

AN-02 does **not** alter a terminal P01 Monitor run. It creates a distinct versioned HistoryReviewRun.v1 identity binding P01 tenant/workspace/competitor/question, AN-01 exact request and basis digests, TM-02 P01 M17 v2 run digest, known-at cutoff, and two explicit human review boundaries. No memory ID can substitute for source evidence. A dated point-in-time selection from HS-01 remains necessary. An incomplete basis enters INSUFFICIENT_EVIDENCE_HOLD with no invented M17 memory. Original M05 source-use rights are still unadmitted.

The deterministic local state machine transitions REQUEST_INSPECTION_REQUIRED → ANALYST_READY → CRITIC_REQUIRED → HUMAN_REVIEW_REQUIRED for a code-generated synthetic success path. The first stage records an inspection in the **local fixture only**; it is not an M02 human approval and cannot authorize the provider. Other explicit outcomes are NO_NEW_INSIGHT, REVISE_HOLD, BLOCK_HOLD, INSUFFICIENT_EVIDENCE_HOLD and UNKNOWN_OUTCOME_HOLD. Terminal states have no automatic path to factual release or routing. There is no new scheduler, ModelExecutor purpose, credential, provider API or paid retry.

## Closed role contracts, genuine contrary refs and uncertainty

HistoryReviewCandidateChecks implements separate immutable HISTORICAL_ANALYST and HISTORICAL_CRITIC contracts, with the inactive history-review-v1.json bundle containing output schemas and role instructions. It is **not** registered with PromptBundleRegistry. Mechanical validation binds both roles to the frozen request, selected primary reference IDs and M17 candidate, requires all selected contrary references to remain visible, and rejects invented support IDs or unsupported roles. Analyst outputs include observed sequence, supporting/contrary refs, a descriptive HYPOTHESIS_FOR_HUMAN or NO_NEW_INSIGHT/INSUFFICIENT_EVIDENCE/ABSTAIN status, alternative explanations (including routine maintenance and noncausal correlation), explicit coverage/cause/owner limitations, and next checks. Text from HTML, JSON-LD, prompt injection and model responses never becomes a privileged role.

Critic challenges are scoped and digest-bound to the exact analyst artifact. PASS_TO_HUMAN requires limitations and points only to HUMAN_REVIEW_REQUIRED. A second model-like role is *not* an independent empirically corroborating source, and not either human reviewer. These validators check code identity, reference set and declared limitations; they cannot prove natural-language claim truth, current legal source reuse, causal diagnosis or business materiality.

## Family 320 synthetic journal, recovery and idempotency

The candidate 320 proposal is three tables (history_review_runs, history_review_events, history_review_artifact_refs) with six append-only UPDATE/DELETE triggers. It stays under docs/longitudinal/proposals, never database/migrations. HistoryReviewJournal is CLI/disposable-schema only; forRuntime refuses unconditionally. All run/source/question scope checks include native tenant/workspace/competitor membership in a **disposable** database. No native P01 Monitor table or primary M05 evidence changes.

Every stage serializes under the immutable run-row lock, rechecks the complete prior event hash/state/sequence chain and each retained artifact, then **persists the analyst/critic/reconciliation metadata artifact before the event cursor in one transaction**. A lost process cannot make the state advance without a retained result; a no-write exact replay has matching timestamp, event and canonical artifact payload. A conflicting same-stage replay or request digest is a HOLD; an unknown provider outcome never causes another model call, fabricated bytes or refunded usage. Replay returns bounded metadata only (safe codes/digests/status), not candidate prose, source body, provider secrets or release permission.

The two human boundaries remain separately closed: (1) evidence/factual/product release, and (2) routing/delivery to recipients. Both must later be checked by their existing M02/M10/M16/M12 owners. Nothing in this source-only mock claims a human performed either.

## Operator surfaces and test evidence

ops/history/review-candidate.php and replay-candidate.php refuse HTTP before autoload. Only --status/--help and explicitly switched ALLFATHER_AN02_SYNTHETIC_TEST=1 local fixed-fixture previews exist. Both have no arbitrary actor, file path, tenant, URL, provider or mutation option. The fixed mock under tests/support is deliberately not PSR-4-loadable by live factories.

Focused unit tests run real HS-01/AN-01/TM-01 source contracts through request inspection → analyst → critic → HUMAN_REVIEW_REQUIRED; NO_NEW_INSIGHT, REVISE, BLOCK, insufficient coverage, unknown-outcome and tampered role/contra examples refuse or HOLD. Disposable MariaDB 10.6/10.11 tests exercise append-only journal and artifact-before-cursor persistence, replay/no-write, scoped read refusal, unknown recovery, and absence of live migration. Separate security/foundation checks prove no live PromptOps role, source egress, self-approved human or customer release.

**Deferred:** U03 historical source rights and U06 manual provider delegation, evaluated prompt/model quality, actual M01 artifact retention, human factual/release decisions, real retry recovery, native schema/host migration, RT-01/RT-02 integration and post-build V01–V11. A passing source CI is only a build gate, not a production or customer GO.
