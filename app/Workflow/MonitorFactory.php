<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Evaluation\EvaluationReport;
use Allfather\Loopia\Ingestion\{CurlHttpsTransport,DnsHostResolver,FetchTransport,HostResolver,NoNetworkTransport,FixedHostResolver};
use Allfather\Loopia\PromptOps\{PromptApprovalStore,PromptSelectionResolver};
use Allfather\Loopia\Provider\{CredentialStore,ModelExecutor,ModelTransport,OpenAIResponsesTransport,SecretBox};
use Allfather\Loopia\Product\P01\{Capture,ChangeDetection};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M06\Gateway;

/** Deployment composition defaults to inert. Fixture injection is explicit and TEST-scoped. */
final class MonitorFactory
{
    public static function inactive(Database $db,Clock $clock,EvidenceStore $store,SecretBox $box): MonitorService
    {
        return self::build($db,$clock,$store,new CredentialStore($db,$clock,$box),new NoNetworkTransport(),new FixedHostResolver([]),self::modelTransport(false),false);
    }

    /**
     * The deployed Monitor composition for one workspace.
     *
     * Live source read and the provider wire exist here only when this workspace holds an ADMITTED
     * MANUAL_MONITOR grant; otherwise this is exactly the inactive composition — no network leaf is
     * constructed at all. Even when admitted, every capture, model stage and release re-checks the
     * run's own grant through ExecutionAdmission. An unadmitted workspace still inspects runs,
     * previews requests and receives explicit refusals.
     */
    public static function forWorkspace(Database $db,Clock $clock,EvidenceStore $store,SecretBox $box,OwnerDecisionRegistry $decisions,string|\Closure $applicationFingerprint,string $tenantId,string $workspaceId): MonitorService
    {
        $approvals=new PromptApprovalStore($db,$clock,$decisions,EvaluationReport::approvalEvidence($db,dirname(__DIR__,2)));
        $grants=new ExecutionGrantStore($db,$clock,$approvals,$decisions);
        $admission=new ExecutionAdmission($db,$clock,$grants,$approvals,$decisions,$applicationFingerprint);
        $admitted=$grants->admittedIn($tenantId,$workspaceId,'MANUAL_MONITOR')!==[];
        [$source,$resolver]=self::liveSource($admitted);
        return self::build($db,$clock,$store,new CredentialStore($db,$clock,$box),$source,$resolver,self::modelTransport($admitted),false,$admitted,PromptSelectionResolver::governed($approvals,$grants),$admission);
    }

    /** THE one place live read is constructed; `$admitted` is the workspace admission computed above. */
    private static function liveSource(bool $admitted): array
    {
        return $admitted ? [new CurlHttpsTransport(),new DnsHostResolver()] : [new NoNetworkTransport(),new FixedHostResolver([])];
    }

    /**
     * The provider transport for one admitted grant — activated only when that grant exists in this
     * scope, serves this purpose and is ADMITTED now. Otherwise it is the inactive transport, which
     * refuses before any cURL handle exists. Activation of the wire and admission of the operation
     * therefore cannot disagree at construction; every call is still re-admitted by the executor.
     */
    public static function admittedModelTransport(ExecutionGrantStore $grants,string $tenantId,string $workspaceId,string $grantId,string $purpose): OpenAIResponsesTransport
    {
        $grant=$grants->find($tenantId,$workspaceId,$grantId);
        $admitted=$grant!==null && $grant['purpose']===$purpose && $grants->status($grantId)==='ADMITTED';
        return self::modelTransport($admitted);
    }

    /** THE one place the provider transport is constructed. `tests/foundation/no_external_action_surface.php` pins it. */
    private static function modelTransport(bool $admitted): OpenAIResponsesTransport
    {
        return new OpenAIResponsesTransport($admitted);
    }
    public static function forFixture(Database $db,Clock $clock,EvidenceStore $store,CredentialStore $credentials,FetchTransport $source,HostResolver $resolver,ModelTransport $model): MonitorService
    {
        if ($model->isLive()) { throw new \InvalidArgumentException('Fixture runtime cannot use a live model transport'); }
        return self::build($db,$clock,$store,$credentials,$source,$resolver,$model,true);
    }
    private static function build(Database $db,Clock $clock,EvidenceStore $store,CredentialStore $credentials,FetchTransport $source,HostResolver $resolver,ModelTransport $model,bool $fixture,bool $native=false,?PromptSelectionResolver $prompts=null,?ExecutionAdmission $admission=null): MonitorService
    {
        $gateway=new Gateway($db,$clock,new AccessGuard($db,$clock),new Eligibility($db,$clock),new Invocation($db,$clock));
        $evidence=new EvidenceSpine($db,$clock); $context=new ContextEnvelope($db,$clock,$store);
        return new MonitorService($db,$clock,new RunJournal($db,$clock),new MissionConfiguration($db,$clock),
            new SourceCaptureStage($db,$clock,new Capture($db,$clock,$gateway,$evidence),$evidence,$store,$context,$source,$resolver),
            new ChangeDetection($db,$clock,$evidence),new ModelExecutor($db,$clock,$gateway,$credentials,$model,$context,$prompts,$admission),
            new RunArtifacts($store),new ReleaseStages($db,$clock),$context,$fixture,$native,$prompts,$admission);
    }
}
