<?php
declare(strict_types=1);

/**
 * DocNumbering — admin-configurable controlled-document numbering.
 *
 * Configuration lives in the `settings` table under key `doc_numbering` as
 * JSON: { "separator": "-", "pad": 4, "prefixes": { "policy": "POL", ... } }.
 * Unset values fall back to the built-in defaults so numbering always works.
 * format() composes a code; nextNumber() finds the next sequence for a prefix.
 */
final class DocNumbering
{
    public const DEFAULT_PREFIXES = [
        'policy' => 'POL', 'procedure' => 'PRC', 'process' => 'PRO', 'standard' => 'STD',
        'guideline' => 'GDL', 'work_instruction' => 'WI', 'plan' => 'PLN', 'form' => 'FRM',
        'template' => 'TPL', 'record' => 'REC', 'evidence' => 'EVD', 'training' => 'TRN',
    ];
    public const DEFAULT_SEPARATOR = '-';
    public const DEFAULT_PAD       = 4;

    private static ?array $cache = null;

    /** Load the merged config (built-in defaults overlaid with saved values). */
    public static function config(): array
    {
        if (self::$cache !== null) return self::$cache;
        $cfg = ['separator' => self::DEFAULT_SEPARATOR, 'pad' => self::DEFAULT_PAD, 'prefixes' => self::DEFAULT_PREFIXES];
        try {
            $row = Database::fetchOne("SELECT value FROM settings WHERE key = 'doc_numbering'");
            $saved = $row ? json_decode((string)$row['value'], true) : null;
            if (is_array($saved)) {
                if (isset($saved['separator'])) $cfg['separator'] = (string)$saved['separator'];
                if (isset($saved['pad']))       $cfg['pad']       = max(1, min(8, (int)$saved['pad']));
                if (isset($saved['prefixes']) && is_array($saved['prefixes'])) {
                    $cfg['prefixes'] = array_merge($cfg['prefixes'], array_filter($saved['prefixes'], 'is_string'));
                }
            }
        } catch (\Throwable) { /* defaults */ }
        return self::$cache = $cfg;
    }

    public static function clearCache(): void { self::$cache = null; }

    /** Persist a numbering config (validates + normalises). */
    public static function save(string $separator, int $pad, array $prefixes): void
    {
        $sep = preg_replace('/[^A-Za-z0-9._\-\/]/', '', $separator);
        if ($sep === '') $sep = self::DEFAULT_SEPARATOR;
        $clean = [];
        foreach (self::DEFAULT_PREFIXES as $type => $def) {
            $p = strtoupper(trim((string)($prefixes[$type] ?? '')));
            $p = preg_replace('/[^A-Z0-9]/', '', $p);
            $clean[$type] = $p !== '' ? substr($p, 0, 10) : $def;
        }
        $json = json_encode(['separator' => $sep, 'pad' => max(1, min(8, $pad)), 'prefixes' => $clean]);
        Database::query(
            "INSERT INTO settings (key, value, type, description, updated_at)
             VALUES ('doc_numbering', ?, 'json', 'Controlled-document numbering scheme', NOW())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()",
            [$json]
        );
        self::clearCache();
    }

    /** The configured prefix for a document type. */
    public static function prefix(string $docType): string
    {
        $cfg = self::config();
        return $cfg['prefixes'][$docType] ?? 'DOC';
    }

    /** Compose a code from a prefix and a sequence number. */
    public static function format(string $prefix, int $n): string
    {
        $cfg = self::config();
        return $prefix . $cfg['separator'] . str_pad((string)$n, (int)$cfg['pad'], '0', STR_PAD_LEFT);
    }

    /**
     * The next document_code for a type — scans existing codes sharing the
     * prefix and increments the highest trailing number.
     */
    public static function next(string $docType): string
    {
        $prefix = self::prefix($docType);
        $sep    = self::config()['separator'];
        $rows = Database::fetchAll(
            "SELECT document_code FROM documents WHERE document_code LIKE ?",
            [$prefix . $sep . '%']
        );
        $max = 0;
        $q = preg_quote($sep, '/');
        foreach ($rows as $r) {
            if (preg_match('/' . $q . '(\d+)$/', (string)$r['document_code'], $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return self::format($prefix, $max + 1);
    }
}
