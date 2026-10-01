<?php

declare(strict_types=1);

namespace Allfather\Loopia\Provider;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Support\Env;

/**
 * Closed version -> SecretBox mapping for provider credentials.
 *
 * A row says exactly which key version sealed it. Decryption therefore resolves that exact version
 * or refuses; it never "tries the other keys" until one happens to authenticate. One configured
 * active version is used for new writes/rewraps, while older versions may remain read-only recovery
 * material for retained rows/backups.
 */
final class KeyRing
{
    /** @param array<int, SecretBox> $boxes */
    private function __construct(
        private readonly array $boxes,
        private readonly int $activeVersion,
        private readonly string $mode,
    ) {
    }

    /** Backwards-compatible test/fixture adapter for one already-constructed SecretBox. */
    public static function single(SecretBox $box): self
    {
        return new self([$box->keyVersion() => $box], $box->keyVersion(), 'single');
    }

    /**
     * Build the only supported runtime configuration.
     *
     * Legacy mode:
     *   APP_MASTER_KEY=<key>        -> version 1, active 1
     *
     * Versioned mode:
     *   APP_MASTER_KEY_VERSIONS=1,2
     *   APP_MASTER_KEY_ACTIVE_VERSION=2
     *   APP_MASTER_KEY_V1=<key>
     *   APP_MASTER_KEY_V2=<key>
     *
     * The modes are mutually exclusive. Key names may be inspected, values may not be enumerated.
     */
    public static function fromEnv(Env $env): self
    {
        $legacy = $env->has('APP_MASTER_KEY');
        $prefixed = $env->keysWithPrefix('APP_MASTER_KEY_');
        // .env.example carries blank versioned placeholders beside the legacy field. Empty values
        // are not configuration and must not make an otherwise valid legacy deployment ambiguous.
        $presentPrefixed = array_values(array_filter(
            $prefixed,
            static fn (string $name): bool => $env->has($name),
        ));
        $versioned = $presentPrefixed !== [];

        if ($legacy && $versioned) {
            throw new \RuntimeException(
                'ambiguous master-key configuration: APP_MASTER_KEY cannot be combined with versioned APP_MASTER_KEY_* settings'
            );
        }

        if ($legacy) {
            return new self(
                [1 => SecretBox::fromMasterKey($env->require('APP_MASTER_KEY'), 1)],
                1,
                'legacy',
            );
        }

        if (!$versioned) {
            throw new \RuntimeException(
                'master-key configuration missing: expected APP_MASTER_KEY or APP_MASTER_KEY_VERSIONS/APP_MASTER_KEY_ACTIVE_VERSION'
            );
        }

        $versionsRaw = $env->get('APP_MASTER_KEY_VERSIONS');
        $activeRaw = $env->get('APP_MASTER_KEY_ACTIVE_VERSION');
        if ($versionsRaw === null || $activeRaw === null) {
            throw new \RuntimeException(
                'versioned master-key configuration requires APP_MASTER_KEY_VERSIONS and APP_MASTER_KEY_ACTIVE_VERSION'
            );
        }

        $versionTokens = array_map('trim', explode(',', $versionsRaw));
        if ($versionTokens === [] || in_array('', $versionTokens, true)) {
            throw new \RuntimeException('APP_MASTER_KEY_VERSIONS must be a comma-separated list of positive integers');
        }

        $versions = [];
        foreach ($versionTokens as $token) {
            if (preg_match('/^[1-9][0-9]*$/D', $token) !== 1) {
                throw new \RuntimeException('APP_MASTER_KEY_VERSIONS contains a non-positive or malformed version');
            }
            $version = (int) $token;
            if ($version < 1 || $version > 2147483647) {
                throw new \RuntimeException('APP_MASTER_KEY_VERSIONS contains an out-of-range version');
            }
            if (isset($versions[$version])) {
                throw new \RuntimeException("APP_MASTER_KEY_VERSIONS contains duplicate version {$version}");
            }
            $versions[$version] = true;
        }
        ksort($versions, SORT_NUMERIC);

        if (preg_match('/^[1-9][0-9]*$/D', $activeRaw) !== 1) {
            throw new \RuntimeException('APP_MASTER_KEY_ACTIVE_VERSION must be a positive integer');
        }
        $active = (int) $activeRaw;
        if (!isset($versions[$active])) {
            throw new \RuntimeException(
                "APP_MASTER_KEY_ACTIVE_VERSION {$active} is not declared in APP_MASTER_KEY_VERSIONS"
            );
        }

        $allowedNames = ['APP_MASTER_KEY_VERSIONS' => true, 'APP_MASTER_KEY_ACTIVE_VERSION' => true];
        foreach (array_keys($versions) as $version) {
            $allowedNames["APP_MASTER_KEY_V{$version}"] = true;
        }
        foreach ($presentPrefixed as $name) {
            if (!isset($allowedNames[$name])) {
                throw new \RuntimeException("undeclared or malformed versioned master-key setting: {$name}");
            }
        }

        $boxes = [];
        foreach (array_keys($versions) as $version) {
            $name = "APP_MASTER_KEY_V{$version}";
            if (!$env->has($name)) {
                throw new \RuntimeException("required versioned master-key setting missing: {$name}");
            }
            try {
                $boxes[$version] = SecretBox::fromMasterKey($env->require($name), $version);
            } catch (\RuntimeException $error) {
                throw new \RuntimeException("invalid {$name}: " . $error->getMessage(), 0, $error);
            }
        }

        return new self($boxes, $active, 'versioned');
    }

    /** Whether any master-key configuration was supplied at all. */
    public static function hasConfiguration(Env $env): bool
    {
        if ($env->has('APP_MASTER_KEY')) {
            return true;
        }
        foreach ($env->keysWithPrefix('APP_MASTER_KEY_') as $name) {
            if ($env->has($name)) {
                return true;
            }
        }

        return false;
    }

    public function activeVersion(): int
    {
        return $this->activeVersion;
    }

    /** @return list<int> */
    public function versions(): array
    {
        $versions = array_keys($this->boxes);
        sort($versions, SORT_NUMERIC);

        return array_values($versions);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function hasVersion(int $version): bool
    {
        return isset($this->boxes[$version]);
    }

    /**
     * Seal with the configured active write version.
     *
     * @return array{ciphertext:string,nonce_hex:string,auth_tag_hex:string,key_version:int,digest:string}
     */
    public function seal(string $plaintext, string $tenantId, string $provider): array
    {
        return $this->boxes[$this->activeVersion]->seal($plaintext, $tenantId, $provider);
    }

    /** Open with exactly the row's declared key version. */
    public function open(
        int $version,
        string $ciphertext,
        string $nonceHex,
        string $authTagHex,
        string $tenantId,
        string $provider,
    ): Outcome {
        $box = $this->boxes[$version] ?? null;
        if ($box === null) {
            return Outcome::refuse(
                'CREDENTIAL_KEY_VERSION_UNAVAILABLE',
                ['key_version' => $version],
                'INTEGRITY',
                'S3',
            );
        }

        return $box->open($ciphertext, $nonceHex, $authTagHex, $tenantId, $provider);
    }

    /** Keyed identity/currentness digest under exactly one version. */
    public function fingerprint(int $version, string $plaintext): Outcome
    {
        $box = $this->boxes[$version] ?? null;
        if ($box === null) {
            return Outcome::refuse(
                'CREDENTIAL_KEY_VERSION_UNAVAILABLE',
                ['key_version' => $version],
                'INTEGRITY',
                'S3',
            );
        }

        return Outcome::ok($box->fingerprint($plaintext));
    }
}
