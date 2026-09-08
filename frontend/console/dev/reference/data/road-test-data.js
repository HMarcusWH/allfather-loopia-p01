/* Allfather BI — Pilot Road Test (controlled road-test EXECUTION READINESS) seed data.
   Mirrors the substrate chain head:
     packages/allfather-control-substrate/src/mvp-controlled-road-test-execution-readiness-report.ts (#262)
   and the ladders beneath it: #242–#247 road-test gate · #248–#254 no-API-key
   preflight · #255–#262 execution-readiness (capability prepared, disabled).

   Plane: Ops/Sources packaging around Core proof objects. An execution-readiness
   report is NOT live execution authority. Every boundary flag below is asserted
   false in code (assert + tamper-tested). This surface is a read-only readout —
   it holds no run/go/activate affordance, and none of these objects is exported
   through the host-readonly seam or the extension api. */
window.AFB = window.AFB || {};

window.AFB.roadTest = {
  /* ── 1. Current status banner ── */
  banner: {
    status: "CONTROLLED_ROAD_TEST_EXECUTION_READY_BLOCKED_PENDING_EXPLICIT_OWNER_GO",
    verdict: "ROAD_TEST_001_EXECUTION_CAPABILITY_PREPARED_NOT_EXECUTED",
    chainHead: "#262 · mvp-controlled-road-test-execution-readiness-report.ts",
    lines: [
      "Execution capability is prepared — and disabled.",
      "No live-source observation has executed.",
      "Blocked pending the exact explicit owner go — the only missing packet.",
    ],
    ceiling: "INTERNAL_GOVERNED_SUBSTRATE_WITH_OPERATOR_AUTHORIZED_READ_ONLY_CONTROLLED_PILOT_OBJECT",
    proof: "Audit lane 2026-07-10 — 4,901 runtime tests green · focused #255–#262 lane 9 files / 170 tests",
  },

  /* ── 2. Promotion ladder — three states: COMPLETE · BLOCKED · FUTURE ──
     The blocked rung is the current position: capability is prepared and
     disabled; the next real step is the exact owner instruction. */
  ladder: [
    { id: "227", state: "COMPLETE", label: "Internal proof-MVP complete",
      note: "#227 · #229–#241 controlled-pilot MVP completion ladder — garage-complete. Fixture owner-review + in-process governed observation only.", ref: "PR #227" },
    { id: "228", state: "COMPLETE", label: "Road-test gate ready",
      note: "#228 · #242–#247 controlled road-test gate ladder — gate built, not passed.", ref: "PR #228" },
    { id: "229", state: "COMPLETE", label: "No-API-key road-test preflight ready",
      note: "#229 · #248–#254 no-API-key preflight ladder — everything possible without keys / live execution prepared.", ref: "PR #229" },
    { id: "cap", state: "COMPLETE", label: "Execution capability prepared — disabled",
      note: "#255–#262 execution-readiness ladder — transport capability contract defined (not implemented), disabled adapter harness (live slot is a sentinel that throws), owner-go intake + evaluator, execution package scaffold, post-run audit + evidence readback schemas, readiness report. Built, checked, none of it executable.", ref: "#255–#262" },
    { id: "blk", state: "BLOCKED", label: "Exact owner go — pending",
      note: "Six owner-go intake packets defined (#257), all MISSING; the evaluator (#258) defaults to ROAD_TEST_OWNER_GO_BLOCKED_PENDING_EXACT_OWNER_INSTRUCTION. The exact instruction is stored as a requirement, never as given.", ref: "#257 · #258" },
    { id: "obs", state: "FUTURE", label: "Road Test 001 execution",
      note: "One allowlisted, read-only, bounded, audited observation. The #259 scaffold pins executionPackageIsNotExecution: true until the go.", ref: "ROAD_TEST_001" },
    { id: "aud", state: "FUTURE", label: "Post-run audit", note: "17 required checks (#260), all REQUIRED_NOT_YET_CHECKED — re-derive every boundary from the recorded run.", ref: "#260" },
    { id: "rbk", state: "FUTURE", label: "Evidence readback", note: "11 required fields (#261), all REQUIRED_NOT_YET_CAPTURED. Readback is not authority to act.", ref: "#261" },
    { id: "cmp", state: "FUTURE", label: "Road-test completion report", note: "Only after a real road test has actually executed.", ref: "future" },
  ],

  /* ── 3. Closed boundary panel — asserted + tamper-tested false in code ── */
  boundary: [
    { k: "roadTestAuthorized", v: false },
    { k: "roadTestExecuted", v: false },
    { k: "liveSourceObservationAuthorized", v: false },
    { k: "liveSourceObservationPerformed", v: false },
    { k: "realNetworkUsed", v: false },
    { k: "apiKeysAvailable", v: false },
    { k: "credentialsAvailable", v: false },
    { k: "liveReadTransportImplemented", v: false },
    { k: "customerOutputEmitted", v: false },
    { k: "crmWritePerformed", v: false },
    { k: "sendActionPerformed", v: false },
    { k: "externalWritePerformed", v: false },
    { k: "productionReady", v: false },
    { k: "productionRolloutEnabled", v: false },
    { k: "transportPromoted", v: false },
    { k: "unboundedLiveFetchEnabled", v: false },
  ],

  /* ── 4. Execution-readiness evidence panel — the #255–#262 rungs, each a
     proof command. tone: ready (cyan) · blocked (amber) · closed (green). ── */
  evidencePrior: "The #248–#254 preflight rungs remain recorded beneath these — superseded as the frontier, not as evidence.",
  evidence: [
    { id: "255", name: "Live-transport capability contract", state: "Defined · not implemented", tone: "ready",
      detail: "GET-only · 1 source / 1 observation · ≤60s · ≤64KB · ≤1 redirect · no credentials, cookies or auth headers · kill-switch, audit and rollback REQUIRED. A contract a future transport must satisfy — no transport exists behind it.",
      cmd: "afb:mvp:road-test:live-transport-capability-contract" },
    { id: "256", name: "Disabled read-only transport adapter harness", state: "Disabled by default", tone: "closed",
      detail: "DI + mock adapter only. The live slot is a sentinel that THROWS on touch; enablement is a future owner-gated act, not a config flip.",
      cmd: "afb:mvp:road-test:disabled-transport-adapter-harness" },
    { id: "257", name: "Owner-go intake schema", state: "Six packets · all MISSING", tone: "ready",
      detail: "Defines exactly what an owner go consists of. Every packet REQUIRED_MISSING; nothing can be synthesized on the owner's behalf.",
      cmd: "afb:mvp:road-test:owner-go-intake" },
    { id: "258", name: "Owner-go evaluator", state: "Blocked · default verdict", tone: "blocked",
      detail: "Default verdict ROAD_TEST_OWNER_GO_BLOCKED_PENDING_EXACT_OWNER_INSTRUCTION — fail-closed until the exact instruction arrives.",
      cmd: "afb:mvp:road-test:owner-go-evaluator" },
    { id: "259", name: "Execution package scaffold", state: "Scaffold · not execution", tone: "ready",
      detail: "Carries plan, scope and boundary into one package object. Pins executionPackageIsNotExecution: true.",
      cmd: "afb:mvp:road-test:execution-package" },
    { id: "260", name: "Post-run audit schema", state: "17 checks · none checked", tone: "ready",
      detail: "Every check REQUIRED_NOT_YET_CHECKED — the audit that would re-derive the boundary from a recorded run.",
      cmd: "afb:mvp:road-test:post-run-audit-schema" },
    { id: "261", name: "Evidence readback schema", state: "11 fields · none captured", tone: "ready",
      detail: "Every field REQUIRED_NOT_YET_CAPTURED. Readback is evidence display, never authority to act.",
      cmd: "afb:mvp:road-test:evidence-readback-schema" },
    { id: "262", name: "Execution readiness report", state: "Ready · blocked", tone: "blocked",
      detail: "Chain head. Everything short of execution is prepared and disabled; the single missing thing is the exact owner instruction.",
      cmd: "afb:mvp:road-test:execution-readiness-report" },
  ],

  /* ── 5. Transport capability panel — the #255 contract. NOT live transport.
     Supersedes the #251 acceptance shape (same 13 policies, stronger status). */
  transport: {
    caveat: "Capability contract only. Defined, not implemented.",
    verdict: "ROAD_TEST_LIVE_TRANSPORT_CAPABILITY_CONTRACT_DEFINED_NOT_IMPLEMENTED",
    ref: "#255 contract — supersedes the #251 acceptance shape",
    harness: "The #256 harness holds the contract as DI + mock only; the live adapter slot is a sentinel that throws. No client, socket, or DNS code exists behind these literals.",
    policies: [
      { k: "methodPolicy", v: "GET_ONLY" },
      { k: "redirectPolicy", v: "MAX_1_OR_DISABLED" },
      { k: "timeoutLimitMs", v: "60000" },
      { k: "byteLimit", v: "65536" },
      { k: "sourceLimit", v: "1" },
      { k: "observationLimit", v: "1" },
      { k: "credentialPolicy", v: "NO_CREDENTIALS" },
      { k: "cookiePolicy", v: "NO_COOKIES" },
      { k: "authHeaderPolicy", v: "NO_AUTH_HEADERS" },
      { k: "writeMethodPolicy", v: "FORBIDDEN" },
      { k: "auditLoggingPolicy", v: "REQUIRED" },
      { k: "killSwitchPolicy", v: "REQUIRED" },
      { k: "rollbackRevocationPolicy", v: "REQUIRED" },
    ],
  },

  /* ── Next gate panel — the only authority (Core/gold) element on the screen. */
  nextGate: {
    gate: "EXACT_OWNER_GO_FOR_ROAD_TEST_001_EXECUTION",
    intake: "Owner-go intake (#257): six required packets — all MISSING. Evaluator (#258) default: ROAD_TEST_OWNER_GO_BLOCKED_PENDING_EXACT_OWNER_INSTRUCTION.",
    requires: [
      "The exact owner go instruction, given explicitly — the only missing packet",
      "Enablement of the disabled live-transport slot under that go (kill-switch, audit and rollback armed)",
    ],
    ownerInstruction: "Execute Road Test 001 under the governed read-only live-source scope.",
    instructionNote: "Stored in code as a requirement definition — not as an instruction already given.",
  },

  /* ── 6. GSMC pipeline — the governed source-monitoring MVP the proof rung
     compresses (~75 files under src/gsmc/). Read-only mirror of the cockpit
     read-model; every stage is closed-hands. cls: "" | done | blocked. ── */
  gsmc: {
    src: "src/gsmc/ · ~75 files · W2→W10b",
    law: "Every stage is closed-hands: executionPermitted pinned false on every artifact, no M12 runtime commands, no external effect. Due plans are candidates, drafts are internal, send-preflight is preflight of a forbidden thing, and the egress guard quarantines — it never sends.",
    stages: [
      { id: "W2", cls: "", name: "Robots / source-access gate", flag: "fail-closed",
        note: "Source-law check before a source is even admissible; a missing robots/terms record blocks, it never warns." },
      { id: "W3", cls: "", name: "Config law · governed admission · active resolver", flag: "governed",
        note: "SOURCE_CONFIG_* rides M04→M07→M08→M10; the active resolver composes admitted versions only." },
      { id: "W4", cls: "", name: "Due-plan selector · fixture observation · triggers", flag: "fixture lane",
        note: "Due plans are candidates, never fetch commands. Trigger evaluation, trigger stats and finding routing run over fixture captures." },
      { id: "W5", cls: "done", name: "Owner-gated live-read pilot", flag: "done · 2026-07-03",
        note: "The one in-process governed observation, plus live-capture normalization, trigger evaluation and the finding review queue." },
      { id: "W6", cls: "", name: "Review lifecycle · operator cockpit", flag: "read-only",
        note: "A read-only operator surface over configs, due plans and findings — the read-model this panel mirrors." },
      { id: "W7", cls: "", name: "Internal draft candidates + lifecycle", flag: "internal",
        note: "Drafts are internal artifacts; no audience pin moves." },
      { id: "W8", cls: "", name: "Export preview · connector policy registry", flag: "preview",
        note: "Preview objects only; every connector class stays DESIGNED_INERT." },
      { id: "W9", cls: "blocked", name: "Send-preflight candidates", flag: "hard-blocked",
        note: "Preflight of a forbidden thing — live send stays HARD-BLOCKED (P07-B / V1-GAP-093)." },
      { id: "W10", cls: "", name: "Fake dispatch harness · egress-guard quarantine", flag: "quarantine",
        note: "Dispatch is fake by construction; the egress guard quarantines anything shaped like an outbound effect." },
    ],
    readModel: ["active source configs", "due-plan candidates", "findings + review queue", "internal drafts", "export previews", "send-preflight candidates", "egress quarantine"],
  },

  /* Host seam note — surfaced as text, never as a capability. */
  seam: "None of the road-test, preflight, or execution-readiness objects is exported through @allfather/control-substrate/host-readonly or the extension api. The seam test forbids the road-test / preflight / no-credential / final-pre-execution symbol and token families.",
};
