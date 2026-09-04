<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

/**
 * A minimal assertion + suite runner.
 *
 * PHPUnit is not used: Loopia hosts no dev dependencies, `composer check` must run with only the
 * PHP the host actually has, and the conformance suite has to be runnable on the deployment host
 * itself when a capability question arises there. The runner is therefore part of the deliverable
 * rather than a build-time tool.
 *
 * A skipped test is reported LOUDLY and counted separately. A suite that cannot reach MariaDB
 * reports SKIP, never PASS — inventing a pass is the failure mode this whole repository exists to
 * make impossible.
 */
final class Assert
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;
    /** @var list<string> */
    public static array $failures = [];
    /** @var list<string> */
    public static array $skips = [];
    private static string $currentCase = '';

    public static function case(string $name): void
    {
        self::$currentCase = $name;
    }

    public static function pass(): void
    {
        self::$passed++;
    }

    public static function fail(string $message): void
    {
        self::$failed++;
        self::$failures[] = self::$currentCase . ': ' . $message;
    }

    public static function skip(string $reason): void
    {
        self::$skipped++;
        self::$skips[] = self::$currentCase . ': ' . $reason;
    }

    public static function true(bool $condition, string $message): void
    {
        $condition ? self::pass() : self::fail($message);
    }

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected === $actual) {
            self::pass();

            return;
        }
        self::fail(sprintf('%s — expected %s, got %s', $message, self::render($expected), self::render($actual)));
    }

    public static function notSame(mixed $unexpected, mixed $actual, string $message): void
    {
        if ($unexpected !== $actual) {
            self::pass();

            return;
        }
        self::fail(sprintf('%s — expected NOT %s', $message, self::render($unexpected)));
    }

    /** @param list<string> $expected */
    public static function equalsList(array $expected, mixed $actual, string $message): void
    {
        self::same(json_encode($expected), json_encode($actual), $message);
    }

    public static function contains(string $needle, string $haystack, string $message): void
    {
        self::true(str_contains($haystack, $needle), sprintf('%s — %s not found in %s', $message, $needle, $haystack));
    }

    /** Assert that `$work` throws, and that the thrown value matches `$expect`. */
    public static function throws(callable $work, string $expectedClass, string $message): ?\Throwable
    {
        try {
            $work();
        } catch (\Throwable $error) {
            if ($error instanceof $expectedClass) {
                self::pass();

                return $error;
            }
            self::fail(sprintf('%s — expected %s, got %s: %s', $message, $expectedClass, $error::class, $error->getMessage()));

            return $error;
        }
        self::fail($message . ' — nothing was thrown');

        return null;
    }

    private static function render(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . $value . "'";
        }

        return (string) json_encode($value);
    }

    public static function reset(): void
    {
        self::$passed = 0;
        self::$failed = 0;
        self::$skipped = 0;
        self::$failures = [];
        self::$skips = [];
    }
}
