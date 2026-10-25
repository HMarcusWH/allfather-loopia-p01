/**
 * Inert R1 history view model. Receives only the closed PHP metadata adapter
 * result; no network, clock, cache, fallback fixture or automatic page request.
 *
 * NO_MATERIAL_CHANGE (model assessment) != unchanged structural capture.
 * No release, evidence content or independent corroboration is implied.
 */
const STATES = Object.freeze({
  BASELINE: "Baseline capture",
  STRUCTURALLY_UNCHANGED: "Structurally unchanged capture",
  STRUCTURAL_CHANGE_RECORDED: "Structural difference recorded",
  MULTIPLE_COMPARISONS: "Multiple structural comparisons",
  COMPARISON_UNAVAILABLE: "Comparison unavailable"
});
const SIGNIFICANCE = Object.freeze({
  NONE: "No structural difference",
  MINOR: "Small structural difference",
  MATERIAL: "Large structural difference",
  UNKNOWN: "Unclassified structural difference"
});
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const STATUS = Object.freeze(["ACTIVE","PAUSED"]);
const empty = (state) => ({state,competitor:null,rows:[],nextCursor:null,readOnly:true});
const safeId = (value) => typeof value === "string" && UUID.test(value);
const isObject = (value) => value !== null && typeof value === "object" && !Array.isArray(value);

/** @returns an explicitly non-authoritative presentation over already allowed metadata */
export function projectCompetitorHistory(read) {
  if (!isObject(read)) return empty("UNAVAILABLE");
  if (read.decision === "NO_ACTIVE_WORKSPACE") return empty("NO_ACTIVE_WORKSPACE");
  if (read.decision === "DENIED") return empty("DENIED");
  if (read.decision === "NOT_FOUND") return empty("NOT_FOUND");
  if (read.decision !== "VISIBLE" || read.required_permission !== "evidence.read") return empty("UNAVAILABLE");
  const data = read.data;
  if (!isObject(data) || data.read_only !== true ||
      data.source_rights !== "CURRENT_ELIGIBILITY_CHECKED" ||
      data.business_materiality_available !== false ||
      data.projection_version !== "li16.competitor-history-metadata.v1" ||
      !isObject(data.competitor) ||
      !safeId(data.competitor.competitor_id) ||
      typeof data.competitor.display_name !== "string" ||
      !STATUS.includes(data.competitor.status) ||
      !Array.isArray(data.rows) || data.rows.length > 50 ||
      !Number.isInteger(data.page_size) || data.page_size < 1 || data.page_size > 50 ||
      data.rows.length > data.page_size) return empty("UNAVAILABLE");
  if (data.next_cursor !== null && data.next_cursor !== undefined && !safeId(data.next_cursor)) return empty("UNAVAILABLE");

  const rows = [];
  for (const row of data.rows) {
    if (!isObject(row) || !safeId(row.source_snapshot_id) || !safeId(row.monitored_source_id) ||
        typeof row.source_kind !== "string" ||
        typeof row.captured_at !== "string" ||
        !Number.isInteger(row.snapshot_sequence) || row.snapshot_sequence < 1 ||
        !(row.coverage_state in STATES) ||
        row.business_materiality !== "NOT_ASSESSED" ||
        row.observation_assessment !== "NOT_IMPLEMENTED" ||
        row.release_status !== "NOT_INFERRED" ||
        !Array.isArray(row.comparisons) || row.comparisons.length > 16) return empty("UNAVAILABLE");
    const comparisonLabels = [];
    for (const c of row.comparisons) {
      if (!isObject(c) || !safeId(c.source_diff_id) || !(c.structural_significance in SIGNIFICANCE)
          || typeof c.detected_at !== "string") return empty("UNAVAILABLE");
      comparisonLabels.push({id:c.source_diff_id,label:SIGNIFICANCE[c.structural_significance],detectedAt:c.detected_at});
    }
    rows.push({
      id:row.source_snapshot_id,
      sourceId:row.monitored_source_id,
      sourceKind:row.source_kind,
      capturedAt:row.captured_at,
      sequence:row.snapshot_sequence,
      label:STATES[row.coverage_state],
      comparisons:comparisonLabels,
      materiality:"Not assessed",
      review:"Not implemented",
      release:"Not inferred"
    });
  }
  if (data.next_cursor && (!rows.length || data.next_cursor !== rows[rows.length-1].id)) return empty("UNAVAILABLE");
  return {
    state:rows.length?"READY":"EMPTY",
    competitor:{id:data.competitor.competitor_id,name:data.competitor.display_name,status:data.competitor.status},
    rows,
    nextCursor:data.next_cursor ?? null,
    readOnly:true,
    note:"Only current-rights-eligible capture metadata. No historical analysis, materiality assessment, notification or customer release."
  };
}
