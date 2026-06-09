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
}
