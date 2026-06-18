<?php
declare(strict_types=1);

/**
 * Csv — spreadsheet (CSV) export safety.
 *
 * Neutralizes formula / CSV injection (a.k.a. "CSV formula injection"): a cell
 * whose value begins with =, +, -, @, TAB, or CR is interpreted as a formula by
 * Excel / Google Sheets / LibreOffice and can exfiltrate data or run commands
 * when the exported file is opened. Prefixing such a value with a single quote
 * forces it to be treated as text.
 *
 * Centralizes the guard that was previously an inline closure in AdminController
 * and missing entirely from ExportController. Pure — unit-tested.
 */
final class Csv
{
    /** Neutralize a single cell value for safe inclusion in a CSV export. */
    public static function cell(mixed $value): string
    {
        $s = (string)($value ?? '');
        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
    }

    /** Apply cell() to every value in a row. */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], $row);
    }
}
