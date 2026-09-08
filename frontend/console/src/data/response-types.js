export const ACCESS = Object.freeze({ VISIBLE: "VISIBLE", DENIED: "DENIED" });
export const PROJECTION = Object.freeze({
  LOADING: "LOADING",
  READY: "READY",
  EMPTY: "EMPTY",
  UNAVAILABLE: "UNAVAILABLE",
  ERROR: "ERROR"
});
export const FRESHNESS = Object.freeze({ CURRENT: "CURRENT", STALE: "STALE", UNKNOWN: "UNKNOWN", ERROR: "ERROR" });

export function unavailableEnvelope(source, reason = "PHASE1_NOT_WIRED") {
  return Object.freeze({
    source,
    as_of: null,
    access: Object.freeze({ decision: ACCESS.VISIBLE, required_permission: null, reason_codes: ["PRESENTATION_ONLY"] }),
    projection: Object.freeze({ state: PROJECTION.UNAVAILABLE, stale_status: FRESHNESS.UNKNOWN }),
    owner_state: null,
    reason_codes: Object.freeze([reason]),
    data: null
  });
}

export function availableEnvelope(source, data, options = {}) {
  const empty = Array.isArray(data) ? data.length === 0 : data == null;
  return Object.freeze({
    source,
    as_of: options.asOf ?? null,
    access: Object.freeze({ decision: options.access ?? ACCESS.VISIBLE, required_permission: null, reason_codes: Object.freeze(options.accessReasons ?? ["FIXTURE_DEV_ONLY"]) }),
    projection: Object.freeze({ state: empty ? PROJECTION.EMPTY : PROJECTION.READY, stale_status: options.freshness ?? FRESHNESS.CURRENT }),
    owner_state: options.ownerState ?? null,
    reason_codes: Object.freeze(options.reasonCodes ?? []),
    data
  });
}
