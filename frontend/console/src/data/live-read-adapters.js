import { PROJECTION } from "./response-types.js";
import { READS } from "./surface-reads.js";

const columns = Object.freeze({
  [READS.P01_BATTLECARDS]: [
    {key:"released_at",label:"Released"},{key:"signal_summary_safe",label:"Summary"},{key:"confidence_band",label:"Confidence"}
  ],
  [READS.P02_ENTITY_INDEX]: [
    {key:"released_at",label:"Released"},{key:"entity_twin_id",label:"Entity"},{key:"claim_ceiling",label:"Claim ceiling"},{key:"visibility_scope",label:"Scope"}
  ],
  [READS.P02_RELATION_INDEX]: [
    {key:"released_at",label:"Released"},{key:"relationship_edge_id",label:"Relationship"},{key:"claim_ceiling",label:"Claim ceiling"},{key:"visibility_scope",label:"Scope"}
  ],
  [READS.P03_QUEUE]: [
    {key:"released_at",label:"Released"},{key:"fit_band",label:"Fit"},{key:"claim_kind",label:"Claim kind"}
  ],
  [READS.P03_WATCH]: [
    {key:"released_at",label:"Released"},{key:"fit_band",label:"Fit"},{key:"claim_kind",label:"Claim kind"}
  ],
  [READS.P04_DISTRIBUTIONS]: [
    {key:"released_at",label:"Released"},{key:"target_org_role",label:"Role"},{key:"briefing_summary_safe",label:"Briefing"}
  ],
  [READS.P05_DRAFTS]: [
    {key:"released_at",label:"Released"},{key:"asset_kind",label:"Kind"},{key:"title_safe",label:"Title"},{key:"draft_content_safe",label:"Draft"}
  ],
  [READS.LEDGER]: [
    {key:"record_sequence_number",label:"Seq"},{key:"record_type",label:"Type"},{key:"primary_outcome_status",label:"Outcome"},{key:"current_reliance_status",label:"Reliance"},{key:"safe_decision_summary",label:"Decision"},{key:"recorded_at",label:"Recorded"}
  ]
});

export function adaptLiveRead(readId,envelope){
  if(!envelope || envelope.source!==readId) return envelope;
  if(envelope.projection?.state!==PROJECTION.READY) return envelope;
  if(readId===READS.CONTEXT) return envelope;
  if(readId===READS.LEDGER){
    const data=envelope.data??{};
    return {...envelope,data:{rows:data.rows??[],columns:columns[readId],summary:{title:"Ledger integrity",body:`${data.environment_class??"UNKNOWN"} · ${data.verification??"NO STREAM"}`}}};
  }
  if(Array.isArray(envelope.data)) return {...envelope,data:{rows:envelope.data,columns:columns[readId]??[]}};
  return envelope;
}
