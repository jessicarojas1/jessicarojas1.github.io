<?php
/**
 * Diff — minimal line-level diff (LCS) for comparing page revisions.
 * HTML bodies are flattened to text lines first, then diffed.
 */
final class Diff {

    /** Flatten HTML to an array of trimmed, non-empty text lines (block-aware). */
    public static function htmlToLines(?string $html): array {
        $s = (string)$html;
        // Turn block boundaries into newlines so structure survives stripping
        $s = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote|pre|details|summary)>#i', "\n", $s);
        $s = preg_replace('#<br\s*/?>#i', "\n", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_split('/\r\n|\r|\n/', $s);
        $out = [];
        foreach ($lines as $l) { $l = trim($l); if ($l !== '') $out[] = $l; }
        return $out;
    }

    /**
     * Line diff between two arrays. Returns a list of
     * ['type' => 'eq'|'add'|'del', 'text' => string].
     */
    public static function lines(array $a, array $b): array {
        $n = count($a); $m = count($b);
        // LCS length table
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $out = []; $i = 0; $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) { $out[] = ['type' => 'eq', 'text' => $a[$i]]; $i++; $j++; }
            elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) { $out[] = ['type' => 'del', 'text' => $a[$i]]; $i++; }
            else { $out[] = ['type' => 'add', 'text' => $b[$j]]; $j++; }
        }
        while ($i < $n) { $out[] = ['type' => 'del', 'text' => $a[$i++]]; }
        while ($j < $m) { $out[] = ['type' => 'add', 'text' => $b[$j++]]; }
        return $out;
    }

    /** Convenience: counts of added/removed lines. */
    public static function stats(array $diff): array {
        $add = 0; $del = 0;
        foreach ($diff as $d) { if ($d['type'] === 'add') $add++; elseif ($d['type'] === 'del') $del++; }
        return ['added' => $add, 'removed' => $del];
    }
}
