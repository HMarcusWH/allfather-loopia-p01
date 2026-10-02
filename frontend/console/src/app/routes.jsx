import { OverviewView } from "../views/operator/overview.jsx";
import { NeedsYouView } from "../views/operator/needs-you.jsx";
import { CompetitionMonitorView } from "../views/p01/competition-monitor.jsx";
import { EntityContextView } from "../views/p02/entity-context.jsx";
import { ProspectQueueView } from "../views/p03/prospect-queue.jsx";
import { DistributionView } from "../views/p04/distribution.jsx";
import { MarketingDraftsView } from "../views/p05/marketing-drafts.jsx";
import { SourceOperationsView } from "../views/source-ops/source-operations.jsx";
import { IngestionView } from "../views/source-ops/ingestion.jsx";
import { EvidenceView } from "../views/source-ops/evidence.jsx";
import { MissionsView } from "../views/governance/missions.jsx";
import { ReviewView } from "../views/governance/review.jsx";
import { ArbiterView } from "../views/governance/arbiter.jsx";
import { LedgerView } from "../views/governance/ledger.jsx";
import { ReleaseVisibilityView } from "../views/governance/release-visibility.jsx";
import { AccessView } from "../views/runtime/access.jsx";
import { ContractsView } from "../views/runtime/contracts.jsx";
import { ToolGatewayView } from "../views/runtime/tool-gateway.jsx";
import { HostRuntimeView } from "../views/runtime/host-runtime.jsx";
import { ConsoleStateView } from "../views/runtime/console-state.jsx";
import { AuditReplayView } from "../views/assurance/audit-replay.jsx";

export const ROUTES = Object.freeze({
  "overview": OverviewView,
  "needs-you": NeedsYouView,
  "p01": CompetitionMonitorView,
  "p02": EntityContextView,
  "p03": ProspectQueueView,
  "p04": DistributionView,
  "p05": MarketingDraftsView,
  "source-ops": SourceOperationsView,
  "ingestion": IngestionView,
  "evidence": EvidenceView,
  "missions": MissionsView,
  "review": ReviewView,
  "arbiter": ArbiterView,
  "ledger": LedgerView,
  "release": ReleaseVisibilityView,
  "access": AccessView,
  "contracts": ContractsView,
  "gateway": ToolGatewayView,
  "host-runtime": HostRuntimeView,
  "console-state": ConsoleStateView,
  "audit-replay": AuditReplayView,
});
