<?php
declare(strict_types=1);

class DashboardController {

    public function index(): void {
        Auth::requireAuth();
        $uid = Auth::id();

        $stats = [
            'documents'   => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents")['c'] ?? 0),
            'published'   => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents WHERE status='published'")['c'] ?? 0),
            'spaces'      => (int)(Database::fetchOne("SELECT COUNT(*) c FROM spaces WHERE is_archived=FALSE")['c'] ?? 0),
            'pages'       => (int)(Database::fetchOne("SELECT COUNT(*) c FROM pages")['c'] ?? 0),
            'processes'   => (int)(Database::fetchOne("SELECT COUNT(*) c FROM processes")['c'] ?? 0),
            'pending'     => (int)(Database::fetchOne("SELECT COUNT(*) c FROM approval_requests WHERE status='pending'")['c'] ?? 0),
            'overdue_rev' => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents WHERE review_date < CURRENT_DATE AND status='published'")['c'] ?? 0),
            'expiring'    => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents WHERE expiration_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days' AND status='published'")['c'] ?? 0),
        ];

        // My pending approvals (where I'm the current-step approver)
        $myApprovals = Database::fetchAll(
            "SELECT ar.* FROM approval_requests ar
             JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.step_number = ar.current_step
             WHERE ar.status='pending' AND ars.status='pending'
               AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')
             ORDER BY ar.due_at NULLS LAST, ar.created_at DESC LIMIT 8",
            [$uid, Auth::role(), Auth::role()]
        );

        $myTasks = Database::fetchAll(
            "SELECT * FROM tasks WHERE assigned_to = ? AND status NOT IN ('done','cancelled')
             ORDER BY (due_date < CURRENT_DATE) DESC, due_date NULLS LAST LIMIT 8",
            [$uid]
        );

        $recent = Database::fetchAll(
            "SELECT al.*, u.name AS user_name FROM activity_log al
             LEFT JOIN users u ON u.id = al.user_id
             WHERE al.action NOT IN ('login','logout','login_failed')
             ORDER BY al.id DESC LIMIT 12"
        );

        $recentDocs = Database::fetchAll(
            "SELECT d.id, d.document_code, d.title, d.status, d.doc_type, d.updated_at
             FROM documents d ORDER BY d.updated_at DESC LIMIT 6"
        );

        // Documents by status for the chart
        $byStatus = Database::fetchAll("SELECT status, COUNT(*) c FROM documents GROUP BY status");

        $recentViews = Recent::recent(6);

        require PALADIN_ROOT . '/views/dashboard/index.php';
    }

    public function admin(): void {
        Auth::requireAdmin();
        $stats = [
            'users'        => (int)(Database::fetchOne("SELECT COUNT(*) c FROM users")['c'] ?? 0),
            'active_users' => (int)(Database::fetchOne("SELECT COUNT(*) c FROM users WHERE is_active=TRUE")['c'] ?? 0),
            'sessions'     => (int)(Database::fetchOne("SELECT COUNT(*) c FROM active_sessions WHERE last_seen_at > NOW() - INTERVAL '30 minutes'")['c'] ?? 0),
            'documents'    => (int)(Database::fetchOne("SELECT COUNT(*) c FROM documents")['c'] ?? 0),
            'workflows'    => (int)(Database::fetchOne("SELECT COUNT(*) c FROM workflow_templates WHERE is_active=TRUE")['c'] ?? 0),
            'audit_events' => (int)(Database::fetchOne("SELECT COUNT(*) c FROM activity_log")['c'] ?? 0),
        ];
        $storageBytes = 0;
        foreach (Database::fetchAll("SELECT COALESCE(SUM(file_size),0) s FROM attachments UNION ALL SELECT COALESCE(SUM(file_size),0) FROM documents") as $r) {
            $storageBytes += (int)$r['s'];
        }
        require PALADIN_ROOT . '/views/dashboard/admin.php';
    }
}
