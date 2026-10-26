# LI-06A — offline deterministic M17 temporal candidate contracts

**Prepared:** 2026-10-26. **Status:** `PURE / OFFLINE / NOT_PERSISTED / NOT_ADMITTED`.
**Authority:** M17 canonical *architecture* (`1169428f...`), not runtime implementation approval. Canonical decision **U04** remains pending in [upstream draft PR #378](https://github.com/HMarcusWH/Allfatherbusinessintelligence/pull/378). The architecture is **ARCHITECTURE_SPEC_ONLY** and cannot grant implementation, production or source-use rights. This PHP work is a constrained experimental precursor, not M17 parity, approval or a complete G21 port.

**Verified source contracts:**

- Canonical `temporal_intelligence_compression_architecture.md` Git blob `271e3226dbb359f4fbfb8fe2c6179cec91d02c28` §§1,4–6.
- Canonical `temporal_intelligence_compression_interface_contracts.md` blob `9e57eaef5a88c90451f13cf4ba7fe6afe5a922f5` §§1–3.
- Canonical ADR blob `8daec8d855eb63c0c3fee03017dd7cb8bdefc6fe` TMP-DEC-002..012, and proposal-only PHP work package LI-06 (2026-10-25).
- Canonical U04 decision in PR #378 is **OPEN/DRAFT**. No new global authority pin or product gate changes were made.

## Scope, no-collapse and prerequisites

The five detached classes in `app/Substrate/M17/` are pure functions over **caller-supplied metadata** only:

| Class | Narrow contract | Claim ceiling |
| --- | --- | --- |
| `Vocabulary` | Closed types for the offline P01 `SOURCE_DIFF` and `COMPANY_SIGNAL` classes; other M17 classes recognized as INERT, all unknowns conservative | No source owner, M13 modality, P02 twin or business event identity |
| `InputSet` | Closed refs, observed UTC clock, exact payload SHA-256 and integer magnitude; tenant/workspace/competitor/question scope; canonical digest, ordering and explicit duplicates refusal | No M05 read, permission, authentic provenance or reuse |
| `WindowEngine` | DAY/WEEK/MONTH only, ISO Monday and month boundaries; half-open UTC output from UTC or explicitly declared Stockholm calendar; 14/90 and 30/180 ladder proposals | Not a capture/review/notification cadence, scheduler or event-level hold implementer |
| `ProfileResolver` | Deterministic narrow-only candidate fold, provenance per field, same-level narrowest conflict, recorded widenings, applied law-floor **candidates** and stable digest | No verified M13/owner policy, legal entitlement, customer output or production approval |
| `DynamicMeasures` | Exact capture density denominator, structural magnitude/milli variance rational, contradiction and high-diagnosticity refs, unknown business importance | No commercial materiality, independent event count, source authority or LLM inference |

All classes require **synthetic or independently lawfully supplied data**. `rights_disposition=NOT_ASSESSED`, `profile_authority_adopted=false` and `historical_reuse_admitted=false` are never switched by a profile, digest or measure. M17 memory references cannot replace M05 evidence, P02 state, M10 decisions, M16 release artifacts or an owner approval. **No migration**, model call, source crawl, HTTP route, schedule, deletion, output, notification, customer surface or new execution grant is installed. Existing four reviewed owner decisions stay byte-for-byte unchanged.

`LI-05A` point-in-time basis has only offline, as-of metadata semantics, not currently authorized historical reuse. `LI-06A` does **not** attach itself to `ContextEnvelope`, `ModelContextAuthority`, `MonitorService`, P01 capture/reader, Railway cron or M13. The next full LI-06/07 wiring requires U04 and selectively adopted contracts; LI-05B, historical use, requires U03/U06 and current M02/M05 rights checks. The existence of a library PHP class in an inert staging source deployment does not mean the module is admitted at runtime.

## Calendars and resolution

- Accepted window kinds are `DAY`, `WEEK` and `MONTH`; other canonical kinds (`HOUR`, `QUARTER`, `ROLLING`, fiscal, event-bounded, etc.) **refuse** instead of being guessed. Bounds are `[start_inclusive_utc, end_exclusive_utc)`, based on UTC capture/observation clocks, not an unverified claimed event date.
- `calendar_config=null` explicitly falls back to UTC+ISO. In the offline precursor, a declared calendar is limited to `UTC` or `Europe/Stockholm` and must carry the **exact host** `timezone_version_get()` value. Config is marked `CALLER_DECLARED_NOT_M13_VERIFIED`; no M13 tenant calendar is consulted. UTC boundaries and start/end zone offsets are frozen in the output alongside the source calendar and tzdb identifier. Stockholm 2026 spring/fall local days are respectively **23h**/**25h**, not an assumed 86,400 seconds.
- **Important host caveat:** PHP/system tzdb may report `0.system`, which is *not a unique immutable fingerprint of timezone rules*. Recorded UTC interval endpoints and offsets can be replayed/compared, but a later different OS tzdata under the same `0.system` label may alter new calculations. No cross-host parity guarantee is claimed without pinning the underlying zoneinfo artifact and adopting a calendar owner.
- A fixed explicit evaluation clock governs candidates. Proposed `SOURCE_DIFF` has DAY `<14 days`, WEEK from day 14 through day 89, MONTH from day 90; `COMPANY_SIGNAL` DAY `<30`, WEEK through day 179, MONTH from day 180. These are the **canonical architecture's distinct proposed defaults** (not a universal 14/90 law). HIGH or UNKNOWN *caller-described* volatility conservatively holds DAY detail; the numeric threshold/hold period must be adopted in U04 before any operational use. Age is counted in UTC 86,400-second days; bucket assignment uses declared local calendar boundaries and therefore may have different UTC durations.
- A legal/audit/other `FORCE_EVENT_LEVEL` floor makes `choose()` **refuse** with `M17_HOLD_REQUIRES_UNIMPLEMENTED_EVENT_WINDOW` rather than converting the held events to day/week/month. No retrospective re-bucketing of an old digest is performed. Any new input, calendar or profile is a **successor candidate**, not an edit to existing memory.

## Profile candidate narrowing and floors

`ProfileResolver::resolve` takes exact `scope`, `data_class`, `evaluation_time_utc`, `candidates[]`, `floors[]`. All profiles and floors are *unverified supplied descriptions*, never authoritative code/config grants. The supported candidate levels are `TENANT`, `WORKSPACE`, `DATA_CLASS_OVERRIDE`, `RISK_OVERRIDE`, `OPERATOR_OVERRIDE`; `LOCKED_LAW`/`MODULE_DEFAULT` are not caller-definable and `MODALITY_DEFAULT`, `ENTITY` and `SOURCE_CLASS_OVERRIDE` remain inert until their owners exist. All candidate scope keys must exactly match one declared synthetic P01 scope. Operator override expires at a strict UTC time and needs a safe reason. Risk override also needs a reason; missing metadata refuses.

Resolution is field-wise and deterministic: **larger DAY/WEEK hold thresholds**, longer *retention expectations*, full input lineage and lower aggressiveness are narrower. Attempted widening is retained as `PROFILE_WIDENING_REJECTED` (not silently applied). Two active same-level candidates with differing values cause a `NARROWEST_WINS` conflict, regardless of caller order. Law-floor candidates are applied **last**, and are recorded with their safe basis refs; allowed effects are explicitly paired to floor classes. Unrecognized combinations refuse. Legal/audit hold preserves event-level detail, privacy retention applies a numeric minimum, evidence floors enforce `EVERY_INPUT_REF`, source-use/customer/isolation `FORBID` can **never** create permission. Neither `retention_expectation_days` nor a resolved resolution is a deletion instruction.

Fixed output ceilings: `release_eligible=false`, `customer_output_eligible=false`, `conflict_behavior=ITEMIZE_ALWAYS`, `summary_shape=STATS_ONLY`. Unknown/inert classes return `CONSERVATIVE_DEFAULT_NOT_EXECUTABLE`: full lineage, aggressiveness `NONE` and no windows or profile override, **not** a proposal to process another data class. The entire value is hash-bound by the existing `Digest::canonicalDigest` law; valid digest means *reproducibility*, never trust in caller or legal floors. There is no digital signature or checked right.

## Integer dynamic measures and caveats

Input reference magnitudes are already-supplied nonnegative **milli-units** (0..1,000,000) or `null`. These units are **offline proposal data**, not adopted U04 numeric semantics. The measures output avoids floats: capture density `(count, window_duration_seconds)`, mean structural magnitude `(sum_milli, known_magnitude_count)`, and variance numerator = `n*sum(x²)-(sum x)²`, denominator = `n²`. With fewer than two known magnitudes variance is `null`. Missing magnitude/importance is explicitly unknown, never silently zero.

Importance is independently `UNKNOWN` by default. If *both* unverified weight inputs are provided as integer basis points, the candidate product is `intdiv(profile_bp * role_bp, 10000)` and remains labelled `CALLER_SUPPLIED_WEIGHTS_UNVERIFIED`; it is **not** a strategic/business materiality finding. Contrary/conflict refs and diagnostic refs are itemized in the result and do not disappear inside averages. Equal SHA-256s are **exact byte classes only**. The derived `distinct_payload_hashes` is not a common-origin proof, and `independent_event_count` is always `null` even with many captures.

`InputSet` rows contain no raw source text, URL, prompt, provider token, release candidate or customer description. An input set never triggers an M05 read. Caps: 256 refs; 48 candidate profiles and 24 law-floor descriptions; identity, UTC, closed shape and numeric range violations refuse. `DynamicMeasures` outputs all in-window reference IDs (maximum 256) so no rare contradictory/high-diagnosticity item can be silently discarded by its aggregation. Any actual compression/release remains separately owner-gated.

## Test and acceptance evidence

`tests/fixtures/temporal_contract_vectors.json` contains *synthetic* identities, UTC/DST/window boundaries and count/variance vectors. Unit/conformance/foundation cases exercise DST and leap-year month ends, ISO week/year rollover, 14/90 vs 30/180, unknown data class, unsupported kind, stale/expired override, cross-tenant reference bounds, widening refusal, same-level conflict, floor precedence, injected source text, duplicate identity, integer variance and itemized contradictory observations. Hosted code qualification still requires complete strict MariaDB 10.6 and 10.11 (zero failures/skips), Node/frontend and exact prepared-package/rsync rehearsal.

The source canonical implementation does not exist: **reference vectors here test an offline PHP interpretation**, not runtime parity with TypeScript M17 or an approved numerical/calendrical contract. U04 is the blocking decision before any M17 G21 migration, lineage/rollup store, live selector, due queue or customer-facing output. U03/U06 are additionally required for historical evidence reuse/model analysis.
