#!/usr/bin/env php
<?php
/**
 * CSV export safety linter.
 *
 * Flags fputcsv() calls that write a bare data row ($variable) without routing
 * it through Csv::row()/Csv::cell() (the formula-injection guard). This is the
 * exact gap that left ExportController vulnerable: DB rows written straight to
 * fputcsv execute as formulas when opened in Excel/Sheets.
 *
 * Safe and therefore ignored:
 *   - header rows written from a literal array or array_keys(...)
 *   - rows already wrapped in Csv::row(...) / Csv::cell(...)
 *   - lines annotated with a "csv-safe:" justification comment (e.g. an import
 *     round-trip to a temp file that is re-parsed, never delivered to a user)
 *
 * Usage:  php scripts/check_csv_export.php
 * Exit:   0 = all data-row writes guarded/justified, 1 = unguarded writes found.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$root = dirname(__DIR__);
$gaps = [];
$scanned = 0;

foreach (['controllers', 'src'] as $dir) {
    foreach (glob("{$root}/{$dir}/*.php") ?: [] as $file) {
        $rel = "{$dir}/" . basename($file);
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line) {
            if (!str_contains($line, 'fputcsv')) continue;
            $scanned++;
            // Justified exceptions.
            if (str_contains($line, 'Csv::') || stripos($line, 'csv-safe:') !== false) continue;
            // Flag when the 2nd argument is a bare variable (a data row).
            // Header writes use a literal array `[...]` or array_keys(...), which
            // contain no user-controlled leading formula characters.
            if (preg_match('/fputcsv\s*\(\s*\$\w+\s*,\s*\$\w+\b/', $line)) {
                $gaps[] = sprintf('%s:%d  %s', $rel, $i + 1, trim($line));
            }
        }
    }
}

echo "fputcsv call sites scanned: {$scanned}\n";

if ($gaps) {
    echo "\nUnguarded CSV data-row writes (formula-injection risk):\n";
    foreach ($gaps as $g) echo "  [ERROR] {$g}\n";
    echo "\nWrap the row in Csv::row(\$row), or annotate with a 'csv-safe:' justification.\n";
    exit(1);
}
echo "\nOK — every CSV data-row write is guarded by Csv:: or justified.\n";
exit(0);
