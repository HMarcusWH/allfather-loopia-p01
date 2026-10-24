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
  const canReadIcp=readUsableForPermissions(READS.P03_DECLARED_ICP,permissionKeys);
  const canReadOffers=readUsableForPermissions(READS.P03_CUSTOMER_OFFERS,permissionKeys);
  const canReadQueue=readUsableForPermissions(READS.P03_QUEUE,permissionKeys);
  const canReadWatch=readUsableForPermissions(READS.P03_WATCH,permissionKeys);
  const [output,setOutput]=useState("");

  return <div className="surface-page p03-view">
    <header className="surface-head"><div><div className="surface-kicker"><StatusBadge tone="green">P03</StatusBadge><span>released prospect intelligence</span></div><h1>Lead Intelligence</h1><p>Available release panels are filtered to the current context permissions before any owner endpoint is called. Open a released item to read its fit hypothesis, signal, assurance and whether it is currently relied on.</p></div></header>
    <div className="owner-grid">
      {canReadIcp?<ReadPanel datasource={datasource} readId={READS.P03_DECLARED_ICP} title="Declared ideal customers" eyebrow="P03 / customer-supplied ICP" icon="users-round">
        {data=><div className="read-detail">
          {(data?.rows??[]).map(profile=><section key={profile.icp_profile_id} className="read-summary">
            <strong>{profile.display_name} · v{profile.version_number}</strong>
            <p>{profile.company_size_band??"Size band not declared"}</p>
            <p>Industries: {profile.industries?.join(", ")||"Not declared"}</p>
            <p>Markets: {profile.geographies?.join(", ")||"Not declared"}</p>
            <p>Buyer roles: {profile.buyer_role_classes?.join(", ")||"Not declared"}</p>
            <p>Excluded: {profile.excluded_segments?.join(", ")||"None declared"}</p>
            <p>Uncertainty: {profile.uncertainty_notes_safe}</p>
            <StatusBadge tone="amber">Customer declaration · not verified</StatusBadge>
          </section>)}
        </div>}
      </ReadPanel>:null}
      {canReadOffers?<ReadPanel datasource={datasource} readId={READS.P03_CUSTOMER_OFFERS} title="Our products & services" eyebrow="P03 / tenant-owned catalogue" icon="briefcase-business">
        {data=><div className="read-detail">
          {(data?.rows??[]).map(offer=><section key={offer.product_key} className="read-summary">
            <strong>{offer.display_name}</strong>
            <p>{offer.product_offer_kind} · {offer.product_status}</p>
            {offer.value_proposition_safe?<p>{offer.value_proposition_safe}</p>:null}
            {offer.attributes?.length?<p>Descriptors: {offer.attributes.join(", ")}</p>:null}
            <p>Source status: {offer.provenance_status}</p>
            <StatusBadge tone="amber">Customer-provided · not released intelligence</StatusBadge>
          </section>)}
        </div>}
      </ReadPanel>:null}
      {canReadQueue?<ReadPanel datasource={datasource} readId={READS.P03_QUEUE} title="Priority queue" icon="target">{data=><OutputIndex data={data} onSelect={setOutput}/>}</ReadPanel>:null}
      {canReadWatch?<ReadPanel datasource={datasource} readId={READS.P03_WATCH} title="Monitored prospects" icon="eye">{data=><OutputIndex data={data} onSelect={setOutput}/>}</ReadPanel>:null}
      {canReadQueue||canReadWatch?<ProspectDetailPanel datasource={datasource} output={output}/>:null}
    </div>
    <BoundaryNote title="Customer declarations are not prospect intelligence">ICP and offers are tenant-provided declarations, not independently verified signals. Released prospect outputs remain separate. No queue item sends email, CRM, SMS or other outbound action.</BoundaryNote>
  </div>;
}
