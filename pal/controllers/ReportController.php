<?php
declare(strict_types=1);

class ReportController {

    public function index(): void {
        Auth::requirePermission('report.view');

        $stats = [
            'documents' => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents")['c'] ?? 0),
            'published' => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents WHERE status='published'")['c'] ?? 0),
            'overdue'   => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents WHERE review_date < CURRENT_DATE AND status='published'")['c'] ?? 0),
            'expiring'  => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents WHERE expiration_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' AND status='published'")['c'] ?? 0),
            'pending'   => (int)(Database::fetchOne("SELECT COUNT(*) c FROM approval_requests WHERE status='pending'")['c'] ?? 0),
            'open_tasks'=> (int)(Database::fetchOne("SELECT COUNT(*) c FROM tasks WHERE status NOT IN ('done','cancelled')")['c'] ?? 0),
        ];

        $byStatus = Database::fetchAll("SELECT status, COUNT(*) c FROM documents GROUP BY status ORDER BY status");

        require PAL_ROOT . '/views/report/index.php';
    }

    public function expiring(): void {
        Auth::requirePermission('report.view');

        $rows = Database::fetchAll(
            "SELECT d.id, d.document_code, d.title, d.status, d.review_date, d.expiration_date,
                    o.name AS owner_name, s.space_key
             FROM documents d
             LEFT JOIN users o ON o.id = d.owner_id
             LEFT JOIN spaces s ON s.id = d.space_id
             WHERE d.status = 'published'
               AND (d.review_date <= CURRENT_DATE + INTERVAL '90 days'
                    OR d.expiration_date <= CURRENT_DATE + INTERVAL '90 days'
                    OR d.review_date < CURRENT_DATE)
             ORDER BY d.review_date NULLS LAST, d.expiration_date NULLS LAST"
        );

        if (($_GET['format'] ?? '') === 'csv' && Auth::can('report.export')) {
            $this->streamCsv('expiring_documents.csv',
                ['Code', 'Title', 'Owner', 'Space', 'Review Date', 'Expiration Date', 'Status'],
                $rows,
                static fn(array $r): array => [
                    $r['document_code'], $r['title'], $r['owner_name'] ?? '', $r['space_key'] ?? '',
                    $r['review_date'] ?? '', $r['expiration_date'] ?? '', $r['status'],
                ]
            );
            return;
        }

        require PAL_ROOT . '/views/report/expiring.php';
    }

    public function approvalBacklog(): void {
        Auth::requirePermission('report.view');

        $rows = Database::fetchAll(
            "SELECT ar.id, ar.title, ar.entity_type, ar.entity_id, ar.current_step, ar.due_at, ar.created_at,
                    EXTRACT(DAY FROM NOW() - ar.created_at)::int AS age_days,
                    u.name AS requester_name
             FROM approval_requests ar
             LEFT JOIN users u ON u.id = ar.requested_by
             WHERE ar.status = 'pending'
             ORDER BY ar.created_at ASC"
        );

        if (($_GET['format'] ?? '') === 'csv' && Auth::can('report.export')) {
            $this->streamCsv('approval_backlog.csv',
                ['Title', 'Entity', 'Requester', 'Current Step', 'Age (days)', 'Due'],
                $rows,
                static fn(array $r): array => [
                    $r['title'],
                    trim(($r['entity_type'] ?? '') . ' #' . ($r['entity_id'] ?? '')),
                    $r['requester_name'] ?? '',
                    $r['current_step'], $r['age_days'] ?? 0, $r['due_at'] ?? '',
                ]
            );
            return;
        }

        require PAL_ROOT . '/views/report/approval_backlog.php';
    }

    public function acknowledgements(): void {
        Auth::requirePermission('report.view');

        $totalUsers = (int)(Database::fetchOne("SELECT COUNT(*) c FROM users WHERE is_active = TRUE")['c'] ?? 0);

        $rows = Database::fetchAll(
            "SELECT d.id, d.document_code, d.title, d.revision,
                    (SELECT COUNT(DISTINCT user_id) FROM document_acknowledgements da
                     WHERE da.document_id = d.id AND da.revision = d.revision) AS ack_count
             FROM documents d
             WHERE d.requires_ack = TRUE AND d.status = 'published'
             ORDER BY d.document_code"
        );

        if (($_GET['format'] ?? '') === 'csv' && Auth::can('report.export')) {
            $this->streamCsv('acknowledgements.csv',
                ['Code', 'Title', 'Revision', 'Acknowledged', 'Total Active Users', 'Percent'],
                $rows,
                static function (array $r) use ($totalUsers): array {
                    $ack = (int)($r['ack_count'] ?? 0);
                    $pct = $totalUsers > 0 ? round($ack / $totalUsers * 100) : 0;
                    return [$r['document_code'], $r['title'], $r['revision'], $ack, $totalUsers, $pct . '%'];
                }
            );
            return;
        }

        require PAL_ROOT . '/views/report/acknowledgements.php';
    }

    /**
     * Stream an array of rows as a CSV download. $mapper turns one DB row into a
     * flat array of cell values matching $header.
     */
    private function streamCsv(string $filename, array $header, array $rows, callable $mapper): void {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, $mapper($r));
        }
        fclose($out);
    }
}
