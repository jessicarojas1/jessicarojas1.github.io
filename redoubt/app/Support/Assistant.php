<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Program Assistant — a permission-aware retrieval engine over everything the
 * user is cleared to see (announcements, documents, task orders, jobs,
 * milestones, FAQ, quick links). It ranks matches and returns cited sources with
 * a synthesized answer.
 *
 * This is retrieval + ranking (no external model, works air-gapped). A
 * self-hosted LLM (e.g. Ollama in-enclave, per docs) can later compose the
 * answer from these same permission-trimmed sources — the retrieval boundary
 * here is exactly what such a model must be limited to.
 */
final class Assistant
{
    /** @return array{summary:string,count:int,results:array<int,array<string,mixed>>} */
    public static function ask(array $user, int $programId, string $question): array
    {
        $terms = self::terms($question);
        if ($terms === []) {
            return ['summary' => 'Ask a question about this program.', 'count' => 0, 'results' => []];
        }
        $cand = self::candidates($user, $programId);
        $scored = [];
        foreach ($cand as $c) {
            $score = self::score($terms, (string) $c['title'], (string) $c['text']);
            if ($score > 0) {
                $c['score'] = $score;
                $c['snippet'] = self::snippet((string) ($c['text'] ?: $c['title']), $terms);
                unset($c['text']);
                $scored[] = $c;
            }
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $results = array_slice($scored, 0, 6);

        // Synthesize a short answer: lead with an FAQ answer if one is the top hit.
        $summary = "No matching program information you can access was found for \"" . mb_substr($question, 0, 120) . "\".";
        if ($results !== []) {
            $top = $results[0];
            if ($top['type'] === 'FAQ' && !empty($top['answer'])) {
                $summary = $top['answer'];
            } else {
                $summary = 'Based on ' . count($results) . ' source' . (count($results) === 1 ? '' : 's')
                    . ' you can access, the most relevant is "' . $top['title'] . '" (' . $top['type'] . ').';
            }
        }
        return ['summary' => $summary, 'count' => count($results), 'results' => $results];
    }

    /** @return string[] */
    private static function terms(string $q): array
    {
        $stop = ['the', 'and', 'for', 'are', 'with', 'what', 'when', 'where', 'who', 'how', 'this', 'that', 'from', 'about', 'does', 'can', 'our', 'you', 'your'];
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($q)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 3 && !in_array($w, $stop, true)) {
                $out[$w] = true;
            }
        }
        return array_keys($out);
    }

    /** Permission-trimmed candidate items across modules. @return array<int,array<string,mixed>> */
    private static function candidates(array $user, int $programId): array
    {
        $can = static fn (string $p): bool => Authorize::can($user, $p, ['program_id' => $programId]);
        $items = [];
        $add = static function (array &$items, string $type, string $title, string $text, string $url, array $extra = []): void {
            $items[] = ['type' => $type, 'title' => $title, 'text' => $text, 'url' => $url] + $extra;
        };

        if ($can('announcement.view')) {
            foreach (Announcements::listForUser($user, $programId) as $a) {
                $add($items, 'Announcement', (string) $a['title'], (string) $a['title'] . ' ' . (string) $a['body'], '/app/announcements?program_id=' . $programId);
            }
        }
        if ($can('document.view')) {
            foreach (Documents::listForUser($user, $programId) as $d) {
                $add($items, 'Document', (string) ($d['title'] ?: '(untitled)'), (string) ($d['title'] ?? ''), '/app/documents?program_id=' . $programId);
            }
        }
        if ($can('taskorder.view')) {
            foreach (TaskOrders::listForUser($user, $programId) as $t) {
                $add($items, 'Task Order', $t['number'] . ' — ' . ($t['title'] ?: ''), $t['number'] . ' ' . ($t['title'] ?? ''), '/app/task-orders?program_id=' . $programId);
            }
        }
        if ($can('job.view')) {
            foreach (Jobs::listForUser($user, $programId) as $j) {
                $add($items, 'Job', (string) $j['title'], (string) $j['title'], '/app/jobs?program_id=' . $programId);
            }
        }
        if ($can('milestone.view')) {
            foreach (Milestones::listForProgram($programId) as $m) {
                $add($items, 'Milestone', (string) $m['title'], (string) $m['title'] . ' ' . (string) $m['type'], '/app/milestones?program_id=' . $programId);
            }
        }
        if (isset($user['memberships'][$programId])) {
            foreach (Faq::listForUser($user, $programId, $can('faq.manage')) as $f) {
                $add($items, 'FAQ', (string) $f['question'], (string) $f['question'] . ' ' . (string) $f['answer'], '/app/faq?program_id=' . $programId, ['answer' => $f['answer']]);
            }
            foreach (QuickLinks::listForUser($user, $programId, $can('quicklink.manage')) as $l) {
                $add($items, 'Quick Link', (string) $l['label'], (string) $l['label'], (string) $l['url']);
            }
        }
        return $items;
    }

    /** @param string[] $terms */
    private static function score(array $terms, string $title, string $text): int
    {
        $t = mb_strtolower($title);
        $b = mb_strtolower($text);
        $s = 0;
        foreach ($terms as $term) {
            if (str_contains($t, $term)) {
                $s += 3;
            }
            $s += min(3, substr_count($b, $term));
        }
        return $s;
    }

    /** @param string[] $terms */
    private static function snippet(string $text, array $terms): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $lower = mb_strtolower($text);
        $pos = null;
        foreach ($terms as $term) {
            $p = mb_strpos($lower, $term);
            if ($p !== false) {
                $pos = $p;
                break;
            }
        }
        if ($pos === null) {
            return mb_substr($text, 0, 160) . (mb_strlen($text) > 160 ? '…' : '');
        }
        $start = max(0, $pos - 60);
        $snip = ($start > 0 ? '…' : '') . mb_substr($text, $start, 180);
        return $snip . (mb_strlen($text) > $start + 180 ? '…' : '');
    }
}
