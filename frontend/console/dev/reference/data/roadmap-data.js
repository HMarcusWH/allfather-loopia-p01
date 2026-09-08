/* Allfather BI — Roadmap surfaces: Post-V1 sequence + M13 Tenant Adaptation +
   M14 PromptOps (architecture IMPORTED 2026-07-07 · runtime-inert).

   M13 stays DOCUMENTED-ONLY. M14 is no longer spec-only: real code exists under
   src/promptops/ — governed prompt version records, binding records,
   deterministic prompt/schema hashing, a fail-closed resolver, an in-memory +
   durable-store simulator, and a promotion check — imported RUNTIME-INERT under
   two pinned claim ceilings. Per console doctrine (doc 21 §2.4) both render as
   read-only surfaces: no live action, explicit inert status.

   Governance doctrine (verbatim intent): evidence → candidate → eval → review →
   verdict → approval → rollout → trace → rollback. The optimizer may propose;
   it may never promote itself. Runtime law (Tier 4) is protected from M13. */
window.AFB = window.AFB || {};

/* ── Post-V1 sequence — the recorded build order (post_v1_roadmap_2026_06_10
   §14) + the surface map's activation classes. DOCS-ONLY: this panel presents
   record state; it authorizes, activates, promotes and builds nothing. ────── */
window.AFB.postV1 = {
  closed: {
    label: "V1 CLOSED", date: "2026-06-10",
    ceiling: "INTERNAL_GOVERNED_SUBSTRATE_WITH_OPERATOR_AUTHORIZED_READ_ONLY_CONTROLLED_PILOT_OBJECT",
    baseline: "1156 tests · 101 files green · schema G00–G20",
    note: "V1 is gated and closed; the ladders below extend it post-V1. The internal proof-MVP is garage-complete (#229–#241), the controlled road-test gate is built (#242–#247), the no-API-key preflight ladder is complete (#248–#254), and the execution-readiness ladder (#255–#262) has prepared — and disabled — everything short of execution. Blocked pending the exact explicit owner go. Nothing live, credentialed, or external has run; each gate is the owner's recorded act, never skipped.",
  },
  classes: [
    { key: "ACTIVE",       tone: "green",  desc: "Read-only / governed-command", items: "Overview · Runtime · Governance · Evidence · Missions · Valkyrie · P01 · P02 (entity + relationship) · P03 (+ ICP) · P04 · P05 (no-live-effect) · P07-A (synthetic) · M15 (fixture-first) · M16 (release output · internal projections)" },
    { key: "GATED",        tone: "gold",   desc: "Own gate before any panel",    items: "M15 production https transport (BUILT · pending owner security review · V1-GAP-090 · cp10+)" },
    { key: "HARD-BLOCKED", tone: "red",    desc: "Legal/privacy + explicit final go", items: "P07-B live send · X01 / P08 / P09 · customer-visible output (V1-GAP-093)" },
    { key: "DOCS-ONLY",    tone: "violet", desc: "No runtime surface exists",    items: "M18 Adaptive Configuration Spine (v0.1 + merge-round docs) · M13 · M17 · Universal Port · Universal Fetch · product-feature intake (5 candidates)" },
    { key: "RETIRED",      tone: "muted",  desc: "Withdrawn — never activated", items: "OpenClaw extension seam (retired · never activated · historical provenance only; the current runtime is host-neutral behind the General Harness Port)" },
    { key: "RUNTIME-INERT", tone: "cyan",  desc: "Imported code · cannot execute", items: "M14 PromptOps + Meta-Validation (imported 2026-07-07) · MCM-HMWH diagnostic MVP (schemas · evaluator · harness) — both ceiling-pinned, review-gated" },
    { key: "INERT CTRL-PLANE", tone: "cyan", desc: "Built · internal · runtime-pinned off", items: "Polymorphic Database G22–G26 + Q6 graph-readiness fixture layer (P0 closed/frozen thru S22) — surfaced read-only on the P02 screen (PDB control-plane overlay)" },
    { key: "EXCLUDED",     tone: "muted",  desc: "Built but not surfaced",       items: "P02 offering twins (V1-GAP-097 · D-MVP-3 DEFER recorded — stay excluded)" },
  ],
  seq: [
    { n: 1, id: "M15", name: "Governed Ingestion Spine", schema: "G19", tone: "green", done: true,
      status: "✅ BUILT · OWNER-GATED — cp1–cp9 complete (CHG-102..110); R-M15-5 granted 2026-06-11",
      gate: "Fixture-first internal use ONLY · live non-fixture fetch stays BLOCKED (V1-GAP-090). The cp10 production https transport is BUILT but PENDING the owner's security review (CHG-128); the 4 connector classes (RSS/sitemap/CSV/JSON_API) stay DESIGNED_INERT until per-class promotion",
      note: "The CHG-110 post-gate swap made the spine's policy compiler P01's capture law too (golden-characterized)." },
    { n: 2, id: "M16", name: "Governed Release & Customer Output Spine", schema: "G20", tone: "cyan", done: true,
      status: "✅ BUILT · OWNER-GATED — cp1–cp6 complete + R-M16-5 blanket clearance 2026-06-12 (CHG-112..118); post-gate CHG-119 surfacing swap + CHG-120 M12 console integration + CHG-127 cp8 P03 panels landed",
      gate: "Owner-gated at the internal ceiling; no audience pin moved — customer-visible output stays HARD-BLOCKED (V1-GAP-093)",
      note: "P01 + P02 compose the spine in-transaction; the three governed P03 console panels render at TENANT_INTERNAL." },
    { n: 3, id: "MVP", name: "ICP extension + MVP track", schema: "G14+", tone: "gold", done: true,
      status: "✅ ICP extension OWNER-GATED — ICP-D5 granted 2026-06-12 (cp1–cp4, CHG-121..125 + CHG-126 hardening; scorer p03-icp-fit-scorer-v2) · MVP-A internal demo REACHED",
      gate: "Decision queue resolved: D-MVP-1/2 CLOSED (M16 + ICP gated) · D-MVP-3 DEFER (offering twins excluded) · D-MVP-4 → MVP-B next track · D-MVP-5 legal review → MVP-C (last)",
      note: "Internal / no-customer-visible ceiling — role CLASSES only, no person data. MVP-A reached; MVP-B = M15 cp10+ live internal monitoring." },
    { n: 4, id: "RT", name: "Controlled-pilot MVP → road-test execution readiness", schema: "—", tone: "amber", now: true, link: "road-test", linkLabel: "open",
      status: "Proof-MVP GARAGE-COMPLETE (#229–#241) · road-test gate BUILT (#242–#247) · preflight COMPLETE (#248–#254) · execution readiness PREPARED (#255–#262) → CONTROLLED_ROAD_TEST_EXECUTION_READY_BLOCKED_PENDING_EXPLICIT_OWNER_GO",
      gate: "Live-transport capability contract defined (not implemented) + disabled adapter harness built (live slot throws); owner-go intake holds six packets, all MISSING. The only missing thing is the exact owner instruction — stored as a requirement, never as given.",
      note: "Future rungs, none reached: exact owner go → Road Test 001 execution → post-run audit (17 checks) → evidence readback (11 fields) → completion report. Open the Pilot Road Test screen for the full ladder, closed boundary and the GSMC pipeline behind the proof rung." },
    { n: 5, id: "M18", name: "Adaptive Configuration Spine", schema: "—", tone: "violet",
      status: "v0.1 PROPOSAL · spec-only — second-round merge docs LANDED (integration blueprint · rollout & open questions · integration inventory · scalability / anti-hardcoding model)",
      gate: "Still spec-only, no runtime; its own ratification + promotion record + per-rung owner gates before any build — no plane collapse",
      note: "ACS Wave 1 is pinned as the GSMC fixture pilot (SOURCE_MONITORING_CONFIG · TRIGGER_PROTOCOL · FETCH_CADENCE_POLICY · ROBOT_TERMS_ACCESS_POLICY). One AdaptiveConfigObject envelope rides the existing M04→M07→M08→M10→M11 pipeline — never a parallel authority plane." },
    { n: 6, id: "PDB", name: "Polymorphic Database — self-adapting data substrate", schema: "G22–G26", tone: "cyan", done: true,
      status: "✅ BUILT · INTERNAL · INERT CONTROL-PLANE — P0 chain CLOSED/FROZEN thru P0-S22 (read-models → G22–G26 storage → writers → links/replay → dry-run → simulation → governance package → full activation-preflight chain). Owner-gated as control-plane PERSISTENCE only; 1199 tests / 104 files. Surfaced read-only as the P02 PDB control-plane overlay (integration-map item #1) — now including the Q6 graph-readiness fixture layer",
      gate: "NOT runtime · NOT customer-visible · NOT docs-only. Every runtime flag CHECK-pinned inert (runtime_active/applied/exported = 0). The S12–S22 activation chain is DRAFT/PREFLIGHT only — terminal step is an immutable decision-record DRAFT, not a recorded decision. Runtime activation = a separate future owner-gated schema change",
      note: "Proves one governed morph loop in a fixture cage ('Nordic Fleet Co.') — candidates/evidence/decisions/dry-run/simulation, never P02 admitted twins. No generic config blob, no EAV. V1-GAP-090/-093 untouched." },
    { n: 7, id: "U-F", name: "Universal Fetch — runtime readiness plan", schema: "—", tone: "violet",
      status: "Docs-only seed (roadmap PR 85)",
      gate: "No live web before the M15 transport PR · the wiring-note §18 gate walk",
      note: "No crawler, no search, no browser, no network exists." },
    { n: 8, id: "U-P", name: "Universal Port — runtime readiness plan", schema: "—", tone: "violet",
      status: "Docs-only seed (roadmap PR 86)",
      gate: "No runtime before M15 · the same §18 gate walk",
      note: "No connector, no OAuth, no browser automation exists." },
    { n: 9, id: "M17", name: "Temporal Intelligence Compression", schema: "G21", tone: "violet",
      status: "ACCEPTED as architecture target — no G21, no src dir",
      gate: "Sequenced after M15 experience — earns its complexity only once admitted signal volume needs it" },
    { n: 10, id: "M13 · M14", name: "Tenant Adaptation + PromptOps", schema: "—", tone: "violet", link: "promptops", linkLabel: "open M14",
      status: "M13 spec-only · M14 architecture IMPORTED 2026-07-07 — runtime-inert code in src/promptops/ (version records · bindings · fail-closed resolver · promotion check) + meta-validation lanes",
      gate: "Two runtime-inert ceilings pinned; no live LLM call, no prompt run, no self-promotion. M13 folds into the M18 / Polymorphic-Database control-plane when adaptation pressure is real",
      note: "The linked MCM-HMWH diagnostic MVP rides the same import wave — see MCM Diagnostics." },
    { n: 11, id: "X01 · P07-B", name: "External execution + live send", schema: "—", tone: "red",
      status: "HARD-BLOCKED",
      gate: "Legal/privacy review + the owner's explicit final go — always last" },
  ],

  /* ── Product feature request intake — docs-only roadmap candidates from the
     product-features/ plane. A request record activates nothing. ──────────── */
  featureIntake: {
    note: "Product feature request intake (product-features/) — a Product/Sources/Ops hybrid plane, docs-only and explicitly not active. Each request names its current-build fit; a request record is not owner-go.",
    items: [
      { name: "Supply Price Sentinel",             fit: "PDB Q6 supplier path family · M15 source classes" },
      { name: "Supply-Line Risk Radar",            fit: "PDB supplier anchors · P01 change detection" },
      { name: "Priority Intelligence Routing",     fit: "M04 mission scoping · P04 role routing · 'Obsession Mode'" },
      { name: "Custom Missions / Mission Control", fit: "M04 mission packets · M12 read-only surface" },
      { name: "Customer Voice Intelligence",       fit: "P05 composition · M05 evidence spine" },
    ],
  },
};

/* ── M13 — Adaptation risk ladder. Tier rises with customer-visible / external
   / personal-data / employee / licensed-source exposure. ─────────────────── */
window.AFB.m13 = {
  preview: true,
  tenant: { name: "Nordic Fleet Co.", modality: "LOGISTICS_OPERATOR", omVersion: "TOM v7", ceiling: "ANALYZE_ONLY", rollback: "TOM v6" },
  tiers: [
    { t: 0, label: "Display preference",        gate: "Auto-apply · M10 log only",                 tone: "green",  auto: true },
    { t: 1, label: "Low-risk config",           gate: "Conditional · policy + M10 notify",         tone: "cyan",   auto: "cond" },
    { t: 2, label: "Meaningful behavior change", gate: "M07 review + M08 verdict + owner approval", tone: "amber",  auto: false },
    { t: 3, label: "Sensitive / external",      gate: "M08 + M09 + admin / legal review",          tone: "violet", auto: false },
    { t: 4, label: "Runtime law / code",        gate: "Forbidden via M13 · developer release only",tone: "red",    auto: false },
  ],
};

/* Adaptation proposals — desired-state changes (spec/status/conditions).
   NEVER shown as active config. status: PROPOSED · IN_REVIEW · APPROVED · BLOCKED · AUTO_APPLIED */
window.AFB.m13.proposals = [
  { id: "ADAPT-214", tier: 2, kind: "TenantRoutingRule", title: "Route FUNDING_EVENT signals to Sales by default",
    rationale: "3 funding-triggered fits this month reached Sales late via manual forward.",
    status: "IN_REVIEW", cond: "HOLD_FOR_EVIDENCE", verdict: null, rollback: "rule v3",
    spec: "signal=FUNDING_EVENT → recipients=[Sales] · priority=High · approval=required" },
  { id: "ADAPT-212", tier: 3, kind: "TenantSchemaExtension", title: "Add field: customer_contract_value (PII-adjacent)",
    rationale: "CFO lens requested contract sizing on prospect briefs.",
    status: "BLOCKED", cond: "BLOCKED_BY_POLICY", verdict: "BLOCK", rollback: "—",
    spec: "field=contract_value · type=currency · pii_class=COMMERCIAL_SENSITIVE → needs M09 + legal" },
  { id: "ADAPT-209", tier: 1, kind: "TenantDynamicConfig", title: "Lower change-detection sensitivity on press feeds",
    rationale: "Press-feed diffs produced 60% minor-significance noise.",
    status: "APPROVED", cond: "ROLLBACK_READY", verdict: "ALLOW_WITH_CONDITIONS", rollback: "config v11",
    spec: "press_feed.min_significance = STRUCTURAL (was MINOR)" },
  { id: "ADAPT-205", tier: 0, kind: "TenantDynamicConfig", title: "Default Overview to ‘Risk-first’ composition",
    rationale: "Operator switched to Risk-first 9 of last 10 sessions.",
    status: "AUTO_APPLIED", cond: "ROLLBACK_READY", verdict: null, rollback: "ui_default v4",
    spec: "overview.default_composition = RISK_FIRST · logged to M10" },
];

/* ── M14 — PromptOps + Meta-Validation, as IMPORTED (2026-07-07). Runtime-inert:
   records, bindings, hashes, checks — objects and proofs, never runs. The old
   fictional variant leaderboard (eval metrics, PRODUCTION chips) is retired;
   no such vocabulary exists in the imported code. ─────────────────────────── */
window.AFB.m14 = {
  imported: true,
  importedAt: "2026-07-07",
  bugfixAudit: "2026-07-08 import-repair audit — lanes green",
  src: "src/promptops/",
  ceiling: "INTERNAL_PROMPTOPS_AND_META_VALIDATION_ARCHITECTURE_IMPORTED_RUNTIME_INERT",
  linkedCeiling: "INTERNAL_MCM_HMWH_PROMPTOPS_META_VALIDATION_LINKED_RUNTIME_INERT",
  doctrine: [
    "Binding is not execution authority",
    "Resolution is not a live LLM call",
    "Hash agreement is not semantic truth",
    "A gate pass is not owner-go",
  ],
  /* Governed prompt version records — content-addressed. Fixture mirror. */
  versionRecords: [
    { id: "PVR-0001", family: "P01_SIGNAL_CLASSIFIER", promptHash: "9d41…c2ae", schemaHash: "77b0…f1d3", status: "RECORDED",
      note: "Governed prompt version record — deterministic prompt + output-schema hashing. The hash pair is identity, never quality." },
    { id: "PVR-0002", family: "P01_SIGNAL_CLASSIFIER", promptHash: "4e88…0b52", schemaHash: "77b0…f1d3", status: "RECORDED",
      note: "Candidate revision — same output schema, different prompt body. A record, not a run; no eval metrics exist anywhere." },
    { id: "PVR-0003", family: "P03_FIT_HYPOTHESIS", promptHash: "b1c7…9e04", schemaHash: "2fa9…6d11", status: "RECORDED",
      note: "Imported with the architecture; exists as a version record only." },
  ],
  /* Binding records — typed, closed-registration. Binding ≠ execution authority. */
  bindings: [
    { id: "PBR-0001", family: "P01_SIGNAL_CLASSIFIER", role: "VK-Skuld / Sales-Intel", pins: "PVR-0001",
      note: "Names which version a role class would resolve. It grants nothing." },
    { id: "PBR-0002", family: "P03_FIT_HYPOTHESIS", role: "VK-Verdandi / Lead-Qual", pins: "PVR-0003",
      note: "Typed and closed-registration — never untyped config in a generic blob." },
  ],
  resolver: {
    law: "FAIL-CLOSED",
    store: "in-memory + durable-store simulator · no live store",
    rows: [
      { q: "P01_SIGNAL_CLASSIFIER × VK-Skuld", out: "PVR-0001 — hashes re-derived and matched.", tone: "ok" },
      { q: "BATTLECARD_GENERATOR × VK-Skuld", out: "NO_BINDING → refusal. No fallback, no default prompt, no silent latest.", tone: "closed" },
    ],
  },
  promotion: {
    note: "The promotion check verifies that a candidate binding COULD be promoted — record integrity, hash agreement, typed target, rollback ref. A pass promotes nothing; promotion is a separate owner decision that does not exist.",
    checks: [
      { k: "Version record integrity", v: "PASS" },
      { k: "Prompt / schema hash agreement", v: "PASS" },
      { k: "Binding target exists + typed", v: "PASS" },
      { k: "Rollback reference present", v: "PASS" },
      { k: "Owner promotion decision", v: "ABSENT — required, never synthesized", warn: true },
    ],
  },
  metaValidation: {
    note: "Meta-validation reads recorded gate heads and reports on them. Run without explicit heads it exits fail-closed — the deliberate refusal is the feature, not a bug.",
    objects: [
      { k: "Release-gate status objects", v: "recorded heads only" },
      { k: "Status-consistency report", v: "fail-closed without explicit heads", warn: true },
      { k: "Import-gate report", v: "2026-07-08 repair audit green" },
      { k: "Decision-record chain validation", v: "hash-linked chain re-derived" },
    ],
  },
  proofLanes: [
    { cmd: "afb:promptops:check", g: "records · bindings · resolver · promotion check" },
    { cmd: "afb:meta-validation:status", g: "release-gate status over recorded heads" },
    { cmd: "afb:meta-validation:status-consistency", g: "fails closed without explicit heads" },
  ],
};
