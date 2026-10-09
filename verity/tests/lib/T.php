<?php

declare(strict_types=1);

/** Zero-dependency assertion harness — no PHPUnit required to run the suite. */
final class T
{
    private static string $group = '';
    private static int $pass = 0;
    private static int $fail = 0;
    private static int $skip = 0;

    public static function group(string $name): void
    {
        self::$group = $name;
        fwrite(STDOUT, "\n== $name ==\n");
    }

    public static function ok(bool $condition, string $label): void
    {
        if ($condition) {
            self::$pass++;
            fwrite(STDOUT, "  PASS  $label\n");
        } else {
            self::$fail++;
            fwrite(STDOUT, "  FAIL  $label\n");
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $label): void
    {
        self::ok($expected === $actual, $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }

    public static function skip(string $label): void
    {
        self::$skip++;
        fwrite(STDOUT, "  SKIP  $label\n");
    }

    public static function summary(): int
    {
        fwrite(STDOUT, "\n" . str_repeat('-', 40) . "\n");
        fwrite(STDOUT, sprintf("%d passed, %d failed, %d skipped\n", self::$pass, self::$fail, self::$skip));
        return self::$fail > 0 ? 1 : 0;
    }
}
