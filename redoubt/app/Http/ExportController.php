<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\AccessRequests;
use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Directory;
use Redoubt\Support\Documents;
use Redoubt\Support\TaskOrders;

/**
 * CSV export of permission-trimmed lists. GET /app/export?type=&program_id=.
 * Each type is gated by the same permission as its module; output is exactly
 * what the requester may already see.
 */
final class ExportController
{
    public static function csv(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Export requires the database.');
            return;
        }
        $programId = (int) ($_GET['program_id'] ?? 0);
        $type = (string) ($_GET['type'] ?? '');
        if (!isset($user['memberships'][$programId])) {
            self::plain(403, '403 Forbidden.');
            return;
        }

        [$perm, $headers, $rows] = match ($type) {
            'roster' => ['access.view', ['Name', 'Email', 'Roles', 'Company', 'Status', 'US person'],
                array_map(static fn ($m) => [$m['display_name'], $m['email'], $m['roles'] ?? '', $m['company_name'] ?? '', $m['status'], self::yn($m['is_us_person'])], AccessRequests::roster($programId))],
            'task-orders' => ['taskorder.view', ['Number', 'Title', 'Status'],
                array_map(static fn ($t) => [$t['number'], $t['title'] ?? '', $t['status'] ?? ''], TaskOrders::listForUser($user, $programId))],
            'documents' => ['document.view', ['Title', 'Zone', 'CUI', 'Export-controlled'],
                array_map(static fn ($d) => [$d['title'] ?? '', $d['zone'], self::yn($d['cui_marked']), self::yn($d['export_controlled'])], Documents::listForUser($user, $programId))],
            'directory' => ['directory.view', ['Name', 'Role', 'Company'],
                array_map(static fn ($c) => [$c['name'], $c['role_label'] ?? '', $c['company'] ?? ''], Directory::listForUser($user, $programId))],
            default => [null, [], []],
        };
        if ($perm === null) {
            self::plain(400, 'Unknown export type.');
            return;
        }
        Authorize::requirePermission($user, $perm, ['program_id' => $programId]);
        Audit::log('export.csv', $type, $programId, $user['id'] ?? null);

        $filename = 'redoubt-' . preg_replace('/[^a-z0-9\-]/i', '', $type) . '-p' . $programId . '-' . date('Ymd') . '.csv';
        // Ensure a clean binary body: route any notices to the log, not output,
        // and discard buffered output before streaming the CSV.
        ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        // Explicit $escape = '' → RFC-4180 CSV, and silences the PHP 8.4+ deprecation.
        fputcsv($out, $headers, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, $r, ',', '"', '');
        }
        fclose($out);
    }

    private static function yn(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return ($v === true || $v === 't' || $v === '1' || $v === 1) ? 'Yes' : 'No';
    }

    private static function plain(int $s, string $m): void
    {
        http_response_code($s);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m;
    }
}
