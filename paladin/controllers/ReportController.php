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

        require PALADIN_ROOT . '/views/report/index.php';
    }

    /** Compliance & operations metrics dashboard (server-rendered, no JS). */
    public function compliance(): void {
        Auth::requirePermission('report.view');

        $docByStatus = Database::fetchAll("SELECT status, COUNT(*) c FROM documents GROUP BY status ORDER BY c DESC");
        $docByType   = Database::fetchAll("SELECT doc_type, COUNT(*) c FROM documents GROUP BY doc_type ORDER BY c DESC LIMIT 10");

        // Review compliance for published documents that have a review date.
        $review = Database::fetchOne(
            "SELECT
                COUNT(*) FILTER (WHERE review_date < CURRENT_DATE) overdue,
                COUNT(*) FILTER (WHERE review_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days') soon,
                COUNT(*) FILTER (WHERE review_date > CURRENT_DATE + INTERVAL '30 days') ontrack
             FROM documents WHERE status='published' AND review_date IS NOT NULL"
        );

        // Approval analytics.
        $avgDecisionDays = Database::fetchOne(
            "SELECT ROUND(AVG(EXTRACT(EPOCH FROM (decided_at - created_at)) / 86400.0)::numeric, 1) d
             FROM approval_requests WHERE status IN ('approved','rejected') AND decided_at IS NOT NULL
               AND decided_at > NOW() - INTERVAL '90 days'"
        );
        $throughput = Database::fetchAll(
            "SELECT to_char(date_trunc('week', decided_at), 'Mon DD') wk, COUNT(*) c
             FROM approval_requests WHERE status IN ('approved','rejected') AND decided_at > NOW() - INTERVAL '8 weeks'
             GROUP BY date_trunc('week', decided_at) ORDER BY date_trunc('week', decided_at)"
        );
        $backlog = Database::fetchOne(
            "SELECT
                COUNT(*) FILTER (WHERE created_at > NOW() - INTERVAL '3 days') fresh,
                COUNT(*) FILTER (WHERE created_at <= NOW() - INTERVAL '3 days' AND created_at > NOW() - INTERVAL '7 days') aging,
                COUNT(*) FILTER (WHERE created_at <= NOW() - INTERVAL '7 days') stale
             FROM approval_requests WHERE status='pending'"
        );

        $procByStatus = Database::fetchAll("SELECT status, COUNT(*) c FROM processes GROUP BY status ORDER BY c DESC");
        $ackStats = Database::fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM documents WHERE requires_ack AND status='published') required,
                (SELECT COUNT(DISTINCT document_id) FROM document_acknowledgements) acknowledged"
        );

        require PALADIN_ROOT . '/views/report/compliance.php';
    }

    /**
     * Content health — orphaned pages (top-level, not a space homepage, nothing
     * links to them) and broken internal links (href to a /pages/N or
     * /documents/N that no longer exists). Read-only; respects space privacy and
     * per-page restrictions.
     */
    public function contentHealth(): void {
        Auth::requirePermission('report.view');

        $pages = Database::fetchAll(
            "SELECT p.id, p.title, p.parent_id, p.space_id, p.status, p.body,
                    s.space_key, s.is_private AS space_private, s.homepage_id
             FROM pages p JOIN spaces s ON s.id = p.space_id
             WHERE p.deleted_at IS NULL"
        );
        // Only consider content the current user may actually see.
        $pages = array_values(array_filter($pages, static fn($p) =>
            SpaceAccess::canView(['id' => (int)$p['space_id'], 'is_private' => $p['space_private']])
            && PageAccess::canView($p)
        ));

        $validPageIds = []; $homepageIds = [];
        foreach ($pages as $p) {
            $validPageIds[(int)$p['id']] = true;
            if (!empty($p['homepage_id'])) { $homepageIds[(int)$p['homepage_id']] = true; }
        }
        $validDocIds = [];
        foreach (Database::fetchAll("SELECT id FROM documents") as $d) { $validDocIds[(int)$d['id']] = true; }

        $inbound = [];   // page id => inbound internal-link count
        $broken  = [];   // ['source_id','source_title','space_key','kind','target']
        foreach ($pages as $p) {
            if (preg_match_all('#href=["\']/(pages|documents)/(\d+)#i', (string)$p['body'], $m, PREG_SET_ORDER)) {
                foreach ($m as $mm) {
                    $kind = strtolower($mm[1]); $tid = (int)$mm[2];
                    if ($kind === 'pages') {
                        $inbound[$tid] = ($inbound[$tid] ?? 0) + 1;
                        if (!isset($validPageIds[$tid])) {
                            $broken[] = ['source_id' => (int)$p['id'], 'source_title' => $p['title'], 'space_key' => $p['space_key'], 'kind' => 'page', 'target' => $tid];
                        }
                    } elseif (!isset($validDocIds[$tid])) {
                        $broken[] = ['source_id' => (int)$p['id'], 'source_title' => $p['title'], 'space_key' => $p['space_key'], 'kind' => 'document', 'target' => $tid];
                    }
                }
            }
        }

        $orphans = [];
        foreach ($pages as $p) {
            $id = (int)$p['id'];
            if ($p['parent_id'] === null && !isset($homepageIds[$id]) && empty($inbound[$id])) {
                $orphans[] = $p;
            }
        }
        usort($orphans, static fn($a, $b) => strcasecmp((string)$a['title'], (string)$b['title']));

        require PALADIN_ROOT . '/views/report/content_health.php';
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

        require PALADIN_ROOT . '/views/report/expiring.php';
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

        require PALADIN_ROOT . '/views/report/approval_backlog.php';
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

        require PALADIN_ROOT . '/views/report/acknowledgements.php';
    }

    /** Page Properties Report — aggregate page properties across a label. */
    public function pageProperties(): void {
        Auth::requirePermission('report.view');
        $labels = Database::fetchAll(
            "SELECT t.id, t.name, COUNT(DISTINCT et.entity_id) AS pages
             FROM tags t JOIN entity_tags et ON et.tag_id = t.id AND et.entity_type = 'page'
             JOIN pages p ON p.id = et.entity_id AND p.deleted_at IS NULL
             JOIN page_properties pp ON pp.page_id = p.id
             GROUP BY t.id, t.name ORDER BY t.name"
        );
        $labelId = !empty($_GET['label']) ? (int)$_GET['label'] : (int)($labels[0]['id'] ?? 0);
        $label   = $labelId ? Database::fetchOne("SELECT id, name FROM tags WHERE id = ?", [$labelId]) : null;

        $columns = []; $rows = [];
        if ($labelId) {
            // Hide pages in private spaces from non-members (admins see all).
            $priv = "(s.id IS NULL OR s.is_private = FALSE OR ? = 'admin'
                      OR EXISTS (SELECT 1 FROM space_members msp WHERE msp.space_id = s.id AND msp.user_id = ?))";
            $pages = Database::fetchAll(
                "SELECT p.id, p.title, s.name AS space_name
                 FROM pages p JOIN entity_tags et ON et.entity_id = p.id AND et.entity_type = 'page'
                 LEFT JOIN spaces s ON s.id = p.space_id
                 WHERE et.tag_id = ? AND p.deleted_at IS NULL AND {$priv} ORDER BY p.title",
                [$labelId, Auth::role(), Auth::id()]
            );
            foreach ($pages as $pg) {
                $props = Database::fetchAll("SELECT prop_key, prop_value FROM page_properties WHERE page_id = ? ORDER BY seq", [(int)$pg['id']]);
                $map = [];
                foreach ($props as $pr) {
                    $k = $pr['prop_key'];
                    if (!in_array($k, $columns, true)) $columns[] = $k;
                    $map[$k] = $pr['prop_value'];
                }
                if ($map) $rows[] = ['page' => $pg, 'props' => $map];
            }
        }
        require PALADIN_ROOT . '/views/report/page_properties.php';
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
        Csv::put($out, $header);
        foreach ($rows as $r) {
            Csv::put($out, $mapper($r));
        }
        fclose($out);
    }
}
