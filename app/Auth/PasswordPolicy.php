<?php

declare(strict_types=1);

namespace Allfather\Loopia\Auth;

/**
 * Password hashing and verification.
 *
 * Argon2id where the host provides it, otherwise PHP's `PASSWORD_DEFAULT`. The choice is made at
 * runtime and RECORDED per row, so an account created on one host configuration still verifies
 * after a host upgrade, and `needsRehash` can upgrade it on the next successful login.
 *
 * Loopia's PHP build is not knowable from here, so this must degrade correctly rather than assume.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** @return array{hash: string, algo: string} */
    public static function hash(string $password): array
    {
        $algo = self::preferredAlgo();
        $hash = password_hash($password, $algo, self::options($algo));
        if (!is_string($hash)) {
            throw new \RuntimeException('password hashing failed');
        }

        return ['hash' => $hash, 'algo' => self::algoName($algo)];
    }

    /**
     * Verify in constant time relative to the stored hash.
     *
     * `password_verify` is already timing-safe against the hash. The caller must additionally
     * avoid leaking WHETHER an account exists — see `Authenticator::authenticate`, which runs a
     * dummy verify for an unknown email so both paths cost the same.
     */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algo = self::preferredAlgo();

        return password_needs_rehash($hash, $algo, self::options($algo));
    }

    /**
     * The one structural rule: length. Composition rules ("one uppercase, one symbol") push users
     * toward predictable substitutions and are not enforced.
     *
     * @return list<string> reason codes; empty means acceptable
     */
    public static function validate(string $password): array
    {
        $reasons = [];
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $reasons[] = 'PASSWORD_TOO_SHORT';
        }
        if (mb_strlen($password) > 4096) {
            // A megabyte password is a hashing-cost denial of service, not a strong password.
            $reasons[] = 'PASSWORD_TOO_LONG';
        }
        if (trim($password) === '') {
            $reasons[] = 'PASSWORD_BLANK';
        }

        return $reasons;
    }

    /** A hash of a value nobody knows, used to equalize timing on the unknown-account path. */
    public static function dummyHash(): string
    {
        static $dummy = null;

        return $dummy ??= self::hash(bin2hex(random_bytes(32)))['hash'];
    }

    private static function preferredAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)
            ? PASSWORD_ARGON2ID
            : PASSWORD_DEFAULT;
    }

    /** @return array<string, int> */
    private static function options(string $algo): array
    {
        if (defined('PASSWORD_ARGON2ID') && $algo === PASSWORD_ARGON2ID) {
            return [
                'memory_cost' => 65536,  // 64 MiB — within a shared-hosting memory_limit
                'time_cost' => 4,
                'threads' => 1,          // shared hosting: do not assume spare cores
            ];
        }

        return ['cost' => 12];
    }

    private static function algoName(string $algo): string
    {
        if (defined('PASSWORD_ARGON2ID') && $algo === PASSWORD_ARGON2ID) {
            return 'ARGON2ID';
        }

        return 'BCRYPT_DEFAULT';
    }
}
