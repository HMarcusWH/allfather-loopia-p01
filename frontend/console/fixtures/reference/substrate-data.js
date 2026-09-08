/* Allfather BI — substrate-aligned seed data (M03 Valkyrie registry + Capability
   Contracts, M08 Arbiter check sequences). Vocabulary is lifted verbatim from
   packages/allfather-control-substrate (ValkyrieRoleClass, ValkyrieStatus,
   ContractStatus, ToolRight.maximumActionClass, OutputClass, failClosedBehavior,
   ArbiterVerdict, ArbiterPermittedNextStage, HmwhResultRecommendation). */
window.AFB = window.AFB || {};

/* The action-class authority ladder (ToolRight.maximumActionClass). The last two
   are the V1 prohibited set — the Arbiter fail-closes any proposal requesting them. */
window.AFB.actionLadder = [
  { id: "OBSERVE",                     label: "Observe",              v1: "admissible" },
  { id: "ANALYZE",                     label: "Analyze",              v1: "admissible" },
  { id: "RELEASE_INTELLIGENCE",        label: "Release intelligence", v1: "admissible" },
  { id: "PREPARE_EXTERNAL_ACTION",     label: "Prepare external action", v1: "gated" },
  { id: "EXECUTE_CONSEQUENTIAL_ACTION",label: "Execute consequential action", v1: "prohibited" },
];

/* FORBIDDEN_CONTRACT_KEYWORDS — the hard ceiling. No Capability Contract may grant
   any of these; a draft requesting one is rejected (EXCLUDED_CAPABILITY_REQUESTED). */
window.AFB.v1Excluded = [
  "crm.send", "campaign.modify", "external_message.approve_or_send",
  "trade.propose_or_execute", "company_twin.promote_or_modify",
  "sensitive_inference.access_or_act", "maso.access", "public_publish",
  "unrestricted_tool.execute", "policy.activate_automatically",
  "ledger.modify_history", "integrity.override",
];

const ROLE_LABEL = {
  RESEARCH_SOURCE_VALKYRIE: "Research / source intelligence",
  REVIEW_CRITIC_VALKYRIE: "Review critic (HMWH)",
  DEMO_ACTION_PREPARER_VALKYRIE: "Action preparer",
  REPLAY_TEST_VALKYRIE: "Replay / test",
  COMPETITION_MONITOR_VALKYRIE: "Competition monitor (P01)",
  ENTITY_TWIN_CURATOR_VALKYRIE: "Entity twin curator (P02)",
  LEAD_QUALIFIER_VALKYRIE: "Lead qualifier (P03)",
  INTELLIGENCE_DISTRIBUTOR_VALKYRIE: "Intelligence distributor (P04)",
  MARKETING_CUSTOMIZER_VALKYRIE: "Marketing customizer (P05)",
  CRM_EXECUTION_VALKYRIE: "CRM execution (P07)",
};
window.AFB.roleLabel = ROLE_LABEL;

/* Canonical Valkyrie registry. status ∈ DRAFT|ACTIVE|SUSPENDED|REVOKED|RETIRED.
   Each ACTIVE valkyrie carries one ACTIVE Capability Contract (versioned). */
window.AFB.registry = [
  {
    id: "VK-Verdandi", display: "Verdandi", roleClass: "RESEARCH_SOURCE_VALKYRIE", status: "ACTIVE",
    purpose: "Continuous market & lead source monitoring; produces evidence bundles and research notes.",
    contract: {
      id: "CC-18", version: 3, status: "ACTIVE", validFrom: "2026-02-11", validUntil: "2026-08-11",
      maxAutonomy: "Observes and analyzes public sources only. Produces proposals; never releases, approves or executes.",
      evidenceObligation: "Every claim must cite a captured Evidence Object with provenance and freshness.",
      escalationObligation: "Conflicting or stale evidence is routed to HMWH; never resolved unilaterally.",
      allowed: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE"],
      toolRights: [
        { tool: "source.fetch", ops: ["read"], cls: "READ_ONLY_SOURCE", max: "OBSERVE", flags: ["evidence capture"] },
        { tool: "change.diff", ops: ["compare"], cls: "INTERNAL_TRANSFORM", max: "ANALYZE", flags: [] },
        { tool: "brief.compose", ops: ["draft"], cls: "INTERNAL_TRANSFORM", max: "RELEASE_INTELLIGENCE", flags: [] },
      ],
      dataScopes: [
        { type: "PERMITTED_PUBLIC_RESOURCES", desc: "Competitor & market public web, press, filings." },
        { type: "PERMITTED_DOCUMENTS", desc: "Operator-supplied product & ICP documents." },
      ],
      outputs: ["INTERNAL_RESEARCH_NOTE", "EVIDENCE_BUNDLE_PROPOSAL", "INTERNAL_BRIEFING_PROPOSAL"],
      review: [{ trigger: "Conflicted or stale evidence", path: ["HMWH"], failClosed: "REQUIRE_REVIEW" }],
      forbidden: [{ cat: "public_publish", sev: "S4", desc: "May not publish to any external surface." }],
    },
  },
  {
    id: "VK-Skuld", display: "Skuld", roleClass: "DEMO_ACTION_PREPARER_VALKYRIE", status: "ACTIVE",
    purpose: "Prepares sales/marketing response artifacts (battlecards, escalations) as frozen proposals for approval.",
    contract: {
      id: "CC-21", version: 2, status: "ACTIVE", validFrom: "2026-03-02", validUntil: "2026-09-02",
      maxAutonomy: "Prepares external-action proposals only. Output is frozen for exact operator signature; no autonomous send.",
      evidenceObligation: "Each prepared action binds to the Evidence Spine and an Arbiter verdict before freeze.",
      escalationObligation: "Any effect-bearing route is held for HMWH → Arbiter → approval; never auto-dispatched.",
      allowed: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE", "PREPARE_EXTERNAL_ACTION"],
      toolRights: [
        { tool: "source.fetch", ops: ["read"], cls: "READ_ONLY_SOURCE", max: "OBSERVE", flags: ["evidence capture"] },
        { tool: "battlecard.compose", ops: ["draft", "revise"], cls: "INTERNAL_TRANSFORM", max: "RELEASE_INTELLIGENCE", flags: [] },
        { tool: "demo.action.prepare", ops: ["stage"], cls: "DEMO_SIDE_EFFECT", max: "PREPARE_EXTERNAL_ACTION", flags: ["approval required", "frozen artifact required"] },
      ],
      dataScopes: [
        { type: "PERMITTED_PUBLIC_RESOURCES", desc: "Competitor public surfaces." },
        { type: "MISSION_SUBMITTED_EVIDENCE", desc: "Evidence bundles submitted by active missions." },
      ],
      outputs: ["EVIDENCE_BUNDLE_PROPOSAL", "INTERNAL_BRIEFING_PROPOSAL", "HARMLESS_DEMO_ACTION_PROPOSAL"],
      review: [{ trigger: "Any PREPARE_EXTERNAL_ACTION output", path: ["HMWH", "Arbiter", "M09 Approval"], failClosed: "REQUIRE_REVIEW" }],
      forbidden: [
        { cat: "external_message.approve_or_send", sev: "S4", desc: "May not approve or send any external message." },
        { cat: "campaign.modify", sev: "S3", desc: "May not modify live campaigns or budgets." },
      ],
    },
  },
  {
    id: "VK-Mimir", display: "Mímir", roleClass: "REVIEW_CRITIC_VALKYRIE", status: "ACTIVE",
    purpose: "Independent HMWH critic — challenges evidence, scope and risk. Cannot review its own producer's work.",
    contract: {
      id: "CC-24", version: 1, status: "ACTIVE", validFrom: "2026-04-19", validUntil: "2026-10-19",
      maxAutonomy: "Assesses and dissents only. Produces HMWH review assessments; has no release, approval or execution path.",
      evidenceObligation: "Assessments cite the exact evidence refs reviewed; missingness is recorded, not inferred.",
      escalationObligation: "Risk concerns are filed as risk vetoes with recommended Arbiter routing.",
      allowed: ["OBSERVE", "ANALYZE"],
      toolRights: [
        { tool: "evidence.read", ops: ["read"], cls: "READ_ONLY_SOURCE", max: "OBSERVE", flags: ["evidence capture"] },
        { tool: "assessment.compose", ops: ["draft"], cls: "INTERNAL_TRANSFORM", max: "ANALYZE", flags: [] },
      ],
      dataScopes: [
        { type: "MISSION_SUBMITTED_EVIDENCE", desc: "Evidence bundles under review; no broader access." },
      ],
      outputs: ["HMWH_REVIEW_ASSESSMENT"],
      review: [{ trigger: "Producer self-review attempt", path: ["Denied — independence required"], failClosed: "DENY" }],
      forbidden: [{ cat: "external_message.approve_or_send", sev: "S4", desc: "Critic has no external surface." }],
    },
  },
  {
    id: "VK-Urd", display: "Urðr", roleClass: "REPLAY_TEST_VALKYRIE", status: "ACTIVE",
    purpose: "Runs no-side-effect replays of sealed decisions against fixtures to assure reconstructability.",
    contract: {
      id: "CC-09", version: 5, status: "ACTIVE", validFrom: "2026-01-08", validUntil: "2026-07-08",
      maxAutonomy: "Replays against synthetic fixtures only. Read-only; produces test findings, mutates nothing.",
      evidenceObligation: "Replay findings reference the exact Decision Record and bundle hash replayed.",
      escalationObligation: "Integrity divergence opens an incident for ledger-integrity evaluation.",
      allowed: ["OBSERVE", "ANALYZE"],
      toolRights: [
        { tool: "replay.run", ops: ["execute-noop"], cls: "INTERNAL_TRANSFORM", max: "ANALYZE", flags: ["no side effect"] },
      ],
      dataScopes: [
        { type: "SYNTHETIC_TEST_FIXTURES", desc: "Replay fixtures; no production data." },
        { type: "INTERNAL_DEMO_RECORDS", desc: "Sealed demo Decision Records." },
      ],
      outputs: ["REPLAY_TEST_ASSESSMENT"],
      review: [{ trigger: "Integrity divergence on replay", path: ["M11 Incident"], failClosed: "QUARANTINE" }],
      forbidden: [{ cat: "ledger.modify_history", sev: "S5", desc: "May never modify ledger history or override integrity." }],
    },
  },
  {
    id: "VK-Eir", display: "Eir", roleClass: "DEMO_ACTION_PREPARER_VALKYRIE", status: "DRAFT",
    purpose: "Customer-success intelligence preparer. Registered but not yet activated — contract in draft.",
    contract: {
      id: "CC-31", version: 1, status: "DRAFT", validFrom: "—", validUntil: "—",
      maxAutonomy: "Draft. No authority is granted until the contract is activated by an operator.",
      evidenceObligation: "—", escalationObligation: "—",
      allowed: ["OBSERVE", "ANALYZE"],
      toolRights: [{ tool: "source.fetch", ops: ["read"], cls: "READ_ONLY_SOURCE", max: "OBSERVE", flags: ["evidence capture"] }],
      dataScopes: [{ type: "PERMITTED_DOCUMENTS", desc: "Pending scope definition." }],
      outputs: ["INTERNAL_RESEARCH_NOTE"],
      review: [], forbidden: [],
    },
  },

  /* ── Commercial-module Valkyries (each registered by its module's single-call
     registration: registerP0X… lands the Valkyrie + Capability Contract + the
     ACTIVE M08 policy set + subscription tiers). Mythic display names; canonical
     role classes verbatim from the registration code. ───────────────────────── */
  {
    id: "VK-Huginn", display: "Huginn", roleClass: "COMPETITION_MONITOR_VALKYRIE", status: "ACTIVE",
    purpose: "Lawful public-source competition monitoring → relevance-reviewed, Arbiter-admitted company-signal & battlecard intelligence. P01 · owner-gated.",
    contract: {
      id: "CC-41", version: 1, status: "ACTIVE", validFrom: "2026-06-05", validUntil: "2026-12-05",
      maxAutonomy: "Bounded: read-only capture + analysis + internal release recording only; never an external/consequential action.",
      evidenceObligation: "Every signal/battlecard must cite captured snapshot evidence + a change-detection reference.",
      escalationObligation: "Stale source / non-reconciling diff / low source authority → finding + incident; never silent repair.",
      allowed: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE"],
      toolRights: [
        { tool: "governed:p01-competition-read", ops: ["fetch"], cls: "READ_ONLY_SOURCE", max: "RELEASE_INTELLIGENCE", flags: ["evidence capture", "egress-guarded"] },
      ],
      dataScopes: [
        { type: "PERMITTED_PUBLIC_RESOURCES", desc: "Approved public competitor sources (per source-card allowlist)." },
        { type: "MISSION_SUBMITTED_EVIDENCE", desc: "Evidence bundles submitted to the monitoring mission." },
        { type: "SYNTHETIC_TEST_FIXTURES", desc: "Deterministic fixtures for tests/replay (the network is never hit)." },
      ],
      outputs: ["COMPANY_SIGNAL_BRIEF_PROPOSAL", "BATTLECARD_BRIEF_PROPOSAL"],
      review: [{ trigger: "Any customer-visible release of competition intelligence.", path: ["M07 Relevance review", "M08 Arbiter"], failClosed: "HOLD" }],
      forbidden: [
        { cat: "EXTERNAL_MESSAGING_OR_CRM_MUTATION", sev: "S5", desc: "No external send or CRM write — no Class D/E effect." },
        { cat: "PUBLIC_OR_CUSTOMER_PUBLISH", sev: "S5", desc: "May not publish to any external surface." },
      ],
    },
  },
  {
    id: "VK-Muninn", display: "Muninn", roleClass: "ENTITY_TWIN_CURATOR_VALKYRIE", status: "ACTIVE",
    purpose: "Proposes evidence-backed twin & relationship state changes for the Entity Twin Network. P02 · owner-gated cp11 (2026-06-10) with explicit limitations — offering twins excluded.",
    contract: {
      id: "CC-44", version: 1, status: "ACTIVE", validFrom: "2026-06-08", validUntil: "2026-12-08",
      maxAutonomy: "Bounded: read tenant-owned + released data, compose versioned twin state, internal release recording only; never an external action; never an unsupported claim filled as fact.",
      evidenceObligation: "A released supported-fact field must bind a source link; unknowns stay UNKNOWN, conflicts stay visible.",
      escalationObligation: "Person/employee overreach, private external data, first-party leakage → BLOCK + incident; never silent repair.",
      allowed: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE"],
      toolRights: [
        { tool: "governed:p02-twin-compose", ops: ["compose"], cls: "INTERNAL_TRANSFORM", max: "RELEASE_INTELLIGENCE", flags: [] },
      ],
      dataScopes: [
        { type: "INTERNAL_DEMO_RECORDS", desc: "Tenant-owned company data + released P01 signals (read)." },
        { type: "MISSION_SUBMITTED_EVIDENCE", desc: "Evidence bundles submitted to the twin-curation mission." },
        { type: "SYNTHETIC_TEST_FIXTURES", desc: "Deterministic fixtures for tests/replay." },
      ],
      outputs: ["ENTITY_TWIN_STATE_CHANGE", "ENTITY_RELATIONSHIP_STATE_CHANGE"],
      review: [{ trigger: "Any twin/relationship state release.", path: ["M07 Twin state review", "M08 Arbiter"], failClosed: "HOLD" }],
      forbidden: [
        { cat: "COVERT_EMPLOYEE_OR_STAKEHOLDER_SCORING", sev: "S5", desc: "No psychological / loyalty / productivity / sensitive-trait field on any person." },
        { cat: "COMPANY_TWIN_PROMOTE_OR_MODIFY", sev: "S5", desc: "The broad company_twin.promote_or_modify stays EXCLUDED (INV-P02-010)." },
        { cat: "EXTERNAL_MESSAGING_OR_CRM_MUTATION", sev: "S5", desc: "No external side effect anywhere in P02." },
      ],
    },
  },
  {
    id: "VK-Vor", display: "V\u00f6r", roleClass: "LEAD_QUALIFIER_VALKYRIE", status: "ACTIVE",
    purpose: "Pairs tenant-owned products with released competitor signals \u2192 fit hypotheses (never buyer-intent) \u2192 reviewed, Arbiter-admitted prospect outputs. P03 · owner-gated.",
    contract: {
      id: "CC-46", version: 1, status: "ACTIVE", validFrom: "2026-06-06", validUntil: "2026-12-06",
      maxAutonomy: "Bounded: internal transform + analysis + internal release recording only; never an external action; never a buyer-intent claim without a cited trigger.",
      evidenceObligation: "Every fit hypothesis cites catalogue ingestion evidence + a derived FIT_HYPOTHESIS_BASIS_REFERENCE; a queue admission cites a buying-trigger signal.",
      escalationObligation: "Stale catalogue / stale signal pairing / buyer-intent overclaim → finding + incident; never silent repair.",
      allowed: ["ANALYZE", "RELEASE_INTELLIGENCE"],
      toolRights: [
        { tool: "governed:p03-catalogue-transform", ops: ["normalize"], cls: "INTERNAL_TRANSFORM", max: "ANALYZE", flags: ["no network"] },
      ],
      dataScopes: [
        { type: "INTERNAL_DEMO_RECORDS", desc: "Tenant-owned customer catalogues + released P01 company signals (read)." },
        { type: "MISSION_SUBMITTED_EVIDENCE", desc: "Evidence bundles submitted to the lead-qualification mission." },
        { type: "SYNTHETIC_TEST_FIXTURES", desc: "Deterministic fixtures for tests/replay." },
      ],
      outputs: ["FIT_HYPOTHESIS_PROPOSAL", "MONITORED_PROSPECT_PROPOSAL", "PRIORITY_OUTREACH_QUEUE_ITEM_PROPOSAL", "PROSPECT_BATTLECARD_BRIEF_PROPOSAL"],
      review: [{ trigger: "Any priority-outreach queue admission.", path: ["M07 HMWH review", "M08 Arbiter"], failClosed: "HOLD" }],
      forbidden: [
        { cat: "COVERT_EMPLOYEE_OR_STAKEHOLDER_SCORING", sev: "S5", desc: "No covert stakeholder scoring (D7)." },
        { cat: "EXTERNAL_MESSAGING_OR_CRM_MUTATION", sev: "S5", desc: "No external send or CRM write." },
      ],
    },
  },
  {
    id: "VK-Heimdall", display: "Heimdall", roleClass: "INTELLIGENCE_DISTRIBUTOR_VALKYRIE", status: "ACTIVE",
    purpose: "Routes M10-recorded P01/P03 intelligence to authorized org-roles as role-scoped briefings + who-needs-to-know routing + read/ack assurance. P04 · owner-gated.",
    contract: {
      id: "CC-48", version: 1, status: "ACTIVE", validFrom: "2026-06-07", validUntil: "2026-12-07",
      maxAutonomy: "Bounded: read released records + compose + internal release recording only; never an external action; never covert scoring; never an individual identifier in released output.",
      evidenceObligation: "Every routing cites the M10 decision record of its source; a stakeholder observation cites a PUBLIC source card (role-class only, never an individual).",
      escalationObligation: "Cross-role view / redaction miss / individual-identifier leak → finding + incident; never silent repair.",
      allowed: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE"],
      toolRights: [
        { tool: "governed:p04-distribution-read", ops: ["read"], cls: "READ_ONLY_SOURCE", max: "RELEASE_INTELLIGENCE", flags: ["no network"] },
      ],
      dataScopes: [
        { type: "INTERNAL_DEMO_RECORDS", desc: "The M10-recorded P01 released_briefings + P03 released_prospect_outputs (read)." },
        { type: "MISSION_SUBMITTED_EVIDENCE", desc: "Evidence bundles submitted to the distribution mission." },
        { type: "PERMITTED_PUBLIC_RESOURCES", desc: "ONLY for public-evidenced, role-class stakeholder observations (never an individual)." },
        { type: "SYNTHETIC_TEST_FIXTURES", desc: "Deterministic fixtures for tests/replay." },
      ],
      outputs: ["ROLE_DISTRIBUTION_BRIEFING_PROPOSAL", "DISTRIBUTION_ROUTING_PROPOSAL"],
      review: [{ trigger: "Any role-distribution routing.", path: ["M07 HMWH review", "M08 Arbiter"], failClosed: "HOLD" }],
      forbidden: [
        { cat: "COVERT_EMPLOYEE_OR_STAKEHOLDER_SCORING", sev: "S5", desc: "Read/ack may never become covert employee/stakeholder scoring (D7)." },
        { cat: "EXTERNAL_MESSAGE_DELIVERY", sev: "S5", desc: "Distribution is internal in-console release only; any outbound send is P07 (D8)." },
      ],
    },
  },
  {
    id: "VK-Bragi", display: "Bragi", roleClass: "MARKETING_CUSTOMIZER_VALKYRIE", status: "ACTIVE",
    purpose: "Composes marketing assets from released intelligence into evidence-cited internal drafts. P05 · owner-gated (2026-06-10) for the no-live-effect ceiling; the rollback drill (CHG-101) proved the full stand-down.",
    contract: {
      id: "CC-50", version: 1, status: "ACTIVE", validFrom: "2026-06-10", validUntil: "2026-12-10",
      maxAutonomy: "Bounded: compose + internal release recording only; the draft-only invariant is storage-pinned — no publish, no send, no dispatch, no CRM write, no customer-visible output.",
      evidenceObligation: "Every competitor/comparative claim must cite valid, fresh, in-scope marketing evidence.",
      escalationObligation: "A forbidden marketing pattern (dark pattern / deception / sensitive-trait targeting) → BLOCK + incident; never silent repair.",
      allowed: ["ANALYZE", "RELEASE_INTELLIGENCE"],
      toolRights: [{ tool: "governed:p05-asset-compose", ops: ["draft"], cls: "INTERNAL_TRANSFORM", max: "RELEASE_INTELLIGENCE", flags: ["no network"] }],
      dataScopes: [
        { type: "INTERNAL_DEMO_RECORDS", desc: "Released P01/P02/P03 intelligence (read)." },
        { type: "SYNTHETIC_TEST_FIXTURES", desc: "Deterministic fixtures for tests/replay." },
      ],
      outputs: ["MARKETING_ASSET_DRAFT_PROPOSAL"],
      review: [{ trigger: "Any marketing-asset admission.", path: ["M07 review", "M08 Arbiter · P05_MARKETING_ASSET_ADMISSION"], failClosed: "HOLD" }],
      forbidden: [
        { cat: "PUBLISH_OR_SEND_MARKETING_OUTPUT", sev: "S5", desc: "Draft-only invariant is storage-pinned — no PUBLISHED value, no external-channel column exists." },
        { cat: "FORBIDDEN_MARKETING_PATTERN", sev: "S4", desc: "Dark patterns, deception, manipulative pressure, sensitive-trait targeting → BLOCK + incident." },
      ],
    },
  },
  {
    id: "VK-Forseti", display: "Forseti", roleClass: "CRM_EXECUTION_VALKYRIE", status: "ACTIVE",
    purpose: "Prepares governed CRM outreach \u2014 consent + PII firewall + mandatory approval + frozen artifact + single-use authorization \u2192 SYNTHETIC dispatch only. P07-A · owner-gated; live send is P07-B (blocked).",
    contract: {
      id: "CC-52", version: 1, status: "ACTIVE", validFrom: "2026-06-07", validUntil: "2026-12-07",
      maxAutonomy: "Bounded: read released records + prepare a frozen, approval-bound outreach + a single-use synthetic dispatch only. No external effect (P07-A); the live send + external action class are the separate P07-B gate.",
      evidenceObligation: "An outreach cites the upstream M10 decision record + a derived CONSENT_STATE_REFERENCE (never raw content); the recipient is referenced only by pseudonym.",
      escalationObligation: "Failed/unknown delivery is recorded truthfully + flagged for triage; never an auto-retry.",
      allowed: ["OBSERVE", "ANALYZE", "RELEASE_INTELLIGENCE"],
      toolRights: [
        { tool: "governed:p07-released-read", ops: ["read"], cls: "READ_ONLY_SOURCE", max: "RELEASE_INTELLIGENCE", flags: ["no network"] },
        { tool: "governed:p07-outreach-dispatch", ops: ["dispatch"], cls: "DEMO_SIDE_EFFECT", max: "RELEASE_INTELLIGENCE", flags: ["synthetic NO_OP", "single-use", "approval required", "frozen artifact required"] },
      ],
      dataScopes: [
        { type: "INTERNAL_DEMO_RECORDS", desc: "The released P01/P03/P04 chain + the consent registry (read)." },
        { type: "MISSION_SUBMITTED_EVIDENCE", desc: "Evidence bundles submitted to the outreach mission." },
        { type: "SYNTHETIC_TEST_FIXTURES", desc: "Deterministic fixtures for tests/replay." },
      ],
      outputs: ["CRM_OUTREACH_EXECUTION_PROPOSAL"],
      review: [{ trigger: "Any outreach execution.", path: ["M07 Compliance review", "M08 Arbiter", "M09 Approval"], failClosed: "HOLD" }],
      forbidden: [
        { cat: "SEND_WITHOUT_CONSENT_OR_LAWFUL_BASIS", sev: "S5", desc: "Non-overridable consent/lawful-basis BLOCK." },
        { cat: "SEND_WITHOUT_HUMAN_APPROVAL", sev: "S5", desc: "Mandatory M09 approval; separation of duties." },
        { cat: "AUTO_RETRY_ON_FAILURE", sev: "S4", desc: "No retry column exists (SF-011)." },
        { cat: "MUTATE_FROZEN_PAYLOAD", sev: "S5", desc: "The frozen artifact's digest is cross-checked against the approved digest." },
        { cat: "BULK_UNTARGETED_SEND", sev: "S4", desc: "Per-recipient frozen artifact only." },
      ],
    },
  },
];

/* M08 Arbiter — per-proposal evaluation: canonical verdict, permitted next stage,
   reason codes, and the fixed V1 check sequence
   (V1 scope → M05 evidence → M07 HMWH → terminal). decision ∈ ArbiterVerdict. */
window.AFB.arbiterDetail = {
  "PRP-0418": {
    verdict: "ALLOW_INFORMATIONAL_RELEASE", next: "M10_INTERNAL_RELEASE_RECORDING",
    reasons: ["ARBITER_ADMISSIBLE_FOR_INTERNAL_RELEASE"], severity: null, policy: "v1.default.admissibility · v1",
    checks: [
      { cat: "V1_SCOPE", type: "PROHIBITION_CHECK", decision: "PASS" },
      { cat: "M05_EVIDENCE", type: "ENUM_STATUS", decision: "PASS" },
      { cat: "M07_HMWH", type: "REQUIRED_REFERENCE", decision: "PASS" },
      { cat: "TERMINAL", type: "BY_PROPOSAL_TYPE", decision: "ALLOW" },
    ],
  },
  "PRP-0419": {
    verdict: "REQUIRE_MORE_EVIDENCE", next: "M05_ADDITIONAL_EVIDENCE_PATH",
    reasons: ["ARBITER_EVIDENCE_NOT_READY", "INSUFFICIENT_EVIDENCE"], severity: "S2", policy: "v1.default.admissibility · v1",
    checks: [
      { cat: "V1_SCOPE", type: "PROHIBITION_CHECK", decision: "PASS" },
      { cat: "M05_EVIDENCE", type: "ENUM_STATUS", decision: "FAIL" },
      { cat: "M07_HMWH", type: "REQUIRED_REFERENCE", decision: "NOT_APPLICABLE" },
    ],
  },
  "PRP-0421": {
    verdict: "BLOCK", next: "NO_PROGRESSION",
    reasons: ["PROHIBITED_CAPABILITY", "ARBITER_REQUESTED_ROUTE_OUTSIDE_V1"], severity: "S3", policy: "v1.default.admissibility · v1",
    checks: [
      { cat: "V1_SCOPE", type: "PROHIBITION_CHECK", decision: "FAIL" },
    ],
  },
  "DR-0007": {
    verdict: "ALLOW_INFORMATIONAL_RELEASE", next: "M10_INTERNAL_RELEASE_RECORDING",
    reasons: ["ARBITER_ADMISSIBLE_FOR_INTERNAL_RELEASE"], severity: null, policy: "v1.default.admissibility · v1",
    checks: [
      { cat: "V1_SCOPE", type: "PROHIBITION_CHECK", decision: "PASS" },
      { cat: "M05_EVIDENCE", type: "ENUM_STATUS", decision: "PASS" },
      { cat: "M07_HMWH", type: "REQUIRED_REFERENCE", decision: "PASS" },
      { cat: "TERMINAL", type: "BY_PROPOSAL_TYPE", decision: "ALLOW" },
    ],
  },
};

/* M08 policy-set registry. The V1 default set + one per commercial module, each
   seeded + activated by the module's registration. A SCOPED set only evaluates a
   same-scope proposal (CHG-093); the V1 seeds outrank every module rule (M08
   non-bypassable). status ∈ ACTIVE|DRAFT. */
window.AFB.policySets = [
  { key: "v1.default.admissibility", module: "V1", status: "ACTIVE", rules: 4,
    note: "The base ceiling — prohibition → evidence → HMWH → terminal. Outranks every module rule.",
    highlights: ["V1_SCOPE prohibition", "M05 evidence status", "M07 HMWH reference"] },
  { key: "P01_COMPETITION_RELEVANCE", module: "P01", status: "ACTIVE", rules: 5,
    note: "Admits company-signal / battlecard releases on relevance, source freshness and authority.",
    highlights: ["source freshness", "diff significance", "authority class"] },
  { key: "P02_TWIN_STATE_ADMISSION", module: "P02", status: "ACTIVE", rules: 15,
    note: "Admits twin state changes; 5 of the 15 are BLOCK+incident privacy/integrity rules.",
    highlights: ["employee-scoring BLOCK", "external-private-data BLOCK", "direct-mutation BLOCK"] },
  { key: "P02_RELATIONSHIP_STATE_ADMISSION", module: "P02", status: "ACTIVE", rules: 6,
    note: "Admits relationship-edge state changes (edge-unsupported / license / uncertainty / ceiling).",
    highlights: ["edge unsupported", "license restricts", "uncertainty missing"] },
  { key: "P03_PROSPECT_ADMISSION", module: "P03", status: "ACTIVE", rules: 5,
    note: "Admits fit hypotheses + queue items; blocks any buyer-intent overclaim.",
    highlights: ["buyer-intent overclaim", "stale pairing", "trigger required"] },
  { key: "P04_DISTRIBUTION_ADMISSION", module: "P04", status: "ACTIVE", rules: 5,
    note: "Admits role-scoped routing; blocks covert scoring and individual-identifier leaks.",
    highlights: ["covert scoring", "redaction miss", "individual identifier"] },
  { key: "P05_MARKETING_ASSET_ADMISSION", module: "P05", status: "ACTIVE", rules: 5,
    note: "Admits marketing drafts; uncited competitor claim + forbidden pattern + draft-only.",
    highlights: ["uncited claim", "forbidden pattern", "draft-only"] },
  { key: "P07_OUTREACH_EXECUTION_ADMISSION", module: "P07", status: "ACTIVE", rules: 5,
    note: "Admits CRM outreach; consent + PII are non-overridable BLOCKs; a clean run requires human approval.",
    highlights: ["consent BLOCK (non-overridable)", "PII not pseudonymized BLOCK", "→ REQUIRE_HUMAN_APPROVAL"] },
];
