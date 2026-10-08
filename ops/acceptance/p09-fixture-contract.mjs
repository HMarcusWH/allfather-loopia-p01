/**
 * P09 browser fixture contract. Pure/offline: does not connect, assign, admit, or mutate.
 * IDs must be from an independently reviewed synthetic TEST-only scope.
 */
const UUID4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const SHA = /^[0-9a-f]{40}$/;
const OBJECTS = ["p02", "p03", "monitor"];
function exactKeys(value, allowed, label) {
  if (!value || typeof value !== "object" || Array.isArray(value) ||
      Object.keys(value).some(key => !allowed.includes(key))) {
    throw new Error(label + ": invalid shape or unexpected field");
  }
}
export function validateP09Fixtures(value, releaseSha, reviewedPlanDigest = null) {
  exactKeys(value, ["test_only", "release_sha", "reviewed_plan_digest", "p02", "p03", "monitor"], "P09 fixture");
  if (value.test_only !== true || !SHA.test(value.release_sha) ||
      value.release_sha !== releaseSha) {
    throw new Error("P09 fixture: TEST-only release binding required");
  }
  if (reviewedPlanDigest !== null && (
    !/^[a-f0-9]{64}$/.test(reviewedPlanDigest) ||
    value.reviewed_plan_digest !== reviewedPlanDigest
  )) throw new Error("P09 fixture: reviewed plan digest mismatch");
  const result = {};
  for (const kind of OBJECTS) {
    if (value[kind] === undefined) continue; // Missing fixture is NOT_COVERED.
    exactKeys(value[kind], ["owned", "foreign"], kind);
    if (!UUID4.test(value[kind].owned) || !UUID4.test(value[kind].foreign) ||
        value[kind].owned === value[kind].foreign) {
      throw new Error(kind + ": distinct existing owned/foreign UUIDv4 IDs required");
    }
    result[kind] = Object.freeze({...value[kind]});
  }
  if (Object.keys(result).length === 0) throw new Error("P09 fixture: no owned/foreign objects");
  return Object.freeze(result);
}
/** Reject unknown envelope fields too; never normalize away unauthorized disclosure. */
function onlyKeys(value, allowed) {
  return value !== null && typeof value === "object" && !Array.isArray(value) &&
    Object.keys(value).every(k => allowed.includes(k));
}
function stable(value) {
  if (Array.isArray(value)) return value.map(stable);
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(Object.keys(value).sort().map(key => [key, stable(value[key])]));
  }
  return value;
}
/** Do not treat DENIED, 503 or any non-404 as foreign/unknown equivalence. */
export function indistinguishableNotFound(unknown, foreign) {
  if (unknown?.status !== 404 || foreign?.status !== 404) return false;
  const valid = p => onlyKeys(p, ["source", "as_of", "access", "projection", "owner_state", "reason_codes", "data"]) &&
    Object.keys(p).length === 7 &&
    p.owner_state === "NOT_FOUND" && p.data === null && p.as_of === null &&
    typeof p.source === "string" && Array.isArray(p.reason_codes) &&
    p.reason_codes.every(r => typeof r === "string") &&
    onlyKeys(p.access, ["decision", "required_permission", "reason_codes"]) &&
    p.access.decision === "VISIBLE" &&
    Array.isArray(p.access.reason_codes) &&
    onlyKeys(p.projection, ["state", "stale_status"]) &&
    p.projection.state === "EMPTY" && p.projection.stale_status === "UNKNOWN";
  if (!valid(unknown?.payload) || !valid(foreign?.payload)) return false;
  return JSON.stringify(stable(unknown.payload)) === JSON.stringify(stable(foreign.payload));
}
const RUN_KEYS = ["run", "integrity", "events", "artifacts", "awaiting",
  "request_preview_available", "request_preview_reason_code",
  "execution_admission", "current_reliance", "workbench_href"];
const SUMMARY_KEYS = ["rows", "scheduled_queue", "missions"];
const ROW_KEYS = ["monitor_run_id", "mission_id", "execution_mode", "stage", "revision",
  "created_at", "updated_at", "workbench_href"];
const DETAIL_RUN_KEYS = ["monitor_run_id", "mission_id", "execution_mode", "stage",
  "revision", "created_at", "updated_at", "started_by_scheduler", "reason_code"];
const ARTIFACT_KEYS = ["kind", "digest"];
const EVENT_KEYS = ["sequence_no", "event_kind", "from_stage", "to_stage", "actor", "recorded_at"];
const ADMISSION_KEYS = ["required", "admitted", "reason_code", "valid_until", "model_attempts", "source_captures"];
const QUEUE_KEYS = ["monitor_run_id", "scheduled_for", "skipped_slots", "queued_at", "workbench_href"];
const MISSION_KEYS = ["mission_id", "title", "status", "execution_mode"];
const ACTOR_OPERATOR_KEYS = ["type", "operator_id"];
const ACTOR_SERVICE_KEYS = ["type", "service_identity_id", "delegating_operator_id"];
const RELIANCE_KEYS = ["p01_release", "routing_release"];
const safePrimitive = value => (
  value === null || typeof value === "boolean" ||
  (typeof value === "number" && Number.isFinite(value)) ||
  (typeof value === "string" && value.length <= 2048)
);
const safeFlat = (object, keys) => onlyKeys(object, keys) &&
  Object.values(object).every(safePrimitive);
const safeActor = value => value?.type === "OPERATOR"
  ? safeFlat(value, ACTOR_OPERATOR_KEYS) && typeof value.operator_id === "string"
  : value?.type === "SERVICE" &&
    safeFlat(value, ACTOR_SERVICE_KEYS) && typeof value.service_identity_id === "string" &&
    typeof value.delegating_operator_id === "string";
const safeReliance = value => (
  Array.isArray(value) ? value.length === 0 :
  onlyKeys(value, RELIANCE_KEYS) &&
    Object.values(value).every(s => s === null || typeof s === "string" && /^[A-Z_]{1,64}$/.test(s))
);
const safeCounter = value => onlyKeys(value, ["used", "limit"]) &&
  Number.isSafeInteger(value.used) && value.used >= 0 &&
  Number.isSafeInteger(value.limit) && value.limit >= 0;
const safeAdmission = value => onlyKeys(value, ADMISSION_KEYS) &&
  Object.entries(value).every(([key, v]) =>
    ["model_attempts", "source_captures"].includes(key)
      ? safeCounter(v)
      : safePrimitive(v));
export function monitorMetadataOnly(data, detail = false) {
  if (!detail) return onlyKeys(data, SUMMARY_KEYS) &&
    Array.isArray(data.rows) && data.rows.every(row => safeFlat(row, ROW_KEYS)) &&
    Array.isArray(data.scheduled_queue) &&
    data.scheduled_queue.every(row => safeFlat(row, QUEUE_KEYS)) &&
    Array.isArray(data.missions) &&
    data.missions.every(row => safeFlat(row, MISSION_KEYS));
  return onlyKeys(data, RUN_KEYS) && safeFlat(data.run, DETAIL_RUN_KEYS) &&
    Array.isArray(data.events) && data.events.every(ev =>
      onlyKeys(ev, EVENT_KEYS) && safeActor(ev.actor) &&
      Object.entries(ev).every(([k, v]) => k === "actor" || safePrimitive(v))) &&
    Array.isArray(data.artifacts) && data.artifacts.every(a => safeFlat(a, ARTIFACT_KEYS)) &&
    safeAdmission(data.execution_admission) &&
    safeReliance(data.current_reliance ?? []) &&
    Object.entries(data).every(([k, v]) =>
      ["run", "events", "artifacts", "execution_admission", "current_reliance"].includes(k) ||
      safePrimitive(v));
}
