<?php
declare(strict_types=1);

/**
 * AEGIS test runner — framework-free, dependency-free.
 *
 * Discovers every tests/test_*.php file, runs it, and reports pass/fail.
 * Each test file uses the assert helpers defined below and returns silently
 * on success or calls fail()/assert*() which records a failure.
 *
 * Run:  php tests/run.php
 * Exit: 0 = all passed, 1 = one or more failed.
 */

$GLOBALS['__aegis_tests']  = 0;
$GLOBALS['__aegis_failed'] = [];

function it(string $name, callable $fn): void
{
    $GLOBALS['__aegis_tests']++;
    try {
        $fn();
        fwrite(STDOUT, "  \033[32m✓\033[0m {$name}\n");
    } catch (Throwable $e) {
        $GLOBALS['__aegis_failed'][] = $name . ' — ' . $e->getMessage();
        fwrite(STDOUT, "  \033[31m✗\033[0m {$name} — " . $e->getMessage() . "\n");
    }
}

function expect(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function expect_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg !== '' ? $msg . ': ' : '') .
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

$dir   = __DIR__;
$files = glob($dir . '/test_*.php') ?: [];
fwrite(STDOUT, "\nAEGIS test suite (" . count($files) . " files)\n" . str_repeat('─', 40) . "\n");

foreach ($files as $file) {
    fwrite(STDOUT, "\n" . basename($file) . "\n");
    require $file;
}

$total  = $GLOBALS['__aegis_tests'];
$failed = $GLOBALS['__aegis_failed'];
fwrite(STDOUT, "\n" . str_repeat('─', 40) . "\n");
if ($failed) {
    fwrite(STDOUT, "\033[31m" . count($failed) . " / {$total} failed\033[0m\n");
    foreach ($failed as $f) {
        fwrite(STDOUT, "  - {$f}\n");
    }
    exit(1);
}
fwrite(STDOUT, "\033[32mAll {$total} tests passed\033[0m\n");
exit(0);
