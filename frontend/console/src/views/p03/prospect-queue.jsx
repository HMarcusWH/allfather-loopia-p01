import { ReadPanel } from "../../app/read-panel.jsx";
import { READS } from "../../data/surface-reads.js";
import { readUsableForPermissions } from "../../data/navigation.js";
import { BoundaryNote, StatusBadge } from "../../primitives/index.jsx";

export function ProspectQueueView({datasource,permissionKeys=[]}){
  const canReadQueue=readUsableForPermissions(READS.P03_QUEUE,permissionKeys);
  const canReadWatch=readUsableForPermissions(READS.P03_WATCH,permissionKeys);

  return <div className="surface-page p03-view">
    <header className="surface-head"><div><div className="surface-kicker"><StatusBadge tone="green">P03</StatusBadge><span>released prospect intelligence</span></div><h1>Lead Intelligence</h1><p>Available release panels are filtered to the current context permissions before any owner endpoint is called. Prospect battlecards remain deferred because no owner-safe read model exists.</p></div></header>
    <div className="owner-grid tri">
      {canReadQueue?<ReadPanel datasource={datasource} readId={READS.P03_QUEUE} title="Priority queue" icon="target"/>:null}
      {canReadWatch?<ReadPanel datasource={datasource} readId={READS.P03_WATCH} title="Monitored prospects" icon="eye"/>:null}
      <ReadPanel datasource={datasource} readId={READS.P03_BATTLECARDS} title="Prospect battlecards" icon="shield-check"/>
    </div>
    <BoundaryNote title="Fit is not intent">The prototype catalogue/ICP/full-fit browser is not promoted. No queue item creates email, CRM, SMS or other outbound authority.</BoundaryNote>
  </div>;
}
