<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Ingestion\{FetchTransport,HostResolver,NoNetworkTransport,FixedHostResolver};
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
        return self::build($db,$clock,$store,new CredentialStore($db,$clock,$box),new NoNetworkTransport(),new FixedHostResolver([]),new OpenAIResponsesTransport(false),false);
    }
    public static function forFixture(Database $db,Clock $clock,EvidenceStore $store,CredentialStore $credentials,FetchTransport $source,HostResolver $resolver,ModelTransport $model): MonitorService
    {
        if ($model->isLive()) { throw new \InvalidArgumentException('Fixture runtime cannot use a live model transport'); }
        return self::build($db,$clock,$store,$credentials,$source,$resolver,$model,true);
    }
    private static function build(Database $db,Clock $clock,EvidenceStore $store,CredentialStore $credentials,FetchTransport $source,HostResolver $resolver,ModelTransport $model,bool $fixture): MonitorService
    {
        $gateway=new Gateway($db,$clock,new AccessGuard($db,$clock),new Eligibility($db,$clock),new Invocation($db,$clock));
        $evidence=new EvidenceSpine($db,$clock); $context=new ContextEnvelope($db,$clock,$store);
        return new MonitorService($db,$clock,new RunJournal($db,$clock),new MissionConfiguration($db,$clock),
            new SourceCaptureStage($db,$clock,new Capture($db,$clock,$gateway,$evidence),$evidence,$store,$context,$source,$resolver),
            new ChangeDetection($db,$clock,$evidence),new ModelExecutor($db,$clock,$gateway,$credentials,$model,$context),
            new RunArtifacts($store),new ReleaseStages($db,$clock),$context,$fixture,false);
    }
}
