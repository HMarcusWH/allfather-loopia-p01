import { GovernedSurface } from "../app/governed-surface.jsx";
export function AuthorityInspector({ datasource }) {
  return <GovernedSurface datasource={datasource} surfaceId="authority-inspector" title="Authority Inspector" subtitle="Normalized Phase-1 presentation source; no product read is wired." owner="owner-composed" plane="GOVERNANCE" icon="shield-check" boundary={{ title: "NEEDS_READ_MODEL — not routed", body: "This component cannot become a cross-scope existence oracle. It remains dormant until an owner-safe data contract exists.", tone: "amber" }} />;
}
