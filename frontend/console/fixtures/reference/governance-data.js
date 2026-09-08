/* Allfather BI — governance-plane seed data (M10 Decision Ledger + integrity
   chain, M09 Approval / Frozen Action Artifact / Execution Authorization).
   Vocabulary canonical to packages/allfather-control-substrate (DecisionRecordType,
   DecisionMaterialityClass, DecisionPrimaryOutcomeStatus, DecisionRelianceStatus,
   DecisionReferenceRole, AdapterType, ApprovalDecisionType, artifact/authorization
   status flows). */
window.AFB = window.AFB || {};

/* M10 — the append-only, hash-chained Decision Ledger stream. */
window.AFB.ledgerStream = {
  id: "DLS-demo-01", env: "DEMO", streamKey: "ledger:demo",
  genesis: "sha256:9b21c0…anchor", head: "DR-0007", headHash: "sha256:4cc9d6…a84f2b",
  verify: { chain: "PASS", refs: "PASS", at: "2026-06-04 11:43", records: 7, purpose: "PRE_RELEASE" },
};

/* Decision records (append-only). seq is the chain height; reliance is expressed by
   appending supersession rows, never by editing the original. */
window.AFB.ledgerRecords = [
  {
    seq: 20, id: "DR-0071", at: "2026-08-28 07:08",
    type: "INTERNAL_INFORMATIONAL_RELEASE", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "ACTIVE_AS_RECORDED", env: "DEMO",
    summary: "Internal informational release recorded after M08 ALLOW verdict — Boreal Tier-2 pricing response play.",
    reasons: ["M10_INTERNAL_RELEASE_RECORDED"], severity: null, incident: false,
    payload: "sha256:c07cb5…payld", prev: "sha256:d3b544…0906", chain: "sha256:629391…139a",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0418", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0418", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M05", t: "evidence_bundle", id: "EB-0204", role: "EVIDENCE_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M07", t: "hmwh_result", id: "HRS-0418", role: "REVIEW_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 19, id: "DR-0068", at: "2026-08-27 23:00",
    type: "INTERNAL_INFORMATIONAL_RELEASE", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "ACTIVE_AS_RECORDED", env: "DEMO",
    summary: "Internal informational release recorded after M08 ALLOW verdict — Fjordr positioning signal briefing.",
    reasons: ["M10_INTERNAL_RELEASE_RECORDED"], severity: null, incident: false,
    payload: "sha256:090e5a…payld", prev: "sha256:e4b8f2…b645", chain: "sha256:d3b544…0906",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0411", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0411", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M05", t: "evidence_object", id: "EV-0724", role: "EVIDENCE_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 18, id: "DR-0059", at: "2026-08-19 05:04",
    type: "DECISION_CORRECTION_OR_INVALIDATION", materiality: "GOVERNANCE_MATERIAL",
    outcome: "CORRECTED_OR_INVALIDATED", reliance: "ACTIVE_AS_RECORDED", supersedes: "DR-0039", env: "DEMO",
    summary: "Correction appended: a licensed excerpt exceeded its permitted use. Visibility deactivated and reliance withdrawn for RA-0396.",
    reasons: ["M10_CORRECTION_APPENDED"], severity: "S3", incident: true,
    payload: "sha256:0e148c…payld", prev: "sha256:a1563b…774d", chain: "sha256:e4b8f2…b645",
    refs: [
      { m: "M11", t: "audit_finding", id: "AUD-0112", role: "INCIDENT_OR_AUDIT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M10", t: "decision_record", id: "DR-0039", role: "OTHER", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 17, id: "DR-0061", at: "2026-08-14 09:12",
    type: "SOURCE_CONFIG_APPROVED", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "ACTIVE_AS_RECORDED", supersedes: "DR-0048", env: "DEMO",
    summary: "Source monitoring configuration v3 approved for boreal.se/pricing — tier-2 list-price threshold trigger added.",
    reasons: ["GSMC_CONFIG_VERSION_ADMITTED"], severity: null, incident: false,
    payload: "sha256:740f57…payld", prev: "sha256:ac53b7…820b", chain: "sha256:a1563b…774d",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0388", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0388", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M03", t: "source_monitoring_config_version", id: "SMCV-0114-3", role: "AUTHORITY_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 16, id: "DR-0044", at: "2026-08-12 11:20",
    type: "INTERNAL_INFORMATIONAL_RELEASE", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "SUPERSEDED", env: "DEMO",
    summary: "Internal informational release recorded after M08 ALLOW verdict — Kvant Industri entity context snapshot. Later superseded on freshness.",
    reasons: ["M10_INTERNAL_RELEASE_RECORDED"], severity: null, incident: false,
    payload: "sha256:0daf40…payld", prev: "sha256:8b851a…5023", chain: "sha256:ac53b7…820b",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0402", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0402", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 15, id: "DR-0039", at: "2026-08-05 16:40",
    type: "INTERNAL_INFORMATIONAL_RELEASE", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "INVALIDATED_FOR_CURRENT_RELIANCE", env: "DEMO",
    summary: "Internal informational release recorded after M08 ALLOW verdict — sector cost-structure dossier. Reliance later withdrawn by DR-0059.",
    reasons: ["M10_INTERNAL_RELEASE_RECORDED"], severity: "S3", incident: true,
    payload: "sha256:bd7261…payld", prev: "sha256:56fbf0…f1a2", chain: "sha256:8b851a…5023",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0389", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0389", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 14, id: "DR-0058", at: "2026-08-04 10:30",
    type: "SOURCE_CONFIG_APPROVED", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "ACTIVE_AS_RECORDED", env: "DEMO",
    summary: "Source monitoring configuration approved for acme.com/news — 3h fetch cadence, assisted autonomy.",
    reasons: ["GSMC_CONFIG_VERSION_ADMITTED"], severity: null, incident: false,
    payload: "sha256:9ca8bd…payld", prev: "sha256:c8f75b…09c1", chain: "sha256:56fbf0…f1a2",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0396", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0396", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M03", t: "source_monitoring_config_version", id: "SMCV-0126-1", role: "AUTHORITY_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 13, id: "DR-0057", at: "2026-08-04 10:22",
    type: "SOURCE_CONFIG_APPROVED", materiality: "GOVERNANCE_MATERIAL",
    outcome: "HELD", reliance: "ACTIVE_AS_RECORDED", env: "DEMO",
    summary: "Source monitoring configuration approved for the Acme procurement portal with monitoring held pending a source-terms review.",
    reasons: ["GSMC_CONFIG_VERSION_ADMITTED", "GSMC_MONITORING_HELD_PENDING_TERMS_REVIEW"], severity: null, incident: false,
    payload: "sha256:4f2ce7…payld", prev: "sha256:c5c43b…b76c", chain: "sha256:c8f75b…09c1",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0397", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0397", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M03", t: "source_monitoring_config_version", id: "SMCV-0129-1", role: "AUTHORITY_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 12, id: "DR-0040", at: "2026-08-02 07:55",
    type: "SOURCE_CONFIG_APPROVED", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "ACTIVE_AS_RECORDED", supersedes: "DR-0029", env: "DEMO",
    summary: "Source monitoring configuration v2 approved for the Börsdata financials feed — cadence relaxed to daily after the licence review.",
    reasons: ["GSMC_CONFIG_VERSION_ADMITTED"], severity: null, incident: false,
    payload: "sha256:24e915…payld", prev: "sha256:bcda03…6bf9", chain: "sha256:c5c43b…b76c",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0362", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0362", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M03", t: "source_monitoring_config_version", id: "SMCV-0121-2", role: "AUTHORITY_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 11, id: "DR-0048", at: "2026-07-30 08:40",
    type: "SOURCE_CONFIG_SUPERSEDED", materiality: "GOVERNANCE_MATERIAL",
    outcome: "CORRECTED_OR_INVALIDATED", reliance: "SUPERSEDED", supersedes: "DR-0031", env: "DEMO",
    summary: "Source monitoring configuration v2 superseded for boreal.se/pricing — fetch cadence tightened from 6h to 1h after two missed same-day changes.",
    reasons: ["GSMC_CONFIG_VERSION_SUPERSEDED"], severity: null, incident: false,
    payload: "sha256:77ab79…payld", prev: "sha256:1768c3…8f4b", chain: "sha256:bcda03…6bf9",
    refs: [
      { m: "M03", t: "source_monitoring_config_version", id: "SMCV-0114-2", role: "AUTHORITY_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M10", t: "decision_record", id: "DR-0031", role: "OTHER", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 10, id: "DR-0055", at: "2026-07-18 14:05",
    type: "SOURCE_CONFIG_APPROVED", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "ACTIVE_AS_RECORDED", env: "DEMO",
    summary: "Source monitoring configuration approved for fjordr.io/launch — 6h fetch cadence, positioning-shift trigger.",
    reasons: ["GSMC_CONFIG_VERSION_ADMITTED"], severity: null, incident: false,
    payload: "sha256:355e5b…payld", prev: "sha256:13e753…05f6", chain: "sha256:1768c3…8f4b",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0391", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0391", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M03", t: "source_monitoring_config_version", id: "SMCV-0117-1", role: "AUTHORITY_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 9, id: "DR-0031", at: "2026-07-11 09:05",
    type: "SOURCE_CONFIG_APPROVED", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "SUPERSEDED", env: "DEMO",
    summary: "Source monitoring configuration v1 approved for boreal.se/pricing — content-hash trigger only.",
    reasons: ["GSMC_CONFIG_VERSION_ADMITTED"], severity: null, incident: false,
    payload: "sha256:04cc12…payld", prev: "sha256:d4f758…9796", chain: "sha256:13e753…05f6",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0355", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0355", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M03", t: "source_monitoring_config_version", id: "SMCV-0114-1", role: "AUTHORITY_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 8, id: "DR-0029", at: "2026-07-11 08:48",
    type: "SOURCE_CONFIG_APPROVED", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "SUPERSEDED", env: "DEMO",
    summary: "Source monitoring configuration v1 approved for the Börsdata financials feed.",
    reasons: ["GSMC_CONFIG_VERSION_ADMITTED"], severity: null, incident: false,
    payload: "sha256:e8ecdc…payld", prev: "sha256:4cc9d6…a84f2b", chain: "sha256:d4f758…9796",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0341", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0341", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M03", t: "source_monitoring_config_version", id: "SMCV-0121-1", role: "AUTHORITY_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 7, id: "DR-0007", at: "2026-06-04 11:42",
    type: "INTERNAL_INFORMATIONAL_RELEASE", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "ACTIVE_AS_RECORDED", env: "DEMO",
    summary: "Internal informational release recorded after M08 ALLOW verdict · Q1 pricing response battlecard.",
    reasons: ["M10_INTERNAL_RELEASE_RECORDED"], severity: null, incident: false,
    payload: "sha256:4cc9d6…payld", prev: "sha256:a91b3f…7d30", chain: "sha256:4cc9d6…a84f2b",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0418", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0418", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M05", t: "evidence_bundle", id: "EB-0204", role: "EVIDENCE_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M07", t: "hmwh_result", id: "HR-0418", role: "REVIEW_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 6, id: "DR-0006", at: "2026-06-03 16:08",
    type: "HARMLESS_DEMO_ACTION_COMPLETED", materiality: "ACTION_MATERIAL",
    outcome: "EXECUTED_SUCCESS", reliance: "ACTIVE_AS_RECORDED", env: "DEMO",
    summary: "Harmless demo notification dispatched and acknowledged. Full M08→M09→M06 chain present.",
    reasons: ["M10_ACTION_OUTCOME_RECORDED"], severity: null, incident: false,
    payload: "sha256:a91b3f…payld", prev: "sha256:0f4e88…12cc", chain: "sha256:a91b3f…7d30",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0411", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M09", t: "approval_decision", id: "AD-0101", role: "APPROVAL_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M09", t: "frozen_action_artifact", id: "FA-0101", role: "ARTIFACT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M09", t: "execution_authorization", id: "EA-0101", role: "AUTHORIZATION_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M06", t: "tool_execution_outcome", id: "TX-0101", role: "OUTCOME_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 5, id: "DR-0005", at: "2026-06-02 09:21",
    type: "DECISION_CORRECTION_OR_INVALIDATION", materiality: "GOVERNANCE_MATERIAL",
    outcome: "CORRECTED_OR_INVALIDATED", reliance: "ACTIVE_AS_RECORDED", supersedes: "DR-0004", env: "DEMO",
    summary: "Correction appended: wk21 digest superseded after a source was reclassified as inferred.",
    reasons: ["M10_CORRECTION_APPENDED"], severity: "S2", incident: false,
    payload: "sha256:0f4e88…payld", prev: "sha256:77aa10…9e01", chain: "sha256:0f4e88…12cc",
    refs: [
      { m: "M10", t: "decision_record", id: "DR-0004", role: "OTHER", v: "VERIFIED_REFERENCE" },
      { m: "M05", t: "evidence_bundle", id: "EB-0118", role: "EVIDENCE_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
  {
    seq: 4, id: "DR-0004", at: "2026-05-30 14:55",
    type: "INTERNAL_INFORMATIONAL_RELEASE", materiality: "GOVERNANCE_MATERIAL",
    outcome: "RELEASED_INTERNAL", reliance: "SUPERSEDED", env: "DEMO",
    summary: "Weekly market digest released internally (Nordic SaaS cohort, wk21). Superseded by DR-0005.",
    reasons: ["M10_INTERNAL_RELEASE_RECORDED"], severity: null, incident: false,
    payload: "sha256:77aa10…payld", prev: "sha256:5d02aa…prev", chain: "sha256:77aa10…9e01",
    refs: [
      { m: "M08", t: "arbiter_verdict", id: "AV-0331", role: "VERDICT_BASIS", v: "VERIFIED_REFERENCE" },
      { m: "M04", t: "proposal_envelope", id: "PRP-0402", role: "PROPOSAL_BASIS", v: "VERIFIED_REFERENCE" },
    ],
  },
];

/* M09 — a live CONTROLLED_DEMO_ACTION moving through the approval pipeline.
   In V1 only harmless demo actions execute, and only via: verdict → human approval
   → frozen artifact → single-use execution authorization → dispatch → record. */
window.AFB.approvalCase = {
  ref: "AR-0102", proposal: "PRP-0420", verdict: "AV-0420",
  title: "Post internal demo notification · battlecard published",
  actionClass: "HARMLESS_DEMO_ACTION", adapter: "INTERNAL_DEMO_NOTIFICATION",
  effect: "Posts a single internal demo notification announcing the battlecard release.",
  scope: "workspace · Competitive Intelligence → channel #intel-demo",
  env: "DEMO", ttl: 300, singleUse: true,
  approverRole: "ROLE_ACTION_APPROVER", permission: "approval.decide_exact_artifact",
  payloadDigest: "sha256:2d9e74…b4c1", artifactHash: "sha256:7f3108…aa90",
  // pipeline stages — M08 verdict already issued (REQUIRE_HUMAN_APPROVAL), awaiting human approval.
  stages: [
    { id: "verdict",   label: "Arbiter verdict",        sub: "REQUIRE_HUMAN_APPROVAL", module: "M08", icon: "scale" },
    { id: "approval",  label: "Human approval",         sub: "Exact-artifact decision", module: "M09", icon: "pen-line" },
    { id: "freeze",    label: "Freeze artifact",        sub: "Exact-payload binding", module: "M09", icon: "lock" },
    { id: "authorize", label: "Execution authorization", sub: "Single-use · TTL 300s", module: "M09", icon: "key-round" },
    { id: "dispatch",  label: "Dispatch",               sub: "Governed tool gateway", module: "M06", icon: "send" },
    { id: "record",    label: "Decision Record",        sub: "Outcome appended to ledger", module: "M10", icon: "book-marked" },
  ],
  integrity: [
    { purpose: "BEFORE_AUTHORIZATION", after: "authorize" },
    { purpose: "BEFORE_DISPATCH", after: "dispatch" },
  ],
};

/* Presentation maps (canonical token → readable label + tone). */
window.AFB.recordTypeLabel = {
  INTERNAL_INFORMATIONAL_RELEASE: "Internal release", PROVISIONAL_ANALYSIS_DISPLAY: "Provisional display",
  PROPOSAL_HELD: "Proposal held", PROPOSAL_BLOCKED: "Proposal blocked", PROPOSAL_QUARANTINED: "Proposal quarantined",
  INCIDENT_EVALUATION_ROUTED: "Incident routed", TOOL_OPERATION_DENIED_NON_DISPATCH: "Tool op denied",
  TOOL_OPERATION_FAILED_OR_UNKNOWN: "Tool op failed", HARMLESS_DEMO_ACTION_COMPLETED: "Demo action completed",
  HARMLESS_DEMO_ACTION_PREVENTED: "Demo action prevented", HARMLESS_DEMO_ACTION_FAILED: "Demo action failed",
  HARMLESS_DEMO_ACTION_OUTCOME_UNKNOWN: "Demo action unknown", APPROVAL_OR_AUTHORIZATION_REVOKED: "Authorization revoked",
  REPLAY_TEST_FINDING_RECORDED: "Replay finding", AUDIT_FINDING_LINKED: "Audit finding",
  POLICY_OR_PROVENANCE_BLOCK_RECORDED: "Policy block", DECISION_CORRECTION_OR_INVALIDATION: "Correction",
  SOURCE_CONFIG_APPROVED: "Source config approved", SOURCE_CONFIG_SUPERSEDED: "Source config superseded",
};
window.AFB.relianceTone = {
  ACTIVE_AS_RECORDED: "green", HISTORICAL_ONLY: "muted", SUPERSEDED: "amber",
  INVALIDATED_FOR_CURRENT_RELIANCE: "red", UNDER_REVIEW: "violet",
};
window.AFB.materialityTone = {
  GOVERNANCE_MATERIAL: "gold", ACTION_MATERIAL: "amber", SECURITY_MATERIAL: "red",
  AUDIT_MATERIAL: "cyan", REPLAY_TEST_MATERIAL: "muted",
};
