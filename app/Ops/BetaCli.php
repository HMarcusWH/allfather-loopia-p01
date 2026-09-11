<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Support\Bootstrap;
use Allfather\Loopia\Support\Env;

/**
 * Shared argument, configuration and secret-input handling for the beta operator commands.
 *
 * IT LIVES IN `app/`, NOT `ops/`, and that is a boundary rather than a filing preference. Every
 * file in `ops/` is an ENTRY POINT and is asserted to refuse a web SAPI before it loads anything;
 * a library sitting among them would either need a guard that protects nothing or would weaken the
 * rule to an "unless it is a helper". So `ops/` holds commands, `app/` holds code, and the two
 * commands that use this reach it through the ordinary autoloader after their own guard has run.
 *
 * THE PASSWORD RULES ARE HERE BECAUSE THEY MUST BE THE SAME IN BOTH COMMANDS. A secret read one way
 * in onboarding and another way in support is a secret with two threat models.
 */
final class BetaCli
{
    /**
     * Parse `--flag=value` arguments, refusing anything not on the known list.
     *
     * AN UNKNOWN FLAG IS FATAL, never ignored. `--suspend` silently dropped by a command that
     * expected `--suspended` is a support action an operator believes they performed. The same rule
     * `ops/provision-staging.php` already follows.
     *
     * A BARE `--password=` IS REFUSED BY NAME rather than falling through to "unknown argument",
     * because the operator who typed it needs to be told WHY, not merely that it did not work.
     * Anything on argv is in `ps`, in the shell history file, and in whatever collects either.
     *
     * @param list<string> $argv
     * @param list<string> $known
     * @return array<string, string>
     */
    public static function parseArgs(array $argv, array $known, callable $fail): array
    {
        $out = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m) !== 1) {
                $fail("unexpected argument '{$arg}'; only --flag or --flag=value is accepted");
            }
            $out[$m[1]] = $m[2] ?? '1';
        }

        foreach (array_keys($out) as $given) {
            if (in_array($given, self::REFUSED_SECRET_FLAGS, true)) {
                $fail(
                    "--{$given} is not accepted: a password on the command line is visible in the "
                    . 'process list and recorded in shell history. The command reads it from a '
                    . 'prompt, or from stdin with --password-stdin.'
                );
            }
            if (!in_array($given, $known, true)) {
                $fail("unknown argument --{$given}; known: " . implode(', ', $known));
            }
        }

        return $out;
    }

    /**
     * Flag names that would put a secret on argv. Refused with an explanation, never accepted.
     *
     * @var list<string>
     */
    public const REFUSED_SECRET_FLAGS = ['password', 'new-password', 'pass', 'pwd', 'secret'];

    /**
     * Read a password without it reaching argv, the terminal, or any output of this process.
     *
     * TWO ROUTES, ONE PROPERTY. `--password-stdin` reads one line from stdin, which is how this is
     * driven from a sealed pipe or a secret manager. Otherwise the operator is prompted, with
     * terminal echo turned off for the duration, and asked twice — a mistyped password an operator
     * cannot see is a lockout, and confirming costs one line.
     *
     * THE VALUE IS NEVER RETURNED ANYWHERE BUT HERE. Callers hand it straight to the service that
     * hashes it. Nothing in either command prints it, logs it, or puts it in the JSON result.
     */
    public static function readPassword(bool $fromStdin, string $prompt, callable $fail): string
    {
        if ($fromStdin) {
            $line = fgets(STDIN);
            if ($line === false) {
                $fail('--password-stdin was given but stdin held nothing');
            }
            // Only the trailing newline. Leading and interior whitespace are part of a passphrase,
            // and trimming them would silently accept a different password than the one supplied.
            $value = rtrim((string) $line, "\r\n");
            if ($value === '') {
                $fail('the password read from stdin was empty');
            }

            return $value;
        }

        if (!stream_isatty(STDIN)) {
            $fail('no terminal is attached; pipe the password and pass --password-stdin');
        }

        $first = self::promptSilently($prompt);
        $second = self::promptSilently('Repeat: ');
        if ($first !== $second) {
            $fail('the two passwords did not match');
        }
        if ($first === '') {
            $fail('an empty password is not a password');
        }

        return $first;
    }

    /**
     * One prompt with terminal echo suppressed.
     *
     * `stty` is invoked through `shell_exec`-free means deliberately: the no-external-action
     * tripwire forbids process execution across the application, and `ops/` is included in it. So
     * echo is suppressed by PHP's own readline support where the host provides it, and where it
     * does not the operator is told rather than silently typing a visible password.
     */
    private static function promptSilently(string $prompt): string
    {
        if (function_exists('readline_callback_handler_install')) {
            // The one portable way to read a line without echo in plain PHP: install a per-character
            // handler, print nothing, and stop at the newline.
            fwrite(STDERR, $prompt);
            $value = '';
            readline_callback_handler_install('', static function (): void {
            });
            while (true) {
                $char = stream_get_contents(STDIN, 1);
                if ($char === "\n" || $char === "\r" || $char === '' || $char === false) {
                    break;
                }
                if ($char === "\x7f" || $char === "\x08") {
                    $value = substr($value, 0, -1);
                    continue;
                }
                if ($char === "\x03") {
                    readline_callback_handler_remove();
                    fwrite(STDERR, PHP_EOL);
                    exit(130);
                }
                $value .= $char;
            }
            readline_callback_handler_remove();
            fwrite(STDERR, PHP_EOL);

            return $value;
        }

        // NO SILENT DEGRADATION TO A VISIBLE PASSWORD. An operator who does not know the password
        // was echoed cannot decide whether the terminal it went to was safe.
        fwrite(STDERR, $prompt . '(WARNING: this terminal cannot suppress echo; the password will '
            . 'be visible. Use --password-stdin instead.)' . PHP_EOL);
        $line = fgets(STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    /**
     * Resolve configuration the same way every other ops entry point does.
     *
     * An explicit `--env` is honoured as given; otherwise the fail-closed bootstrap decides, which
     * is what stops a staging command quietly running against the test database beside it.
     *
     * @param array<string, string> $args
     * @return array{env: Env, environment: string}
     */
    public static function configuration(string $root, array $args, callable $fail): array
    {
        if (isset($args['env'])) {
            if (!is_file($args['env'])) {
                $fail("no such configuration file: {$args['env']}");
            }
            $env = Env::load($args['env']);

            return ['env' => $env, 'environment' => Bootstrap::resolveEnvironment($env->get('APP_ENV'))];
        }

        try {
            $boot = Bootstrap::load($root);
        } catch (\RuntimeException $error) {
            $fail($error->getMessage());
        }

        return ['env' => $boot['env'], 'environment' => $boot['environment']];
    }

    /** @param Env $env */
    public static function connect(Env $env, callable $fail): Database
    {
        try {
            return Database::connect(
                $env->get('DB_HOST', '127.0.0.1') ?? '127.0.0.1',
                $env->int('DB_PORT', 3306),
                $env->require('DB_NAME'),
                $env->require('DB_USER'),
                $env->get('DB_PASSWORD', '') ?? '',
                $env->get('DB_SOCKET'),
            );
        } catch (\Throwable $error) {
            $fail('database unreachable: ' . $error->getMessage(), 3);
        }
    }

    /**
     * Print one sanitized result and stop.
     *
     * EVERY EXIT FROM THESE COMMANDS COMES THROUGH HERE, so there is one place to audit for what
     * they can emit. Nothing that reaches this function has ever held a password, a hash, a session
     * token, a CSRF token, a provider credential or a master key — the services above return
     * identifiers and states, and the commands pass those through unchanged.
     *
     * @param array<string, mixed> $payload
     */
    public static function emit(array $payload, int $exitCode = 0): never
    {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
        exit($exitCode);
    }
}
