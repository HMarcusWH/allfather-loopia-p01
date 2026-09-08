/* Allfather BI — M02 Access & Authority + M11 Assurance seed data.
   Vocabulary canonical to packages/allfather-control-substrate/src/m02 (PERMISSIONS,
   ROLE_DEFINITIONS, AccessDecisionValue, AccessReasonCode) and /src/m11 (IncidentClass,
   TimelineEntryType, ContainmentClass, AuditCategory, GapType, GapEffectOnV1,
   ChangeProposalType, ReplayOutcomeClassification). */
window.AFB = window.AFB || {};

/* ---- M02 · roles (verbatim ROLE_DEFINITIONS) ---- */
window.AFB.roles = [
  { key: "ROLE_TENANT_ADMIN", name: "Tenant Administrator", note: "Tenant & workspace administration, identity and role assignment.",
    perms: ["tenant.read", "tenant.administer", "workspace.read", "workspace.create", "workspace.administer", "identity.read", "identity.register", "identity.suspend_revoke", "role.assign", "role.revoke"] },
  { key: "ROLE_WORKSPACE_OPERATOR", name: "Workspace Operator", note: "Runs missions, submits evidence & proposals. Cannot approve actions.",
    perms: ["workspace.read", "mission.read", "mission.create", "mission.activate", "mission.transition", "evidence.read", "evidence.submit", "evidence.annotate", "proposal.read", "proposal.submit", "hmwh.read", "arbiter.read", "decision.read", "internal_release.read", "tool.invoke"] },
  { key: "ROLE_ACTION_APPROVER", name: "Controlled Action Approver", note: "Sole holder of approval authority for an exact frozen artifact.",
    perms: ["approval.read", "approval.decide_exact_artifact", "decision.read"] },
  { key: "ROLE_AUDITOR", name: "Auditor", note: "Reads decisions, verifies ledger integrity, reviews incidents & replays.",
    perms: ["decision.read", "decision.verify_integrity", "incident.read", "replay.read"] },
  { key: "ROLE_INCIDENT_REVIEWER", name: "Incident Reviewer", note: "Manages incident cases and reviews related decisions & replays.",
    perms: ["incident.read", "incident.manage", "decision.read", "replay.read"] },
  { key: "ROLE_DEMO_VIEWER", name: "Demo Viewer", note: "Read-only visibility of workspace, decisions and internal releases.",
    perms: ["workspace.read", "decision.read", "internal_release.read"] },

  /* ---- Commercial-module roles (each landed by its module's registration;
     narrow, module-scoped permissions — the broad future permissions stay EXCLUDED). ---- */
  { key: "ROLE_COMPETITION_MONITOR", name: "Competition Monitor", note: "P01 · read competition signals, battlecards and source freshness. Read-only.", mod: "P01",
    perms: ["competitor.read", "company_signal.read", "battlecard.read", "source_freshness.read"] },
  { key: "ROLE_ENTITY_TWIN_CURATOR", name: "Entity Twin Curator", note: "P02 · propose + read twin and relationship state changes. The broad company_twin.promote_or_modify stays EXCLUDED.", mod: "P02",
    perms: ["entity_twin.read", "entity_twin.propose_state_change", "entity_relationship.read", "entity_relationship.propose", "offering_twin.read", "stakeholder_role_profile.read"] },
  { key: "ROLE_LEAD_QUALIFIER", name: "Lead Qualifier", note: "P03 · read prospects + priority outreach queue, review admissions, upload the customer catalogue.", mod: "P03",
    perms: ["prospect.read", "priority_outreach_queue.read", "priority_outreach_queue.review", "prospect_battlecard.read", "customer_catalogue.upload", "fit_hypothesis.read"] },
  { key: "ROLE_INTELLIGENCE_DISTRIBUTOR", name: "Intelligence Distributor", note: "P04 · read + route role briefings, acknowledge, configure targets. Never covert stakeholder scoring.", mod: "P04",
    perms: ["distribution.read", "role_briefing.read", "distribution.route", "distribution.acknowledge", "stakeholder_role.read", "distribution.configure_targets"] },
  { key: "ROLE_MARKETING_CUSTOMIZER", name: "Marketing Customizer", note: "P05 · propose + review evidence-cited marketing drafts. Internal only; no publish.", mod: "P05",
    perms: ["marketing_asset.read", "marketing_asset.propose", "marketing_asset.review"] },
  { key: "ROLE_CRM_EXECUTOR", name: "CRM Executor (drafter)", note: "P07 · draft outreach + request approval. SEPARATE from the approver seat (separation of duties).", mod: "P07",
    perms: ["outreach.read", "outreach.draft", "consent.read", "outreach.request_approval"] },
  { key: "ROLE_CRM_APPROVER", name: "CRM Approver", note: "P07 · the sole holder of outreach approval authority. Cannot draft what it approves.", mod: "P07",
    perms: ["approval.read", "outreach.approve", "decision.read"] },
];

/* ---- Subscription tiers (Gap-024 · subscription_tiers ↔ permissions; read by
   M12 surface-visibility AND M06 capability gate). Two-gate default-deny: a held
   role AND an active tier that grants the permission. Ascending price + governance. ---- */
window.AFB.tiers = [
  { key: "TIER_MONITOR",      name: "Monitor",      fee: "99 kr / mo",   tone: "cyan",
    modules: ["P01 · read-only signals"],
    note: "Entry tier — competition signals and source freshness, read-only." },
  { key: "TIER_INTELLIGENCE", name: "Intelligence", fee: "299 kr / mo",  tone: "violet",
    modules: ["P01 full", "P03 · prospect reads"],
    note: "Adds lead-qualification reads + the priority outreach queue." },
  { key: "TIER_COMMAND",      name: "Command",      fee: "999 kr / mo",  tone: "gold",
    modules: ["P03 · catalogue upload", "P04 · distribution", "P02 · twin network", "P05 · marketing"],
    note: "Adds the modules that write tenant-owned data + role distribution." },
  { key: "TIER_GOVERNED_OPS", name: "Governed Ops", fee: "2 999 kr / mo", tone: "green",
    modules: ["P04 · heavier governance", "P07 · CRM execution"],
    note: "The heaviest-governance tier — the ONLY tier that unlocks P07 CRM execution (D10)." },
];
window.AFB.tierTone = { TIER_MONITOR: "cyan", TIER_INTELLIGENCE: "violet", TIER_COMMAND: "gold", TIER_GOVERNED_OPS: "green" };

/* default-deny evaluation sequence (evaluateAccessRequest order). */
window.AFB.accessSteps = [
  { step: "Excluded permission", detail: "Prohibited V1 permission → hard deny before any role/scope check.", fail: "EXCLUDED_PERMISSION_REQUESTED", sev: "S5" },
  { step: "Tenant active", detail: "Tenant must exist and be ACTIVE.", fail: "TENANT_NOT_ACTIVE", sev: "S2" },
  { step: "Principal identity", detail: "Operator/service mapped, in-tenant and ACTIVE.", fail: "IDENTITY_NOT_MAPPED · TENANT_SCOPE_MISMATCH", sev: "S2–S4" },
  { step: "Workspace scope", detail: "Workspace required, in-tenant and ACTIVE.", fail: "WORKSPACE_SCOPE_MISMATCH", sev: "S4" },
  { step: "Active membership", detail: "Principal holds an active role assignment here.", fail: "ROLE_ASSIGNMENT_NOT_ACTIVE", sev: "S2" },
  { step: "Permission granted", detail: "A held role actually grants the requested permission.", fail: "PERMISSION_NOT_GRANTED", sev: "S1" },
  { step: "Allow", detail: "Granted — downstream governance gates (M03–M10) still apply.", fail: null, sev: null },
];

/* a recent access-evaluation log (append-only audit). decision ∈ ALLOW|DENY|HOLD_FOR_REVIEW. */
window.AFB.accessLog = [
  { at: "11:38", actor: "K. Lindqvist", role: "ROLE_ACTION_APPROVER", perm: "approval.decide_exact_artifact", decision: "ALLOW", reason: "GRANTED", sev: null },
  { at: "11:36", actor: "M. Hermansson", role: "ROLE_WORKSPACE_OPERATOR", perm: "approval.decide_exact_artifact", decision: "DENY", reason: "PERMISSION_NOT_GRANTED", sev: "S1" },
  { at: "11:25", actor: "svc:m04-missions", role: "SERVICE", perm: "external_message.approve_or_send", decision: "DENY", reason: "EXCLUDED_PERMISSION_REQUESTED", sev: "S5" },
  { at: "11:18", actor: "M. Hermansson", role: "ROLE_WORKSPACE_OPERATOR", perm: "proposal.submit", decision: "ALLOW", reason: "GRANTED", sev: null },
  { at: "09:14", actor: "VK-Skuld", role: "VALKYRIE", perm: "tool.invoke", decision: "DENY", reason: "IDENTITY_NOT_MAPPED", sev: "S2" },
];
window.AFB.accessDecisionTone = { ALLOW: "green", DENY: "red", HOLD_FOR_REVIEW: "amber" };

/* permission groups for display (purely cosmetic grouping of the 38 keys). */
window.AFB.permGroups = [
  { g: "Tenant & workspace", keys: ["tenant.read", "tenant.administer", "workspace.read", "workspace.create", "workspace.administer"] },
  { g: "Identity & roles", keys: ["identity.read", "identity.register", "identity.suspend_revoke", "role.assign", "role.revoke", "runtime_host_binding.read", "runtime_host_binding.administer"] },
  { g: "Valkyrie & missions", keys: ["valkyrie.read", "valkyrie.register", "valkyrie.administer_contract", "mission.read", "mission.create", "mission.activate", "mission.transition"] },
  { g: "Evidence, proposal, review", keys: ["evidence.read", "evidence.submit", "evidence.annotate", "proposal.read", "proposal.submit", "hmwh.initiate_review", "hmwh.read", "arbiter.read"] },
  { g: "Approval, decision, replay", keys: ["approval.read", "approval.decide_exact_artifact", "decision.read", "decision.verify_integrity", "replay.read", "replay.initiate_test_only", "internal_release.read"] },
  { g: "Incident, policy, tools", keys: ["incident.read", "incident.manage", "policy_change.propose", "tool.invoke"] },
];

/* ---- M11 · Assurance ---- */
window.AFB.assuranceSummary = {
  replay: "SAME_ROUTE", replayPass: true,
  audit: "CONTROL_PASS", openIncidents: 1, blockingGaps: 2,
};

/* incident cases. status ∈ OPEN|CONTAINMENT_RECOMMENDED|CONTAINMENT_ACKNOWLEDGED|RESOLVED|… */
window.AFB.incidents = [
  {
    id: "INC-0042", cls: "FORBIDDEN_CAPABILITY_ATTEMPT", sev: "S3", status: "RESOLVED", trigger: "M08",
    summary: "Proposal PRP-0421 requested an effect-bearing budget action outside the V1 safe set; Arbiter BLOCK opened an incident.",
    timeline: [
      { t: "OPENED", at: "11:25", by: "SYSTEM", note: "Opened from Arbiter BLOCK · PROHIBITED_CAPABILITY." },
      { t: "TRIAGE", at: "11:27", by: "INCIDENT_REVIEWER", note: "OPEN_CASE · class FORBIDDEN_CAPABILITY_ATTEMPT." },
      { t: "CONTAINMENT_RECOMMENDED", at: "11:31", by: "M11", note: "BLOCK_ACTION_PATH → M06 (urgency URGENT)." },
      { t: "CONTAINMENT_ACKNOWLEDGED", at: "11:40", by: "M06", note: "Owner response APPLIED — dispatch path pinned off." },
      { t: "RESOLVED", at: "11:52", by: "INCIDENT_REVIEWER", note: "Verified: no dispatch occurred. Reliance unaffected." },
    ],
  },
  {
    id: "INC-0044", cls: "PROVENANCE_OR_IP", sev: "S2", status: "CONTAINMENT_ACKNOWLEDGED", trigger: "M05",
    summary: "Procurement signal cited without a verified primary source on PRP-0419; provenance path quarantined pending evidence.",
    timeline: [
      { t: "OPENED", at: "09:21", by: "SYSTEM", note: "Opened from evidence missingness on PRP-0419." },
      { t: "TRIAGE", at: "09:25", by: "INCIDENT_REVIEWER", note: "OPEN_CASE · class PROVENANCE_OR_IP." },
      { t: "CONTAINMENT_RECOMMENDED", at: "09:30", by: "M11", note: "QUARANTINE_EVIDENCE_OR_PROVENANCE_PATH → M05." },
      { t: "CONTAINMENT_ACKNOWLEDGED", at: "09:44", by: "M05", note: "Owner response APPLIED — claim held for evidence." },
    ],
  },
];
window.AFB.incidentClassTone = { INTEGRITY: "red", AUTHORITY_BYPASS: "red", SCOPE_OR_TENANT_BREACH: "red", FORBIDDEN_CAPABILITY_ATTEMPT: "red", PROVENANCE_OR_IP: "violet", UNKNOWN_OR_UNEXPECTED_EFFECT: "amber", LEDGER_INTEGRITY: "red", DISCLOSURE_OR_OVERCLAIM: "amber", OTHER: "muted" };
window.AFB.incidentStatusTone = { OPEN: "amber", CONTAINMENT_RECOMMENDED: "violet", CONTAINMENT_ACKNOWLEDGED: "cyan", RESOLVED: "green" };

/* ledger-integrity audit run (read-only; CONTROL_PASS). */
window.AFB.auditRun = {
  id: "AR-LI-0007", category: "LEDGER_INTEGRITY", finding: "CONTROL_PASS", sev: "S1",
  check: "LEDGER_CHAIN_AND_REFERENCE_INTEGRITY", chain: "PASS", refs: "PASS", records: 7, at: "2026-06-04 11:43",
  summary: "Decision ledger chain and references verified. No silent repair; a FAIL would open an incident (INV-014).",
};

/* gap register. effect_on_v1 BLOCK_* / REQUIRE_INCIDENT_REVIEW = blocking (INV-018, stays visible). */
window.AFB.gaps = [
  { id: "GAP-014", type: "RUNTIME_GAP", plane: "CORE", effect: "BLOCK_FEATURE", status: "OPEN", summary: "Effect-bearing execution adapters are not in the V1 safe set; consequential actions remain blocked." },
  { id: "GAP-016", type: "VALIDATION_GAP", plane: "OPS", effect: "REQUIRE_INCIDENT_REVIEW", status: "OPEN", summary: "Procurement-signal source class lacks a verified provenance path; claims relying on it are held." },
  { id: "GAP-011", type: "POLICY_GAP", plane: "MULTI_PLANE", effect: "QUALIFY_CLAIM", status: "OPEN", summary: "Cross-tenant benchmarking would need an explicit data-use policy; claims are qualified until then." },
];
window.AFB.gapEffectTone = { NONE: "muted", QUALIFY_CLAIM: "amber", BLOCK_FEATURE: "red", BLOCK_RELEASE: "red", REQUIRE_INCIDENT_REVIEW: "violet" };

/* governed change proposals — PROPOSAL ONLY, never active law (INV-016). */
window.AFB.changeProposals = [
  { id: "GCP-016", type: "POLICY_CHANGE", status: "DRAFT", promotion: "INCOMPLETE", sourceGap: "GAP-016",
    summary: "Promote a verified provenance path for procurement signals so qualified leads can escalate with evidence." },
  { id: "GCP-014", type: "TOOL_OR_CAPABILITY_PROMOTION", status: "DRAFT", promotion: "INCOMPLETE", sourceGap: "GAP-014",
    summary: "Evaluate a governed execution adapter behind approval gates for a future effect-bearing module." },
];
