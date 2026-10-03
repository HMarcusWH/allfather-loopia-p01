import { READS } from "./surface-reads.js";

export const NAVIGATION_ALLOWLIST=Object.freeze([
 {group:"Operator",items:[{id:"overview",label:"Overview",icon:"layout-dashboard",reads:[READS.OVERVIEW_PULSE]},{id:"needs-you",label:"Needs you",icon:"inbox",reads:[READS.ATTENTION_QUEUE]}]},
 {group:"Intelligence",items:[{id:"p01",label:"Competition Monitor",icon:"radar",reads:[READS.P01_BATTLECARDS]},{id:"monitor-runs",label:"Monitor runs",icon:"history",reads:[READS.MONITOR_RUNS]},{id:"p02",label:"Entity Context",icon:"network",reads:[READS.P02_ENTITY_INDEX,READS.P02_RELATION_INDEX]},{id:"p03",label:"Lead Intelligence",icon:"target",reads:[READS.P03_QUEUE,READS.P03_WATCH]},{id:"p04",label:"Distribution",icon:"route",reads:[READS.P04_DISTRIBUTIONS]},{id:"p05",label:"Marketing Drafts",icon:"file-pen-line",reads:[READS.P05_DRAFTS]}]},
 {group:"Sources",items:[{id:"source-ops",label:"Source Operations",icon:"scan-search",reads:[READS.SOURCE_P01,READS.SOURCE_M05,READS.SOURCE_M15]},{id:"ingestion",label:"Ingestion",icon:"funnel",reads:[READS.INGESTION_CANDIDATES]},{id:"evidence",label:"Evidence",icon:"file-check-2",reads:[READS.EVIDENCE_INDEX]}]},
 {group:"Governance",items:[{id:"missions",label:"Missions",icon:"map",reads:[READS.MISSIONS]},{id:"review",label:"Council review",icon:"users-round",reads:[READS.REVIEW]},{id:"arbiter",label:"Arbiter",icon:"scale",reads:[READS.ARBITER]},{id:"ledger",label:"Decision ledger",icon:"book-open-check",reads:[READS.LEDGER]},{id:"release",label:"Release visibility",icon:"eye",reads:[READS.RELEASE]}]},
 {group:"Runtime",items:[{id:"access",label:"Access",icon:"key-round",reads:[READS.ACCESS]},{id:"contracts",label:"Contracts",icon:"shield-check",reads:[READS.CONTRACTS]},{id:"gateway",label:"Tool Gateway",icon:"waypoints",reads:[READS.GATEWAY]},{id:"host-runtime",label:"Host runtime",icon:"server",reads:[READS.HOST]},{id:"console-state",label:"Console state",icon:"panels-top-left",reads:[READS.CONSOLE_STATE]}]},
 {group:"Assurance",items:[{id:"audit-replay",label:"Audit / replay",icon:"history",reads:[READS.AUDIT]}]}
]);

// Usability only. These keys mirror the permissions required by the already-wired owner reads;
// they never replace the backend owner checks. Read-level mapping is explicit so a multi-read route
// can avoid issuing a sibling request that the current context cannot use.
export const READ_USABILITY_PERMISSION=Object.freeze({
  [READS.P01_BATTLECARDS]:"battlecard.read",
  [READS.P02_ENTITY_INDEX]:"entity_twin.read",
  [READS.P02_RELATION_INDEX]:"entity_twin.read",
  [READS.P03_QUEUE]:"priority_outreach_queue.read",
  [READS.P03_WATCH]:"prospect.read",
  [READS.P04_DISTRIBUTIONS]:"role_briefing.read",
  [READS.P05_DRAFTS]:"marketing_asset.read",
  [READS.LEDGER]:"decision.read",
  // P09 detail and Monitor reads. A detail read is reached from its index row, so it shares the
  // index permission; the owner still decides per object.
  [READS.P02_ENTITY_DETAIL]:"entity_twin.read",
  [READS.MONITOR_RUNS]:"mission.read",
  [READS.MONITOR_RUN]:"evidence.read"
});

const permissionsForReads=(...readIds)=>Object.freeze([...new Set(readIds.map(id=>READ_USABILITY_PERMISSION[id]).filter(Boolean))]);
export const ROUTE_USABILITY_PERMISSIONS=Object.freeze({
  p01:permissionsForReads(READS.P01_BATTLECARDS),
  "monitor-runs":permissionsForReads(READS.MONITOR_RUNS),
  p02:permissionsForReads(READS.P02_ENTITY_INDEX,READS.P02_RELATION_INDEX),
  p03:permissionsForReads(READS.P03_QUEUE,READS.P03_WATCH),
  p04:permissionsForReads(READS.P04_DISTRIBUTIONS),
  p05:permissionsForReads(READS.P05_DRAFTS),
  ledger:permissionsForReads(READS.LEDGER)
});

export const STATIC_NAV_IDS=Object.freeze(NAVIGATION_ALLOWLIST.flatMap(g=>g.items.map(x=>x.id)));

export function buildNavigation({adapterReadIds=[],usableIds=null}={}){
  const adapters=new Set(adapterReadIds);
  const usable=usableIds==null?null:new Set(usableIds);
  return Object.freeze(NAVIGATION_ALLOWLIST.map(g=>Object.freeze({...g,items:Object.freeze(g.items.filter(item=>(usable==null||usable.has(item.id))&&item.reads.some(r=>adapters.has(r))))})).filter(g=>g.items.length));
}

export function readUsableForPermissions(readId,permissionKeys=[]){
  const required=READ_USABILITY_PERMISSION[readId];
  if(!required) return false;
  return new Set(Array.isArray(permissionKeys)?permissionKeys:[]).has(required);
}

export function usableNavigationIdsForPermissions(permissionKeys=[]){
  const permissions=new Set(Array.isArray(permissionKeys)?permissionKeys:[]);
  return Object.freeze(STATIC_NAV_IDS.filter(id=>{
    const required=ROUTE_USABILITY_PERMISSIONS[id];
    return required?.some(permission=>permissions.has(permission))??false;
  }));
}

export function navigationIds(groups){ return Object.freeze(groups.flatMap(g=>g.items.map(x=>x.id))); }
export function firstNavigationId(groups,fallback=null){ return groups?.[0]?.items?.[0]?.id??fallback; }
