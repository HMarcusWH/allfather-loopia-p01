# VA-01 — Offline historical-sequence evaluation and failure-analysis protocol

**Package:** BUILD-09 / VA-01, Wave 03, prepared 2026-10-29.
**Dependencies:** VA-00, OB-01, BF-01. **Source trace:** LI-18, LI-02, R14.
**Authority:** BUILT_INERT. U01/U06 remain unadopted; no funded execution, model quality approval or release.

## Actual positive path

HistoricalSequenceEvaluationRunner implements a deterministic offline
EvaluationHarness.v1 against source-verified VA-00 development material.
It scores 15 publicly supplied historical DEVELOPMENT sequences under each
of the two VA-00 frozen baseline names, producing **30 explicit case records**.
SUBMITTED, ABSTAINED, REFUSED, INVALID and NOT_RUN all remain in denominators.
A missing response creates NOT_RUN, never an implicit pass or omitted case.
The old DEV7 Monitor evaluation and 18-case Monitor split remain unchanged.

The new versioned input is
tests/fixtures/longitudinal/development/va01_sequence_responses_v1.json.
All responses in it were constructed from **PUBLIC DEVELOPMENT EXPECTATIONS**
for a mechanical smoke test; they are NOT outputs of the historical baseline
runtimes, actual trained models, or independently heldout oracles. Some deliberately
overcount duplicate events or include a later-known observation, to verify
failure detection. The runner records these synthetic diagnostics but never
claims observed baseline model performance or actual critical incidents.

The test CLI command is:

    php ops/evaluation/history.php --offline-development

It prints machine-inspectable JSON, no external calls, credentials, database
or customer output. The input is retrieved through a CLI-only
HistoricalSequenceFixtureTransport with exactly one readback and zero network
attempts. Other CLI commands besides --help and --status fail closed.

The report is HistoricalSequenceReport.v1, with exact VA-00 manifest
canonical digest, 15-case development SHA-256, heldout manifest digest,
local response fixture byte SHA-256, report digest and all 30 attempt digests.

## Denominators and independent quality

Both baselines have 15 source cases, grouped by lineage_rights_time,
quiet_noise_coverage, origin_dedup, contradiction_injection and
sequence_backpressure, with named failure codes and explicit counts for
eligible/blocked/future-known source refs, unique expected event labels,
candidate origin-group labels, refusals, abstentions and missing responses.
The source eligibility and unique-event label exactness rates use a visible
15-case denominator and are marked PUBLIC_DEV_FIXTURE_CASE_EXACTNESS,
not authenticated model source coverage.

VA-00's seven metric IDs (source_coverage, unique_events, origin_independence,
due_review, alert_quality, grounding and usefulness) are preserved.
Independent origin corroboration, due-review correctness, alert quality,
human grounding, human usefulness and ALL authoritative rates remain NULL.
ZERO human assessor denominators yield NULL, never a perfect rate.
A source-origin group name is not independently authenticated provenance.
Both baselines are **fixture comparison labels only**: EXISTING_PER_DIFF_V1
is not executed here and FIXED_WEEKLY_UTC_CANDIDATE_V1 does not schedule
reviews or grant a seven-day policy. No model attempt is performed.

Provider model attempts and network calls are observed ZERO.
Provider input/output token counts and cost are UNKNOWN / NULL,
not invented or estimated from strings. No human PASS or confidence band
is fabricated. Commercial significance remains NOT_ASSESSED.
Source rights, customer release, model funding, notifications, M10 action
and source re-use authority are explicitly false.

## Heldout and old-evidence freeze

The six longitudinal HELD_OUT slots are RESERVED_NOT_FROZEN_NOT_RUN.
They have no payload, oracle, independent custodian, accepted human assessor
or unblinding. A request naming a heldout ID refuses; no model output,
mechanical pass or heldout rate is generated. Overall model quality is
always INCOMPLETE_UNFROZEN_HELDOUT, regardless of development fixture echo.

The VA-00 pinned historical development file remains SHA-256:
52bc6093d70dcbcd7dd8f37115609d361d6564dd46d9e969ec2d9113ca1a550b.
The heldout registry stays:
dc70e10438f6ffdf3acba6f1eba77e2aaa49f2e8e1682890b09aa44f0f1995f8.
The older 18-case Monitor file stays:
cd21a4224b4b1c54f4378657b1950d5d399a0a743738739799f2341eeddf7c70.
Historical DEV7 5/7 development evidence and independence/quality gaps are
unmodified; no retroactive new evaluation has been asserted.

## Future funded path — explicitly disabled

HistoricalSequenceModelExecutionPort names a future typed funded-model
evaluation seam. DisabledHistoricalSequenceModelPort is the only implementation
in VA-01. It ALWAYS refuses VA01_FUNDED_EXECUTION_U01_U06_NOT_ADOPTED,
even when passed forged U01/U06 approvals, a fake MODEL_EVALUATION grant or
a purported heldout payload. No ModelExecutor, provider transport, M06/M01
call or paid request can be constructed by this source-build candidate.

Any later implementation requires a separate reviewed owner decision,
current M02/M03/M06/M01 grant, model/budget source-use approvals, frozen
prompt/schema, independent heldout custody and authenticated human scoring.
This is a Phase V/V05 activity after integration freeze, not part of VA-01
source CI. No production, main/Railway, host or new schema effects.

## Focused checks

    php tests/run.php --layer=unit --filter=va01_historical_sequence_runner --strict
    php tests/run.php --layer=security --filter=va01_historical_sequence_refusals --strict
    php tests/run.php --layer=foundation --filter=va01_evaluation_boundary --strict
    php ops/evaluation/history.php --status
    php ops/evaluation/history.php --offline-development

A merge requires all five standard exact-head CI jobs SUCCESS on real
MariaDB 10.6 and 10.11, with zero failures or skipped assertions.
This is build hygiene, not V05, production GO or customer acceptance.
