/* Allfather BI — RELEASE, RUNTIME and SYSTEM fixtures for the MVP operator console.

   Vocabulary verbatim from the live substrate:
     release-output/vocabulary.ts       — AudienceClass, ReleaseArtifactKind/Status,
                                          ClaimCeiling, ConfidenceBand, InterpretationKind
     release-output/read-model.ts       — RenderableOutputState, staleness marks
     release-output/redaction.ts        — RedactionAction + reason codes
     host-readonly-surface.ts           — GovernanceStatus, HostReadinessHealth
     harness-port/{capability,descriptor,lifecycle,refusal}.ts — the harness law
     m01/health.ts                      — HostHealthStatus
   Repository state read from docs/allfather-bi/STATE_OF_THE_BUILD.md (Checkpoint 12
   post-cutover reconciliation): the tree is STANDALONE and host-neutral; OpenClaw is
   historical provenance + optional future adapter concept only. */
window.AFB = window.AFB || {};

/* ═══════════════ M16 · release & visibility ═══════════════ */
window.AFB.m16 = {
  note: "M16 constructs, projects and activates a governed representation AFTER M10 has recorded the decision. It is not the customer briefing screen — it is the machinery that makes a governed output renderable, and the record of who may currently rely on it.",
  boundary: "M10 decides and records. M16 assembles, projects, activates and withdraws. Merging them would let a projection look like a decision.",

  /* The closed audience ladder. Everything past TENANT_INTERNAL is designed-inert:
     activation is CHECK-pinned to 0, not a configuration switch. */
  audiences: [
    { cls: "OPERATOR_ONLY", state: "ACTIVE", tone: "green", note: "Operator console reads." },
    { cls: "TENANT_INTERNAL", state: "ACTIVE", tone: "green", note: "The MVP's widest live audience — internal role lenses only." },
    { cls: "CUSTOMER_VISIBLE", state: "DESIGNED_INERT", tone: "red", note: "activation_permitted CHECK-pinned 0 · un-pinning is a promoted schema change after legal review, never configuration." },
    { cls: "EXPORTABLE", state: "DESIGNED_INERT", tone: "red", note: "No export surface exists." },
    { cls: "API_VISIBLE", state: "DESIGNED_INERT", tone: "red", note: "No API projection exists." },
    { cls: "CHANNEL_EMAIL_SMS_CRM", state: "DESIGNED_INERT", tone: "red", note: "Channel territory belongs to P07/X01 and is hard-blocked." },
  ],

  summary: { released: 4, held: 2, superseded: 1, rolledBack: 1, stale: 1, relianceActive: 4 },

  artifacts: [
    {
      id: "RA-0418", kind: "BATTLECARD_BRIEFING", status: "RELEASED_INTERNAL", tone: "green",
      title: "Boreal Tier-2 pricing — response play", entity: "Boreal Systems AB",
      decision: "DR-0071", assembled: "2026-08-28 07:12", freshness: "2026-08-28 06:40",
      staleness: "FRESH", claimCeiling: "INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS",
      audience: "TENANT_INTERNAL", lenses: ["SALES", "MARKETING", "TENANT_ADMIN_LEADERSHIP"],
      renderState: "VISIBLE", reliance: "CURRENT", displayRef: "DSP-1188",
      uncertainty: "List price is directly observed. Segment intent is a hypothesis carried at MEDIUM confidence.",
      sourceUse: { sources: 3, primary: 3, excerptRights: 2, license: "PUBLIC_WEB", holds: [] },
      elements: [
        { cls: "CLAIM", interpretation: "FACT_OBSERVED", confidence: "HIGH", text: "Boreal raised the Tier-2 list price from 4 900 kr to 5 490 kr and added a usage-based add-on block." },
        { cls: "CLAIM", interpretation: "HYPOTHESIS", confidence: "MEDIUM", text: "The packaging change is consistent with a move toward larger, usage-linked accounts." },
        { cls: "UNCERTAINTY_BLOCK", text: "Non-redactable. Intent is not observed; only the published packaging is." },
        { cls: "FRESHNESS_BLOCK", text: "Non-redactable. Observed 2026-08-28 06:40." },
        { cls: "CITATION", text: "boreal.se/pricing · FULL_CITATION at TENANT_INTERNAL" },
      ],
      packet: "PKT-0418",
    },
    {
      id: "RA-0411", kind: "COMPANY_SIGNAL_BRIEFING", status: "RELEASED_INTERNAL", tone: "green",
      title: "Fjordr repositions on compliance", entity: "Fjordr",
      decision: "DR-0068", assembled: "2026-08-27 23:02", freshness: "2026-08-27 22:10",
      staleness: "STALE_WARNING", claimCeiling: "INTERNAL_DEMO_VALIDATED_WITH_LIMITATIONS",
      audience: "TENANT_INTERNAL", lenses: ["MARKETING", "PRODUCT"],
      renderState: "VISIBLE", reliance: "CURRENT", displayRef: "DSP-1174",
      uncertainty: "One primary source. The claim is about published messaging, not about certification status.",
      sourceUse: { sources: 1, primary: 1, excerptRights: 1, license: "PUBLIC_WEB", holds: [] },
      elements: [
        { cls: "CLAIM", interpretation: "FACT_OBSERVED", confidence: "HIGH", text: "The Fjordr launch page replaced “the simple way to ship” with “compliant by default”." },
        { cls: "LIMITATION_NOTE", text: "No certification evidence was captured. The claim covers messaging only." },
        { cls: "UNCERTAINTY_BLOCK", text: "Non-redactable." },
        { cls: "FRESHNESS_BLOCK", text: "Non-redactable. Observed 2026-08-27 22:10 — past the freshness warning threshold." },
      ],
      packet: "PKT-0411",
    },
    {
      id: "RA-0419", kind: "COMPANY_SIGNAL_BRIEFING", status: "HELD_SOURCE_USE", tone: "amber",
      title: "Acme Logistics — funding round and procurement signal", entity: "Acme Logistics",
      decision: "—", assembled: "—", freshness: "2026-08-28 05:15",
      staleness: null, claimCeiling: "INTERNAL_DRAFT",
      audience: "OPERATOR_ONLY", lenses: [],
      renderState: "REFUSED", reliance: "NONE", displayRef: null,
      uncertainty: "The funding claim is primary-sourced. The procurement claim has no primary link, so the artifact cannot be assembled.",
      sourceUse: { sources: 2, primary: 1, excerptRights: 1, license: "PUBLIC_WEB", holds: ["A CLAIM element carries no PRIMARY evidence link"] },
      elements: [
        { cls: "CLAIM", interpretation: "FACT_OBSERVED", confidence: "MEDIUM", text: "Acme announced a funding round." },
        { cls: "CLAIM", interpretation: "HYPOTHESIS", confidence: "LOW", text: "A procurement initiative is under way. — held: no primary evidence link" },
      ],
      packet: "PKT-0419",
    },
    {
      id: "RA-0402", kind: "ENTITY_TWIN_SNAPSHOT_VIEW", status: "SUPERSEDED", tone: "muted",
      title: "Kvant Industri — entity context snapshot", entity: "Kvant Industri",
      decision: "DR-0044", assembled: "2026-08-12 11:20", freshness: "2026-08-11 03:00",
      staleness: "STALE_DOWNGRADED", claimCeiling: "SUPERSEDED",
      audience: "TENANT_INTERNAL", lenses: ["PROCUREMENT_FINANCE_OPS"],
      renderState: "WITHHELD_STALE", reliance: "SUPERSEDED_BY RA-0430", displayRef: null,
      uncertainty: "Financial fields could not be refreshed after the upstream schema change; the snapshot was superseded rather than left to look current.",
      sourceUse: { sources: 2, primary: 1, excerptRights: 0, license: "LICENSED", holds: ["Licence terms recheck lapsed"] },
      elements: [
        { cls: "CLAIM", interpretation: "FACT_OBSERVED", confidence: "NOT_QUANTIFIED", text: "Segment and headcount band as of 2026-08-11." },
        { cls: "FRESHNESS_BLOCK", text: "Non-redactable. Beyond the downgrade threshold — the read model withholds it as stale." },
      ],
      packet: null,
    },
    {
      id: "RA-0396", kind: "BATTLECARD_BRIEFING", status: "ROLLED_BACK", tone: "red",
      title: "Sector cost-structure dossier", entity: "Kvant Industri",
      decision: "DR-0039", assembled: "2026-08-05 16:40", freshness: "2026-08-05 03:00",
      staleness: null, claimCeiling: "RETRACTED",
      audience: "TENANT_INTERNAL", lenses: [],
      renderState: "WITHDRAWN", reliance: "WITHDRAWN", displayRef: null,
      uncertainty: "A licensed excerpt exceeded its permitted use. Visibility was deactivated and reliance withdrawn; the record and the reason are preserved.",
      sourceUse: { sources: 5, primary: 2, excerptRights: 0, license: "LICENSED", holds: ["Excerpt rights absent for two elements"] },
      elements: [{ cls: "LIMITATION_NOTE", text: "Withdrawn. The artifact stays in the ledger with its rollback reason; it is not deleted." }],
      packet: null,
    },
  ],

  /* Redaction decisions on the widest live audience — shown so an operator can
     see exactly what a projection removes and why. Caveats are never removable. */
  redaction: [
    { ordinal: 1, cls: "CLAIM", action: "SHOW", reason: "ALLOWED_BY_PROFILE" },
    { ordinal: 2, cls: "CLAIM", action: "SHOW", reason: "ALLOWED_BY_PROFILE" },
    { ordinal: 3, cls: "UNCERTAINTY_BLOCK", action: "SHOW", reason: "NON_REDACTABLE_BLOCK_RETAINED" },
    { ordinal: 4, cls: "FRESHNESS_BLOCK", action: "SHOW", reason: "NON_REDACTABLE_BLOCK_RETAINED" },
    { ordinal: 5, cls: "CITATION", action: "SHOW", reason: "CITATION_CLASS_APPLIED" },
    { ordinal: 6, cls: "INTERNAL_NOTE", action: "WITHHOLD_SILENT", reason: "INTERNAL_NOTE_CEILING" },
    { ordinal: 7, cls: "BOUNDED_EXCERPT", action: "WITHHOLD_TOMBSTONED", reason: "EXCERPT_RIGHTS_ABSENT" },
  ],

  audits: [
    { id: "RELEASE_SOURCE_USE_CONFORMANCE", pass: 18, total: 18, tone: "green" },
    { id: "RELEASE_AUDIENCE_MONOTONICITY", pass: 12, total: 12, tone: "green" },
    { id: "RELEASE_CAVEAT_PRESERVATION", pass: 22, total: 22, tone: "green" },
    { id: "RELEASE_HOLD_DISCIPLINE", pass: 9, total: 9, tone: "green" },
    { id: "RELEASE_LINEAGE_INTEGRITY", pass: 14, total: 14, tone: "green" },
    { id: "RELEASE_ROLLBACK_VISIBILITY", pass: 6, total: 7, tone: "amber" },
  ],
};

window.AFB.m16StatusTone = {
  DRAFT: "muted", READY_FOR_RELEASE: "cyan", RELEASED_INTERNAL: "green",
  RELEASED_CUSTOMER_VISIBLE: "red", HELD_SOURCE_USE: "amber", HELD_FRESHNESS: "amber",
  HELD_CONFLICT: "amber", SUPERSEDED: "muted", ROLLED_BACK: "red", QUARANTINED: "red",
};
window.AFB.m16RenderTone = { VISIBLE: "green", WITHHELD_STALE: "amber", WITHDRAWN: "red", REFUSED: "amber", DENIED: "red" };
window.AFB.m16StaleTone = { FRESH: "green", STALE_WARNING: "amber", STALE_DOWNGRADED: "red" };

/* ═══════════════ M01 / M06 · runtime topology (host-neutral) ═══════════════ */
window.AFB.topology = {
  /* The conceptual route, top to bottom. Each hop states what it does and — just
     as important — what it does NOT confer. */
  chain: [
    { id: "request", label: "Governed request", module: "—", tone: "muted",
      is: "A caller asks for a tool operation inside a mission scope.",
      isnot: "Asking is not authorization." },
    { id: "m06", label: "Authorization", module: "M06", tone: "gold",
      is: "Six ordered gates decide one canonical dispatch decision per request and record it append-only.",
      isnot: "An ALLOW authorizes exactly this operation — not the class, not the next one." },
    { id: "m01", label: "Host + adapter binding", module: "M01", tone: "cyan",
      is: "Binds a generic runtime host to one exact conformant adapter identity and replays the conformance law over the persisted identity digest.",
      isnot: "M01 binding is not M06 per-operation authorization." },
    { id: "port", label: "General Harness Port", module: "@allfather/harness-port", tone: "violet",
      is: "The current execution-environment boundary: protocol version, capability manifest, conformance profile, lifecycle, refusal vocabulary.",
      isnot: "Harness conformance is not admission, authorization, reliance, release or owner-go." },
    { id: "adapter", label: "Exact conformant adapter", module: "@allfather/harness-native", tone: "cyan",
      is: "The current reference adapter. Identity is adapterId@adapterVersion plus a manifest digest.",
      isnot: "Adapter conformance is not authority." },
    { id: "host", label: "Runtime host", module: "—", tone: "muted",
      is: "Executes only what the admitted envelope permits and returns a terminal result.",
      isnot: "Host availability is not authorization." },
  ],

  /* The bound adapter, as M01 records it. */
  adapter: {
    adapterId: "allfather-harness-native", adapterVersion: "1.4.2",
    identity: "allfather-harness-native@1.4.2",
    protocolVersion: "harness/1", conformanceProfile: "afb-conformance/operator-read-v3",
    environmentClass: "OPERATOR_WORKSTATION",
    lifecycle: "READY", lifecyclePath: ["REGISTERED", "BOUND", "READY"],
    manifestDigest: "sha256:9f31c4…7ab2e0", identityDigest: "sha256:41ce8b…d0f7a4",
    repositoryReference: "repo://allfather/harness-native",
    licence: "licence status · cleared",
    assumptions: [
      "The adapter declares no external-effect capability family.",
      "Every result is checked against its own request challenge before it is admitted.",
      "Persisted READY is re-verified against the conformance law on every M01 boundary crossing.",
    ],
  },

  /* Capability ≠ authority ≠ activation. Three separate columns, deliberately. */
  capabilities: [
    { family: "READ_MODEL", status: "IMPLEMENTED", authority: "M06 ALLOW required", activation: "ACTIVE" },
    { family: "OPERATOR_COMMAND", status: "BOUNDED", constraint: "operator-initiated read commands only", authority: "M06 ALLOW required", activation: "ACTIVE" },
    { family: "INTERNAL_TRANSFORM", status: "IMPLEMENTED", authority: "M06 ALLOW required", activation: "ACTIVE" },
    { family: "ARTIFACT_READ", status: "IMPLEMENTED", authority: "M06 ALLOW required", activation: "ACTIVE" },
    { family: "RUN_CONTEXT", status: "IMPLEMENTED", authority: "M06 ALLOW required", activation: "ACTIVE" },
    { family: "TELEMETRY", status: "BOUNDED", constraint: "health observations only", authority: "M06 ALLOW required", activation: "ACTIVE" },
    { family: "LIFECYCLE", status: "IMPLEMENTED", authority: "M01 lifecycle law", activation: "ACTIVE" },
    { family: "CLARIFICATION", status: "IMPLEMENTED", authority: "M06 ALLOW required", activation: "ACTIVE" },
    { family: "ARTIFACT_WRITE", status: "DISABLED", authority: "no grant exists", activation: "INERT" },
    { family: "MODEL_INFERENCE", status: "DISABLED", authority: "no grant exists", activation: "INERT" },
    { family: "SCHEDULED_TASK", status: "ABSENT", authority: "no grant exists", activation: "INERT" },
    { family: "EXTERNAL_TRANSPORT", status: "ABSENT", authority: "outside the safe set", activation: "BLOCKED" },
    { family: "CREDENTIAL_ACCESS", status: "ABSENT", authority: "outside the safe set", activation: "BLOCKED" },
    { family: "EXTERNAL_ACTION", status: "ABSENT", authority: "outside the safe set", activation: "BLOCKED" },
  ],

  /* Recent harness refusals — the vocabulary an operator will actually meet. */
  refusals: [
    { reason: "REFUSED_CAPABILITY_UNDECLARED", family: "EXTERNAL_TRANSPORT", count: 2, detail: "A live-fetch request reached the port; the adapter declares no external-transport family." },
    { reason: "REFUSED_EXTERNAL_EFFECT", family: "EXTERNAL_ACTION", count: 1, detail: "An effect-bearing envelope was refused at the boundary before the adapter was reached." },
    { reason: "REFUSED_MANIFEST_DIGEST", family: null, count: 1, detail: "A stale registration digest did not match the recomputed conformance evidence." },
    { reason: "REFUSED_SCOPE_MISMATCH", family: null, count: 3, detail: "Request scope did not match the admitted envelope's tenant/workspace." },
  ],

  health: { status: "HEALTHY", observed: "2026-08-28 06:52", component: "harness-native · operator-workstation",
    note: "Health is an append-only observation. No adapter registration or capability metadata may create a production-readiness claim." },

  /* OpenClaw — corrected. Historical provenance only; not the current host. */
  openclaw: {
    status: "ABSENT",
    rows: [
      { k: "Current runtime", v: "ABSENT" },
      { k: "Current tooling dependency", v: "ABSENT" },
      { k: "Current governance dependency", v: "ABSENT" },
      { k: "Current harness dependency", v: "ABSENT" },
      { k: "Extension runtime files", v: "0" },
      { k: "Plugin SDK imports", v: "0" },
    ],
    note: "OpenClaw is historical provenance, negative proof, legacy data migration, and an optional future adapter concept. The retired extension seam was never activated. Older documents that name it as the current host are superseded predecessors.",
  },
};

window.AFB.capStatusTone = { IMPLEMENTED: "green", BOUNDED: "cyan", DISABLED: "muted", ABSENT: "muted" };
window.AFB.activationTone = { ACTIVE: "green", INERT: "muted", BLOCKED: "red" };
window.AFB.hostHealthTone = { HEALTHY: "green", DEGRADED: "amber", UNAVAILABLE: "red", MISCONFIGURED: "amber", UNTRUSTED_BEHAVIOR: "red", UNKNOWN: "muted" };

/* ═══════════════ host-readonly-surface · governance status ═══════════════ */
window.AFB.governanceStatus = {
  hostSurfaceMode: "READ_ONLY",
  externalSideEffectsDisabled: true,
  guard: [
    { k: "Workspaces permitting external effect", v: 0 },
    { k: "Contracts with relaxed execution authority", v: 0 },
    { k: "Channel bindings permitting external effect", v: 0 },
    { k: "Non-synthetic channel adapters", v: 0 },
    { k: "Non-synthetic delivery outcomes", v: 0 },
  ],
  flags: [
    { k: "customerVisibleOutputEnabled", v: false },
    { k: "schedulerEnabled", v: false },
    { k: "p07BLiveActionEnabled", v: false },
    { k: "universalFetchEnabled", v: false },
    { k: "universalPortEnabled", v: false },
    { k: "promptOpsRuntimeEnabled", v: false },
    { k: "pdbRuntimeActivationEnabled", v: false },
  ],
};

/* ═══════════════ M12 · console state ═══════════════ */
window.AFB.m12 = {
  law: [
    "The console owns no domain truth.",
    "A read model is a regenerable projection — never evidence.",
    "A displayed approval object is not approval authority.",
    "A button does not create authority.",
    "Every controlled command is routed back to its owning module, and M12 re-runs the M02 access guard for each one.",
    "A stale read model shows nothing rather than a grant the backend would refuse.",
  ],
  readModels: [
    { id: "buildWorkspaceContextProjection", surfaces: ["Overview", "Needs you"], regenerated: "2026-08-28 06:55", rows: 1, tone: "cyan" },
    { id: "buildDecisionTimelineProjection", surfaces: ["Decision Ledger", "Packet Trace"], regenerated: "2026-08-28 06:55", rows: 24, tone: "cyan" },
    { id: "getReleaseReadinessProjection", surfaces: ["Release & Visibility"], regenerated: "2026-08-28 06:55", rows: 8, tone: "cyan" },
    { id: "listConsoleRenderableOutputs", surfaces: ["Release & Visibility"], regenerated: "2026-08-28 06:56", rows: 5, tone: "cyan" },
    { id: "listVisibleReleasedBriefings", surfaces: ["Competition Monitor"], regenerated: "2026-08-28 06:56", rows: 3, tone: "cyan" },
    { id: "listVisibleEntityTwinSnapshots", surfaces: ["Entity Context"], regenerated: "2026-08-28 06:56", rows: 6, tone: "cyan" },
    { id: "listVisibleRoleDistributions", surfaces: ["Distribution"], regenerated: "2026-08-28 06:56", rows: 4, tone: "cyan" },
    { id: "getLiveOperatorCockpitSnapshot", surfaces: ["Source Monitoring"], regenerated: "2026-08-28 06:57", rows: 12, tone: "cyan" },
  ],
  /* The distinction the UI must never blur. */
  routing: [
    { state: "ROUTED_TO_OWNER", tone: "cyan", meaning: "The console handed the command to the owning module. Nothing has been accepted." },
    { state: "OWNER_ACCEPTED", tone: "gold", meaning: "The owning module accepted the command as valid for its own governance. Nothing has run." },
    { state: "EXECUTED", tone: "green", meaning: "The owning module ran it and recorded the outcome. Only the owner can report this." },
  ],
  commands: [
    { name: "m04.mission.activate", owner: "M04", access: "mission.activate", state: "ROUTED_TO_OWNER", at: "2026-08-28 06:31" },
    { name: "m11.audit.run", owner: "M11", access: "audit.run", state: "EXECUTED", at: "2026-08-28 06:12" },
    { name: "gsmc.liveReview.audit.build", owner: "GSMC", access: "INTERNAL_OPERATOR_READ", state: "EXECUTED", at: "2026-08-28 05:58" },
    { name: "m09.approval.decide", owner: "M09", access: "approval.decide", state: "ROUTED_TO_OWNER", at: "2026-08-28 05:44" },
    { name: "m16.visibility.activate", owner: "M16", access: "release.activate", state: "OWNER_ACCEPTED", at: "2026-08-27 23:02" },
    { name: "p07.dispatch.send", owner: "P07", access: "—", state: "DENIED", at: "2026-08-27 19:20", deny: "TOOL_FORBIDDEN_IN_V1 · the command surface refuses identically to the backend" },
  ],
  displayGuard: {
    note: "Every material render records a display-representation reference anchored on the object's own owner basis. A render the guard cannot anchor fails closed and shows a detail-free refusal — never material content.",
    recorded: 1188, failedClosed: 3,
  },
};
window.AFB.m12RoutingTone = { ROUTED_TO_OWNER: "cyan", OWNER_ACCEPTED: "gold", EXECUTED: "green", DENIED: "red" };

/* ═══════════════ MVP readiness · environment, not architecture ═══════════════
   NOTE: named `mvpReadiness`, not `readiness` — `window.AFB.readiness` is already
   the M05 per-proposal evidence-readiness array in mission-data.js. */
window.AFB.mvpReadiness = {
  intro: "These are ENVIRONMENT pins at this baseline. They say what is switched on here — not what the product is. None of them is a permanent part of the console's structure.",
  repo: { branch: "main", tree: "STANDALONE", ref: "76e9d22", checkpoint: "CHECKPOINT_12_POST_CUTOVER_AUDIT · IN_PROGRESS" },
  groups: [
    { label: "Implementation — does the capability exist?", tone: "green", rows: [
      { k: "Governed source monitoring (GSMC)", v: "BUILT", tone: "green" },
      { k: "Governed ingestion spine (M15)", v: "BUILT", tone: "green" },
      { k: "Release & output spine (M16)", v: "BUILT", tone: "green" },
      { k: "General Harness Port + native adapter", v: "BUILT", tone: "green" },
      { k: "Physical standalone cutover", v: "COMPLETE ON MAIN", tone: "green" },
    ] },
    { label: "Activation — is it enabled in this environment?", tone: "amber", rows: [
      { k: "LIVE_READ_ACTIVATED", v: "FALSE", tone: "amber" },
      { k: "ROAD_TEST_001_EXECUTED", v: "FALSE", tone: "amber" },
      { k: "Scheduler", v: "DISABLED", tone: "amber" },
      { k: "Hosted CI", v: "UNAVAILABLE · credits exhausted", tone: "amber" },
      { k: "Independent exact-head review", v: "NOT SATISFIED", tone: "amber" },
    ] },
    { label: "Authority — what may it actually do?", tone: "red", rows: [
      { k: "OWNER_GO", v: "NOT GRANTED", tone: "red" },
      { k: "CUSTOMER_VISIBLE_OUTPUT", v: "FALSE", tone: "red" },
      { k: "LIVE_EXTERNAL_ACTION", v: "FALSE", tone: "red" },
      { k: "PRODUCTION_LAUNCH", v: "NOT AUTHORIZED", tone: "red" },
    ] },
  ],
  doNotClaim: ["HOSTED_CI_PASS", "OWNER_GO", "OPENCLAW_COMPATIBILITY_ADAPTER", "UNIVERSAL_PORT_RUNTIME", "UNIVERSAL_FETCH_RUNTIME", "ROAD_TEST_001", "CUSTOMER_VISIBLE_OUTPUT", "LIVE_EXTERNAL_ACTION"],
};

/* ═══════════════ Development & future systems (preserved, demoted) ═══════════════ */
window.AFB.archive = {
  intro: "Systems that exist in the repository but do not participate in operating the Monitor MVP. They keep their surfaces so the later full-release console can reuse them; none of them holds a primary navigation position, and none is an MVP capability.",
  law: "Code existing is not promotion. Unmerged candidate work is not current runtime law.",
  items: [
    { id: "road-test", label: "Pilot Road Test", code: "RT-001", icon: "route", cls: "OPERATIONAL HISTORY", tone: "amber",
      note: "The #242–#262 execution-readiness ladder. Its live-source function now has a mature home in Source Monitoring; this screen is kept as the owner-go record." },
    { id: "roadmap", label: "Post-V1 Sequence", code: "DOCS", icon: "map", cls: "DOCS-ONLY", tone: "muted",
      note: "Recorded build order and activation classes. A roadmap panel authorizes, activates, promotes and builds nothing." },
    { id: "migration", label: "Migration Readiness", code: "A0", icon: "package-open", cls: "OPERATIONAL HISTORY", tone: "muted",
      note: "Standalone-extraction preparation. The physical cutover has since completed on main; kept as the preparation record." },
    { id: "adapt", label: "Adaptation Review", code: "M13", icon: "git-pull-request-arrow", cls: "SPEC-ONLY", tone: "muted",
      note: "Tenant self-adaptation under governance. Not active." },
    { id: "promptops", label: "PromptOps", code: "M14", icon: "anchor", cls: "RUNTIME-INERT", tone: "muted",
      note: "Governed prompt architecture — version records, bindings, fail-closed resolver, meta-validation. promptOpsRuntimeEnabled is false." },
    { id: "mcm", label: "MCM Diagnostics", code: "MCM", icon: "stethoscope", cls: "RUNTIME-INERT", tone: "muted",
      note: "Governed diagnostic operator layer. Review gate hard-closed; a diagnosis is never truth and a score never authorizes." },
    { id: "p02", label: "Polymorphic Database overlay", code: "PDB", icon: "boxes", cls: "INERT CONTROL-PLANE", tone: "muted",
      note: "Reachable as the second mode on Entity Context. Candidates, evidence, decisions, dry-run, simulation and gates — runtime flags pinned off.", modeNote: "opens Entity Context" },
  ],
  candidate: {
    label: "Candidate / development-only mechanisms",
    rows: [
      { k: "Candidate Runtime / Phenotype Host (SEV)", v: "PROPOSED · internal laboratory path only, deliberately absent from the harness-port public API" },
      { k: "M17 temporal intelligence & compression", v: "DOCS-ONLY" },
      { k: "M18 adaptive configuration spine", v: "DOCS-ONLY beyond the MVP slice" },
      { k: "Strategic Core", v: "DOCS-ONLY" },
      { k: "Universal Port / Universal Fetch", v: "DOCS-ONLY · universalPortEnabled and universalFetchEnabled are false" },
      { k: "X01 · P07-B live send", v: "HARD-BLOCKED · legal and privacy review plus an explicit final go" },
    ],
  },
};
