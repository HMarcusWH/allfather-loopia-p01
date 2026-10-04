import { ConsoleDataSource } from "./console-data-source.js";
import { getJsonRead, HttpReadError } from "./http-client.js";
import { unavailableEnvelope } from "./response-types.js";
import { unavailableCollectionPage } from "./collection-query.js";
import { READS, readMeta } from "./surface-reads.js";
import { adaptLiveRead } from "./live-read-adapters.js";

const ENDPOINTS=Object.freeze({
  [READS.CONTEXT]: "/console/api/v1/context",
  [READS.P01_BATTLECARDS]: "/console/api/v1/p01/battlecards",
  [READS.P02_ENTITY_INDEX]: "/console/api/v1/p02/entities",
  [READS.P02_RELATION_INDEX]: "/console/api/v1/p02/relationships",
  [READS.P03_QUEUE]: "/console/api/v1/p03/priority-queue",
  [READS.P03_WATCH]: "/console/api/v1/p03/monitored",
  [READS.P04_DISTRIBUTIONS]: "/console/api/v1/p04/distributions",
  [READS.P05_DRAFTS]: "/console/api/v1/p05/drafts",
  [READS.LEDGER]: "/console/api/v1/m10/decisions",
  [READS.P02_ENTITY_DETAIL]: "/console/api/v1/p02/entity-detail",
  [READS.P03_PROSPECT_DETAIL]: "/console/api/v1/p03/prospect-detail",
  [READS.MONITOR_RUNS]: "/console/api/v1/monitor/runs",
  [READS.MONITOR_RUN]: "/console/api/v1/monitor/run"
});
// 404 is a governed answer for detail reads: the caller may read, and the selector names nothing
// they can see — an unknown id and another workspace's id look the same.
const EXPECTED_STATUSES=Object.freeze(new Set([200,403,404,409,503]));
// Detail reads take exactly one selector each. It is an identifier, never scope; the owner re-checks it.
const SELECTORS=Object.freeze({[READS.P02_ENTITY_DETAIL]:"snapshot",[READS.P03_PROSPECT_DETAIL]:"output",[READS.MONITOR_RUN]:"run"});
export const LIVE_READ_IDS=Object.freeze(Object.keys(ENDPOINTS));

function failureEnvelope(source,reason){
  return Object.freeze({source,as_of:null,access:Object.freeze({decision:"VISIBLE",required_permission:null,reason_codes:Object.freeze([reason])}),projection:Object.freeze({state:"UNAVAILABLE",stale_status:"ERROR"}),owner_state:"UNAVAILABLE",reason_codes:Object.freeze([reason]),data:null});
}
function queryFor(readId,query){
  if(readId===READS.P02_ENTITY_INDEX||readId===READS.P02_RELATION_INDEX) return {visibility_scope:query?.visibility_scope??""};
  if(readId===READS.P04_DISTRIBUTIONS) return {target_org_role:query?.target_org_role??""};
  const selector=SELECTORS[readId];
  if(selector) return {[selector]:typeof query?.[selector]==="string"?query[selector]:""};
  return {};
}
function isEnvelope(payload,readId){ return payload && payload.source===readId && payload.access && payload.projection && Array.isArray(payload.reason_codes); }

export class HttpConsoleDataSource extends ConsoleDataSource {
  constructor({fetchImpl=globalThis.fetch}={}){ super(); this.fetchImpl=fetchImpl; }
  async readContext(_query={},options={}){ return this.readDescriptor(READS.CONTEXT,{},options); }
  async readDescriptor(readId,query={},options={}){
    const endpoint=ENDPOINTS[readId];
    if(!endpoint){ const meta=readMeta(readId); return unavailableEnvelope(meta.id,meta.status==="UNKNOWN_READ"?"UNKNOWN_READ_DESCRIPTOR":"GOVERNED_READ_NOT_WIRED"); }
    try {
      const {status,payload}=await getJsonRead(endpoint,{query:queryFor(readId,query),signal:options.signal,fetchImpl:this.fetchImpl});
      if(status===401) return failureEnvelope(readId,"AUTHENTICATION_REQUIRED");
      if(!EXPECTED_STATUSES.has(status)) return failureEnvelope(readId,"UNEXPECTED_READ_STATUS");
      if(!isEnvelope(payload,readId)) return failureEnvelope(readId,"INVALID_READ_ENVELOPE");
      return adaptLiveRead(readId,payload);
    } catch (error) {
      if(error?.name==="AbortError") throw error;
      return failureEnvelope(readId,error instanceof HttpReadError?error.code:"READ_TRANSPORT_FAILED");
    }
  }
  async query(collectionName){ return unavailableCollectionPage(`console.collection.${collectionName}`); }
  getAdapterAvailability(){ return LIVE_READ_IDS; }
  // Production navigation has no unconditional usable routes. ConsoleApp derives the usability
  // filter from the current M12 context permissions before choosing a route.
  getUsableNavigationIds(){ return Object.freeze([]); }
}
