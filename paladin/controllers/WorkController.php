<?php
declare(strict_types=1);

/**
 * WorkController — a personal "My Work" cockpit aggregating the things that need
 * the current user's attention across modules: open tasks assigned to them,
 * approvals awaiting their decision, documents they own that are due/overdue for
 * review, and published documents requiring acknowledgement they haven't signed.
 */
class WorkController {

    public function index(): void {
        Auth::requireAuth();
        $uid = Auth::id();
        $role = Auth::role();

        $myTasks = Database::fetchAll(
            "SELECT id, title, status, priority, due_date
             FROM tasks
             WHERE assigned_to = ? AND status NOT IN ('done','cancelled')
             ORDER BY (due_date IS NULL), due_date, id DESC LIMIT 25", [$uid]
        );

        $myApprovals = Database::fetchAll(
            "SELECT DISTINCT ar.id, ar.title, ar.due_at, ar.created_at
             FROM approval_requests ar
             JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.status = 'pending'
                  AND (ar.approval_mode IN ('parallel','consensus') OR ars.step_number = ar.current_step)
             WHERE ar.status = 'pending'
               AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')
             ORDER BY ar.due_at NULLS LAST, ar.created_at LIMIT 25", [$uid, $role, $role]
        );

        $myReviews = Database::fetchAll(
            "SELECT id, document_code, title, review_date, expiration_date
             FROM documents
             WHERE owner_id = ? AND status = 'published'
               AND ((review_date IS NOT NULL AND review_date <= CURRENT_DATE + INTERVAL '30 days')
                 OR (expiration_date IS NOT NULL AND expiration_date <= CURRENT_DATE + INTERVAL '30 days'))
             ORDER BY COALESCE(review_date, expiration_date) LIMIT 25", [$uid]
        );

        $myAcks = Database::fetchAll(
            "SELECT d.id, d.document_code, d.title, d.revision
             FROM documents d
             WHERE d.requires_ack = TRUE AND d.status = 'published'
               AND NOT EXISTS (
                   SELECT 1 FROM document_acknowledgements da
                   WHERE da.document_id = d.id AND da.user_id = ? AND da.revision = d.revision)
             ORDER BY d.document_code LIMIT 25", [$uid]
        );

        $today = date('Y-m-d');
        require PALADIN_ROOT . '/views/work/index.php';
    }
}
