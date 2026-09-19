<?php

declare(strict_types=1);

/**
 * Minimal zero-dependency test harness for REDOUBT.
 *
 * No Composer/PHPUnit requirement so the suite runs anywhere php-cli exists
 * (dev workstation, container, CI). Test files call T::ok()/T::eq()/T::skip();
 * tests/run.php includes them and exits non-zero if anything failed.
 */
final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static int $skip = 0;
    /** @var string[] */
    public static array $failures = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n== {$name} ==\n";
    }

    public static function ok(string $label, bool $cond): void
    {
        if ($cond) {
            self::$pass++;
            echo "  ok   {$label}\n";
        } else {
            self::$fail++;
            self::$failures[] = self::$group . ' :: ' . $label;
            echo "  FAIL {$label}\n";
        }
    }

    public static function eq(string $label, mixed $expected, mixed $actual): void
    {
        $cond = $expected === $actual;
        if (!$cond) {
            $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
        }
        self::ok($label, $cond);
    }

    public static function skip(string $label): void
    {
        self::$skip++;
        echo "  skip {$label}\n";
    }

    /** Print summary and return a process exit code. */
    public static function summary(): int
    {
        echo "\n----------------------------------------\n";
        echo 'RESULT: ' . self::$pass . ' passed, ' . self::$fail . ' failed, ' . self::$skip . " skipped\n";
        if (self::$failures !== []) {
            echo "Failures:\n";
            foreach (self::$failures as $f) {
                echo "  - {$f}\n";
            }
        }
        return self::$fail === 0 ? 0 : 1;
    }
}
