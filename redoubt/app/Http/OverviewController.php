<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Announcements;
use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Directory;
use Redoubt\Support\Faq;
use Redoubt\Support\Jobs;
use Redoubt\Support\Milestones;
use Redoubt\Support\QuickLinks;
use Redoubt\Support\Settings;
use Redoubt\Support\TaskOrders;

/**
 * Program Overview (the "front door" hub) and a printable Program Status Report.
 * Both are read-only and permission-trimmed to what the member may see.
 */
final class OverviewController
{
    /** GET /app/overview */
    public static function index(string $nonce): void
    {
        [$user, $programId, $programs] = self::ctx();
        if ($user === null) {
            return;
        }
        $can = static fn (string $p): bool => Authorize::can($user, $p, ['program_id' => $programId]);
        $program = Db::fetchOne('SELECT name, customer, contract_number, status FROM program WHERE id = :id', ['id' => $programId]) ?? [];
        $data = [
            'program'    => $program,
            'pocs'       => $can('directory.view') ? array_slice(Directory::listForUser($user, $programId), 0, 8) : [],
            'links'      => QuickLinks::listForUser($user, $programId, $can('quicklink.manage')),
            'faqs'       => array_slice(Faq::listForUser($user, $programId, $can('faq.manage')), 0, 5),
            'milestones' => $can('milestone.view') ? Milestones::upcoming($programId, 5) : [],
        ];
        Audit::log('overview.view', 'program#' . $programId, $programId);
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_overview.php';
    }

    /** GET /app/report — printable program status report. */
    public static function report(string $nonce): void
    {
        [$user, $programId, $programs] = self::ctx();
        if ($user === null) {
            return;
        }
        $can = static fn (string $p): bool => Authorize::can($user, $p, ['program_id' => $programId]);
        $program = Db::fetchOne('SELECT name, customer, contract_number, status FROM program WHERE id = :id', ['id' => $programId]) ?? [];
        $openJobs = array_filter(Jobs::listForUser($user, $programId), static fn ($j) => ($j['status'] ?? '') === 'open');
        $data = [
            'program'       => $program,
            'branding'      => Settings::branding($programId),
            'generated'     => gmdate('Y-m-d H:i') . ' UTC',
            'by'            => $user['name'] ?? '',
            'announcements' => $can('announcement.view') ? array_slice(Announcements::listForUser($user, $programId), 0, 8) : [],
            'milestones'    => $can('milestone.view') ? Milestones::upcoming($programId, 10) : [],
            'taskorders'    => $can('taskorder.view') ? TaskOrders::listForUser($user, $programId) : [],
            'openjobs'      => array_values($openJobs),
            'counts'        => [
                'documents' => $can('document.view') ? count(\Redoubt\Support\Documents::listForUser($user, $programId)) : null,
                'contacts'  => $can('directory.view') ? count(Directory::listForUser($user, $programId)) : null,
            ],
        ];
        Audit::log('report.view', 'program#' . $programId, $programId);
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_report.php';
    }

    /** @return array{0:?array,1:int,2:array<int,string>} */
    private static function ctx(): array
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'This page requires the database.');
            return [null, 0, []];
        }
        $programs = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
            $programs[$pid] = $row['name'] ?? ('Program #' . $pid);
        }
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no program access.');
            return [null, 0, []];
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        return [$user, $programId, $programs];
    }

    private static function plain(int $s, string $m): void
    {
        http_response_code($s);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m;
    }
}
