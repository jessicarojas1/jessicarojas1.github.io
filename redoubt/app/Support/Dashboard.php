<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Aggregates the executive home dashboard for a user in a program. Every figure
 * is permission-gated and computed through the modules' own authorized listings,
 * so the dashboard never shows a count or item the user could not otherwise see.
 */
final class Dashboard
{
    /** @return array<string,mixed> */
    public static function forUser(array $user, int $programId): array
    {
        $can = static fn (string $p): bool => Authorize::can($user, $p, ['program_id' => $programId]);
        $prog = Db::fetchOne('SELECT name, customer, contract_number, status FROM program WHERE id = :id', ['id' => $programId]) ?? [];

        $stats = [];
        if ($can('announcement.view')) {
            $stats['announcements'] = ['label' => 'Announcements', 'icon' => '📣', 'count' => count(Announcements::listForUser($user, $programId)), 'url' => '/app/announcements'];
        }
        if ($can('document.view')) {
            $stats['documents'] = ['label' => 'Documents', 'icon' => '📁', 'count' => count(Documents::listForUser($user, $programId)), 'url' => '/app/documents'];
        }
        if ($can('taskorder.view')) {
            $stats['taskorders'] = ['label' => 'Task Orders', 'icon' => '📄', 'count' => count(TaskOrders::listForUser($user, $programId)), 'url' => '/app/task-orders'];
        }
        if ($can('job.view')) {
            $open = array_filter(Jobs::listForUser($user, $programId), static fn ($j) => ($j['status'] ?? '') === 'open');
            $stats['jobs'] = ['label' => 'Open Jobs', 'icon' => '💼', 'count' => count($open), 'url' => '/app/jobs'];
        }
        if ($can('milestone.view')) {
            $stats['milestones'] = ['label' => 'Upcoming Dates', 'icon' => '📅', 'count' => count(Milestones::upcoming($programId, 50)), 'url' => '/app/milestones'];
        }
        if ($can('directory.view')) {
            $stats['directory'] = ['label' => 'Contacts', 'icon' => '👥', 'count' => count(Directory::listForUser($user, $programId)), 'url' => '/app/directory'];
        }
        if ($can('access.grant') || $can('access.view')) {
            $stats['onboarding'] = ['label' => 'Pending Access', 'icon' => '🔐', 'count' => count(AccessRequests::listForProgram($programId, 'pending')), 'url' => '/app/admin/access'];
        }

        return [
            'program'       => $prog,
            'stats'         => $stats,
            'announcements' => $can('announcement.view') ? array_slice(Announcements::listForUser($user, $programId), 0, 5) : [],
            'milestones'    => $can('milestone.view') ? Milestones::upcoming($programId, 5) : [],
            'actions'       => self::actions($user, $programId, $can),
            'unread'        => !empty($user['id']) ? Notifications::unreadCount((int) $user['id']) : 0,
        ];
    }

    /** "My actions" — things that need the user's attention. @return array<int,array{label:string,url:string,count:int}> */
    private static function actions(array $user, int $programId, callable $can): array
    {
        $out = [];
        if ($can('access.grant')) {
            $n = count(AccessRequests::listForProgram($programId, 'pending'));
            if ($n > 0) {
                $out[] = ['label' => "$n access request" . ($n === 1 ? '' : 's') . ' awaiting your approval', 'url' => '/app/admin/access', 'count' => $n];
            }
        }
        if ($can('announcement.edit')) {
            $drafts = count(array_filter(Announcements::listForUser($user, $programId), static fn ($a) => empty($a['publish_at'])));
            if ($drafts > 0) {
                $out[] = ['label' => "$drafts announcement draft" . ($drafts === 1 ? '' : 's') . ' not yet published', 'url' => '/app/announcements', 'count' => $drafts];
            }
        }
        $due = array_filter(Milestones::upcoming($programId, 50), static fn ($m) => $m['days_out'] !== null && $m['days_out'] <= 7);
        if ($due !== [] && $can('milestone.view')) {
            $n = count($due);
            $out[] = ['label' => "$n milestone" . ($n === 1 ? '' : 's') . ' due within 7 days', 'url' => '/app/milestones', 'count' => $n];
        }
        return $out;
    }
}
