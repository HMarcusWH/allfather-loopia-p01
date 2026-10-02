<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Outcome;

/**
 * Reviewed owner decisions, read from files a person committed — never from the database.
 *
 * THE PROBLEM IT SOLVES. An execution grant or a prompt approval is a row, and a row can be inserted
 * by anybody who can reach the database. If the row WERE the authority, whoever wrote it would have
 * granted themselves live model calls. So a row only ever POINTS at authority: a decision document
 * under `docs/decisions/`, added by a reviewed commit, naming the exact grant digests and prompt
 * identities it authorizes. The row stores the document's path and SHA-256; every admission check
 * re-reads the document, and a changed, missing or narrowed document refuses.
 *
 * Nothing in the application writes this directory. `tests/foundation/execution_authority_boundary.php`
 * asserts that, the same way the lock and evidence registry are protected. An empty directory — the
 * state this repository ships in — means nothing can be admitted.
 *
 * A decision document is itself not canonical law. It must name the canonical authority it relies
 * on (`canonical_authority_ref`); where canonical authority does not yet cover an operation, the
 * right move is an upstream decision first, not a local document asserting one.
 */
final class OwnerDecisionRegistry
{
    public const SCHEMA_VERSION = '1.0';
    public const KIND = 'OWNER_EXECUTION_DECISION';
    public const PURPOSES = ['MODEL_EVALUATION', 'MANUAL_MONITOR', 'SCHEDULED_OBSERVATION'];
    private const PROMPT_IDENTITY_KEYS = [
        'purpose', 'role', 'pack_version', 'prompt_version_id', 'binding_id',
        'prompt_sha256', 'schema_sha256', 'model_policy_digest',
    ];
    private const REF_PATTERN = '#^docs/decisions/([A-Za-z0-9][A-Za-z0-9._-]{0,120}\.json)$#D';
    private const MAX_BYTES = 65536;

    public function __construct(private readonly string $directory) {}

    /** The deployed tree's own reviewed directory. */
    public static function forRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/docs/decisions');
    }

    /**
     * Resolve a decision by its repository path and the SHA-256 the caller expects. Both must agree
     * with the bytes on disk now; a decision edited after it was referenced is a different decision.
     */
    public function resolve(string $ref, string $sha256): Outcome
    {
        if (preg_match(self::REF_PATTERN, $ref, $m) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            return Outcome::refuse('OWNER_DECISION_REFERENCE_INVALID');
        }
        $base = realpath($this->directory);
        $path = $base === false ? false : realpath($base . '/' . $m[1]);
        if ($base === false || $path === false || !is_file($path) || dirname($path) !== $base || is_link($base . '/' . $m[1])) {
            return Outcome::refuse('OWNER_DECISION_NOT_FOUND');
        }
        $bytes = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES) {
            return Outcome::refuse('OWNER_DECISION_INVALID');
        }
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            return Outcome::refuse('OWNER_DECISION_CHANGED');
        }
        try {
            $decision = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Outcome::refuse('OWNER_DECISION_INVALID');
        }
        if (!self::wellFormed($decision)) {
            return Outcome::refuse('OWNER_DECISION_INVALID');
        }

        return Outcome::ok($decision + ['_ref' => $ref, '_sha256' => $sha256]);
    }

    /** Whether a resolved decision authorizes this exact grant in this exact scope. */
    public static function authorizesGrant(array $decision, string $tenantId, string $workspaceId, string $purpose, string $grantDigest): bool
    {
        return $decision['tenant_id'] === $tenantId && $decision['workspace_id'] === $workspaceId
            && in_array($purpose, $decision['purposes'], true)
            && in_array($grantDigest, $decision['authorizes']['execution_grants'], true);
    }

    /** Whether a resolved decision authorizes this exact prompt identity for this purpose. */
    public static function authorizesPrompt(array $decision, string $tenantId, string $workspaceId, array $prompt): bool
    {
        if ($decision['tenant_id'] !== $tenantId || $decision['workspace_id'] !== $workspaceId || !in_array($prompt['purpose'] ?? null, $decision['purposes'], true)) {
            return false;
        }
        foreach ($decision['authorizes']['prompt_approvals'] as $allowed) {
            $match = true;
            foreach (self::PROMPT_IDENTITY_KEYS as $key) {
                if (($allowed[$key] ?? null) !== ($prompt[$key] ?? null)) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }

        return false;
    }

    private static function wellFormed(mixed $d): bool
    {
        if (!is_array($d)) {
            return false;
        }
        $keys = ['schema_version', 'kind', 'decision_id', 'decided_by', 'decided_at', 'canonical_authority_ref', 'tenant_id', 'workspace_id', 'purposes', 'authorizes', 'disclosure_scope', 'retention_scope'];
        if (array_diff($keys, array_keys($d)) !== [] || array_diff(array_keys($d), [...$keys, 'notes']) !== []) {
            return false;
        }
        if ($d['schema_version'] !== self::SCHEMA_VERSION || $d['kind'] !== self::KIND) {
            return false;
        }
        foreach (['decision_id', 'decided_by', 'decided_at', 'canonical_authority_ref', 'tenant_id', 'workspace_id', 'disclosure_scope', 'retention_scope'] as $text) {
            if (!is_string($d[$text]) || trim($d[$text]) === '' || strlen($d[$text]) > 500) {
                return false;
            }
        }
        if (!is_array($d['purposes']) || $d['purposes'] === [] || array_diff($d['purposes'], self::PURPOSES) !== []) {
            return false;
        }
        $a = $d['authorizes'];
        if (!is_array($a) || array_diff(array_keys($a), ['prompt_approvals', 'execution_grants']) !== [] || !is_array($a['prompt_approvals'] ?? null) || !is_array($a['execution_grants'] ?? null)) {
            return false;
        }
        foreach ($a['execution_grants'] as $digest) {
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return false;
            }
        }
        foreach ($a['prompt_approvals'] as $prompt) {
            if (!is_array($prompt) || array_diff(array_keys($prompt), self::PROMPT_IDENTITY_KEYS) !== []
                || array_diff(self::PROMPT_IDENTITY_KEYS, array_keys($prompt)) !== []) {
                return false;
            }
            foreach (['purpose','role','pack_version','prompt_version_id','binding_id'] as $key) {
                if (!is_string($prompt[$key]) || trim($prompt[$key]) === '' || strlen($prompt[$key]) > 191) {
                    return false;
                }
            }
            if (!in_array($prompt['purpose'], ['MODEL_EVALUATION','MANUAL_MONITOR'], true)) {
                return false;
            }
            foreach (['prompt_sha256','schema_sha256','model_policy_digest'] as $key) {
                if (!is_string($prompt[$key]) || preg_match('/^[a-f0-9]{64}$/D', $prompt[$key]) !== 1) {
                    return false;
                }
            }
        }

        return true;
    }
}
