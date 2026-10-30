/** UI-02: exact metadata-only projection. Never inspect candidate text or build raw HTML. */
const HEX=/^[a-f0-9]{64}$/;
const ID=/^[A-Za-z0-9_.:-]{1,128}$/;
const reasons=Object.freeze({
  NO_ACTIVE_WORKSPACE:"Select an authorized workspace.",
  DENIED:"The current owner refused this review.",
  NOT_FOUND:"No eligible review matches this selector.",
  UNAVAILABLE:"Metadata is unavailable; no-change is not inferred.",
});
export const PRODUCT_READ_JOURNEY=Object.freeze([
  ["Company and entity context","p02.entitySnapshots","entity_twin.read"],
  ["Declared ICP","p03.declaredIcp","customer_catalogue.read"],
  ["Customer offers","p03.customerOffers","customer_catalogue.read"],
  ["Candidate source watchlist","p01.sourceWatch","competition_monitoring.read"],
  ["Approved P01 battlecards","p01.releasedBattlecards","battlecard.read"],
  ["Internal P04 role briefs","p04.roleDistributions","role_briefing.read"],
  ["Internal P05 marketing drafts","p05.internalMarketingDrafts","marketing_asset.read"],
  ["M10 decisions","m10.decisions","decision.read"],
]);
const fail=(status)=>({status,rows:[],reason:reasons[status]??reasons.UNAVAILABLE,nextPage:null});
const obj=x=>x!==null&&typeof x==="object"&&!Array.isArray(x);
export function projectHistoryWorkbench(read){
  if(!obj(read))return fail("UNAVAILABLE");
  if(read.decision!=="VISIBLE")return fail(read.decision in reasons?read.decision:"UNAVAILABLE");
  const d=read.data;
  if(!obj(d)||d.version!=="ui02.history-review-workbench.v1"
      ||d.read_only!==true||d.fixture_only!==true||d.runtime_commands_enabled!==false
      ||d.actual_source_owner_authenticated!==false
      ||!Array.isArray(d.rows)||d.rows.length>10
      ||!Number.isInteger(d.page)||d.page<1||d.page>50
      ||!Number.isInteger(d.page_size)||d.page_size<1||d.page_size>10
      ||d.rows.length>d.page_size)return fail("UNAVAILABLE");
  const rows=[];
  const seen=new Set();
  for(const r of d.rows){
    if(!obj(r)||!HEX.test(r.revision)||!HEX.test(r.finding_digest)
        ||!HEX.test(r.basis_digest)||!ID.test(r.review_id)
        ||!Array.isArray(r.primary_references)
        ||r.primary_references.length>20
        ||r.selected_count!==r.primary_references.length
        ||!Number.isInteger(r.contrary_count)||r.contrary_count<0
        ||r.contrary_count>r.selected_count
        ||r.human_factual_release_approved!==false
        ||r.human_delivery_approved!==false
        ||r.customer_visibility_authorized!==false
        ||r.status!=="HUMAN_REVIEW_REQUIRED"
        ||r.historical_source_rights!=="U03_NOT_ADOPTED"
        ||seen.has(r.revision))return fail("UNAVAILABLE");
    seen.add(r.revision);
    const refs=[];
    for(const x of r.primary_references){
      if(!obj(x)||!ID.test(x.ref_id)||!ID.test(x.source_ref_id)
        ||!["SUPPORT","CONTRARY","UNKNOWN"].includes(x.polarity)
        ||typeof x.cited_as_support!=="boolean"
        ||x.cited_as_support&&x.polarity!=="SUPPORT")return fail("UNAVAILABLE");
      refs.push({id:x.ref_id,source:x.source_ref_id,polarity:x.polarity,
        knownAt:x.known_at_utc,support:x.cited_as_support});
    }
    rows.push({revision:r.revision,reviewId:r.review_id,
      finding:r.finding_digest,basis:r.basis_digest,
      cutoff:r.knowledge_cutoff_utc,critic:r.critic_digest,
      analyst:r.analyst_digest,status:r.status,
      contrary:r.contrary_count,refs,
      outcome:r.outcome_status,adjudication:r.outcome_adjudication,
      attention:r.attention_status,due:r.review_due_status,
      humanFactualApproval:false,humanDeliveryApproval:false});
  }
  const next=d.next_page;
  if(next!==null&&(!Number.isInteger(next)||next!==d.page+1||next>50))return fail("UNAVAILABLE");
  return {status:rows.length?"READY":"EMPTY",rows,reason:null,nextPage:next,
    scope:d.scope};
}
export function availableProductJourney({adapterReadIds=[],permissionKeys=[]}={}){
  const adapters=new Set(adapterReadIds),permissions=new Set(permissionKeys);
  return PRODUCT_READ_JOURNEY.map(([label,id,permission])=>({
    label,id,permission,state:adapters.has(id)&&permissions.has(permission)
      ?"OWNER_READ_WIRED":"UNAVAILABLE_OR_DENIED",
  }));
}
