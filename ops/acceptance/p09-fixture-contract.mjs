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
export function validateP09Fixtures(value, releaseSha) {
  exactKeys(value, ["test_only", "release_sha", "p02", "p03", "monitor"], "P09 fixture");
  if (value.test_only !== true || !SHA.test(value.release_sha) ||
      value.release_sha !== releaseSha) {
    throw new Error("P09 fixture: TEST-only release binding required");
  }
  const result = {};
  for (const kind of OBJECTS) {
    if (value[kind] === undefined) continue; // Missing fixture is NOT_COVERED.
    exactKeys(value[kind], ["owned", "foreign"], kind);
    if (!UUID4.test(value[kind].owned) || !UUID4.test(value[kind].foreign) ||
        value[kind].owned === value[kind].foreign) {
      throw new Error(kind + ": distinct existing owned/foreign UUIDv4 IDs required");
    }
    result[kind] = {...value[kind]};
  }
  if (Object.keys(result).length === 0) throw new Error("P09 fixture: no owned/foreign objects");
  return Object.freeze(result);
}
/** Do not treat DENIED or UNAVAILABLE as proof of selector non-enumeration. */
export function indistinguishableNotFound(unknown, foreign) {
  if (unknown.status !== 404 || foreign.status !== 404) return false;
  const normalized = x => {
    const p = x.payload;
    if (!p || p.owner_state !== "NOT_FOUND" || p.data !== null) return null;
    return JSON.stringify({
      source:p.source, owner_state:p.owner_state,
      access:p.access, projection:p.projection, reason_codes:p.reason_codes,
      data:p.data
    });
  };
  const a = normalized(unknown), b = normalized(foreign);
  return a !== null && a === b;
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
const RELIANCE_FORBIDDEN = /(secret|private.path|password|credential.bytes|prompt.body|candidate.text|request.body|assessment.text)/i;
function onlyKeys(value, allowed) {
  return value && typeof value === "object" && !Array.isArray(value) &&
    Object.keys(value).every(k => allowed.includes(k));
}
export function monitorMetadataOnly(data, detail = false) {
  if (!detail) return onlyKeys(data, SUMMARY_KEYS) && Array.isArray(data.rows) &&
    data.rows.every(row => onlyKeys(row, ROW_KEYS));
  return onlyKeys(data, RUN_KEYS) && onlyKeys(data.run, DETAIL_RUN_KEYS) &&
    Array.isArray(data.events) && data.events.every(ev => onlyKeys(ev, EVENT_KEYS)) &&
    Array.isArray(data.artifacts) && data.artifacts.every(a => onlyKeys(a, ARTIFACT_KEYS)) &&
    onlyKeys(data.execution_admission, ADMISSION_KEYS) &&
    !RELIANCE_FORBIDDEN.test(JSON.stringify(data.current_reliance ?? {}));
}
