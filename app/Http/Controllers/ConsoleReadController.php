<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Http\ConsoleReadEnvelope;
use Allfather\Loopia\Http\ReadAdapters\ConsoleContextAdapter;
use Allfather\Loopia\Http\ReadAdapters\DecisionLedgerAdapter;
use Allfather\Loopia\Http\ReadAdapters\MonitorReadAdapter;
use Allfather\Loopia\Http\ReadAdapters\P02SnapshotDetailAdapter;
use Allfather\Loopia\Http\ReadAdapters\P03ProspectDetailAdapter;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Product\P01\Surfacing;
use Allfather\Loopia\Product\P01\WatchlistRead;
use Allfather\Loopia\Product\P02\OwnCompanyDraftRead;
use Allfather\Loopia\Product\P02\RelationshipRelease;
use Allfather\Loopia\Product\P02\TwinRelease;
use Allfather\Loopia\Product\P03\Release as P03Release;
use Allfather\Loopia\Product\P03\CustomerProfileRead;
use Allfather\Loopia\Product\P04\Release as P04Release;
use Allfather\Loopia\Product\P05\Release as P05Release;

/** Explicit browser GET adapters. Scope comes only from the authenticated session. */
final class ConsoleReadController
{
    public function __construct(
        private readonly ConsoleContextAdapter $context,
        private readonly DecisionLedgerAdapter $decisions,
        private readonly Surfacing $p01,
        private readonly TwinRelease $p02Twins,
        private readonly RelationshipRelease $p02Relationships,
        private readonly P03Release $p03,
        private readonly P04Release $p04,
        private readonly P05Release $p05,
        private readonly ?MonitorReadAdapter $monitor = null,
        private readonly ?OwnCompanyDraftRead $ownCompany = null,
        private readonly ?WatchlistRead $watchlist = null,
        private readonly ?CustomerProfileRead $customerProfile = null,
    ) {
    }

    public function context(Request $request, ?array $session): Response
    {
        return $this->safe(fn (): Response => ConsoleReadEnvelope::adapter('m12.consoleContext', $this->context->read($session)), 'm12.consoleContext');
    }

    public function p01Battlecards(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($session): Response {
            $scope = $this->scope($session, 'p01.releasedBattlecards');
            if ($scope instanceof Response) return $scope;
            return ConsoleReadEnvelope::owner('p01.releasedBattlecards', $this->p01->listVisibleReleasedBriefings($scope + ['briefing_kind' => 'BATTLECARD_BRIEF']), 'briefings');
        }, 'p01.releasedBattlecards');
    }

    /** Candidate state and rights readiness, never released intelligence or a capture command. */
    public function p01SourceWatch(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($session): Response {
            $source='p01.sourceWatch';
            $scope=$this->scope($session,$source);
            if ($scope instanceof Response) return $scope;
            return $this->watchlist===null?ConsoleReadEnvelope::unavailable($source)
                :ConsoleReadEnvelope::owner($source,$this->watchlist->listCandidates($scope),'rows');
        },'p01.sourceWatch');
    }

    public function p02Entities(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($request, $session): Response {
            $scope = $this->scope($session, 'p02.entitySnapshots');
            if ($scope instanceof Response) return $scope;
            return ConsoleReadEnvelope::owner('p02.entitySnapshots', $this->p02Twins->listVisibleSnapshots($scope + ['visibility_scope' => $request->query['visibility_scope'] ?? '']), 'rows');
        }, 'p02.entitySnapshots');
    }

    /** A separately authorized own-company INTERNAL_DRAFT read, never a released snapshot. */
    public function p02OwnCompanyDrafts(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($session): Response {
            $source = 'p02.ownCompanyDrafts';
            $scope = $this->scope($session, $source);
            if ($scope instanceof Response) return $scope;
            return $this->ownCompany === null
                ? ConsoleReadEnvelope::unavailable($source)
                : ConsoleReadEnvelope::owner($source, $this->ownCompany->listInternalDrafts($scope), 'rows');
        }, 'p02.ownCompanyDrafts');
    }

    public function p02Relationships(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($request, $session): Response {
            $scope = $this->scope($session, 'p02.relationshipSnapshots');
            if ($scope instanceof Response) return $scope;
            return ConsoleReadEnvelope::owner('p02.relationshipSnapshots', $this->p02Relationships->listVisibleSnapshots($scope + ['visibility_scope' => $request->query['visibility_scope'] ?? '']), 'rows');
        }, 'p02.relationshipSnapshots');
    }

    /** Existing customer-supplied P03 declarations, not inferred leads or released outputs. */
    public function p03DeclaredIcp(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($session): Response {
            $source='p03.declaredIcp';
            $scope=$this->scope($session,$source);
            if ($scope instanceof Response) return $scope;
            return $this->customerProfile===null?ConsoleReadEnvelope::unavailable($source)
                :ConsoleReadEnvelope::owner($source,$this->customerProfile->listDeclaredIcp($scope),'rows');
        },'p03.declaredIcp');
    }

    public function p03CustomerOffers(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($session): Response {
            $source='p03.customerOffers';
            $scope=$this->scope($session,$source);
            if ($scope instanceof Response) return $scope;
            return $this->customerProfile===null?ConsoleReadEnvelope::unavailable($source)
                :ConsoleReadEnvelope::owner($source,$this->customerProfile->listCustomerOffers($scope),'rows');
        },'p03.customerOffers');
    }

    public function p03PriorityQueue(Request $request, ?array $session): Response
    {
        return $this->p03Read($session, 'PRIORITY_OUTREACH_QUEUE_ITEM', 'p03.priorityQueue');
    }

    public function p03Monitored(Request $request, ?array $session): Response
    {
        return $this->p03Read($session, 'MONITORED_PROSPECT', 'p03.monitoredProspects');
    }

    public function p04Distributions(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($request, $session): Response {
            $scope = $this->scope($session, 'p04.roleDistributions');
            if ($scope instanceof Response) return $scope;
            return ConsoleReadEnvelope::owner('p04.roleDistributions', $this->p04->listVisibleRoleDistributions($scope + ['target_org_role' => $request->query['target_org_role'] ?? '']), 'outputs');
        }, 'p04.roleDistributions');
    }

    public function p05Drafts(Request $request, ?array $session): Response
    {
        return $this->safe(function () use ($session): Response {
            $scope = $this->scope($session, 'p05.internalMarketingDrafts');
            if ($scope instanceof Response) return $scope;
            return ConsoleReadEnvelope::owner('p05.internalMarketingDrafts', $this->p05->listVisibleMarketingAssets($scope), 'outputs');
        }, 'p05.internalMarketingDrafts');
    }

    public function decisions(Request $request, ?array $session): Response
    {
        return $this->safe(fn (): Response => ConsoleReadEnvelope::adapter('m10.decisions', $this->decisions->read($session)), 'm10.decisions');
    }

    /**
     * P09 detail reads. The query value is a SELECTOR only: the owner re-checks the object against the
     * session's tenant and workspace, and an id from elsewhere answers exactly like an unknown one.
     */
    public function p02EntityDetail(Request $request, ?array $session): Response
    {
        return $this->safe(fn (): Response => ConsoleReadEnvelope::adapter('p02.entityContextDetail', (new P02SnapshotDetailAdapter($this->p02Twins))->read($session, (string) ($request->query['snapshot'] ?? ''))), 'p02.entityContextDetail');
    }

    public function p03ProspectDetail(Request $request, ?array $session): Response
    {
        return $this->safe(fn (): Response => ConsoleReadEnvelope::adapter('p03.prospectDetail', (new P03ProspectDetailAdapter($this->p03))->read($session, (string) ($request->query['output'] ?? ''))), 'p03.prospectDetail');
    }

    /** Monitor metadata for the React console; inspection and every mutation stay in the SSR workbench. */
    public function monitorRuns(Request $request, ?array $session): Response
    {
        return $this->safe(fn (): Response => $this->monitor === null
            ? ConsoleReadEnvelope::unavailable('monitor.runs')
            : ConsoleReadEnvelope::adapter('monitor.runs', $this->monitor->runs($session)), 'monitor.runs');
    }

    public function monitorRun(Request $request, ?array $session): Response
    {
        return $this->safe(fn (): Response => $this->monitor === null
            ? ConsoleReadEnvelope::unavailable('monitor.run')
            : ConsoleReadEnvelope::adapter('monitor.run', $this->monitor->run($session, (string) ($request->query['run'] ?? ''))), 'monitor.run');
    }

    private function p03Read(?array $session, string $kind, string $source): Response
    {
        return $this->safe(function () use ($session, $kind, $source): Response {
            $scope = $this->scope($session, $source);
            if ($scope instanceof Response) return $scope;
            return ConsoleReadEnvelope::owner($source, $this->p03->listVisibleProspectOutputs($scope + ['output_kind' => $kind]), 'rows');
        }, $source);
    }

    /** @return array<string, string>|Response */
    private function scope(?array $session, string $source): array|Response
    {
        if ($session === null || ($session['active_workspace_id'] ?? null) === null) return ConsoleReadEnvelope::noActiveWorkspace($source);
        return [
            'tenant_id' => (string) $session['tenant_id'],
            'workspace_id' => (string) $session['active_workspace_id'],
            'actor_type' => 'OPERATOR',
            'actor_id' => (string) $session['operator_id'],
        ];
    }

    private function safe(callable $read, string $source): Response
    {
        try { return $read(); } catch (\Throwable) { return ConsoleReadEnvelope::unavailable($source); }
    }
}
