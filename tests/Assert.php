<?php

declare(strict_types=1);

namespace Tests;

/**
 * Thrown by a failed assertion. Distinguished from an ordinary exception so
 * the harness can report "this test failed" separately from "this test blew
 * up", which usually means a different bug.
 */
final class AssertionFailed extends \Exception
{
}

/**
 * Assertions.
 *
 * Every message says what was expected and what actually happened, because a
 * failure message that only says "assertion failed" costs more time than the
 * test saves.
 */
final class Assert
{
    public static function true(mixed $value, string $because = ''): void
    {
        if ($value !== true) {
            self::fail('Expected true, got ' . self::describe($value), $because);
        }
    }

    public static function false(mixed $value, string $because = ''): void
    {
        if ($value !== false) {
            self::fail('Expected false, got ' . self::describe($value), $because);
        }
    }

    public static function same(mixed $expected, mixed $actual, string $because = ''): void
    {
        if ($expected !== $actual) {
            self::fail(
                'Expected ' . self::describe($expected) . ', got ' . self::describe($actual),
                $because
            );
        }
    }

    public static function notSame(mixed $unexpected, mixed $actual, string $because = ''): void
    {
        if ($unexpected === $actual) {
            self::fail('Expected anything but ' . self::describe($unexpected), $because);
        }
    }

    public static function equals(mixed $expected, mixed $actual, string $because = ''): void
    {
        // Loose on purpose: database drivers return "1" where PHP wrote 1.
        if ($expected != $actual) {
            self::fail(
                'Expected ' . self::describe($expected) . ' (loosely), got ' . self::describe($actual),
                $because
            );
        }
    }

    public static function null(mixed $value, string $because = ''): void
    {
        if ($value !== null) {
            self::fail('Expected null, got ' . self::describe($value), $because);
        }
    }

    public static function notNull(mixed $value, string $because = ''): void
    {
        if ($value === null) {
            self::fail('Expected a value, got null', $because);
        }
    }

    public static function contains(string $needle, string $haystack, string $because = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            self::fail(
                'Expected to find ' . self::describe($needle) . ' in: ' . self::truncate($haystack),
                $because
            );
        }
    }

    public static function notContains(string $needle, string $haystack, string $because = ''): void
    {
        if (str_contains($haystack, $needle)) {
            self::fail(
                'Did not expect to find ' . self::describe($needle) . ' in: ' . self::truncate($haystack),
                $because
            );
        }
    }

    public static function matches(string $pattern, string $subject, string $because = ''): void
    {
        if (preg_match($pattern, $subject) !== 1) {
            self::fail(
                'Expected ' . self::truncate($subject) . ' to match ' . $pattern,
                $because
            );
        }
    }

    public static function count(int $expected, array|\Countable $value, string $because = ''): void
    {
        $actual = count($value);

        if ($actual !== $expected) {
            self::fail('Expected ' . $expected . ' items, got ' . $actual, $because);
        }
    }

    public static function greaterThan(int|float $floor, int|float $actual, string $because = ''): void
    {
        if ($actual <= $floor) {
            self::fail('Expected something greater than ' . $floor . ', got ' . $actual, $because);
        }
    }

    public static function atLeast(int|float $floor, int|float $actual, string $because = ''): void
    {
        if ($actual < $floor) {
            self::fail('Expected at least ' . $floor . ', got ' . $actual, $because);
        }
    }

    /** @param array<array-key,mixed> $haystack */
    public static function hasKey(string|int $key, array $haystack, string $because = ''): void
    {
        if (!array_key_exists($key, $haystack)) {
            self::fail(
                'Expected key ' . self::describe($key) . '; present: '
                    . implode(', ', array_map('strval', array_keys($haystack))),
                $because
            );
        }
    }

    /** @param array<array-key,mixed> $haystack */
    public static function inArray(mixed $needle, array $haystack, string $because = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            self::fail('Expected ' . self::describe($needle) . ' to be in the list', $because);
        }
    }

    public static function empty(mixed $value, string $because = ''): void
    {
        if (!empty($value)) {
            self::fail('Expected something empty, got ' . self::describe($value), $because);
        }
    }

    public static function notEmpty(mixed $value, string $because = ''): void
    {
        if (empty($value)) {
            self::fail('Expected something non-empty, got ' . self::describe($value), $because);
        }
    }

    /**
     * Assert that a callable throws. Returns the throwable so the caller can
     * assert on it further.
     */
    public static function throws(string $expectedClass, callable $callback, string $because = ''): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $expectedClass) {
                self::fail(
                    'Expected ' . $expectedClass . ', got ' . $e::class . ': ' . $e->getMessage(),
                    $because
                );
            }

            return $e;
        }

        self::fail('Expected ' . $expectedClass . ' to be thrown, but nothing was', $because);
    }

    /** Assert that a callable does NOT throw. */
    public static function doesNotThrow(callable $callback, string $because = ''): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            self::fail('Expected no exception, got ' . $e::class . ': ' . $e->getMessage(), $because);
        }
    }

    public static function fail(string $message, string $because = ''): never
    {
        throw new AssertionFailed($because === '' ? $message : $because . ' — ' . $message);
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null  => 'null',
            $value === true  => 'true',
            $value === false => 'false',
            is_string($value) => '"' . self::truncate($value) . '"',
            is_array($value)  => 'array(' . count($value) . ')',
            is_object($value) => $value::class,
            default           => (string) $value,
        };
    }

    private static function truncate(string $value, int $length = 300): string
    {
        $value = str_replace(["\n", "\r"], ['\n', ''], $value);

        return strlen($value) <= $length ? $value : substr($value, 0, $length) . '…';
    }
}
