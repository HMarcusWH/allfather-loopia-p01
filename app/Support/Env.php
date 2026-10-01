<?php

declare(strict_types=1);

namespace Allfather\Loopia\Support;

/**
 * `.env` loading for a host with no process manager.
 *
 * Loopia gives no way to set process environment variables for PHP, so configuration arrives as a
 * file that must live OUTSIDE the public document root. Values are read into this class rather
 * than into `$_ENV`/`putenv` so that:
 *
 *  - a secret cannot leak through `phpinfo()`, a `getenv()` dump, or a subprocess environment;
 *  - `Env::redactedSnapshot()` is the only way to render configuration, and it cannot render a
 *    secret by construction.
 */
final class Env
{
    /** Keys whose values must never be rendered, logged, or returned by a diagnostic surface. */
    private const SECRET_KEYS = [
        'DB_PASSWORD',
        'APP_MASTER_KEY',
        'SCHEDULER_TOKEN',
        'SCHEDULER_TOKEN_HASH',
    ];

    /** @param array<string, string> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            return new self([]);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("unable to read env file: {$path}");
        }

        return self::parse($contents);
    }

    public static function parse(string $contents): self
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eq = strpos($trimmed, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eq));
            $value = trim(substr($trimmed, $eq + 1));
            // Strip one matched pair of surrounding quotes, so a value with trailing spaces or a
            // '#' can be expressed without it being read as a comment.
            if (strlen($value) >= 2) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }
            $values[$key] = $value;
        }

        return new self($values);
    }

    /** @param array<string, string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /** Read a value that must be present. Fails closed rather than defaulting. */
    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new \RuntimeException("required configuration missing: {$key}");
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]) && $this->values[$key] !== '';
    }

    /**
     * Return configuration NAMES only for one reviewed prefix.
     *
     * P11 needs to refuse an undeclared APP_MASTER_KEY_V<n> without exposing the private value
     * behind it. There is deliberately no all-values accessor: callers may discover that a key name
     * exists, never enumerate secret contents.
     *
     * @return list<string>
     */
    public function keysWithPrefix(string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        $keys = array_values(array_filter(
            array_keys($this->values),
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Configuration rendered for a diagnostic surface. Secret keys report only whether they are
     * SET or MISSING — never their value, never a prefix, never a length.
     *
     * @return array<string, string>
     */
    public function redactedSnapshot(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            $out[$key] = in_array($key, self::SECRET_KEYS, true) || str_contains($key, 'SECRET')
                || str_contains($key, 'PASSWORD') || str_contains($key, 'KEY') || str_contains($key, 'TOKEN')
                ? ($value === '' ? 'MISSING' : 'SET')
                : $value;
        }
        ksort($out);

        return $out;
    }
}
