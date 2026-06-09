<?php
declare(strict_types=1);

/**
 * PageTasks — extracts inline tasks (action items) from a page body and keeps
 * them in sync in the page_tasks table.
 *
 * Two authoring styles are recognised:
 *   1. Checkbox lists (the editor's Task list macro): <input type="checkbox">
 *      inside a list item; `checked` marks it done.
 *   2. Plain markers: a list item / paragraph whose text starts with "[ ]" or
 *      "[x]" (as produced by the blueprints / Markdown import).
 *
 * On sync(), tasks are re-parsed and reconciled with what's stored: a task's
 * assignee, due date and completion are preserved across edits by matching on
 * a hash of its normalised text.
 */
final class PageTasks
{
    /** @return array<int,array{text:string,done:bool}> in document order */
    public static function parse(string $html): array
    {
        if (trim($html) === '') return [];
        $out = [];
        $seen = [];
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        // 1. Checkbox-based tasks.
        foreach ($xp->query('//input[@type="checkbox"]') as $cb) {
            /** @var \DOMElement $cb */
            $host = $cb->parentNode;
            // Climb to the enclosing li/label/p for the task text.
            while ($host && !in_array(strtolower($host->nodeName), ['li', 'label', 'p', 'div'], true)) {
                $host = $host->parentNode;
            }
            $text = self::clean($host ? $host->textContent : '');
            if ($text === '') continue;
            $done = $cb->hasAttribute('checked');
            $key = mb_strtolower($text);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['text' => $text, 'done' => $done];
        }

        // 2. Plain "[ ] / [x]" markers in list items or paragraphs.
        foreach ($xp->query('//li | //p') as $node) {
            $raw = self::clean($node->textContent);
            if (preg_match('/^\[([ xX])\]\s*(.+)$/u', $raw, $m)) {
                $text = self::clean($m[2]);
                if ($text === '') continue;
                $key = mb_strtolower($text);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $out[] = ['text' => $text, 'done' => strtolower($m[1]) === 'x'];
            }
        }
        return $out;
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    public static function hash(string $text): string
    {
        return substr(sha1(mb_strtolower(self::clean($text))), 0, 40);
    }

    /** Re-parse a page body and reconcile page_tasks, preserving per-task state. */
    public static function sync(int $pageId, string $html): void
    {
        $parsed = self::parse($html);
        try {
            $existing = Database::fetchAll("SELECT * FROM page_tasks WHERE page_id = ?", [$pageId]);
        } catch (\Throwable) { return; }
        $byHash = [];
        foreach ($existing as $e) { $byHash[$e['text_hash']] = $e; }

        Database::query("DELETE FROM page_tasks WHERE page_id = ?", [$pageId]);
        $seq = 0;
        foreach ($parsed as $t) {
            $h = self::hash($t['text']);
            $prev = $byHash[$h] ?? null;
            // Done state: a prior completion (via checkbox or the action-items
            // panel) sticks even if the body marker still reads "[ ]".
            $done = $t['done'] || ($prev && in_array(strtolower((string)$prev['done']), ['1','t','true'], true));
            Database::insert('page_tasks', [
                'page_id'     => $pageId,
                'seq'         => $seq++,
                'text'        => mb_substr($t['text'], 0, 2000),
                'text_hash'   => $h,
                'assignee_id' => $prev['assignee_id'] ?? null,
                'due_date'    => $prev['due_date'] ?? null,
                'done'        => $done ? 't' : 'f',
                'done_at'     => $done ? ($prev['done_at'] ?? date('Y-m-d H:i:s')) : null,
                'done_by'     => $done ? ($prev['done_by'] ?? null) : null,
            ]);
        }
    }

    public static function forPage(int $pageId): array
    {
        return Database::fetchAll(
            "SELECT pt.*, u.name AS assignee_name FROM page_tasks pt
             LEFT JOIN users u ON u.id = pt.assignee_id
             WHERE pt.page_id = ? ORDER BY pt.seq",
            [$pageId]
        );
    }

    public static function openCount(int $pageId): int
    {
        return (int)(Database::fetchOne("SELECT COUNT(*) c FROM page_tasks WHERE page_id = ? AND done = FALSE", [$pageId])['c'] ?? 0);
    }
}
