<?php

declare(strict_types=1);

namespace Allfather\Loopia\Provider;

use Allfather\Loopia\Core\Outcome;

/**
 * AES-256-GCM sealing for BYOK provider credentials.
 *
 * Rules this implements, from the deployment security boundary:
 *  - never plaintext in the database;
 *  - APP_MASTER_KEY lives only in server-private configuration, never in a row, never in a log;
 *  - the database stores ciphertext + nonce + auth tag + key version, so rotation can re-wrap
 *    without guessing which key sealed a row;
 *  - a key is never echoed after save — the UI shows a fingerprint, and the fingerprint is a
 *    keyed HMAC, not a prefix or a suffix of the secret.
 *
 * GCM is authenticated encryption: a tampered ciphertext, nonce or tag fails to open rather than
 * decrypting to garbage the caller might then send to a provider. Additional authenticated data
 * binds the sealed value to its tenant and provider, so a row copied between tenants will not open.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    private function __construct(
        private readonly string $key,
        private readonly int $keyVersion,
    ) {
    }

    /**
     * Build from the configured master key.
     *
     * The key is required to be 32 bytes of real entropy, supplied base64- or hex-encoded. A short
     * or absent key is a fatal configuration error, not something to pad or derive around: a
     * weak key here would make every stored credential recoverable while still "encrypted".
     */
    public static function fromMasterKey(string $encoded, int $keyVersion = 1): self
    {
        if ($keyVersion < 1) {
            throw new \RuntimeException('credential key version must be a positive integer');
        }
        $raw = self::decodeKey($encoded);
        if ($raw === null || strlen($raw) !== self::KEY_BYTES) {
            throw new \RuntimeException(
                'APP_MASTER_KEY must decode to exactly 32 bytes (base64 or hex). '
                . 'Generate with: php -r "echo base64_encode(random_bytes(32));"'
            );
        }

        return new self($raw, $keyVersion);
    }

    public static function generateMasterKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    public function keyVersion(): int
    {
        return $this->keyVersion;
    }

    /**
     * Seal `$plaintext`, binding it to `$tenantId` and `$provider`.
     *
     * @return array{ciphertext: string, nonce_hex: string, auth_tag_hex: string, key_version: int, digest: string}
     */
    public function seal(string $plaintext, string $tenantId, string $provider): array
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($tenantId, $provider),
            self::TAG_BYTES,
        );
        if ($ciphertext === false) {
            throw new \RuntimeException('SecretBox: encryption failed');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce_hex' => bin2hex($nonce),
            'auth_tag_hex' => bin2hex($tag),
            'key_version' => $this->keyVersion,
            'digest' => $this->fingerprint($plaintext),
        ];
    }

    /**
     * Open a sealed value.
     *
     * @return Outcome Outcome::ok(string) with the plaintext
     */
    public function open(string $ciphertextB64, string $nonceHex, string $tagHex, string $tenantId, string $provider): Outcome
    {
        $ciphertext = base64_decode($ciphertextB64, true);
        $nonce = @hex2bin($nonceHex);
        $tag = @hex2bin($tagHex);
        if ($ciphertext === false || $nonce === false || $tag === false
            || strlen($nonce) !== self::NONCE_BYTES || strlen($tag) !== self::TAG_BYTES) {
            return Outcome::refuse('CREDENTIAL_CIPHERTEXT_MALFORMED', [], 'INTEGRITY');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($tenantId, $provider),
        );
        if ($plaintext === false) {
            // Wrong key, wrong tenant, wrong provider, or tampering — all indistinguishable, and
            // all fail closed. The detail deliberately says nothing about which.
            return Outcome::refuse('CREDENTIAL_DECRYPT_FAILED', [], 'INTEGRITY', 'S3');
        }

        return Outcome::ok($plaintext);
    }

    /**
     * A non-reversible fingerprint for identity/currentness. It is a keyed HMAC of the WHOLE secret, so it
     * reveals no part of it — showing "sk-ant-…3f9a" would leak both the provider and a suffix.
     */
    public function fingerprint(string $plaintext): string
    {
        return hash_hmac('sha256', $plaintext, $this->key);
    }

    private static function aad(string $tenantId, string $provider): string
    {
        return 'allfather:credential:v1:' . $tenantId . ':' . $provider;
    }

    private static function decodeKey(string $encoded): ?string
    {
        $encoded = trim($encoded);
        if ($encoded === '') {
            return null;
        }
        if (preg_match('/^[0-9a-fA-F]{64}$/', $encoded) === 1) {
            $raw = @hex2bin($encoded);

            return $raw === false ? null : $raw;
        }
        $raw = base64_decode($encoded, true);

        return $raw === false ? null : $raw;
    }
}
