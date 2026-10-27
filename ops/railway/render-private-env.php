<?php
declare(strict_types=1);

/**
 * Railway-only private .env renderer. Never render keys into stdout, argv, or a
 * web route. Actual key semantics are owned by Provider\KeyRing, not this port.
 *
 * The renderer creates ONLY an exclusive .env.prepare file; entrypoint.sh
 * validates ownership/permissions then atomically publishes it as .env.
 */
require dirname(__DIR__, 2) . '/app/autoload.php';

use Allfather\Loopia\Provider\KeyRing;
use Allfather\Loopia\Support\Env;

try {
    if (PHP_SAPI !== 'cli' || $argc !== 2 || basename((string) $argv[1]) !== '.env.prepare') {
        throw new RuntimeException('CLI staging target required');
    }

    $target = (string) $argv[1];
    if (!is_dir(dirname($target)) || is_link($target) || file_exists($target)) {
        throw new RuntimeException('unsafe private configuration target');
    }

    $source = getenv();
    if (!is_array($source)) {
        throw new RuntimeException('Railway process environment unavailable');
    }

    $required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
    $values = ['APP_ENV' => 'staging'];
    if (($source['APP_ENV'] ?? null) !== 'staging') {
        throw new RuntimeException('not a staging runtime');
    }

    $validate = static function (string $name, string $value): void {
        // Env::parse trims values and peels one matched quote pair. Refuse input
        // that would silently become a different credential at runtime.
        if ($value === '' || $value !== trim($value)
            || str_contains($value, "\n") || str_contains($value, "\r")
            || str_contains($value, "\0")
            || (strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && str_ends_with($value, $value[0]))
        ) {
            throw new RuntimeException("invalid {$name}");
        }
    };

    foreach ($required as $name) {
        $value = $source[$name] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("missing {$name}");
        }
        $validate($name, $value);
        $values[$name] = $value;
    }
    if (!ctype_digit($values['DB_PORT']) || (int) $values['DB_PORT'] < 1
        || (int) $values['DB_PORT'] > 65535) {
        throw new RuntimeException('invalid DB_PORT');
    }

    $baseUrl = $source['APP_BASE_URL'] ?? 'https://railway-staging.invalid';
    $release = $source['RAILWAY_GIT_COMMIT_SHA'] ?? 'UNQUALIFIED_RAILWAY_STAGING';
    foreach (['APP_BASE_URL' => $baseUrl, 'APP_RELEASE_REF' => $release] as $name => $value) {
        if (!is_string($value)) {
            throw new RuntimeException("invalid {$name}");
        }
        $validate($name, $value);
        $values[$name] = $value;
    }

    // Treat *every* APP_MASTER_KEY_* name in the process as relevant to P11,
    // including malformed non-empty names. Never silently drop an undeclared
    // version or fall back to the legacy key.
    $keySettings = [];
    foreach ($source as $name => $value) {
        if ($name === 'APP_MASTER_KEY' || str_starts_with((string) $name, 'APP_MASTER_KEY_')) {
            if (!is_string($value)) {
                throw new RuntimeException('invalid master-key setting');
            }
            if ($value !== '') {
                $validate((string) $name, $value);
                $keySettings[$name] = $value;
            }
        }
    }
    $ring = KeyRing::fromEnv(Env::fromArray($keySettings));

    if ($ring->mode() === 'legacy') {
        $values['APP_MASTER_KEY'] = $keySettings['APP_MASTER_KEY'];
    } elseif ($ring->mode() === 'versioned') {
        $values['APP_MASTER_KEY_VERSIONS'] = $keySettings['APP_MASTER_KEY_VERSIONS'];
        $values['APP_MASTER_KEY_ACTIVE_VERSION'] = $keySettings['APP_MASTER_KEY_ACTIVE_VERSION'];
        foreach ($ring->versions() as $version) {
            $name = "APP_MASTER_KEY_V{$version}";
            $values[$name] = $keySettings[$name];
        }
    } else {
        throw new RuntimeException('unknown master-key mode');
    }

    $lines = [];
    foreach ($values as $name => $value) {
        $lines[] = $name . '=' . $value;
    }
    $rendered = implode("\n", $lines) . "\n";

    // Assert actual application reader sees exactly what the renderer meant,
    // including P11's key version/mode. This is not simply a string check.
    $roundTrip = Env::parse($rendered);
    foreach ($values as $name => $value) {
        if ($roundTrip->require($name) !== $value) {
            throw new RuntimeException('configuration serialization changed a value');
        }
    }
    $parsedRing = KeyRing::fromEnv($roundTrip);
    if ($parsedRing->mode() !== $ring->mode()
        || $parsedRing->activeVersion() !== $ring->activeVersion()
        || $parsedRing->versions() !== $ring->versions()) {
        throw new RuntimeException('master-key serialization mismatch');
    }

    umask(0077);
    $file = @fopen($target, 'x');
    if (!is_resource($file)) {
        throw new RuntimeException('cannot exclusively create private configuration');
    }
    try {
        if (fwrite($file, $rendered) !== strlen($rendered) || !fflush($file)) {
            throw new RuntimeException('incomplete private configuration write');
        }
    } finally {
        fclose($file);
    }
    if (!chmod($target, 0600)) {
        throw new RuntimeException('private configuration permissions failed');
    }
} catch (Throwable) {
    // Deliberately generic: no user input, provider key or DB password leaks.
    fwrite(STDERR, "allfather-railway: PRIVATE_CONFIG_REFUSED\n");
    exit(1);
}
