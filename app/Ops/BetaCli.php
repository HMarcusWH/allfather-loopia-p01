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
 * in onboarding and another way in support is a secret with two threat models — and there is
 * exactly one way in: stdin. See `readPassword` for why there is no interactive prompt.
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
     * STDIN ONLY. There is deliberately no interactive prompt, and the reason is the one this
     * repository applies to every host question: an unproven capability is a missing capability.
     *
     * PHP has no portable primitive for reading a line without terminal echo. The usual trick —
     * `readline_callback_handler_install` followed by raw single-character reads — relies on
     * readline having put the terminal into a no-echo mode as a side effect, and whether it does
     * depends on which readline the host linked. It was observed suppressing echo on one build and
     * NOT suppressing it on another, with the password appearing verbatim in the terminal. The
     * alternative, shelling out to `stty`, is process execution, which the no-external-action
     * tripwire forbids across this entire codebase — correctly.
     *
     * So there is nothing here to fall back TO, and a fallback that echoes is worse than no
     * interactive path at all: an operator who does not know the password was displayed cannot
     * decide whether the terminal it went to was safe. The command refuses and names the flag.
     *
     * WHAT THE OPERATOR USES INSTEAD is a no-echo primitive that IS proved — their own shell's:
     *
     *     read -rs -p 'Password: ' PW; echo
     *     printf '%s\n' "$PW" | php ops/onboard-beta-user.php ... --password-stdin
     *
     * or a pipe straight from a secret manager, which is better again because the value never
     * enters a shell variable. Either way the secrecy rests on a mechanism somebody can inspect
     * rather than on a library's undocumented side effect.
     *
     * THE VALUE IS NEVER RETURNED ANYWHERE BUT HERE. Callers hand it straight to the service that
     * hashes it. Nothing in either command prints it, logs it, or puts it in the JSON result.
     */
    public static function readPassword(bool $fromStdin, string $purpose, callable $fail): string
    {
        if (!$fromStdin) {
            $fail(
                "a password is required to {$purpose}, and this command reads one only from stdin: "
                . 'pass --password-stdin and pipe it in. There is no interactive prompt, because PHP '
                . 'has no portable way to suppress terminal echo and a prompt that might display the '
                . 'password is worse than none. Use your shell\'s own: '
                . "read -rs PW; printf '%s\\n' \"\$PW\" | php <command> ... --password-stdin"
            );
        }

        $line = fgets(STDIN);
        if ($line === false) {
            $fail('--password-stdin was given but stdin held nothing');
        }

        // Only the trailing newline. Leading and interior whitespace are part of a passphrase, and
        // trimming them would silently accept a different password than the one supplied.
        $value = rtrim((string) $line, "\r\n");
        if ($value === '') {
            $fail('the password read from stdin was empty');
        }

        return $value;
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
