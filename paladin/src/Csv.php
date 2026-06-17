<?php
declare(strict_types=1);

/**
 * Csv — safe CSV output helper.
 *
 * Centralises protection against spreadsheet **formula / CSV injection**
 * (CWE-1236): a cell whose value begins with a formula trigger (= + - @, or a
 * leading tab/CR) can execute when the file is opened in Excel / Google Sheets /
 * LibreOffice. We neutralise such cells by prefixing a single quote, which
 * spreadsheet apps treat as "literal text". Genuine numbers (including negative
 * numbers like -5 and +44) are left untouched so numeric columns stay numeric.
 *
 * Always write rows through Csv::put() so every export is protected uniformly.
 */
final class Csv {

    /** Characters that make a spreadsheet treat the cell as a formula. */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /** Neutralise a single cell value. */
    public static function cell(mixed $value): string {
        $s = (string)$value;
        if ($s === '') { return $s; }
        // Leave real numbers alone (-5, +44, 3.14) — only guard formula-shaped text.
        if (in_array($s[0], self::TRIGGERS, true) && !is_numeric(trim($s))) {
            return "'" . $s;
        }
        return $s;
    }

    /** Guard every cell in a row. */
    public static function row(array $cells): array {
        return array_map([self::class, 'cell'], $cells);
    }

    /** Write a formula-injection-safe CSV row to an open stream. */
    public static function put($handle, array $cells): void {
        fputcsv($handle, self::row($cells));
    }
}
