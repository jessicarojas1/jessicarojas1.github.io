<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * What changed between two revisions (ADR-0009 "structural comparison"): metadata field by
 * field, the attached file by fingerprint, and editor content block by block, with a
 * word-level diff inside blocks that were edited.
 *
 * Blocks are the top-level nodes of the canonical body (headings, paragraphs, whole lists,
 * whole tables). They are matched by their exact canonical JSON with a longest-common-
 * subsequence diff; a removed block directly followed by an added block of the same type is
 * reported as "changed". Pure computation: callers handle access and loading.
 */
final class RevisionComparer
{
    /** Above these sizes the diff falls back to coarser output instead of quadratic work. */
    private const MAX_BLOCKS = 2_000;
    private const MAX_WORDS = 1_500;
    /**
     * Total LCS table cells one comparison may compute (block diff plus every word diff).
     * Bounds CPU per request; the largest single table (2,000² cells) bounds memory.
     */
    private const CELL_BUDGET = 6_000_000;

    /** Remaining cells for the comparison in progress; null outside compareBodies(). */
    private static ?int $budget = null;

    /**
     * @param array{title: string, description: ?string, keywords: list<string>, changeSummary: ?string, file: ?array{sha256: string, filename: string}, body: ?array<string, mixed>} $before
     * @param array{title: string, description: ?string, keywords: list<string>, changeSummary: ?string, file: ?array{sha256: string, filename: string}, body: ?array<string, mixed>} $after
     *
     * @return array{fields: list<array{field: string, before: mixed, after: mixed}>, file: array{changed: bool, before: ?array{sha256: string, filename: string}, after: ?array{sha256: string, filename: string}}, body: array{blocks: list<array<string, mixed>>, truncated: bool}}
     */
    public static function compare(array $before, array $after): array
    {
        $fields = [];
        foreach (['title', 'description', 'keywords', 'changeSummary'] as $field) {
            if ($before[$field] !== $after[$field]) {
                $fields[] = ['field' => $field, 'before' => $before[$field], 'after' => $after[$field]];
            }
        }

        return [
            'fields' => $fields,
            'file' => [
                'changed' => ($before['file']['sha256'] ?? null) !== ($after['file']['sha256'] ?? null)
                    || ($before['file']['filename'] ?? null) !== ($after['file']['filename'] ?? null),
                'before' => $before['file'],
                'after' => $after['file'],
            ],
            'body' => self::compareBodies($before['body'], $after['body']),
        ];
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     *
     * @return array{blocks: list<array<string, mixed>>, truncated: bool}
     */
    public static function compareBodies(?array $before, ?array $after): array
    {
        self::$budget = self::CELL_BUDGET;
        try {
            return self::diffBodies($before, $after);
        } finally {
            self::$budget = null;
        }
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     *
     * @return array{blocks: list<array<string, mixed>>, truncated: bool}
     */
    private static function diffBodies(?array $before, ?array $after): array
    {
        $a = self::blocks($before);
        $b = self::blocks($after);
        $raw = \count($a) > self::MAX_BLOCKS || \count($b) > self::MAX_BLOCKS
            ? null
            : self::lcsDiff(array_map(self::key(...), $a), array_map(self::key(...), $b));
        if (null === $raw) {
            // Too large for a block diff: everything old removed, everything new added.
            $ops = [...array_map(fn (array $n): array => ['op' => 'removed', 'node' => $n], $a), ...array_map(fn (array $n): array => ['op' => 'added', 'node' => $n], $b)];

            return ['blocks' => $ops, 'truncated' => true];
        }

        $ops = [];
        foreach ($raw as [$op, $i, $j]) {
            $ops[] = match ($op) {
                'same' => ['op' => 'same', 'node' => $b[$j]],
                'removed' => ['op' => 'removed', 'node' => $a[$i]],
                default => ['op' => 'added', 'node' => $b[$j]],
            };
        }

        return ['blocks' => self::pairChanges($ops), 'truncated' => false];
    }

    /**
     * Word-level diff of two strings, as runs of {op, text}. Whitespace is kept with the word
     * before it so the runs join back into the original text.
     *
     * @return list<array{op: 'same'|'added'|'removed', text: string}>
     */
    public static function words(string $before, string $after): array
    {
        $a = self::tokens($before);
        $b = self::tokens($after);
        $diff = \count($a) > self::MAX_WORDS || \count($b) > self::MAX_WORDS ? null : self::lcsDiff($a, $b);
        if (null === $diff) {
            // Too long, or this comparison's work budget is spent: show old and new whole.
            return self::merge([['removed', $before], ['added', $after]]);
        }
        $runs = [];
        foreach ($diff as [$op, $i, $j]) {
            $runs[] = [$op, 'removed' === $op ? $a[$i] : $b[$j]];
        }

        return self::merge($runs);
    }

    /**
     * Plain text of one node (for the word diff of a changed block).
     *
     * @param array<mixed> $node
     */
    public static function text(array $node): string
    {
        if ('text' === ($node['type'] ?? null)) {
            return \is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if ('hardBreak' === ($node['type'] ?? null)) {
            return "\n";
        }
        $parts = [];
        foreach (\is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (\is_array($child)) {
                $parts[] = self::text($child);
            }
        }
        $inline = \in_array($node['type'] ?? null, ['paragraph', 'heading'], true);

        return implode($inline ? '' : "\n", $parts);
    }

    /**
     * Link addresses in a node, in document order.
     *
     * @param array<mixed> $node
     *
     * @return list<string>
     */
    public static function links(array $node): array
    {
        $found = [];
        foreach (\is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
            if (\is_array($mark) && 'link' === ($mark['type'] ?? null) && \is_array($mark['attrs'] ?? null) && \is_string($mark['attrs']['href'] ?? null)) {
                $found[] = $mark['attrs']['href'];
            }
        }
        foreach (\is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (\is_array($child)) {
                $found = [...$found, ...self::links($child)];
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return list<array<mixed>>
     */
    private static function blocks(?array $body): array
    {
        $blocks = [];
        foreach (\is_array($body['content'] ?? null) ? $body['content'] : [] as $node) {
            if (\is_array($node)) {
                $blocks[] = $node;
            }
        }

        return $blocks;
    }

    /**
     * @param array<mixed> $node
     */
    private static function key(array $node): string
    {
        return json_encode($node, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * A run of removed blocks followed by a run of added blocks: pair them up in order when
     * their types match, and report each pair as one "changed" block with a word diff.
     *
     * @param list<array<string, mixed>> $ops
     *
     * @return list<array<string, mixed>>
     */
    private static function pairChanges(array $ops): array
    {
        $out = [];
        $n = \count($ops);
        for ($k = 0; $k < $n;) {
            if ('removed' !== $ops[$k]['op']) {
                $out[] = $ops[$k++];
                continue;
            }
            $removed = [];
            while ($k < $n && 'removed' === $ops[$k]['op']) {
                $removed[] = $ops[$k++];
            }
            $added = [];
            while ($k < $n && 'added' === $ops[$k]['op']) {
                $added[] = $ops[$k++];
            }
            $pairs = min(\count($removed), \count($added));
            for ($p = 0; $p < $pairs; ++$p) {
                $old = $removed[$p]['node'];
                $new = $added[$p]['node'];
                if (\is_array($old) && \is_array($new) && ($old['type'] ?? null) === ($new['type'] ?? null)) {
                    $change = ['op' => 'changed', 'before' => $old, 'node' => $new, 'words' => self::words(self::text($old), self::text($new))];
                    // Link targets are not words: list them so a swapped address is never invisible.
                    $linksBefore = self::links($old);
                    $linksAfter = self::links($new);
                    if ($linksBefore !== $linksAfter) {
                        $change['links'] = ['before' => $linksBefore, 'after' => $linksAfter];
                    }
                    $out[] = $change;
                } else {
                    $out[] = $removed[$p];
                    $out[] = $added[$p];
                }
            }
            foreach (\array_slice($removed, $pairs) as $rest) {
                $out[] = $rest;
            }
            foreach (\array_slice($added, $pairs) as $rest) {
                $out[] = $rest;
            }
        }

        return $out;
    }

    /**
     * Longest-common-subsequence diff of two key lists, in order, as [op, indexA, indexB].
     * Removals are emitted before additions at the same point, so replacements pair up.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: 'same'|'removed'|'added', 1: int, 2: int}>|null null when the work budget does not allow it
     */
    private static function lcsDiff(array $a, array $b): ?array
    {
        $n = \count($a);
        $m = \count($b);
        if (null !== self::$budget) {
            $cells = ($n + 1) * ($m + 1);
            if ($cells > self::$budget) {
                return null;
            }
            self::$budget -= $cells;
        }
        // Suffix LCS lengths, one row at a time kept in full (n*m ints; inputs are capped).
        $len = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $len[$i][$j] = $a[$i] === $b[$j] ? $len[$i + 1][$j + 1] + 1 : max($len[$i + 1][$j], $len[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['same', $i++, $j++];
            } elseif ($len[$i + 1][$j] >= $len[$i][$j + 1]) {
                $ops[] = ['removed', $i++, $j];
            } else {
                $ops[] = ['added', $i, $j++];
            }
        }
        while ($i < $n) {
            $ops[] = ['removed', $i++, $j];
        }
        while ($j < $m) {
            $ops[] = ['added', $i, $j++];
        }

        return $ops;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        if ('' === $text) {
            return [];
        }
        preg_match_all('/\S+\s*|\s+/u', $text, $matches);

        return $matches[0];
    }

    /**
     * @param list<array{0: string, 1: string}> $runs
     *
     * @return list<array{op: 'same'|'added'|'removed', text: string}>
     */
    private static function merge(array $runs): array
    {
        $out = [];
        foreach ($runs as [$op, $text]) {
            if ('' === $text) {
                continue;
            }
            $op = \in_array($op, ['same', 'added', 'removed'], true) ? $op : 'same';
            $last = \count($out) - 1;
            if ($last >= 0 && $out[$last]['op'] === $op) {
                $out[$last]['text'] .= $text;
            } else {
                $out[] = ['op' => $op, 'text' => $text];
            }
        }

        return $out;
    }
}
