/* Allfather BI — M04 Mission Packets + M05 evidence capture/readiness seed data.
   Vocabulary canonical to packages/allfather-control-substrate/src/m04/missions.ts
   (MissionType, ActionClass, requestedReviewPath, assignment narrowing) and
   /src/m05/readiness.ts (EvidenceReadinessStatus, evidence_status, bundle status). */
window.AFB = window.AFB || {};

/* M04 — mission packets. Missions only NARROW Capability Contract authority; an
   assigned Valkyrie can never self-release or self-execute; a SUBMITTED proposal
   is immutable. status DRAFT → ACTIVE. */
window.AFB.missions = [
  {
    id: "MSN-0407", type: "RESEARCH", status: "ACTIVE", valid: "2026-05-19 → 2026-08-19",
    title: "Weekly competitor digest · Nordic SaaS cohort",
    objective: "Continuously observe 9 competitors and surface material changes as evidence and digests.",
    scope: "Pricing pages · launch pages · public communications across 9 watched entities.",
    actions: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE"],
    outputs: ["INTERNAL_RESEARCH_NOTE", "EVIDENCE_BUNDLE_PROPOSAL", "INTERNAL_BRIEFING_PROPOSAL"],
    tools: ["source.fetch", "change.diff", "brief.compose"],
    completion: ["Weekly digest issued", "Material-change alerts raised within 4h of detection"],
    prohibitions: ["No external publication", "No effect-bearing actions"],
    assignments: [{ vk: "VK-Verdandi", role: "Primary research", actions: ["OBSERVE", "ANALYZE"], tools: ["source.fetch", "change.diff"], status: "ACTIVE" }],
    proposals: [],
  },
  {
    id: "MSN-0405", type: "INTERNAL_BRIEFING", status: "ACTIVE", valid: "2026-06-03 → 2026-07-03",
    title: "Competitive response · Tier-2 pricing shift",
    objective: "Prepare an evidence-grounded battlecard responding to Boreal's Tier-2 pricing move.",
    scope: "Boreal Systems public pricing & launch surfaces; internal positioning library.",
    actions: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE"],
    outputs: ["EVIDENCE_BUNDLE_PROPOSAL", "INTERNAL_BRIEFING_PROPOSAL"],
    tools: ["source.fetch", "change.diff", "brief.compose"],
    completion: ["Battlecard proposal submitted with ≥3 verified primary sources"],
    prohibitions: ["No external send", "No campaign or budget modification"],
    assignments: [{ vk: "VK-Skuld", role: "Action preparer", actions: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE"], tools: ["source.fetch", "brief.compose"], status: "ACTIVE" }],
    proposals: [
      { ref: "PRP-0418", title: "Publish competitor battlecard", type: "INTERNAL_BRIEFING", action: "RELEASE_INTELLIGENCE", reviewPath: "HMWH_THEN_ARBITER", state: "evidence" },
    ],
  },
  {
    id: "MSN-0402", type: "INTERNAL_BRIEFING", status: "ACTIVE", valid: "2026-05-28 → 2026-07-28",
    title: "Lead qualification · Acme Logistics",
    objective: "Qualify Acme Logistics for the Watch List / Priority Outreach Queue using evidence-backed buying signals.",
    scope: "Acme public news, funding & procurement signals; portfolio fit model.",
    actions: ["OBSERVE", "ANALYZE"],
    outputs: ["INTERNAL_RESEARCH_NOTE", "EVIDENCE_BUNDLE_PROPOSAL"],
    tools: ["source.fetch", "change.diff"],
    completion: ["Qualification proposal submitted with primary-sourced trigger"],
    prohibitions: ["No outreach", "No escalation without verified primary source"],
    assignments: [{ vk: "VK-Verdandi", role: "Primary research", actions: ["OBSERVE", "ANALYZE"], tools: ["source.fetch"], status: "ACTIVE" }],
    proposals: [
      { ref: "PRP-0419", title: "Admit Acme to the Priority Outreach Queue", type: "INTERNAL_BRIEFING", action: "RELEASE_INTELLIGENCE", reviewPath: "HMWH_THEN_ARBITER", state: "hold" },
    ],
  },
  {
    id: "MSN-0409", type: "CONTROLLED_DEMO_ACTION", status: "ACTIVE", valid: "2026-06-04 → 2026-06-18",
    title: "Battlecard publish · internal demo notification",
    objective: "Prepare a single harmless internal demo notification announcing a battlecard release.",
    scope: "Internal #intel-demo channel only; no external surface.",
    actions: ["OBSERVE", "ANALYZE", "PREPARE_EXTERNAL_ACTION"],
    outputs: ["HARMLESS_DEMO_ACTION_PROPOSAL"],
    tools: ["demo.action.notify"],
    completion: ["Demo notification approved, frozen and recorded"],
    prohibitions: ["No external messaging", "No budget or campaign changes", "Execution only via approved frozen artifact"],
    assignments: [{ vk: "VK-Skuld", role: "Action preparer", actions: ["PREPARE_EXTERNAL_ACTION"], tools: ["demo.action.notify"], status: "ACTIVE" }],
    proposals: [
      { ref: "PRP-0420", title: "Post internal demo notification", type: "CONTROLLED_DEMO_ACTION", action: "PREPARE_EXTERNAL_ACTION", reviewPath: "ARBITER_THEN_APPROVAL_FOR_DEMO_ACTION", state: "frozen" },
      { ref: "PRP-0421", title: "Auto-adjust ad budget on competitor launch", type: "CONTROLLED_DEMO_ACTION", action: "EXECUTE_CONSEQUENTIAL_ACTION", reviewPath: "ARBITER_ONLY", state: "blocked" },
    ],
  },
];
window.AFB.missionTypeTone = { RESEARCH: "cyan", INTERNAL_BRIEFING: "gold", CONTROLLED_DEMO_ACTION: "amber", REPLAY_SUPPORT: "violet", TEST: "muted" };
window.AFB.reviewPathLabel = {
  ARBITER_ONLY: "Arbiter only", HMWH_THEN_ARBITER: "HMWH → Arbiter",
  ARBITER_THEN_APPROVAL_FOR_DEMO_ACTION: "Arbiter → Approval",
};

/* M05 — evidence capture lifecycle: source reference → capture → bundle draft →
   add items → submit (freeze hash) → readiness query. */
window.AFB.captureFlow = [
  { id: "register", label: "Register source", module: "M05", detail: "A source reference is registered with its class & scope." },
  { id: "capture", label: "Capture evidence", module: "M05", detail: "An evidence object is captured with provenance & freshness." },
  { id: "bundle", label: "Bundle draft", module: "M05", detail: "Captured objects are gathered into a draft bundle." },
  { id: "items", label: "Add items", module: "M05", detail: "Each evidence object is added as a bundle item." },
  { id: "submit", label: "Submit bundle", module: "M05", detail: "Submission freezes the bundle and computes its hash." },
  { id: "readiness", label: "Readiness query", module: "M08", detail: "M08 asks: is this basis SATISFIED_FOR_EVALUATION?" },
];

/* Per-proposal readiness (EvidenceReadinessStatus + reason + frozen bundle hash). */
window.AFB.readiness = [
  { proposal: "PRP-0418", bundle: "EB-0204", hash: "sha256:e204aa…91f", status: "SATISFIED_FOR_EVALUATION", cited: 3, reasons: [] },
  { proposal: "PRP-0419", bundle: "EB-0119", hash: "sha256:b119cd…40a", status: "INCOMPLETE", cited: 2, reasons: ["EVIDENCE_MISSINGNESS_BLOCKS_RELEASE"] },
  { proposal: "PRP-0420", bundle: "EB-0220", hash: "sha256:2204ef…7c2", status: "SATISFIED_FOR_EVALUATION", cited: 1, reasons: [] },
];
window.AFB.readinessTone = {
  SATISFIED_FOR_EVALUATION: "green", INCOMPLETE: "amber", CONFLICTED: "amber",
  STALE_FOR_USE: "amber", QUARANTINED: "red", FORBIDDEN_USE: "red", UNKNOWN: "muted",
};
/* evidence_status precedence (most → least blocking). */
window.AFB.evidenceStatusLegend = [
  { s: "USABLE", tone: "green", note: "Verified, fresh, usable." },
  { s: "STALE_FOR_USE", tone: "amber", note: "Past freshness window." },
  { s: "CONFLICTED", tone: "amber", note: "Unresolved conflict." },
  { s: "REVOKED_FOR_USE", tone: "red", note: "Use forbidden." },
  { s: "QUARANTINED", tone: "red", note: "Integrity/restriction hold." },
];
