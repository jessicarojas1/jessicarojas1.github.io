<?php
/**
 * View — small presentation helpers shared across templates (status badges,
 * doc-type labels, date/relative-time formatting). All output is escaped.
 */
final class View {

    /** Map an entity status to a coloured .badge.* class + label. */
    public static function statusBadge(string $status): string {
        $map = [
            'draft'      => ['badge-gray',    'Draft'],
            'in_review'  => ['badge-warning', 'In Review'],
            'approved'   => ['badge-info',    'Approved'],
            'published'  => ['badge-green',   'Published'],
            'rejected'   => ['badge-red',     'Rejected'],
            'archived'   => ['badge-gray',    'Archived'],
            'obsolete'   => ['badge-gray',    'Obsolete'],
            'retired'    => ['badge-gray',    'Retired'],
            'pending'    => ['badge-warning', 'Pending'],
            'returned'   => ['badge-orange',  'Returned'],
            'cancelled'  => ['badge-gray',    'Cancelled'],
            'open'       => ['badge-blue',    'Open'],
            'in_progress'=> ['badge-warning', 'In Progress'],
            'blocked'    => ['badge-red',     'Blocked'],
            'done'       => ['badge-green',   'Done'],
            'skipped'    => ['badge-gray',    'Skipped'],
        ];
        [$cls, $label] = $map[$status] ?? ['badge-gray', ucfirst(str_replace('_', ' ', $status))];
        return '<span class="badge ' . $cls . '">' . Security::h($label) . '</span>';
    }

    public static function priorityBadge(string $p): string {
        $map = ['urgent' => 'badge-red', 'high' => 'badge-orange', 'medium' => 'badge-warning', 'low' => 'badge-gray'];
        return '<span class="badge ' . ($map[$p] ?? 'badge-gray') . '">' . Security::h(ucfirst($p)) . '</span>';
    }

    public static function docTypeLabel(string $t): string {
        return ucwords(str_replace('_', ' ', $t));
    }

    public static function docTypes(): array {
        return ['policy','procedure','process','standard','guideline','work_instruction','plan','form','template','record','evidence','training'];
    }

    public static function classifications(): array {
        return ['public','internal','confidential','restricted'];
    }

    public static function spaceTypes(): array {
        return ['department','team','program','project','compliance','process','admin'];
    }

    public static function fmtDate(?string $d, string $fmt = 'M j, Y'): string {
        if (empty($d)) return '—';
        $ts = strtotime($d);
        return $ts ? date($fmt, $ts) : '—';
    }

    public static function timeAgo(?string $d): string {
        if (empty($d)) return '';
        $ts = strtotime($d);
        if (!$ts) return '';
        $diff = time() - $ts;
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        return date('M j, Y', $ts);
    }

    public static function avatar(?string $name, string $size = ''): string {
        $initial = strtoupper(substr((string)($name ?: '?'), 0, 1));
        $cls = 'user-avatar' . ($size ? ' ' . $size : '');
        return '<div class="' . $cls . '">' . Security::h($initial) . '</div>';
    }

    /** Escape comment text and highlight @mentions (CSP-safe). */
    public static function mentionize(?string $text): string {
        $safe = Security::h((string)$text);
        return preg_replace('/@([A-Za-z0-9._-]{2,})/', '<span class="mention">@$1</span>', $safe);
    }

    /**
     * Parse rendered page HTML: inject stable ids into h1–h3 headings and return
     * the augmented HTML, a table-of-contents list and a word count.
     * @return array{html:string,toc:array<int,array{level:int,text:string,id:string}>,words:int}
     */
    public static function buildToc(?string $html): array {
        $html = (string)$html;
        $plain = trim(strip_tags($html));
        $words = $plain === '' ? 0 : count(preg_split('/\s+/', $plain));
        if (trim($html) === '' || stripos($html, '<h') === false) {
            return ['html' => $html, 'toc' => [], 'words' => $words];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__pal_toc_root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();

        $xp = new \DOMXPath($dom);
        $toc = []; $used = [];
        foreach ($xp->query('//h1|//h2|//h3') as $node) {
            /** @var \DOMElement $node */
            $text = trim($node->textContent);
            if ($text === '') { continue; }
            $base = substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)) ?: 'section', 0, 60);
            $base = trim($base, '-') ?: 'section';
            $id = $base; $n = 1;
            while (isset($used[$id])) { $id = $base . '-' . (++$n); }
            $used[$id] = true;
            if (!$node->getAttribute('id')) { $node->setAttribute('id', $id); }
            else { $id = $node->getAttribute('id'); }
            $toc[] = ['level' => (int)substr($node->nodeName, 1), 'text' => $text, 'id' => $id];
        }
        if (count($toc) < 2) { return ['html' => $html, 'toc' => [], 'words' => $words]; }

        $root = $dom->getElementById('__pal_toc_root');
        $inner = '';
        if ($root) { foreach ($root->childNodes as $c) { $inner .= $dom->saveHTML($c); } }
        return ['html' => $inner !== '' ? $inner : $html, 'toc' => $toc, 'words' => $words];
    }
}
