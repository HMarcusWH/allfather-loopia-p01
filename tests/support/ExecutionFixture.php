<?php
declare(strict_types=1);
namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Clock,FixedClock,Ids,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Ingestion\{FetchRequest,FetchResponse,FetchTransport,FixedHostResolver};
use Allfather\Loopia\Product\P01\{Capture,ChangeDetection};
use Allfather\Loopia\Product\P04\Distribution;
use Allfather\Loopia\PromptOps\{ProductPromptPack,PromptApprovalStore,PromptSelectionResolver};
use Allfather\Loopia\Provider\{ModelArtifactStore,ModelExecutor,ModelPolicy,ModelResponse,ModelTransport,PreparedModelRequest};
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M05\EvidenceSpine;
use Allfather\Loopia\Substrate\M06\Gateway;
use Allfather\Loopia\Substrate\M08\PolicySets;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;
use Allfather\Loopia\Workflow\{ContextEnvelope,ExecutionAdmission,ExecutionGrantStore,MissionConfiguration,MonitorService,OwnerDecisionRegistry,ReleaseStages,RunArtifacts,RunJournal,SourceCaptureStage};

require_once __DIR__ . '/MonitorModelFixture.php';

/**
 * A NATIVE Monitor mission with the admission machinery, for offline tests only.
 *
 * The model transport here CLAIMS to be live — so every live-transport rule applies — and never
 * touches a network. Owner decisions are written to a private temporary directory the registry is
 * pointed at; that is test authority standing in for a reviewed commit, and nothing else can write
 * one. Nothing here is reachable from a deployed composition root.
 */
final class ExecutionFixture
{
    public const FINGERPRINT = 'f00df00df00df00df00df00df00df00df00df00df00df00df00df00df00df00d';

    public static function create(Database $db, FixedClock $clock, array $pages = ['The planned seminar is November.', 'The planned seminar is December.']): array
    {
        $f = MonitorModelFixture::create($db, $clock, true);
        $scope = $f['scope'];
        $role = Seed::role($db, $clock, 'native-monitor-' . substr(Ids::new(), 0, 8), ['mission.read', 'mission.create', 'mission.activate', 'mission.transition', 'evidence.read', 'tool.invoke', 'company_signal.review', 'distribution.route', 'distribution.configure_targets', 'tenant.administer']);
        Seed::membership($db, $clock, $scope['tenant_id'], $scope['workspace_id'], $scope['operator_id'], $role['role_id']);
        $mission = $f['context_selection']['mission_id'];
        $db->run('UPDATE missions SET mission_type=? WHERE mission_id=?', ['COMPETITION_MONITORING', $mission]);
        $f['context_selection']['purpose'] = $db->scalar('SELECT objective FROM missions WHERE mission_id=?', [$mission]);
        $policies = new PolicySets($db, $clock);
        $ledger = new DecisionLedger($db, $clock);
        $p01 = self::value($policies->seed(PolicySets::P01_COMPETITION_RELEVANCE, $scope['tenant_id'], $scope['workspace_id']));
        $p04 = self::value($policies->seed(PolicySets::P04_DISTRIBUTION_ADMISSION, $scope['tenant_id'], $scope['workspace_id']));
        (new ReleaseOutput($db, $clock, $ledger))->seedVisibilityProfiles($scope['tenant_id']);
        self::value((new Distribution($db, $clock, $ledger))->configureRoleRedactionPolicy($scope + ['target_org_role' => 'SALES', 'redaction_rules' => []]));
        $definition = [
            'mission_id' => $mission, 'monitored_source_id' => $f['monitored_source_id'], 'capture_url' => $f['capture_url'],
            'capture_governance' => $f['capture_governance'], 'model_governance' => ['author' => $f['author_governance'], 'critic' => $f['critic_governance']],
            'release_governance' => ['mission_id' => $mission, 'producing_valkyrie_id' => $f['author_governance']['valkyrie_id'], 'capability_contract_id' => $f['author_governance']['capability_contract_id']],
            'context_selection' => $f['context_selection'], 'credential_id' => $f['credential_id'], 'target_org_role' => 'SALES',
            'p01_policy_id' => $p01['arbiter_policy_set_id'], 'p04_policy_id' => $p04['arbiter_policy_set_id'],
        ];
        $configured = self::value((new MissionConfiguration($db, $clock))->configure($scope, $definition, 'NATIVE'));

        $dir = sys_get_temp_dir() . '/allfather-decisions-' . Ids::new();
        mkdir($dir, 0700, true);
        $decisions = new OwnerDecisionRegistry($dir);
        // Synthetic fixture evidence stands in for the real EvaluationReport resolver. Product
        // approvals still fail closed if a caller omits a validator entirely.
        $evaluationEvidence = static function (array $facts, string $reference): Outcome {
            return preg_match('/^evaluation:(?:fixture|[a-f0-9-]{36})$/D', $reference) === 1
                ? Outcome::ok(['report_digest' => hash('sha256', $reference)])
                : Outcome::refuse('PROMPT_APPROVAL_EVALUATION_NOT_ACCEPTED');
        };
        $approvals = new PromptApprovalStore($db, $clock, $decisions, $evaluationEvidence);
        $grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
        $admission = new ExecutionAdmission($db, $clock, $grants, $approvals, $decisions, self::FINGERPRINT);
        $resolver = PromptSelectionResolver::governed($approvals, $grants);

        $source = new class($pages) implements FetchTransport {
            public int $calls = 0;
            public function __construct(private array $pages) {}
            public function send(FetchRequest $request): FetchResponse { return new FetchResponse(200, 'text/plain; charset=utf-8', null, $this->pages[$this->calls++] ?? 'Unexpected extra capture'); }
        };
        $model = new LiveFlaggedFixtureTransport(MonitorModelFixture::response(...));

        return $f + [
            'mission_id' => $mission, 'definition' => $definition, 'definition_digest' => $configured['definition_digest'],
            'decision_dir' => $dir, 'decisions' => $decisions, 'approvals' => $approvals, 'grants' => $grants,
            'admission' => $admission, 'resolver' => $resolver, 'source' => $source, 'model' => $model,
            'service' => self::service($db, $clock, $f, $source, $model, $resolver, $admission),
        ];
    }

    /** The native composition, built the way MonitorFactory builds it, with a network-free transport. */
    public static function service(Database $db, Clock $clock, array $f, FetchTransport $source, ModelTransport $model, PromptSelectionResolver $resolver, ExecutionAdmission $admission, bool $nativeEnabled = true): MonitorService
    {
        $gateway = new Gateway($db, $clock, new AccessGuard($db, $clock), new Eligibility($db, $clock), new Invocation($db, $clock));
        $evidence = new EvidenceSpine($db, $clock);
        $context = new ContextEnvelope($db, $clock, $f['evidence_store']);

        return new MonitorService($db, $clock, new RunJournal($db, $clock), new MissionConfiguration($db, $clock),
            new SourceCaptureStage($db, $clock, new Capture($db, $clock, $gateway, $evidence), $evidence, $f['evidence_store'], $context, $source, new FixedHostResolver(['sources.example' => ['93.184.216.34']])),
            new ChangeDetection($db, $clock, $evidence), new ModelExecutor($db, $clock, $gateway, $f['credential_store'], $model, $context, new ModelArtifactStore($db, $clock, $f['evidence_store']), $resolver, $admission),
            new RunArtifacts($f['evidence_store']), new ReleaseStages($db, $clock), $context, false, $nativeEnabled, $resolver, $admission);
    }

    /** Write a decision document where the registry looks, and return its ref and SHA-256. */
    public static function decision(array $fx, array $purposes, array $prompts = [], array $grantDigests = [], string $name = ''): array
    {
        $name = $name !== '' ? $name : 'decision-' . substr(Ids::new(), 0, 8) . '.json';
        $bytes = json_encode([
            'schema_version' => '1.0', 'kind' => 'OWNER_EXECUTION_DECISION', 'decision_id' => 'TEST-' . $name,
            'decided_by' => 'Synthetic test owner', 'decided_at' => '2026-01-01T00:00:00Z',
            'canonical_authority_ref' => 'test-only authority standing in for a reviewed commit',
            'tenant_id' => $fx['scope']['tenant_id'], 'workspace_id' => $fx['scope']['workspace_id'], 'purposes' => $purposes,
            'authorizes' => ['prompt_approvals' => $prompts, 'execution_grants' => $grantDigests],
            'disclosure_scope' => 'Synthetic fixture content only.', 'retention_scope' => 'Test database only.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($fx['decision_dir'] . '/' . $name, $bytes);

        return ['ref' => 'docs/decisions/' . $name, 'sha256' => hash('sha256', $bytes)];
    }

    /** @return list<array{purpose:string,role:string,pack_version:string,prompt_version_id:string,binding_id:string,prompt_sha256:string,schema_sha256:string,model_policy_digest:string}> */
    public static function promptIdentities(string $purpose, array $roles = ProductPromptPack::ROLES): array
    {
        $out = [];
        foreach ($roles as $role) {
            $p = ProductPromptPack::resolve($role)->value();
            $out[] = [
                'purpose' => $purpose, 'role' => $role, 'pack_version' => $p['pack_version'],
                'prompt_version_id' => $p['prompt_version_id'], 'binding_id' => $p['binding_id'],
                'prompt_sha256' => $p['prompt_sha256'], 'schema_sha256' => $p['schema_sha256'],
                'model_policy_digest' => ModelPolicy::digest(),
            ];
        }

        return $out;
    }

    /** Approve every product prompt for a purpose under one decision. @return list<string> sorted approval ids */
    public static function approveAll(array $fx, string $purpose, ?string $evaluationRef = null, array $roles = ProductPromptPack::ROLES): array
    {
        $decision = self::decision($fx, [$purpose], self::promptIdentities($purpose, $roles));
        $ids = [];
        foreach ($roles as $role) {
            $ids[] = self::value($fx['approvals']->approve($fx['scope'], $role, $purpose, $decision['ref'], $decision['sha256'], $purpose === 'MANUAL_MONITOR' ? ($evaluationRef ?? 'evaluation:fixture') : null))['prompt_approval_id'];
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    public static function manualDefinition(Database $db, FixedClock $clock, array $fx, array $approvalIds, array $overrides = []): array
    {
        $from = Clock::parse($clock->now());

        return array_replace([
            'purpose' => 'MANUAL_MONITOR', 'mission_id' => $fx['mission_id'], 'definition_digest' => $fx['definition_digest'],
            'evaluation_manifest_sha256' => null, 'principal_operator_id' => $fx['scope']['operator_id'],
            'prompt_approval_ids' => $approvalIds, 'model_policy_id' => ModelPolicy::ID, 'model_policy_digest' => ModelPolicy::digest(),
            'credential_id' => $fx['credential_id'],
            'credential_digest' => $db->scalar('SELECT credential_digest FROM provider_credentials WHERE provider_credential_id=?', [$fx['credential_id']]),
            'source_scope' => ['monitored_source_id' => $fx['monitored_source_id'], 'capture_url' => $fx['capture_url']],
            'target_org_role' => 'SALES', 'limits' => ['max_model_attempts' => 4, 'max_source_captures' => 2],
            'valid_from' => Clock::format($from->modify('-1 minute')), 'valid_until' => Clock::format($from->modify('+7 days')),
            'disclosure_scope' => 'Synthetic source pages and a synthetic owner profile.', 'retention_scope' => 'Test database only.',
            'application_fingerprint' => self::FINGERPRINT,
        ], $overrides);
    }

    /** Propose a grant, write the decision that names its digest, admit it. */
    public static function admitted(array $fx, array $definition): array
    {
        $proposed = self::value($fx['grants']->propose($fx['scope'], $definition));
        $decision = self::decision($fx, [$definition['purpose']], [], [$proposed['grant_digest']]);
        self::value($fx['grants']->admit($fx['scope'], $proposed['execution_grant_id'], $decision['ref'], $decision['sha256']));

        return $proposed + ['decision' => $decision];
    }

    public static function value(Outcome $outcome): mixed
    {
        if (!$outcome->isOk()) {
            throw new \RuntimeException('execution fixture refused: ' . $outcome->reasonCode());
        }

        return $outcome->value();
    }
}

/** Claims to be live so every live-transport rule applies; never opens a socket. */
final class LiveFlaggedFixtureTransport implements ModelTransport
{
    public int $calls = 0;
    /** @var null|\Closure(PreparedModelRequest):void */
    public ?\Closure $during = null;

    public function __construct(private readonly \Closure $response) {}

    public function isLive(): bool
    {
        return true;
    }

    public function send(PreparedModelRequest $request, string $credential): ModelResponse
    {
        // The same two refusals the real transport makes before any network attempt.
        if (($request->provenance()['activation_status'] ?? '') !== 'ACTIVE') {
            return new ModelResponse(0, '', null, 'MODEL_PROMPT_INACTIVE');
        }
        ++$this->calls;
        if ($this->during !== null) {
            ($this->during)($request);
        }

        return ($this->response)($request);
    }
}
