import { useState } from "react"; import { ReadPanel } from "../../app/read-panel.jsx"; import { Collection } from "../../collections/collection.jsx"; import { READS } from "../../data/surface-reads.js"; import { BoundaryNote, StatusBadge } from "../../primitives/index.jsx";
const SCOPES=["TENANT_ADMIN_LEADERSHIP","SALES","MARKETING","PRODUCT","PROCUREMENT_FINANCE_OPS","BROAD_INTERNAL_BI","RESTRICTED_NEED_TO_KNOW"];
function SnapshotIndex({data,onSelect}){const rows=(data?.rows??[]).map(row=>({...row,open:<button type="button" className="link-button" onClick={()=>onSelect(row.released_entity_twin_snapshot_id)}>Open detail</button>}));return <Collection rows={rows} columns={[...(data?.columns??[]),{key:"open",label:"Detail"}]}/>;}
export function EntityDetail({data}){
  if(!data) return null;
  const entity=data.entity??{};const snapshot=data.snapshot??{};const uncertainty=data.uncertainty;
  return <div className="entity-detail">
    <p><strong>{entity.display_name??"Unnamed entity"}</strong> <span className="mono">{entity.entity_kind}</span> · {[entity.industry,entity.segment,entity.country].filter(Boolean).join(" · ")}</p>
    <p>Released {snapshot.released_at} · claim ceiling <StatusBadge tone="amber">{snapshot.claim_ceiling}</StatusBadge> · version {data.version?.version_sequence}</p>
    <p>Current reliance <StatusBadge tone={data.currently_relied_on?"green":"amber"}>{String(data.current_reliance??"UNKNOWN")}</StatusBadge>{data.superseded_by?<> · superseded by <code>{data.superseded_by}</code></>:null}</p>
    <p>Freshest source observation: {data.freshest_source_observed_at??"unknown"}</p>
    <h3>Released state</h3>
    <Collection rows={data.fields??[]} columns={[{key:"field_key",label:"Field"},{key:"field_value_safe",label:"Value"},{key:"value_status",label:"Status"}]}/>
    {uncertainty?<div className="read-summary"><strong>Uncertainty</strong><p>{uncertainty.summary_safe} {uncertainty.unknown_field_count?`· ${uncertainty.unknown_field_count} unknown field(s)`:""} {uncertainty.has_conflict?`· conflict: ${uncertainty.conflict_summary_safe??""}`:""}</p></div>:null}
    <h3>Lineage</h3>
    <Collection rows={data.lineage??[]} columns={[{key:"source_basis",label:"Basis"},{key:"allowed_use",label:"Allowed use"},{key:"freshness_observed_at",label:"Observed"},{key:"evidence_id",label:"Evidence"}]}/>
  </div>;
}

function OwnCompanyDraftIndex({data}){
  return <div className="own-company-drafts">
    {(data?.rows??[]).map(row=><section key={row.entity_twin_id} className="entity-detail">
      <p><strong>{row.display_name}</strong> · {[row.industry,row.segment,row.country].filter(Boolean).join(" · ")}</p>
      <p><StatusBadge tone="amber">INTERNAL_DRAFT</StatusBadge> · version {row.version_sequence} · internal use only; not an M10 release</p>
      <p>Source-linked draft · {row.uncertainty_summary_safe}{row.unknown_field_count?(" · "+row.unknown_field_count+" unknown fields"):""}</p>
      <h3>Customer-provided fields</h3>
      <Collection rows={row.fields??[]} columns={[{key:"field_key",label:"Field"},{key:"field_value_safe",label:"Value"},{key:"value_status",label:"Status"}]}/>
      <h3>Source classification</h3>
      <Collection rows={row.lineage??[]} columns={[{key:"source_basis",label:"Basis"},{key:"allowed_use",label:"Allowed use"},{key:"freshness_observed_at",label:"Observed"}]}/>
    </section>)}
  </div>;
}
export function EntityContextView({datasource}){const [scope,setScope]=useState("");const [snapshot,setSnapshot]=useState("");const query={visibility_scope:scope};return <div className="surface-page p02-view"><header className="surface-head"><div><div className="surface-kicker"><StatusBadge tone="cyan">P02</StatusBadge><span>first-party draft + released snapshots</span></div><h1>Entity Context</h1><p>Customer-provided first-party company drafts are shown separately from governed released entity and relationship snapshots. Open a released entity snapshot to read its state, uncertainty, lineage and whether it is currently relied on.</p></div></header><label>Visibility lens <select value={scope} onChange={e=>{setScope(e.target.value);setSnapshot("");}}><option value="">Select a governed scope</option>{SCOPES.map(value=><option key={value} value={value}>{value}</option>)}</select></label><div className="owner-grid"><ReadPanel datasource={datasource} readId={READS.P02_OWN_DRAFT} title="Own-company internal draft" eyebrow="P02 / customer-provided" icon="building-2" className="span-2">{data=><OwnCompanyDraftIndex data={data}/>}</ReadPanel><ReadPanel datasource={datasource} readId={READS.P02_ENTITY_INDEX} title="Released entity snapshots" icon="network" query={query} enabled={scope!==""}>{data=><SnapshotIndex data={data} onSelect={setSnapshot}/>}</ReadPanel><ReadPanel datasource={datasource} readId={READS.P02_RELATION_INDEX} title="Released relationships" icon="route" query={query} enabled={scope!==""}/><ReadPanel datasource={datasource} readId={READS.P02_ENTITY_DETAIL} title="Entity snapshot detail" eyebrow="P02 owner detail read" icon="eye" className="span-2" query={{snapshot}} enabled={snapshot!==""}>{data=><EntityDetail data={data}/>}</ReadPanel></div><BoundaryNote title="Twin boundary">OWN_COMPANY is an intelligence twin concept, not tenant identity. Offering twins, stakeholder-profile producer state and polymorphic/PDB functionality are excluded. No relationship graph is drawn from data that is not a released relationship snapshot.</BoundaryNote></div>;}
