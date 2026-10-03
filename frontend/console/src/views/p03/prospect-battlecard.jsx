import { ReadPanel } from "../../app/read-panel.jsx"; import { READS } from "../../data/surface-reads.js"; import { StatusBadge } from "../../primitives/index.jsx";
// One released prospect output, read through the P03 owner. The battlecard is a REFERENCE carried on
// the detail; there is still no prospect-battlecard list read (READS.P03_BATTLECARDS stays deferred).
export function ProspectDetail({data}){
  if(!data) return null;
  const h=data.hypothesis??{};const signal=data.signal??{};
  return <div className="prospect-detail">
    <p><StatusBadge tone="cyan">{data.output?.output_kind}</StatusBadge> released {data.output?.released_at} · current reliance <StatusBadge tone={data.currently_relied_on?"green":"amber"}>{String(data.current_reliance??"UNKNOWN")}</StatusBadge></p>
    <p>Fit <strong>{h.fit_band}</strong> · {h.claim_kind}{h.icp_fit_score!=null?` · score ${h.icp_fit_score}`:""}{h.uncertainty_explicit?" · uncertainty stated":""}</p>
    {(h.icp_fit_reasons??[]).length?<ul className="plain-list">{h.icp_fit_reasons.map((r,i)=><li key={i}>{typeof r==="string"?r:JSON.stringify(r)}</li>)}</ul>:null}
    <div className="read-summary"><strong>Signal</strong><p>{signal.status==="RELEASED"?`${signal.signal_class} · ${signal.confidence_band} · ${signal.summary_safe??""}`:"This signal is no longer released; its text is not shown."}</p></div>
    {data.product?.display_name?<p>Product: {data.product.display_name}</p>:null}
    <p>Battlecard: {data.battlecard?<code>{data.battlecard.prospect_battlecard_brief_id}</code>:"none released"}</p>
    {(data.assurance??[]).length?<><h3>Assurance</h3><ul className="plain-list">{data.assurance.map(a=><li key={a.audit_category}>{a.audit_category}: <StatusBadge tone={a.finding_type==="PASS"?"green":"amber"}>{a.finding_type}</StatusBadge> {a.completed_at??""}</li>)}</ul></>:null}
    <p className="claim-note">{data.claim_ceiling_note}</p>
  </div>;
}
export function ProspectDetailPanel({datasource,output}){return <ReadPanel datasource={datasource} readId={READS.P03_PROSPECT_DETAIL} title="Released prospect detail" eyebrow="P03 owner detail read" icon="shield-check" className="span-2" query={{output}} enabled={output!==""}>{data=><ProspectDetail data={data}/>}</ReadPanel>;}
