import { useEffect, useState } from "react";
import logoLockup from "../assets/logo-lockup.svg";
import { Navigation } from "./navigation.jsx";
import { CommandPalette } from "./command-palette.jsx";
import { ShellUtilities } from "./utilities.jsx";
import { Icon } from "../primitives/icons.jsx";
import { Panel, StatusBadge } from "../primitives/index.jsx";
import { CONTEXT_STATE, classifyContextEnvelope } from "../data/context-state.js";

function ContextBoundary({context,state}){
  const reasons=Array.isArray(context?.reason_codes)?context.reason_codes:[];
  if(state===CONTEXT_STATE.LOADING){
    return <Panel title="Resolving workspace context" eyebrow="M12" icon="panels-top-left" meta="AUTHENTICATED CONTEXT">
      <div className="read-state"><StatusBadge tone="cyan">Loading</StatusBadge><span>Resolving the governed workspace and permission context.</span></div>
    </Panel>;
  }
  if(state===CONTEXT_STATE.AUTHENTICATION_REQUIRED){
    return <Panel title="Session required" eyebrow="M12" icon="key-round" meta="AUTHENTICATION REQUIRED">
      <div className="read-state"><StatusBadge tone="red">Sign in required</StatusBadge><span>The authenticated console session is unavailable or expired.</span>{reasons.length?<code>{reasons.join(" · ")}</code>:null}<a href="/login">Sign in again</a></div>
    </Panel>;
  }
  if(state===CONTEXT_STATE.NO_ACTIVE_WORKSPACE){
    return <Panel title="Select a workspace" eyebrow="M12" icon="panels-top-left" meta="WORKSPACE REQUIRED">
      <div className="read-state"><StatusBadge tone="amber">Workspace required</StatusBadge><span>Choose an active governed workspace before opening browser reads.</span>{reasons.length?<code>{reasons.join(" · ")}</code>:null}<a href="/console/workspaces">Open workspace selector</a></div>
    </Panel>;
  }
  if(state===CONTEXT_STATE.DENIED){
    return <Panel title="Context denied" eyebrow="M12" icon="shield-check" meta="OWNER DENIAL">
      <div className="read-state"><StatusBadge tone="red">Denied</StatusBadge><span>The current tenant, membership, role, or operator lifecycle does not permit console context.</span>{reasons.length?<code>{reasons.join(" · ")}</code>:null}</div>
    </Panel>;
  }
  return <Panel title="Context unavailable" eyebrow="M12" icon="panels-top-left" meta="FAIL CLOSED">
    <div className="read-state"><StatusBadge tone="amber">Unavailable</StatusBadge><span>The console could not resolve governed context. No product route has been selected.</span>{reasons.length?<code>{reasons.join(" · ")}</code>:null}<button className="palette-button" type="button" onClick={()=>globalThis.location?.reload?.()}>Retry context</button></div>
  </Panel>;
}

function NoUsableSurfaces(){
  return <Panel title="No usable browser surfaces" eyebrow="M12" icon="shield-check" meta="USABILITY ONLY">
    <div className="read-state"><StatusBadge tone="amber">No routed reads</StatusBadge><span>The current context is valid, but none of its permissions map to a PR6 browser-read route. Backend owner gates remain authoritative.</span></div>
  </Panel>;
}

export function Shell({datasource,current,onNavigate,navigation,context,csrfToken,children}){
  const [palette,setPalette]=useState(false);
  const contextState=classifyContextEnvelope(context);
  const contextReady=contextState===CONTEXT_STATE.READY;
  const workspace=contextReady?context.data?.workspace:null;
  const hasNavigation=Array.isArray(navigation)&&navigation.length>0;
  const contextLine=workspace
    ? `${workspace.display_name} · ${workspace.environment_class}`
    : contextState===CONTEXT_STATE.NO_ACTIVE_WORKSPACE
      ? "No active workspace"
      : contextState===CONTEXT_STATE.AUTHENTICATION_REQUIRED
        ? "Authentication required"
        : contextState===CONTEXT_STATE.DENIED
          ? "Context denied"
          : contextState===CONTEXT_STATE.LOADING
            ? "Resolving governed context"
            : "Governed browser reads · owner gates server-side";

  useEffect(()=>{
    const h=e=>{
      if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==="k"){
        e.preventDefault();
        setPalette(v=>!v);
      }
    };
    document.addEventListener("keydown",h);
    return()=>document.removeEventListener("keydown",h);
  },[]);

  const mainContent=contextReady
    ? (hasNavigation?children:<NoUsableSurfaces/> )
    : <ContextBoundary context={context} state={contextState}/>;

  return <div className="console-shell">
    <aside className="rail">
      <div className="brand"><img src={logoLockup} alt="Allfather BI"/></div>
      <Navigation current={current} onNavigate={onNavigate} navigation={navigation}/>
      {/* OUTSIDE the navigation, and outside the context boundary above it. These four are the
          things an operator needs precisely when the console cannot resolve context — a denied
          session still has to be able to choose a workspace, read the support page, or sign out.
          Rendering them inside `Navigation` would make them disappear in exactly that case. */}
      <ShellUtilities csrfToken={csrfToken}/>
      <div className="rail-foot"><span className="rail-boundary">Internal presentation only</span><span>No control grants authority.</span></div>
    </aside>
    <div className="shell-main">
      <header className="topbar">
        <div><span className="topbar-title">Operator Console</span><span className="topbar-sub">{contextLine}</span></div>
        <button className="palette-button" type="button" onClick={()=>setPalette(true)} disabled={!hasNavigation}><Icon name="panels-top-left"/>Navigate <kbd>⌘K</kbd></button>
      </header>
      <main className="content">{mainContent}</main>
    </div>
    <CommandPalette open={palette&&hasNavigation} onClose={()=>setPalette(false)} onNavigate={onNavigate} navigation={navigation}/>
  </div>;
}
