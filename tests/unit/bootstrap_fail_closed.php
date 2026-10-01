<?php

declare(strict_types=1);

/**
 * Configuration loading fails CLOSED.
 *
 * Every entry point used to resolve its configuration with one inline expression:
 *
 *     Env::load(is_file($root . '/.env') ? $root . '/.env' : $root . '/.env.testing');
 *
 * which fails open in three directions. A production host whose `.env` was missing or unreadable
 * silently loaded the TEST file beside it. With neither present the result was an empty
 * configuration in which every default was taken, so the absence of configuration read as a set of
 * choices somebody had made. And nothing checked at boot that a production deployment held the
 * keys it cannot work safely without — the first sign of a missing master key was a credential
 * failing to encrypt, long after the deployment reported healthy.
 *
 * These run against real files in a scratch directory rather than a mocked filesystem, because the
 * behaviour under test IS which file gets read.
 */

use Allfather\Loopia\Support\Bootstrap;
use Allfather\Loopia\Tests\Assert;

$scratch = sys_get_temp_dir() . '/allfather-bootstrap-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700, true);

$write = static function (string $name, string $body) use ($scratch): void {
    file_put_contents($scratch . '/' . $name, $body);
};
$clear = static function () use ($scratch): void {
    foreach (['/.env', '/.env.testing'] as $file) {
        if (is_file($scratch . $file)) {
            unlink($scratch . $file);
        }
    }
};
$fails = static function (callable $act): ?string {
    try {
        $act();

        return null;
    } catch (\RuntimeException $error) {
        return $error->getMessage();
    }
};

$masterKey = base64_encode(random_bytes(32));
$productionBody = "APP_ENV=production\nDB_NAME=live\nDB_USER=live\nAPP_MASTER_KEY={$masterKey}\n";

// ============================================================ an unknown environment is PRODUCTION

// The inversion that carries the rest: the cost of treating a development box as production is a
// few required keys; the cost of the inverse is a production deployment with every check switched
// off by a typo.
// A LIST of pairs, not a keyed array: PHP collapses null and '' to the same array key, and two of
// the shapes that matter most would have silently become one case.
foreach ([
    [null, 'unset'],
    ['', 'empty'],
    ['   ', 'whitespace'],
    ['prod', 'abbreviated'],
    ['PRODUCTIN', 'misspelled'],
    ['live', 'invented'],
    ['dev', 'an abbreviation of a NON-strict environment'],
] as [$declared, $shape]) {
    Assert::same(
        'production',
        Bootstrap::resolveEnvironment($declared),
        "an APP_ENV that is {$shape} resolves to production, the strictest posture",
    );
}
foreach (Bootstrap::ENVIRONMENTS as $known) {
    Assert::same($known, Bootstrap::resolveEnvironment($known), "{$known} is recognised as itself");
    Assert::same(
        $known,
        Bootstrap::resolveEnvironment('  ' . strtoupper($known) . ' '),
        "and survives being differently cased and padded: {$known}",
    );
}
Assert::same(
    ['development', 'testing', 'staging', 'production'],
    Bootstrap::ENVIRONMENTS,
    'and the vocabulary is closed — anything outside it is production by the rule above',
);

// ============================================================ errors are never DISPLAYED in strict

// A PHP notice rendered into a response carries absolute paths, and a fatal carries a stack trace
// naming every layer it passed through. They are still logged; the caller is not the audience.
$displayBefore = ini_get('display_errors');
Bootstrap::applyErrorPosture(true);
Assert::same('0', (string) ini_get('display_errors'), 'a strict environment displays no errors');
Assert::same('1', (string) ini_get('log_errors'), 'and still logs them — the audience changes, not the record');
Bootstrap::applyErrorPosture(false);
Assert::same('1', (string) ini_get('display_errors'), 'a development environment shows them');
ini_set('display_errors', (string) $displayBefore);

// ============================================================ no configuration is not empty configuration

$clear();
$noFile = $fails(static fn () => Bootstrap::load($scratch));
Assert::true($noFile !== null, 'a deployment with no configuration file at all refuses to start');
Assert::contains(
    'no configuration file found',
    (string) $noFile,
    'and says so — rather than starting on an empty configuration in which every default is taken',
);

// ============================================================ the test file is never production's

// The defect this whole class exists for: a production host whose `.env` was missing loaded the
// test file beside it and ran on test credentials.
$clear();
$write('.env.testing', $productionBody);
$wrongSource = $fails(static fn () => Bootstrap::load($scratch));
Assert::true($wrongSource !== null, 'a strict environment configured from .env.testing refuses to start');
Assert::contains('.env.testing', (string) $wrongSource, 'naming the file it came from');
Assert::contains(
    'reads .env and nothing else',
    (string) $wrongSource,
    'and stating the rule — a strict environment has one configuration file',
);

// The same file, declaring a non-strict environment, is fine. The rule is about the POSTURE the
// configuration claims, not about the filename: a development box legitimately has only this file.
$clear();
$write('.env.testing', "APP_ENV=testing\nDB_NAME=t\nDB_USER=t\n");
$loaded = Bootstrap::load($scratch);
Assert::same('testing', $loaded['environment'], 'a testing environment loads from .env.testing');
Assert::false($loaded['strict'], 'and is not strict');
Assert::same($scratch . '/.env.testing', $loaded['source'], 'the source is reported, not guessed at');

// `.env` WINS when both exist, so a stale test file cannot shadow the real configuration.
$clear();
$write('.env', $productionBody);
$write('.env.testing', "APP_ENV=testing\nDB_NAME=t\nDB_USER=t\n");
$both = Bootstrap::load($scratch);
Assert::same('production', $both['environment'], '.env wins when both files exist');
Assert::same($scratch . '/.env', $both['source'], 'and a stale test file beside it is not read');

// ============================================================ production requires DB identity + one keyring AT BOOT

foreach (Bootstrap::REQUIRED_IN_STRICT as $omitted) {
    $clear();
    $lines = [];
    foreach (['APP_ENV' => 'production', 'DB_NAME' => 'live', 'DB_USER' => 'live', 'APP_MASTER_KEY' => $masterKey] as $key => $value) {
        if ($key !== $omitted) {
            $lines[] = "{$key}={$value}";
        }
    }
    $write('.env', implode("\n", $lines) . "\n");

    $missing = $fails(static fn () => Bootstrap::load($scratch));
    Assert::true($missing !== null, "production without {$omitted} refuses to start");
    Assert::contains($omitted, (string) $missing, "and names {$omitted} as the missing key");
    // Key NAMES are public — they are in `.env.example` — but a VALUE must never travel with them.
    Assert::false(
        str_contains((string) $missing, $masterKey),
        "and never carries a configured value alongside the name it reports ({$omitted})",
    );
}

// DB_PASSWORD is deliberately NOT required: a socket-authenticated MariaDB user legitimately has
// none, and requiring it would refuse a correct deployment.
Assert::false(
    in_array('DB_PASSWORD', Bootstrap::REQUIRED_IN_STRICT, true),
    'DB_PASSWORD is not required — a socket-authenticated user legitimately has none',
);

// Master-key configuration is a legacy-or-versioned grammar, not one required literal name.
Assert::false(
    in_array('APP_MASTER_KEY', Bootstrap::REQUIRED_IN_STRICT, true),
    'APP_MASTER_KEY is not a literal strict requirement because versioned keyring mode is valid too',
);
$clear();
$write('.env', "APP_ENV=production\nDB_NAME=live\nDB_USER=live\n");
$noKeyring = $fails(static fn () => Bootstrap::load($scratch));
Assert::true($noKeyring !== null, 'production with no master-key mode refuses to start');
Assert::contains('master-key configuration missing', (string) $noKeyring, 'and names the missing keyring contract');

$versionedKey2 = base64_encode(random_bytes(32));
$clear();
$write(
    '.env',
    "APP_ENV=production\nDB_NAME=live\nDB_USER=live\n"
    . "APP_MASTER_KEY_VERSIONS=1,2\nAPP_MASTER_KEY_ACTIVE_VERSION=2\n"
    . "APP_MASTER_KEY_V1={$masterKey}\nAPP_MASTER_KEY_V2={$versionedKey2}\n",
);
$versionedBoot = Bootstrap::load($scratch);
Assert::same('production', $versionedBoot['environment'], 'a complete versioned production keyring boots');

$clear();
$write(
    '.env',
    "APP_ENV=production\nDB_NAME=live\nDB_USER=live\nAPP_MASTER_KEY={$masterKey}\n"
    . "APP_MASTER_KEY_VERSIONS=1,2\nAPP_MASTER_KEY_ACTIVE_VERSION=2\n"
    . "APP_MASTER_KEY_V1={$masterKey}\nAPP_MASTER_KEY_V2={$versionedKey2}\n",
);
$mixedMode = $fails(static fn () => Bootstrap::load($scratch));
Assert::contains('ambiguous master-key configuration', (string) $mixedMode, 'legacy + versioned key configuration refuses at boot');

// A master key that is PRESENT but not 32 bytes is a configuration error the deployment meets at
// boot, not at the first credential it tries to seal.
$clear();
$write('.env', "APP_ENV=production\nDB_NAME=live\nDB_USER=live\nAPP_MASTER_KEY=" . base64_encode(random_bytes(31)) . "\n");
$shortKey = $fails(static fn () => Bootstrap::load($scratch));
Assert::true($shortKey !== null, 'a present-but-short master key refuses to start');
Assert::contains('32 bytes', (string) $shortKey, 'and says what a valid one looks like');

// A complete production configuration starts.
$clear();
$write('.env', $productionBody);
$good = Bootstrap::load($scratch);
Assert::same('production', $good['environment'], 'a complete production configuration loads');
Assert::true($good['strict'], 'as a strict environment');
Assert::same('live', $good['env']->get('DB_NAME'), 'with its values available');

// Staging is strict too: it holds real credentials, so it gets production's checks.
Assert::true(
    in_array('staging', Bootstrap::STRICT_ENVIRONMENTS, true),
    'staging is strict as well — it holds real credentials',
);
$clear();
$write('.env', "APP_ENV=staging\nDB_NAME=stage\nDB_USER=stage\n");
$stagingMissing = $fails(static fn () => Bootstrap::load($scratch));
Assert::contains(
    'APP_MASTER_KEY',
    (string) $stagingMissing,
    'and refuses on the same missing keys production does',
);

$clear();
rmdir($scratch);
