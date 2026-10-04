import { useState } from "react";
import { ReadPanel } from "../../app/read-panel.jsx";
import { Collection } from "../../collections/collection.jsx";
import { READS } from "../../data/surface-reads.js";
import { readUsableForPermissions } from "../../data/navigation.js";
import { BoundaryNote, StatusBadge } from "../../primitives/index.jsx";
import { ProspectDetailPanel } from "./prospect-battlecard.jsx";

function OutputIndex({data,onSelect}){
  const rows=(data?.rows??[]).map(row=>({...row,open:<button type="button" className="link-button" onClick={()=>onSelect(row.released_prospect_output_id)}>Open detail</button>}));
  return <Collection rows={rows} columns={[...(data?.columns??[]),{key:"open",label:"Detail"}]}/>;
}

export function ProspectQueueView({datasource,permissionKeys=[]}){
  const canReadQueue=readUsableForPermissions(READS.P03_QUEUE,permissionKeys);
  const canReadWatch=readUsableForPermissions(READS.P03_WATCH,permissionKeys);
  const [output,setOutput]=useState("");

  return <div className="surface-page p03-view">
    <header className="surface-head"><div><div className="surface-kicker"><StatusBadge tone="green">P03</StatusBadge><span>released prospect intelligence</span></div><h1>Lead Intelligence</h1><p>Available release panels are filtered to the current context permissions before any owner endpoint is called. Open a released item to read its fit hypothesis, signal, assurance and whether it is currently relied on.</p></div></header>
    <div className="owner-grid">
      {canReadQueue?<ReadPanel datasource={datasource} readId={READS.P03_QUEUE} title="Priority queue" icon="target">{data=><OutputIndex data={data} onSelect={setOutput}/>}</ReadPanel>:null}
      {canReadWatch?<ReadPanel datasource={datasource} readId={READS.P03_WATCH} title="Monitored prospects" icon="eye">{data=><OutputIndex data={data} onSelect={setOutput}/>}</ReadPanel>:null}
      {canReadQueue||canReadWatch?<ProspectDetailPanel datasource={datasource} output={output}/>:null}
    </div>
    <BoundaryNote title="Fit is not intent">The prototype catalogue/ICP/full-fit browser is not promoted. No queue item creates email, CRM, SMS or other outbound authority.</BoundaryNote>
  </div>;
}
