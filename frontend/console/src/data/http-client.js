// Phase-1 transport policy metadata only. No endpoint, fetch(), XHR or live network wiring exists here.
export const READ_TRANSPORT = Object.freeze({
  ORDINARY: Object.freeze({ key: "ordinary", allowPrefetch: true, transparentRetry: "IDEMPOTENT_ONLY" }),
  AUDITED_DISPLAY: Object.freeze({ key: "audited-display", allowPrefetch: false, transparentRetry: "NEVER" })
});
export function transportPolicyFor(readMeta){ return readMeta?.auditedDisplay ? READ_TRANSPORT.AUDITED_DISPLAY : READ_TRANSPORT.ORDINARY; }
export function assertJsonReadResponseShape(contentType){ return typeof contentType === "string" && contentType.toLowerCase().includes("application/json"); }
