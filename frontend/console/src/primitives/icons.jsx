import {
  AlertTriangle, Ban, BookOpenCheck, Check, ChevronRight, CirclePause, Eye, FileCheck2,
  FilePenLine, Funnel, History, Inbox, KeyRound, LayoutDashboard, LockKeyhole, Map,
  Network, PanelsTopLeft, Radar, Route, Scale, ScanSearch, Server, ShieldCheck,
  Target, UsersRound, Waypoints, XCircle
} from "lucide-react";

const ICONS = Object.freeze({
  "alert-triangle": AlertTriangle, ban: Ban, "book-open-check": BookOpenCheck, check: Check,
  "chevron-right": ChevronRight, "circle-pause": CirclePause, eye: Eye, "file-check-2": FileCheck2,
  "file-pen-line": FilePenLine, funnel: Funnel, history: History, inbox: Inbox, "key-round": KeyRound,
  "layout-dashboard": LayoutDashboard, "lock-keyhole": LockKeyhole, map: Map, network: Network,
  "panels-top-left": PanelsTopLeft, radar: Radar, route: Route, scale: Scale, "scan-search": ScanSearch,
  server: Server, "shield-check": ShieldCheck, target: Target, "users-round": UsersRound,
  waypoints: Waypoints, "x-circle": XCircle
});

export function Icon({ name, size = 16, label = null }) {
  const Component = ICONS[name] ?? CirclePause;
  return <Component size={size} strokeWidth={1.7} aria-hidden={label ? undefined : "true"} aria-label={label ?? undefined} />;
}
