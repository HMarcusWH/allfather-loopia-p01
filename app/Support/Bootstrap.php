<?php

declare(strict_types=1);

namespace Allfather\Loopia\Support;

use Allfather\Loopia\Provider\SecretBox;

/**
 * The one place configuration is loaded, and the one place a deployment is allowed to refuse to
 * start.
 *
 * Every entry point used to do this inline:
 *
 *     Env::load(is_file($root . '/.env') ? $root . '/.env' : $root . '/.env.testing');
 *
 * which fails OPEN in three directions at once. A production host whose `.env` was missing,
 * unreadable, or not yet written would silently load the TEST file beside it — test credentials, a
 * development `APP_ENV`, no master key, no scheduler token — and run. If neither file existed the
 * result was an empty configuration in which every `bool()` and `int()` took its default, so the
 * absence of configuration read as a set of choices nobody made. And nothing checked at boot that
 * a production deployment had the keys it cannot work safely without; the first sign of a missing
 * `APP_MASTER_KEY` was a credential failing to encrypt, long after the deployment reported healthy.
 *
 * THE INVERSIONS THAT FIX IT:
 *
 *   AN UNKNOWN ENVIRONMENT IS PRODUCTION. `APP_ENV` is a closed vocabulary, and a value outside it
 *   — a typo, an empty string, a value some future tooling invents — resolves to the STRICTEST
 *   posture. Defaulting to development would mean one typo turns every production check off.
 *
 *   THE TEST FILE IS NEVER PRODUCTION'S. `.env.testing` is loaded only when `.env` is absent, and
 *   an environment that came from it may not declare itself production. So "the production host
 *   quietly ran on test credentials" has no path.
 *
 *   PRODUCTION REQUIRES ITS KEYS AT BOOT. Missing ones are named and the deployment refuses to
 *   start, rather than starting and failing at whichever request first needed them. The master key
 *   is DECODED here too, because a key that is present but 31 bytes is a configuration error a
 *   deployment should meet at boot and not at the first credential it tries to seal.
 */
final class Bootstrap
{
    /**
     * The closed environment vocabulary. Anything else is production, by the rule above.
     *
     * @var list<string>
     */
    public const ENVIRONMENTS = ['development', 'testing', 'staging', 'production'];

    /** The postures that get production's checks. Staging holds real credentials too. */
    public const STRICT_ENVIRONMENTS = ['staging', 'production'];

    /**
     * Keys a strict environment cannot start without.
     *
     * `DB_PASSWORD` is deliberately absent: a socket-authenticated MariaDB user legitimately has
     * none, and requiring it would refuse a correct deployment.
     *
     * @var list<string>
     */
    public const REQUIRED_IN_STRICT = ['DB_NAME', 'DB_USER', 'APP_MASTER_KEY'];

    /**
     * Load configuration for a deployment, or explain why it may not start.
     *
     * @param string $root the application root — the directory holding `.env`, never the served one
     * @return array{env: Env, environment: string, strict: bool, source: string}
     * @throws \RuntimeException when a strict environment is not safe to start
     */
    public static function load(string $root): array
    {
        $productionPath = $root . '/.env';
        $testingPath = $root . '/.env.testing';

        $source = null;
        if (is_file($productionPath)) {
            $source = $productionPath;
        } elseif (is_file($testingPath)) {
            $source = $testingPath;
        }

        if ($source === null) {
            // NOT an empty configuration. An empty Env answers every `bool()` and `int()` with its
            // default, so a deployment with no configuration at all would look like one that had
            // chosen every default on purpose.
            throw new \RuntimeException(
                'no configuration file found: expected .env in the application root '
                . '(see .env.example, and docs/DEPLOYMENT_RUNBOOK.md for where the root belongs)'
            );
        }

        $env = Env::load($source);
        $environment = self::resolveEnvironment($env->get('APP_ENV'));
        $strict = in_array($environment, self::STRICT_ENVIRONMENTS, true);

        if ($strict && $source !== $productionPath) {
            throw new \RuntimeException(sprintf(
                'refusing to start: APP_ENV is %s but configuration came from %s. '
                . 'A strict environment reads .env and nothing else.',
                $environment,
                basename($source),
            ));
        }

        if ($strict) {
            $missing = [];
            foreach (self::REQUIRED_IN_STRICT as $key) {
                if (!$env->has($key)) {
                    $missing[] = $key;
                }
            }
            if ($missing !== []) {
                // The key NAMES are safe to report: they are in `.env.example` in this repository.
                // Their values never appear here, and `Env::redactedSnapshot` is the only thing
                // that renders configuration at all.
                throw new \RuntimeException(sprintf(
                    'refusing to start in %s: required configuration missing: %s',
                    $environment,
                    implode(', ', $missing),
                ));
            }

            // Decoded, not merely present. A key that is present but 31 bytes is a configuration
            // error a deployment should meet at boot rather than at the first credential it seals.
            SecretBox::fromMasterKey((string) $env->get('APP_MASTER_KEY'));
        }

        return ['env' => $env, 'environment' => $environment, 'strict' => $strict, 'source' => $source];
    }

    /**
     * Resolve `APP_ENV` against the closed vocabulary.
     *
     * An unset, empty, misspelled or unrecognised value is PRODUCTION. That direction is the whole
     * point: the cost of treating a development box as production is a few required keys, and the
     * cost of the inverse is a production deployment with every check switched off by a typo.
     */
    public static function resolveEnvironment(?string $declared): string
    {
        $normalized = strtolower(trim((string) $declared));

        return in_array($normalized, self::ENVIRONMENTS, true) ? $normalized : 'production';
    }

    /**
     * Apply the runtime posture for an environment.
     *
     * Errors are never DISPLAYED in a strict environment — a PHP notice rendered into a response
     * carries absolute paths, and a fatal carries a stack trace naming every layer it passed
     * through. They are still logged; the caller is simply not the audience.
     */
    public static function applyErrorPosture(bool $strict): void
    {
        ini_set('display_errors', $strict ? '0' : '1');
        ini_set('display_startup_errors', $strict ? '0' : '1');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
    }
}
