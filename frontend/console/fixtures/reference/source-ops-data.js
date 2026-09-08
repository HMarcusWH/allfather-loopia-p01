/* Allfather BI — SOURCE OPERATIONS fixtures: GSMC (governed source monitoring)
   and M15 (governed ingestion). Two DISTINCT owners that sit next to each other
   in navigation and never merge:

     GSMC owns monitoring configuration, cadence, trigger evaluation, findings and
          the live-finding review lifecycle.
     M15  owns the governed intake transformation chain
          source policy → fetch plan → capture → evidence → normalization →
          candidate → entity resolution → consumer admission.

   Vocabulary is verbatim from the live substrate:
     gsmc/source-monitoring-config.ts  — GSMC_TRIGGER_KINDS, GsmcAutonomyMode,
                                         GSMC_FETCH_CADENCE_FLOOR_MS, config/version status
     gsmc/live-operator-cockpit.ts     — route summary + review statuses
     gsmc/live-operator-surface.ts     — the five closed read commands
     ingestion/vocabulary.ts           — ConnectorClass, CandidateType, CandidateStatus,
                                         ExtractionMethod, SourceAuthorityAssessment,
                                         ConfidenceBand, InterpretationKind
     ingestion/admission.ts            — AdmissionConsumer + the closed outcome union */
window.AFB = window.AFB || {};

/* ═══════════════ GSMC · governed source monitoring ═══════════════ */
window.AFB.gsmc = {
  /* Environment state — NOT the permanent product structure. The intended MVP
     operating configuration is what the surface below describes; this strip says
     what is switched on in THIS environment right now. */
  env: {
    liveReadActivated: false,
    captureClass: "SYNTHETIC_TEST_FIXTURE",
    registeredClass: "PUBLIC_WEB_PAGE",
    note: "Monitoring configuration, cadence and review lifecycle are admitted and operating on fixture-class captures. Live public-web read is registered and NOT activated in this environment; activation is a separate owner act, not a console act.",
  },

  /* gsmc.liveReview.cockpit.snapshot — the counts the operator scans first. */
  snapshot: {
    openCount: 3, acknowledgedCount: 2, dismissedCount: 1, resolvedCount: 6,
    reviewDueCount: 2, totalLiveRoutes: 12, totalMatchedLiveEvaluationsPromoted: 27,
  },

  /* The five commands this surface may issue. Two append an audit artifact; none
     fetches, dispatches, schedules or sends. */
  commands: [
    { name: "gsmc.liveReview.cockpit.snapshot", mutation: false, label: "Cockpit snapshot" },
    { name: "gsmc.liveReview.route.detail", mutation: false, label: "Route detail" },
    { name: "gsmc.liveReview.due.list", mutation: false, label: "Due findings" },
    { name: "gsmc.liveReview.audit.build", mutation: "APPEND_ONLY_AUDIT_PACKAGE", label: "Build audit package" },
    { name: "gsmc.liveReview.exportManifest.build", mutation: "APPEND_ONLY_EXPORT_MANIFEST", label: "Build export manifest" },
  ],

  /* Configured sources. `health` is operational state; `rights` is the source
     access policy verdict; both are shown, never conflated. */
  sources: [
    {
      id: "SRC-0114", ref: "boreal.se/pricing", entity: "Boreal Systems AB", kind: "Pricing page",
      health: "MONITORING", tone: "green",
      configId: "SMC-0114", versionId: "SMCV-0114-3", version: 3, configStatus: "ACTIVE", versionStatus: "ADMITTED",
      autonomy: { mode: "ASSISTED", ownerApprovalRequired: true },
      fetchCadenceMs: 3600000, reviewCadenceMs: 86400000, ownerReviewRequired: true, manualFetchAllowed: true,
      lastObserved: "2026-08-28 06:40", nextDue: "07:40", freshness: "FRESH",
      rights: "APPROVED", rightsNote: "Public web page · terms clear · rechecked 2026-08-21",
      triggers: [
        { kind: "CONTENT_HASH_CHANGED", detail: "hashAlgorithm sha256", fires: 4 },
        { kind: "FIELD_THRESHOLD", detail: "tier2.priceSek gte 5400", fires: 1 },
        { kind: "KEYWORD_MATCH", detail: "enterprise · usage-based · platform fee", fires: 2 },
      ],
      openRoutes: 1, dueRoutes: 1,
      uncertainty: "Page is server-rendered; a template change can register as a content-hash change without a commercial change.",
      authority: { proposal: "PRP-0388", verdict: "AV-0388", record: "DR-0061" },
      observations: [
        { id: "OBR-2291", at: "2026-08-28 06:40", outcome: "CAPTURED", matched: 2, selected: 2, note: "Content hash changed; two evaluations matched and were promoted." },
        { id: "OBR-2287", at: "2026-08-28 05:40", outcome: "NO_CHANGE", matched: 0, selected: 0, note: "Hash identical to the prior capture." },
        { id: "OBR-2281", at: "2026-08-28 04:40", outcome: "NO_CHANGE", matched: 0, selected: 0, note: "Hash identical to the prior capture." },
      ],
      history: [
        { version: 3, at: "2026-08-14", status: "ADMITTED", change: "Added the FIELD_THRESHOLD trigger on tier-2 list price.", record: "DR-0061" },
        { version: 2, at: "2026-07-30", status: "SUPERSEDED", change: "Fetch cadence 6h → 1h after two missed same-day changes.", record: "DR-0048" },
        { version: 1, at: "2026-07-11", status: "SUPERSEDED", change: "Initial admitted configuration — content-hash trigger only.", record: "DR-0031" },
      ],
    },
    {
      id: "SRC-0117", ref: "fjordr.io/launch", entity: "Fjordr", kind: "Launch page",
      health: "REVIEW_DUE", tone: "amber",
      configId: "SMC-0117", versionId: "SMCV-0117-1", version: 1, configStatus: "ACTIVE", versionStatus: "ADMITTED",
      autonomy: { mode: "ASSISTED", ownerApprovalRequired: true },
      fetchCadenceMs: 21600000, reviewCadenceMs: 86400000, ownerReviewRequired: true, manualFetchAllowed: true,
      lastObserved: "2026-08-27 22:10", nextDue: "overdue · 04:10", freshness: "WARN",
      rights: "APPROVED", rightsNote: "Public web page · terms clear",
      triggers: [
        { kind: "CONTENT_HASH_CHANGED", detail: "hashAlgorithm sha256", fires: 3 },
        { kind: "SIGNAL_CLASS_MATCH", detail: "signalClass POSITIONING_SHIFT", fires: 1 },
      ],
      openRoutes: 1, dueRoutes: 1,
      uncertainty: "Marketing copy changes frequently; only a claim-bearing change should reach review.",
      authority: { proposal: "PRP-0391", verdict: "AV-0391", record: "DR-0055" },
      observations: [
        { id: "OBR-2274", at: "2026-08-27 22:10", outcome: "CAPTURED", matched: 1, selected: 1, note: "Positioning claim changed; one evaluation promoted to review." },
        { id: "OBR-2260", at: "2026-08-27 16:10", outcome: "NO_CHANGE", matched: 0, selected: 0, note: "Hash identical to the prior capture." },
      ],
      history: [
        { version: 1, at: "2026-07-18", status: "ADMITTED", change: "Initial admitted configuration.", record: "DR-0055" },
      ],
    },
    {
      id: "SRC-0121", ref: "Börsdata · financials feed", entity: "Kvant Industri", kind: "Licensed feed",
      health: "DEGRADED", tone: "red",
      configId: "SMC-0121", versionId: "SMCV-0121-2", version: 2, configStatus: "ACTIVE", versionStatus: "ADMITTED",
      autonomy: { mode: "MANUAL" },
      fetchCadenceMs: 86400000, reviewCadenceMs: 604800000, ownerReviewRequired: true, manualFetchAllowed: false,
      lastObserved: "2026-08-25 03:00", nextDue: "held", freshness: "STALE",
      rights: "TERMS_RECHECK_OVERDUE", rightsNote: "Licensed feed · terms recheck lapsed 2026-08-19 · new admission holds until the recheck is recorded",
      triggers: [
        { kind: "FIELD_THRESHOLD", detail: "quarterly.revenueDeltaPct gte 8", fires: 0 },
        { kind: "ANY_NEW_ITEM", detail: "itemPath filings[]", fires: 1 },
      ],
      openRoutes: 1, dueRoutes: 0,
      uncertainty: "Feed schema changed upstream; the deterministic field map no longer resolves the revenue field.",
      authority: { proposal: "PRP-0362", verdict: "AV-0362", record: "DR-0040" },
      observations: [
        { id: "OBR-2248", at: "2026-08-25 03:00", outcome: "FETCH_FAILED", matched: 0, selected: 0, note: "Transport not activated in this environment; the fixture-class capture for this source is absent." },
        { id: "OBR-2231", at: "2026-08-24 03:00", outcome: "CAPTURED", matched: 1, selected: 0, note: "New filing item detected; normalization could not map the revenue field." },
      ],
      history: [
        { version: 2, at: "2026-08-02", status: "ADMITTED", change: "Cadence relaxed to daily after the licence review.", record: "DR-0040" },
        { version: 1, at: "2026-07-11", status: "SUPERSEDED", change: "Initial admitted configuration.", record: "DR-0029" },
      ],
    },
    {
      id: "SRC-0126", ref: "acme.com/news", entity: "Acme Logistics", kind: "Company updates",
      health: "MONITORING", tone: "green",
      configId: "SMC-0126", versionId: "SMCV-0126-1", version: 1, configStatus: "ACTIVE", versionStatus: "ADMITTED",
      autonomy: { mode: "ASSISTED", ownerApprovalRequired: true },
      fetchCadenceMs: 10800000, reviewCadenceMs: 172800000, ownerReviewRequired: true, manualFetchAllowed: true,
      lastObserved: "2026-08-28 05:15", nextDue: "08:15", freshness: "FRESH",
      rights: "APPROVED", rightsNote: "Public web page · terms clear",
      triggers: [
        { kind: "ANY_NEW_ITEM", detail: "itemPath updates[]", fires: 2 },
        { kind: "KEYWORD_MATCH", detail: "funding · procurement · tender", fires: 1 },
      ],
      openRoutes: 0, dueRoutes: 0,
      uncertainty: "A press item is evidence of an announcement, never of the underlying commercial fact.",
      authority: { proposal: "PRP-0396", verdict: "AV-0396", record: "DR-0058" },
      observations: [
        { id: "OBR-2289", at: "2026-08-28 05:15", outcome: "CAPTURED", matched: 1, selected: 1, note: "Funding announcement item detected and promoted." },
      ],
      history: [
        { version: 1, at: "2026-08-04", status: "ADMITTED", change: "Initial admitted configuration.", record: "DR-0058" },
      ],
    },
    {
      id: "SRC-0129", ref: "acme.com · procurement portal", entity: "Acme Logistics", kind: "Portal",
      health: "PAUSED", tone: "muted",
      configId: "SMC-0129", versionId: "SMCV-0129-1", version: 1, configStatus: "ACTIVE", versionStatus: "ADMITTED",
      autonomy: { mode: "MANUAL" },
      fetchCadenceMs: 86400000, reviewCadenceMs: 604800000, ownerReviewRequired: true, manualFetchAllowed: false,
      lastObserved: "—", nextDue: "held", freshness: "—",
      rights: "PENDING_REVIEW", rightsNote: "Access terms unknown · source access policy compiles BLOCKED until a terms review is recorded",
      triggers: [{ kind: "ANY_NEW_ITEM", detail: "itemPath tenders[]", fires: 0 }],
      openRoutes: 0, dueRoutes: 0,
      uncertainty: "No lawful basis recorded for monitoring this portal. Nothing has been captured.",
      authority: { proposal: "PRP-0397", verdict: "AV-0397", record: "DR-0059" },
      observations: [],
      history: [{ version: 1, at: "2026-08-04", status: "ADMITTED", change: "Configuration admitted with monitoring held pending the terms review.", record: "DR-0059" },
      ],
    },
  ],

  /* Live finding review routes — gsmc.liveReview.due.list / route.detail. */
  routes: [
    {
      id: "LFR-0912", sourceId: "SRC-0114", sourceRef: "boreal.se/pricing", entity: "Boreal Systems AB",
      status: "OPEN", severity: "HIGH", promotedAt: "2026-08-28 06:41", reviewDueAt: "2026-08-28 10:41",
      matchedCount: 2, selectedCount: 2, triggerKinds: ["CONTENT_HASH_CHANGED", "FIELD_THRESHOLD"],
      evidencePreview: "Tier-2 list price 4 900 kr → 5 490 kr · new usage-based add-on block present",
      lastDecisionKind: null, lastDecidedAt: null,
      packet: "PKT-0418",
    },
    {
      id: "LFR-0908", sourceId: "SRC-0117", sourceRef: "fjordr.io/launch", entity: "Fjordr",
      status: "ACKNOWLEDGED", severity: "MEDIUM", promotedAt: "2026-08-27 22:11", reviewDueAt: "2026-08-28 04:11",
      matchedCount: 1, selectedCount: 1, triggerKinds: ["SIGNAL_CLASS_MATCH"],
      evidencePreview: "Positioning claim “the simple way to ship” → “compliant by default”",
      lastDecisionKind: "ACKNOWLEDGE", lastDecidedAt: "2026-08-28 07:02",
      packet: "PKT-0411",
    },
    {
      id: "LFR-0903", sourceId: "SRC-0121", sourceRef: "Börsdata · financials feed", entity: "Kvant Industri",
      status: "OPEN", severity: "MEDIUM", promotedAt: "2026-08-24 03:04", reviewDueAt: "2026-08-31 03:04",
      matchedCount: 1, selectedCount: 0, triggerKinds: ["ANY_NEW_ITEM"],
      evidencePreview: "New filing item present · normalization could not map the revenue field",
      lastDecisionKind: null, lastDecidedAt: null,
      packet: null,
    },
    {
      id: "LFR-0899", sourceId: "SRC-0126", sourceRef: "acme.com/news", entity: "Acme Logistics",
      status: "OPEN", severity: "MEDIUM", promotedAt: "2026-08-28 05:16", reviewDueAt: "2026-08-30 05:16",
      matchedCount: 1, selectedCount: 1, triggerKinds: ["ANY_NEW_ITEM", "KEYWORD_MATCH"],
      evidencePreview: "Funding round announced · procurement initiative referenced without a primary source",
      lastDecisionKind: null, lastDecidedAt: null,
      packet: "PKT-0419",
    },
    {
      id: "LFR-0884", sourceId: "SRC-0114", sourceRef: "boreal.se/pricing", entity: "Boreal Systems AB",
      status: "RESOLVED", severity: "LOW", promotedAt: "2026-08-21 06:40", reviewDueAt: "2026-08-22 06:40",
      matchedCount: 1, selectedCount: 1, triggerKinds: ["CONTENT_HASH_CHANGED"],
      evidencePreview: "Footer template change only · no claim-bearing change",
      lastDecisionKind: "RESOLVE", lastDecidedAt: "2026-08-21 09:15",
      packet: null,
    },
    {
      id: "LFR-0877", sourceId: "SRC-0117", sourceRef: "fjordr.io/launch", entity: "Fjordr",
      status: "DISMISSED", severity: "LOW", promotedAt: "2026-08-19 16:10", reviewDueAt: "2026-08-20 16:10",
      matchedCount: 1, selectedCount: 0, triggerKinds: ["CONTENT_HASH_CHANGED"],
      evidencePreview: "Image asset swap · no textual claim change",
      lastDecisionKind: "DISMISS", lastDecidedAt: "2026-08-19 17:44",
      packet: null,
    },
  ],

  law: [
    "A trigger fire is an observation, not a finding. A finding is not a fact.",
    "Manual fetch is a stored permission on the configuration — the console never performs a fetch.",
    "Configuration is admitted through a proposal → verdict → decision-record chain; a change supersedes, it never edits.",
    "Cadence floor is 300 000 ms. A configuration below the floor is refused at admission, not warned about later.",
  ],
};

window.AFB.gsmcHealthTone = { MONITORING: "green", REVIEW_DUE: "amber", DEGRADED: "red", PAUSED: "muted" };
window.AFB.gsmcRightsTone = { APPROVED: "green", PENDING_REVIEW: "amber", TERMS_RECHECK_OVERDUE: "amber", BLOCKED: "red" };
window.AFB.gsmcRouteTone = { OPEN: "amber", ACKNOWLEDGED: "cyan", DISMISSED: "muted", RESOLVED: "green" };
window.AFB.gsmcObsTone = { CAPTURED: "green", NO_CHANGE: "muted", FETCH_FAILED: "red", POLICY_REFUSED: "red" };

/* ═══════════════ M15 · governed ingestion spine ═══════════════ */
window.AFB.m15 = {
  note: "M15 owns the intake transformation chain. It is a distinct owner from GSMC: GSMC decides WHEN and WHETHER to observe a source; M15 decides what an observation may lawfully become. Pipeline movement never makes anything true.",
  surfaceNote: "No canonical M12 read model exists for M15 yet (ING-Q-008). This surface reads the spine directly and says so.",

  /* The eight stages, in order. `count` is packets currently at that stage;
     `refused` is packets that terminated there. */
  stages: [
    { id: "policy", label: "Source policy", module: "M15 · policy.ts", count: 5, refused: 1, tone: "cyan",
      law: "compileSourcePolicy is pure and fail-closed. A source card gone blocked or expired holds new admission without rewriting the historical capture.",
      states: ["COMPILED_ALLOWED", "COMPILED_BLOCKED"] },
    { id: "plan", label: "Fetch plan", module: "M15 · fetch-plan.ts", count: 4, refused: 0, tone: "cyan",
      law: "A plan is an intention bounded by the compiled policy. A plan is not a fetch.",
      states: ["PLANNED", "REFUSED_BY_POLICY"] },
    { id: "capture", label: "Capture", module: "M15 · raw-capture.ts", count: 4, refused: 1, tone: "cyan",
      law: "A raw capture is bytes with provenance. A raw capture is not evidence.",
      states: ["CAPTURED", "FETCH_FAILED"] },
    { id: "evidence", label: "Evidence", module: "M05 · evidence spine", count: 4, refused: 0, tone: "cyan",
      law: "Evidence is an immutable safe reference plus a digest. Evidence is not a fact.",
      states: ["CAPTURED", "ASSESSED", "QUARANTINED"] },
    { id: "normalize", label: "Normalization", module: "M15 · normalize.ts", count: 4, refused: 1, tone: "violet",
      law: "Two deterministic normalizers ride a closed registry. Adding one is a versioned code change, never configuration. No model extraction in V1.",
      states: ["NORMALIZED", "NORMALIZER_UNMAPPED"] },
    { id: "candidate", label: "Candidate", module: "M15 · candidates.ts", count: 6, refused: 0, tone: "violet",
      law: "A candidate is a proposal about the world. A candidate is not admitted state.",
      states: ["NORMALIZED", "RESOLUTION_PENDING", "RESOLVED", "HELD"] },
    { id: "resolution", label: "Entity resolution", module: "M15 · entity-resolution.ts", count: 5, refused: 1, tone: "violet",
      law: "Four ordered deterministic strategies. AMBIGUOUS and UNRESOLVED are terminal for automation until an alias or registry change.",
      states: ["RESOLVED", "AMBIGUOUS", "UNRESOLVED"] },
    { id: "admission", label: "Consumer admission", module: "M15 · admission.ts", count: 4, refused: 1, tone: "gold",
      law: "Admission is routing, not judging. The consumer's own governance owns the object from the moment it is submitted.",
      states: ["SUBMITTED", "HELD", "CONSUMER_REFUSED", "ALREADY_ADMITTED"] },
  ],

  /* Recent packets — each is one candidate's actual path. Terminal refusals are
     named with their canonical reason code, never softened. */
  packets: [
    { id: "ICD-4471", type: "COMPANY_SIGNAL", status: "ADMITTED_TO_CONSUMER", consumer: "P01_COMPANY_SIGNAL",
      source: "boreal.se/pricing", connector: "PUBLIC_WEB_PAGE", authority: "PUBLIC_SOURCE",
      extraction: "DETERMINISTIC_DIFF", interpretation: "FACT_OBSERVED", confidence: "HIGH",
      stage: "admission", outcome: "SUBMITTED", tone: "green",
      detail: "Tier-2 list price change routed to P01 as a company signal. Consumer object CSG-0418 · proposal PRP-0418.",
      resolved: "Boreal Systems AB · COMPETITOR", packet: "PKT-0418" },
    { id: "ICD-4470", type: "COMPANY_SIGNAL", status: "ADMITTED_TO_CONSUMER", consumer: "P01_COMPANY_SIGNAL",
      source: "acme.com/news", connector: "PUBLIC_WEB_PAGE", authority: "PUBLIC_SOURCE",
      extraction: "DETERMINISTIC_PARSER", interpretation: "FACT_OBSERVED", confidence: "MEDIUM",
      stage: "admission", outcome: "SUBMITTED", tone: "green",
      detail: "Funding announcement routed to P01. The procurement inference was NOT admitted — it is a separate candidate held below.",
      resolved: "Acme Logistics · COMPETITOR", packet: "PKT-0419" },
    { id: "ICD-4469", type: "COMPANY_SIGNAL", status: "HELD", consumer: "P01_COMPANY_SIGNAL",
      source: "acme.com/news", connector: "PUBLIC_WEB_PAGE", authority: "PUBLIC_SOURCE",
      extraction: "OPERATOR_DECLARED", interpretation: "HYPOTHESIS", confidence: "LOW",
      stage: "admission", outcome: "HELD · CANDIDATE_EVIDENCE_UNUSABLE", tone: "amber",
      detail: "Procurement-initiative hypothesis. Its only linked evidence object was quarantined after normalization, so admission fails closed.",
      resolved: "Acme Logistics · COMPETITOR", packet: null },
    { id: "ICD-4468", type: "ENTITY_FACT", status: "ADMITTED_TO_CONSUMER", consumer: "P02_TWIN_STATE",
      source: "fjordr.io/launch", connector: "PUBLIC_WEB_PAGE", authority: "PUBLIC_SOURCE",
      extraction: "STRUCTURED_FIELD_MAP", interpretation: "FACT_OBSERVED", confidence: "MEDIUM",
      stage: "admission", outcome: "SUBMITTED", tone: "green",
      detail: "Positioning-claim fact routed to P02 as a twin state-change proposal. P02 governance owns it now.",
      resolved: "Fjordr · ENTITY_TWIN", packet: "PKT-0411" },
    { id: "ICD-4465", type: "RELATIONSHIP", status: "HELD",
      source: "acme.com/news", connector: "PUBLIC_WEB_PAGE", authority: "PUBLIC_SOURCE",
      extraction: "DETERMINISTIC_PARSER", interpretation: "FACT_OBSERVED", confidence: "MEDIUM",
      stage: "resolution", outcome: "HELD · ENTITY_NOT_RESOLVED", tone: "amber",
      detail: "Supplier relationship named a second party matching two registry twins. Both endpoints must resolve independently; AMBIGUOUS is terminal until an alias is curated.",
      resolved: "source RESOLVED · target AMBIGUOUS", packet: null },
    { id: "ICD-4463", type: "MARKET_CONTEXT", status: "HELD",
      source: "ec.europa.eu/data-act", connector: "PUBLIC_WEB_PAGE", authority: "PUBLIC_SOURCE",
      extraction: "DETERMINISTIC_PARSER", interpretation: "FACT_OBSERVED", confidence: "HIGH",
      stage: "admission", outcome: "HELD · NO_ADMITTED_CONSUMER", tone: "muted",
      detail: "Regulatory context has no admitted consumer in the MVP. It sits in an evidence-linked held queue and mutates nothing.",
      resolved: "—", packet: null },
    { id: "ICD-4461", type: "ENTITY_FACT", status: "REJECTED",
      source: "Börsdata · financials feed", connector: "JSON_API", authority: "LICENSED_SOURCE",
      extraction: "STRUCTURED_FIELD_MAP", interpretation: "FACT_OBSERVED", confidence: "NOT_QUANTIFIED",
      stage: "normalize", outcome: "REFUSED · NORMALIZER_UNMAPPED", tone: "red",
      detail: "Upstream schema change: the deterministic field map no longer resolves the revenue field. No candidate value was produced — nothing was guessed.",
      resolved: "—", packet: null },
    { id: "ICD-4458", type: "COMPANY_SIGNAL", status: "REJECTED", consumer: "P01_COMPANY_SIGNAL",
      source: "acme.com · procurement portal", connector: "PUBLIC_WEB_PAGE", authority: "PUBLIC_SOURCE",
      extraction: "DETERMINISTIC_PARSER", interpretation: "HYPOTHESIS", confidence: "LOW",
      stage: "policy", outcome: "REFUSED · SOURCE_NO_LONGER_APPROVED", tone: "red",
      detail: "Admission recompiled the CURRENT source policy: the portal's access terms are unreviewed, so the policy compiles BLOCKED. The historical capture keeps its pinned snapshot untouched.",
      resolved: "—", packet: null },
  ],

  /* Connector registry — existing is not activating (ING-DEC-009). */
  connectors: [
    { cls: "PUBLIC_WEB_PAGE", status: "ACTIVE", note: "Registered and used for fixture-class capture in this environment." },
    { cls: "SYNTHETIC_TEST_FIXTURE", status: "ACTIVE", note: "The capture class actually producing bytes at this baseline." },
    { cls: "JSON_API", status: "INERT", note: "Registry-clamped and compile-blocked; no M06 operation exists." },
    { cls: "RSS_OR_ATOM_FEED", status: "INERT", note: "Registry-clamped; no M06 operation exists." },
    { cls: "SITEMAP_OR_URL_LIST", status: "INERT", note: "Registry-clamped; no M06 operation exists." },
    { cls: "CSV_OR_STATIC_FILE", status: "INERT", note: "Registry-clamped; no M06 operation exists." },
    { cls: "TENANT_UPLOADED_ARTIFACT", status: "INERT", note: "Registry-clamped; no M06 operation exists." },
  ],

  boundary: [
    "raw capture ≠ evidence · evidence ≠ fact · candidate ≠ admitted state",
    "Not every P01 observation travels this chain — P01 owns its own monitored-source, snapshot and diff machinery for directly observed changes.",
    "The router holds a dedicated service identity with exactly the consumer submission permissions. An M02 deny is a configuration breach, never a route-around.",
  ],
};

window.AFB.m15ConnectorTone = { ACTIVE: "green", INERT: "muted", SUSPENDED: "amber", RETIRED: "muted" };
window.AFB.m15CandidateTone = {
  NORMALIZED: "cyan", RESOLUTION_PENDING: "cyan", RESOLVED: "cyan",
  AMBIGUOUS: "amber", UNRESOLVED: "amber", HELD: "amber",
  SUBMITTED_FOR_ADMISSION: "gold", ADMITTED_TO_CONSUMER: "green",
  REJECTED: "red", SUPERSEDED: "muted", EXPIRED: "muted",
};
