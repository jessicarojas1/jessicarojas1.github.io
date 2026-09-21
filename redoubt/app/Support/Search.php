<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Global, permission-aware search (Annex I / §13). It searches each module via
 * that module's own already-permission-trimmed listing, then substring-matches —
 * so a user can NEVER discover through search anything they could not otherwise
 * access (export-controlled docs, other companies' items, restricted zones, etc.).
 */
final class Search
{
    private const PER_TYPE = 20;

    /** @return array<int,array{type:string,title:string,subtitle:string,url:string}> */
    public static function run(array $user, int $programId, string $query): array
    {
        $q = trim(mb_strtolower($query));
        if ($q === '') {
            return [];
        }
        $hits = static fn (string $hay): bool => $hay !== '' && str_contains(mb_strtolower($hay), $q);
        $results = [];

        if (Authorize::can($user, 'announcement.view', ['program_id' => $programId])) {
            foreach (Announcements::listForUser($user, $programId) as $a) {
                if ($hits((string) $a['title']) || $hits((string) $a['body'])) {
                    $results[] = self::hit('Announcement', (string) $a['title'], ucfirst((string) $a['priority']), '/app/announcements?program_id=' . $programId);
                }
            }
        }
        if (Authorize::can($user, 'document.view', ['program_id' => $programId])) {
            foreach (Documents::listForUser($user, $programId) as $d) {
                if ($hits((string) ($d['title'] ?? ''))) {
                    $marks = $d['export_controlled'] ? 'ITAR/EAR' : ($d['cui_marked'] ? 'CUI' : (string) $d['zone']);
                    $results[] = self::hit('Document', (string) ($d['title'] ?: '(untitled)'), $marks, '/app/documents?program_id=' . $programId);
                }
            }
        }
        if (Authorize::can($user, 'taskorder.view', ['program_id' => $programId])) {
            foreach (TaskOrders::listForUser($user, $programId) as $t) {
                if ($hits((string) $t['number']) || $hits((string) ($t['title'] ?? ''))) {
                    $results[] = self::hit('Task Order', $t['number'] . ' — ' . ($t['title'] ?: ''), (string) $t['status'], '/app/task-orders?program_id=' . $programId);
                }
            }
        }
        if (Authorize::can($user, 'job.view', ['program_id' => $programId])) {
            foreach (Jobs::listForUser($user, $programId) as $j) {
                if ($hits((string) $j['title'])) {
                    $results[] = self::hit('Job', (string) $j['title'], (string) $j['status'], '/app/jobs?program_id=' . $programId);
                }
            }
        }
        if (Authorize::can($user, 'directory.view', ['program_id' => $programId])) {
            foreach (Directory::listForUser($user, $programId) as $c) {
                if ($hits((string) $c['name']) || $hits((string) ($c['role_label'] ?? '')) || $hits((string) ($c['company'] ?? ''))) {
                    $results[] = self::hit('Contact', (string) $c['name'], trim(($c['role_label'] ?? '') . ' ' . ($c['company'] ? '· ' . $c['company'] : '')), '/app/directory?program_id=' . $programId);
                }
            }
        }
        if (Authorize::can($user, 'milestone.view', ['program_id' => $programId])) {
            foreach (Milestones::listForProgram($programId) as $m) {
                if ($hits((string) $m['title'])) {
                    $results[] = self::hit('Milestone', (string) $m['title'], (string) $m['type'], '/app/milestones?program_id=' . $programId);
                }
            }
        }
        $isMember = isset($user['memberships'][$programId]);
        if ($isMember) {
            $faqMgr = Authorize::can($user, 'faq.manage', ['program_id' => $programId]);
            foreach (Faq::listForUser($user, $programId, $faqMgr) as $f) {
                if ($hits((string) $f['question']) || $hits((string) $f['answer'])) {
                    $results[] = self::hit('FAQ', (string) $f['question'], 'Knowledge base', '/app/faq?program_id=' . $programId);
                }
            }
            $linkMgr = Authorize::can($user, 'quicklink.manage', ['program_id' => $programId]);
            foreach (QuickLinks::listForUser($user, $programId, $linkMgr) as $l) {
                if ($hits((string) $l['label'])) {
                    $results[] = self::hit('Quick Link', (string) $l['label'], (string) $l['url'], '/app/quick-links?program_id=' . $programId);
                }
            }
        }
        return array_slice($results, 0, self::PER_TYPE * 8);
    }

    private static function hit(string $type, string $title, string $subtitle, string $url): array
    {
        return ['type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'url' => $url];
    }
}
