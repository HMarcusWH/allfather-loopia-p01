// Same-origin GET transport for governed browser reads. No generic network client lives here.
export const READ_TRANSPORT = Object.freeze({
  ORDINARY: Object.freeze({ key: "ordinary", allowPrefetch: true, transparentRetry: "IDEMPOTENT_ONLY" }),
  AUDITED_DISPLAY: Object.freeze({ key: "audited-display", allowPrefetch: false, transparentRetry: "NEVER" })
});
export function transportPolicyFor(readMeta){ return readMeta?.auditedDisplay ? READ_TRANSPORT.AUDITED_DISPLAY : READ_TRANSPORT.ORDINARY; }
export function assertJsonReadResponseShape(contentType){ return typeof contentType === "string" && contentType.toLowerCase().includes("application/json"); }

export class HttpReadError extends Error {
  constructor(code, status = null){ super(code); this.name="HttpReadError"; this.code=code; this.status=status; }
}

function assertLocalApiPath(path){
  if(typeof path!=="string" || !path.startsWith("/console/api/v1/") || path.includes("://") || path.startsWith("//")){
    throw new HttpReadError("INVALID_READ_PATH");
  }
}

export async function getJsonRead(path,{query={},signal,fetchImpl=globalThis.fetch}={}){
  assertLocalApiPath(path);
  if(typeof fetchImpl!=="function") throw new HttpReadError("FETCH_UNAVAILABLE");
  const params=new URLSearchParams();
  for(const [key,value] of Object.entries(query??{})){
    if(value===null||value===undefined||value==="") continue;
    params.set(key,String(value));
  }
  const url=params.size?`${path}?${params.toString()}`:path;
  let response;
  try {
    response=await fetchImpl(url,{method:"GET",credentials:"same-origin",redirect:"manual",headers:{Accept:"application/json"},signal});
  } catch (error) {
    if(error?.name==="AbortError") throw error;
    throw new HttpReadError("NETWORK_READ_FAILED");
  }
  if(response.status>=300&&response.status<400) throw new HttpReadError("UNEXPECTED_REDIRECT",response.status);
  if(!assertJsonReadResponseShape(response.headers?.get?.("content-type")??"")) throw new HttpReadError("NON_JSON_READ_RESPONSE",response.status);
  let payload;
  try { payload=await response.json(); } catch { throw new HttpReadError("INVALID_JSON_READ_RESPONSE",response.status); }
  return {status:response.status,payload,url};
}
