import { useEffect, useMemo, useState } from "react";
import { Shell } from "../shell/shell.jsx";
import { ROUTES } from "./routes.jsx";
import { useConsoleRead } from "./use-console-read.js";
import { PROJECTION } from "../data/response-types.js";
import { READS } from "../data/surface-reads.js";
import { buildNavigation, firstNavigationId, navigationIds, usableNavigationIdsForPermissions } from "../data/navigation.js";

function requestedView(ids){
  if(typeof globalThis.location?.search!=="string") return null;
  const value=new URLSearchParams(globalThis.location.search).get("view");
  return value&&ids.includes(value)?value:null;
}
function writeView(id,{replace=false}={}){
  if(!globalThis.history||!globalThis.location?.href||!id) return;
  const url=new URL(globalThis.location.href);
  url.searchParams.set("view",id);
  globalThis.history[replace?"replaceState":"pushState"]({},"",url);
}

export function ConsoleApp({datasource,csrfToken}){
  const context=useConsoleRead(datasource,READS.CONTEXT);
  const contextReady=context.projection?.state===PROJECTION.READY;
  const contextPermissions=contextReady&&Array.isArray(context.data?.permissions)?context.data.permissions:null;
  const declaredUsable=datasource.getUsableNavigationIds();
  const usableIds=contextPermissions!==null
    ? usableNavigationIdsForPermissions(contextPermissions)
    : Array.isArray(declaredUsable)?declaredUsable:[];

  const navigation=buildNavigation({adapterReadIds:datasource.getAdapterAvailability(),usableIds});
  const ids=navigationIds(navigation);
  const idsKey=ids.join("|");
  const fallback=firstNavigationId(navigation,null);
  const [route,setRoute]=useState(null);

  useEffect(()=>{
    if(ids.length===0){
      if(route!==null) setRoute(null);
      return;
    }
    if(route!==null&&ids.includes(route)) return;
    const requested=requestedView(ids);
    const next=requested??fallback;
    if(next===null) return;
    setRoute(next);
    if(requested===null) writeView(next,{replace:true});
  },[idsKey,route,fallback]);

  useEffect(()=>{
    const handler=()=>{
      if(ids.length===0){ setRoute(null); return; }
      setRoute(requestedView(ids)??fallback);
    };
    globalThis.addEventListener?.("popstate",handler);
    return()=>globalThis.removeEventListener?.("popstate",handler);
  },[idsKey,fallback]);

  const navigate=(next)=>{ if(!ids.includes(next)||next===route) return; setRoute(next); writeView(next); };
  const View=useMemo(()=>route?ROUTES[route]??null:null,[route]);
  return <Shell datasource={datasource} context={context} csrfToken={csrfToken} current={route} onNavigate={navigate} navigation={navigation}>{View?<View datasource={datasource} permissionKeys={contextPermissions??[]}/>:null}</Shell>;
}
