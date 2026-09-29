<?php
declare(strict_types=1);
namespace Allfather\Loopia\Evaluation;

use Allfather\Loopia\Core\Clock;
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\PromptOps\{PromptApprovalStore,PromptSelectionResolver};
use Allfather\Loopia\Provider\{CredentialStore,ModelArtifactStore,ModelExecutor,ModelTransport};
use Allfather\Loopia\Storage\EvidenceStore;
use Allfather\Loopia\Substrate\M01\Invocation;
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M03\Eligibility;
use Allfather\Loopia\Substrate\M06\Gateway;
use Allfather\Loopia\Workflow\{ExecutionAdmission,ExecutionGrantStore,OwnerDecisionRegistry};

/**
 * The evaluation composition, and what it deliberately leaves out.
 *
 * It wires the product's own pieces — the governed M06/M01 gateway, the model executor, the
 * credential store, the trusted prompt resolver and the admission validator — around the frozen
 * case manifest. It constructs NO source transport, NO host resolver, NO capture stage and NO
 * release service, so an evaluation cannot fetch a page or release intelligence however it is
 * driven. `tests/integration/monitor_evaluation_boundary.php` asserts both halves.
 */
final class EvaluationFactory
{
    /** @return array{runner: MonitorEvaluationRunner, admission: ExecutionAdmission, grants: ExecutionGrantStore, approvals: PromptApprovalStore, resolver: PromptSelectionResolver} */
    public static function create(
        Database $db,
        Clock $clock,
        EvidenceStore $store,
        CredentialStore $credentials,
        ModelTransport $transport,
        OwnerDecisionRegistry $decisions,
        string $applicationFingerprint,
        EvaluationManifest $manifest,
        ?callable $approvalEvidence = null,
    ): array {
        $approvals = new PromptApprovalStore($db, $clock, $decisions, $approvalEvidence);
        $grants = new ExecutionGrantStore($db, $clock, $approvals, $decisions);
        $admission = new ExecutionAdmission($db, $clock, $grants, $approvals, $decisions, $applicationFingerprint);
        $resolver = PromptSelectionResolver::governed($approvals, $grants);
        $gateway = new Gateway($db, $clock, new AccessGuard($db, $clock), new Eligibility($db, $clock), new Invocation($db, $clock));
        $cases = new EvaluationCaseContext($manifest);
        $executor = new ModelExecutor($db, $clock, $gateway, $credentials, $transport, $cases, new ModelArtifactStore($db, $clock, $store), $resolver, $admission);

        return [
            'runner' => new MonitorEvaluationRunner($db, $clock, $grants, $resolver, $executor, $cases, $store, $transport->isLive()),
            'admission' => $admission, 'grants' => $grants, 'approvals' => $approvals, 'resolver' => $resolver,
        ];
    }
}
