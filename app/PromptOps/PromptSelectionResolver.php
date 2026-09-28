<?php
declare(strict_types=1);
namespace Allfather\Loopia\PromptOps;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Provider\ModelPolicy;
use Allfather\Loopia\Workflow\ExecutionGrantStore;

/**
 * The one trusted answer to "which prompt runs here, and may it?".
 *
 * Content and permission are different things. `ProductPromptPack` holds immutable content, always
 * in its authored INACTIVE/DRAFT state. A prompt becomes executable only as a SELECTION: the same
 * bytes, marked ACTIVE and bound to the approval and admitted grant that permit it. Nothing else can
 * produce an ACTIVE prompt — not editing the bundle, not a caller building the array, not a caller
 * recomputing a digest — because `verify()` re-derives the selection from stored approvals and
 * grants and compares it whole.
 *
 * Every place that used to call `ProductPromptPack::resolve()` for execution (MonitorService,
 * PreparedModelRequest, ModelExecutor) now asks this resolver, so there is one derivation to review.
 * The inert resolver has no database and can only return content: it is what fixtures and every
 * unadmitted deployment use, and under it a live transport refuses every request.
 */
final class PromptSelectionResolver
{
    private function __construct(
        private readonly ?PromptApprovalStore $approvals,
        private readonly ?ExecutionGrantStore $grants,
    ) {}

    public static function inert(): self
    {
        return new self(null, null);
    }

    public static function governed(PromptApprovalStore $approvals, ExecutionGrantStore $grants): self
    {
        return new self($approvals, $grants);
    }

    /** Immutable content only. Never an execution permission. */
    public function content(string $role): Outcome
    {
        return ProductPromptPack::resolve($role);
    }

    /**
     * The prompt as it may execute under this admitted grant, or its inert content when there is
     * no grant. Admission — status, window, budget, principal — is ExecutionAdmission's question;
     * this answers only whether the grant names a current approval for exactly this content.
     */
    public function select(string $role, ?array $grant): Outcome
    {
        $content = $this->content($role);
        if (!$content->isOk() || $grant === null) {
            return $content;
        }
        if ($this->approvals === null || $this->grants === null) {
            return Outcome::refuse('PROMPT_SELECTION_NOT_GOVERNED');
        }
        $prompt = $content->value();
        foreach ($grant['definition']['prompt_approval_ids'] ?? [] as $approvalId) {
            $row = $this->approvals->find($grant['tenant_id'], $grant['workspace_id'], $approvalId);
            if ($row === null || $row['role'] !== $role) {
                continue;
            }
            $current = $this->approvals->current($grant['tenant_id'], $grant['workspace_id'], $approvalId);
            if (!$current->isOk()) {
                return $current;
            }
            if ($row['purpose'] !== $grant['purpose'] || $row['prompt_sha256'] !== $prompt['prompt_sha256'] || $row['schema_sha256'] !== $prompt['schema_sha256']) {
                return Outcome::refuse('PROMPT_APPROVAL_CONTENT_CHANGED');
            }

            return Outcome::ok(array_merge($prompt, [
                'approval_status' => 'APPROVED', 'activation_status' => 'ACTIVE',
                'execution_authorized' => true, 'live_llm_calls_enabled' => true,
                'prompt_approval_id' => $approvalId, 'execution_grant_id' => $grant['execution_grant_id'],
                'grant_digest' => $grant['grant_digest'], 'execution_purpose' => $grant['purpose'],
                'model_policy_id' => ModelPolicy::ID, 'model_policy_digest' => ModelPolicy::digest(),
                // Unchanged by selection: running a prompt is never an external action or customer output.
                'external_action_authorized' => false, 'customer_output_authorized' => false,
            ]));
        }

        return Outcome::refuse('PROMPT_NOT_APPROVED_FOR_GRANT');
    }

    /**
     * Whether a prompt array handed back by a caller is exactly what this resolver would derive.
     * A self-edited, self-promoted or re-hashed prompt fails, whoever built it.
     */
    public function verify(array $prompt): bool
    {
        $role = $prompt['role'] ?? null;
        if (!is_string($role)) {
            return false;
        }
        $grantId = $prompt['execution_grant_id'] ?? null;
        if ($grantId === null) {
            $expected = $this->content($role);
        } elseif (!is_string($grantId) || $this->grants === null || ($grant = $this->grants->findById($grantId)) === null) {
            return false;
        } else {
            $expected = $this->select($role, $grant);
        }

        return $expected->isOk() && hash_equals(Digest::canonicalDigest($expected->value()), Digest::canonicalDigest($prompt));
    }
}
