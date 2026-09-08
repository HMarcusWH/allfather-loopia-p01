/* Allfather BI — OPERATOR fixtures: the operating-state overview, Packet Trace
   chains, and the universal object inspector index.

   Packet Trace reconstructs the ACTUAL route an object took. Stages that are not
   part of a given object's path are absent — never rendered as skipped boxes.
   Edge vocabulary is the causal relation between two nodes, stated on the node
   it arrives at. */
window.AFB = window.AFB || {};

/* ═══════════════ Overview · operating state ═══════════════
   The tile values below are the SEED baseline. `window.AFB.operatingLive()`
   recomputes them from the current collections so the Overview cannot disagree
   with the surface it links to — which matters the moment volume changes. */
window.AFB.operating = {
  /* Zone 1 — what is happening now. Each tile routes to its owning surface. */
  state: [
    { id: "sources", label: "Sources monitored", value: 5, sub: "2 monitoring · 1 review due · 1 degraded · 1 paused", tone: "cyan", icon: "radar", view: "sources" },
    { id: "observations", label: "Observations recorded", value: 18, sub: "14 no-change · 3 captured · 1 fetch failed", tone: "cyan", icon: "eye", view: "sources" },
    { id: "findings", label: "Open findings", value: 3, sub: "2 review due · highest severity HIGH", tone: "amber", icon: "flag", view: "sources" },
    { id: "candidates", label: "Candidates in intake", value: 8, sub: "3 admitted · 4 held · 1 refused at policy", tone: "violet", icon: "funnel", view: "ingestion" },
    { id: "missions", label: "Active missions", value: 3, sub: "9 competitors in scope · advisory ceiling", tone: "gold", icon: "crosshair", view: "missions" },
    { id: "review", label: "Under review", value: 2, sub: "1 HMWH session open · 1 Arbiter condition", tone: "violet", icon: "users", view: "hmwh" },
  ],

  /* Zone 2 — the MVP loop, left to right, with real counts and the one place
     each stage is owned. `stalled` marks where work is not moving. */
  flow: [
    { id: "monitor", label: "Monitor", module: "GSMC", count: 5, unit: "sources", view: "sources", tone: "cyan" },
    { id: "capture", label: "Capture", module: "GSMC · M15", count: 3, unit: "captures recorded", view: "sources", tone: "cyan" },
    { id: "verify", label: "Verify", module: "M15", count: 4, unit: "candidates past capture", view: "ingestion", tone: "cyan" },
    { id: "qualify", label: "Qualify", module: "M07 · M08", count: 2, unit: "sessions in review", view: "hmwh", tone: "violet", stalled: 1 },
    { id: "route", label: "Route", module: "P04", count: 2, unit: "distributions", view: "p04", tone: "gold" },
    { id: "prepare", label: "Prepare", module: "M16", count: 4, unit: "released internal", view: "release", tone: "green", stalled: 2 },
    { id: "replay", label: "Replay", module: "M11", count: 24, unit: "replayable records", view: "replay", tone: "cyan" },
  ],
  flowNote: "Counts are objects currently at that stage, not throughput, and each is read from the module named beneath it — never from whichever collection happens to be largest. A number moving does not make anything true. Under scale mode, stages whose owning fixture has no generated equivalent (M07 review, P04 distribution) stay at seed volume; the narrowing you see there is a fixture boundary, not a stalled pipeline.",

  /* Zone 3 — runtime and governance health. Capability, authority and activation
     are kept apart on purpose. */
  health: [
    { id: "binding", label: "M01 host binding", value: "BOUND · READY", tone: "green", detail: "allfather-harness-native@1.4.2 · conformance re-verified at the boundary", view: "runtime" },
    { id: "port", label: "General Harness Port", value: "harness/1", tone: "green", detail: "Current execution-environment boundary. Conformance is not authority.", view: "runtime" },
    { id: "effect", label: "External effect", value: "DISABLED", tone: "green", detail: "All five external-effect guard invariants read zero.", view: "runtime" },
    { id: "gateway", label: "M06 gateway · 24h", value: "2 allowed · 3 refused", tone: "amber", detail: "Every refusal carries a no-dispatch proof.", view: "gateway" },
    { id: "audit", label: "Audit findings", value: "3 open", tone: "amber", detail: "Recommendations from the 2026-07-10 sweep: F3, F4, F5.", view: "incidents" },
    { id: "incidents", label: "Incidents", value: "1 open", tone: "violet", detail: "Provenance incident INC-0044 · containment acknowledged.", view: "incidents" },
  ],

  /* What the operator should NOT read into the overview. */
  frame: "Read-only projection of governed seed objects. Nothing on this screen acts, and no tile grants what its owning module has not recorded.",
};

/* ═══════════════ Packet Trace ═══════════════ */
window.AFB.packetEdge = {
  CAPTURED_AS: "captured as", DERIVED_FROM: "derived from", SUPPORTED_BY: "supported by",
  SUBMITTED_AS: "submitted as", REVIEWED_BY: "reviewed by", ADMITTED_BY: "admitted by",
  AUTHORIZED_BY: "authorized by", RECORDED_AS: "recorded as", PROJECTED_AS: "projected as",
  VISIBLE_AS: "visible as", SUPERSEDES: "supersedes", INVALIDATES: "invalidates", REPLAYED_AS: "replayed as",
};

window.AFB.packets = [
  {
    id: "PKT-0418", head: "RA-0418", outcome: "RELEASED_INTERNAL", tone: "green",
    title: "Boreal Tier-2 pricing change → internal battlecard release",
    summary: "A complete route with no gaps: a monitored pricing page changed, the change was captured and diffed, three primary evidence objects supported two claims, review preserved one dissent, the Arbiter admitted an informational release, the ledger recorded it and M16 assembled and activated an internal projection. No approval was required because nothing effect-bearing was proposed.",
    nodes: [
      { id: "SRC-0114", type: "Monitored source", owner: "GSMC", plane: "SOURCES", state: "MONITORING", at: "config v3", digest: "SMCV-0114-3", tone: "cyan", edge: null,
        basis: "Admitted through PRP-0388 → AV-0388 → DR-0061", truth: "Public web page · terms clear · rechecked 2026-08-21", view: "sources", goId: "SRC-0114" },
      { id: "OBR-2291", type: "Observation run", owner: "GSMC", plane: "SOURCES", state: "CAPTURED", at: "06:40", digest: "content hash changed", tone: "cyan", edge: "CAPTURED_AS",
        basis: "Fetch cadence 1h · within the admitted configuration", truth: "A trigger fire is an observation, not a finding.", view: "sources", goId: "SRC-0114" },
      { id: "SNP-8841", type: "Snapshot", owner: "P01", plane: "SOURCES", state: "STORED", at: "06:40", digest: "sha256:6d21a9…", tone: "cyan", edge: "CAPTURED_AS",
        basis: "P01 native monitored-source machinery — not the M15 candidate chain", truth: "Raw capture. Not evidence.", view: "p01" },
      { id: "DIFF-3307", type: "Change diff", owner: "P01", plane: "SOURCES", state: "MAJOR", at: "06:40", digest: "prior SNP-8790", tone: "cyan", edge: "DERIVED_FROM",
        basis: "Deterministic diff against the immediately prior snapshot", truth: "Directly observed: list price and add-on block. Nothing about intent.", view: "p01" },
      { id: "EB-0204", type: "Evidence bundle", owner: "M05", plane: "SOURCES", state: "3 OBJECTS ASSESSED", at: "06:44", digest: "sha256:4cc9d6…a84f2b", tone: "cyan", edge: "SUPPORTED_BY",
        basis: "Safe content reference plus digest — no raw payload is stored", truth: "Evidence. Not fact.", view: "evidence" },
      { id: "ICD-4471", type: "Ingestion candidate", owner: "M15", plane: "SOURCES", state: "ADMITTED_TO_CONSUMER", at: "06:47", digest: "FACT_OBSERVED · HIGH", tone: "violet", edge: "DERIVED_FROM",
        basis: "Current source policy recompiled at admission — COMPILED_ALLOWED", truth: "Candidate. Not admitted state until the consumer's own governance accepts it.", view: "ingestion", goId: "ICD-4471" },
      { id: "CSG-0418", type: "Company signal", owner: "P01", plane: "SOURCES", state: "CREATED", at: "06:47", digest: "PRICING_CHANGE", tone: "cyan", edge: "ADMITTED_BY",
        basis: "P01-owned command composed by the M15 adapter under the admission service identity", truth: "The signal carries FACT_OBSERVED for the price and HYPOTHESIS for the segment reading.", view: "p01" },
      { id: "PRP-0418", type: "Proposal envelope", owner: "M04", plane: "OPS", state: "SUBMITTED", at: "06:48", digest: "MSN-0407", tone: "cyan", edge: "SUBMITTED_AS",
        basis: "Mission MSN-0407 narrowed contract authority to advisory", truth: "A mission scopes work. It grants no authorization to execute.", view: "console-state", goId: "PRP-0418" },
      { id: "HRS-0418", type: "HMWH review session", owner: "M07", plane: "GOVERNANCE", state: "PROCEED_TO_ARBITER", at: "06:52 → 07:03", digest: "4 assessments · 1 dissent", tone: "violet", edge: "REVIEWED_BY",
        basis: "Four typed critics; producer self-review denied and re-assigned", truth: "Review is not a verdict. The dissent is preserved append-only, not outvoted.", view: "hmwh", goId: "HRS-0418" },
      { id: "AV-0418", type: "Arbiter verdict", owner: "M08", plane: "GOVERNANCE", state: "ALLOW_INFORMATIONAL_RELEASE", at: "07:05", digest: "basis sha256:1b77e2…", tone: "green", edge: "ADMITTED_BY",
        basis: "V1 scope → evidence → HMWH → terminal, all PASS", truth: "An ALLOW permits the next stage. It is not a release and not an approval.", view: "arbiter", goId: "PRP-0418" },
      { id: "DR-0071", type: "Decision Record", owner: "M10", plane: "CORE", state: "RELEASED_INTERNAL", at: "07:08", digest: "sha256:4cc9d6…a84f2b", tone: "gold", edge: "RECORDED_AS",
        basis: "Hash-linked append to the ledger with four verified references", truth: "The canonical record of the decision. Everything downstream cites it.", view: "ledger", goId: "DR-0071" },
      { id: "RA-0418", type: "Release artifact", owner: "M16", plane: "OPS", state: "RELEASED_INTERNAL", at: "07:12", digest: "3 sources · 2 claims", tone: "green", edge: "PROJECTED_AS",
        basis: "Assembled after DR-0071; a CLAIM without a primary evidence link cannot be assembled", truth: "Uncertainty and freshness blocks are mandatory and non-redactable.", view: "release", goId: "RA-0418" },
      { id: "COP-1188", type: "Output projection", owner: "M16", plane: "OPS", state: "TENANT_INTERNAL · ACTIVE", at: "07:12", digest: "redaction engine v2", tone: "green", edge: "VISIBLE_AS",
        basis: "Monotonic projection derived from the OPERATOR_ONLY element set", truth: "Every audience wider than TENANT_INTERNAL is designed-inert.", view: "release", goId: "RA-0418" },
      { id: "DSP-1188", type: "Display reference", owner: "M12", plane: "OPS", state: "RECORDED", at: "07:13", digest: "CUSTOMER_OUTPUT_PROJECTION_DISPLAY", tone: "cyan", edge: "VISIBLE_AS",
        basis: "Anchored on DR-0071 as the owner basis", truth: "A render the guard cannot anchor fails closed to a detail-free refusal.", view: "console-state" },
      { id: "AR-0418", type: "Replay package", owner: "M11", plane: "OPS", state: "REPLAYABLE · CONTROL_PASS", at: "07:15", digest: "no-side-effect", tone: "cyan", edge: "REPLAYED_AS",
        basis: "Re-derives the projection and the source-use compilation from recorded inputs", truth: "Replay explains what happened. It confers no authority to re-execute.", view: "replay" },
    ],
  },
  {
    id: "PKT-0419", head: "held at M16 assembly", outcome: "HELD_SOURCE_USE", tone: "amber",
    title: "Acme funding announcement → procurement hypothesis held",
    summary: "A route that legitimately stops. The funding announcement is primary-sourced and reached P01. The procurement reading is a hypothesis whose only evidence object was quarantined, so admission held it and M16 cannot assemble an artifact containing an unsupported claim. Nothing was downgraded silently: the hold names its reason.",
    nodes: [
      { id: "SRC-0126", type: "Monitored source", owner: "GSMC", plane: "SOURCES", state: "MONITORING", at: "config v1", digest: "SMCV-0126-1", tone: "cyan", edge: null,
        basis: "Admitted through PRP-0396 → AV-0396 → DR-0058", truth: "Public web page · terms clear", view: "sources", goId: "SRC-0126" },
      { id: "OBR-2289", type: "Observation run", owner: "GSMC", plane: "SOURCES", state: "CAPTURED", at: "05:15", digest: "new item in updates[]", tone: "cyan", edge: "CAPTURED_AS",
        basis: "ANY_NEW_ITEM and KEYWORD_MATCH both fired", truth: "A press item is evidence of an announcement, never of the underlying fact.", view: "sources", goId: "SRC-0126" },
      { id: "EV-0731", type: "Evidence object", owner: "M05", plane: "SOURCES", state: "ASSESSED", at: "05:18", digest: "sha256:a91b73…", tone: "cyan", edge: "CAPTURED_AS",
        basis: "Primary public source · safe reference and digest", truth: "Supports the funding announcement only.", view: "evidence" },
      { id: "EV-0732", type: "Evidence object", owner: "M05", plane: "SOURCES", state: "QUARANTINED", at: "05:19", digest: "provenance unresolved", tone: "red", edge: "CAPTURED_AS",
        basis: "Quarantined by the provenance incident INC-0044", truth: "The only object that could have supported the procurement claim.", view: "evidence" },
      { id: "ICD-4470", type: "Ingestion candidate", owner: "M15", plane: "SOURCES", state: "ADMITTED_TO_CONSUMER", at: "05:21", digest: "FACT_OBSERVED · MEDIUM", tone: "violet", edge: "DERIVED_FROM",
        basis: "Resolved to Acme Logistics · COMPETITOR", truth: "The funding leg only.", view: "ingestion", goId: "ICD-4470" },
      { id: "ICD-4469", type: "Ingestion candidate", owner: "M15", plane: "SOURCES", state: "HELD · CANDIDATE_EVIDENCE_UNUSABLE", at: "05:21", digest: "HYPOTHESIS · LOW", tone: "amber", edge: "DERIVED_FROM",
        basis: "Evidence usability re-checked at admission — the linked object is quarantined", truth: "Held, not rejected. Resubmission after a usable source is a NEW candidate.", view: "ingestion", goId: "ICD-4469" },
      { id: "PRP-0419", type: "Proposal envelope", owner: "M04", plane: "OPS", state: "SUBMITTED", at: "05:24", digest: "MSN-0407", tone: "cyan", edge: "SUBMITTED_AS",
        basis: "Promote Acme from the Watch List to the Priority Outreach Queue", truth: "Carries both legs; the weaker one is explicitly marked.", view: "console-state", goId: "PRP-0419" },
      { id: "HRS-0419", type: "HMWH review session", owner: "M07", plane: "GOVERNANCE", state: "REQUIRE_MORE_EVIDENCE", at: "05:32 → 06:10", digest: "risk veto RV-0042", tone: "amber", edge: "REVIEWED_BY",
        basis: "Evidence critic and general reviewer agree on the gap", truth: "A risk veto routes the outcome; it is not overridden by agreement elsewhere.", view: "hmwh", goId: "HRS-0419" },
      { id: "AV-0419", type: "Arbiter verdict", owner: "M08", plane: "GOVERNANCE", state: "HOLD_FOR_EVIDENCE", at: "06:12", digest: "basis sha256:0f4e12…", tone: "amber", edge: "ADMITTED_BY",
        basis: "V1 scope PASS · M05 evidence FAIL", truth: "A hold is a condition, not a denial.", view: "arbiter", goId: "PRP-0419" },
      { id: "INC-0044", type: "Incident", owner: "M11", plane: "OPS", state: "CONTAINMENT_ACKNOWLEDGED", at: "05:19", digest: "provenance-or-IP", tone: "violet", edge: "INVALIDATES",
        basis: "Opened from the evidence quarantine", truth: "The incident is why the second claim has no usable support.", view: "incidents" },
      { id: "RA-0419", type: "Release artifact", owner: "M16", plane: "OPS", state: "HELD_SOURCE_USE", at: "—", digest: "assembly refused", tone: "amber", edge: "PROJECTED_AS",
        basis: "A CLAIM element with no PRIMARY evidence link cannot be assembled", truth: "No projection, no audience, no reliance. The refusal is the record.", view: "release", goId: "RA-0419" },
    ],
  },
  {
    id: "PKT-0411", head: "RA-0411", outcome: "RELEASED_INTERNAL · STALE_WARNING", tone: "amber",
    title: "Fjordr positioning change → internal signal briefing, now warning on freshness",
    summary: "A released route whose truth has aged. The observed messaging change is still correct as of its capture, but it has passed the freshness warning threshold, so the read model marks every render. The same change also routed to P02 as a twin state-change proposal — one observation, two consumers, two separate governance paths.",
    nodes: [
      { id: "SRC-0117", type: "Monitored source", owner: "GSMC", plane: "SOURCES", state: "REVIEW_DUE", at: "config v1", digest: "SMCV-0117-1", tone: "amber", edge: null,
        basis: "Admitted through PRP-0391 → AV-0391 → DR-0055", truth: "Review cadence 24h — currently overdue.", view: "sources", goId: "SRC-0117" },
      { id: "OBR-2274", type: "Observation run", owner: "GSMC", plane: "SOURCES", state: "CAPTURED", at: "2026-08-27 22:10", digest: "SIGNAL_CLASS_MATCH", tone: "cyan", edge: "CAPTURED_AS",
        basis: "POSITIONING_SHIFT signal class matched", truth: "Marketing copy changes often; only a claim-bearing change should reach review.", view: "sources", goId: "SRC-0117" },
      { id: "LFR-0908", type: "Finding review route", owner: "GSMC", plane: "OPS", state: "ACKNOWLEDGED", at: "07:02", digest: "1 matched · 1 selected", tone: "cyan", edge: "DERIVED_FROM",
        basis: "Operator acknowledgement recorded in the lifecycle chain", truth: "Acknowledgement is not resolution.", view: "sources", goId: "SRC-0117" },
      { id: "EV-0724", type: "Evidence object", owner: "M05", plane: "SOURCES", state: "ASSESSED", at: "22:12", digest: "sha256:77aa9e…", tone: "cyan", edge: "SUPPORTED_BY",
        basis: "Single primary public source", truth: "Covers published messaging, not certification status.", view: "evidence" },
      { id: "ICD-4468", type: "Ingestion candidate", owner: "M15", plane: "SOURCES", state: "ADMITTED_TO_CONSUMER", at: "22:16", digest: "ENTITY_FACT", tone: "violet", edge: "DERIVED_FROM",
        basis: "Resolved to Fjordr · ENTITY_TWIN", truth: "Routed to P02, which owns it from submission onward.", view: "ingestion", goId: "ICD-4468" },
      { id: "ETC-0611", type: "Twin state change", owner: "P02", plane: "SOURCES", state: "PROPOSED", at: "22:16", digest: "positioning claim", tone: "cyan", edge: "ADMITTED_BY",
        basis: "Existing P02 propose command; offering twins remain excluded by type", truth: "A proposed state change is not admitted twin state.", view: "p02" },
      { id: "AV-0411", type: "Arbiter verdict", owner: "M08", plane: "GOVERNANCE", state: "ALLOW_INFORMATIONAL_RELEASE", at: "22:58", digest: "basis sha256:c30d18…", tone: "green", edge: "ADMITTED_BY",
        basis: "Claim class advisory; no external side effect", truth: "Admits the next stage only.", view: "arbiter" },
      { id: "DR-0068", type: "Decision Record", owner: "M10", plane: "CORE", state: "RELEASED_INTERNAL", at: "23:00", digest: "sha256:c30d18…", tone: "gold", edge: "RECORDED_AS",
        basis: "Append-only with two verified references", truth: "Still active; reliance has not been withdrawn.", view: "ledger", goId: "DR-0068" },
      { id: "RA-0411", type: "Release artifact", owner: "M16", plane: "OPS", state: "RELEASED_INTERNAL", at: "23:02", digest: "1 source · 1 claim", tone: "green", edge: "PROJECTED_AS",
        basis: "Assembled after DR-0068 with a limitation note", truth: "Marked STALE_WARNING at view time — the mark travels with every read.", view: "release", goId: "RA-0411" },
    ],
  },
  {
    id: "PKT-0396", head: "withdrawn", outcome: "ROLLED_BACK", tone: "red",
    title: "Kvant cost dossier → visibility deactivated, reliance withdrawn",
    summary: "A route that was released and then withdrawn. A licensed excerpt exceeded its permitted use; the source-use conformance audit found it, M16 deactivated visibility and withdrew reliance, and a correction record supersedes the original. Nothing is deleted — the withdrawal and its reason are the record.",
    nodes: [
      { id: "SRC-0121", type: "Monitored source", owner: "GSMC", plane: "SOURCES", state: "DEGRADED", at: "config v2", digest: "SMCV-0121-2", tone: "red", edge: null,
        basis: "Admitted through PRP-0362 → AV-0362 → DR-0040", truth: "Licensed feed · terms recheck lapsed 2026-08-19.", view: "sources", goId: "SRC-0121" },
      { id: "RA-0396", type: "Release artifact", owner: "M16", plane: "OPS", state: "ROLLED_BACK", at: "2026-08-05 16:40", digest: "5 sources · 2 primary", tone: "red", edge: "PROJECTED_AS",
        basis: "Assembled after DR-0039 and activated for TENANT_INTERNAL", truth: "Two elements carried excerpts without recorded rights.", view: "release", goId: "RA-0396" },
      { id: "AUD-0112", type: "Audit finding", owner: "M11 · M16", plane: "OPS", state: "RELEASE_SOURCE_USE_CONFORMANCE · FAIL", at: "2026-08-19 04:10", digest: "1 of 7 checks", tone: "red", edge: "INVALIDATES",
        basis: "Standing audit programme, not an ad-hoc review", truth: "A finding opens an incident; it does not itself withdraw anything.", view: "incidents" },
      { id: "RB-0396", type: "Release rollback", owner: "M16", plane: "OPS", state: "VISIBILITY_DEACTIVATED", at: "2026-08-19 05:02", digest: "reliance withdrawn", tone: "red", edge: "INVALIDATES",
        basis: "Rollback choreography — deactivate visibility, then withdraw reliance", truth: "Renders now return WITHDRAWN, detail-free.", view: "release", goId: "RA-0396" },
      { id: "DR-0057", type: "Decision Record", owner: "M10", plane: "CORE", state: "CORRECTION · SUPERSEDES DR-0039", at: "2026-08-19 05:04", digest: "sha256:0f4e12…cc", tone: "gold", edge: "SUPERSEDES",
        basis: "Correction record citing the audit finding", truth: "The superseded record is preserved, not rewritten.", view: "ledger", goId: "DR-0057" },
    ],
  },
];

window.AFB.packetPlaneTone = { CORE: "gold", SOURCES: "cyan", OPS: "amber", GOVERNANCE: "violet" };

/* ═══════════════ Universal object inspector index ═══════════════
   One shape for every object kind. BOUNDARIES are read from the owning module's
   recorded law — the console never derives a permission from UI state. */
window.AFB.objects = {
  "SRC-0114": {
    id: "SRC-0114", type: "Source monitoring configuration", owner: "GSMC", module: "GSMC", version: "v3 · SMCV-0114-3",
    plane: "SOURCES", layer: "Governance-admitted configuration", role: "MVP PRIMARY",
    scope: { tenant: "Nordvik Industrier AB", workspace: "Competitive Intelligence", mission: "MSN-0407", actor: "M. Hermansson" },
    state: { lifecycle: "ACTIVE", implementation: "BUILT", activation: "MONITORING · fixture-class capture", authority: "Owner-gated · assisted autonomy" },
    basis: { authority: "PRP-0388 → AV-0388 → DR-0061", evidence: ["Source access policy · APPROVED"], upstream: [], downstream: ["OBR-2291", "LFR-0912"] },
    truth: { freshness: "Observed 06:40 · next due 07:40", uncertainty: "A template change can register as a content-hash change without a commercial change.", reliance: "Active", supersession: "Supersedes SMCV-0114-2" },
    can: ["Be observed on its admitted cadence", "Promote matched evaluations to a review route", "Be superseded by a new admitted version"],
    cannot: ["Be edited in place", "Be fetched from the console", "Lower its cadence below the 300 000 ms floor"],
    next: ["Supersede with a new version (proposal → verdict → record)", "Record a source-terms recheck"],
    view: "sources",
  },
  "ICD-4471": {
    id: "ICD-4471", type: "Ingestion candidate", owner: "M15", module: "M15", version: "COMPANY_SIGNAL",
    plane: "SOURCES", layer: "Intake transformation", role: "MVP SUPPORT",
    scope: { tenant: "Nordvik Industrier AB", workspace: "Competitive Intelligence", mission: "MSN-0407", actor: "svc-ingestion-admission" },
    state: { lifecycle: "ADMITTED_TO_CONSUMER", implementation: "BUILT", activation: "ACTIVE · owner-gated", authority: "Consumer submission permission only" },
    basis: { authority: "Admission recompiled the current source policy — COMPILED_ALLOWED", evidence: ["EV-0718", "EV-0719", "EV-0720"], upstream: ["DIFF-3307"], downstream: ["CSG-0418", "PRP-0418"] },
    truth: { freshness: "Observed 06:40", uncertainty: "FACT_OBSERVED · HIGH confidence for the price; the segment reading is a separate HYPOTHESIS candidate.", reliance: "Consumer governance owns it", supersession: "—" },
    can: ["Be routed to exactly one consumer per candidate", "Be replayed deterministically from its recorded inputs"],
    cannot: ["Write a consumer table directly", "Be re-routed after refusal — resubmission is a new candidate", "Make anything true by moving stage"],
    next: ["Consumer governance decides (P01 owns it now)"],
    view: "ingestion",
  },
  "ICD-4469": {
    id: "ICD-4469", type: "Ingestion candidate", owner: "M15", module: "M15", version: "COMPANY_SIGNAL",
    plane: "SOURCES", layer: "Intake transformation", role: "MVP SUPPORT",
    scope: { tenant: "Nordvik Industrier AB", workspace: "Competitive Intelligence", mission: "MSN-0407", actor: "svc-ingestion-admission" },
    state: { lifecycle: "HELD", implementation: "BUILT", activation: "ACTIVE · owner-gated", authority: "Consumer submission permission only" },
    basis: { authority: "Held at admission · CANDIDATE_EVIDENCE_UNUSABLE", evidence: ["EV-0732 · QUARANTINED"], upstream: ["OBR-2289"], downstream: [] },
    truth: { freshness: "Observed 05:15", uncertainty: "HYPOTHESIS · LOW confidence. No usable primary support exists.", reliance: "None", supersession: "—" },
    can: ["Sit in the evidence-linked held queue", "Be superseded by a new candidate with usable evidence"],
    cannot: ["Be admitted while its evidence is quarantined", "Be downgraded into a fact"],
    next: ["Resolve INC-0044", "Submit a new candidate with a primary source"],
    view: "ingestion",
  },
  "RA-0418": {
    id: "RA-0418", type: "Release artifact", owner: "M16", module: "M16", version: "BATTLECARD_BRIEFING",
    plane: "OPS", layer: "Governed representation", role: "MVP PRIMARY",
    scope: { tenant: "Nordvik Industrier AB", workspace: "Competitive Intelligence", mission: "MSN-0407", actor: "—" },
    state: { lifecycle: "RELEASED_INTERNAL", implementation: "BUILT", activation: "TENANT_INTERNAL · ACTIVE", authority: "Claim ceiling INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS" },
    basis: { authority: "DR-0071", evidence: ["EB-0204 · 3 primary objects"], upstream: ["PRP-0418", "AV-0418", "DR-0071"], downstream: ["COP-1188", "DSP-1188"] },
    truth: { freshness: "2026-08-28 06:40 · FRESH", uncertainty: "Price observed; segment intent carried as HYPOTHESIS at MEDIUM.", reliance: "CURRENT", supersession: "—" },
    can: ["Be projected to TENANT_INTERNAL under a role lens", "Be replayed to re-derive its projection", "Be superseded or rolled back"],
    cannot: ["Reach any audience wider than TENANT_INTERNAL", "Drop its uncertainty or freshness blocks", "Render without a recorded display reference"],
    next: ["Supersede on the next observed change", "Withdraw if a source-use finding lands"],
    view: "release",
  },
  "RA-0419": {
    id: "RA-0419", type: "Release artifact", owner: "M16", module: "M16", version: "COMPANY_SIGNAL_BRIEFING",
    plane: "OPS", layer: "Governed representation", role: "MVP PRIMARY",
    scope: { tenant: "Nordvik Industrier AB", workspace: "Competitive Intelligence", mission: "MSN-0407", actor: "—" },
    state: { lifecycle: "HELD_SOURCE_USE", implementation: "BUILT", activation: "OPERATOR_ONLY", authority: "Claim ceiling INTERNAL_DRAFT" },
    basis: { authority: "No decision record — assembly refused", evidence: ["EV-0731", "EV-0732 · QUARANTINED"], upstream: ["PRP-0419", "AV-0419"], downstream: [] },
    truth: { freshness: "2026-08-28 05:15", uncertainty: "One claim has no primary evidence link.", reliance: "NONE", supersession: "—" },
    can: ["Be assembled once every CLAIM carries a primary link"],
    cannot: ["Be projected", "Be activated for any audience", "Be relied on"],
    next: ["Add a primary source", "Downgrade the claim and reassemble"],
    view: "release",
  },
  "LFR-0912": {
    id: "LFR-0912", type: "Live finding review route", owner: "GSMC", module: "GSMC", version: "—",
    plane: "OPS", layer: "Review lifecycle", role: "MVP PRIMARY",
    scope: { tenant: "Nordvik Industrier AB", workspace: "Competitive Intelligence", mission: "MSN-0407", actor: "M. Hermansson" },
    state: { lifecycle: "OPEN", implementation: "BUILT", activation: "ACTIVE", authority: "Internal operator read" },
    basis: { authority: "Promoted from the matched evaluations of OBR-2291", evidence: ["2 selected evaluations"], upstream: ["SRC-0114", "OBR-2291"], downstream: ["PRP-0418"] },
    truth: { freshness: "Promoted 06:41 · review due 10:41", uncertainty: "Severity HIGH is a routing property, not a claim about impact.", reliance: "—", supersession: "—" },
    can: ["Be acknowledged, dismissed or resolved by an operator decision", "Carry an append-only audit package"],
    cannot: ["Be edited", "Trigger a fetch", "Produce a customer-visible output"],
    next: ["ACKNOWLEDGE", "DISMISS", "RESOLVE"],
    view: "sources",
  },
};

/* Derive the Overview's counts from the collections they route to. Called by the
   Overview on every render, so the seed numbers above are only a fallback. */
window.AFB.operatingLive = function () {
  const A = window.AFB, o = A.operating, k = A.counts();
  // Every figure here comes from window.AFB.counts() — never a local expression,
  // never the largest array to hand. A tile and the strip must not be able to
  // disagree, and a tile must not cite an owner that did not produce its number.
  const sub = (part, whole) => (part > 0 && part < whole ? part : undefined);
  const state = o.state.map((t) => {
    if (t.id === "sources") return { ...t, value: k.sources.value, sub: `${k.monitoring.value} monitoring · ${k.reviewDue.value} review due · ${k.degraded.value} degraded · ${k.paused.value} paused` };
    if (t.id === "observations") return { ...t, value: k.observations.value, sub: `${k.noChange.value} no-change · ${k.captured.value} captured · ${k.fetchFailed.value} fetch failed` };
    if (t.id === "findings") return { ...t, value: k.openFindings.value, sub: `${k.highFindings.value} at HIGH severity · separately, ${k.ackFindings.value} acknowledged and still unresolved` };
    if (t.id === "candidates") return { ...t, value: k.candidates.value, sub: `${k.admitted.value} admitted · ${k.held.value} held · ${k.refused.value} refused` };
    if (t.id === "missions") {
      const tot = (A.missions || []).length, act = k.activeMissions.value;
      return { ...t, value: act, sub: `${act < tot ? `of ${tot} total · ` : ""}advisory ceiling · ${k.sessions.value} review session${k.sessions.value === 1 ? "" : "s"}` };
    }
    if (t.id === "review") return { ...t, value: k.underReview.value, sub: `${k.openSessions.value} HMWH session open · ${k.arbiterHolds.value} Arbiter condition${k.arbiterHolds.value === 1 ? "" : "s"}` };
    return t;
  });
  const flow = o.flow.map((f) => {
    if (f.id === "monitor") return { ...f, count: k.sources.value, stalled: sub(k.degraded.value + k.paused.value, k.sources.value) };
    if (f.id === "capture") return { ...f, count: k.captured.value, stalled: sub(k.fetchFailed.value, k.captured.value) };
    if (f.id === "verify") {
      const past = A.m15.packets.filter((p) => p.stage !== "policy" && p.stage !== "plan" && p.stage !== "capture");
      const distinct = (arr) => new Set(arr.map((p) => p.id)).size;
      return { ...f, count: distinct(past), stalled: sub(distinct(past.filter((p) => p.status === "REJECTED")), distinct(past)) };
    }
    if (f.id === "qualify") return { ...f, count: k.sessions.value, stalled: sub(k.openSessions.value, k.sessions.value) };
    if (f.id === "route") return { ...f, count: k.distributions.value };
    if (f.id === "prepare") return { ...f, count: k.released.value, stalled: sub(k.releaseHeld.value, k.released.value) };
    if (f.id === "replay") return { ...f, count: k.replayable.value };
    return f;
  });
  return { ...o, state, flow };
};

/* ═══════════════ window.AFB.counts() — the single source of cross-surface figures
   Any number that appears under the SAME LABEL in more than one place must come
   from here. Two independent expressions for one label always drift; this is the
   third time that bug class has surfaced, so the expressions now live in exactly
   one function and every consumer reads it.

   Each entry names its owning module and the surface it routes to, so a caller
   cannot cite an owner that did not produce the figure. */
window.AFB.counts = function () {
  const A = window.AFB;
  const g = A.gsmc, m15 = A.m15, m16 = A.m16;
  const arts = A.artifacts || [];
  const st = (s) => arts.filter((a) => a.state === s).length;
  const n = (arr, f) => (arr || []).filter(f).length;
  const missions = A.missions || [];
  const sessions = A.hmwhSessions || [];
  const obs = g.sources.reduce((a, s) => a.concat(s.observations || []), []);

  // An Arbiter condition is a proposal held for a condition, not a denial.
  const arbiterConditions = st("hold");
  // A review session is open while it is still gathering assessments.
  const openSessions = n(sessions, (s) => s.status === "AWAITING_ASSESSMENTS");

  return {
    sources:        { value: g.sources.length, owner: "GSMC", view: "sources" },
    monitoring:     { value: n(g.sources, (s) => s.health === "MONITORING"), owner: "GSMC", view: "sources" },
    sourceAttention:{ value: n(g.sources, (s) => s.health === "DEGRADED" || s.rights !== "APPROVED"), owner: "GSMC", view: "sources" },
    reviewDue:      { value: n(g.sources, (s) => s.health === "REVIEW_DUE"), owner: "GSMC", view: "sources" },
    degraded:       { value: n(g.sources, (s) => s.health === "DEGRADED"), owner: "GSMC", view: "sources" },
    paused:         { value: n(g.sources, (s) => s.health === "PAUSED"), owner: "GSMC", view: "sources" },
    observations:   { value: obs.length, owner: "GSMC", view: "sources" },
    captured:       { value: n(obs, (o) => o.outcome === "CAPTURED"), owner: "GSMC", view: "sources" },
    noChange:       { value: n(obs, (o) => o.outcome === "NO_CHANGE"), owner: "GSMC", view: "sources" },
    fetchFailed:    { value: n(obs, (o) => o.outcome === "FETCH_FAILED"), owner: "GSMC", view: "sources" },
    openFindings:   { value: n(g.routes, (r) => r.status === "OPEN"), owner: "GSMC", view: "sources" },
    highFindings:   { value: n(g.routes, (r) => r.severity === "HIGH" && r.status === "OPEN"), owner: "GSMC", view: "sources" },
    ackFindings:    { value: n(g.routes, (r) => r.status === "ACKNOWLEDGED"), owner: "GSMC", view: "sources" },
    candidates:     { value: m15.packets.length, owner: "M15", view: "ingestion" },
    admitted:       { value: n(m15.packets, (p) => p.status === "ADMITTED_TO_CONSUMER"), owner: "M15", view: "ingestion" },
    held:           { value: n(m15.packets, (p) => p.status === "HELD"), owner: "M15", view: "ingestion" },
    refused:        { value: n(m15.packets, (p) => p.status === "REJECTED"), owner: "M15", view: "ingestion" },
    notAdmitted:    { value: n(m15.packets, (p) => p.status === "HELD" || p.status === "REJECTED"), owner: "M15", view: "ingestion" },
    activeMissions: { value: n(missions, (m) => m.status === "ACTIVE"), owner: "M04", view: "missions" },
    // "Under review" = review sessions still open PLUS proposals held on an
    // Arbiter condition. One expression, both consumers.
    underReview:    { value: openSessions + arbiterConditions, owner: "M07 · M08", view: "hmwh" },
    openSessions:   { value: openSessions, owner: "M07", view: "hmwh" },
    arbiterHolds:   { value: arbiterConditions, owner: "M08", view: "arbiter" },
    approvals:      { value: st("frozen") + (A.approvalCase ? 1 : 0), owner: "M09", view: "frozen" },
    blocked:        { value: st("blocked"), owner: "M08", view: "arbiter" },
    released:       { value: n(m16.artifacts, (a) => a.status === "RELEASED_INTERNAL"), owner: "M16", view: "release" },
    releaseHeld:    { value: n(m16.artifacts, (a) => a.status === "HELD_SOURCE_USE" || a.status === "HELD_FRESHNESS"), owner: "M16", view: "release" },
    superseded:     { value: n(m16.artifacts, (a) => a.status === "SUPERSEDED"), owner: "M16", view: "release" },
    rolledBack:     { value: n(m16.artifacts, (a) => a.status === "ROLLED_BACK"), owner: "M16", view: "release" },
    stale:          { value: n(m16.artifacts, (a) => a.staleness && a.staleness !== "FRESH"), owner: "M16", view: "release" },
    relianceActive: { value: n(m16.artifacts, (a) => a.reliance === "CURRENT"), owner: "M16", view: "release" },
    replayable:     { value: m16.artifacts.filter((a) => a.decision && a.decision !== "—").length, owner: "M11", view: "replay" },
    distributions:  { value: ((A.p04 || {}).dist || []).length, owner: "P04", view: "p04" },
    sessions:       { value: sessions.length, owner: "M07", view: "hmwh" },
    // GSMC review-route breakdown. The Source Monitoring snapshot cells read
    // these, so a cell can never contradict the findings table below it.
    routes:         { value: g.routes.length, owner: "GSMC", view: "sources" },
    resolvedFindings:{ value: n(g.routes, (r) => r.status === "RESOLVED"), owner: "GSMC", view: "sources" },
    dismissedFindings:{ value: n(g.routes, (r) => r.status === "DISMISSED"), owner: "GSMC", view: "sources" },
    findingsDue:    { value: n(g.routes, (r) => r.status === "OPEN" || r.status === "ACKNOWLEDGED"), owner: "GSMC", view: "sources" },
    promoted:       { value: g.routes.reduce((a, r) => a + (r.selectedCount || 0), 0), owner: "GSMC", view: "sources" },
  };
};

/* Which packet, if any, actually contains this object as a node? Returns null when
   no trace exists — a missing packet is a real answer, not a link to the wrong one. */
window.AFB.packetFor = function (id) {
  if (!id) return null;
  const p = (window.AFB.packets || []).find((x) => x.nodes.some((n) => n.id === id));
  return p ? p.id : null;
};

/* Human name for a route id, so a surface is never described to the operator by its
   slug ("performed on the frozen surface"). */
window.AFB.viewLabel = function (id) {
  const M = {
    overview: "Overview", queue: "Needs you", trace: "Packet Trace",
    p01: "Competition Monitor", p02: "Entity Context", p03: "Lead Intelligence",
    p04: "Distribution", p05: "Marketing Drafts", p07: "Execution Safety",
    sources: "Source Monitoring", ingestion: "Ingestion", evidence: "Evidence",
    missions: "Mission Control", "mission-control": "Mission Control", hmwh: "Review",
    arbiter: "Arbiter", frozen: "Approvals", ledger: "Decision Ledger", release: "Release & Visibility",
    access: "Access & Authority", valkyrie: "Valkyries & Contracts", gateway: "Tool Gateway",
    runtime: "Runtime & Harness", replay: "Audit & Replay", incidents: "Incidents",
    "console-state": "Console State", readiness: "MVP Readiness", archive: "Development Systems",
    "road-test": "Pilot Road Test", roadmap: "Post-V1 Sequence", migration: "Migration Readiness",
  };
  return M[id] || id;
};

/* Which module owns each attention class — a section title is the KIND of attention,
   not its owner, and the two must not be conflated. */
window.AFB.queueOwner = {
  sign: "M09 · approval", decide: "M08 · arbiter", "owner-go": "Owner authority",
  incident: "M11 · assurance", sources: "GSMC · source monitoring", intake: "M15 · ingestion",
  release: "M16 · release & visibility", blocked: "M08 · arbiter",
};

/* M10 stream head + a genuine linkage walk over window.AFB.ledgerRecords.
   Never assert a verification the console did not actually perform. */
window.AFB.ledgerHead = function () {
  const A = window.AFB;
  const recs = A.ledgerRecords || [];
  const s = A.ledgerStream;
  if (recs.length === 0) return { ...s, head: null, headHash: s.genesis, height: 0, records: 0 };

  // Newest-first array: index 0 is the head.
  const head = recs[0];
  let seqBreaks = 0, linkBreaks = 0, roleBreaks = 0;
  for (let i = 0; i < recs.length - 1; i++) {
    // (a) sequence continuity — a hole, reorder or injection breaks the run.
    if (recs[i].seq !== recs[i + 1].seq + 1) seqBreaks++;
    // (c) prev-digest — each record must chain onto its predecessor's chain hash.
    if (recs[i].prev !== recs[i + 1].chain) linkBreaks++;
  }
  // (e) required basis roles — satisfied ONLY by a VERIFIED_REFERENCE.
  const REQUIRED = {
    INTERNAL_INFORMATIONAL_RELEASE: ["VERDICT_BASIS", "PROPOSAL_BASIS"],
    HARMLESS_DEMO_ACTION_COMPLETED: ["VERDICT_BASIS", "APPROVAL_BASIS", "ARTIFACT_BASIS", "AUTHORIZATION_BASIS", "OUTCOME_BASIS"],
  };
  recs.forEach((r) => {
    const need = REQUIRED[r.type];
    if (!need) return;
    const have = new Set((r.refs || []).filter((x) => x.v === "VERIFIED_REFERENCE").map((x) => x.role));
    if (need.some((role) => !have.has(role))) roleBreaks++;
  });

  const chainStatus = seqBreaks + linkBreaks === 0 ? "PASS" : "FAIL";
  const refStatus = roleBreaks === 0 ? "PASS" : "FAIL";
  return {
    ...s,
    head: head.id, headHash: head.chain, height: head.seq, records: recs.length,
    oldest: recs[recs.length - 1].id, oldestSeq: recs[recs.length - 1].seq,
    live: {
      chain: chainStatus, refs: refStatus,
      seqBreaks, linkBreaks, roleBreaks,
      // Steps (b) and (d) recompute canonical digests; the console cannot, and says so.
      digests: "NOT_RUN",
      note: chainStatus === "PASS" && refStatus === "PASS"
        ? "Sequence continuity, prev-digest chaining and required basis roles walked over the loaded records. Payload and chain-hash recomputation is not run in the console — it needs the canonical digest function."
        : "Linkage walk found a break. In the substrate a head or sequence divergence is an S4 finding requiring incident evaluation.",
    },
  };
};
