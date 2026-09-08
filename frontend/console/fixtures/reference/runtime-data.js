/* Allfather BI — M01 Runtime Host Binding + M06 Tool Gateway seed data.
   Vocabulary canonical to packages/allfather-control-substrate/src/m01/binding.ts
   (BindingType, SafetyClassification, ActionClassRef, refusal reasons) and
   /src/m06/gateway.ts (the 6-gate dispatch sequence, ToolDispatchDecision kinds,
   tool classes, no_dispatch_confirmed). */
window.AFB = window.AFB || {};

/* M01 host identity is NOT declared here.

   The current architecture is host-neutral: a governed request is authorized by
   M06, then M01 binds one exact conformant adapter behind the General Harness
   Port. The bound adapter identity, its capability/authority/activation matrix
   and host health all live in `window.AFB.topology` (console/mvp-system-data.js)
   and render on the Runtime & Harness surface.

   The predecessor of this file declared an ACTIVE `window.AFB.host` of
   hostType OPENCLAW. That model is stale and was removed, not relabelled:
   OpenClaw is historical provenance, negative proof, legacy data migration and
   an optional FUTURE adapter concept — never the current host. The retired
   extension seam was never activated. It is represented once, as
   `topology.openclaw` with status ABSENT and six zero rows.

   Do not reintroduce a host record here. A host that is ABSENT must not also
   exist as an ACTIVE fixture, even an unrendered one. */

/* Runtime host bindings. status DRAFT → ACTIVE. safety ∈ SAFE_READ_ONLY |
   SAFE_INTERNAL_TRANSFORM | HARMLESS_DEMO_ACTION | UNSAFE_BLOCKED. */
window.AFB.bindings = [
  { id: "BND-01", type: "TOOL_SURFACE", purpose: "Source fetch & change-diff tools", classes: ["OBSERVE", "ANALYZE"], safety: "SAFE_READ_ONLY", status: "ACTIVE" },
  { id: "BND-02", type: "TOOL_SURFACE", purpose: "Internal transforms — compose, summarise", classes: ["OBSERVE", "ANALYZE"], safety: "SAFE_INTERNAL_TRANSFORM", status: "ACTIVE" },
  { id: "BND-03", type: "HEALTH_SURFACE", purpose: "Integration health reporting", classes: ["OBSERVE"], safety: "SAFE_READ_ONLY", status: "ACTIVE" },
  { id: "BND-04", type: "EVENT_SURFACE", purpose: "Mission signal intake", classes: ["OBSERVE"], safety: "SAFE_READ_ONLY", status: "ACTIVE" },
  { id: "BND-05", type: "DEMO_ADAPTER_SURFACE", purpose: "Harmless internal demo notification", classes: ["EXECUTE_CONSEQUENTIAL_ACTION"], safety: "HARMLESS_DEMO_ACTION", status: "ACTIVE", note: "The only surface permitted to map EXECUTE — and only gated through M06 + M09." },
  { id: "BND-06", type: "UI_TRANSPORT_SURFACE", purpose: "Console projection transport", classes: ["OBSERVE"], safety: "SAFE_READ_ONLY", status: "DRAFT" },
];
window.AFB.bindingTypeTone = { DEMO_ADAPTER_SURFACE: "amber", HEALTH_SURFACE: "cyan", EVENT_SURFACE: "cyan", TOOL_SURFACE: "cyan", UI_TRANSPORT_SURFACE: "muted", SESSION_SURFACE: "muted", HOST_INSTANCE: "gold" };
window.AFB.safetyTone = { SAFE_READ_ONLY: "green", SAFE_INTERNAL_TRANSFORM: "green", HARMLESS_DEMO_ACTION: "amber", UNSAFE_BLOCKED: "red" };

/* What M01 binding registration refuses in V1 (the hard ceiling). */
window.AFB.bindingCeiling = [
  { label: "UNSAFE_BLOCKED safety classification", reason: "UNSAFE_SAFETY_CLASSIFICATION" },
  { label: "RELEASE_INTELLIGENCE / PREPARE_EXTERNAL_ACTION mapped as a host op", reason: "PERMITTED_OPERATION_CLASS_NOT_ALLOWED_IN_V1" },
  { label: "EXECUTE_CONSEQUENTIAL_ACTION on any non-demo surface", reason: "UNSAFE_BINDING_TYPE_FOR_V1" },
];

/* M06 — the governed tool registry. tool_class ∈ READ_ONLY_SOURCE |
   INTERNAL_TRANSFORM | DEMO_SIDE_EFFECT | FORBIDDEN_IN_V1. */
window.AFB.tools = [
  { id: "source.fetch", op: "fetch", cls: "READ_ONLY_SOURCE", max: "OBSERVE", flags: ["evidence capture"], status: "ACTIVE" },
  { id: "change.diff", op: "compare", cls: "INTERNAL_TRANSFORM", max: "ANALYZE", flags: [], status: "ACTIVE" },
  { id: "brief.compose", op: "draft", cls: "INTERNAL_TRANSFORM", max: "ANALYZE", flags: [], status: "ACTIVE" },
  { id: "demo.action.notify", op: "stage", cls: "DEMO_SIDE_EFFECT", max: "EXECUTE_CONSEQUENTIAL_ACTION", flags: ["approval required", "frozen artifact"], status: "ACTIVE" },
  { id: "ad.budget.adjust", op: "execute", cls: "FORBIDDEN_IN_V1", max: "EXECUTE_CONSEQUENTIAL_ACTION", flags: [], status: "FORBIDDEN" },
];
window.AFB.toolClassTone = { READ_ONLY_SOURCE: "green", INTERNAL_TRANSFORM: "cyan", DEMO_SIDE_EFFECT: "amber", FORBIDDEN_IN_V1: "red" };

/* The single dispatch path: M02 → M03 → M04 → M06 → V1 scope → idempotency.
   Each writes an append-only tool_gate_checks row; one canonical decision per request. */
window.AFB.gateSeq = [
  { gate: "M02_ACCESS_SCOPE", label: "Access scope", module: "M02", detail: "Caller holds tool.invoke in this workspace." },
  { gate: "M03_CAPABILITY_CONTRACT", label: "Capability contract", module: "M03", detail: "Operation is in the Valkyrie contract's tool rights." },
  { gate: "M04_MISSION_SCOPE", label: "Mission scope", module: "M04", detail: "Active assignment; tool + action class in mission scope." },
  { gate: "M06_TOOL_DESCRIPTOR", label: "Tool descriptor", module: "M06", detail: "Tool + operation ACTIVE; action class ≤ tool ceiling." },
  { gate: "V1_SCOPE_PROHIBITION", label: "V1 scope / safety", module: "M06", detail: "Safe tool class; release/prepare/execute route to approval." },
  { gate: "IDEMPOTENCY_CONCURRENCY", label: "Idempotency", module: "M06", detail: "Same key + digest replays; a mismatch is a conflict." },
];

/* Recent gateway decisions. kind ∈ ALLOWED_AND_DISPATCHED | DENIED | HELD |
   REQUIRE_APPROVAL | QUARANTINED | IDEMPOTENT_REPLAY | CONFLICT. DENY/HOLD/
   REQUIRE_APPROVAL carry the no_dispatch_confirmed proof (SF-003). */
window.AFB.dispatchLog = [
  { id: "TR-2207", tool: "source.fetch", action: "OBSERVE", valkyrie: "VK-Verdandi", kind: "ALLOWED_AND_DISPATCHED", outcome: "SUCCEEDED", classification: "SOURCE_CANDIDATE", evidence: true, reasons: ["GRANTED"] },
  { id: "TR-2206", tool: "change.diff", action: "ANALYZE", valkyrie: "VK-Skuld", kind: "ALLOWED_AND_DISPATCHED", outcome: "SUCCEEDED", classification: "TRANSFORM_OUTPUT", evidence: false, reasons: ["GRANTED"] },
  { id: "TR-2205", tool: "demo.action.notify", action: "EXECUTE", valkyrie: "VK-Skuld", kind: "REQUIRE_APPROVAL", outcome: null, classification: null, reasons: ["TOOL_REQUIRES_APPROVAL"], severity: "S3", noDispatch: true },
  { id: "TR-2204", tool: "ad.budget.adjust", action: "EXECUTE", valkyrie: "VK-Skuld", kind: "DENIED", outcome: null, classification: null, reasons: ["TOOL_FORBIDDEN_IN_V1"], severity: "S5", noDispatch: true },
  { id: "TR-2203", tool: "source.fetch", action: "OBSERVE", valkyrie: "VK-Eir", kind: "DENIED", outcome: null, classification: null, reasons: ["TOOL_VALKYRIE_AUTHORITY_INVALID"], severity: "S2", noDispatch: true },
];
window.AFB.dispatchKindTone = { ALLOWED_AND_DISPATCHED: "green", DENIED: "red", HELD: "amber", REQUIRE_APPROVAL: "gold", QUARANTINED: "violet", IDEMPOTENT_REPLAY: "cyan", CONFLICT: "red" };

/* Controlled road-test transport — NOTE ONLY, never a host binding or tool.
   The #255 live-transport capability CONTRACT (superseding the #251 acceptance
   shape) defines what a future transport must satisfy; the #256 harness holds it
   DI + mock only — the live adapter slot is a sentinel that throws. No live
   transport is implemented and none of these objects is exported through
   @allfather/control-substrate/host-readonly or the extension api (the
   seam test forbids the road-test / preflight / no-credential token families).
   Surfaced on this plane as a note so operators see the runtime posture — it must
   NOT appear as a binding row or a registered tool. */
window.AFB.roadTestTransport = {
  rows: [
    { k: "Live transport", v: "Not implemented", tone: "red" },
    { k: "Capability contract", v: "Defined · #255", tone: "cyan" },
    { k: "Adapter harness", v: "Disabled · #256 · live slot throws", tone: "red" },
    { k: "GET-only policy", v: "Contract-pinned", tone: "cyan" },
    { k: "Byte cap", v: "65,536", tone: "muted" },
    { k: "Timeout", v: "60,000 ms", tone: "muted" },
    { k: "API keys", v: "Absent", tone: "red" },
    { k: "Credentials", v: "Absent", tone: "red" },
    { k: "Network use", v: "Blocked", tone: "red" },
    { k: "Owner go", v: "Pending · exact instruction · #258", tone: "amber" },
  ],
  seam: "Not a host binding. None of the road-test / preflight objects is exported through the host-readonly seam or the extension api — this is a runtime-posture note, not a registered capability.",
};
