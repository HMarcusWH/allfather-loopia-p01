import { useEffect, useMemo, useState } from "react";
import { PROJECTION, unavailableEnvelope } from "../data/response-types.js";
const loading=()=>({projection:{state:PROJECTION.LOADING,stale_status:"UNKNOWN"},data:null,reason_codes:[]});
export function useConsoleRead(datasource,readId,query={},enabled=true){
  const queryKey=useMemo(()=>JSON.stringify(query??{}),[query]);
  const [envelope,setEnvelope]=useState(enabled?loading:()=>unavailableEnvelope(readId,"READ_PARAMETER_REQUIRED"));
  useEffect(()=>{
    if(!enabled){ setEnvelope(unavailableEnvelope(readId,"READ_PARAMETER_REQUIRED")); return; }
    let current=true;
    const controller=new AbortController();
    setEnvelope(loading());
    datasource.readDescriptor(readId,query,{signal:controller.signal}).then(next=>{if(current)setEnvelope(next);}).catch(error=>{
      if(!current||error?.name==="AbortError") return;
      setEnvelope({projection:{state:PROJECTION.ERROR,stale_status:"ERROR"},access:{decision:"VISIBLE",reason_codes:[]},data:null,reason_codes:["DATASOURCE_ERROR"]});
    });
    return()=>{current=false;controller.abort();};
  },[datasource,readId,queryKey,enabled]);
  return envelope;
}
