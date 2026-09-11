import { PROJECTION } from "./response-types.js";

export const CONTEXT_STATE = Object.freeze({
  LOADING: "LOADING",
  READY: "READY",
  AUTHENTICATION_REQUIRED: "AUTHENTICATION_REQUIRED",
  NO_ACTIVE_WORKSPACE: "NO_ACTIVE_WORKSPACE",
  DENIED: "DENIED",
  UNAVAILABLE: "UNAVAILABLE",
});

/**
 * Classify the M12 context envelope for presentation only.
 *
 * This does not grant authority or infer permissions. It exists so context failures remain visible
 * instead of collapsing navigation to an indistinguishable blank console.
 */
export function classifyContextEnvelope(envelope) {
  const projectionState = envelope?.projection?.state ?? null;
  const reasons = Array.isArray(envelope?.reason_codes) ? envelope.reason_codes : [];

  if (projectionState === PROJECTION.LOADING) return CONTEXT_STATE.LOADING;
  if (projectionState === PROJECTION.READY) return CONTEXT_STATE.READY;
  if (reasons.includes("AUTHENTICATION_REQUIRED")) return CONTEXT_STATE.AUTHENTICATION_REQUIRED;
  if (reasons.includes("NO_ACTIVE_WORKSPACE")) return CONTEXT_STATE.NO_ACTIVE_WORKSPACE;
  if (envelope?.access?.decision === "DENIED") return CONTEXT_STATE.DENIED;
  return CONTEXT_STATE.UNAVAILABLE;
}
