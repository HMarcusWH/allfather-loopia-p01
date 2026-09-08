/* Allfather BI — repo-surface seed data: MCM-HMWH Diagnostics + standalone-
   migration readiness. Both are read-only mirrors of 2026-07-07/-10 repo
   imports; neither grants, implies, or displays live capability. */
window.AFB = window.AFB || {};

/* ── MCM-HMWH diagnostic MVP (2026-07-07) — schema/report slice only.
   A governed diagnostic operator layer: it reads recorded evidence, scores
   candidate explanations deterministically, red-teams its own rigor, and stops
   at a manual review gate it never performs. Runtime-inert. ───────────────── */
window.AFB.mcm = {
  imported: "2026-07-07",
  classification: "RUNTIME-INERT · schema/report slice",
  ceiling: "INTERNAL_MCM_HMWH_PROMPTOPS_META_VALIDATION_LINKED_RUNTIME_INERT",
  law: "A diagnosis is never truth. A score never authorizes. The manual review gate is the product — hard-closed, and never performed by this layer. There is no analyze affordance anywhere; only records to read.",
  pins: ["manualReviewRequired: true", "externalActionAuthorized: false"],
  proof: "13 source files · 6 afb: commands — schemas · evaluator · harness · agent-role registry · implementation plan · MVP report",
  roles: "12 typed agent roles — registered as configuration, not runtime agents",
  flow: [
    { id: 1, name: "Evidence packets", tone: "cyan", gate: null,
      note: "Recorded inputs only — no live observation, no fetch." },
    { id: 2, name: "Diagnostic case", tone: "cyan", gate: null,
      note: "A governed case object opened over the packets; scope is pinned at open." },
    { id: 3, name: "Candidate claims", tone: "cyan", gate: null,
      note: "Hypotheses about hidden structure — candidates, never findings of record." },
    { id: 4, name: "Deterministic HMWH scoring", tone: "violet", gate: "deterministic",
      note: "Same scoring lineage as the M07 council, different object family. Replayable: same inputs, same scores." },
    { id: 5, name: "Diagnostic-theater check", tone: "violet", gate: "red-team",
      note: "Adversarial pass asking one question: does this diagnosis merely LOOK rigorous?" },
    { id: 6, name: "Manual review gate", tone: "gold", gate: "HARD-CLOSED",
      note: "Requires a human act this layer never performs. Nothing passes it by default — or at all, today." },
    { id: 7, name: "Frozen diagnosis artifact", tone: "amber", gate: null,
      note: "Sealed and content-addressed; later information annotates it, never edits it." },
    { id: 8, name: "Outcome annotation", tone: "amber", gate: null,
      note: "What actually happened, recorded against the frozen artifact." },
    { id: 9, name: "Config-version rollback record", tone: "amber", gate: null,
      note: "Any config change that ever followed a diagnosis carries its rollback reference." },
  ],
  /* Six fixture cases — fixture mirror; the negative control is the point. */
  cases: [
    { id: "MCM-F1", label: "Margin drift · fixture",           verdict: "hidden structure surfaced → gated", neg: false },
    { id: "MCM-F2", label: "Churn cluster · fixture",          verdict: "hidden structure surfaced → gated", neg: false },
    { id: "MCM-F3", label: "Supply delay cascade · fixture",   verdict: "hidden structure surfaced → gated", neg: false },
    { id: "MCM-F4", label: "Pricing-page regression · fixture",verdict: "hidden structure surfaced → gated", neg: false },
    { id: "MCM-F5", label: "Lead-quality dip · fixture",       verdict: "hidden structure surfaced → gated", neg: false },
    { id: "MCM-F6", label: "No-hidden-structure control",      verdict: "correctly finds nothing — no invented structure", neg: true },
  ],
};

/* ── Standalone migration readiness (2026-07-10) — A0 complete + owner-
   requested sweep audit. Preparation is evidence work; it grants nothing. ─── */
window.AFB.migration = {
  classification: "PREPARATION · NOT PROMOTION",
  audited: "Sweep audit 2026-07-10 · repo head b0c02cf (audit ran at 91dcf26a)",
  law: "Extraction grants no connector, credential, or execution authority — the whole-substrate ceiling is unchanged. During the campaign this repo becomes the read-only source of truth; the standalone repo is built beside it, never inside it.",
  gate: { label: "Readiness gate", value: "60/60 checks green", extra: "+ 21-test refusal suite" },
  phases: [
    { id: "A0", name: "Freeze + attestation", state: "COMPLETE", tone: "green",
      note: "Attested per-file freeze manifest (v2) · owner decision log · API baseline · stale-document ledger · disposition inventory (what lifts, what stays host) · root-intent map." },
    { id: "A1", name: "Scaffold standalone repo", state: "NEXT · APPROVED", tone: "cyan",
      note: "Build the skeleton in a sibling work area — approved as the next phase, not yet begun." },
    { id: "A2–A4", name: "Lift · parity · cutover rehearsal", state: "FUTURE", tone: "muted",
      note: "Each phase its own owner-gated act; the host repo stays read-only throughout." },
    { id: "M5 · C1 · H1 · M6", name: "Later campaign phases", state: "FUTURE", tone: "muted",
      note: "Sequenced only after the A-phases complete." },
  ],
  findings: [
    { id: "F1", state: "REPAIRED", note: "Stale freeze manifest — regenerated and attested (v2)" },
    { id: "F2", state: "REPAIRED", note: "Unrecorded repair lane — recorded" },
    { id: "F6", state: "REPAIRED", note: "Repaired in the same sweep" },
    { id: "F3", state: "OPEN", note: "Declaration-hash parity can flake on a cold cache" },
    { id: "F4", state: "OPEN", note: "Migration gate not CI-enforced yet" },
    { id: "F5", state: "OPEN", note: "40 latent strict-tsc errors" },
  ],
  proofLine: "Audit lane 2026-07-10: 4,901 runtime tests pass · 551 files format-clean · 1,457 doc links resolved, 0 broken",
};
