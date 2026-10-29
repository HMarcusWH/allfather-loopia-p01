/**
 * UI-01 — pure, metadata-only temporal memory and historical due projections.
 * No backend calls, timers, source URLs, model narration or fallback fixtures.
 * Read results must come from current M02-scoped owner wrappers.
 */
const UUID=/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const HASH=/^[a-f0-9]{64}$/;
const UTC=/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const KINDS=new Set(["SOURCE_DIFF","COMPANY_SIGNAL"]);
const WINDOWS=new Set(["DAY","WEEK","MONTH"]);
const HEADS=new Set(["SINGLE_CANDIDATE_NOT_ADMITTED","AMBIGUOUS_HEAD_HOLD"]);
const REPLAYS=new Set(["MATCH_METADATA_ONLY","NOT_REPLAYABLE","DIVERGED","NOT_EVALUATED"]);
const DUE=new Set([
  "DUE_CANDIDATE_HUMAN_REVIEW_ONLY","NO_NEW_BASIS_CANDIDATE",
  "NOT_EVALUATED","NOT_DUE_CANDIDATE","DEFERRED_COOLDOWN",
  "DEFERRED_BACKPRESSURE","DEFERRED_COALESCE_REVIEW","HOLD_CURRENT_RIGHTS"
]);
const safe=(v)=>v!==null&&typeof v==="object"&&!Array.isArray(v);
const id=(v)=>typeof v==="string"&&UUID.test(v);
const hex=(v)=>typeof v==="string"&&HASH.test(v);
const utc=(v)=>typeof v==="string"&&UTC.test(v);
const empty=(status)=>({status,rows:[],nextCursor:null,readOnly:true,liveActions:false});
const initial=(read)=>{
  if(!safe(read))return "UNAVAILABLE";
  if(read.decision==="NO_ACTIVE_WORKSPACE")return "NO_ACTIVE_WORKSPACE";
  if(read.decision==="DENIED")return "DENIED";
  if(read.decision==="NOT_FOUND")return "NOT_FOUND";
  if(read.decision!=="VISIBLE"||read.required_permission!=="evidence.read")return "UNAVAILABLE";
  return null;
};

export function projectTemporalMemory(read){
  const state=initial(read);
  if(state)return empty(state);
  const d=read.data;
  if(!safe(d)||d.projection_version!=="ui01.inert-p01-temporal-memory.v1"||
      d.read_only!==true||d.candidate_only!==true||
      d.source_rights!=="U03_NOT_ADOPTED"||d.runtime_actions_available!==false||
      d.historical_analysis_available!==false||d.customer_output_authorized!==false||
      !Array.isArray(d.rows)||!Number.isInteger(d.page_size)||
      d.page_size<1||d.page_size>50||d.rows.length>d.page_size)return empty("UNAVAILABLE");
  const seen=new Set(),rows=[];
  for(const r of d.rows){
    if(!safe(r)||!id(r.temporal_memory_id)||seen.has(r.temporal_memory_id)||
        !hex(r.v2_run_identity_digest)||!hex(r.rollup_digest)||
        !KINDS.has(r.data_class)||!WINDOWS.has(r.window_kind)||
        !utc(r.period_start_utc)||!utc(r.period_end_utc)||
        r.period_start_utc>=r.period_end_utc||!utc(r.knowledge_cutoff_utc)||
        !Number.isInteger(r.selected_ref_count)||r.selected_ref_count<1||r.selected_ref_count>64||
        !Number.isInteger(r.contrary_ref_count)||r.contrary_ref_count<0||
        r.contrary_ref_count>r.selected_ref_count||
        !HEADS.has(r.head_status)||!REPLAYS.has(r.replay_status)||
        r.rights_status!=="NOT_ADMITTED"||r.source_bytes_verified!==false||
        r.historical_reuse_admitted!==false||r.customer_release_authorized!==false)
      return empty("UNAVAILABLE");
    seen.add(r.temporal_memory_id);
    rows.push({
      id:r.temporal_memory_id,runDigest:r.v2_run_identity_digest,
      rollupDigest:r.rollup_digest,dataClass:r.data_class,
      window:r.window_kind,from:r.period_start_utc,to:r.period_end_utc,
      knownCutoff:r.knowledge_cutoff_utc,
      references:r.selected_ref_count,contrary:r.contrary_ref_count,
      head:r.head_status,replay:r.replay_status,
      rights:"Not adopted",readOnly:true
    });
  }
  const cursor=d.next_cursor??null;
  if(cursor!==null&&(!id(cursor)||rows.length===0||cursor!==rows.at(-1).id))
    return empty("UNAVAILABLE");
  return {status:rows.length?"READY":"EMPTY",rows,nextCursor:cursor,
    readOnly:true,liveActions:false};
}

export function projectHistoryReview(read){
  const state=initial(read);
  if(state)return empty(state);
  const d=read.data;
  if(!safe(d)||d.projection_version!=="li16.r2.inert-temporal-readback.v1"||
      d.read_only!==true||d.candidate_only!==true||
      d.source_rights!=="U03_NOT_ADOPTED"||d.runtime_actions_available!==false||
      d.historical_analysis_available!==false||!Array.isArray(d.rows)||
      d.rows.length>50)return empty("UNAVAILABLE");
  const seen=new Set(),rows=[];
  for(const r of d.rows){
    if(!safe(r)||!id(r.history_review_basis_id)||seen.has(r.history_review_basis_id)||
       !hex(r.basis_digest)||!Number.isInteger(r.selected_count)||
       r.selected_count<0||r.selected_count>256||
       !Number.isInteger(r.excluded_count)||r.excluded_count<0||
       r.excluded_count>256||
       !new Set(["NONE_NOT_ADMITTED",...HEADS]).has(r.head_status)||
       !DUE.has(r.due_status)||r.rights_status!=="NOT_ADOPTED")return empty("UNAVAILABLE");
    seen.add(r.history_review_basis_id);
    rows.push({
      id:r.history_review_basis_id,basisDigest:r.basis_digest,
      selected:r.selected_count,excluded:r.excluded_count,
      head:r.head_status,due:r.due_status,rights:"Not adopted"
    });
  }
  return {status:rows.length?"READY":"EMPTY",rows,nextCursor:null,
    readOnly:true,liveActions:false};
}
