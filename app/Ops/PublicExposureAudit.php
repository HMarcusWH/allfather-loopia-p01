<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

/**
 * Read-only public source exposure guard. Returns stable codes, file paths and line numbers,
 * NEVER the matched credential, raw source line, owner decision payload or private key.
 * Public owner-decision metadata is reviewed disclosure, not an API credential.
 */
final class PublicExposureAudit
{
    public const VERSION = '1.0';
    public const MAX_FILE_BYTES = 3145728;
    public const REVIEWED_DECISIONS = [
        'docs/decisions/plus46_luna_dev7_model_evaluation_grant_2026_10_24.json',
        'docs/decisions/plus46_luna_model_evaluation_prompts_2026_10_24.json',
        'docs/decisions/plus46_model_evaluation_grant_2026_10_24.json',
        'docs/decisions/plus46_model_evaluation_prompts_2026_10_24.json',
    ];

    /** @return list<array{code:string,path:string,line:int}> */
    public static function inspect(string $path, string $bytes): array
    {
        $path = str_replace('\\', '/', $path);
        $found = [];
        $add = static function (string $code, int $line = 0) use ($path, &$found): void {
            $found[] = ['code' => $code, 'path' => $path, 'line' => $line];
        };
        if ($path === '' || str_starts_with($path, '/')
            || str_contains('/' . $path . '/', '/../') || str_contains('/' . $path . '/', '/./')) {
            $add('INVALID_SOURCE_PATH');
            return $found;
        }
        $name = basename($path);
        if ($name === '.env' || (str_starts_with($name, '.env.') && $name !== '.env.example')
            || preg_match('/^(?:\.npmrc|\.netrc|id_rsa|id_ed25519|credentials\.json|service[-_]account(?:[-_].*)?\.json)$/i', $name) === 1
            || preg_match('/\.(?:pem|p12|pfx|p8|key|kdbx|sqlite3?|db|bak|dump|sql\.gz)$/i', $name) === 1
            || str_starts_with($path, '.private-ops/')
            || (str_starts_with($path, 'storage/')
                && !in_array($path, ['storage/.htaccess', 'storage/README.md'], true))) {
            $add('PRIVATE_MATERIAL_TRACKED_PATH');
        }

        if (str_starts_with($path, 'docs/decisions/') && str_ends_with($path, '.json')) {
            if (!in_array($path, self::REVIEWED_DECISIONS, true)) {
                $add('UNREVIEWED_OWNER_DECISION_PATH');
            }
            $doc = json_decode($bytes, true);
            if (!is_array($doc) || ($doc['kind'] ?? null) !== 'OWNER_EXECUTION_DECISION'
                || !is_string($doc['decision_id'] ?? null)
                || !is_array($doc['authorizes'] ?? null)) {
                $add('INVALID_PUBLIC_OWNER_DECISION');
            }
        }
        if (strlen($bytes) > self::MAX_FILE_BYTES) {
            $add('SOURCE_TOO_LARGE_TO_INSPECT');
            return $found;
        }
        if (str_contains($bytes, "\0")) {
            $add('BINARY_SOURCE_UNINSPECTED');
            return $found;
        }
        if ($path === '.env.example') {
            foreach (preg_split('/\r?\n/', $bytes) ?: [] as $i => $line) {
                if (!str_starts_with(ltrim($line), '#')
                    && preg_match('/^\s*(?:DB_PASSWORD|APP_MASTER_KEY|APP_MASTER_KEY_VERSIONS|SCHEDULER_TOKEN|SCHEDULER_TOKEN_HASH|OPENAI_API_KEY|GITHUB_TOKEN)\s*=\s*\S+/', $line) === 1) {
                    $add('NONEMPTY_SECRET_IN_ENV_TEMPLATE', $i + 1);
                }
            }
        }

        // High-confidence patterns only. Public UUIDs, SHA-256 digests, public contact addresses,
        // fixture IDs and obvious CI-only SQL credentials are not classified as API tokens.
        foreach ([
            'PRIVATE_KEY_BLOCK' => '/-----BEGIN (?:RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----/',
            'GITHUB_ACCESS_TOKEN' => '/\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})\b/',
            'OPENAI_API_TOKEN' => '/\bsk-(?:proj-|svcacct-)?[A-Za-z0-9_-]{24,}\b/',
            'SLACK_ACCESS_TOKEN' => '/\bxox[aboprs]-[A-Za-z0-9-]{18,}\b/',
            'GOOGLE_API_TOKEN' => '/\bAIza[A-Za-z0-9_-]{30,}\b/',
            'AWS_ACCESS_ID' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
            'STRIPE_SECRET_TOKEN' => '/\b(?:sk_live_|rk_live_|whsec_)[A-Za-z0-9]{20,}\b/',
            'HTTP_BEARER_LITERAL' => '/\bAuthorization\s*:\s*Bearer\s+[A-Za-z0-9._~+\/-]{28,}/i',
        ] as $code => $regex) {
            if (preg_match($regex, $bytes, $hit, PREG_OFFSET_CAPTURE) === 1) {
                $add($code, substr_count(substr($bytes, 0, $hit[0][1]), "\n") + 1);
            }
        }
        return $found;
    }

    /**
     * Inspect source export without reading untracked host .env, runtime storage or private ops.
     * Git-index coverage is separately checked in a real CI checkout.
     * @return array{files:int,decisions:int,findings:list<array{code:string,path:string,line:int}>}
     */
    public static function auditTree(string $root): array
    {
        $root = rtrim($root, '/');
        $files = 0;
        $decisions = 0;
        $found = [];
        $walk = static function (string $dir, string $relative) use (&$walk, &$files, &$decisions, &$found): void {
            foreach (new \DirectoryIterator($dir) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                $path = ltrim($relative . '/' . $entry->getFilename(), '/');
                $parts = explode('/', $path);
                if (array_intersect($parts, ['.git', '.private-ops', '.idea', '.vscode', 'vendor', 'node_modules', 'dist'])
                    || $path === 'storage' || str_starts_with($path, 'storage/')
                    || str_starts_with($path, 'public_html/assets/operator-console/')
                    || (str_starts_with($path, '.env') && $path !== '.env.example')) {
                    continue;
                }
                if ($entry->isLink()) {
                    $found[] = ['code' => 'UNINSPECTED_SOURCE_SYMLINK', 'path' => $path, 'line' => 0];
                } elseif ($entry->isDir()) {
                    $walk($entry->getPathname(), $path);
                } elseif (!$entry->isFile() || !is_readable($entry->getPathname())) {
                    $found[] = ['code' => 'SOURCE_UNREADABLE', 'path' => $path, 'line' => 0];
                } else {
                    $files++;
                    if (in_array($path, self::REVIEWED_DECISIONS, true)) {
                        $decisions++;
                    }
                    $bytes = file_get_contents($entry->getPathname());
                    if ($bytes === false) {
                        $found[] = ['code' => 'SOURCE_UNREADABLE', 'path' => $path, 'line' => 0];
                    } else {
                        array_push($found, ...self::inspect($path, $bytes));
                    }
                }
            }
        };
        $walk($root, '');
        usort($found, static fn (array $a, array $b): int => [$a['path'], $a['code']] <=> [$b['path'], $b['code']]);
        return ['files' => $files, 'decisions' => $decisions, 'findings' => $found];
    }
}
