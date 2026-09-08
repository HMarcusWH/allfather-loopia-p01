/* Allfather BI — M07 HMWH Council seed data. Vocabulary lifted verbatim from
   packages/allfather-control-substrate/src/m07/council.ts: review sessions
   (OPEN → AWAITING_ASSESSMENTS → READY_FOR_AGGREGATION → AGGREGATED), typed
   participant roles + independence classification (producer self-review as
   INDEPENDENT is DENIED), per-assessment recommendation / confidence band /
   evidence-sufficiency view, append-only dissent + risk-veto records, and a
   frozen result pinned not_approval_or_execution_authority = 1.

   There are no voters and no votes in M07 — aggregation freezes ONE result and
   PRESERVES disagreement; nothing is outvoted away. */
window.AFB = window.AFB || {};

window.AFB.hmwhPurposeLabel = {
  INTERNAL_BRIEFING_REVIEW: "Internal briefing",
  CONTROLLED_DEMO_ACTION_REVIEW: "Controlled demo action",
  REPLAY_TEST_REVIEW: "Replay test",
  COMPETITION_RELEVANCE_REVIEW: "Competition relevance (P01)",
  FIT_HYPOTHESIS_UNCERTAINTY_REVIEW: "Fit-hypothesis uncertainty (P03)",
  DISTRIBUTION_SENSITIVITY_REVIEW: "Distribution sensitivity (P04)",
  OUTREACH_COMPLIANCE_REVIEW: "Outreach compliance (P07)",
  TWIN_STATE_REVIEW: "Twin state (P02)",
  RELATIONSHIP_STATE_REVIEW: "Relationship state (P02)",
  MARKETING_ASSET_REVIEW: "Marketing asset (P05)",
  SOURCE_CONFIG_REVIEW: "Source config (GSMC)",
};
window.AFB.hmwhRoleLabel = {
  EVIDENCE_CRITIC: "Evidence critic",
  SCOPE_AND_POLICY_CRITIC: "Scope & policy critic",
  RISK_CRITIC: "Risk critic",
  GENERAL_REVIEWER: "General reviewer",
  FIXTURE_REVIEWER: "Fixture reviewer",
};
window.AFB.hmwhAssessLabel = {
  SUPPORT_PROCEED_TO_ARBITER: "Support · proceed",
  SUPPORT_WITH_CONDITIONS: "Support with conditions",
  REQUIRE_MORE_EVIDENCE: "Require more evidence",
  HOLD: "Hold",
  BLOCK: "Block",
  QUARANTINE_OR_INCIDENT_REVIEW: "Quarantine / incident",
};
window.AFB.hmwhAssessTone = {
  SUPPORT_PROCEED_TO_ARBITER: "green",
  SUPPORT_WITH_CONDITIONS: "gold",
  REQUIRE_MORE_EVIDENCE: "amber",
  HOLD: "amber",
  BLOCK: "red",
  QUARANTINE_OR_INCIDENT_REVIEW: "violet",
};
window.AFB.hmwhSufficiencyLabel = {
  SUFFICIENT_FOR_REVIEW: "Sufficient", INCOMPLETE: "Incomplete", CONFLICTED: "Conflicted",
  STALE: "Stale", QUARANTINED: "Quarantined", UNKNOWN: "Unknown",
};

/* Review sessions — newest first. */
window.AFB.hmwhSessions = [
  {
    id: "HRS-0431", proposal: "PRP-0431", mission: "MSN-0412",
    title: "Twin state change · Acme Logistics operating-status",
    purpose: "TWIN_STATE_REVIEW", mode: "MULTI_REVIEWER_DEMO",
    producer: "VK-Muninn", status: "AWAITING_ASSESSMENTS", opened: "2026-07-09 14:21",
    evidenceBundle: "EB-0311",
    participants: [
      { vk: "VK-Mimir", role: "EVIDENCE_CRITIC", independence: "INDEPENDENT_DECLARED_REVIEWER", eligibility: "ELIGIBLE",
        assessment: { rec: "SUPPORT_WITH_CONDITIONS", confidence: "MEDIUM", sufficiency: "SUFFICIENT_FOR_REVIEW",
          summary: "Twin change is evidence-backed; require the unknown headcount field stays UNKNOWN rather than inferred.",
          risks: ["Inference creep on unknown fields"], cited: 3 } },
      { vk: "VK-Huginn", role: "SCOPE_AND_POLICY_CRITIC", independence: "INDEPENDENT_DECLARED_REVIEWER", eligibility: "ELIGIBLE", assessment: null },
      { vk: "VK-Urd", role: "RISK_CRITIC", independence: "INDEPENDENT_DECLARED_REVIEWER", eligibility: "ELIGIBLE", assessment: null },
    ],
    eligibilityEvents: [],
    dissents: [], riskVetoes: [], result: null,
  },
  {
    id: "HRS-0419", proposal: "PRP-0419", mission: "MSN-0409",
    title: "Admit Acme Logistics to the Priority Outreach Queue",
    purpose: "FIT_HYPOTHESIS_UNCERTAINTY_REVIEW", mode: "MULTI_REVIEWER_DEMO",
    producer: "VK-Verdandi", status: "AGGREGATED", opened: "2026-07-06 10:12", closed: "2026-07-06 15:40",
    evidenceBundle: "EB-0298",
    participants: [
      { vk: "VK-Mimir", role: "EVIDENCE_CRITIC", independence: "INDEPENDENT_DECLARED_REVIEWER", eligibility: "ELIGIBLE",
        assessment: { rec: "REQUIRE_MORE_EVIDENCE", confidence: "HIGH", sufficiency: "INCOMPLETE",
          summary: "Procurement signal rests on one secondary source. Add a primary source or downgrade the claim.",
          risks: ["Single-source reliance"], cited: 2 } },
      { vk: "VK-Vor", role: "GENERAL_REVIEWER", independence: "INDEPENDENT_DECLARED_REVIEWER", eligibility: "ELIGIBLE",
        assessment: { rec: "SUPPORT_WITH_CONDITIONS", confidence: "MEDIUM", sufficiency: "INCOMPLETE",
          summary: "Fit hypothesis itself is sound; the funding-round evidence is primary. Condition on the procurement gap.",
          risks: [], cited: 3 } },
    ],
    eligibilityEvents: [],
    dissents: [],
    riskVetoes: [
      { id: "RV-0042", by: "VK-Mimir", type: "EVIDENCE_INTEGRITY_CONCERN",
        summary: "Procurement claim would enter the outreach queue on secondary sourcing only.",
        routing: "REQUIRE_MORE_EVIDENCE" },
    ],
    result: {
      rec: "REQUIRE_MORE_EVIDENCE", validity: "VALID_FOR_ARBITER_INPUT",
      summary: "Hold the queue admission until the procurement signal gains a primary source; funding-round claims stand.",
      confidence: "Reviewers agree on the gap; disagreement is on severity only.",
      limitations: ["No assessment of outreach content — out of this session's scope"],
      digest: "sha256:8e12aa…40cf", frozen: "2026-07-06 15:40",
    },
  },
  {
    id: "HRS-0418", proposal: "PRP-0418", mission: "MSN-0407",
    title: "Publish competitor battlecard · Tier-2 pricing shift",
    purpose: "INTERNAL_BRIEFING_REVIEW", mode: "MULTI_REVIEWER_DEMO",
    producer: "VK-Skuld", status: "AGGREGATED", opened: "2026-07-04 09:31", closed: "2026-07-04 10:03",
    evidenceBundle: "EB-0204",
    participants: [
      { vk: "VK-Mimir", role: "EVIDENCE_CRITIC", independence: "INDEPENDENT_DECLARED_REVIEWER", eligibility: "ELIGIBLE",
        assessment: { rec: "SUPPORT_PROCEED_TO_ARBITER", confidence: "HIGH", sufficiency: "SUFFICIENT_FOR_REVIEW",
          summary: "All three pricing claims cite captured primary sources, freshness ≤ 2h.",
          risks: [], cited: 3 } },
      { vk: "VK-Verdandi", role: "SCOPE_AND_POLICY_CRITIC", independence: "INDEPENDENT_DECLARED_REVIEWER", eligibility: "ELIGIBLE",
        assessment: { rec: "SUPPORT_WITH_CONDITIONS", confidence: "MEDIUM", sufficiency: "SUFFICIENT_FOR_REVIEW",
          summary: "Within advisory claim class if the positioning read is labelled analyst inference, not sourced fact.",
          risks: ["Claim-class drift if inference is presented as fact"], cited: 2 } },
      { vk: "VK-Urd", role: "RISK_CRITIC", independence: "INDEPENDENT_DECLARED_REVIEWER", eligibility: "ELIGIBLE",
        assessment: { rec: "REQUIRE_MORE_EVIDENCE", confidence: "MEDIUM", sufficiency: "INCOMPLETE",
          summary: "The launch-page rewrite is diffed once; a second capture would rule out an A/B variant.",
          risks: ["Single capture of a mutable page"], cited: 1 } },
      { vk: "VK-Skuld", role: "GENERAL_REVIEWER", independence: "NON_INDEPENDENT_SELF_CRITIQUE", eligibility: "CONDITIONAL",
        assessment: { rec: "SUPPORT_PROCEED_TO_ARBITER", confidence: "NOT_QUANTIFIED", sufficiency: "SUFFICIENT_FOR_REVIEW",
          summary: "Self-critique: response angle 3 is the weakest — flagged for the sales team's judgement.",
          risks: [], cited: 0 } },
    ],
    eligibilityEvents: [
      { vk: "VK-Skuld", claimed: "INDEPENDENT_DECLARED_REVIEWER", outcome: "DENIED",
        reason: "PRODUCER_SELF_REVIEW", proof: "SELF_REVIEW_ONLY",
        note: "The producing Valkyrie claimed independence on its own proposal — denied and recorded. Re-assigned as an explicit non-independent self-critique." },
    ],
    dissents: [
      { id: "DS-0027", type: "MINORITY_RECOMMENDATION", by: "VK-Urd", material: true,
        summary: "Risk critic holds that a second page capture should precede release. Preserved for the Arbiter; did not block aggregation." },
    ],
    riskVetoes: [],
    result: {
      rec: "PROCEED_TO_ARBITER", validity: "VALID_FOR_ARBITER_INPUT",
      summary: "Claims source-grounded; positioning interpretation within advisory scope. One dissent preserved and routed.",
      confidence: "High on pricing facts; medium on positioning inference.",
      limitations: ["Positioning read is analyst inference, not sourced fact"],
      digest: "sha256:c07d31…9b2e", frozen: "2026-07-04 10:03",
    },
  },
];

/* ── Valkyrie governed track record — DERIVED, never scored. Every count is a
   re-derivation over Decision Ledger / dispatch / M07 rows at the governed-seed
   baseline (2026-07-10); replay re-derives the same numbers. A track record is
   a record of governed outcomes, not an evaluation metric — no ranking exists
   anywhere in the substrate. Weeks are ISO weeks w21–w28. ─────────────────── */
window.AFB.vkRecordMeta = {
  weeks: ["w21", "w22", "w23", "w24", "w25", "w26", "w27", "w28"],
  basis: "Derived from M10 Decision Records, M06 dispatch decisions and M07 session rows · governed seed at baseline 2026-07-10",
  law: "A track record is a governed record, not a score. It never ranks, never authorizes, and never feeds promotion — reading it changes nothing.",
};
window.AFB.vkRecord = {
  "VK-Verdandi": {
    produced: [3, 4, 2, 5, 4, 3, 4, 5],
    outcomes: { admitted: 21, conditions: 5, held: 3, blocked: 1 },
    heldSeries: [1, 0, 0, 1, 0, 0, 1, 0],
    reviews: { assessments: 6, dissents: 0, vetoes: 0 },
    dispatch: null,
    sufficiency: { SUFFICIENT_FOR_REVIEW: 24, INCOMPLETE: 5, CONFLICTED: 1 },
    note: "Holds cluster on single-source procurement signals — the same gap each time (see HRS-0419).",
  },
  "VK-Skuld": {
    produced: [2, 2, 3, 2, 3, 2, 3, 3],
    outcomes: { admitted: 16, conditions: 3, held: 1, blocked: 0 },
    heldSeries: [0, 0, 1, 0, 0, 0, 0, 0],
    reviews: { assessments: 4, dissents: 0, vetoes: 0 },
    dispatch: { allowed: 6, denied: 1, held: 1 },
    sufficiency: { SUFFICIENT_FOR_REVIEW: 17, INCOMPLETE: 3, CONFLICTED: 0 },
    note: "All 4 as-reviewer rows are explicit self-critiques (SELF_REVIEW_ONLY) — never independent review.",
  },
  "VK-Mimir": {
    produced: [0, 0, 0, 0, 0, 0, 0, 0],
    outcomes: { admitted: 0, conditions: 0, held: 0, blocked: 0 },
    heldSeries: [0, 0, 0, 0, 0, 0, 0, 0],
    reviews: { assessments: 22, dissents: 3, vetoes: 2 },
    dispatch: null,
    sufficiency: { SUFFICIENT_FOR_REVIEW: 15, INCOMPLETE: 5, CONFLICTED: 2 },
    note: "Review critic — produces nothing by contract. Both risk vetoes routed REQUIRE_MORE_EVIDENCE; both upheld by the Arbiter.",
  },
  "VK-Urd": {
    produced: [1, 1, 1, 1, 1, 1, 1, 1],
    outcomes: { admitted: 8, conditions: 0, held: 0, blocked: 0 },
    heldSeries: [0, 0, 0, 0, 0, 0, 0, 0],
    reviews: { assessments: 9, dissents: 2, vetoes: 0 },
    dispatch: null,
    sufficiency: { SUFFICIENT_FOR_REVIEW: 8, INCOMPLETE: 1, CONFLICTED: 0 },
    note: "Weekly replay findings, all recorded clean. Its two dissents are both single-capture concerns — a consistent, preserved position.",
  },
  "VK-Huginn": {
    produced: [4, 5, 6, 4, 5, 6, 5, 6],
    outcomes: { admitted: 34, conditions: 4, held: 2, blocked: 1 },
    heldSeries: [0, 1, 0, 0, 1, 0, 0, 0],
    reviews: { assessments: 3, dissents: 0, vetoes: 0 },
    dispatch: null,
    sufficiency: { SUFFICIENT_FOR_REVIEW: 36, INCOMPLETE: 4, CONFLICTED: 1 },
    note: "Highest producer — company-signal briefs. The one block was a source-class violation caught at M08, quarantined clean.",
  },
  "VK-Muninn": {
    produced: [2, 3, 2, 3, 2, 2, 3, 2],
    outcomes: { admitted: 15, conditions: 3, held: 1, blocked: 0 },
    heldSeries: [0, 0, 1, 0, 0, 0, 0, 0],
    reviews: { assessments: 2, dissents: 0, vetoes: 0 },
    dispatch: null,
    sufficiency: { SUFFICIENT_FOR_REVIEW: 14, INCOMPLETE: 4, CONFLICTED: 1 },
    note: "Twin-state changes; conditions are all unknown-field honesty conditions (keep UNKNOWN unknown).",
  },
  "VK-Vor": {
    produced: [2, 2, 3, 3, 2, 3, 2, 3],
    outcomes: { admitted: 17, conditions: 2, held: 1, blocked: 0 },
    heldSeries: [0, 1, 0, 0, 0, 0, 0, 0],
    reviews: { assessments: 5, dissents: 1, vetoes: 0 },
    dispatch: null,
    sufficiency: { SUFFICIENT_FOR_REVIEW: 16, INCOMPLETE: 4, CONFLICTED: 0 },
    note: "Fit hypotheses — never buyer-intent. The dissent is severity-only on HRS-0419.",
  },
  "VK-Heimdall": {
    produced: [3, 3, 4, 3, 4, 3, 4, 4],
    outcomes: { admitted: 26, conditions: 2, held: 0, blocked: 0 },
    heldSeries: [0, 0, 0, 0, 0, 0, 0, 0],
    reviews: { assessments: 2, dissents: 0, vetoes: 0 },
    dispatch: null,
    sufficiency: { SUFFICIENT_FOR_REVIEW: 27, INCOMPLETE: 1, CONFLICTED: 0 },
    note: "Role-scoped briefing routings; distribution-sensitivity conditions only.",
  },
  "VK-Bragi": {
    produced: [1, 2, 1, 2, 2, 1, 2, 2],
    outcomes: { admitted: 11, conditions: 2, held: 0, blocked: 0 },
    heldSeries: [0, 0, 0, 0, 0, 0, 0, 0],
    reviews: { assessments: 1, dissents: 0, vetoes: 0 },
    dispatch: null,
    sufficiency: { SUFFICIENT_FOR_REVIEW: 12, INCOMPLETE: 1, CONFLICTED: 0 },
    note: "Marketing asset drafts, internal-only ceiling; every citation resolves or the draft is refused at compose.",
  },
  "VK-Forseti": {
    produced: [1, 1, 2, 1, 1, 2, 1, 1],
    outcomes: { admitted: 8, conditions: 2, held: 0, blocked: 0 },
    heldSeries: [0, 0, 0, 0, 0, 0, 0, 0],
    reviews: { assessments: 1, dissents: 0, vetoes: 0 },
    dispatch: { allowed: 8, denied: 2, held: 0 },
    sufficiency: { SUFFICIENT_FOR_REVIEW: 9, INCOMPLETE: 1, CONFLICTED: 0 },
    note: "SYNTHETIC dispatch only. Both denials are consent-gap fail-closes — the firewall doing its job.",
  },
};
