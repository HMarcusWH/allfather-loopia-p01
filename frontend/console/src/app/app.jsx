import { useEffect, useMemo, useState } from "react";
import { Shell } from "../shell/shell.jsx";
import { ROUTES } from "./routes.jsx";
import { buildNavigation, firstNavigationId, navigationIds } from "../data/navigation.js";

function requestedView(ids){
  if(typeof globalThis.location?.search!=="string") return null;
  const value=new URLSearchParams(globalThis.location.search).get("view");
  return value&&ids.includes(value)?value:null;
}
function writeView(id,{replace=false}={}){
  if(!globalThis.history||!globalThis.location?.href) return;
  const url=new URL(globalThis.location.href);
  url.searchParams.set("view",id);
  globalThis.history[replace?"replaceState":"pushState"]({},"",url);
}

export function ConsoleApp({datasource}){
  const navigation=useMemo(()=>buildNavigation({adapterReadIds:datasource.getAdapterAvailability(),usableIds:datasource.getUsableNavigationIds()}),[datasource]);
  const ids=useMemo(()=>navigationIds(navigation),[navigation]);
  const fallback=useMemo(()=>firstNavigationId(navigation,"overview"),[navigation]);
  const [route,setRoute]=useState(()=>requestedView(ids)??fallback);

  useEffect(()=>{ if(ids.length&&!ids.includes(route)){ setRoute(fallback); writeView(fallback,{replace:true}); } },[ids,route,fallback]);
  useEffect(()=>{ const handler=()=>{ const next=requestedView(ids); if(next) setRoute(next); }; globalThis.addEventListener?.("popstate",handler); return()=>globalThis.removeEventListener?.("popstate",handler); },[ids]);

  const navigate=(next)=>{ if(!ids.includes(next)) return; setRoute(next); writeView(next); };
  const View=useMemo(()=>ROUTES[route]??ROUTES[fallback]??ROUTES.overview,[route,fallback]);
  return <Shell datasource={datasource} current={route} onNavigate={navigate} navigation={navigation}><View datasource={datasource}/></Shell>;
}
