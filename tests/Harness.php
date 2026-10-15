<?php

declare(strict_types=1);

namespace Tests;

/**
 * A minimal test harness.
 *
 * Castle CRM ships with zero Composer dependencies, and that constraint is
 * worth keeping in the test suite too: CI then needs nothing but PHP, which is
 * the same thing the production host needs. This is a few hundred lines that
 * covers what PHPUnit would be used for here — grouping, assertions, a summary
 * and JUnit output for CI.
 */
final class Harness
{
    /** @var list<array{suite:string,name:string,status:string,message:string,time:float}> */
    private static array $results = [];

    private static string $currentSuite = 'default';

    private static float $startedAt = 0.0;

    /** @var list<string> Only run suites matching this filter. */
    private static array $filter = [];

    private static bool $stopOnFailure = false;

    /** @param list<string> $filter */
    public static function configure(array $filter = [], bool $stopOnFailure = false): void
    {
        self::$filter = $filter;
        self::$stopOnFailure = $stopOnFailure;
        self::$startedAt = microtime(true);
    }

    public static function suite(string $name): void
    {
        self::$currentSuite = $name;
    }

    /**
     * Run one test. The callback asserts; a thrown AssertionFailed is a
     * failure, any other throwable is an error (which is reported separately,
     * because an unexpected exception usually means a different bug).
     */
    public static function test(string $name, callable $callback): void
    {
        if (self::$filter !== [] && !self::matchesFilter($name)) {
            return;
        }

        $start = microtime(true);

        try {
            $callback();

            self::record('pass', $name, '', microtime(true) - $start);
        } catch (AssertionFailed $e) {
            self::record('fail', $name, $e->getMessage(), microtime(true) - $start);

            if (self::$stopOnFailure) {
                self::report();
                exit(1);
            }
        } catch (\Throwable $e) {
            self::record(
                'error',
                $name,
                $e::class . ': ' . $e->getMessage() . ' @ '
                    . basename($e->getFile()) . ':' . $e->getLine(),
                microtime(true) - $start
            );

            if (self::$stopOnFailure) {
                self::report();
                exit(1);
            }
        }
    }

    /** Mark a test as skipped with a reason, which is reported but not failed. */
    public static function skip(string $name, string $reason): void
    {
        self::record('skip', $name, $reason, 0.0);
    }

    private static function matchesFilter(string $name): bool
    {
        $haystack = strtolower(self::$currentSuite . ' ' . $name);

        foreach (self::$filter as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private static function record(string $status, string $name, string $message, float $time): void
    {
        self::$results[] = [
            'suite'   => self::$currentSuite,
            'name'    => $name,
            'status'  => $status,
            'message' => $message,
            'time'    => $time,
        ];

        // Stream progress so a hanging test is visible in CI output.
        $glyph = match ($status) {
            'pass'  => '.',
            'fail'  => 'F',
            'error' => 'E',
            default => 's',
        };

        echo $glyph;

        if (count(self::$results) % 72 === 0) {
            echo "\n";
        }
    }

    /**
     * How many tests have run.
     *
     * The runner uses this for its "green but empty" guard: a suite that
     * silently skips everything would otherwise report success having tested
     * nothing at all.
     */
    public static function testCount(): int
    {
        return count(self::$results);
    }

    /**
     * Print the summary and return the process exit code.
     */
    public static function report(?string $junitPath = null): int
    {
        $counts = ['pass' => 0, 'fail' => 0, 'error' => 0, 'skip' => 0];

        foreach (self::$results as $result) {
            $counts[$result['status']]++;
        }

        $elapsed = microtime(true) - self::$startedAt;

        echo "\n\n";

        // Failures first and in full: the summary line is useless if you have
        // to scroll past 400 dots to find what broke.
        $problems = array_values(array_filter(
            self::$results,
            static fn (array $r): bool => in_array($r['status'], ['fail', 'error'], true)
        ));

        if ($problems !== []) {
            echo self::colour("FAILURES\n", '1;31');
            echo str_repeat('─', 72) . "\n";

            foreach ($problems as $i => $problem) {
                printf(
                    "%s%d) [%s] %s\n   %s\n\n",
                    $problem['status'] === 'error' ? 'ERROR ' : '',
                    $i + 1,
                    $problem['suite'],
                    $problem['name'],
                    $problem['message']
                );
            }
        }

        $skipped = array_values(array_filter(
            self::$results,
            static fn (array $r): bool => $r['status'] === 'skip'
        ));

        if ($skipped !== []) {
            echo self::colour("SKIPPED\n", '1;33');

            foreach ($skipped as $skip) {
                printf("   [%s] %s — %s\n", $skip['suite'], $skip['name'], $skip['message']);
            }

            echo "\n";
        }

        // Per-suite breakdown, so it is obvious which area is unhealthy.
        $bySuite = [];

        foreach (self::$results as $result) {
            $bySuite[$result['suite']][$result['status']] =
                ($bySuite[$result['suite']][$result['status']] ?? 0) + 1;
        }

        echo self::colour("BY SUITE\n", '1');

        foreach ($bySuite as $suite => $statuses) {
            printf(
                "   %-32s %3d passed%s%s\n",
                $suite,
                $statuses['pass'] ?? 0,
                ($statuses['fail'] ?? 0) > 0 ? ', ' . $statuses['fail'] . ' FAILED' : '',
                ($statuses['error'] ?? 0) > 0 ? ', ' . $statuses['error'] . ' ERRORED' : ''
            );
        }

        echo "\n" . str_repeat('─', 72) . "\n";

        $failed = $counts['fail'] + $counts['error'];

        printf(
            "%s  %d tests, %d assertions passed, %d failed, %d errored, %d skipped in %.2fs\n",
            $failed === 0
                ? self::colour(' PASS ', '42;30')
                : self::colour(' FAIL ', '41;37'),
            count(self::$results),
            $counts['pass'],
            $counts['fail'],
            $counts['error'],
            $counts['skip'],
            $elapsed
        );

        if ($junitPath !== null) {
            self::writeJunit($junitPath, $elapsed);
        }

        return $failed === 0 ? 0 : 1;
    }

    private static function writeJunit(string $path, float $elapsed): void
    {
        $bySuite = [];

        foreach (self::$results as $result) {
            $bySuite[$result['suite']][] = $result;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= sprintf(
            '<testsuites name="Castle CRM" tests="%d" time="%.4f">' . "\n",
            count(self::$results),
            $elapsed
        );

        foreach ($bySuite as $suite => $tests) {
            $failures = count(array_filter($tests, static fn (array $t): bool => $t['status'] === 'fail'));
            $errors   = count(array_filter($tests, static fn (array $t): bool => $t['status'] === 'error'));
            $skips    = count(array_filter($tests, static fn (array $t): bool => $t['status'] === 'skip'));

            $xml .= sprintf(
                '  <testsuite name="%s" tests="%d" failures="%d" errors="%d" skipped="%d">' . "\n",
                self::xml($suite),
                count($tests),
                $failures,
                $errors,
                $skips
            );

            foreach ($tests as $test) {
                $xml .= sprintf(
                    '    <testcase name="%s" classname="%s" time="%.4f"',
                    self::xml($test['name']),
                    self::xml($suite),
                    $test['time']
                );

                if ($test['status'] === 'pass') {
                    $xml .= "/>\n";
                    continue;
                }

                $xml .= ">\n";

                $tag = match ($test['status']) {
                    'fail'  => 'failure',
                    'error' => 'error',
                    default => 'skipped',
                };

                $xml .= sprintf(
                    '      <%s message="%s"/>' . "\n",
                    $tag,
                    self::xml($test['message'])
                );

                $xml .= "    </testcase>\n";
            }

            $xml .= "  </testsuite>\n";
        }

        $xml .= "</testsuites>\n";

        @file_put_contents($path, $xml);
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Colour only when writing to a terminal that wants it. */
    private static function colour(string $text, string $code): string
    {
        static $supported = null;

        if ($supported === null) {
            $supported = getenv('NO_COLOR') === false
                && (getenv('CI') !== false || (function_exists('posix_isatty') && @posix_isatty(STDOUT)));
        }

        return $supported ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}
