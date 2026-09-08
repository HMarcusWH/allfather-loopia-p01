/* Allfather BI — Polymorphic Database (PDB) P0 control-plane OVERLAY data.

   This is the BUILT / INTERNAL / INERT control-plane realization of the M18 /
   Adaptive-Config self-adapting substrate. It is NOT runtime, NOT customer-
   visible, NOT live Fetch/Port, NOT CRM writeback, NOT Economy ingestion, NOT
   an M12 runtime UI — and NOT merely docs-only. It is governed control-plane
   PERSISTENCE: schema groups G22–G26 are registered (versions 22–26; v21 stays
   reserved for M17/G21) with every runtime-facing flag CHECK-pinned inert.

   It is a SEPARATE fixture cage (tenant "Nordic Fleet Co.") that proves one
   governed morph loop. Per surface-map law these rows are Sources/Ops objects —
   candidates, evidence, decisions, dry-run, simulation, proposals, gates — and
   NEVER P02 admitted entity twins. This data drives the PDB OVERLAY on the P02
   screen; the admitted P02 graph (tenant Nordvik) stays the untouched base layer.

   Source of truth: polymorphic_database_p0_vertical_slice.md +
   polymorphic_database_p0_s2_s6_physical_control_plane.md (G22–G26) +
   …_s8/_s9/_s10 (links · resolver dry-run · admission simulation). All static. */
window.AFB = window.AFB || {};

window.AFB.p02Polymorphic = {
  meta: {
    classification: "BUILT · INTERNAL · INERT CONTROL-PLANE",
    phase: "P0 CLOSED / FROZEN through P0-S22 — further waves deferred pending a separate proposal",
    schema: "G22–G26",
    schemaNote: "registered versions 22–26 · v21 reserved for M17/G21 · schema head v26",
    baseline: "1199 tests · 104 files green (incl. db/polymorphic-control-plane.test.ts)",
    fixtureTenant: "Nordic Fleet Co. AB — fixture cage (.example · 556001-7777)",
    ownerGate: "Owner-gated: P0-S2…S6 = governed control-plane PERSISTENCE only. The gate does NOT cover runtime activation, M12 runtime UI, Universal Fetch/Port, source monitoring, Economy ingestion, customer-visible output, or candidate→runtime-truth promotion.",
    /* The frozen-state warning every display-only PDB surface must carry (p02-readonly-overlay.ts · map §13 invariant 5). */
    frozenWarning: "PDB P0 spine is closed and frozen through P0-S22. This overlay is display-only: it renders read-only views of the frozen spine and performs no P02 mutation, candidate promotion, decision recording, or runtime activation.",
    /* This P02 overlay IS the design mirror of integration-map item #1: a pure projection over the P0-S15 runtime-inactive admitted projection preview. */
    overlayContract: "P02_READ_ONLY_OVERLAY_VIEW_ONLY · projection over P0-S15 admitted projection preview · not exported · no runtime path imports it",
    waves: [
      { id: "S1",     label: "Read-model contracts", done: true },
      { id: "S2–S6",  label: "G22–G26 physical control-plane storage", done: true },
      { id: "S7",     label: "Guarded fixture/dev writers", done: true },
      { id: "S8",     label: "Evidence/decision links + recorded-input replay", done: true },
      { id: "S9",     label: "Query service + resolver dry-run", done: true },
      { id: "S10",    label: "Admission simulation + governance preview", done: true },
      { id: "S11",    label: "Governance proposal package (proposal-only)", done: true },
      { id: "S12–S14", label: "Launch-readiness handoff + owner decision intake preflight", done: true },
      { id: "S15",    label: "Runtime-inactive admitted projection preview", done: true },
      { id: "S16–S17", label: "Resolver-consumption + manual activation-gate preflight", done: true },
      { id: "S18",    label: "Activation audit / replay / rollback closure package", done: true },
      { id: "S19–S22", label: "Owner closure review → final-decision recording preflight/intake/commit (immutable draft)", done: true },
    ],
    /* The storage-layer inert pins (S2–S6 §1.6) — activation needs a future owner-gated schema change, never data. */
    runtimePins: ["runtime_active = 0", "runtime_applied = 0", "runtime_exported = 0", "display_only = 1", "is_request_only = 1", "economy_ingestion_active = 0", "emitted_artifact_count = 0", "run_mode = FIXTURE"],
  },

  /* The S12–S22 activation-preflight chain — every rung is DRAFT/PREFLIGHT only.
     Surfaced read-only; nothing here submits, approves, records, or activates. */
  activationChain: {
    note: "A complete owner-review → final-activation-decision chain exists as DRAFT/PREFLIGHT packets. Each rung requires a manual owner act it never performs itself; the terminal step is an immutable decision-record DRAFT, not a recorded decision and not activation.",
    rungs: [
      { id: "S12–S13", label: "Launch-readiness gate preflight + owner launch handoff packet", state: "PREFLIGHT", note: "no launch submission/approval" },
      { id: "S14",     label: "Owner decision intake / admission-decision recording preflight", state: "PREFLIGHT", note: "no decision write" },
      { id: "S15",     label: "Runtime-inactive admitted projection preview", state: "PREVIEW", note: "no admission write, no P02 mutation, no resolver consumption" },
      { id: "S16",     label: "Runtime resolver consumption preflight (draft input packet)", state: "PREFLIGHT", note: "no resolver invocation / input submission" },
      { id: "S17",     label: "Manual runtime activation gate preflight (draft review pkg)", state: "PREFLIGHT", note: "manual gates required · no approval/apply" },
      { id: "S18",     label: "Activation audit / replay / rollback closure package", state: "PACKAGED", note: "no replay/rollback execution" },
      { id: "S19",     label: "Owner closure review + draft final-activation decision", state: "DRAFT", note: "no approval recording" },
      { id: "S20–S21", label: "Final-decision recording preflight + owner-supplied intake validation", state: "PREFLIGHT", note: "no recording" },
      { id: "S22",     label: "Final-decision recording commit preflight → immutable decision-record DRAFT", state: "DRAFT", note: "no decision-record write, no activation" },
    ],
  },

  /* The three-plane split of the PDB control plane (GF-AoA discipline). */
  planes: [
    { key: "CORE-BOUNDARY", tone: "gold", icon: "book-marked",
      desc: "Typed dictionaries · admitted config-version refs (runtime_active pinned 0) · admitted P02 refs",
      tables: "trait_definitions · adaptive_config_versions (inactive) · admitted_p02_* (nullable refs)" },
    { key: "SOURCES", tone: "cyan", icon: "link",
      desc: "Candidates · evidence · claims · provenance — never admitted truth",
      tables: "entity/relation anchor candidates · trait_value_candidates · *_evidence_links → M05" },
    { key: "OPS", tone: "amber", icon: "git-branch",
      desc: "Decisions · replay · rollback · dry-run · simulation · governance proposals · gates",
      tables: "*_decision_links → M10 · config_resolver_results (dry-run) · admission simulation · config_rollback_plans" },
  ],

  /* PDB candidate anchors (G23) — Sources / Core-boundary CANDIDATES, never P02 twins.
     viewBox 0 0 640 380. status: ADMITTED_CANDIDATE (fixture M10 record) | HELD. */
  candidateNodes: [
    { id: "nordfleet", name: "Nordic Fleet Co. AB", brand: "NordFleet", kind: "OWN_COMPANY", anchorKind: "ORGANIZATION",
      x: 320, y: 196, status: "ADMITTED_CANDIDATE", evidence: 3, plane: "SOURCES",
      note: "Organization anchor + registry identity (T1 lane) + brand anchor (NordFleet ≠ org) + Gothenburg geo anchor. Admitted within the fixture pack only — no live P02 twin.",
      admittedRef: "— (no live P02 twin; fixture decision record only)" },
    { id: "scandia", name: "Scandia Truck Parts AB", kind: "SUPPLIER", anchorKind: "RELATION_ANCHOR",
      x: 132, y: 96, status: "ADMITTED_CANDIDATE", evidence: 1, plane: "SOURCES",
      note: "SUPPLIES relation anchor candidate. Source basis TENANT_PROVIDED (UP-FIX-01).", admittedRef: "—" },
    { id: "baltic", name: "Baltic Retail Group AB", kind: "CUSTOMER", anchorKind: "RELATION_ANCHOR",
      x: 540, y: 104, status: "ADMITTED_CANDIDATE", evidence: 2, plane: "SOURCES",
      note: "SELLS_TO relation anchor candidate. Source basis TENANT_PROVIDED (UP-FIX-01/02).", admittedRef: "—" },
    { id: "svea", name: "Svea Transport AB", kind: "COMPETITOR", anchorKind: "RELATION_ANCHOR",
      x: 360, y: 340, status: "HELD", evidence: 1, plane: "SOURCES",
      note: "COMPETES_WITH relation anchor candidate — HELD_FOR_EVIDENCE: a single REPUTABLE_SECONDARY source (UF-FIX-03) is below the medium_high relationship strictness (C7/C11).", admittedRef: "— (held)" },
  ],
  candidateEdges: [
    { from: "scandia",   to: "nordfleet", type: "SUPPLIES",      status: "ADMITTED_CANDIDATE", conf: "TENANT_PROVIDED" },
    { from: "nordfleet", to: "baltic",    type: "SELLS_TO",      status: "ADMITTED_CANDIDATE", conf: "TENANT_PROVIDED" },
    { from: "nordfleet", to: "svea",      type: "COMPETES_WITH", status: "HELD",               conf: "INFERRED_HYPOTHESIS" },
  ],

  /* Operating-trait candidates (G22) — one inference run; AI may infer, never silently admit. */
  traitRun: { id: "TIR-P0-1", dict: "operating_trait_schema_v0_1", run_mode: "FIXTURE", note: "One OperatingTraitInferenceRun over fixture evidence — recorded inputs/outputs + digests; trait admission rides the TOM proposal (T2)." },
  traitAnnotations: [
    { key: "operating_trait.revenue_motion",      value: "contract_service",        conf: 0.8,  status: "CANDIDATE" },
    { key: "operating_trait.customer_motion",     value: "b2b_relationship_sales",   conf: 0.8,  status: "CANDIDATE" },
    { key: "operating_trait.sales_cycle_length",  value: "medium (2–4 months)",      conf: 0.7,  status: "CANDIDATE" },
    { key: "operating_trait.evidence_strictness", value: "medium_high",              conf: 0.7,  status: "CANDIDATE" },
    { key: "operating_trait.memory_window",       value: "medium_long",              conf: 0.7,  status: "CANDIDATE" },
    { key: "operating_trait.supplier_dependency", value: "present",                  conf: 0.7,  status: "CANDIDATE" },
    { key: "operating_trait.economy_projection_relevance", value: "supply_chain_exposure_candidate", conf: 0.5, status: "HELD", note: "rationale only — activates nothing (projection stays HELD)" },
    { key: "operating_trait.repeat_purchase_pattern", value: "subscription_renewal", conf: null, status: "REJECTED", note: "no evidence" },
    { key: "operating_trait.buying_committee_complexity", value: "—",                conf: null, status: "UNKNOWN", note: "unknown trait candidate, kept visible" },
  ],

  /* Source fixtures — all ride the SYNTHETIC_TEST_FIXTURE lane (no network). */
  sources: [
    { id: "UF-FIX-01", label: "nordicfleet.example/about", side: "Universal Fetch", authority: "OFFICIAL_PRIMARY",  license: "PUBLIC_WEB_ALLOWED", fresh: "current",  lane: "SYNTHETIC_TEST_FIXTURE" },
    { id: "UF-FIX-02", label: "registry 556001-7777",      side: "Universal Fetch", authority: "REGISTRY_OR_REGULATORY", license: "OPEN_CC0",     fresh: "current",  lane: "SYNTHETIC_TEST_FIXTURE" },
    { id: "UF-FIX-03", label: "logistics-weekly.example",  side: "Universal Fetch", authority: "REPUTABLE_SECONDARY", license: "PUBLIC_WEB_ALLOWED", fresh: "recent · 6 mo", lane: "SYNTHETIC_TEST_FIXTURE" },
    { id: "UP-FIX-01", label: "sales/process note",        side: "Universal Port",  authority: "TENANT_PROVIDED", license: "customer_owned_tenant_scoped", fresh: "current", lane: "uploaded artifact" },
    { id: "UP-FIX-02", label: "CRM export sample (role classes only)", side: "Universal Port", authority: "TENANT_PROVIDED", license: "customer_owned_tenant_scoped", fresh: "current", lane: "uploaded artifact" },
  ],

  /* Candidate claims (read models over M05/M15/P02 seams — no second claim store). */
  claims: [
    { id: "C1",  txt: "Legal name = Nordic Fleet Co. AB",                cls: "KNOWN_FACT",          src: "UF-FIX-02 + UF-FIX-01", outcome: "candidate → admitted" },
    { id: "C2",  txt: "Headquarters = Gothenburg, SE",                   cls: "KNOWN_FACT",          src: "UF-FIX-02 + UF-FIX-01", outcome: "candidate → admitted" },
    { id: "C4",  txt: "Service category = road freight / logistics",     cls: "KNOWN_FACT",          src: "UF-FIX-02",             outcome: "admitted · suggests modality, never activates it" },
    { id: "C5",  txt: "Supplier: Scandia Truck Parts → Nordic Fleet",    cls: "TENANT_PROVIDED",     src: "UP-FIX-01",             outcome: "admitted relation anchor (fixture)" },
    { id: "C7",  txt: "Competitor: Nordic Fleet ↔ Svea Transport",       cls: "INFERRED_HYPOTHESIS", src: "UF-FIX-03 (1 secondary)", outcome: "HELD pending corroboration" },
    { id: "C8",  txt: "Fleet size = 120 vehicles (web) vs 85 trucks (press)", cls: "CONFLICTING",    src: "UF-FIX-01 vs UF-FIX-03", outcome: "ConflictRecord open · stays conflicting · no averaging" },
    { id: "C9",  txt: "Employee count = ?",                              cls: "UNKNOWN",             src: "—",                     outcome: "UnknownRecord · remains visible" },
    { id: "C11", txt: "Evidence sufficiency: competitor relation = 1 secondary, below medium_high", cls: "SUFFICIENCY", src: "evidence policy", outcome: "justifies the C7 hold" },
  ],

  /* Adaptive-config proposals (G24) — typed family parameter spaces. No generic blob, no EAV. */
  configProposals: [
    { family: "SOURCE_MONITORING_CONFIG",     tier: "T2",    status: "PROPOSED", note: "Monitor nordicfleet.example + competitor press surface (fixture cards, fixture lane). New monitored sources are ALWAYS T2 + operator approval." },
    { family: "TRIGGER_PROTOCOL",             tier: "T1–T2", status: "PROPOSED", note: "CONTENT_HASH_CHANGED on about/pricing; KEYWORD_MATCH on press surface." },
    { family: "FETCH_CADENCE_POLICY",         tier: "T1",    status: "PROPOSED", note: "Weekly website check; monthly registry re-verify — within cadence floors." },
    { family: "PROMPT_BINDING_PROFILE",       tier: "T2",    status: "PROPOSED", note: "Bind logistics interpretation partial — interpretation only, no authority widening." },
    { family: "WORKFLOW_ROUTE_PROFILE",       tier: "T2",    status: "PROPOSED", note: "Supplier signals → procurement queue; competitor pricing → sales/strategy queue (route only)." },
    { family: "UI_SURFACE_PROFILE",           tier: "T0–T1", status: "PROPOSED", note: "Owner dashboard panel order + supplier-watch panel." },
    { family: "TEMPORAL_COMPRESSION_PROFILE", tier: "T2",    status: "PROPOSED", note: "medium_long memory; slower stale-decay for contract-cycle signals." },
    { family: "ECONOMY_PROJECTION_PROFILE",   tier: "T3",    status: "HELD",     note: "Drafted from trait rationale, immediately HELD / NOT ACTIVE — T3 Economy-side gate not exercised in P0." },
    { family: "DATABASE_SCHEMA_EXTENSION_PROFILE", tier: "T4", status: "REJECTED", note: "'add fleet_size field' — schema change is T4 / developer-release; never via adaptation." },
    { family: "CODE_EXTENSION_PROFILE",       tier: "T4",    status: "REJECTED", note: "custom press-parser adapter — code extension is T4 by default; AI may propose specs, never deploy." },
  ],

  /* Admitted config-version refs (G24, Core boundary) — runtime_active pinned 0. */
  configVersions: [
    { id: "ACV-P0-UI-1",   family: "UI_SURFACE_PROFILE",     status: "ADMITTED", runtimeActive: false, note: "T0 panel-order preference — admitted (fixture M10), runtime_active pinned 0." },
    { id: "ACV-P0-CAD-1",  family: "FETCH_CADENCE_POLICY",   status: "ADMITTED", runtimeActive: false, note: "T1 cadence within floors — admitted (fixture M10), runtime_active pinned 0." },
    { id: "ACV-P0-SRC-1",  family: "SOURCE_MONITORING_CONFIG", status: "ADMITTED", runtimeActive: false, note: "T2 monitored-source set — admitted (fixture M10); resolver consumption separately gated, runtime_active pinned 0." },
  ],

  /* Decision links (G22–G24, Ops) — route to M10; linkage, not self-authorization. */
  decisionLinks: [
    { id: "DL-1", subject: "Organization anchor · Nordic Fleet Co.", route: "M04 → M07 (≥2 reviewers) → M08 → M10", verdict: "ALLOW", record: "fixture DR · INTERNAL_INFORMATIONAL_RELEASE" },
    { id: "DL-2", subject: "SUPPLIES relation anchor · Scandia",     route: "M04 → M07 → M08 → M10", verdict: "ALLOW", record: "fixture DR" },
    { id: "DL-3", subject: "COMPETES_WITH relation anchor · Svea",   route: "M04 → M07 → M08",        verdict: "HOLD_FOR_EVIDENCE", record: "no admitted DR — held" },
    { id: "DL-4", subject: "TOM proposal · generation 1",            route: "M04 → M07 → M08 → M10", verdict: "ALLOW", record: "fixture DR (authorizes resolver composition only)" },
    { id: "DL-5", subject: "SOURCE_MONITORING_CONFIG (missing robots)", route: "M08 source-law",     verdict: "BLOCK", record: "BLOCKED_BY_SOURCE_LAW — fail closed" },
  ],

  /* Resolver dry-run (G24 · P0-S9) — DRY_RUN_ONLY; runtime_applied pinned 0; no runtime resolver call. */
  dryRun: {
    mode: "DRY_RUN_ONLY", family: "SOURCE_MONITORING_CONFIG", scope: "competitor public-web surface",
    effectiveHash: "sha256:e7c1…9a04", overlayDigest: "sha256:0b55…dd31",
    overlayStack: [
      "GLOBAL_DEFAULTS",
      "PRODUCT_MODULE_DEFAULTS · P02/P01/M15 (fixture lane · cadence/evidence floors)",
      "BUSINESS_MODALITY · LOGISTICS_OPERATOR (future template → falls back to B2B_SALES_LED)",
      "TENANT · NORDIC_FLEET_CO (medium_high evidence · medium_long memory)",
      "WORKSPACE · DEFAULT (no overrides)",
      "ENTITY · SVEA_TRANSPORT_AB (press-surface keywords)",
      "SOURCE_CLASS · PUBLIC_WEB (robots/terms law · cadence floor · short_spans)",
      "RISK_TIER · T2 (stricter review)",
      "OPERATOR_OVERRIDE · DEFAULT (none)",
      "EMERGENCY_PATCH · (none · empty stack logged)",
    ],
    rejectedWidening: "A workspace variant tried to (a) raise fetch frequency above the PUBLIC_WEB cadence floor and (b) widen extraction short_spans → full_text. Both REJECTED_WIDENING — merged value held at the inherited floor; rejected widenings logged + surfaced, never applied.",
  },

  /* Admission simulation (P0-S10) — SIMULATION_ONLY; never admits, never applies. */
  admissionSimulation: {
    mode: "SIMULATION_ONLY", guard: "assertAdmissionSimulationPreviewIsNonRuntime — fail-closed on any runtime command field / true runtime flag / URL / Fetch-Port / dispatch / writeback",
    readyForProposal: ["Organization anchor", "SUPPLIES relation anchor", "SELLS_TO relation anchor", "TOM proposal gen-1", "UI / cadence config versions"],
    blocked: [
      { ref: "COMPETES_WITH relation anchor (Svea)", missing: "≥2 independent sources or operator confirmation" },
      { ref: "SOURCE_MONITORING_CONFIG (unrobots'd source)", missing: "robots_policy_checked — BLOCKED_BY_SOURCE_LAW" },
      { ref: "ECONOMY_PROJECTION_PROFILE", missing: "Economy-side T3 gate" },
      { ref: "Fleet-size value", missing: "conflict resolution policy — stays CONFLICTING" },
    ],
    audit: { previewOnly: true, runtimeActivationIncluded: false, note: "AuditBundlePreview is an in-memory summary — not persisted, not a release artifact, no customer output." },
  },

  /* Governance proposal package (P0-S11) — BUILT · proposal-only. No submission, approval, admission, or activation. */
  governancePackage: {
    status: "BUILT", gate: "proposal-only",
    scope: "governance proposal package generation · owner-review packet construction · admission request object drafting — still no runtime application, no submission, no approval",
  },

  /* The gate ledger — what is required before anything here could ever leave the cage. */
  gates: [
    { id: "ACTIVATION_PREFLIGHT", label: "Runtime activation gate (S17/S19)", state: "DRAFT/PREFLIGHT", tone: "amber", note: "The full owner-review → final-activation-decision chain exists only as DRAFT/PREFLIGHT packets. The terminal S22 step is an immutable decision-record DRAFT — not a recorded decision, not activation. Manual owner acts are required and never performed here." },
    { id: "OWNER_GATE",      label: "Owner gate · control-plane persistence", state: "GRANTED",  tone: "green",  note: "P0-S2…S6 storage owner-gated this session — persistence only; does NOT cover runtime / M12 UI / Fetch-Port / monitoring / Economy / customer output." },
    { id: "RUNTIME_GATE",    label: "Runtime resolver gate",                  state: "CLOSED",    tone: "red",    note: "Runtime resolver consumption of admitted config versions is separately owner-gated. runtime_active / runtime_applied / runtime_exported all pinned 0." },
    { id: "ECONOMY_GATE",    label: "Economy-side gate",                      state: "REQUIRED",  tone: "amber",  note: "EconomyProjectionProfile is HELD; economy_ingestion_active + emitted_artifact_count pinned 0. ADMITTED_BY_ECONOMY needs a separate Economy-side M10 decision." },
    { id: "M12_SURFACE",     label: "M12 surface",                           state: "DISPLAY/REQUEST-ONLY", tone: "cyan", note: "G25 snapshots pinned display_only = 1; requests pinned is_request_only = 1. Request ≠ approval ≠ runtime command ≠ writeback. No activation column exists." },
    { id: "SOURCE_LAW",      label: "Source-law gate",                       state: "FAIL-CLOSED", tone: "red",  note: "A SOURCE_MONITORING_CONFIG variant naming a source without robots_policy_checked is BLOCKED_BY_SOURCE_LAW / BLOCKED_BY_ROBOTS." },
    { id: "LIVE_FETCH",      label: "Live fetch (V1-GAP-090)",               state: "BLOCKED",   tone: "red",    note: "All observation rides the SYNTHETIC_TEST_FIXTURE lane. Live non-fixture fetch stays BLOCK_FEATURE — untouched." },
    { id: "CUSTOMER_OUTPUT", label: "Customer-visible output (V1-GAP-093)",  state: "BLOCKED",   tone: "red",    note: "Everything stays OPERATOR_ONLY / TENANT_INTERNAL. No customer-visible projection — untouched." },
    { id: "SCHEMA_CODE",     label: "Schema / code extension",              state: "REJECTED",  tone: "red",    note: "DATABASE_SCHEMA_EXTENSION_PROFILE + CODE_EXTENSION_PROFILE are T4 — refused via adaptation; developer-release only." },
  ],

  /* ── Q6 — product-feature graph-readiness FIXTURE layer (2026-07 wave).
     A static fixture package + master-architect doc over the frozen P0/S22
     spine: nodes/edges/paths, traversal queries + results, path explanations,
     candidate-signal taxonomy, feature↔module support map, role-recipient +
     source-policy readiness, replay/rollback refs, and an M12 display-only
     readiness surface. Validated by afb:pdbq6:fixtures:check. ───────────── */
  q6: {
    classification: "STATIC FIXTURE LAYER",
    proof: "afb:pdbq6:fixtures:check — green in the 2026-07-10 audit lane",
    law: "Graph results are not truth and not evidence. Traversal can only NOMINATE governed candidates into the normal M04→M07→M08→M10 pipeline. The PDB spine stays frozen through P0-S22 — Q6 adds no writer, no server, no adapter, no sync; Neo4j is an optional static explorer only.",
    pathFamilies: [
      { feature: "Supply Price Sentinel", ready: true,
        path: "supplier anchor → SUPPLIES edge → price-signal candidates → routing",
        note: "Path family resolves over the frozen fixture graph; every signal stays a candidate." },
      { feature: "Supply-Line Risk Radar", ready: true,
        path: "supplier network paths → dependency traits → risk-signal taxonomy",
        note: "Traversal explains each path; a path explanation is display, not evidence." },
      { feature: "Priority Intelligence Routing", ready: true,
        path: "finding routes × role-recipient readiness map",
        note: "Role-recipient + source-policy readiness resolved from fixtures." },
      { feature: "Custom Missions / Mission Control", ready: true,
        path: "mission scope ↔ node / edge / path families",
        note: "The M12 readiness surface stays display-only; a mission request is not a command." },
      { feature: "Customer Voice Intelligence", ready: false,
        path: "customer anchors → voice-signal candidates",
        note: "Gaps recorded in the readiness surface — kept visible, not padded over." },
    ],
    keptVisible: [
      { ref: "UnknownRecords", note: "traverse as UNKNOWN — never dropped from results" },
      { ref: "ConflictRecords", note: "traverse as CONFLICTING — never averaged" },
    ],
    blockedActions: ["Graph write", "Candidate promotion", "Runtime activation", "Live sync / server / adapter"],
    allowedNext: "Nominate a governed candidate from a traversal result into M04 intake (fixture pipeline). Nomination is the ceiling — and even that is not performed from this overlay.",
  },
};
